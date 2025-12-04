<?php
/*
* Plugin Name: Contact Form 7 + Telegram
* Description: Sends messages to Telegram-chat
* Author: Hokku
* Version: 0.10.2
* License: GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: cf7-telegram
* Domain Path: /languages
*/

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

define( 'WPCF7TG_PLUGIN_NAME', plugin_basename( __FILE__ ) );

define( 'WPCF7TG_VERSION', '0.10.2' );
define( 'WPCF7TG_FILE', __FILE__ );

const WPCF7TG_MIGRATION_HOOK = 'cf7tg_migrations';
require __DIR__ . '/vendor/autoload.php';

require ( __DIR__ . '/classes/wpcf7telegram.php' );
wpcf7_Telegram::get_instance();

add_action( 'in_plugin_update_message-' . WPCF7TG_PLUGIN_NAME, 'wpcf7tg_plugin_update_message', 10, 2 );

function wpcf7tg_plugin_update_message( $data, $response ) {
	if (
		version_compare( WPCF7TG_VERSION, '0.9', '>=' ) &&
		version_compare( $response->new_version, '1.0', '<' )
	) {
		// Temporary hide the message for users who have already updated to 0.9,
		// but still there's no v1.0 version available.
		return;
	}

	if(
		isset( $data['upgrade_notice'] )
	) :
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
				'preVersion' => WPCF7TG_VERSION,
			]
		);
	},
	10, 2
);

if ( wpcf7_Telegram::get_instance()->pre_releases ) {
	$updateChecker = PucFactory::buildUpdateChecker(
		'https://github.com/hokoo/cf7-telegram',
		WPCF7TG_FILE,
		'cf7-telegram'
	);

	if ( defined( 'WPCF7TG_GITHUB_TOKEN' ) ) {
		$updateChecker->setAuthentication( WPCF7TG_GITHUB_TOKEN );
	}

	$updateChecker->setBranch( 'plugin-dist' );
}

register_activation_hook( WPCF7TG_FILE, function () {
	update_option( 'cf7tg_version', WPCF7TG_VERSION );
} );

register_deactivation_hook( WPCF7TG_FILE, function () {
	delete_option( 'cf7tg_version' );
} );
