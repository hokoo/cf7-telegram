<?php

namespace iTRON\cf7Telegram\Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class TelegramRedactor {
	public static function tokenLabel( ?string $token ): string {
		$token = trim( (string) $token );

		if ( '' === $token ) {
			return '[empty]';
		}

		return '[telegram-token]';
	}

	public static function text( string $text, ?string $token = null ): string {
		if ( null !== $token && '' !== $token ) {
			$text = str_replace( $token, self::tokenLabel( $token ), $text );
		}

		$text = preg_replace( '/bot\d{5,}:[A-Za-z0-9_-]{8,}/', 'bot[telegram-token]', $text ) ?? $text;
		return preg_replace( '/\d{5,}:[A-Za-z0-9_-]{8,}/', '[telegram-token]', $text ) ?? $text;
	}

	public static function data( $data, ?string $token = null ) {
		if ( is_string( $data ) ) {
			return self::text( $data, $token );
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$redacted = [];
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/token/i', $key ) ) {
				$redacted[ $key ] = self::tokenLabel( is_scalar( $value ) ? (string) $value : $token );
				continue;
			}

			$redacted[ $key ] = self::data( $value, $token );
		}

		return $redacted;
	}
}
