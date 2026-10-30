<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit;

use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Telegram\TelegramDeliveryResult;
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
		add_filter( 'rest_pre_dispatch', [ self::class, 'decodeRestGetInput' ], 10, 3 );
	}

	/** Core 6.9 passes GET input through as a string despite documenting JSON objects. */
	public static function decodeRestGetInput( $result, $server, $request ) {
		if ( null !== $result || 'GET' !== $request->get_method() ||
			! preg_match( '~^/wp-abilities/v1/abilities/cf7-telegram/(?:get-configuration|diagnose-form|get-recent-errors)/run/?$~', $request->get_route() ) ) return $result;
		$query = $request->get_query_params();
		if ( ! is_string( $query['input'] ?? null ) ) return $result;
		$object = json_decode( $query['input'] );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $object ) ) return $result;
		$query['input'] = json_decode( $query['input'], true );
		$request->set_query_params( $query );
		return $result;
	}

	/** Let the execute callback report the existing 503 while Core is still booting. */
	public static function abilityPermission(): bool {
		return is_user_logged_in() && ( ! self::ready() || current_user_can( Settings::getCaps() ) );
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
			'ability_class'       => ReadOnlyAbility::class,
			'label'               => __( 'Get CF7 Telegram configuration', 'cf7-telegram' ),
			'description'         => __( 'List visible forms, bridges, bots, or recipients without sending messages or changing configuration.', 'cf7-telegram' ),
			'category'            => 'cf7-telegram',
			'input_schema'        => self::inputSchema(),
			'output_schema'       => self::outputSchema(),
			'permission_callback' => [ self::class, 'abilityPermission' ],
			'execute_callback'    => [ self::class, 'getConfiguration' ],
			'meta'                => [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			],
		] );
		wp_register_ability( 'cf7-telegram/diagnose-form', [
			'ability_class'       => ReadOnlyAbility::class,
			'label'               => __( 'Diagnose CF7 Telegram form', 'cf7-telegram' ),
			'description'         => __( 'Inspect saved delivery routes for one form without contacting Telegram or changing configuration.', 'cf7-telegram' ),
			'category'            => 'cf7-telegram',
			'input_schema'        => [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'form_id' ],
				'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] ],
			'output_schema'       => self::diagnosisSchema(),
			'permission_callback' => [ self::class, 'abilityPermission' ],
			'execute_callback'    => [ self::class, 'diagnoseForm' ],
			'meta'                => [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			],
		] );
		wp_register_ability( 'cf7-telegram/get-recent-errors', [
			'ability_class'       => ReadOnlyAbility::class,
			'label'               => __( 'Get recent CF7 Telegram errors', 'cf7-telegram' ),
			'description'         => __( 'Read a bounded page of sanitized plugin log events without changing them.', 'cf7-telegram' ),
			'category'            => 'cf7-telegram',
			'input_schema'        => self::recentErrorsInputSchema(),
			'output_schema'       => self::recentErrorsOutputSchema(),
			'permission_callback' => [ self::class, 'recentErrorsAbilityPermission' ],
			'execute_callback'    => [ self::class, 'getRecentErrors' ],
			'meta'                => [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			],
		] );
	}

	private static function recentErrorsInputSchema(): array {
		return [ 'type' => 'object', 'default' => [], 'additionalProperties' => false, 'properties' => [
			'since' => [ 'type' => 'integer', 'minimum' => 0 ],
			'until' => [ 'type' => 'integer', 'minimum' => 0 ],
			'min_level' => [ 'type' => 'integer', 'enum' => [ 0, 1, 2, 3 ], 'default' => 1 ],
			'before_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
		] ];
	}

	private static function recentErrorsOutputSchema(): array {
		$nullableID = [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ];
		$nullableNumber = [ 'type' => [ 'integer', 'null' ] ];
		$context = [
			'bot_id' => $nullableID, 'bridge_id' => $nullableID, 'recipient_id' => $nullableID,
			'chunk' => $nullableID, 'http_status' => $nullableNumber,
			'telegram_error_code' => $nullableNumber, 'error_type' => [ 'type' => [ 'string', 'null' ],
				'enum' => [ null, TelegramDeliveryResult::ERROR_TRANSPORT, TelegramDeliveryResult::ERROR_HTTP,
					TelegramDeliveryResult::ERROR_TELEGRAM, TelegramDeliveryResult::ERROR_MALFORMED_RESPONSE ] ],
			'retry_after' => $nullableNumber,
		];
		$item = [
			'id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'occurred_at' => [ 'type' => 'integer', 'minimum' => 0 ],
			'level' => [ 'type' => 'integer', 'enum' => [ 0, 1, 2, 3 ] ],
			'event' => [ 'type' => 'string', 'enum' => [ 'telegram_delivery_failed', 'bot_unreachable', 'bot_token_missing', 'bridge_bot_missing', 'other' ] ],
			'message' => [ 'type' => 'string' ],
			'context' => [ 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $context ), 'properties' => $context ],
		];
		$retention = [ 'days' => [ 'type' => 'integer', 'minimum' => 0 ], 'max_rows' => [ 'type' => 'integer', 'minimum' => 1 ] ];
		$properties = [
			'schema_version' => [ 'type' => 'integer', 'enum' => [ 1 ] ],
			'since' => [ 'type' => 'integer', 'minimum' => 0 ],
			'until' => [ 'type' => 'integer', 'minimum' => 0 ],
			'min_level' => [ 'type' => 'integer', 'enum' => [ 0, 1, 2, 3 ] ],
			'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
				'required' => array_keys( $item ), 'properties' => $item ] ],
			'next_before_id' => $nullableID,
			'history_complete' => [ 'type' => 'boolean', 'enum' => [ false ] ],
			'retention' => [ 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $retention ), 'properties' => $retention ],
		];
		return [ 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $properties ), 'properties' => $properties ];
	}

	private static function diagnosisSchema(): array {
		$id = [ 'type' => 'integer', 'minimum' => 1 ];
		$nullableID = [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ];
		$bridge = [ 'bridge_id' => $id, 'eligible_recipient_ids' => [ 'type' => 'array', 'items' => $id ] ];
		$issue = [
			'code' => [ 'type' => 'string', 'enum' => [ 'no_bridge', 'broken_relation', 'no_bot', 'token_missing', 'no_recipients', 'recipient_not_active', 'invalid_destination', 'cached_bot_offline' ] ],
			'severity' => [ 'type' => 'string', 'enum' => [ 'warning', 'error' ] ],
			'bridge_id' => $nullableID, 'recipient_id' => $nullableID,
			'message' => [ 'type' => 'string' ], 'suggested_action' => [ 'type' => 'string' ],
		];
		return [ 'type' => 'object', 'additionalProperties' => false,
			'required' => [ 'schema_version', 'form_id', 'configuration_status', 'delivery_verified', 'bridges', 'issues' ],
			'properties' => [
				'schema_version' => [ 'type' => 'integer', 'enum' => [ 1 ] ], 'form_id' => $id,
				'configuration_status' => [ 'type' => 'string', 'enum' => [ 'ready', 'partial', 'blocked' ] ],
				'delivery_verified' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'bridges' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
					'required' => array_keys( $bridge ), 'properties' => $bridge ] ],
				'issues' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => false,
					'required' => array_keys( $issue ), 'properties' => $issue ] ],
			],
		];
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

	public static function recentErrorsAbilityPermission(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' ) &&
			( ! self::ready() || current_user_can( Settings::getCaps() ) );
	}

	public static function getRecentErrors( $input = [] ) {
		$permission = self::permission();
		if ( true !== $permission ) return $permission;
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::error( 'cf7tg_forbidden', __( 'You cannot read CF7 Telegram logs.', 'cf7-telegram' ), 403 );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'since', 'until', 'min_level', 'before_id', 'limit' ] ) ) {
			return self::error( 'cf7tg_invalid_input', __( 'Invalid recent errors request.', 'cf7-telegram' ), 400 );
		}
		$now = time();
		$since = array_key_exists( 'since', $input ) ? $input['since'] : max( 0, $now - 24 * HOUR_IN_SECONDS );
		$until = array_key_exists( 'until', $input ) ? $input['until'] : $now;
		$minLevel = array_key_exists( 'min_level', $input ) ? $input['min_level'] : 1;
		$beforeID = $input['before_id'] ?? null;
		$limit = array_key_exists( 'limit', $input ) ? $input['limit'] : 20;
		if ( ! is_int( $since ) || $since < 0 || ! is_int( $until ) || $until < $since ||
			! is_int( $minLevel ) || $minLevel < 0 || $minLevel > 3 ||
			( array_key_exists( 'before_id', $input ) && ( ! is_int( $beforeID ) || $beforeID < 1 ) ) ||
			! is_int( $limit ) || $limit < 1 || $limit > 100 ) {
			return self::error( 'cf7tg_invalid_input', __( 'Invalid recent errors request.', 'cf7-telegram' ), 400 );
		}
		try {
			global $wpdb;
			$table = $wpdb->prefix . 'cf7tg_log';
			$exists = self::storageRead( static fn() => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
			$rows = [];
			if ( $exists === $table ) {
				$identifier = '`' . str_replace( '`', '``', $table ) . '`';
				$query = "SELECT ID, date, level, msg, data FROM $identifier WHERE date BETWEEN %d AND %d AND level BETWEEN %d AND %d";
				$args = [ $since, $until, $minLevel, Logger::LEVEL_CRITICAL ];
				if ( null !== $beforeID ) {
					$query .= ' AND ID < %d';
					$args[] = $beforeID;
				}
				$query .= ' ORDER BY ID DESC LIMIT %d';
				$args[] = $limit + 1;
				$rows = self::storageRead( static fn() => $wpdb->get_results( $wpdb->prepare( $query, $args ) ) );
				if ( ! is_array( $rows ) ) throw new \RuntimeException();
			}
			$hasMore = count( $rows ) > $limit;
			$items = array_map( [ self::class, 'recentErrorItem' ], array_slice( $rows, 0, $limit ) );
			return [
				'schema_version' => 1, 'since' => $since, 'until' => $until, 'min_level' => $minLevel,
				'items' => $items, 'next_before_id' => $hasMore ? $items[ count( $items ) - 1 ]['id'] : null,
				'history_complete' => false,
				'retention' => [ 'days' => Maintenance::getLogRetentionDays(), 'max_rows' => Maintenance::getLogMaxRows() ],
			];
		} catch ( \Throwable $error ) {
			return self::error( 'cf7tg_storage_unavailable', __( 'Log storage is unavailable.', 'cf7-telegram' ), 503 );
		}
	}

	private static function recentErrorItem( $row ): array {
		$messages = [
			'Telegram delivery failed.' => [ 'telegram_delivery_failed', __( 'Telegram delivery failed.', 'cf7-telegram' ) ],
			'Bot is unreachable' => [ 'bot_unreachable', __( 'Bot is unreachable.', 'cf7-telegram' ) ],
			'Bot cannot be pinged' => [ 'bot_unreachable', __( 'Bot is unreachable.', 'cf7-telegram' ) ],
			'Bot initialization error.' => [ 'bot_token_missing', __( 'Bot token is missing.', 'cf7-telegram' ) ],
			'Channel has no bot connected. Telegram send skipped.' => [ 'bridge_bot_missing', __( 'Bridge has no connected bot.', 'cf7-telegram' ) ],
		];
		$known = is_object( $row ) && is_string( $row->msg ?? null ) ? ( $messages[ $row->msg ] ?? null ) : null;
		$decoded = is_object( $row ) && is_string( $row->data ?? null ) ? json_decode( $row->data ) : null;
		$data = is_object( $decoded ) ? get_object_vars( $decoded ) : null;
		if ( $known && 'bot_token_missing' === $known[0] && 'Bot token is not set.' === $row->data ) $data = [];
		if ( ! is_array( $data ) ) $known = null;
		$context = array_fill_keys( [ 'bot_id', 'bridge_id', 'recipient_id', 'chunk', 'http_status',
			'telegram_error_code', 'error_type', 'retry_after' ], null );
		if ( $known ) {
			$event = $known[0];
			if ( 'telegram_delivery_failed' === $event ) {
				$context['recipient_id'] = self::logInteger( $data['chatPostID'] ?? null, 1 );
				$context['chunk'] = self::logInteger( $data['chunk'] ?? null, 1 );
			} elseif ( 'bridge_bot_missing' === $event ) {
				$context['bridge_id'] = self::logInteger( $data['channelPostID'] ?? null, 1 );
			}
			if ( in_array( $event, [ 'telegram_delivery_failed', 'bot_unreachable' ], true ) ) {
				$context['http_status'] = self::logInteger( $data['status'] ?? null, 0, 599 );
				$context['telegram_error_code'] = self::logInteger( $data['errorCode'] ?? null );
				$context['retry_after'] = self::logInteger( $data['retryAfter'] ?? null, 0 );
				$types = [ TelegramDeliveryResult::ERROR_TRANSPORT, TelegramDeliveryResult::ERROR_HTTP,
					TelegramDeliveryResult::ERROR_TELEGRAM, TelegramDeliveryResult::ERROR_MALFORMED_RESPONSE ];
				$context['error_type'] = in_array( $data['errorType'] ?? null, $types, true ) ? $data['errorType'] : null;
			}
		}
		return [
			'id' => (int) $row->ID, 'occurred_at' => (int) $row->date, 'level' => (int) $row->level,
			'event' => $known[0] ?? 'other',
			'message' => LogRedactor::redactString( $known[1] ?? __( 'Plugin event', 'cf7-telegram' ) ),
			'context' => $context,
		];
	}

	private static function logInteger( $value, int $minimum = PHP_INT_MIN, int $maximum = PHP_INT_MAX ): ?int {
		return is_int( $value ) && $value >= $minimum && $value <= $maximum ? $value : null;
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
				'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ] ) );
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

	public static function diagnoseForm( $input ) {
		$permission = self::permission();
		if ( true !== $permission ) return $permission;
		if ( ! is_array( $input ) || array_keys( $input ) !== [ 'form_id' ] || ! is_int( $input['form_id'] ) || $input['form_id'] < 1 ) {
			return self::error( 'cf7tg_invalid_input', __( 'Invalid diagnosis request.', 'cf7-telegram' ), 400 );
		}
		$formID = $input['form_id'];
		try {
			$posts = [];
			$form = self::graphPost( $formID, $posts );
			if ( ! self::validGraphPost( $form, Client::CPT_CF7FORM ) ) {
				return self::error( 'cf7tg_not_found', __( 'Form not found.', 'cf7-telegram' ), 404 );
			}
			$relations = self::relations();
			$bridgeIDs = [];
			foreach ( $relations[ Client::FORM2CHANNEL ] as $edge ) if ( $edge->from === $formID ) $bridgeIDs[] = $edge->to;
			$bridgeIDs = array_values( array_unique( $bridgeIDs ) );
			$recipientIDs = [];
			foreach ( $bridgeIDs as $bridgeID ) self::graphPost( $bridgeID, $posts );
			foreach ( [ Client::FORM2CHANNEL, Client::BOT2CHANNEL, Client::CHAT2CHANNEL ] as $name ) {
				foreach ( $relations[ $name ] as $edge ) {
					if ( ! in_array( $edge->to, $bridgeIDs, true ) ) continue;
					self::graphPost( $edge->from, $posts );
					if ( Client::CHAT2CHANNEL === $name ) $recipientIDs[] = $edge->from;
				}
			}
			foreach ( $relations[ Client::BOT2CHAT ] as $edge ) {
				if ( in_array( $edge->to, $recipientIDs, true ) ) self::graphPost( $edge->from, $posts );
			}

			$issues = [];
			$bridges = [];
			if ( ! $bridgeIDs ) self::issue( $issues, 'no_bridge' );
			foreach ( $bridgeIDs as $bridgeID ) {
				$eligible = [];
				$bridgeRef = $bridgeID > 0 ? $bridgeID : null;
				$bridge = $posts[ $bridgeID ] ?? null;
				if ( ! self::validGraphPost( $bridge, Client::CPT_CHANNEL ) ) {
					self::issue( $issues, 'broken_relation', $bridgeRef );
					if ( $bridgeRef ) $bridges[] = [ 'bridge_id' => $bridgeRef, 'eligible_recipient_ids' => [] ];
					continue;
				}
				$routeValid = true;
				$validBridgeParams = true;
				self::params( $bridge, $validBridgeParams );
				if ( ! $validBridgeParams ) {
					self::issue( $issues, 'broken_relation', $bridgeID );
					$routeValid = false;
				}
				$formCounts = [];
				foreach ( $relations[ Client::FORM2CHANNEL ] as $edge ) {
					if ( $edge->to !== $bridgeID ) continue;
					$formCounts[ $edge->from ] = ( $formCounts[ $edge->from ] ?? 0 ) + 1;
					if ( ! self::validGraphPost( $posts[ $edge->from ] ?? null, Client::CPT_CF7FORM ) ) {
						self::issue( $issues, 'broken_relation', $bridgeID );
						$routeValid = false;
					}
				}
				foreach ( $formCounts as $count ) {
					if ( $count > 1 ) {
						self::issue( $issues, 'broken_relation', $bridgeID );
						$routeValid = false;
					}
				}
				$botEdges = array_values( array_filter( $relations[ Client::BOT2CHANNEL ], static fn( $edge ): bool => $edge->to === $bridgeID ) );
				$bot = null;
				if ( ! $botEdges ) self::issue( $issues, 'no_bot', $bridgeID );
				elseif ( count( $botEdges ) !== 1 || ! self::validGraphPost( $posts[ $botEdges[0]->from ] ?? null, Client::CPT_BOT ) ) {
					self::issue( $issues, 'broken_relation', $bridgeID );
				} else $bot = $posts[ $botEdges[0]->from ];
				$tokenReady = false;
				if ( $bot ) {
					$validParams = true;
					$params = self::params( $bot, $validParams );
					$tokenReady = ! BotToken::isEmpty( BotToken::value( $bot->ID, $params[ Bot::TOKEN_SOURCE_PARAM ] ?? null, $params['token'] ?? null ) );
					if ( ! $validParams ) {
						self::issue( $issues, 'broken_relation', $bridgeID );
						$routeValid = false;
					} elseif ( ! $tokenReady ) self::issue( $issues, 'token_missing', $bridgeID );
					if ( 'offline' === ( $params['lastStatus'] ?? null ) ) self::issue( $issues, 'cached_bot_offline', $bridgeID );
				}
				$chatEdges = array_values( array_filter( $relations[ Client::CHAT2CHANNEL ], static fn( $edge ): bool => $edge->to === $bridgeID ) );
				if ( ! $chatEdges ) self::issue( $issues, 'no_recipients', $bridgeID );
				$recipientCounts = array_count_values( array_map( static fn( $edge ): int => $edge->from, $chatEdges ) );
				foreach ( $chatEdges as $edge ) {
					$recipientID = $edge->from;
					$recipientRef = $recipientID > 0 ? $recipientID : null;
					$recipient = $posts[ $recipientID ] ?? null;
					if ( $recipientCounts[ $recipientID ] !== 1 || ! self::validGraphPost( $recipient, Client::CPT_CHAT ) ) {
						self::issue( $issues, 'broken_relation', $bridgeID, $recipientRef );
						continue;
					}
					$validParams = true;
					$params = self::params( $recipient, $validParams );
					[ $kind ] = self::destination( $params );
					$destinationReady = $validParams && 'invalid' !== $kind;
					if ( ! $destinationReady ) self::issue( $issues, 'invalid_destination', $bridgeID, $recipientID );
					foreach ( $relations[ Client::BOT2CHAT ] as $link ) {
						if ( $link->to === $recipientID && ! self::validGraphPost( $posts[ $link->from ] ?? null, Client::CPT_BOT ) ) {
							self::issue( $issues, 'broken_relation', $bridgeID, $recipientID );
						}
					}
					if ( ! $bot ) continue;
					$links = array_values( array_filter( $relations[ Client::BOT2CHAT ], static fn( $link ): bool => $link->from === $bot->ID && $link->to === $recipientID ) );
					if ( count( $links ) > 1 ) self::issue( $issues, 'broken_relation', $bridgeID, $recipientID );
					$statusMeta = count( $links ) === 1 ? $links[0]->meta->where( 'key', Chat::STATUS_KEY ) : null;
					$status = $statusMeta && count( $statusMeta ) === 1 ? $statusMeta->first()->value : null;
					if ( 'active' !== $status ) self::issue( $issues, 'recipient_not_active', $bridgeID, $recipientID );
					if ( $routeValid && $tokenReady && $destinationReady && 'active' === $status && count( $links ) === 1 ) $eligible[] = $recipientID;
				}
				sort( $eligible, SORT_NUMERIC );
				$bridges[] = [ 'bridge_id' => $bridgeID, 'eligible_recipient_ids' => $eligible ];
			}
			usort( $bridges, static fn( array $a, array $b ): int => $a['bridge_id'] <=> $b['bridge_id'] );
			$hasEligible = false;
			$hasError = false;
			foreach ( $bridges as $bridge ) if ( $bridge['eligible_recipient_ids'] ) $hasEligible = true;
			foreach ( $issues as $issue ) if ( 'error' === $issue['severity'] ) $hasError = true;
			return [ 'schema_version' => 1, 'form_id' => $formID,
				'configuration_status' => ! $hasEligible ? 'blocked' : ( $hasError ? 'partial' : 'ready' ),
				'delivery_verified' => false, 'bridges' => $bridges, 'issues' => $issues ];
		} catch ( \DomainException $error ) {
			return self::error( 'cf7tg_forbidden', __( 'You cannot read CF7 Telegram configuration.', 'cf7-telegram' ), 403 );
		} catch ( \Throwable $error ) {
			return self::error( 'cf7tg_storage_unavailable', __( 'Configuration storage is unavailable.', 'cf7-telegram' ), 503 );
		}
	}

	private static function graphPost( int $id, array &$posts ): ?WP_Post {
		if ( $id < 1 ) return null;
		if ( ! array_key_exists( $id, $posts ) ) {
			$post = self::storageRead( static fn() => get_post( $id ) );
			if ( $post instanceof WP_Post && ! current_user_can( 'read_post', $id ) ) throw new \DomainException();
			$posts[ $id ] = $post instanceof WP_Post ? $post : null;
		}
		return $posts[ $id ];
	}

	private static function validGraphPost( ?WP_Post $post, string $type ): bool {
		return $post && $post->post_type === $type && 'publish' === $post->post_status;
	}

	private static function issue( array &$issues, string $code, ?int $bridgeID = null, ?int $recipientID = null ): void {
		$text = [
			'no_bridge' => [ __( 'No bridge is connected to this form.', 'cf7-telegram' ), __( 'Connect a bridge to the form.', 'cf7-telegram' ) ],
			'broken_relation' => [ __( 'A saved relation is broken.', 'cf7-telegram' ), __( 'Review the related objects and reconnect them.', 'cf7-telegram' ) ],
			'no_bot' => [ __( 'The bridge has no bot.', 'cf7-telegram' ), __( 'Select a bot for the bridge.', 'cf7-telegram' ) ],
			'token_missing' => [ __( 'The bot has no usable token.', 'cf7-telegram' ), __( 'Configure a bot token.', 'cf7-telegram' ) ],
			'no_recipients' => [ __( 'The bridge has no recipients.', 'cf7-telegram' ), __( 'Select recipients for the bridge.', 'cf7-telegram' ) ],
			'recipient_not_active' => [ __( 'The recipient is not active for this bot.', 'cf7-telegram' ), __( 'Activate the recipient connection.', 'cf7-telegram' ) ],
			'invalid_destination' => [ __( 'The recipient destination is invalid.', 'cf7-telegram' ), __( 'Check the saved chat and topic.', 'cf7-telegram' ) ],
			'cached_bot_offline' => [ __( 'The bot was last saved as offline.', 'cf7-telegram' ), __( 'Check the bot status separately.', 'cf7-telegram' ) ],
		];
		$issues[] = [ 'code' => $code, 'severity' => 'cached_bot_offline' === $code ? 'warning' : 'error',
			'bridge_id' => $bridgeID, 'recipient_id' => $recipientID,
			'message' => $text[ $code ][0], 'suggested_action' => $text[ $code ][1] ];
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
		$address = is_int( $chatID ) || is_string( $chatID ) ? (string) $chatID : '';
		$numeric = (bool) preg_match( '/^-?[1-9][0-9]*\z/', $address );
		$username = in_array( $type, [ 'supergroup', 'channel' ], true ) && (bool) preg_match( '/^@[A-Za-z0-9_]+\z/', $address );
		if ( ( ! $numeric && ! $username ) || ! is_string( $type ) || '' === Util::sanitizeTelegramChatType( $type ) ) return [ 'invalid', null ];
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
		if ( '' !== $label ) return $label;
		if ( $post->post_type === Client::CPT_CHAT ) {
			/* translators: %d: Local WordPress recipient post ID. */
			return sprintf( __( 'Recipient #%d', 'cf7-telegram' ), $post->ID );
		}
		/* translators: %d: Local WordPress post ID. */
		return sprintf( __( 'Item #%d', 'cf7-telegram' ), $post->ID );
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
