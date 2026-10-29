<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit;

use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use WP_Error;
use WP_Post;

class Abilities {
	private const RESOURCES = [
		'forms'      => Client::CPT_CF7FORM,
		'bridges'    => Client::CPT_CHANNEL,
		'bots'       => Client::CPT_BOT,
		'recipients' => Client::CPT_CHAT,
	];

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'registerCategory' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register' ] );
	}

	public static function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) return;
		wp_register_ability_category( 'cf7-telegram', [
			'label'       => __( 'CF7 Telegram', 'cf7-telegram' ),
			'description' => __( 'Read Contact Form 7 to Telegram bridge configuration.', 'cf7-telegram' ),
		] );
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		wp_register_ability( 'cf7-telegram/get-configuration', [
			'label'               => __( 'Get CF7 Telegram configuration', 'cf7-telegram' ),
			'description'         => __( 'List visible forms, bridges, bots, or recipients without sending messages or changing configuration.', 'cf7-telegram' ),
			'category'            => 'cf7-telegram',
			'input_schema'        => self::inputSchema(),
			'output_schema'       => self::outputSchema(),
			'permission_callback' => [ self::class, 'permission' ],
			'execute_callback'    => [ self::class, 'getConfiguration' ],
			'meta'                => [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			],
		] );
	}

	private static function inputSchema(): array {
		return [
			'type' => 'object', 'additionalProperties' => false, 'required' => [ 'resource' ],
			'properties' => [
				'resource' => [ 'type' => 'string', 'enum' => array_keys( self::RESOURCES ) ],
				'page' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
			],
		];
	}

	private static function outputSchema(): array {
		$id = [ 'type' => 'integer', 'minimum' => 1 ];
		$ids = [ 'type' => 'array', 'items' => $id ];
		$base = [ 'id' => $id, 'label' => [ 'type' => 'string' ], 'relations_complete' => [ 'type' => 'boolean' ] ];
		$items = [];
		foreach ( [
			'forms' => [ 'bridge_ids' => $ids ],
			'bridges' => [ 'bot_id' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ], 'form_ids' => $ids, 'recipient_ids' => $ids ],
			'bots' => [ 'token_configured' => [ 'type' => 'boolean' ], 'cached_status' => [ 'type' => 'string', 'enum' => [ 'online', 'offline', 'unknown' ] ] ],
			'recipients' => [
				'destination_kind' => [ 'type' => 'string', 'enum' => [ 'default', 'forum_topic', 'invalid' ] ],
				'message_thread_id' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ],
				'bridge_ids' => $ids,
				'bot_connections' => [ 'type' => 'array', 'items' => [
					'type' => 'object', 'additionalProperties' => false, 'required' => [ 'bot_id', 'status' ],
					'properties' => [ 'bot_id' => $id, 'status' => [ 'type' => 'string', 'enum' => [ 'active', 'pending', 'muted', 'unknown' ] ] ],
				] ],
			],
		] as $resource => $extra ) {
			$properties = array_merge( $base, $extra );
			$items[] = [ 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $properties ), 'properties' => $properties ];
		}
		return [
			'type' => 'object', 'additionalProperties' => false,
			'required' => [ 'schema_version', 'resource', 'page', 'per_page', 'total', 'total_pages', 'items' ],
			'properties' => [
				'schema_version' => [ 'type' => 'integer', 'enum' => [ 1 ] ],
				'resource' => [ 'type' => 'string', 'enum' => array_keys( self::RESOURCES ) ],
				'page' => [ 'type' => 'integer', 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
				'total' => [ 'type' => 'integer', 'minimum' => 0 ],
				'total_pages' => [ 'type' => 'integer', 'minimum' => 0 ],
				'items' => [ 'type' => 'array', 'items' => [ 'oneOf' => $items ] ],
			],
		];
	}

	private static function ready(): bool {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) || ! class_exists( '\\WPCF7_ContactForm' ) ) return false;
		foreach ( self::RESOURCES as $postType ) {
			if ( ! post_type_exists( $postType ) ) return false;
		}
		$caps = CPT::get_instance()->cf7_orig_capabilities;
		if ( empty( $caps['edit_posts'] ) || ! Client::hasInitializedConnections() ) return false;
		try {
			$client = Client::getInstance();
			$client->getBot2ChannelRelation();
			$client->getChat2ChannelRelation();
			$client->getForm2ChannelRelation();
			$client->getBot2ChatRelation();
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public static function permission() {
		if ( ! self::ready() ) return self::error( 'cf7tg_not_ready', __( 'CF7 Telegram is not ready.', 'cf7-telegram' ), 503 );
		if ( ! is_user_logged_in() || ! current_user_can( Settings::getCaps() ) ) {
			return self::error( 'cf7tg_forbidden', __( 'You cannot read CF7 Telegram configuration.', 'cf7-telegram' ), 403 );
		}
		return true;
	}

	public static function getConfiguration( $input ) {
		$permission = self::permission();
		if ( true !== $permission ) return $permission;
		if ( ! is_array( $input ) || ! isset( $input['resource'] ) || ! is_string( $input['resource'] ) || ! isset( self::RESOURCES[ $input['resource'] ] ) ||
			array_diff( array_keys( $input ), [ 'resource', 'page', 'per_page' ] ) ) {
			return self::error( 'cf7tg_invalid_input', __( 'Invalid configuration request.', 'cf7-telegram' ), 400 );
		}
		$page = array_key_exists( 'page', $input ) ? $input['page'] : 1;
		$perPage = array_key_exists( 'per_page', $input ) ? $input['per_page'] : 50;
		if ( ! is_int( $page ) || $page < 1 || ! is_int( $perPage ) || $perPage < 1 || $perPage > 100 ) {
			return self::error( 'cf7tg_invalid_input', __( 'Invalid configuration request.', 'cf7-telegram' ), 400 );
		}
		$resource = $input['resource'];
		try {
			$posts = self::storageRead( static fn() => get_posts( [ 'post_type' => self::RESOURCES[ $resource ], 'post_status' => 'publish', 'posts_per_page' => -1,
				'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] ) );
			if ( ! is_array( $posts ) ) throw new \RuntimeException();
			$visible = [];
			foreach ( $posts as $postID ) {
				$post = self::storageRead( static fn() => get_post( (int) $postID ) );
				if ( $post instanceof WP_Post && $post->post_type === self::RESOURCES[ $resource ] &&
					$post->post_status === 'publish' && current_user_can( 'read_post', $post->ID ) ) $visible[] = $post;
			}
			usort( $visible, static fn( WP_Post $a, WP_Post $b ): int => $a->ID <=> $b->ID );
			$total = count( $visible );
			$relations = self::relations();
			$items = [];
			$offset = $page > intdiv( PHP_INT_MAX, $perPage ) ? $total : ( $page - 1 ) * $perPage;
			foreach ( array_slice( $visible, $offset, $perPage ) as $post ) {
				$items[] = self::item( $resource, $post, $relations );
			}
			return [ 'schema_version' => 1, 'resource' => $resource, 'page' => $page, 'per_page' => $perPage,
				'total' => $total, 'total_pages' => (int) ceil( $total / $perPage ), 'items' => $items ];
		} catch ( \Throwable $error ) {
			return self::error( 'cf7tg_storage_unavailable', __( 'Configuration storage is unavailable.', 'cf7-telegram' ), 503 );
		}
	}

	private static function relations(): array {
		$client = Client::getInstance();
		$map = [
			Client::FORM2CHANNEL => $client->getForm2ChannelRelation(),
			Client::BOT2CHANNEL => $client->getBot2ChannelRelation(),
			Client::CHAT2CHANNEL => $client->getChat2ChannelRelation(),
			Client::BOT2CHAT => $client->getBot2ChatRelation(),
		];
		$result = [];
		foreach ( $map as $name => $relation ) {
			$result[ $name ] = [];
			$result[ $name ] = self::storageRead( static function () use ( $relation ): array {
				$connections = [];
				foreach ( $relation->findConnections( new ConnectionQuery() ) as $connection ) $connections[] = $connection;
				return $connections;
			} );
		}
		return $result;
	}

	private static function storageRead( callable $read ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		$warning = false;
		set_error_handler( static function () use ( &$warning ): bool { $warning = true; return true; }, E_WARNING | E_NOTICE | E_USER_WARNING );
		try {
			$result = $read();
			self::checkStorage();
			if ( $warning ) throw new \RuntimeException();
			return $result;
		} finally {
			restore_error_handler();
			$wpdb->suppress_errors( $previous );
		}
	}

	private static function checkStorage(): void {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) throw new \RuntimeException();
	}

	private static function item( string $resource, WP_Post $post, array $relations ): array {
		$validParams = true;
		$params = self::params( $post, $validParams );
		$item = [ 'id' => $post->ID, 'label' => self::label( $post, $params ), 'relations_complete' => $validParams ];
		switch ( $resource ) {
			case 'forms':
				$item['bridge_ids'] = self::related( $post->ID, $relations[ Client::FORM2CHANNEL ], 'from', Client::CPT_CHANNEL, $item['relations_complete'] );
				break;
			case 'bridges':
				$bots = self::related( $post->ID, $relations[ Client::BOT2CHANNEL ], 'to', Client::CPT_BOT, $item['relations_complete'] );
				$rawBotCount = self::edgeCount( $post->ID, $relations[ Client::BOT2CHANNEL ], 'to' );
				$item['bot_id'] = 1 === $rawBotCount && 1 === count( $bots ) ? $bots[0] : null;
				if ( $rawBotCount > 1 ) $item['relations_complete'] = false;
				$item['form_ids'] = self::related( $post->ID, $relations[ Client::FORM2CHANNEL ], 'to', Client::CPT_CF7FORM, $item['relations_complete'] );
				$item['recipient_ids'] = self::related( $post->ID, $relations[ Client::CHAT2CHANNEL ], 'to', Client::CPT_CHAT, $item['relations_complete'] );
				break;
			case 'bots':
				$token = BotToken::value( $post->ID, $params[ Bot::TOKEN_SOURCE_PARAM ] ?? null, $params['token'] ?? null );
				$item['token_configured'] = ! BotToken::isEmpty( $token );
				$item['cached_status'] = in_array( $params['lastStatus'] ?? null, [ 'online', 'offline' ], true ) ? $params['lastStatus'] : 'unknown';
				self::related( $post->ID, $relations[ Client::BOT2CHANNEL ], 'from', Client::CPT_CHANNEL, $item['relations_complete'] );
				self::related( $post->ID, $relations[ Client::BOT2CHAT ], 'from', Client::CPT_CHAT, $item['relations_complete'] );
				break;
			case 'recipients':
				[ $item['destination_kind'], $item['message_thread_id'] ] = self::destination( $params );
				$item['bridge_ids'] = self::related( $post->ID, $relations[ Client::CHAT2CHANNEL ], 'from', Client::CPT_CHANNEL, $item['relations_complete'] );
				$item['bot_connections'] = [];
				foreach ( $relations[ Client::BOT2CHAT ] as $connection ) {
					if ( $connection->to !== $post->ID ) continue;
					$bot = self::visiblePost( $connection->from, Client::CPT_BOT );
					if ( ! $bot ) { $item['relations_complete'] = false; continue; }
					$statusMeta = $connection->meta->where( 'key', Chat::STATUS_KEY );
					$status = 1 === count( $statusMeta ) ? $statusMeta->first()->value : null;
					$item['bot_connections'][] = [ 'bot_id' => $bot->ID, 'status' => in_array( $status, [ 'active', 'pending', 'muted' ], true ) ? $status : 'unknown' ];
				}
				usort( $item['bot_connections'], static fn( array $a, array $b ): int => $a['bot_id'] <=> $b['bot_id'] );
				break;
		}
		return $item;
	}

	private static function related( int $id, array $connections, string $side, string $type, bool &$complete ): array {
		$ids = [];
		$other = 'from' === $side ? 'to' : 'from';
		foreach ( $connections as $connection ) {
			if ( $connection->$side !== $id ) continue;
			$post = self::visiblePost( $connection->$other, $type );
			if ( ! $post ) { $complete = false; continue; }
			$ids[] = $post->ID;
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function edgeCount( int $id, array $connections, string $side ): int {
		$count = 0;
		foreach ( $connections as $connection ) if ( $connection->$side === $id ) $count++;
		return $count;
	}

	private static function visiblePost( int $id, string $type ): ?WP_Post {
		if ( $id < 1 ) return null;
		$post = self::storageRead( static fn() => get_post( $id ) );
		return $post instanceof WP_Post && $post->post_type === $type && $post->post_status === 'publish' && current_user_can( 'read_post', $id ) ? $post : null;
	}

	private static function params( WP_Post $post, bool &$valid ): array {
		if ( '' === $post->post_content_filtered ) return [];
		$params = json_decode( $post->post_content_filtered );
		$valid = is_object( $params );
		return $valid ? get_object_vars( $params ) : [];
	}

	private static function destination( array $params ): array {
		$chatID = $params['chatID'] ?? null;
		$type = $params['chatType'] ?? null;
		if ( ! is_scalar( $chatID ) || '' === Util::sanitizeTelegramChatID( $chatID ) || ! is_string( $type ) || '' === Util::sanitizeTelegramChatType( $type ) ) return [ 'invalid', null ];
		try {
			$thread = Util::normalizeMessageThreadID( $params['messageThreadID'] ?? null );
			if ( null !== $thread && 'supergroup' !== $type ) return [ 'invalid', $thread ];
			return [ null === $thread ? 'default' : 'forum_topic', $thread ];
		} catch ( \InvalidArgumentException $error ) {
			return [ 'invalid', null ];
		}
	}

	private static function label( WP_Post $post, array $params ): string {
		$label = Util::sanitizeTelegramText( $post->post_title );
		if ( $post->post_type === Client::CPT_CHAT && ! empty( $params['chatID'] ) && is_scalar( $params['chatID'] ) ) {
			$label = str_replace( (string) $params['chatID'], '', $label );
		}
		if ( $post->post_type === Client::CPT_BOT ) {
			$token = BotToken::value( $post->ID, $params[ Bot::TOKEN_SOURCE_PARAM ] ?? null, $params['token'] ?? null );
			$label = str_replace( Bot::getEmptyToken(), '', $label );
			if ( is_string( $token ) && '' !== $token ) {
				$label = str_replace( $token, '', $label );
				if ( strlen( $token ) >= 4 ) $label = str_replace( substr( $token, -4 ), '', $label );
			}
		}
		$label = LogRedactor::redactString( $label );
		$label = Telegram\TelegramRedactor::text( $label );
		$label = preg_replace( '/(?<!\d)-?\d{8,}(?!\d)/', LogRedactor::REDACTION_MARKER, $label ) ?? $label;
		$label = preg_replace( '/WPFC7TG_BOT_TOKEN(?:__\d+)?/', '', $label ) ?? $label;
		$label = trim( $label );
		return '' === $label ? sprintf( $post->post_type === Client::CPT_CHAT ? __( 'Recipient #%d', 'cf7-telegram' ) : __( 'Item #%d', 'cf7-telegram' ), $post->ID ) : $label;
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
