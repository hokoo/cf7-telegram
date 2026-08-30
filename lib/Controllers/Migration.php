<?php

namespace iTRON\cf7Telegram\Controllers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Logger;
use iTRON\cf7Telegram\Settings;
use iTRON\cf7Telegram\Util;

class Migration {
	// This is a migration class. It is used to migrate the plugin from one version to another.
	// Singleton. Use getInstance() method for instance creating.

	const MIGRATION_HOOK = 'cf7tg_migrations';
	const FIX_1_0_FLAG = 'cf7tg_fix_1.0_migration';
	public const STATE_OPTION = 'cf7tg_migration_state';
	public const LOCK_OPTION = 'cf7tg_migration_lock';
	public const STATE_SCHEMA = 1;
	public const LOCK_TTL = 300;

	private const LEGACY_REPAIR_VERSION = '0.0';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_RUNNING = 'running';
	public const STATUS_FAILED = 'failed';
	public const STATUS_COMPLETED = 'completed';

	private static Migration $instance;
	private ?string $lockToken = null;

	/**
	 * Use the get_instance() method for instance creating.
	 */
	protected function __construct() {
	}

	protected function __clone() {
	}

	public function __wakeup() {
		// Prevent deserialization of the instance.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		trigger_error( 'Deserializing of iTRON\cf7Telegram\Controllers\Migration instance is prohibited.',
			E_USER_NOTICE );
	}

	/**
	 * @return Migration
	 */
	public static function getInstance(): Migration {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function init(): void {
		add_action( 'upgrader_process_complete', [ self::getInstance(), 'verifyUpgrading' ], 10, 2 );
		add_action( 'init', [ self::getInstance(), 'ensureLegacyMigrationScheduled' ], 25 );
		add_action( self::MIGRATION_HOOK, [ self::getInstance(), 'migrate' ], 10, 3 );
	}

	/**
	 * Schedules a migration event if the plugin was updated.
	 *
	 * This function is run with the old version right before switching to the new version.
	 * So that migrations shipped with the new version can be executed by a scheduled event.
	 *
	 * @param $upgrader
	 * @param array $hook_extra
	 *
	 * @return void
	 */
	public function verifyUpgrading( $upgrader, array $hook_extra ): void {
		if ( 'update' !== ( $hook_extra['action'] ?? null ) || 'plugin' !== ( $hook_extra['type'] ?? null ) ) {
			return;
		}

		$plugins = [];
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$plugins[] = $hook_extra['plugin'];
		}

		if ( ! in_array( WPCF7TG_PLUGIN_NAME, $plugins, true ) ) {
			return;
		}

		$this->scheduleMigration( 'plugin_update', self::resolveSourceVersion(), WPCF7TG_VERSION, 30 );
	}

	public function ensureLegacyMigrationScheduled(): void {
		$state = self::getMigrationState();

		if ( self::isCompletedState( $state ) ) {
			return;
		}

		if ( ! self::needsMigrationRepair( $state ) ) {
			return;
		}

		if ( self::hasActiveMigrationLock() ) {
			return;
		}

		self::clearStaleMigrationLock();
		$this->scheduleMigration( 'self_heal', self::resolveSourceVersion(), WPCF7TG_VERSION, 0 );
	}

	public function migrate( $reason = null, $sourceVersion = null, $targetVersion = null ): void {
		$context = self::normalizeMigrationContext( func_get_args() );

		if ( self::isCompletedState( self::getMigrationState() ) ) {
			return;
		}

		if ( ! $this->acquireMigrationLock() ) {
			$this->scheduleMigration(
				'lock_retry',
				$context['source_version'],
				$context['target_version'],
				self::LOCK_TTL
			);
			return;
		}

		try {
			self::markRunStarted( $context );
			$this->loadMigrations();

			do_action(
				'cf7tg_telegram_migrations',
				$context['source_version'],
				$context['target_version'],
				$context
			);

			$state = self::getMigrationState();
			if ( self::STATUS_FAILED === ( $state['status'] ?? '' ) ) {
				return;
			}

			update_option( 'cf7tg_version', $context['target_version'], false );
			update_option( self::FIX_1_0_FLAG, true, false );
			self::markRunCompleted( $context );
		} catch ( \Throwable $e ) {
			self::markStepFailed( '__runner__', $context, $e );
			self::logMigrationError( '__runner__', $context, $e );
		} finally {
			$this->releaseMigrationLock();
		}
	}

	public static function getMigrationState(): array {
		$state = get_option( self::STATE_OPTION, [] );
		if ( empty( $state ) ) {
			return [];
		}

		return is_array( $state ) ? self::normalizeMigrationState( $state ) : [];
	}

	public static function getAdminRecoveryState(): array {
		$state = self::getMigrationState();
		$status = $state['status'] ?? '';
		$scheduled = self::hasScheduledMigrationEvent();
		$running = self::STATUS_RUNNING === $status || self::hasActiveMigrationLock();
		$completed = self::isCompletedState( $state );
		$repairable = ! $completed && self::needsMigrationRepair( $state );
		$canRetry = $repairable && ! $scheduled && ! $running;

		return [
			'show_action_button' => $repairable,
			'can_retry'          => $canRetry,
			'is_scheduled'       => $scheduled,
			'is_running'         => $running,
			'is_failed'          => self::STATUS_FAILED === $status,
			'is_completed'       => $completed,
			'status'             => $status ?: ( $repairable ? self::STATUS_SCHEDULED : '' ),
			'attempts'           => (int) ( $state['attempts'] ?? 0 ),
			'current_step'       => (string) ( $state['current_step'] ?? '' ),
			'last_error'         => self::lastMigrationError( $state ),
			'state'              => $state,
		];
	}

	public function scheduleAdminRetry(): bool {
		$state = self::getMigrationState();

		if ( self::isCompletedState( $state ) || ! self::needsMigrationRepair( $state ) ) {
			return false;
		}

		if (
			self::STATUS_RUNNING === ( $state['status'] ?? '' ) ||
			self::hasActiveMigrationLock() ||
			self::hasScheduledMigrationEvent()
		) {
			return false;
		}

		self::clearStaleMigrationLock();

		return $this->scheduleMigration(
			'admin_retry',
			self::normalizeVersionValue( $state['source_version'] ?? self::resolveSourceVersion() ),
			self::normalizeVersionValue( $state['target_version'] ?? WPCF7TG_VERSION, WPCF7TG_VERSION ),
			0
		);
	}

	public static function registerMigration( $migration_version, callable $migration_function ): void {
		add_action( 'cf7tg_telegram_migrations',
			function ( $old_version, $new_version, $context ) use ( $migration_version, $migration_function ) {
				if (
					version_compare(
						$old_version,
						$migration_version,
						'<'
					) && version_compare(
						self::stripPrerelease( $new_version ),
						$migration_version,
						'>='
					)
					) {
						if ( self::STATUS_FAILED === ( self::getMigrationState()['status'] ?? '' ) ) {
							return;
						}

						if (
							self::isStepCompleted( $migration_version ) ||
							(
								! self::hasLegacyMigrationEvidence() &&
								! empty( get_option( 'cf7tg_migration_' . $migration_version ) )
							)
						) {
							self::logMigrationAlreadyDone( $migration_version, $old_version, $new_version );
							return;
						}

						$context = is_array( $context ) ? $context : self::normalizeMigrationContext( [ $context, $old_version, $new_version ] );
						do_action( 'cf7tg_telegram_migration', $migration_version, $old_version, $new_version );

						self::markStepStarted( $migration_version, $context );

						try {
							call_user_func( $migration_function, $old_version, $new_version, $context );
						} catch ( \Throwable $e ) {
							self::markStepFailed( $migration_version, $context, $e );
							self::logMigrationError( $migration_version, $context, $e );
							return;
						}

						self::markStepCompleted( $migration_version, $context );
						update_option( 'cf7tg_migration_' . $migration_version, compact( 'old_version', 'new_version' ), false );
					}
				},
				Util::versionToInt( $migration_version ),
				3 );
	}

	private function loadMigrations(): void {
		foreach ( glob( Settings::pluginDir() . '/inc/migrations/*.php' ) as $file ) {
			require_once $file;
		}
	}

	private function scheduleMigration( string $reason, string $sourceVersion, string $targetVersion, int $delay ): bool {
		$context = [
			'reason'         => $reason,
			'source_version' => $sourceVersion,
			'target_version' => $targetVersion,
		];

		$state = self::prepareStateForContext( $context );

		if ( self::isCompletedState( $state ) ) {
			return false;
		}

		if ( self::hasScheduledMigrationEvent() ) {
			$state['status'] = self::STATUS_SCHEDULED;
			$state['updated_at'] = time();
			self::saveMigrationState( $state );
			return true;
		}

		$state['status'] = self::STATUS_SCHEDULED;
		$state['updated_at'] = time();
		self::saveMigrationState( $state );

		$result = wp_schedule_single_event(
			time() + max( 0, $delay ),
			self::MIGRATION_HOOK,
			array_values( $context )
		);

		if ( false === $result || is_wp_error( $result ) ) {
			self::markScheduleFailed( $context, 'wp_schedule_single_event returned false.' );
			return false;
		}

		return true;
	}

	private static function hasScheduledMigrationEvent(): bool {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return false;
		}

		foreach ( _get_cron_array() as $hooks ) {
			if ( ! empty( $hooks[ self::MIGRATION_HOOK ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function needsMigrationRepair( array $state ): bool {
		if ( ! empty( $state ) && ! self::isCompletedState( $state ) ) {
			return true;
		}

		return self::hasLegacyMigrationEvidence() || self::hasModernMigrationEvidence();
	}

	private static function hasModernMigrationEvidence(): bool {
		$version = get_option( 'cf7tg_version', false );

		return is_scalar( $version ) && '' !== trim( (string) $version );
	}

	private static function hasLegacyMigrationEvidence(): bool {
		return
			self::hasUsableLegacyTokenConstant() ||
			false !== get_option( 'wpcf7_telegram_tkn', false ) ||
			false !== get_option( 'wpcf7_telegram_chats', false ) ||
			false !== get_option( 'wpcf7_telegram_last_update_id', false ) ||
			! empty( get_option( self::FIX_1_0_FLAG, false ) );
	}

	private static function hasUsableLegacyTokenConstant(): bool {
		if ( ! defined( 'WPFC7TG_BOT_TOKEN' ) ) {
			return false;
		}

		$token = constant( 'WPFC7TG_BOT_TOKEN' );

		return is_scalar( $token ) && '' !== trim( (string) $token );
	}

	private static function resolveSourceVersion(): string {
		if ( self::hasLegacyMigrationEvidence() ) {
			return self::LEGACY_REPAIR_VERSION;
		}

		$version = get_option( 'cf7tg_version', self::LEGACY_REPAIR_VERSION );
		if ( ! is_string( $version ) || '' === trim( $version ) ) {
			return self::LEGACY_REPAIR_VERSION;
		}

		return trim( $version );
	}

	private static function normalizeMigrationContext( array $args ): array {
		if ( isset( $args[0] ) && is_string( $args[0] ) && isset( $args[1], $args[2] ) ) {
			return [
				'reason'         => sanitize_key( $args[0] ) ?: 'cron',
				'source_version' => self::normalizeVersionValue( $args[1] ),
				'target_version' => self::normalizeVersionValue( $args[2], WPCF7TG_VERSION ),
			];
		}

		if ( isset( $args[0] ) && is_array( $args[0] ) && isset( $args[0]['reason'] ) ) {
			return [
				'reason'         => sanitize_key( (string) $args[0]['reason'] ) ?: 'cron',
				'source_version' => self::normalizeVersionValue( $args[0]['source_version'] ?? self::LEGACY_REPAIR_VERSION ),
				'target_version' => self::normalizeVersionValue( $args[0]['target_version'] ?? WPCF7TG_VERSION, WPCF7TG_VERSION ),
			];
		}

		$legacySourceVersion = self::LEGACY_REPAIR_VERSION;
		if ( ! self::hasLegacyMigrationEvidence() ) {
			$legacySourceVersion = $args[1] ?? self::legacyPreVersionFromArgument( $args[0] ?? null );
		}

		return [
			'reason'         => 'legacy_cron',
			'source_version' => self::normalizeVersionValue( $legacySourceVersion ),
			'target_version' => self::normalizeVersionValue( $args[2] ?? WPCF7TG_VERSION, WPCF7TG_VERSION ),
		];
	}

	private static function legacyPreVersionFromArgument( $argument ): string {
		if ( is_array( $argument ) && isset( $argument['preVersion'] ) ) {
			return self::normalizeVersionValue( $argument['preVersion'] );
		}

		return self::LEGACY_REPAIR_VERSION;
	}

	private static function normalizeVersionValue( $value, string $fallback = self::LEGACY_REPAIR_VERSION ): string {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		$value = trim( (string) $value );
		return '' === $value ? $fallback : $value;
	}

	private static function prepareStateForContext( array $context ): array {
		$state = self::getMigrationState();
		$now = time();

		if ( empty( $state ) || self::STATUS_COMPLETED === ( $state['status'] ?? '' ) ) {
			$state = [
				'schema'         => self::STATE_SCHEMA,
				'status'         => self::STATUS_SCHEDULED,
				'reason'         => $context['reason'],
				'source_version' => $context['source_version'],
				'target_version' => $context['target_version'],
				'attempts'       => 0,
				'current_step'   => '',
				'steps'          => [],
				'errors'         => [],
				'lock'           => null,
				'created_at'     => $now,
				'updated_at'     => $now,
			];
		}

		$state['reason'] = $context['reason'];
		$state['source_version'] = $context['source_version'];
		$state['target_version'] = $context['target_version'];
		$state['schema'] = self::STATE_SCHEMA;
		$state['updated_at'] = $now;

		return self::normalizeMigrationState( $state );
	}

	private static function normalizeMigrationState( array $state ): array {
		$state['schema'] = (int) ( $state['schema'] ?? self::STATE_SCHEMA );
		$state['status'] = (string) ( $state['status'] ?? self::STATUS_SCHEDULED );
		$state['reason'] = (string) ( $state['reason'] ?? '' );
		$state['source_version'] = (string) ( $state['source_version'] ?? self::LEGACY_REPAIR_VERSION );
		$state['target_version'] = (string) ( $state['target_version'] ?? WPCF7TG_VERSION );
		$state['attempts'] = (int) ( $state['attempts'] ?? 0 );
		$state['current_step'] = (string) ( $state['current_step'] ?? '' );
		$state['steps'] = isset( $state['steps'] ) && is_array( $state['steps'] ) ? $state['steps'] : [];
		$state['errors'] = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : [];
		$state['lock'] = isset( $state['lock'] ) && is_array( $state['lock'] ) ? $state['lock'] : null;
		$state['created_at'] = (int) ( $state['created_at'] ?? time() );
		$state['updated_at'] = (int) ( $state['updated_at'] ?? time() );

		return $state;
	}

	private static function lastMigrationError( array $state ): array {
		$errors = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : [];
		$error = end( $errors );

		if ( ! is_array( $error ) ) {
			return [];
		}

		return [
			'step'    => (string) ( $error['step'] ?? '' ),
			'message' => (string) ( $error['message'] ?? '' ),
			'code'    => (string) ( $error['code'] ?? '' ),
			'time'    => (int) ( $error['time'] ?? 0 ),
		];
	}

	private static function saveMigrationState( array $state ): void {
		update_option( self::STATE_OPTION, self::normalizeMigrationState( $state ), false );
	}

	private static function isCompletedState( array $state ): bool {
		return self::STATUS_COMPLETED === ( $state['status'] ?? '' )
			&& WPCF7TG_VERSION === ( $state['target_version'] ?? '' );
	}

	private static function markRunStarted( array $context ): void {
		$state = self::prepareStateForContext( $context );
		$state['status'] = self::STATUS_RUNNING;
		$state['attempts'] = (int) $state['attempts'] + 1;
		$state['started_at'] = $state['started_at'] ?? time();
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function markRunCompleted( array $context ): void {
		$state = self::prepareStateForContext( $context );
		$state['status'] = self::STATUS_COMPLETED;
		$state['current_step'] = '';
		$state['completed_at'] = time();
		$state['updated_at'] = time();
		$state['lock'] = null;
		self::saveMigrationState( $state );
	}

	private static function markScheduleFailed( array $context, string $message ): void {
		$state = self::prepareStateForContext( $context );
		$state['status'] = self::STATUS_FAILED;
		$state['errors'][] = [
			'step'    => '__schedule__',
			'message' => $message,
			'time'    => time(),
		];
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function markStepStarted( string $step, array $context ): void {
		$state = self::prepareStateForContext( $context );
		$previous = isset( $state['steps'][ $step ] ) && is_array( $state['steps'][ $step ] )
			? $state['steps'][ $step ]
			: [];

		$state['status'] = self::STATUS_RUNNING;
		$state['current_step'] = $step;
		$state['steps'][ $step ] = array_merge(
			$previous,
			[
				'status'         => self::STATUS_RUNNING,
				'attempts'       => (int) ( $previous['attempts'] ?? 0 ) + 1,
				'source_version' => $context['source_version'],
				'target_version' => $context['target_version'],
				'started_at'     => $previous['started_at'] ?? time(),
				'updated_at'     => time(),
			]
		);
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function markStepCompleted( string $step, array $context ): void {
		$state = self::prepareStateForContext( $context );
		$previous = isset( $state['steps'][ $step ] ) && is_array( $state['steps'][ $step ] )
			? $state['steps'][ $step ]
			: [];

		$state['steps'][ $step ] = array_merge(
			$previous,
			[
				'status'         => self::STATUS_COMPLETED,
				'source_version' => $context['source_version'],
				'target_version' => $context['target_version'],
				'completed_at'   => time(),
				'updated_at'     => time(),
			]
		);
		$state['current_step'] = '';
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function markStepFailed( string $step, array $context, \Throwable $e ): void {
		$state = self::prepareStateForContext( $context );
		$previous = isset( $state['steps'][ $step ] ) && is_array( $state['steps'][ $step ] )
			? $state['steps'][ $step ]
			: [];

		$error = [
			'step'    => $step,
			'message' => $e->getMessage(),
			'code'    => (string) $e->getCode(),
			'time'    => time(),
		];

		$state['status'] = self::STATUS_FAILED;
		$state['current_step'] = $step;
		$state['steps'][ $step ] = array_merge(
			$previous,
			[
				'status'       => self::STATUS_FAILED,
				'last_error'   => $error,
				'completed_at' => null,
				'updated_at'   => time(),
			]
		);
		$state['errors'][] = $error;
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function isStepCompleted( string $step ): bool {
		$state = self::getMigrationState();
		return self::STATUS_COMPLETED === ( $state['steps'][ $step ]['status'] ?? '' );
	}

	private function acquireMigrationLock(): bool {
		self::clearStaleMigrationLock();

		$now = time();
		$token = sha1( uniqid( 'cf7tg_migration_', true ) );

		if ( ! add_option( self::LOCK_OPTION, [ 'token' => $token, 'locked_at' => $now ], '', false ) ) {
			return false;
		}

		$this->lockToken = $token;

		$state = self::getMigrationState();
		$state['lock'] = [ 'token' => $token, 'locked_at' => $now ];
		$state['updated_at'] = $now;
		self::saveMigrationState( $state );

		return true;
	}

	private function releaseMigrationLock(): void {
		$lock = get_option( self::LOCK_OPTION, [] );
		if (
			null === $this->lockToken ||
			! is_array( $lock ) ||
			! isset( $lock['token'] ) ||
			! hash_equals( $this->lockToken, (string) $lock['token'] )
		) {
			$this->lockToken = null;
			return;
		}

		delete_option( self::LOCK_OPTION );
		$this->lockToken = null;

		$state = self::getMigrationState();
		if ( empty( $state ) ) {
			return;
		}

		$state['lock'] = null;
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function hasActiveMigrationLock(): bool {
		$lock = get_option( self::LOCK_OPTION, [] );
		if ( ! is_array( $lock ) || empty( $lock['locked_at'] ) ) {
			return false;
		}

		return ( time() - (int) $lock['locked_at'] ) < self::LOCK_TTL;
	}

	private static function clearStaleMigrationLock(): void {
		$lock = get_option( self::LOCK_OPTION, [] );
		if ( ! is_array( $lock ) || empty( $lock['locked_at'] ) ) {
			return;
		}

		if ( ( time() - (int) $lock['locked_at'] ) < self::LOCK_TTL ) {
			return;
		}

		delete_option( self::LOCK_OPTION );

		$state = self::getMigrationState();
		if ( empty( $state ) ) {
			return;
		}

		$state['lock'] = null;
		$state['status'] = self::STATUS_FAILED === ( $state['status'] ?? '' )
			? self::STATUS_FAILED
			: self::STATUS_SCHEDULED;
		$state['updated_at'] = time();
		self::saveMigrationState( $state );
	}

	private static function logMigrationError( string $migrationVersion, array $context, \Throwable $e ): void {
		try {
			( new Logger() )->write(
				[
					'migration_v' => $migrationVersion,
					'old_v'       => $context['source_version'],
					'new_v'       => $context['target_version'],
					'error'       => $e->getMessage(),
				],
				'Migration error',
				Logger::LEVEL_CRITICAL,
			);
		} catch ( \Throwable $loggerError ) {
			return;
		}
	}

	private static function logMigrationAlreadyDone( string $migrationVersion, string $oldVersion, string $newVersion ): void {
		try {
			( new Logger() )->write(
				[
					'migration_v' => $migrationVersion,
					'old_v'       => $oldVersion,
					'new_v'       => $newVersion,
				],
				'Migration already done',
				Logger::LEVEL_ATTENTION,
			);
		} catch ( \Throwable $loggerError ) {
			return;
		}
	}

	public static function stripPrerelease( string $version ): string {
		return preg_replace( '/[-+].*/', '', $version );
	}
}
