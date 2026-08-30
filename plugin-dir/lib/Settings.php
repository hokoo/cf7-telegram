<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Controllers\Migration;

class Settings {
	const OPTION_PREFIX = 'cf7t_';

	static function init(): void {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'wpcf7', 'CF7 Telegram', 'CF7 Telegram', self::getCaps(), 'wpcf7_tg', [ self::class, 'plugin_menu_cbf' ] );
		} );
		add_action( 'current_screen', [ self::class, 'initScreen' ], 999 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'admin_enqueue_scripts' ] );
		add_action( 'admin_post_cf7tg_migration_action', [ self::class, 'handle_migration_action' ] );
	}

	public static function getCaps(): string {
		return CPT::get_instance()->cf7_orig_capabilities['edit_posts'];
	}

	public static function plugin_menu_cbf(){
                $migration_notice = '';
                $migration = Migration::getAdminRecoveryState();

                if ( $migration['is_scheduled'] || $migration['is_running'] ) {
                        $migration_notice = sprintf(
                                '<div class="notice cf7t-notice notice-info"><p>%s</p></div>',
                                esc_html__( 'Data migration to the new plugin version is in progress. Please reload the page after a few seconds.', 'cf7-telegram' ),
                        );
                } elseif ( $migration['is_failed'] ) {
                        $message = $migration['last_error']['message'] ?? '';
                        $migration_notice = sprintf(
                                '<div class="notice cf7t-notice notice-error"><p>%s</p></div>',
                                esc_html( trim( __( 'Data migration failed. You can retry it below.', 'cf7-telegram' ) . ' ' . $message ) ),
                        );
                }

                $s = '
                <div id="cf7-telegram-container">
                        <div class="wrap">
                                %s
                        </div>
                </div>';

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                printf( $s, $migration_notice . self::get_settings_content() );
        }

	public static function initScreen(){
		$screen = get_current_screen();
		if ( false === strpos( $screen->id, 'wpcf7_tg' ) ) return;
		do_action( 'wpcf7_telegram_settings' );
	}

	public static function admin_enqueue_scripts(){
		if ( ! did_action( 'wpcf7_telegram_settings' ) ) return;

		$asset = self::getReactBuildAsset();

		wp_enqueue_style( 'cf7-telegram-admin-styles', self::pluginUrl() . '/react/build/static/css/main.css', [], $asset['version'] );
		wp_enqueue_style( 'gf-styles', 'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap', null, WPCF7TG_VERSION );
		wp_enqueue_script( 'cf7-telegram-admin', self::pluginUrl() . '/react/build/static/js/main.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'cf7-telegram-admin', 'cf7-telegram' );

		wp_localize_script( 'cf7-telegram-admin', 'cf7TelegramData', array(
			'routes' => [
				'relations' => [
					'bot2channel'  => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/bot2channel/' ),
					'chat2channel' => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/chat2channel/' ),
					'form2channel' => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/form2channel/' ),
					'bot2chat'     => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/bot2chat/' ),
				],

				'client'   => get_rest_url( null, 'wp-connections/v1' . '/client/' . Client::WPCONNECTIONS_CLIENT ),
				'channels' => get_rest_url( null, 'wp/v2' . '/cf7tg_channel/' ),
				'bots'     => get_rest_url( null, 'wp/v2' . '/cf7tg_bot/' ),
				'chats'    => get_rest_url( null, 'wp/v2' . '/cf7tg_chat/' ),
				'forms'    => get_rest_url( null, 'contact-form-7/v1' . '/contact-forms/' ),
			],

			// Put this nonce to X-WP-Nonce header request.
			'nonce'	  => wp_create_nonce( 'wp_rest' ),
			'phrases' => [
				'empty' => Bot::getEmptyToken(),
			],

			'migration' => [
				'show_action_button' => self::shouldShowMigrationActionButton(),
				'action_url'         => admin_url( 'admin-post.php' ),
				'nonce'              => wp_create_nonce( 'cf7tg_migration_action' ),
				'status'             => self::getMigrationUiData(),
			],

			'intervals' => [
				'ping'      => defined( 'WPCF7TG_PING_INTERVAL' ) ? WPCF7TG_PING_INTERVAL : 5000,
				'bot_fetch' => defined( 'WPCF7TG_UPDATES_INTERVAL' ) ? WPCF7TG_UPDATES_INTERVAL : 30000,
			],
		) );
	}

	public static function pluginUrl() {
		return untrailingslashit( plugins_url( '/', WPCF7TG_FILE ) );
	}

	public static function pluginDir(): string {
		return untrailingslashit( plugin_dir_path( WPCF7TG_FILE ) );
	}

	static function shouldShowMigrationActionButton(): bool {
		return Migration::getAdminRecoveryState()['show_action_button'];
	}

	public static function getMigrationUiData(): array {
		$state = Migration::getAdminRecoveryState();

		return [
			'status'       => $state['status'],
			'can_retry'    => $state['can_retry'],
			'is_scheduled' => $state['is_scheduled'],
			'is_running'   => $state['is_running'],
			'is_failed'    => $state['is_failed'],
			'is_completed' => $state['is_completed'],
			'attempts'     => $state['attempts'],
			'current_step' => $state['current_step'],
			'last_error'   => $state['last_error'],
		];
	}

	public static function handle_migration_action(): void {
		if ( ! current_user_can( self::getCaps() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cf7-telegram' ) );
		}

		check_admin_referer( 'cf7tg_migration_action', 'cf7tg_migration_nonce' );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wpcf7_tg' );

		self::requestMigrationRetry();

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function requestMigrationRetry(): bool {
		return Migration::getInstance()->scheduleAdminRetry();
	}

	private static function get_settings_content() : string {
		return file_get_contents( self::pluginDir() . '/react/build/settings-content.html' ) ?: '';
	}

	private static function getReactBuildAsset(): array {
		$asset_path = self::pluginDir() . '/react/build/static/js/main.asset.php';
		$asset      = file_exists( $asset_path ) ? include $asset_path : [];

		if ( ! is_array( $asset ) ) {
			$asset = [];
		}

		$dependencies = $asset['dependencies'] ?? [];

		if ( ! is_array( $dependencies ) ) {
			$dependencies = [];
		}

		$dependencies[] = 'wp-i18n';

		return [
			'dependencies' => array_values( array_unique( $dependencies ) ),
			'version'      => isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : WPCF7TG_VERSION,
		];
	}
}
