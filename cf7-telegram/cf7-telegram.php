<?php
/*
* Plugin Name: Contact Form 7 + Telegram
* Description: Sends messages to Telegram-chat
* Author: Hokku
* Version: 1.0-rc1
* License: GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: cf7-telegram
* Domain Path: /languages
*/

use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Settings;

define( 'WPCF7TG_PLUGIN_NAME', plugin_basename( __FILE__ ) );

define( 'WPCF7TG_VERSION', '1.0' );
define( 'WPCF7TG_FILE', __FILE__ );

require ( __DIR__ . '/inc/wp5_functions/wp5_functions.php' );
//require ( __DIR__ . '/classes/wpcf7telegram.php' );

require __DIR__ . '/vendor/autoload.php';

add_action( 'init', [ Client::getInstance(), 'init' ], 11 );
Settings::init();

add_action( 'in_plugin_update_message-' . WPCF7TG_PLUGIN_NAME, 'wpcf7tg_plugin_update_message', 10, 2 );

function wpcf7tg_plugin_update_message( $data, $response ) {
	if( isset( $data['upgrade_notice'] ) ) :
		printf(
			'<div class="update-message">%s</div>',
			wpautop( $data['upgrade_notice'] )
		);
	endif;
}
