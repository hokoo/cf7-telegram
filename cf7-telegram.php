<?php
/*
* Plugin Name: Contact Form 7 + Telegram
* Description: Sends messages to Telegram-chat
* Author:      Hokku
* Version:     0.8
* License:     GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: cf7-telegram
* Domain Path: /languages
*/
define( 'WPCF7TG_PLUGIN_NAME', plugin_basename( __FILE__ ) );
define( 'WPCF7TG_DOMAIN', 'cf7-telegram' );
define( 'WPCF7TG_VERSION', '0.8' );
define( 'WPCF7TG_FILE', __FILE__ );


require ( __DIR__ . '/inc/wp5_functions/wp5_functions.php' );
require ( __DIR__ . '/classes/wpcf7telegram.php' );
$wpcf7tg = wpcf7_Telegram::get_instance();

load_plugin_textdomain( WPCF7TG_DOMAIN, FALSE,  dirname( WPCF7TG_PLUGIN_NAME ) . '/languages' );

add_action( 'in_plugin_update_message-' . WPCF7TG_PLUGIN_NAME, 'wpcf7tg_plugin_update_message', 10, 2 );

function wpcf7tg_plugin_update_message( $data, $response ) {
	if( isset( $data['upgrade_notice'] ) ) :
		printf(
			'<div class="update-message">%s</div>',
			wpautop( $data['upgrade_notice'] )
		);
	endif;
}
