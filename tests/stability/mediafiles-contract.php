<?php
/** Focused addon contract check: php tests/stability/mediafiles-contract.php modern|legacy */

$mode = $argv[1] ?? '';
if ( ! in_array( $mode, array( 'modern', 'legacy' ), true ) ) {
	fwrite( STDERR, "Choose modern or legacy.\n" );
	exit( 2 );
}
define( 'WPCF7TG_VERSION', 'legacy' === $mode ? '0.9.2' : '1.2.1' );
define( 'WPCF7TGMF_DOMAIN', 'cf7-telegram-mediafiles' );
define( 'WPCF7TGMF_SALT', 'test' );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
}
function __( $text ) { return $text; }
function home_url() { return 'https://example.test'; }
function get_option( $key ) { return $GLOBALS['options'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function wp_generate_password() { return 'testBoundary123'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['actions'][$name][] = $callback; }
function do_action( $name, ...$args ) {
	foreach ( $GLOBALS['actions'][$name] ?? array() as $callback ) $callback( ...$args );
}
function apply_filters( $name, $value ) {
	foreach ( $GLOBALS['actions'][$name] ?? array() as $callback ) $value = $callback( $value );
	return $value;
}
function check( $condition, $description ) {
	if ( ! $condition ) throw new RuntimeException( $description );
}
function wp_remote_post( $url, $args ) {
	$method = substr( $url, strrpos( $url, '/' ) + 1 );
	if ( ! preg_match( '~^https://api\.telegram\.org/bot([^/]+)/~', $url, $match ) ) throw new RuntimeException( 'unexpected HTTP' );
	$GLOBALS['calls'][] = array( 'token' => rawurldecode( $match[1] ), 'method' => $method, 'args' => $args );
	$failure = $GLOBALS['failure_next'] ?? null;
	if ( $failure ) {
		$GLOBALS['failure_next'] = null;
		if ( 'throw' === $failure ) throw new RuntimeException( 'simulated transport exception' );
		if ( 'transport' === $failure ) return new WP_Error( 'transport' );
		if ( 'malformed' === $failure ) return array( 'response' => array( 'code' => 200 ), 'body' => '{' );
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":false,"description":"failed"}' );
	}
	$field = strtolower( substr( $method, 4 ) );
	$result = 'sendPhoto' === $method
		? array( 'photo' => array( array( 'file_id' => 'id-' . rawurldecode( $match[1] ) ) ) )
		: array( $field => array( 'file_id' => 'id-' . rawurldecode( $match[1] ) . '-' . $field ) );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'ok' => true, 'result' => $result ) ) );
}

class MediaSubmission {
	private $files;
	public function __construct( $files ) { $this->files = $files; }
	public function uploaded_files() { return $this->files; }
}
class MediaBot {
	private $token;
	public function __construct( $token ) { $this->token = $token; }
	public function getToken() { return $this->token; }
}
class MediaChat {
	private $chat;
	private $thread;
	public function __construct( $chat, $thread = null ) { $this->chat = $chat; $this->thread = $thread; }
	public function getChatID() { return $this->chat; }
	public function getMessageThreadID() { return $this->thread; }
}

$GLOBALS['options'] = array( 'wpcf7_telegram_tkn' => 'legacy-test-token', 'wpcf7_telegram_chats' => array() );
$GLOBALS['actions'] = $GLOBALS['calls'] = $GLOBALS['transients'] = array();
if ( 'legacy' === $mode ) require dirname( __DIR__, 2 ) . '/wordpress/wp-content/plugins/cf7-telegram/classes/wpcf7telegram.php';
require dirname( __DIR__, 3 ) . '/cf7-telegram-mediafiles/classes/mediafiles.php';
wpcf7tg_mediafiles::get_instance();
do_action( 'plugins_loaded' );

$png = tempnam( sys_get_temp_dir(), 'mf-png-' );
$video = tempnam( sys_get_temp_dir(), 'mf-video-' );
$audio = tempnam( sys_get_temp_dir(), 'mf-audio-' );
$document = tempnam( sys_get_temp_dir(), 'mf-doc-' );
rename( $png, $png . '.png' ); $png .= '.png';
rename( $video, $video . '.mp4' ); $video .= '.mp4';
rename( $audio, $audio . '.mp3' ); $audio .= '.mp3';
rename( $document, $document . '.txt' ); $document .= '.txt';
$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXioAAAAASUVORK5CYII=' );
file_put_contents( $png, $bytes );
file_put_contents( $video, $bytes );
file_put_contents( $audio, $bytes );
file_put_contents( $document, $bytes );
try {
	$instance = new MediaSubmission( array( 'file-836' => $png ) );
	if ( 'modern' === $mode ) {
		$chat = new MediaChat( '-100123', 21 );
		$a = new MediaBot( 'bot-a' );
		$b = new MediaBot( 'bot-b' );
		$send = function( $submission, $bot ) use ( $chat ) {
			do_action( 'cf7tg_telegram_delivery_result', null, $chat, $bot, array( 'instance' => $submission ) );
		};
		$send( $instance, $a );
		$send( $instance, $a ); // second text chunk or repeated callback
		do_action( 'wpcf7tg_messages_sent', array( '-100123' => array( 'id' => '-100123' ) ), '', '', $instance );
		$send( $instance, $b );
		check( 2 === count( $GLOBALS['calls'] ), 'modern destinations send exactly once each' );
		foreach ( $GLOBALS['calls'] as $index => $call ) {
			check( 'sendPhoto' === $call['method'], 'PNG routes to sendPhoto' );
			check( $index ? 'bot-b' === $call['token'] : 'bot-a' === $call['token'], 'bot identity kept' );
			check( false !== strpos( $call['args']['body'], $bytes ), 'multipart has exact PNG bytes' );
			check( false !== strpos( $call['args']['body'], 'Content-Disposition: form-data; name="photo"; filename="' ), 'multipart file field header is exact' );
			check( false !== strpos( $call['args']['body'], 'name="chat_id"' . "\r\n\r\n-100123" ), 'multipart chat kept' );
			check( false !== strpos( $call['args']['body'], 'name="message_thread_id"' . "\r\n\r\n21" ), 'multipart topic kept' );
		}
		$send( new MediaSubmission( array( 'file-836' => $png ) ), $a );
		check( 3 === count( $GLOBALS['calls'] ) && is_array( $GLOBALS['calls'][2]['args']['body'] ), 'same bot reuses file ID' );
		check( 'id-bot-a' === $GLOBALS['calls'][2]['args']['body']['photo'], 'cached ID belongs to bot A' );
		check( 21 === $GLOBALS['calls'][2]['args']['body']['message_thread_id'], 'reused ID keeps topic' );
		$send( new MediaSubmission( array( 'file-836' => $video ) ), $a );
		check( 'sendVideo' === $GLOBALS['calls'][3]['method'] && is_string( $GLOBALS['calls'][3]['args']['body'] ), 'same bytes under a different method upload afresh' );
		$send( new MediaSubmission( array( 'file-836' => $audio ) ), $a );
		check( 'sendAudio' === $GLOBALS['calls'][4]['method'], 'audio route preserved' );
		$send( new MediaSubmission( array( 'file-836' => $document ) ), $a );
		check( 'sendDocument' === $GLOBALS['calls'][5]['method'], 'document route preserved' );
		$send( new MediaSubmission( array() ), $a );
		check( 6 === count( $GLOBALS['calls'] ), 'no files cause no media call' );
		foreach ( array( 'malformed', 'transport', 'api', 'throw' ) as $failure ) {
			$failed = new MediaBot( 'failed-' . $failure );
			$success = new MediaBot( 'next-' . $failure );
			$shared = new MediaSubmission( array( 'file-836' => $video ) );
			$GLOBALS['failure_next'] = $failure;
			$send( $shared, $failed );
			$send( $shared, $success );
			$failed_call = $GLOBALS['calls'][count( $GLOBALS['calls'] ) - 2];
			$success_call = $GLOBALS['calls'][count( $GLOBALS['calls'] ) - 1];
			check( 'next-' . $failure === $success_call['token'] && is_string( $success_call['args']['body'] ), $failure . ' failure did not isolate same-submission recipient' );
			$send( new MediaSubmission( array( 'file-836' => $video ) ), $failed );
			$retry_call = $GLOBALS['calls'][count( $GLOBALS['calls'] ) - 1];
			check( is_string( $retry_call['args']['body'] ), $failure . ' failure cached an invalid file ID' );
		}
		$before_missing = count( $GLOBALS['calls'] );
		$send( new MediaSubmission( array( 'missing' => '/missing.png', 'good' => $png ) ), new MediaBot( 'bot-e' ) );
		check( $before_missing + 1 === count( $GLOBALS['calls'] ), 'missing file does not block next file' );
	} else {
		check( ! class_exists( 'iTRON\\cf7Telegram\\Bot' ), 'legacy process has no modern Bot' );
		$list = array( 123 => array( 'id' => 123 ), 0 => array( 'id' => 456 ) );
		do_action( 'wpcf7tg_messages_sent', $list, '', '', $instance );
		check( 2 === count( $GLOBALS['calls'] ), 'real 0.9.2 hook sends both keyed and indexed chats' );
		check( false !== strpos( $GLOBALS['calls'][0]['args']['body'], $bytes ), 'legacy api_request uploads PNG bytes' );
		check( false !== strpos( $GLOBALS['calls'][0]['args']['body'], 'name="chat_id"' . "\r\n\r\n123" ), 'legacy keyed chat kept' );
		check( is_array( $GLOBALS['calls'][1]['args']['body'] ) && 456 == $GLOBALS['calls'][1]['args']['body']['chat_id'], 'legacy second chat reuses ID' );
		do_action( 'wpcf7tg_messages_sent', array( 123 => array( 'id' => 123 ) ), '', '', new MediaSubmission( array( 'file-836' => $png ) ) );
		check( 3 === count( $GLOBALS['calls'] ) && 'id-legacy-test-token' === $GLOBALS['calls'][2]['args']['body']['photo'], 'legacy repeat reuses file ID' );
		$legacy_token = new ReflectionProperty( 'wpcf7_Telegram', 'bot_token' );
		$legacy_token->setAccessible( true );
		$legacy_token->setValue( wpcf7_Telegram::get_instance(), 'legacy-second-token' );
		$GLOBALS['options']['wpcf7_telegram_tkn'] = 'legacy-second-token';
		do_action( 'wpcf7tg_messages_sent', array( 123 => array( 'id' => 123 ) ), '', '', new MediaSubmission( array( 'file-836' => $png ) ) );
		check( 4 === count( $GLOBALS['calls'] ) && 'legacy-second-token' === $GLOBALS['calls'][3]['token'] && is_string( $GLOBALS['calls'][3]['args']['body'] ), 'legacy token switch uploads rather than sharing another bot ID' );
	}
	fwrite( STDOUT, "mediafiles {$mode}: pass\n" );
} finally {
	unlink( $png );
	unlink( $video );
	unlink( $audio );
	unlink( $document );
}
