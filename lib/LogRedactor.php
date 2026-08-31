<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class LogRedactor {
	public const REDACTION_MARKER = '[redacted]';

	private const DEFAULT_SENSITIVE_KEYS = [
		'token',
		'authorization',
		'password',
		'secret',
		'chatid',
		'email',
		'phone',
	];

	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			$redacted = [];

			foreach ( $value as $key => $item ) {
				$redacted[ $key ] = self::isSensitiveKey( $key )
					? self::REDACTION_MARKER
					: self::redact( $item );
			}

			return $redacted;
		}

		if ( is_object( $value ) ) {
			$redacted = clone $value;

			foreach ( get_object_vars( $value ) as $key => $item ) {
				$redacted->$key = self::isSensitiveKey( $key )
					? self::REDACTION_MARKER
					: self::redact( $item );
			}

			return $redacted;
		}

		if ( is_string( $value ) ) {
			return self::redactString( $value );
		}

		return $value;
	}

	public static function redactString( string $value ): string {
		$value = preg_replace(
			'/(?<!\d)\d{5,20}:[A-Za-z0-9_-]{30,80}(?![A-Za-z0-9_-])/',
			self::REDACTION_MARKER,
			$value
		) ?? $value;
		$value = preg_replace(
			'/(?<!\d)\d{5,20}%3A[A-Za-z0-9_-]{30,80}(?![A-Za-z0-9_-])/i',
			self::REDACTION_MARKER,
			$value
		) ?? $value;
		$value = self::redactBase64EncodedTelegramTokens( $value );
		$value = self::redactKeyValueSecrets( $value );
		$value = preg_replace(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			self::REDACTION_MARKER,
			$value
		) ?? $value;
		$value = preg_replace(
			'/(?<![\w])\+\d[\d\s().-]{6,}\d(?![\w])/',
			self::REDACTION_MARKER,
			$value
		) ?? $value;

		$extraPatterns = apply_filters( 'cf7tg/logRedactionPatterns', [] );
		if ( is_array( $extraPatterns ) ) {
			foreach ( $extraPatterns as $pattern ) {
				if ( is_string( $pattern ) && '' !== $pattern ) {
					$value = preg_replace( $pattern, self::REDACTION_MARKER, $value ) ?? $value;
				}
			}
		}

		return $value;
	}

	private static function redactBase64EncodedTelegramTokens( string $value ): string {
		return preg_replace_callback(
			'/(?<![A-Za-z0-9+\/_-])[A-Za-z0-9+\/_-]{40,}={0,2}(?![A-Za-z0-9+\/_-])/',
			static function ( array $matches ): string {
				$encoded = strtr( $matches[0], '-_', '+/' );
				$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
				$decoded = base64_decode( $encoded, true );

				if (
					is_string( $decoded ) &&
					preg_match( '/(?<!\d)\d{5,20}:[A-Za-z0-9_-]{30,80}(?![A-Za-z0-9_-])/', rawurldecode( $decoded ) )
				) {
					return self::REDACTION_MARKER;
				}

				return $matches[0];
			},
			$value
		) ?? $value;
	}

	private static function redactKeyValueSecrets( string $value ): string {
		$value = preg_replace(
			'/(\bauthorization\b["\']?\s*[:=]\s*["\']?)(?:Bearer\s+)?[^"\'\r\n,;&}]+/i',
			'$1' . self::REDACTION_MARKER,
			$value
		) ?? $value;

		return preg_replace(
			'/((?:["\']?\b(?:password|secret|token|chat[\s_-]*id|chatid|email|phone)\b["\']?)\s*[:=]\s*["\']?)[^"\'\s,;&}]+/i',
			'$1' . self::REDACTION_MARKER,
			$value
		) ?? $value;
	}

	private static function isSensitiveKey( $key ): bool {
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			return false;
		}

		$normalizedKey = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $key ) ?? '' );
		if ( '' === $normalizedKey ) {
			return false;
		}

		$sensitiveKeys = apply_filters( 'cf7tg/logSensitiveKeys', [] );
		if ( ! is_array( $sensitiveKeys ) ) {
			$sensitiveKeys = [];
		}
		$sensitiveKeys = array_merge( self::DEFAULT_SENSITIVE_KEYS, $sensitiveKeys );

		foreach ( $sensitiveKeys as $sensitiveKey ) {
			$needle = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $sensitiveKey ) ?? '' );
			if ( '' !== $needle && str_contains( $normalizedKey, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
