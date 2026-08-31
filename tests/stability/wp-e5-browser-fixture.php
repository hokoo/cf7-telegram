<?php
/**
 * Seeds the E5 browser smoke fixture in an isolated WordPress install.
 *
 * Intended to be executed with WP-CLI only:
 * wp eval-file /e5-tests/wp-e5-browser-fixture.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu_dir ) ) {
	wp_mkdir_p( $mu_dir );
}

$mu_plugin = <<<'PHP'
<?php
/**
 * E5 browser smoke controls. Created only inside the ephemeral Docker volume.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div id="cf7tg-e5-server-notice" class="notice notice-warning"><p>E5 browser smoke system notice should be hidden.</p></div>';
	}
);

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, 'https://api.telegram.org/' ) ) {
			return $preempt;
		}

		$method = basename( strtok( (string) $url, '?' ) );
		$result = [];
		if ( 'getMe' === $method ) {
			$result = [
				'id'         => 515151,
				'is_bot'     => true,
				'first_name' => 'E5 Browser',
				'username'   => 'E5BrowserSmokeBot',
			];
		} elseif ( 'getWebhookInfo' === $method ) {
			$result = [
				'url'                  => '',
				'pending_update_count' => 0,
			];
		} elseif ( 'getUpdates' === $method ) {
			$result = [];
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'ok'     => true,
					'result' => $result,
				]
			),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$route = untrailingslashit( $request->get_route() );
		if ( 'GET' !== $request->get_method() || '/wp/v2/cf7tg_channel' !== $route ) {
			return $result;
		}

		$remaining = (int) get_option( 'cf7tg_e5_browser_fail_channel_once_remaining', 0 );
		if ( $remaining < 1 ) {
			return $result;
		}

		update_option( 'cf7tg_e5_browser_fail_channel_once_remaining', $remaining - 1, false );
		return new WP_Error(
			'cf7tg_e5_browser_channel_once',
			'Synthetic channel failure for E5 browser retry smoke.',
			[ 'status' => 503 ]
		);
	},
	10,
	3
);

add_action(
	'wp_ajax_cf7tg_e5_browser_control',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$action = sanitize_key( wp_unslash( $_POST['e5_action'] ?? '' ) );
		if ( 'fail-channel-once' === $action ) {
			update_option( 'cf7tg_e5_browser_fail_channel_once_remaining', 1, false );
			wp_send_json_success( [ 'remaining' => 1 ] );
		}

		if ( 'reactivate-candidate' === $action ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			deactivate_plugins( 'cf7-telegram/cf7-telegram.php', true );
			$result = activate_plugin( 'cf7-telegram/cf7-telegram.php' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
			}

			wp_send_json_success( [ 'active' => is_plugin_active( 'cf7-telegram/cf7-telegram.php' ) ] );
		}

		wp_send_json_error( [ 'message' => 'unknown_action' ], 400 );
	}
);
PHP;

file_put_contents( $mu_dir . '/cf7tg-e5-browser-smoke.php', $mu_plugin );
update_option( 'cf7tg_e5_browser_fail_channel_once_remaining', 0, false );

$created = [
	'bots'     => [],
	'chats'    => [],
	'channels' => [],
	'forms'    => [],
];

for ( $i = 1; $i <= 12; $i++ ) {
	$bot = new Bot();
	$bot->setTitle( sprintf( 'E5 Browser Bot %02d', $i ) );
	$bot->setToken( sprintf( '100000%03d:E5_BROWSER_FAKE_TOKEN_%02d', $i, $i ) );
	$bot->savePost();
	wp_update_post(
		[
			'ID'          => $bot->getPost()->ID,
			'post_status' => 'publish',
		]
	);
	$created['bots'][] = $bot->getPost()->ID;

	$chat = new Chat();
	$chat->setTitle( sprintf( 'E5 Browser Chat %02d', $i ) );
	$chat->setChatID( (string) ( 880000 + $i ) );
	$chat->setChatType( 'private' );
	$chat->setUsername( sprintf( 'e5_browser_chat_%02d', $i ) );
	$chat->savePost();
	wp_update_post(
		[
			'ID'          => $chat->getPost()->ID,
			'post_status' => 'publish',
		]
	);
	$created['chats'][] = $chat->getPost()->ID;

	$channel = new Channel();
	$channel->setTitle( sprintf( 'E5 Browser Channel %02d', $i ) );
	$channel->savePost();
	wp_update_post(
		[
			'ID'          => $channel->getPost()->ID,
			'post_status' => 'publish',
		]
	);
	$created['channels'][] = $channel->getPost()->ID;

	$form_id = wp_insert_post(
		[
			'post_type'    => 'wpcf7_contact_form',
			'post_status'  => 'publish',
			'post_title'   => sprintf( 'E5 Browser Form %02d', $i ),
			'post_content' => "[text* your-name]\n[email* your-email]\n[telegram]\n[submit \"Send\"]",
		],
		true
	);

	if ( ! is_wp_error( $form_id ) ) {
		update_post_meta( $form_id, '_form', "[text* your-name]\n[email* your-email]\n[telegram]\n[submit \"Send\"]" );
		$created['forms'][] = (int) $form_id;
	}
}

echo wp_json_encode(
	[
		'schema'       => 1,
		'plugin_active' => is_plugin_active( 'cf7-telegram/cf7-telegram.php' ),
		'mu_plugin'    => $mu_dir . '/cf7tg-e5-browser-smoke.php',
		'created'      => [
			'bots'     => count( $created['bots'] ),
			'chats'    => count( $created['chats'] ),
			'channels' => count( $created['channels'] ),
			'forms'    => count( $created['forms'] ),
		],
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
