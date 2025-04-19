<?php

namespace iTRON\cf7Telegram;

class Settings {
	 static function init() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'wpcf7', 'CF7 Telegram', 'CF7 Telegram', 'wpcf7_read_contact_forms', 'wpcf7_tg', [ self::class, 'plugin_menu_cbf' ] );
		} );
		add_action( 'current_screen', [ self::class, 'initScreen' ], 999 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'admin_enqueue_scripts' ] );
	}

	public static function plugin_menu_cbf(){
		$s = <<<HTML
		<div id="cf7-telegram-container">
			<div class="wrap">
				%s
			</div>
		</div>
HTML;

		printf( $s, self::get_settings_content() );
	}

	public static function initScreen(){
		$screen = get_current_screen();
		if ( false === strpos( $screen->id, 'wpcf7_tg' ) ) return;
		do_action( 'wpcf7_telegram_settings' );
	}

	public static function admin_enqueue_scripts(){
		if ( ! did_action( 'wpcf7_telegram_settings' ) ) return;

		$json_manifest = self::pluginDir() . '/react/build/asset-manifest.json';
		if ( ! file_exists( $json_manifest ) ) {
			wp_die( 'React build not found' );
		}

		$manifest = json_decode( file_get_contents( $json_manifest ), true );

		wp_enqueue_style( 'cf7-telegram-admin-styles', self::pluginUrl() . '/react/build/' . $manifest['files']['main.css'], null, WPCF7TG_VERSION );
		wp_enqueue_style( 'gf-styles', 'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap', null, WPCF7TG_VERSION );
		wp_enqueue_script( 'cf7-telegram-admin', self::pluginUrl() . '/react/build/' . $manifest['files']['main.js'], null, WPCF7TG_VERSION, true );

		wp_set_script_translations(
			'cf7-telegram-admin',
			'cf7-telegram',
			self::pluginDir() . '/languages'
		);

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
			'nonce'		        => wp_create_nonce( 'wp_rest' ),

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

	private static function get_settings_content() : string {
		return file_get_contents( self::pluginDir() . '/assets/settings-content.html' );
	}
}
