<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Logger;
use iTRON\cf7Telegram\Settings;
use iTRON\cf7Telegram\Util;

class Migration {
	// This is a migration class. It is used to migrate the plugin from one version to another.
	// Singleton. Use getInstance() method for instance creating.

	const MIGRATION_HOOK = 'cf7tg_migrations';

	private static Migration $instance;

	/**
	 * Use the get_instance() method for instance creating.
	 */
	protected function __construct() {
	}

	protected function __clone() {
	}

	public function __wakeup() {
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
		add_action( self::MIGRATION_HOOK, [ self::getInstance(), 'migrate' ], 10, 2 );
	}

	/**
	 * Schedules a migration event if the plugin was updated.
	 *
	 * @param $upgrader
	 * @param array $hook_extra
	 *
	 * @return void
	 */
	public function verifyUpgrading( $upgrader, array $hook_extra ): void {
		if ( 'update' !== $hook_extra['action'] || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		if (
			empty( $hook_extra['plugins'] ) ||
			! is_array( $hook_extra['plugins'] ) ||
			! in_array( WPCF7TG_PLUGIN_NAME, $hook_extra['plugins'] )
		) {
			return;
		}

		wp_schedule_single_event(
			time() + 5,
			self::MIGRATION_HOOK,
			[
				$upgrader,
				WPCF7TG_VERSION,
			]
		);
	}

	public function migrate( $upgrader, $prev_version ): void {
		$this->loadMigrations();

		update_option( 'cf7tg_version', WPCF7TG_VERSION );

		do_action( 'cf7_telegram_migrations', $prev_version, WPCF7TG_VERSION, $upgrader );
	}

	public static function registerMigration( $migration_version, callable $migration_function ): void {
		add_action( 'cf7_telegram_migrations',
			function ( $old_version, $new_version, $upgrader ) use ( $migration_version, $migration_function ) {
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
					do_action( 'cf7_telegram_migration', $migration_version, $old_version, $new_version );

					try {
						call_user_func( $migration_function, $old_version, $new_version, $upgrader );
					} catch ( \Exception|\Error $e ) {
						( new Logger() )->write(
							[
								'migration_v' => $migration_version,
								'old_v'       => $old_version,
								'new_v'       => $new_version,
								$e->getMessage()
							],
							'Migration error',
							Logger::LEVEL_CRITICAL,
						);
					}

					if ( ! empty( get_option( 'cf7tg_migration_' . $migration_version ) ) ) {
						( new Logger() )->write(
							[
								'migration_v' => $migration_version,
								'old_v'       => $old_version,
								'new_v'       => $new_version,
							],
							'Migration already done',
							Logger::LEVEL_ATTENTION,
						);
					} else {
						update_option( 'cf7tg_migration_' . $migration_version, compact( $old_version, $new_version ), false );
					}
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

	public static function stripPrerelease( string $version ): string {
		return preg_replace( '/[-+].*/', '', $version );
	}
}
