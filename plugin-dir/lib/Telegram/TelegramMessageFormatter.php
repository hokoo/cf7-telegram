<?php

namespace iTRON\cf7Telegram\Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class TelegramMessageFormatter {
	public const MAX_MESSAGE_LENGTH = 4096;

	public static function normalizeMode( string $mode ): string {
		$mode = trim( $mode );
		return in_array( $mode, [ 'HTML', 'Markdown', 'MarkdownV2' ], true ) ? $mode : '';
	}

	public static function chunks( string $message, int $limit = self::MAX_MESSAGE_LENGTH ): array {
		$limit = max( 1, $limit );
		$message = (string) apply_filters( 'cf7tg_telegram_message_before_chunk', $message, $limit );

		if ( '' === $message ) {
			return [ '' ];
		}

		$chunks = [];
		$length = self::length( $message );

		for ( $offset = 0; $offset < $length; $offset += $limit ) {
			$chunks[] = self::substring( $message, $offset, $limit );
		}

		return $chunks;
	}

	public static function plaintext( string $message, string $mode ): string {
		if ( 'HTML' === self::normalizeMode( $mode ) ) {
			return html_entity_decode( wp_strip_all_tags( $message, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$message = preg_replace( '/\\\\([_*`\[])/', '$1', $message ) ?? $message;
		$message = preg_replace( '/\s*```\s*/', ' ', $message ) ?? $message;
		$message = preg_replace( '/[*~`]+/', '', $message ) ?? $message;
		$message = preg_replace( '/(?<!\w)_{1,2}|_{1,2}(?!\w)/u', '', $message ) ?? $message;

		return trim( preg_replace( '/[ \t]+/', ' ', $message ) ?? $message );
	}

	private static function length( string $message ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $message, 'UTF-8' );
		}

		return count( self::characters( $message ) );
	}

	private static function substring( string $message, int $offset, int $limit ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, $offset, $limit, 'UTF-8' );
		}

		return implode( '', array_slice( self::characters( $message ), $offset, $limit ) );
	}

	private static function characters( string $message ): array {
		preg_match_all( '/./us', $message, $matches );
		return $matches[0] ?? [];
	}
}
