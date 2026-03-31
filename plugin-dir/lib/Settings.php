<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Controllers\Migration;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Settings {
	const OPTION_PREFIX = 'cf7t_';
	const EARLY_FLAG_OPTION = self::OPTION_PREFIX . 'early_access';
	const MIGRATION_ADMIN_BAR_NODE = 'cf7tg-migration-status';
	const MANUAL_MIGRATION_VERSION = '1.0-alpha';
	const MANUAL_MIGRATION_PRE_VERSION = '0.9';

		static function init(): void {
			add_action( 'admin_menu', function () {
				add_submenu_page( 'wpcf7', 'CF7 Telegram', 'CF7 Telegram', self::getCaps(), 'wpcf7_tg', [ self::class, 'plugin_menu_cbf' ] );
			} );
			add_action( 'current_screen', [ self::class, 'initScreen' ], 999 );
			add_action( 'admin_enqueue_scripts', [ self::class, 'admin_enqueue_scripts' ] );
			add_action( 'admin_post_cf7tg_migration_action', [ self::class, 'handle_migration_action' ] );
			add_action( 'admin_bar_menu', [ self::class, 'addMigrationAdminBarNode' ], 999 );
			add_action( 'admin_head', [ self::class, 'printMigrationAdminBarStyles' ] );
			add_action( 'wp_head', [ self::class, 'printMigrationAdminBarStyles' ] );

			self::getEarlyFlag() && self::initPreReleases();
		}

	public static function getEarlyFlag(): bool {
		return filter_var( get_option( self::EARLY_FLAG_OPTION, false ), FILTER_VALIDATE_BOOLEAN );
	}

	public static function setEarlyFlag( $value ): void {
		update_option( self::EARLY_FLAG_OPTION, $value, false );
	}

	public static function getCaps(): string {
		return CPT::get_instance()->cf7_orig_capabilities['edit_posts'] ?? 'manage_options';
	}

	public static function plugin_menu_cbf(){
		$migration_notice = self::getMigrationNoticeMarkup();

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
				'status' => self::getMigrationStatus()['state'],
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
		return 'manual-required' === self::getMigrationStatus()['state'];
	}

	public static function handle_migration_action(): void {
		if ( ! current_user_can( self::getCaps() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cf7-telegram' ) );
		}

		check_admin_referer( 'cf7tg_migration_action', 'cf7tg_migration_nonce' );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wpcf7_tg' );

		if ( ! self::isManualMigrationRequired() ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		// Preserve the legacy flag for compatibility with existing installs.
		update_option( Migration::FIX_1_0_FLAG, true, false );

		wp_schedule_single_event(
			time(),
			Migration::MIGRATION_HOOK,
			[
				[],
				self::MANUAL_MIGRATION_PRE_VERSION,
			]
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private static function get_settings_content() : string {
		return file_get_contents( self::pluginDir() . '/react/build/settings-content.html' ) ?: '';
	}

	public static function getMigrationStatus(): array {
		static $status = null;

		if ( null !== $status ) {
			return $status;
		}

		$settings_url = admin_url( 'admin.php?page=wpcf7_tg' );
		$scheduled_at = Migration::getScheduledTimestamp();

		if ( Migration::isRunning() ) {
			$status = [
				'state'           => 'running',
				'admin_bar_title' => __( 'CF7 Telegram: migration running', 'cf7-telegram' ),
				'message'         => __( 'CF7 Telegram data migration is currently running. Please reload the page after a few seconds.', 'cf7-telegram' ),
				'notice_class'    => 'notice-warning',
				'url'             => $settings_url,
			];

			return $status;
		}

		if ( false !== $scheduled_at ) {
			$status = [
				'state'           => 'scheduled',
				'admin_bar_title' => __( 'CF7 Telegram: migration scheduled', 'cf7-telegram' ),
				'message'         => __( 'CF7 Telegram data migration is scheduled and should start shortly. Please reload the page after a few seconds.', 'cf7-telegram' ),
				'notice_class'    => 'notice-info',
				'url'             => $settings_url,
			];

			return $status;
		}

		if ( self::isManualMigrationRequired() ) {
			$status = [
				'state'           => 'manual-required',
				'admin_bar_title' => __( 'CF7 Telegram: run migration', 'cf7-telegram' ),
				'message'         => __( 'Legacy CF7 Telegram settings were found. Open the plugin settings page and run the migration manually.', 'cf7-telegram' ),
				'notice_class'    => 'notice-error',
				'url'             => $settings_url,
			];

			return $status;
		}

		$status = [
			'state' => 'idle',
			'url'   => $settings_url,
		];

		return $status;
	}

	private static function isManualMigrationRequired(): bool {
		if ( Migration::isScheduled() || Migration::isRunning() ) {
			return false;
		}

		if ( ! self::hasLegacyMigrationSource() ) {
			return false;
		}

		if ( self::hasMigratedEntities() ) {
			return false;
		}

		if ( Migration::isCompleted( self::MANUAL_MIGRATION_VERSION ) ) {
			return false;
		}

		return true;
	}

	private static function hasLegacyMigrationSource(): bool {
		if ( defined( 'WPFC7TG_BOT_TOKEN' ) ) {
			return true;
		}

		return ! empty( get_option( 'wpcf7_telegram_tkn' ) );
	}

	private static function hasMigratedEntities(): bool {
		return self::countPosts( Client::CPT_BOT ) > 0 || self::countPosts( Client::CPT_CHANNEL ) > 0;
	}

	private static function countPosts( string $post_type ): int {
		$counts = wp_count_posts( $post_type );

		return is_object( $counts ) ? array_sum( array_map( 'intval', get_object_vars( $counts ) ) ) : 0;
	}

	private static function getMigrationNoticeMarkup(): string {
		$migration_status = self::getMigrationStatus();

		if ( ! in_array( $migration_status['state'], [ 'scheduled', 'running' ], true ) ) {
			return '';
		}

		return sprintf(
			'<div class="notice cf7t-notice %1$s"><p>%2$s</p></div>',
			esc_attr( $migration_status['notice_class'] ),
			esc_html( $migration_status['message'] )
		);
	}

	public static function addMigrationAdminBarNode( \WP_Admin_Bar $admin_bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( self::getCaps() ) ) {
			return;
		}

		$migration_status = self::getMigrationStatus();

		if ( 'idle' === $migration_status['state'] ) {
			return;
		}

		$admin_bar->add_node( [
			'id'    => self::MIGRATION_ADMIN_BAR_NODE,
			'title' => sprintf(
				'<span class="ab-label">%s</span>',
				esc_html( $migration_status['admin_bar_title'] )
			),
			'href'  => $migration_status['url'],
			'meta'  => [
				'class' => 'cf7tg-migration-status cf7tg-migration-status--' . $migration_status['state'],
				'title' => $migration_status['message'],
			],
		] );
	}

	public static function printMigrationAdminBarStyles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$migration_status = self::getMigrationStatus();

		if ( 'idle' === $migration_status['state'] ) {
			return;
		}
		?>
		<style id="cf7tg-migration-admin-bar-styles">
			#wpadminbar .cf7tg-migration-status > .ab-item {
				color: #fff !important;
				font-weight: 700;
				box-shadow: inset 0 -3px 0 rgba(0, 0, 0, 0.18);
			}

			#wpadminbar .cf7tg-migration-status--scheduled > .ab-item,
			#wpadminbar .cf7tg-migration-status--scheduled:hover > .ab-item,
			#wpadminbar .cf7tg-migration-status--scheduled.hover > .ab-item {
				background: #dba617 !important;
			}

			#wpadminbar .cf7tg-migration-status--running > .ab-item,
			#wpadminbar .cf7tg-migration-status--running:hover > .ab-item,
			#wpadminbar .cf7tg-migration-status--running.hover > .ab-item {
				background: #d63638 !important;
			}

			#wpadminbar .cf7tg-migration-status--manual-required > .ab-item,
			#wpadminbar .cf7tg-migration-status--manual-required:hover > .ab-item,
			#wpadminbar .cf7tg-migration-status--manual-required.hover > .ab-item {
				background: #b32d2e !important;
			}
		</style>
		<?php
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
