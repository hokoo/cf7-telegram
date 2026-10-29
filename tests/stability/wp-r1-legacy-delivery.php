<?php
/** Legacy WordPress: absence of Abilities API must preserve ordinary fake delivery. */
if ( ! defined( 'ABSPATH' ) ) exit( 1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Form;

$checks = [];
$record = static function ( string $id, bool $pass ) use ( &$checks ): void {
	$checks[] = [ 'id' => $id, 'status' => $pass ? 'pass' : 'fail' ];
};
$record( 'r1-legacy-no-api', ! function_exists( 'wp_register_ability' ) &&
	has_action( 'wpcf7_before_send_mail', [ CF7::class, 'handleSubscribe' ] ) !== false );
$requests = [];
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$requests ) {
	if ( ! str_starts_with( $url, 'https://api.telegram.org/' ) ) return $pre;
	$method = basename( strtok( $url, '?' ) );
	$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
	$requests[] = [ 'method' => $method, 'chat_id' => $body['chat_id'] ?? null, 'thread_id' => $body['message_thread_id'] ?? null ];
	$result = 'getMe' === $method ? [ 'id' => 123, 'is_bot' => true, 'first_name' => 'R1 Fake', 'username' => 'R1FakeBot' ] :
		( 'getWebhookInfo' === $method ? [ 'url' => '', 'pending_update_count' => 0 ] :
			[ 'message_id' => 123, 'chat' => [ 'id' => $body['chat_id'] ?? null ], 'message_thread_id' => $body['message_thread_id'] ?? null ] );
	return [ 'headers' => [], 'body' => wp_json_encode( [ 'ok' => true, 'result' => $result ] ),
		'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
}, 1, 3 );
$bot = new Bot();
$bot->setTitle( 'R1 Legacy fake bot' )->setToken( '123456789:R1_LEGACY_FAKE_TOKEN' )->savePost();
wp_update_post( [ 'ID' => $bot->getPost()->ID, 'post_status' => 'publish' ] );
$bridge = new Channel();
$bridge->setTitle( 'R1 Legacy bridge' )->savePost();
wp_update_post( [ 'ID' => $bridge->getPost()->ID, 'post_status' => 'publish' ] );
$bridge->connectBot( $bot );
foreach ( [ [ '700001', null ], [ '-100700001', 22 ] ] as [ $chat_id, $thread_id ] ) {
	$chat = new Chat();
	$chat->setTitle( 'R1 Legacy recipient' )->setChatID( $chat_id )->setChatType( null === $thread_id ? 'private' : 'supergroup' );
	if ( null !== $thread_id ) $chat->setMessageThreadID( $thread_id );
	$chat->savePost();
	wp_update_post( [ 'ID' => $chat->getPost()->ID, 'post_status' => 'publish' ] );
	$bot->connectChat( $chat );
	$chat->setActivated( $bot );
	$bridge->connectChat( $chat );
}
$cf = wpcf7_save_contact_form( [
	'id' => -1, 'title' => 'R1 Legacy CF7 form', 'locale' => 'en_US',
	'form' => '[text your-name][submit "Send"]',
	'mail' => [ 'subject' => 'R1 legacy', 'sender' => 'R1 <admin@example.test>', 'body' => '[your-name]',
		'recipient' => 'admin@example.test', 'additional_headers' => '', 'attachments' => '', 'use_html' => 0,
		'exclude_blank' => 0 ],
	'additional_settings' => "skip_mail: on\n",
], 'save' );
$form_id = $cf ? (int) $cf->id() : 0;
if ( $form_id > 0 ) ( new Form( $form_id ) )->connectChannel( $bridge );
$deliveries = [];
add_action( 'cf7tg_telegram_deliveries_completed', static function ( $list ) use ( &$deliveries ) { $deliveries = $list; }, 10, 1 );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [ '_wpcf7' => (string) $form_id, '_wpcf7_version' => WPCF7_VERSION,
	'_wpcf7_locale' => 'en_US', '_wpcf7_unit_tag' => 'wpcf7-f' . $form_id . '-p0-o1',
	'your-name' => 'R1 Legacy' ];
$submission = $form_id > 0 ? WPCF7_Submission::get_instance( $cf, [ 'skip_mail' => true ] ) : null;
$send_calls = array_values( array_filter( $requests, static fn( array $request ): bool => 'sendMessage' === $request['method'] ) );
$record( 'r1-legacy-fake-delivery', $form_id > 0 && $submission && 'mail_sent' === $submission->get_status() &&
	count( $deliveries ) === 2 && count( $send_calls ) === 2 &&
	count( array_filter( $deliveries, static function ( array $delivery ): bool {
		return ! empty( $delivery['results'] ) && ! array_filter( $delivery['results'], static fn( $result ): bool => ! $result->ok );
	} ) ) === 2 &&
	(string) $send_calls[0]['chat_id'] === '700001' && null === $send_calls[0]['thread_id'] &&
	(string) $send_calls[1]['chat_id'] === '-100700001' && 22 === (int) $send_calls[1]['thread_id'] );
$failed = count( array_filter( $checks, static fn( array $check ): bool => 'fail' === $check['status'] ) );
echo wp_json_encode( [ 'schema' => 1, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'contact_form_7' => WPCF7_VERSION, 'summary' => [ 'failed' => $failed ], 'checks' => $checks ] ) . "\n";
exit( $failed ? 1 : 0 );
