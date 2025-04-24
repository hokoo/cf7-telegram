<?php
/*
* Plugin Name: Contact Form 7 + Telegram
* Description: Sends messages to Telegram-chat
* Author: Hokku
* Version: 0.9.2
* License: GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: cf7-telegram
* Domain Path: /languages
*/

define( 'WPCF7TG_PLUGIN_NAME', plugin_basename( __FILE__ ) );

define( 'WPCF7TG_VERSION', '0.9.2' );
define( 'WPCF7TG_FILE', __FILE__ );

const WPCF7TG_MIGRATION_HOOK = 'cf7tg_migrations';

require ( __DIR__ . '/classes/wpcf7telegram.php' );
wpcf7_Telegram::get_instance();

add_action( 'in_plugin_update_message-' . WPCF7TG_PLUGIN_NAME, 'wpcf7tg_plugin_update_message', 10, 2 );

function wpcf7tg_plugin_update_message( $data, $response ) {
	if( isset( $data['upgrade_notice'] ) ) :
		printf(
			'<div class="update-message">%s</div>',
			wpautop( $data['upgrade_notice'] )
		);
	endif;
}

add_action(
	'upgrader_process_complete',
	function ( $upgrader, array $hook_extra ) {

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
			WPCF7TG_MIGRATION_HOOK,
			[
				'upgrader' => $upgrader,
				'prev-version' => WPCF7TG_VERSION,
			]
		);
	},
	10, 2
);

