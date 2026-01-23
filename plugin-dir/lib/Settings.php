<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Controllers\Migration;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Settings {
	const OPTION_PREFIX = 'cf7t_';
	const EARLY_FLAG_OPTION = self::OPTION_PREFIX . 'early_access';

	static function init(): void {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'wpcf7', 'CF7 Telegram', 'CF7 Telegram', self::getCaps(), 'wpcf7_tg', [ self::class, 'plugin_menu_cbf' ] );
		} );
		add_action( 'current_screen', [ self::class, 'initScreen' ], 999 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'admin_enqueue_scripts' ] );
		add_action( 'admin_post_cf7tg_migration_action', [ self::class, 'handle_migration_action' ] );

		self::getEarlyFlag() && self::initPreReleases();
	}

	public static function getEarlyFlag(): bool {
		return filter_var( get_option( self::EARLY_FLAG_OPTION, false ), FILTER_VALIDATE_BOOLEAN );
	}

	public static function setEarlyFlag( $value ): void {
		update_option( self::EARLY_FLAG_OPTION, $value, false );
	}

	public static function getCaps(): string {
		return CPT::get_instance()->cf7_orig_capabilities['edit_posts'];
	}

        public static function plugin_menu_cbf(){
                $migration_notice = '';

                if ( wp_next_scheduled( Migration::MIGRATION_HOOK ) ) {
                        $migration_notice = sprintf(
                                '<div class="notice cf7t-notice notice-info"><p>%s</p></div>',
                                esc_html__( 'Data migration to the new plugin version is in progress. Please reload the page after a few seconds.', 'cf7-telegram' ),
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

		wp_enqueue_style( 'cf7-telegram-admin-styles', self::pluginUrl() . '/react/build/static/css/main.css', null, WPCF7TG_VERSION );
		wp_enqueue_style( 'gf-styles', 'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap', null, WPCF7TG_VERSION );
		wp_enqueue_script( 'cf7-telegram-admin', self::pluginUrl() . '/react/build/static/js/main.js', ['wp-i18n'], WPCF7TG_VERSION, true );
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
				'settings' => get_rest_url( null, 'wp/v2' . '/settings/' ),
			],

			'options' => [
				'early_access' => self::EARLY_FLAG_OPTION,
			],

			// Put this nonce to X-WP-Nonce header request.
			'nonce'	  => wp_create_nonce( 'wp_rest' ),
			'phrases' => [
				'empty' => Bot::getEmptyToken(),
			],

			'migration' => [
				'show_action_button' => self::shouldShowMigrationActionButton(),
				'action_url' => admin_url( 'admin-post.php' ),
				'nonce' => wp_create_nonce( 'cf7tg_migration_action' ),
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
		if ( ( ! defined( 'WPFC7TG_BOT_TOKEN' ) ) && empty( get_option( 'wpcf7_telegram_tkn' ) ) ) {
			return false;
		}

		if ( ! empty( get_option( Migration::FIX_1_0_FLAG, false ) ) ) {
			return false;
		}

		return true;
	}

	public static function handle_migration_action(): void {
		if ( ! current_user_can( self::getCaps() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cf7-telegram' ) );
		}

		check_admin_referer( 'cf7tg_migration_action', 'cf7tg_migration_nonce' );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wpcf7_tg' );

		// Exit if a migration was already performed.
		if ( ! empty( get_option( Migration::FIX_1_0_FLAG, false ) ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		// Set 'fix_1.0_migration' flag to true to indicate migration is needed.
		update_option( Migration::FIX_1_0_FLAG, true, false );

		// Schedule the migration manually.
		wp_schedule_single_event(
			time(),
			Migration::MIGRATION_HOOK,
			[
				[],
				'0.9',
			]
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private static function get_settings_content() : string {
		return file_get_contents( self::pluginDir() . '/react/build/settings-content.html' ) ?: '';
	}

	private static function initPreReleases(): void {
		$updateChecker = PucFactory::buildUpdateChecker(
			'https://github.com/hokoo/cf7-telegram',
			WPCF7TG_FILE,
			'cf7-telegram',
			1
		);

		defined( 'WPCF7TG_GITHUB_TOKEN' ) && $updateChecker->setAuthentication( WPCF7TG_GITHUB_TOKEN );

		$updateChecker->setBranch( 'plugin-dist' );
	}
}
