<?php
/**
 * docker compose -p cf7t exec -T php wp --path=/srv/web/wordpress \
 *   --require=/srv/web/tests/stability/mediafiles-wp-prelude.php \
 *   eval-file /srv/web/tests/stability/mediafiles-wp-smoke.php
 *
 * Runs real current Main's form-4 submit path with CF7 validation and mail.
 * CLI cannot call move_uploaded_file, so an early CF7 hook injects the
 * ephemeral upload into the real submission object before Main's hook.
 * The prelude blocks all HTTP/email before bootstrap; this file restores
 * singleton, globals, and media transient options in finally.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'CF7TG_MEDIA_PRELUDE' ) ) {
	throw new RuntimeException( 'Run with mediafiles-wp-prelude.php via WP-CLI --require.' );
}
function mf_smoke_check( $condition, $description ) {
	if ( ! $condition ) throw new RuntimeException( $description );
}
function mf_smoke_transients() {
	global $wpdb;
	$like = $wpdb->esc_like( '_transient_wpcf7tgmedia_' ) . '%';
	$timeout_like = $wpdb->esc_like( '_transient_timeout_wpcf7tgmedia_' ) . '%';
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name", $like, $timeout_like ),
		ARRAY_A
	);
	$result = array();
	foreach ( $rows as $row ) $result[ $row['option_name'] ] = $row;
	return $result;
}
function mf_smoke_restore_transients( $before ) {
	global $wpdb;
	$after = mf_smoke_transients();
	foreach ( $after as $name => $row ) {
		if ( ! isset( $before[ $name ] ) ) $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}
	foreach ( $before as $name => $row ) {
		if ( ! isset( $after[ $name ] ) ) {
			$wpdb->insert( $wpdb->options, $row );
		} elseif ( $row !== $after[ $name ] ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ), array( 'option_name' => $name ) );
		}
		wp_cache_delete( $name, 'options' );
	}
}

mf_smoke_check( defined( 'WPCF7TG_VERSION' ) && version_compare( WPCF7TG_VERSION, '1.2.1', '>=' ), 'current Main must be active' );
mf_smoke_check( class_exists( 'wpcf7tg_mediafiles' ) && defined( 'WPCF7TG_MF_ALLOWED' ) && WPCF7TG_MF_ALLOWED, 'media addon must be active' );
$form = WPCF7_ContactForm::get_instance( 4 );
mf_smoke_check( $form instanceof WPCF7_ContactForm, 'form 4 is absent' );
mf_smoke_check( false !== strpos( $form->prop( 'form' ), 'file-836' ), 'form 4 file-836 is absent' );
$singleton = new ReflectionProperty( 'WPCF7_Submission', 'instance' );
$singleton->setAccessible( true );
$uploads = new ReflectionProperty( 'WPCF7_Submission', 'uploaded_files' );
$uploads->setAccessible( true );
$prior_singleton = $singleton->getValue();
$prior_post = $_POST;
$prior_files = $_FILES;
$prior_server = $_SERVER;
$before_transients = mf_smoke_transients();
$png = null;
try {
$png = tempnam( sys_get_temp_dir(), 'cf7tg-media-smoke-' );
rename( $png, $png . '.png' );
$png .= '.png';
$base_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXioAAAAASUVORK5CYII=' );
$metadata = 'fixture' . "\0" . bin2hex( random_bytes( 8 ) );
$chunk = 'tEXt' . $metadata;
$bytes = substr( $base_png, 0, -12 ) . pack( 'N', strlen( $metadata ) ) . $chunk . pack( 'N', crc32( $chunk ) ) . substr( $base_png, -12 );
file_put_contents( $png, $bytes );
mf_smoke_check( 'image/png' === ( getimagesize( $png )['mime'] ?? null ), 'fixture is not a valid PNG' );
$GLOBALS['cf7tg_media_calls'] = array();
$GLOBALS['cf7tg_media_mail_calls'] = 0;
$GLOBALS['cf7tg_media_smoke_enabled'] = true;
$inject = static function( $cf, &$abort, $submission ) use ( $uploads, $png ) {
	if ( ! empty( $GLOBALS['cf7tg_media_inject_png'] ) ) $uploads->setValue( $submission, array( 'file-836' => $png ) );
};
add_action( 'wpcf7_before_send_mail', $inject, 1, 3 );
	foreach ( array( array( 'file-836' => $png ), array() ) as $index => $files ) {
		$singleton->setValue( null );
		$GLOBALS['cf7tg_media_inject_png'] = ! empty( $files );
		$_POST = array( '_wpcf7' => 4 );
		$_FILES = array();
		$_SERVER['HTTP_USER_AGENT'] = 'CF7 MediaFiles local smoke';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['REQUEST_URI'] = '/?mediafiles-smoke=1';
		if ( $form->nonce_is_active() && is_user_logged_in() ) $_POST['_wpnonce'] = wpcf7_create_nonce();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( ! $tag->name || 'file' === $tag->basetype ) continue;
			$type = $tag->basetype;
			if ( 'email' === $type ) $value = 'smoke@example.test';
			elseif ( 'url' === $type ) $value = 'https://example.test';
			elseif ( 'tel' === $type ) $value = '+15555550123';
			elseif ( 'number' === $type ) $value = '1';
			elseif ( 'date' === $type ) $value = '2026-10-30';
			elseif ( in_array( $type, array( 'checkbox', 'radio', 'select' ), true ) ) $value = array( $tag->values[0] ?? 'smoke' );
			elseif ( 'acceptance' === $type ) $value = '1';
			else $value = 'Media smoke';
			$_POST[ $tag->name ] = $value;
		}
		$result = $form->submit();
		mf_smoke_check( 'mail_sent' === ( $result['status'] ?? null ), 'CF7 submission was not mail_sent' );
		if ( 0 === $index ) $first_calls = $GLOBALS['cf7tg_media_calls'];
	}
	mf_smoke_check( $GLOBALS['cf7tg_media_mail_calls'] >= 2, 'CF7 mail was not intercepted for both submissions' );
	$calls = $GLOBALS['cf7tg_media_calls'];
	$text = array_values( array_filter( $first_calls, static function( $call ) { return 'sendMessage' === $call['method']; } ) );
	$photos = array_values( array_filter( $first_calls, static function( $call ) { return 'sendPhoto' === $call['method']; } ) );
	mf_smoke_check( count( $text ) >= 1, 'form 4 did not deliver first text' );
	mf_smoke_check( count( $calls ) > count( $first_calls ), 'no-files submission did not deliver text' );
	mf_smoke_check( count( $photos ) >= 1, 'form 4 PNG did not sendPhoto' );
	mf_smoke_check( count( array_filter( array_slice( $calls, count( $first_calls ) ), static function( $call ) { return 'sendMessage' !== $call['method']; } ) ) === 0, 'no-files submission sent media' );
	$destinations = array();
	foreach ( $text as $message ) {
		$body = is_string( $message['body'] ) ? json_decode( $message['body'], true ) : $message['body'];
		if ( is_array( $body ) && isset( $body['chat_id'] ) ) {
			$destinations[ $message['token_hash'] . ':' . $body['chat_id'] . ':' . ( $body['message_thread_id'] ?? '' ) ] = true;
		}
	}
	mf_smoke_check( count( $photos ) === count( $destinations ), 'text chunks or callbacks duplicated media' );
	foreach ( $photos as $photo ) {
		mf_smoke_check( is_string( $photo['body'] ) && false !== strpos( $photo['body'], $bytes ), 'PNG bytes differ' );
		mf_smoke_check( false !== strpos( $photo['body'], 'Content-Disposition: form-data; name="photo"; filename="' ), 'multipart file header differs' );
		$matched = false;
		foreach ( $text as $message ) {
			$body = is_string( $message['body'] ) ? json_decode( $message['body'], true ) : $message['body'];
			if ( ! is_array( $body ) || false === strpos( $photo['body'], 'name="chat_id"' . "\r\n\r\n" . $body['chat_id'] ) ) continue;
			if ( $photo['token_hash'] !== $message['token_hash'] ) continue;
			if ( isset( $body['message_thread_id'] ) && false === strpos( $photo['body'], 'name="message_thread_id"' . "\r\n\r\n" . $body['message_thread_id'] ) ) continue;
			$matched = true;
			break;
		}
		mf_smoke_check( $matched, 'photo bot/chat/topic differs from text delivery' );
	}
	echo 'mediafiles current WP form 4: pass (' . count( $text ) . ' first text chunks, ' . count( $photos ) . " photo calls)\n";
} finally {
	$GLOBALS['cf7tg_media_smoke_enabled'] = false;
	$GLOBALS['cf7tg_media_inject_png'] = false;
	if ( isset( $inject ) ) remove_action( 'wpcf7_before_send_mail', $inject, 1 );
	$singleton->setValue( $prior_singleton );
	$_POST = $prior_post;
	$_FILES = $prior_files;
	$_SERVER = $prior_server;
	mf_smoke_restore_transients( $before_transients );
	if ( $png && is_file( $png ) ) unlink( $png );
	mf_smoke_check( mf_smoke_transients() === $before_transients, 'media transient options were not restored' );
}
