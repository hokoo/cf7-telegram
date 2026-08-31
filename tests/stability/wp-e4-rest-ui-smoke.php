<?php
/**
 * Prints JSON evidence for the E4 REST/UI integration smoke.
 *
 * Intended to be executed with WP-CLI only:
 * wp eval-file /e4-tests/wp-e4-rest-ui-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Settings;

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

$checks            = [];
$telegram_requests = [];

$record = static function ( string $id, bool $passed, string $message, array $extra = [] ) use ( &$checks ): void {
	$checks[] = [
		'id'      => $id,
		'status'  => $passed ? 'pass' : 'fail',
		'message' => $message,
		'extra'   => $extra,
	];
};

$request_data = static function ( string $method, string $route, array $params = [] ): array {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );
	return [
		'status'  => $response->get_status(),
		'headers' => $response->get_headers(),
		'data'    => $response->get_data(),
	];
};

$get_header = static function ( array $headers, string $name ) {
	foreach ( $headers as $key => $value ) {
		if ( 0 === strcasecmp( (string) $key, $name ) ) {
			return $value;
		}
	}

	return null;
};

$data_array = static function ( $data ): array {
	if ( is_array( $data ) ) {
		return $data;
	}

	if ( is_object( $data ) ) {
		return get_object_vars( $data );
	}

	return [];
};

$route_has_method = static function ( array $route_entries, string $method ): bool {
	$method = strtoupper( $method );
	foreach ( $route_entries as $entry ) {
		$methods = $entry['methods'] ?? [];
		if ( is_array( $methods ) ) {
			$keys   = array_map( 'strtoupper', array_map( 'strval', array_keys( $methods ) ) );
			$values = array_map( 'strtoupper', array_map( 'strval', array_values( $methods ) ) );
			if ( in_array( $method, $keys, true ) || in_array( $method, $values, true ) ) {
				return true;
			}
			continue;
		}

		$bitmask = (int) $methods;
		if ( 'GET' === $method && ( $bitmask & WP_REST_Server::READABLE ) ) {
			return true;
		}
		if ( 'POST' === $method && ( $bitmask & WP_REST_Server::CREATABLE ) ) {
			return true;
		}
	}

	return false;
};

$admin_user = get_user_by( 'login', 'admin' );
if ( $admin_user ) {
	wp_set_current_user( (int) $admin_user->ID );
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$telegram_requests ) {
		if ( false === strpos( (string) $url, 'https://api.telegram.org/' ) ) {
			return $preempt;
		}

		$method = basename( strtok( (string) $url, '?' ) );
		$telegram_requests[] = [
			'method' => $method,
			'url'    => preg_replace( '#bot[^/]+/#', 'bot[redacted]/', (string) $url ),
		];

		$result = [];
		if ( 'getMe' === $method ) {
			$result = [
				'id'         => 424242,
				'is_bot'     => true,
				'first_name' => 'E4 Smoke',
				'username'   => 'E4SmokeBot',
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

$plugin_file = WP_PLUGIN_DIR . '/cf7-telegram/cf7-telegram.php';
$plugin_data = file_exists( $plugin_file ) ? get_plugin_data( $plugin_file, false, false ) : [];

$record(
	'plugin-active',
	is_plugin_active( 'cf7-telegram/cf7-telegram.php' ),
	'Candidate plugin is active in the real WordPress install.',
	[
		'version' => $plugin_data['Version'] ?? null,
		'file'    => $plugin_file,
	]
);
$record(
	'cf7-active',
	is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ),
	'Contact Form 7 is active in the smoke install.'
);

if ( ! $admin_user ) {
	$record( 'admin-user', false, 'Admin user was not available for REST/admin checks.' );
} else {
	$record( 'admin-user', true, 'Admin user is available for REST/admin checks.', [ 'id' => (int) $admin_user->ID ] );
}

// Ensure routes registered for this request context.
rest_get_server();
do_action( 'rest_api_init' );

$bots     = [];
$chats    = [];
$channels = [];

try {
	for ( $i = 1; $i <= 12; $i++ ) {
		$bot = new Bot();
		$bot->setTitle( sprintf( 'E4 Smoke Bot %02d', $i ) );
		$bot->setToken( sprintf( '123456789:E4_SMOKE_FAKE_TOKEN_%02d', $i ) );
		$bot->savePost();
		wp_update_post(
			[
				'ID'          => $bot->getPost()->ID,
				'post_status' => 'publish',
			]
		);
		$bots[] = $bot->getPost()->ID;

		$chat = new Chat();
		$chat->setTitle( sprintf( 'E4 Smoke Chat %02d', $i ) );
		$chat->setChatID( (string) ( 700000 + $i ) );
		$chat->setChatType( 'private' );
		$chat->savePost();
		wp_update_post(
			[
				'ID'          => $chat->getPost()->ID,
				'post_status' => 'publish',
			]
		);
		$chats[] = $chat->getPost()->ID;

		$channel = new Channel();
		$channel->setTitle( sprintf( 'E4 Smoke Channel %02d', $i ) );
		$channel->savePost();
		wp_update_post(
			[
				'ID'          => $channel->getPost()->ID,
				'post_status' => 'publish',
			]
		);
		$channels[] = $channel->getPost()->ID;
	}

	$record(
		'seed-pagination-fixture',
		12 === count( $bots ) && 12 === count( $chats ) && 12 === count( $channels ),
		'Seeded more than ten bots, chats, and channels through candidate runtime classes.',
		[
			'bots'     => count( $bots ),
			'chats'    => count( $chats ),
			'channels' => count( $channels ),
		]
	);
} catch ( Throwable $exception ) {
	$record(
		'seed-pagination-fixture',
		false,
		'Could not seed REST pagination fixture through candidate runtime classes.',
		[
			'exception' => get_class( $exception ),
			'message'   => $exception->getMessage(),
		]
	);
}

$routes = rest_get_server()->get_routes();
$required_routes = [
	'/wp/v2/cf7tg_bot',
	'/wp/v2/cf7tg_chat',
	'/wp/v2/cf7tg_channel',
	'/wp/v2/cf7tg_bot/(?P<id>[\\d]+)/ping',
	'/wp/v2/cf7tg_bot/(?P<id>[\\d]+)/fetch_updates',
];

foreach ( $required_routes as $route ) {
	$record(
		'route-' . trim( preg_replace( '/[^a-z0-9]+/i', '-', $route ), '-' ),
		isset( $routes[ $route ] ),
		'Expected REST route is registered.',
		[ 'route' => $route ]
	);
}

foreach ( [ 'ping', 'fetch_updates' ] as $action ) {
	$route = '/wp/v2/cf7tg_bot/(?P<id>[\\d]+)/' . $action;
	$record(
		'action-route-methods-' . $action,
		isset( $routes[ $route ] )
			&& $route_has_method( $routes[ $route ], 'POST' )
			&& $route_has_method( $routes[ $route ], 'GET' ),
		'Bot mutation route supports POST and deprecated GET.',
		[
			'route'      => $route,
			'has_post'   => isset( $routes[ $route ] ) ? $route_has_method( $routes[ $route ], 'POST' ) : false,
			'has_get'    => isset( $routes[ $route ] ) ? $route_has_method( $routes[ $route ], 'GET' ) : false,
			'post_route' => '/wp/v2/cf7tg_bot/{id}/' . $action,
		]
	);
}

$bot_schema = $request_data( 'OPTIONS', '/wp/v2/cf7tg_bot' );
$bot_props  = is_array( $bot_schema['data']['schema']['properties'] ?? null ) ? $bot_schema['data']['schema']['properties'] : [];
$record(
	'bot-schema-custom-fields',
	200 === $bot_schema['status']
		&& isset( $bot_props['token'], $bot_props['isTokenEmpty'], $bot_props['isTokenDefinedByConst'], $bot_props['phpConst'] ),
	'Bot schema exposes E4 custom fields.',
	[
		'status'     => $bot_schema['status'],
		'properties' => array_keys( $bot_props ),
	]
);

$ping_schema = $request_data( 'OPTIONS', '/wp/v2/cf7tg_bot/' . ( $bots[0] ?? 1 ) . '/ping' );
$ping_methods = [];
foreach ( (array) ( $ping_schema['data']['endpoints'] ?? [] ) as $endpoint ) {
	foreach ( (array) ( $endpoint['methods'] ?? [] ) as $method ) {
		$ping_methods[] = strtoupper( (string) $method );
	}
}
$record(
	'ping-schema-methods',
	200 === $ping_schema['status'] && in_array( 'POST', $ping_methods, true ) && in_array( 'GET', $ping_methods, true ),
	'Ping OPTIONS response exposes POST and deprecated GET methods.',
	[
		'status'  => $ping_schema['status'],
		'methods' => array_values( array_unique( $ping_methods ) ),
	]
);

foreach (
	[
		Client::CPT_BOT     => '/wp/v2/cf7tg_bot',
		Client::CPT_CHAT    => '/wp/v2/cf7tg_chat',
		Client::CPT_CHANNEL => '/wp/v2/cf7tg_channel',
	] as $post_type => $route
) {
	$page_one    = $request_data( 'GET', $route, [ 'per_page' => 10, 'page' => 1, 'orderby' => 'id', 'order' => 'asc' ] );
	$page_two    = $request_data( 'GET', $route, [ 'per_page' => 10, 'page' => 2, 'orderby' => 'id', 'order' => 'asc' ] );
	$total_pages = (int) $get_header( $page_one['headers'], 'X-WP-TotalPages' );
	$total_items = (int) $get_header( $page_one['headers'], 'X-WP-Total' );
	$record(
		'pagination-' . $post_type,
		200 === $page_one['status']
			&& 200 === $page_two['status']
			&& is_array( $page_one['data'] )
			&& is_array( $page_two['data'] )
			&& 10 === count( $page_one['data'] )
			&& count( $page_two['data'] ) >= 2
			&& $total_pages >= 2
			&& $total_items >= 12,
		'REST collection paginates beyond the default first ten items.',
		[
			'post_type'   => $post_type,
			'route'       => $route,
			'page_1'      => [
				'status' => $page_one['status'],
				'count'  => is_array( $page_one['data'] ) ? count( $page_one['data'] ) : null,
			],
			'page_2'      => [
				'status' => $page_two['status'],
				'count'  => is_array( $page_two['data'] ) ? count( $page_two['data'] ) : null,
			],
			'total'       => $total_items,
			'total_pages' => $total_pages,
		]
	);
}

$primary_bot_id = (int) ( $bots[0] ?? 0 );
if ( $primary_bot_id > 0 ) {
	$post_ping = $request_data( 'POST', '/wp/v2/cf7tg_bot/' . $primary_bot_id . '/ping' );
	$post_ping_data = $data_array( $post_ping['data'] );
	$record(
		'post-ping',
		200 === $post_ping['status']
			&& true === ( $post_ping_data['online'] ?? null )
			&& 'E4SmokeBot' === ( $post_ping_data['botName'] ?? null ),
		'POST /ping succeeds through fake Telegram transport.',
		[
			'status' => $post_ping['status'],
			'data'   => $post_ping_data,
		]
	);

	$post_updates = $request_data( 'POST', '/wp/v2/cf7tg_bot/' . $primary_bot_id . '/fetch_updates' );
	$post_updates_data = $data_array( $post_updates['data'] );
	$record(
		'post-fetch-updates',
		200 === $post_updates['status']
			&& false === ( $post_updates_data['hasWebhookConflict'] ?? null )
			&& false === ( $post_updates_data['hasNewChats'] ?? null ),
		'POST /fetch_updates succeeds through fake Telegram transport.',
		[
			'status' => $post_updates['status'],
			'data'   => $post_updates_data,
		]
	);

	foreach ( [ 'ping', 'fetch_updates' ] as $action ) {
		$get_response = $request_data( 'GET', '/wp/v2/cf7tg_bot/' . $primary_bot_id . '/' . $action );
		$record(
			'deprecated-get-' . $action,
			200 === $get_response['status']
				&& 'true' === (string) $get_header( $get_response['headers'], 'Deprecation' )
				&& 'Use POST for this mutating endpoint.' === (string) $get_header( $get_response['headers'], 'X-CF7TG-Deprecated-Route' ),
			'Deprecated GET mutation route preserves compatibility headers.',
			[
				'status'  => $get_response['status'],
				'headers' => [
					'Deprecation'                => $get_header( $get_response['headers'], 'Deprecation' ),
					'X-CF7TG-Deprecated-Route'   => $get_header( $get_response['headers'], 'X-CF7TG-Deprecated-Route' ),
				],
			]
		);
	}
} else {
	$record( 'post-ping', false, 'No seeded bot was available for POST /ping.' );
	$record( 'post-fetch-updates', false, 'No seeded bot was available for POST /fetch_updates.' );
}

$subscriber_id = wp_insert_user(
	[
		'user_login' => 'e4_subscriber',
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'e4-subscriber@example.test',
		'role'       => 'subscriber',
	]
);

if ( is_wp_error( $subscriber_id ) ) {
	$record(
		'capability-subscriber-created',
		false,
		'Could not create subscriber for capability rejection checks.',
		[ 'error' => $subscriber_id->get_error_message() ]
	);
} else {
	wp_set_current_user( (int) $subscriber_id );
	$collection_denied = $request_data( 'GET', '/wp/v2/cf7tg_bot', [ 'per_page' => 1 ] );
	$mutation_denied   = $primary_bot_id > 0
		? $request_data( 'POST', '/wp/v2/cf7tg_bot/' . $primary_bot_id . '/ping' )
		: [ 'status' => 0, 'data' => null, 'headers' => [] ];
	$record(
		'capability-rejection',
		in_array( $collection_denied['status'], [ 401, 403 ], true )
			&& in_array( $mutation_denied['status'], [ 401, 403 ], true ),
		'Subscriber cannot read protected collections or call mutating bot action.',
		[
			'collection_status' => $collection_denied['status'],
			'mutation_status'   => $mutation_denied['status'],
		]
	);
	if ( $admin_user ) {
		wp_set_current_user( (int) $admin_user->ID );
	}
}

set_current_screen( 'contact_page_wpcf7_tg' );
do_action( 'current_screen' );
do_action( 'admin_enqueue_scripts', 'contact_page_wpcf7_tg' );

ob_start();
Settings::plugin_menu_cbf();
$admin_html = ob_get_clean();

$script = wp_scripts()->registered['cf7-telegram-admin'] ?? null;
$style  = wp_styles()->registered['cf7-telegram-admin-styles'] ?? null;
$script_data = wp_scripts()->get_data( 'cf7-telegram-admin', 'data' );

$record(
	'admin-page-mount-root',
	false !== strpos( $admin_html, 'id="cf7-telegram-container"' ),
	'Admin settings page renders the React mount root from the candidate artifact.',
	[
		'html_bytes' => strlen( $admin_html ),
	]
);
$record(
	'admin-page-assets',
	$script
		&& $style
		&& false !== strpos( (string) $script->src, '/react/build/static/js/main.js' )
		&& false !== strpos( (string) $style->src, '/react/build/static/css/main.css' )
		&& is_string( $script_data )
		&& false !== strpos( $script_data, 'cf7TelegramData' ),
	'Admin settings page enqueues built JS/CSS assets and localized REST bootstrap data.',
	[
		'script_src'       => $script ? $script->src : null,
		'style_src'        => $style ? $style->src : null,
		'has_localization' => is_string( $script_data ) && false !== strpos( $script_data, 'cf7TelegramData' ),
	]
);

$candidate_css = WP_PLUGIN_DIR . '/cf7-telegram/react/build/static/css/main.css';
$css           = file_exists( $candidate_css ) ? file_get_contents( $candidate_css ) : '';
$record(
	'admin-page-notice-policy',
	is_string( $css )
		&& false !== strpos( $css, 'body[class*=page_wpcf7_tg]' )
		&& false !== strpos( $css, '#wpcontent' )
		&& false !== strpos( $css, '#wpbody-content>.notice:not(.cf7t-notice)' )
		&& false !== strpos( $css, '#cf7-telegram-container' )
		&& false !== strpos( $css, 'background-color:#0e1621' )
		&& false !== strpos( $css, 'display:none!important' ),
	'Candidate CSS fills the plugin page, hides system notices, and preserves plugin-owned notices.',
	[
		'css_file'   => $candidate_css,
		'css_exists' => file_exists( $candidate_css ),
		'css_bytes'  => is_string( $css ) ? strlen( $css ) : 0,
	]
);

$failed = 0;
foreach ( $checks as $check ) {
	if ( 'fail' === $check['status'] ) {
		$failed++;
	}
}

$result = [
	'schema'            => 1,
	'captured_at_gmt'   => gmdate( 'c' ),
	'wordpress'         => [
		'version' => get_bloginfo( 'version' ),
		'url'     => home_url(),
	],
	'php_version'       => PHP_VERSION,
	'plugin'            => [
		'version' => $plugin_data['Version'] ?? null,
		'active'  => is_plugin_active( 'cf7-telegram/cf7-telegram.php' ),
	],
	'seeded'            => [
		'bots'     => count( $bots ),
		'chats'    => count( $chats ),
		'channels' => count( $channels ),
	],
	'telegram_requests' => $telegram_requests,
	'summary'           => [
		'total'  => count( $checks ),
		'passed' => count( $checks ) - $failed,
		'failed' => $failed,
	],
	'checks'            => $checks,
];

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed > 0 ? 1 : 0 );
