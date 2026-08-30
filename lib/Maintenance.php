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
	public const REPAIR_MODE_DRY_RUN = 'dry-run';
	public const REPAIR_MODE_APPLY = 'apply';
	public const REPAIR_PLAN_SCHEMA = 1;

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
		add_action( 'before_delete_post', [ self::class, 'cascadeDeletedPost' ], 10, 2 );
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

		if ( self::hasExpectedRecurringCleanupEvent( $interval ) ) {
			return;
		}

		self::clearRecurringCleanupEvents();
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

			$result = self::runRepair();

			if ( self::hasReportableRepairResult( $result ) ) {
				self::writeLog( self::summarizeRepairResult( $result ), 'Scheduled repair report' );
			}
		} catch ( \Throwable $e ) {
			self::writeLog(
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

	public static function buildRepairPlan(): array {
		$connections = self::getPluginConnections();
		$brokenConnectionIDs = self::getBrokenConnectionIDs( $connections );
		$ambiguousOrphanChatIDs = self::getAmbiguousOrphanChatIDs( $connections );

		return [
			'schema'    => self::REPAIR_PLAN_SCHEMA,
			'planned'   => [
				'delete_connection_ids' => $brokenConnectionIDs,
				'delete_connections'    => count( $brokenConnectionIDs ),
				'delete_chat_ids'       => [],
				'delete_chats'          => 0,
			],
			'preserved' => [
				'ambiguous_orphan_chat_ids' => $ambiguousOrphanChatIDs,
				'ambiguous_orphan_chats'    => count( $ambiguousOrphanChatIDs ),
			],
		];
	}

	public static function runRepair( string $mode = self::REPAIR_MODE_DRY_RUN ): array {
		$mode = self::normalizeRepairMode( $mode );
		$plan = self::buildRepairPlan();
		$result = [
			'schema'    => $plan['schema'],
			'mode'      => $mode,
			'planned'   => $plan['planned'],
			'preserved' => $plan['preserved'],
			'applied'   => [
				'deleted_connections' => 0,
				'deleted_chats'       => 0,
			],
		];

		if ( self::REPAIR_MODE_APPLY === $mode ) {
			$result['applied']['deleted_connections'] = self::deleteConnectionsByIDs( $plan['planned']['delete_connection_ids'] );
		}

		return $result;
	}

	public static function cascadeDeletedPost( int $postID, ?WP_Post $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $postID );
		}

		if ( ! $post instanceof WP_Post || ! in_array( (string) $post->post_type, self::getOwnedPostTypes(), true ) ) {
			return;
		}

		self::deleteConnectionsByObjectIDs( [ $postID ] );
	}

	private static function getBrokenConnectionIDs( ?array $connections = null ): array {
		if ( null === $connections ) {
			$connections = self::getPluginConnections();
		}

		if ( empty( $connections ) ) {
			return [];
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

		return self::normalizeIDs( $connectionIDs );
	}

	private static function getAmbiguousOrphanChatIDs( ?array $connections = null ): array {
		$chatIDs = self::getPostIDs( Client::CPT_CHAT );

		if ( empty( $chatIDs ) ) {
			return [];
		}

		$connectedChatIDs = self::getValidConnectedChatIDs( $connections );

		return self::normalizeIDs( array_diff( $chatIDs, $connectedChatIDs ) );
	}

	private static function getValidConnectedChatIDs( ?array $connections = null ): array {
		if ( null === $connections ) {
			$connections = self::getPluginConnections();
		}

		if ( empty( $connections ) ) {
			return [];
		}

		$postTypeCache = [];
		$chatIDs = [];

		foreach ( $connections as $connection ) {
			if ( Client::BOT2CHAT !== ( $connection->relation ?? '' ) ) {
				continue;
			}

			if (
				Client::CPT_BOT === self::getPostType( (int) $connection->from, $postTypeCache ) &&
				Client::CPT_CHAT === self::getPostType( (int) $connection->to, $postTypeCache )
			) {
				$chatIDs[] = (int) $connection->to;
			}
		}

		return self::normalizeIDs( $chatIDs );
	}

	private static function getPluginConnections(): array {
		$table = self::getConnectionsTableName();

		if ( ! self::tableExists( $table ) ) {
			return [];
		}

		$relations = array_values( array_keys( self::getRelationDefinitions() ) );
		$sql = 'SELECT ID, relation, `from`, `to` FROM ' . self::sqlIdentifier( $table );
		$sql .= ' WHERE relation IN (' . self::placeholderList( count( $relations ), '%s' ) . ')';

		return self::runPreparedGetResults(
			$sql,
			$relations
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
		$sql = 'SELECT ID FROM ' . self::sqlIdentifier( $table );
		$sql .= ' WHERE relation IN (' . self::placeholderList( count( $relations ), '%s' ) . ')';
		$sql .= ' AND (`from` IN (' . self::placeholderList( count( $objectIDs ), '%d' ) . ')';
		$sql .= ' OR `to` IN (' . self::placeholderList( count( $objectIDs ), '%d' ) . '))';

		$connectionIDs = self::runPreparedGetCol(
			$sql,
			array_merge( $relations, $objectIDs, $objectIDs )
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
				'DELETE FROM ' . self::sqlIdentifier( $metaTable ) . ' WHERE connection_id IN (' . $deletePlaceholders . ')',
				$connectionIDs
			);
		}

		return self::runPreparedQuery(
			'DELETE FROM ' . self::sqlIdentifier( $table ) . ' WHERE ID IN (' . $deletePlaceholders . ')',
			$connectionIDs
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
			'SELECT ID FROM ' . self::sqlIdentifier( $table ) . ' WHERE relation IN (' . self::placeholderList( count( $relations ), '%s' ) . ')',
			$relations
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

		if ( ! $post instanceof WP_Post ) {
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

	private static function getOwnedPostTypes(): array {
		return [
			Client::CPT_BOT,
			Client::CPT_CHAT,
			Client::CPT_CHANNEL,
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

	private static function hasExpectedRecurringCleanupEvent( int $interval ): bool {
		$recurringEvents = array_values(
			array_filter(
				self::getScheduledCleanupEvents(),
				static fn( array $event ): bool => ! empty( $event['schedule'] )
			)
		);

		if ( 1 !== count( $recurringEvents ) ) {
			return false;
		}

		$event = $recurringEvents[0];

		return self::CRON_SCHEDULE === ( $event['schedule'] ?? false )
			&& isset( $event['interval'] )
			&& (int) $event['interval'] === $interval;
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

	private static function clearRecurringCleanupEvents(): void {
		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( '_set_cron_array' ) ) {
			return;
		}

		$crons = _get_cron_array();
		$key = md5( serialize( [] ) );
		$changed = false;

		foreach ( $crons as $timestamp => $cron ) {
			if (
				! isset( $cron[ self::CRON_HOOK ][ $key ] ) ||
				empty( $cron[ self::CRON_HOOK ][ $key ]['schedule'] )
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
				'SELECT option_name FROM ' . self::sqlIdentifier( $wpdb->options ) . ' WHERE option_name LIKE %s',
				[
					$wpdb->esc_like( $prefix ) . '%',
				]
			)
		);
	}

	private static function normalizeIDs( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', array_filter( $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	private static function normalizeRepairMode( string $mode ): string {
		if ( in_array( $mode, [ self::REPAIR_MODE_DRY_RUN, self::REPAIR_MODE_APPLY ], true ) ) {
			return $mode;
		}

		throw new \InvalidArgumentException( sprintf( 'Unsupported repair mode: %s', $mode ) );
	}

	private static function hasReportableRepairResult( array $result ): bool {
		return (bool) (
			( $result['planned']['delete_connections'] ?? 0 ) ||
			( $result['planned']['delete_chats'] ?? 0 ) ||
			( $result['preserved']['ambiguous_orphan_chats'] ?? 0 )
		);
	}

	private static function summarizeRepairResult( array $result ): array {
		return [
			'mode'      => $result['mode'] ?? self::REPAIR_MODE_DRY_RUN,
			'planned'   => [
				'delete_connections' => (int) ( $result['planned']['delete_connections'] ?? 0 ),
				'delete_chats'       => (int) ( $result['planned']['delete_chats'] ?? 0 ),
			],
			'applied'   => [
				'deleted_connections' => (int) ( $result['applied']['deleted_connections'] ?? 0 ),
				'deleted_chats'       => (int) ( $result['applied']['deleted_chats'] ?? 0 ),
			],
			'preserved' => [
				'ambiguous_orphan_chats' => (int) ( $result['preserved']['ambiguous_orphan_chats'] ?? 0 ),
			],
		];
	}

	private static function writeLog( $data, string $title = '', int $level = Logger::LEVEL_INFO ): void {
		try {
			( new Logger() )->write( $data, $title, $level );
		} catch ( \Throwable $e ) {
			// Cleanup and deletion hooks must not fail only because logging is unavailable.
		}
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
		self::runPreparedQuery( 'DROP TABLE IF EXISTS ' . self::sqlIdentifier( $tableName ) );
	}

	private static function tableExists( string $tableName ): bool {
		return (bool) self::runPreparedGetVar( 'SHOW TABLES LIKE %s', [ $tableName ] );
	}

	private static function sqlIdentifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
