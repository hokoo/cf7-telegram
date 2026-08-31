<?php
/**
 * Seeds the E6 fake Telegram form-delivery fixture in an isolated WordPress install.
 *
 * Intended to be executed with WP-CLI only:
 * wp eval-file /e6-tests/wp-e6-form-delivery-fixture.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Form;

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$admin_user = get_user_by( 'login', 'admin' );
if ( $admin_user ) {
	wp_set_current_user( (int) $admin_user->ID );
}

$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu_dir ) ) {
	wp_mkdir_p( $mu_dir );
}

$mu_plugin = <<<'PHP'
<?php
/**
 * E6 fake Telegram transport and browser controls.
 * Created only inside the ephemeral Docker volume.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cf7tg_e6_empty_telegram_state(): array {
	return [
		'schema'   => 1,
		'active'   => true,
		'calls'    => [],
		'failures' => [],
		'updates'  => [],
	];
}

function cf7tg_e6_telegram_state(): array {
	$state = get_option( 'cf7tg_e6_fake_telegram_state', [] );
	return is_array( $state ) ? array_merge( cf7tg_e6_empty_telegram_state(), $state ) : cf7tg_e6_empty_telegram_state();
}

function cf7tg_e6_save_telegram_state( array $state ): void {
	update_option( 'cf7tg_e6_fake_telegram_state', array_merge( cf7tg_e6_empty_telegram_state(), $state ), false );
}

function cf7tg_e6_parse_telegram_params( array $args ): array {
	$body = $args['body'] ?? '';
	if ( is_string( $body ) && '' !== trim( $body ) ) {
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : [ '_malformed_body' => true ];
	}

	return [];
}

function cf7tg_e6_sanitize_params( array $params ): array {
	$sanitized = [];
	foreach ( $params as $key => $value ) {
		$key = (string) $key;
		if ( preg_match( '/token|secret|password|key/i', $key ) ) {
			$sanitized[ $key ] = '[redacted]';
			continue;
		}

		if ( is_scalar( $value ) || null === $value ) {
			$sanitized[ $key ] = $value;
			continue;
		}

		$sanitized[ $key ] = is_array( $value ) ? cf7tg_e6_sanitize_params( $value ) : '[object]';
	}

	return $sanitized;
}

function cf7tg_e6_response_summary( array $response, string $category ): array {
	$body = json_decode( (string) ( $response['body'] ?? '' ), true );
	$body = is_array( $body ) ? $body : [];

	return [
		'http_code'        => (int) ( $response['response']['code'] ?? 0 ),
		'ok'               => (bool) ( $body['ok'] ?? false ),
		'error_code'       => (int) ( $body['error_code'] ?? 0 ),
		'description'      => isset( $body['description'] ) ? (string) $body['description'] : '',
		'failure_category' => $category,
	];
}

function cf7tg_e6_bot_api_response( array $body, int $status = 200 ): array {
	return [
		'headers'  => [ 'content-type' => 'application/json; charset=UTF-8' ],
		'body'     => wp_json_encode( $body ),
		'response' => [
			'code'    => $status,
			'message' => $status >= 200 && $status < 300 ? 'OK' : 'Synthetic Telegram failure',
		],
		'cookies'  => [],
		'filename' => null,
	];
}

function cf7tg_e6_default_telegram_response( string $method, array $params, array $state ): array {
	if ( 'getMe' === $method ) {
		return cf7tg_e6_bot_api_response(
			[
				'ok'     => true,
				'result' => [
					'id'         => 660001,
					'is_bot'     => true,
					'first_name' => 'E6 Fake',
					'username'   => 'E6FakeTelegramBot',
				],
			]
		);
	}

	if ( 'getWebhookInfo' === $method ) {
		return cf7tg_e6_bot_api_response(
			[
				'ok'     => true,
				'result' => [
					'url'                  => '',
					'pending_update_count' => 0,
				],
			]
		);
	}

	if ( 'getUpdates' === $method ) {
		return cf7tg_e6_bot_api_response(
			[
				'ok'     => true,
				'result' => array_values( $state['updates'] ?? [] ),
			]
		);
	}

	if ( 'sendMessage' === $method ) {
		return cf7tg_e6_bot_api_response(
			[
				'ok'     => true,
				'result' => [
					'message_id' => 900000 + count( $state['calls'] ?? [] ) + 1,
					'chat'       => [
						'id'   => $params['chat_id'] ?? '',
						'type' => 'private',
					],
					'date'       => time(),
					'text'       => $params['text'] ?? '',
				],
			]
		);
	}

	return cf7tg_e6_bot_api_response(
		[
			'ok'          => false,
			'error_code'  => 404,
			'description' => 'Synthetic Telegram method is not implemented.',
		],
		404
	);
}

function cf7tg_e6_take_scripted_failure( string $method, array $params, array &$state ): ?array {
	$chat_id = isset( $params['chat_id'] ) ? (string) $params['chat_id'] : '';
	foreach ( [ $method . ':' . $chat_id, $method ] as $key ) {
		$count = (int) ( $state['failures'][ $key ] ?? 0 );
		if ( $count < 1 ) {
			continue;
		}

		$state['failures'][ $key ] = $count - 1;
		return cf7tg_e6_bot_api_response(
			[
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Synthetic Telegram failure for E6.',
			]
		);
	}

	return null;
}

function cf7tg_e6_record_telegram_call( string $method, string $token, array $params, array $response, string $category ): void {
	$state = cf7tg_e6_telegram_state();
	$state['calls'][] = [
		'index'       => count( $state['calls'] ) + 1,
		'method'      => $method,
		'token_hash'  => substr( hash( 'sha256', $token ), 0, 16 ),
		'params'      => cf7tg_e6_sanitize_params( $params ),
		'response'    => cf7tg_e6_response_summary( $response, $category ),
		'captured_at' => gmdate( 'c' ),
	];
	cf7tg_e6_save_telegram_state( $state );
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, 'https://api.telegram.org/' ) ) {
			return $preempt;
		}

		if ( ! preg_match( '#https://api\.telegram\.org/bot([^/]+)/([^/?]+)#', (string) $url, $matches ) ) {
			$response = cf7tg_e6_bot_api_response(
				[
					'ok'          => false,
					'error_code'  => 400,
					'description' => 'Malformed Telegram URL.',
				],
				400
			);
			cf7tg_e6_record_telegram_call( 'unknown', '', [], $response, 'malformed_url' );
			return $response;
		}

		$token  = rawurldecode( $matches[1] );
		$method = rawurldecode( $matches[2] );
		$params = cf7tg_e6_parse_telegram_params( is_array( $args ) ? $args : [] );
		$state  = cf7tg_e6_telegram_state();

		$response = cf7tg_e6_take_scripted_failure( $method, $params, $state );
		$category = $response ? 'scripted_failure' : 'success';
		if ( null === $response ) {
			$response = cf7tg_e6_default_telegram_response( $method, $params, $state );
			$body     = json_decode( (string) ( $response['body'] ?? '' ), true );
			if ( empty( $body['ok'] ) ) {
				$category = 'unsupported_method';
			}
		}

		cf7tg_e6_save_telegram_state( $state );
		cf7tg_e6_record_telegram_call( $method, $token, $params, $response, $category );
		return $response;
	},
	10,
	3
);

add_filter(
	'wpcf7_skip_mail',
	static function ( $skip_mail, $contact_form ) {
		$fixture = get_option( 'cf7tg_e6_fixture', [] );
		$form_id = is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0;
		if ( $form_id && $form_id === (int) ( $fixture['form_id'] ?? 0 ) ) {
			return true;
		}

		return $skip_mail;
	},
	10,
	2
);

function cf7tg_e6_control_response( array $data ): void {
	wp_send_json_success( $data );
}

function cf7tg_e6_fake_telegram_control(): void {
	$action = sanitize_key( wp_unslash( $_POST['e6_action'] ?? '' ) );
	if ( 'reset' === $action ) {
		$state = cf7tg_e6_empty_telegram_state();
		cf7tg_e6_save_telegram_state( $state );
		cf7tg_e6_control_response( [ 'active' => true, 'telegram' => $state, 'fixture' => get_option( 'cf7tg_e6_fixture', [] ) ] );
	}

	if ( 'script-failure' === $action ) {
		$method = sanitize_key( wp_unslash( $_POST['method'] ?? 'sendMessage' ) );
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) );
		$count = max( 1, (int) ( $_POST['count'] ?? 1 ) );
		$key = $chat_id ? $method . ':' . $chat_id : $method;
		$state = cf7tg_e6_telegram_state();
		$state['failures'][ $key ] = (int) ( $state['failures'][ $key ] ?? 0 ) + $count;
		cf7tg_e6_save_telegram_state( $state );
		cf7tg_e6_control_response( [ 'active' => true, 'telegram' => $state, 'fixture' => get_option( 'cf7tg_e6_fixture', [] ) ] );
	}

	if ( 'evidence' === $action ) {
		cf7tg_e6_control_response(
			[
				'active'   => true,
				'telegram' => cf7tg_e6_telegram_state(),
				'fixture'  => get_option( 'cf7tg_e6_fixture', [] ),
			]
		);
	}

	wp_send_json_error( [ 'message' => 'unknown_action' ], 400 );
}

add_action( 'wp_ajax_cf7tg_e6_fake_telegram_control', 'cf7tg_e6_fake_telegram_control' );
add_action( 'wp_ajax_nopriv_cf7tg_e6_fake_telegram_control', 'cf7tg_e6_fake_telegram_control' );
PHP;

file_put_contents( $mu_dir . '/cf7tg-e6-fake-telegram.php', $mu_plugin );
update_option( 'cf7tg_e6_fake_telegram_state', [ 'schema' => 1, 'active' => true, 'calls' => [], 'failures' => [], 'updates' => [] ], false );

if ( ! function_exists( 'wpcf7_save_contact_form' ) ) {
	echo wp_json_encode(
		[
			'schema' => 1,
			'error'  => 'Contact Form 7 API is not available.',
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 1 );
}

$form_template = <<<'FORM'
<label> Your name
    [text* your-name] </label>

<label> Your email
    [email* your-email] </label>

<label> Subject
    [text* your-subject] </label>

<label> E6 marker
    [text* e6-marker] </label>

<label> Message
    [textarea your-message] </label>

[submit "Send"]
FORM;

$mail_template = [
	'subject'            => 'CF7TG E6 [your-subject]',
	'sender'             => 'CF7TG E6 <admin@example.test>',
	'body'               => "E6 marker: [e6-marker]\nName: [your-name]\nEmail: [your-email]\nSubject: [your-subject]\nMessage: [your-message]",
	'recipient'          => 'admin@example.test',
	'additional_headers' => 'Reply-To: [your-email]',
	'attachments'        => '',
	'use_html'           => 0,
	'exclude_blank'      => 0,
];

$contact_form = wpcf7_save_contact_form(
	[
		'id'                  => -1,
		'title'               => 'CF7TG E6 Delivery Form',
		'locale'              => 'en_US',
		'form'                => $form_template,
		'mail'                => $mail_template,
		'additional_settings' => "skip_mail: on\n",
	],
	'save'
);

if ( ! $contact_form || ! method_exists( $contact_form, 'id' ) ) {
	echo wp_json_encode(
		[
			'schema' => 1,
			'error'  => 'Could not create Contact Form 7 form.',
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 1 );
}

$form_id = (int) $contact_form->id();
$page_id = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'CF7TG E6 Delivery Page',
		'post_content' => sprintf( '[contact-form-7 id="%d" title="CF7TG E6 Delivery Form"]', $form_id ),
	],
	true
);

if ( is_wp_error( $page_id ) || ! $page_id ) {
	echo wp_json_encode(
		[
			'schema' => 1,
			'error'  => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'Could not create public page.',
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 1 );
}

$bot_token = '660001:E6_FAKE_TOKEN_CANARY';
$bot       = new Bot();
$bot->setTitle( 'E6 Fake Telegram Bot' );
$bot->setToken( $bot_token );
$bot->savePost();
wp_update_post(
	[
		'ID'          => $bot->getPost()->ID,
		'post_status' => 'publish',
	]
);

$channel = new Channel();
$channel->setTitle( 'E6 Delivery Channel' );
$channel->savePost();
wp_update_post(
	[
		'ID'          => $channel->getPost()->ID,
		'post_status' => 'publish',
	]
);

$expected_chat_ids = [ '990001', '990002' ];
$chat_posts        = [];
foreach ( $expected_chat_ids as $index => $chat_id ) {
	$chat = new Chat();
	$chat->setTitle( sprintf( 'E6 Delivery Chat %d', $index + 1 ) );
	$chat->setChatID( $chat_id );
	$chat->setChatType( 'private' );
	$chat->setUsername( sprintf( 'e6_delivery_chat_%d', $index + 1 ) );
	$chat->savePost();
	wp_update_post(
		[
			'ID'          => $chat->getPost()->ID,
			'post_status' => 'publish',
		]
	);

	$bot->connectChat( $chat );
	$chat->setActivated( $bot );
	$channel->connectChat( $chat );
	$chat_posts[] = $chat->getPost()->ID;
}

$unrelated_chat = new Chat();
$unrelated_chat->setTitle( 'E6 Unrelated Chat' );
$unrelated_chat->setChatID( '990099' );
$unrelated_chat->setChatType( 'private' );
$unrelated_chat->setUsername( 'e6_unrelated_chat' );
$unrelated_chat->savePost();
wp_update_post(
	[
		'ID'          => $unrelated_chat->getPost()->ID,
		'post_status' => 'publish',
	]
);

$channel->connectBot( $bot );
( new Form( $form_id ) )->connectChannel( $channel );

$fixture = [
	'schema'             => 1,
	'form_id'            => $form_id,
	'page_id'            => (int) $page_id,
	'page_url'           => get_permalink( $page_id ),
	'bot_post_id'        => $bot->getPost()->ID,
	'channel_post_id'    => $channel->getPost()->ID,
	'chat_post_ids'      => $chat_posts,
	'expected_chat_ids'  => $expected_chat_ids,
	'unexpected_chat_id' => $unrelated_chat->getChatID(),
	'bot_token_hash'     => substr( hash( 'sha256', $bot_token ), 0, 16 ),
];

update_option( 'cf7tg_e6_fixture', $fixture, false );

echo wp_json_encode(
	[
		'schema'        => 1,
		'plugin_active' => is_plugin_active( 'cf7-telegram/cf7-telegram.php' ),
		'cf7_active'    => is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ),
		'mu_plugin'     => $mu_dir . '/cf7tg-e6-fake-telegram.php',
		'fixture'       => $fixture,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
