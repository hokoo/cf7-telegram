<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Token source rules shared by delivery and read-only configuration. */
class BotToken {
	public static function constantName( int $botID, $source ): string {
		return Bot::TOKEN_SOURCE_LEGACY_CONST === $source
			? Bot::LEGACY_TOKEN_CONST
			: sprintf( Bot::TOKEN_CONST_MASK, $botID );
	}

	public static function value( int $botID, $source, $saved ) {
		$name = self::constantName( $botID, $source );
		if ( defined( $name ) ) {
			return constant( $name );
		}
		if ( Bot::TOKEN_SOURCE_LEGACY_CONST === $source && defined( Bot::LEGACY_TOKEN_CONST ) ) {
			return constant( Bot::LEGACY_TOKEN_CONST );
		}
		return $saved;
	}

	public static function isEmpty( $token ): bool {
		return ! is_string( $token ) || empty( $token ) || $token !== rtrim( ltrim( $token, '[' ), ']' );
	}
}
