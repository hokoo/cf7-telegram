<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Illuminate\Support\Collection;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use iTRON\wpPostAble\Exceptions\wppaSavePostException;
use wpdb;

class Util {
	private const TELEGRAM_CHAT_TYPES = [ 'private', 'group', 'supergroup', 'channel' ];

	/**
	 * Runs the SQL query for installing/upgrading a table.
	 *
	 * @param string $tableName
	 * @param string $columns The SQL columns for the CREATE TABLE statement.
	 * @param array $opts (optional) Various other options.
	 *
	 * @return void
	 */
	static function installTable( string $tableName, string $columns, array $opts = [] ) {
		self::getWPDB()->tables[] = $tableName;
		self::getWPDB()->$tableName = self::getWPDB()->prefix . $tableName;

		$full_table_name = self::getWPDB()->$tableName;

		if ( is_string( $opts ) ) {
			$opts = [ 'upgrade_method' => $opts ];
		}

		$opts = wp_parse_args( $opts, [
			'upgrade_method' => 'dbDelta',
			'table_options' => '',
		] );

		$charset_collate = '';
		if ( self::getWPDB()->has_cap( 'collation' ) ) {
			if ( ! empty( $wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}

		$table_options = $charset_collate . ' ' . $opts['table_options'];

		if ( 'dbDelta' == $opts['upgrade_method'] ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( "CREATE TABLE $full_table_name ( $columns ) $table_options" );
			return;
		}

		if ( 'delete_first' == $opts['upgrade_method'] ) {
			self::getWPDB()->query( "DROP TABLE IF EXISTS $full_table_name;" );
		}

		self::getWPDB()->query( "CREATE TABLE IF NOT EXISTS $full_table_name ( $columns ) $table_options;" );
	}

	static function getWPDB(): wpdb {
		global $wpdb;
		return $wpdb;
	}

	static function sanitizeTelegramText( $value, bool $collapseWhitespace = true ): string {
		if ( is_null( $value ) ) {
			return '';
		}

		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = wp_strip_all_tags( $value, false );
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? '';

		if ( $collapseWhitespace ) {
			$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		}

		return trim( $value );
	}

	static function sanitizeTelegramChatID( $chatID ): string {
		return preg_replace( '/\s+/u', '', self::sanitizeTelegramText( $chatID, false ) ) ?? '';
	}

	static function sanitizeTelegramChatType( $chatType ): string {
		$chatType = sanitize_key( (string) $chatType );
		return in_array( $chatType, self::TELEGRAM_CHAT_TYPES, true ) ? $chatType : '';
	}

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	static function getChatByTelegramID( $chatID ): ?Chat {
		$chats = get_posts( [
			'post_type'      => Client::CPT_CHAT,
			'posts_per_page' => - 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
		] );

		foreach ( $chats as $postID ) {
			$chat = new Chat( $postID );
			if ( $chat->getChatID() == $chatID ) {
				return $chat;
			}
		}

		return null;
	}

	/**
	 * @throws wppaSavePostException
	 */
	static function createChat( Collection $tg_chat ): Chat {
		$chatID    = self::sanitizeTelegramChatID( $tg_chat->get( 'id' ) );
		$chatType  = self::sanitizeTelegramChatType( $tg_chat->get( 'type' ) );
		$firstName = self::sanitizeTelegramText( $tg_chat->get( 'first_name' ) ?? '' );
		$lastName  = self::sanitizeTelegramText( $tg_chat->get( 'last_name' ) ?? '' );
		$username  = self::sanitizeTelegramText( $tg_chat->get( 'username' ) ?? '' );
		$title     = self::sanitizeTelegramText( $tg_chat->get( 'title' ) ?? '' );

		if ( '' === $title ) {
			$title = trim( $firstName . ' ' . $lastName );
		}

		if ( '' === $title && '' !== $username ) {
			$title = '@' . ltrim( $username, '@' );
		}

		if ( '' === $title ) {
			$title = $chatID ?: __( 'Telegram Chat', 'cf7-telegram' );
		}

		$chat = new Chat();
		$chat
			->setChatID( $chatID )
			->setChatType( $chatType )
			->setFirstName( $firstName )
			->setLastName( $lastName )
			->setUsername( $username )
			->setTitle( $title )
			->publish();

		return $chat;
	}

	/**
	 * Converts a version string to an integer for comparison.
	 *
	 * @param string $version Version string (e.g., "1.2.3-beta4").
	 *
	 * @return int Integer representation of the version.
	 *
	 * @throws \InvalidArgumentException If the version string is invalid.
	 */
	static function versionToInt(string $version): int {
		// Regex: major.minor[.patch][-tagN]
		if (!preg_match(
			'/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([a-z]+)(\d+)?)?$/i',
			$version,
			$m
		)) {
			throw new \InvalidArgumentException( esc_html( "Invalid version: $version" ) );
		}

		$major = (int)($m[1] ?? 0);
		$minor = (int)($m[2] ?? 0);
		$patch = (int)($m[3] ?? 0);

		$tag   = strtolower($m[4] ?? '');
		$tagNo = (int)($m[5] ?? 0);

		// Order: dev < alpha < beta < rc < (no tag = stable)
		$tagRankMap = [
			'dev'   => 0,
			'alpha' => 1,
			'a'     => 1,
			'beta'  => 2,
			'b'     => 2,
			'rc'    => 3,
			''      => 4, // stable
		];

		$tagRank = $tagRankMap[$tag] ?? 4;

		// Encode: MMM MMM MMM T NNN
		return $major * 10000000000
		       + $minor * 10000000
		       + $patch * 10000
		       + $tagRank * 1000
		       + $tagNo;
	}

}
