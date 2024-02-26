<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Settings;
use WP_Upgrader;

class Migration {
	// This is a migration class. It is used to migrate the plugin from one version to another.
	// Singleton. Use getInstance() method for instance creating.

	private static Migration $instance;

	/**
	 * Use get_instance() method for instance creating.
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

	public static function init() {
		add_action( 'upgrader_process_complete', [ self::getInstance(), 'checkMigrate' ], 10, 2 );
	}

	/**
	 * Checks if the plugin was updated and performs migration if necessary.
	 *
	 * @param $upgrader
	 * @param array $hook_extra
	 *
	 * @return void
	 */
	public function checkMigrate( $upgrader, array $hook_extra ) {
		if ( 'update' !== $hook_extra['action'] || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		if ( ! is_array( $hook_extra['plugins'] ) || ! in_array( WPCF7TG_PLUGIN_NAME, $hook_extra['plugins'] ) ) {
			return;
		}

		$this->loadMigrations();

		$old_version = get_option( 'cf7tg_version', '0.6' );
		update_option( 'cf7tg_version', WPCF7TG_VERSION );

		do_action( 'cf7_telegram_migrations', $old_version, WPCF7TG_VERSION );
	}

	public static function registerMigration ( $migration_version, callable $migration_function ) {
		add_action( 'cf7_telegram_migrations', function ( $old_version, $new_version ) use ( $migration_version, $migration_function ) {
			if (
				version_compare(
					$old_version,
					$migration_version,
					'<'
				) && version_compare(
					$new_version,
					$migration_version,
					'>='
				)
			) {
				// todo Exception handling
				$migration_function( $old_version, $new_version );
			}
		}, (int) $migration_version * 10, 2 );
	}

	private function loadMigrations() {
		foreach ( glob( Settings::pluginDir() . '/inc/migrations/*.php' ) as $file ) {
			require_once $file;
		}
	}

	// todo Write tests
	public static function test() {
		// Set the cf7tg_version option to any value to test the migration.
		do_action('upgrader_process_complete', [], ['type'=>'plugin', 'action'=>'update', 'plugins' => ['cf7-telegram/cf7-telegram.php'] ] );
	}
}
