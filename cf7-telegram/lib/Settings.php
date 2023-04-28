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
		?>
		<div id="cf7-telegram-container">
			<div class="wrap">
				<h1><?php echo __( 'Telegram notificator settings', WPCF7TG_DOMAIN ); ?></h1>
			</div>
		</div>
		<?php
	}

	public static function initScreen(){
		$screen = get_current_screen();
		if ( false === strpos( $screen->id, 'wpcf7_tg' ) ) return;
		do_action( 'wpcf7_telegram_settings' );
	}

	public static function admin_enqueue_scripts(){
		if ( ! did_action( 'wpcf7_telegram_settings' ) ) return;

		wp_enqueue_style( 'wpcf7telegram-admin-styles', self::pluginUrl() . '/assets/css/index.css', null, WPCF7TG_VERSION );
		wp_enqueue_script( 'wpcf7telegram-admin', self::pluginUrl() . '/assets/js/index.js', null, WPCF7TG_VERSION );
		wp_localize_script( 'wpcf7telegram-admin', 'wpData', array(
			'rest_client_url'   => get_rest_url( null, 'wp-connections/v1' . '/client/' . Client::WPCONNECTIONS_CLIENT ),
			// Put this nonce to X-WP-Nonce header request.
			'nonce'		        => wp_create_nonce( 'wpcf7_telegram_nonce' ),
			'l10n'		        => [
				'channel' => [
					'new_channel_name'  	    => __( 'New Channel Name', WPCF7TG_DOMAIN ),
					'create_new_channel'	    => __( 'Create new channel', WPCF7TG_DOMAIN ),
					'rename_channel'		    => __( 'Rename channel', WPCF7TG_DOMAIN ),
					'connect_form'              => __( 'Connect form', WPCF7TG_DOMAIN ),
					'connect_bot'               => __( 'Connect bot', WPCF7TG_DOMAIN ),
					'confirm_disconnect_bot'    => __( 'Disconnect this bot from %s channel?', WPCF7TG_DOMAIN ),
					'confirm_remove_channel'    => __( 'Do you really want to remove %s channel?', WPCF7TG_DOMAIN ),
					'confirm_disconnect_form'   => __( 'Do you really want to disconnect %s form from %s channel?', WPCF7TG_DOMAIN ),
					'pause_chat'                => __( 'Pause', WPCF7TG_DOMAIN ),
					'resume_chat'               => __( 'Resume', WPCF7TG_DOMAIN ),
				],
				'bot'   => [
					'bot'       =>  __( 'Bot', WPCF7TG_DOMAIN ),
					'api_key'   =>  __( 'API Key', WPCF7TG_DOMAIN ),
				],
				'chat'  => [
					'confirm_approve'	=> __( 'Do you really want to approve?', WPCF7TG_DOMAIN ),
					'confirm_refuse'	=> __( 'Do you really want to refuse?', WPCF7TG_DOMAIN ),
					'confirm_pause'     => __( 'Do you really want to pause?', WPCF7TG_DOMAIN ),
					'approved'          => __( 'Successfully approved', WPCF7TG_DOMAIN ),
					'refused'           => __( 'Request refused', WPCF7TG_DOMAIN ),
					'chat_is_muted'     => __( 'Muted', WPCF7TG_DOMAIN ),
					'mute_chat'         => __( 'Mute', WPCF7TG_DOMAIN ),
					'remove_chat'       => __( 'Remove', WPCF7TG_DOMAIN ),
					'activate_chat'     => __( 'Activate', WPCF7TG_DOMAIN ),
				],
			],
		) );
	}

	public static function pluginUrl() {
		return untrailingslashit( plugins_url( '/', WPCF7TG_FILE ) );
	}
}
