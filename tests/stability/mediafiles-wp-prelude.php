<?php
/**
 * Load with WP-CLI --require before WordPress bootstraps.
 * Blocks every external request and email; fake Telegram starts only in the smoke.
 */
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'CF7TG_MEDIA_PRELUDE', true );
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail() {
		$GLOBALS['cf7tg_media_mail_calls'] = ( $GLOBALS['cf7tg_media_mail_calls'] ?? 0 ) + 1;
		return true;
	}
}
$http = function( $preempt, $args, $url ) {
		if ( empty( $GLOBALS['cf7tg_media_smoke_enabled'] ) ) return new WP_Error( 'media_smoke_http_blocked' );
		if ( ! preg_match( '~^https://api\.telegram\.org/bot([^/]+)/(sendMessage|sendPhoto|sendAudio|sendVideo|sendDocument)$~', $url, $matches ) ) {
			return new WP_Error( 'media_smoke_http_blocked' );
		}
		$method = $matches[2];
		$token_hash = hash( 'sha256', rawurldecode( $matches[1] ) );
		$GLOBALS['cf7tg_media_calls'][] = array(
			'token_hash' => $token_hash,
			'method' => $method,
			'body' => $args['body'] ?? null,
			'headers' => $args['headers'] ?? array(),
		);
		$field = strtolower( substr( $method, 4 ) );
		$result = 'sendPhoto' === $method
			? array( 'photo' => array( array( 'file_id' => 'smoke-' . $token_hash ) ) )
			: array( $field => array( 'file_id' => 'smoke-' . $token_hash . '-' . $field ) );
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers' => array(),
			'body' => wp_json_encode( array( 'ok' => true, 'result' => $result ) ),
			'cookies' => array(),
		);
	};
$mail = function( $preempt, $atts ) {
	$GLOBALS['cf7tg_media_mail_calls'] = ( $GLOBALS['cf7tg_media_mail_calls'] ?? 0 ) + 1;
	return true;
};
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'pre_http_request', $http, 0, 3 );
	add_filter( 'pre_wp_mail', $mail, 0, 2 );
} else {
	$GLOBALS['wp_filter']['pre_http_request'][0][] = array( 'function' => $http, 'accepted_args' => 3 );
	$GLOBALS['wp_filter']['pre_wp_mail'][0][] = array( 'function' => $mail, 'accepted_args' => 2 );
}
