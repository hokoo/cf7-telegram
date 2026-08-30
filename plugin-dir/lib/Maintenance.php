<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\wpConnections\Helpers\Database;
use WP_Post;

class Maintenance {
	public const CRON_HOOK = 'cf7tg_cleanup';
	public const CRON_SCHEDULE = 'cf7tg_cleanup_interval';
	public const CLEANUP_LOCK_OPTION = 'cf7tg_cleanup_lock';
	public const CRON_LAST_ERROR_OPTION = 'cf7tg_cleanup_cron_last_error';
	public const DEFAULT_INTERVAL = 24 * HOUR_IN_SECONDS; // Seconds.
	public const CLEANUP_LOCK_TTL = 300; // Seconds.

	private const LEGACY_EARLY_ACCESS_OPTION = 'cf7t_early_access';

	private const OPTION_PREFIXES = [
		'cf7tg_',
		'cf7t_',
		'wpcf7_telegram_',
	];

	public static function init(): void {
		add_filter( 'cron_schedules', [ self::class, 'registerSchedule' ] );
		add_action( 'init', [ self::class, 'ensureScheduled' ] );
		add_action( self::CRON_HOOK, [ self::class, 'runScheduledCleanup' ] );
	}

	public static function activate(): void {
		self::ensureScheduled();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::releaseCleanupLock();
		self::clearFetchLocks();
	}

	public static function uninstall(): void {
		self::deactivate();
		self::deleteAllPluginPosts();
		self::deleteAllPluginConnections();
		self::dropConnectionsTables();
		self::dropLogTable();
		self::deletePluginOptions();
	}

	public static function registerSchedule( array $schedules ): array {
		$interval = self::getCleanupInterval();
		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => $interval,
			'display'  => sprintf(
				/* translators: %d: number of minutes */
				__( 'CF7 Telegram cleanup every %d minutes', 'cf7-telegram' ),
				max( 1, (int) ceil( $interval / MINUTE_IN_SECONDS ) )
			),
		];

		return $schedules;
	}

	public static function ensureScheduled(): void {
		self::ensureScheduleRegistered();

		$now = time();
		$interval = self::getCleanupInterval();

		if ( ! self::hasAnyScheduledCleanupEvent() ) {
			self::scheduleSingleCleanup( $now );
		}

		if ( self::hasRecurringCleanupEvent( $interval ) ) {
			return;
		}

		self::clearScheduledCleanupEvents( self::CRON_SCHEDULE );
		self::scheduleRecurringCleanup( $now + $interval );
	}

	public static function hasCleanupLock( int $ttl = self::CLEANUP_LOCK_TTL ): bool {
		$lockedAt = (int) get_option( self::CLEANUP_LOCK_OPTION, 0 );

		if ( ! $lockedAt ) {
			return false;
		}

		if ( ( time() - $lockedAt ) < $ttl ) {
			return true;
		}

		delete_option( self::CLEANUP_LOCK_OPTION );
		return false;
	}

	public static function runScheduledCleanup(): void {
		if ( self::hasActiveFetchLocks() ) {
			return;
		}

		if ( ! self::acquireCleanupLock() ) {
			return;
		}

		try {
			if ( self::hasActiveFetchLocks() ) {
				return;
			}

			$result = self::cleanupOrphanChatsAndBrokenRelations();

			if ( $result['deleted_connections'] || $result['deleted_chats'] ) {
				( new Logger() )->write( $result, 'Scheduled cleanup completed' );
			}
		} catch ( \Throwable $e ) {
			( new Logger() )->write(
				[
					'error' => $e->getMessage(),
				],
				'Scheduled cleanup failed',
				Logger::LEVEL_CRITICAL
			);
		} finally {
			self::releaseCleanupLock();
		}
	}

	public static function hasActiveFetchLocks( int $ttl = Bot::FETCH_UPDATES_LOCK_TTL ): bool {
		$active = false;
		$now = time();
		$lockOptionNames = self::getOptionNamesByPrefix( Bot::FETCH_UPDATES_LOCK_PREFIX );

		foreach ( $lockOptionNames as $optionName ) {
			$lockedAt = (int) get_option( $optionName, 0 );

			if ( $lockedAt && ( $now - $lockedAt ) < $ttl ) {
				$active = true;
				continue;
			}

			delete_option( $optionName );
		}

		return $active;
	}

	private static function cleanupOrphanChatsAndBrokenRelations(): array {
		$deletedConnections = self::deleteBrokenConnections();
		$deletedChats = self::deleteOrphanChats();

		return [
			'deleted_connections' => $deletedConnections,
			'deleted_chats'       => $deletedChats,
		];
	}

	private static function deleteBrokenConnections(): int {
		$connections = self::getPluginConnections();

		if ( empty( $connections ) ) {
			return 0;
		}

		$definitions = self::getRelationDefinitions();
		$postTypeCache = [];
		$connectionIDs = [];

		foreach ( $connections as $connection ) {
			$relation = $connection->relation ?? '';
			$definition = $definitions[ $relation ] ?? null;

			if ( ! $definition ) {
				continue;
			}

			$fromType = self::getPostType( (int) $connection->from, $postTypeCache );
			$toType = self::getPostType( (int) $connection->to, $postTypeCache );

			if ( $definition['from'] !== $fromType || $definition['to'] !== $toType ) {
				$connectionIDs[] = (int) $connection->ID;
			}
		}

		return self::deleteConnectionsByIDs( $connectionIDs );
	}

	private static function deleteOrphanChats(): int {
		if ( ! self::tableExists( self::getConnectionsTableName() ) ) {
			return 0;
		}

		$chatIDs = self::getPostIDs( Client::CPT_CHAT );

		if ( empty( $chatIDs ) ) {
			return 0;
		}

		$connectedChatIDs = self::getConnectedChatIDs();
		$orphanChatIDs = array_values( array_diff( $chatIDs, $connectedChatIDs ) );

		if ( empty( $orphanChatIDs ) ) {
			return 0;
		}

		self::deleteConnectionsByObjectIDs( $orphanChatIDs );

		$deleted = 0;

		foreach ( $orphanChatIDs as $chatID ) {
			if ( wp_delete_post( $chatID, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	private static function getConnectedChatIDs(): array {
		$table = self::getConnectionsTableName();

		if ( ! self::tableExists( $table ) ) {
			return [];
		}

		$chatIDs = self::runPreparedGetCol(
			'SELECT DISTINCT `to` FROM %i WHERE relation = %s',
			[
				$table,
				Client::BOT2CHAT,
			]
		);

		return array_map( 'intval', $chatIDs );
	}

	private static function getPluginConnections(): array {
		$table = self::getConnectionsTableName();

		if ( ! self::tableExists( $table ) ) {
			return [];
		}

		$relations = array_values( array_keys( self::getRelationDefinitions() ) );
		$sql = 'SELECT ID, relation, `from`, `to` FROM %i WHERE relation IN (' . self::placeholderList( count( $relations ), '%s' ) . ')';

		return self::runPreparedGetResults(
			$sql,
			array_merge( [ $table ], $relations )
		);
	}

	private static function deleteConnectionsByObjectIDs( array $objectIDs ): int {
		$table = self::getConnectionsTableName();

		if ( ! self::tableExists( $table ) ) {
			return 0;
		}

		$objectIDs = array_values( array_unique( array_map( 'intval', array_filter( $objectIDs ) ) ) );

		if ( empty( $objectIDs ) ) {
			return 0;
		}

		$relations = array_values( array_keys( self::getRelationDefinitions() ) );
		$sql = 'SELECT ID FROM %i WHERE relation IN (' . self::placeholderList( count( $relations ), '%s' ) . ')';
		$sql .= ' AND (`from` IN (' . self::placeholderList( count( $objectIDs ), '%d' ) . ')';
		$sql .= ' OR `to` IN (' . self::placeholderList( count( $objectIDs ), '%d' ) . '))';

		$connectionIDs = self::runPreparedGetCol(
			$sql,
			array_merge( [ $table ], $relations, $objectIDs, $objectIDs )
		);

		return self::deleteConnectionsByIDs( array_map( 'intval', $connectionIDs ) );
	}

	private static function deleteConnectionsByIDs( array $connectionIDs ): int {
		$table = self::getConnectionsTableName();
		$metaTable = self::getConnectionsMetaTableName();

		if ( ! self::tableExists( $table ) ) {
			return 0;
		}

		$connectionIDs = array_values( array_unique( array_map( 'intval', array_filter( $connectionIDs ) ) ) );

		if ( empty( $connectionIDs ) ) {
			return 0;
		}

		$deletePlaceholders = self::placeholderList( count( $connectionIDs ), '%d' );

		if ( self::tableExists( $metaTable ) ) {
			self::runPreparedQuery(
				'DELETE FROM %i WHERE connection_id IN (' . $deletePlaceholders . ')',
				array_merge( [ $metaTable ], $connectionIDs )
			);
		}

		return self::runPreparedQuery(
			'DELETE FROM %i WHERE ID IN (' . $deletePlaceholders . ')',
			array_merge( [ $table ], $connectionIDs )
		);
	}

	private static function deleteAllPluginPosts(): void {
		$postIDs = array_merge(
			self::getPostIDs( Client::CPT_CHAT ),
			self::getPostIDs( Client::CPT_BOT ),
			self::getPostIDs( Client::CPT_CHANNEL )
		);

		if ( empty( $postIDs ) ) {
			return;
		}

		self::deleteConnectionsByObjectIDs( $postIDs );

		foreach ( $postIDs as $postID ) {
			wp_delete_post( $postID, true );
		}
	}

	private static function deleteAllPluginConnections(): void {
		$table = self::getConnectionsTableName();

		if ( ! self::tableExists( $table ) ) {
			return;
		}

		$relations = array_values( array_keys( self::getRelationDefinitions() ) );
		$connectionIDs = self::runPreparedGetCol(
			'SELECT ID FROM %i WHERE relation IN (' . self::placeholderList( count( $relations ), '%s' ) . ')',
			array_merge( [ $table ], $relations )
		);

		self::deleteConnectionsByIDs( array_map( 'intval', $connectionIDs ) );
	}

	private static function dropConnectionsTables(): void {
		$tables = [
			self::getConnectionsMetaTableName(),
			self::getConnectionsTableName(),
		];

		foreach ( $tables as $table ) {
			if ( self::tableExists( $table ) ) {
				self::dropTable( $table );
			}
		}
	}

	private static function dropLogTable(): void {
		$table = self::getLogTableName();

		if ( self::tableExists( $table ) ) {
			self::dropTable( $table );
		}
	}

	private static function deletePluginOptions(): void {
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			foreach ( self::getOptionNamesByPrefix( $prefix ) as $optionName ) {
				delete_option( $optionName );
			}
		}

		delete_option( self::LEGACY_EARLY_ACCESS_OPTION );
		delete_option( Migration::FIX_1_0_FLAG );
		delete_option( self::CLEANUP_LOCK_OPTION );
	}

	private static function clearFetchLocks(): void {
		foreach ( self::getOptionNamesByPrefix( Bot::FETCH_UPDATES_LOCK_PREFIX ) as $optionName ) {
			delete_option( $optionName );
		}
	}

	private static function acquireCleanupLock( int $ttl = self::CLEANUP_LOCK_TTL ): bool {
		if ( self::hasCleanupLock( $ttl ) ) {
			return false;
		}

		return add_option( self::CLEANUP_LOCK_OPTION, time(), '', false );
	}

	private static function releaseCleanupLock(): void {
		delete_option( self::CLEANUP_LOCK_OPTION );
	}

	private static function getPostType( int $postID, array &$cache ): string {
		if ( isset( $cache[ $postID ] ) ) {
			return $cache[ $postID ];
		}

		$post = get_post( $postID );

		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			$cache[ $postID ] = '';
			return $cache[ $postID ];
		}

		$cache[ $postID ] = $post->post_type;
		return $cache[ $postID ];
	}

	private static function getPostIDs( string $postType ): array {
		return array_map(
			'intval',
			get_posts( [
				'post_type'      => $postType,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] )
		);
	}

	private static function getRelationDefinitions(): array {
		return [
			Client::CHAT2CHANNEL => [
				'from' => Client::CPT_CHAT,
				'to'   => Client::CPT_CHANNEL,
			],
			Client::BOT2CHANNEL => [
				'from' => Client::CPT_BOT,
				'to'   => Client::CPT_CHANNEL,
			],
			Client::FORM2CHANNEL => [
				'from' => Client::CPT_CF7FORM,
				'to'   => Client::CPT_CHANNEL,
			],
			Client::BOT2CHAT => [
				'from' => Client::CPT_BOT,
				'to'   => Client::CPT_CHAT,
			],
		];
	}

	private static function getCleanupInterval(): int {
		$interval = defined( 'WPCF7TG_CLEANUP_INTERVAL' ) ? (int) WPCF7TG_CLEANUP_INTERVAL : self::DEFAULT_INTERVAL;
		$interval = (int) apply_filters( 'cf7tg/cleanupInterval', $interval );

		return max( MINUTE_IN_SECONDS, $interval );
	}

	private static function ensureScheduleRegistered(): void {
		add_filter( 'cron_schedules', [ self::class, 'registerSchedule' ] );
	}

	private static function scheduleSingleCleanup( int $timestamp ): void {
		if ( self::functionAcceptsParameter( 'wp_schedule_single_event', 4 ) ) {
			self::storeScheduleResult(
				wp_schedule_single_event( $timestamp, self::CRON_HOOK, [], true ),
				'single'
			);
			return;
		}

		self::storeScheduleResult(
			wp_schedule_single_event( $timestamp, self::CRON_HOOK ),
			'single'
		);
	}

	private static function scheduleRecurringCleanup( int $timestamp ): void {
		if ( self::functionAcceptsParameter( 'wp_schedule_event', 5 ) ) {
			$result = wp_schedule_event( $timestamp, self::CRON_SCHEDULE, self::CRON_HOOK, [], true );

			if ( self::isWpError( $result ) && 'invalid_schedule' === $result->get_error_code() ) {
				self::ensureScheduleRegistered();
				$result = wp_schedule_event( $timestamp, self::CRON_SCHEDULE, self::CRON_HOOK, [], true );
			}

			self::storeScheduleResult( $result, 'recurring' );
			return;
		}

		self::storeScheduleResult(
			wp_schedule_event( $timestamp, self::CRON_SCHEDULE, self::CRON_HOOK ),
			'recurring'
		);
	}

	private static function hasRecurringCleanupEvent( int $interval ): bool {
		foreach ( self::getScheduledCleanupEvents() as $event ) {
			if ( self::CRON_SCHEDULE !== ( $event['schedule'] ?? false ) ) {
				continue;
			}

			if ( isset( $event['interval'] ) && (int) $event['interval'] !== $interval ) {
				continue;
			}

			return true;
		}

		return false;
	}

	private static function hasAnyScheduledCleanupEvent(): bool {
		return ! empty( self::getScheduledCleanupEvents() );
	}

	private static function getScheduledCleanupEvents(): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::CRON_HOOK, [] ) : false;

			if ( ! $event ) {
				return [];
			}

			return [
				[
					'schedule' => $event->schedule ?? false,
					'interval' => $event->interval ?? null,
				],
			];
		}

		$events = [];
		$key = md5( serialize( [] ) );

		/*
		 * Public wp_get_scheduled_event() returns only the next matching hook.
		 * It cannot see a later recurring cleanup when an immediate single cleanup exists first.
		 */
		foreach ( _get_cron_array() as $timestamp => $cron ) {
			if ( ! isset( $cron[ self::CRON_HOOK ][ $key ] ) ) {
				continue;
			}

			$events[] = $cron[ self::CRON_HOOK ][ $key ];
		}

		return $events;
	}

	private static function clearScheduledCleanupEvents( string $schedule ): void {
		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( '_set_cron_array' ) ) {
			return;
		}

		$crons = _get_cron_array();
		$key = md5( serialize( [] ) );
		$changed = false;

		foreach ( $crons as $timestamp => $cron ) {
			if (
				! isset( $cron[ self::CRON_HOOK ][ $key ] ) ||
				$schedule !== ( $cron[ self::CRON_HOOK ][ $key ]['schedule'] ?? false )
			) {
				continue;
			}

			unset( $crons[ $timestamp ][ self::CRON_HOOK ][ $key ] );
			$changed = true;

			if ( empty( $crons[ $timestamp ][ self::CRON_HOOK ] ) ) {
				unset( $crons[ $timestamp ][ self::CRON_HOOK ] );
			}

			if ( empty( $crons[ $timestamp ] ) ) {
				unset( $crons[ $timestamp ] );
			}
		}

		if ( $changed ) {
			_set_cron_array( $crons );
		}
	}

	private static function functionAcceptsParameter( string $functionName, int $parameterCount ): bool {
		try {
			return ( new \ReflectionFunction( $functionName ) )->getNumberOfParameters() >= $parameterCount;
		} catch ( \ReflectionException $e ) {
			return false;
		}
	}

	private static function isWpError( $value ): bool {
		if ( function_exists( 'is_wp_error' ) ) {
			return is_wp_error( $value );
		}

		return $value instanceof \WP_Error;
	}

	private static function storeScheduleResult( $result, string $type ): void {
		if ( true === $result ) {
			delete_option( self::CRON_LAST_ERROR_OPTION );
			return;
		}

		if ( self::isWpError( $result ) ) {
			update_option(
				self::CRON_LAST_ERROR_OPTION,
				[
					'type'    => $type,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'time'    => time(),
				],
				false
			);
			return;
		}

		if ( false === $result ) {
			update_option(
				self::CRON_LAST_ERROR_OPTION,
				[
					'type'    => $type,
					'code'    => 'schedule_failed',
					'message' => '',
					'time'    => time(),
				],
				false
			);
		}
	}

	private static function getConnectionsTableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'post_connections_' . Database::normalize_table_name( Client::WPCONNECTIONS_CLIENT );
	}

	private static function getConnectionsMetaTableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'post_connections_meta_' . Database::normalize_table_name( Client::WPCONNECTIONS_CLIENT );
	}

	private static function getLogTableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'cf7tg_log';
	}

	private static function getOptionNamesByPrefix( string $prefix ): array {
		global $wpdb;

		return array_map(
			'strval',
			self::runPreparedGetCol(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				[
					$wpdb->options,
					$wpdb->esc_like( $prefix ) . '%',
				]
			)
		);
	}

	private static function placeholderList( int $count, string $placeholder ): string {
		return implode( ', ', array_fill( 0, $count, $placeholder ) );
	}

	private static function prepareSql( string $sql, array $args = [] ): string {
		global $wpdb;

		if ( empty( $args ) ) {
			return $sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (string) $wpdb->prepare( $sql, $args );
	}

	private static function runPreparedGetResults( string $sql, array $args = [] ): array {
		global $wpdb;

		$query = self::prepareSql( $sql, $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_results( $query );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $result ) ? $result : [];
	}

	private static function runPreparedGetCol( string $sql, array $args = [] ): array {
		global $wpdb;

		$query = self::prepareSql( $sql, $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_col( $query );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $result ) ? $result : [];
	}

	private static function runPreparedGetVar( string $sql, array $args = [] ): mixed {
		global $wpdb;

		$query = self::prepareSql( $sql, $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function runPreparedQuery( string $sql, array $args = [] ): int {
		global $wpdb;

		$query = self::prepareSql( $sql, $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $query );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $result;
	}

	private static function dropTable( string $tableName ): void {
		self::runPreparedQuery( 'DROP TABLE IF EXISTS %i', [ $tableName ] );
	}

	private static function tableExists( string $tableName ): bool {
		return (bool) self::runPreparedGetVar( 'SHOW TABLES LIKE %s', [ $tableName ] );
	}
}
