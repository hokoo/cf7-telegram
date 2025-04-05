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
				<h1>%s</h1>
				%s
			</div>
		</div>
HTML;

		printf( $s, __( 'Telegram notificator settings', 'cf7-telegram' ), self::get_settings_content() );
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

		wp_enqueue_style( 'wpcf7telegram-admin-styles', self::pluginUrl() . '/react/build/' . $manifest['files']['main.css'], null, WPCF7TG_VERSION );
		wp_enqueue_script( 'wpcf7telegram-admin', self::pluginUrl() . '/react/build/' . $manifest['files']['main.js'], null, WPCF7TG_VERSION, true );
		wp_localize_script( 'wpcf7telegram-admin', 'cf7TelegramData', array(
			'routes' => [
				'relations' => [
					'bot2channel'  => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/bot2channel/' ),
					'chat2channel'  => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/chat2channel/' ),
					'form2channel'  => get_rest_url( null, 'wp-connections/v1' . '/client/cf7-telegram/relation/form2channel/' ),
				],

				'client'   => get_rest_url( null, 'wp-connections/v1' . '/client/' . Client::WPCONNECTIONS_CLIENT ),
				'channels' => get_rest_url( null, 'wp/v2' . '/cf7tg_channel/' ),
				'bots'     => get_rest_url( null, 'wp/v2' . '/cf7tg_bot/' ),
				'chats'    => get_rest_url( null, 'wp/v2' . '/cf7tg_chat/' ),
				'forms'    => get_rest_url( null, 'contact-form-7/v1' . '/contact-forms/' ),
			],

			// Put this nonce to X-WP-Nonce header request.
			'nonce'		        => wp_create_nonce( 'wp_rest' ),
			'l10n'		        => [
				'channel' => [
					'new_channel_name'  	    => __( 'New Channel Name', 'cf7-telegram' ),
					'create_new_channel'	    => __( 'Create new channel', 'cf7-telegram' ),
					'rename_channel'		    => __( 'Rename channel', 'cf7-telegram' ),
					'connect_form'              => __( 'Connect form', 'cf7-telegram' ),
					'connect_bot'               => __( 'Connect bot', 'cf7-telegram' ),
					/* translators: channel name */
					'confirm_disconnect_bot'    => __( 'Disconnect this bot from %s channel?', 'cf7-telegram' ),
					/* translators: channel name */
					'confirm_remove_channel'    => __( 'Do you really want to remove %s channel?', 'cf7-telegram' ),
					/* translators: 1. form name, 2. channel name */
					'confirm_disconnect_form'   => __( 'Do you really want to disconnect %1$s form from %2$s channel?', 'cf7-telegram' ),
					'pause_chat'                => __( 'Pause', 'cf7-telegram' ),
					'resume_chat'               => __( 'Resume', 'cf7-telegram' ),
				],
				'bot'   => [
					'bot'       =>  __( 'Bot', 'cf7-telegram' ),
					'api_key'   =>  __( 'API Key', 'cf7-telegram' ),
				],
				'chat'  => [
					'confirm_approve'	=> __( 'Do you really want to approve?', 'cf7-telegram' ),
					'confirm_refuse'	=> __( 'Do you really want to refuse?', 'cf7-telegram' ),
					'confirm_pause'     => __( 'Do you really want to pause?', 'cf7-telegram' ),
					'approved'          => __( 'Successfully approved', 'cf7-telegram' ),
					'refused'           => __( 'Request refused', 'cf7-telegram' ),
					'chat_is_muted'     => __( 'Muted', 'cf7-telegram' ),
					'mute_chat'         => __( 'Mute', 'cf7-telegram' ),
					'remove_chat'       => __( 'Remove', 'cf7-telegram' ),
					'activate_chat'     => __( 'Activate', 'cf7-telegram' ),
				],
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
