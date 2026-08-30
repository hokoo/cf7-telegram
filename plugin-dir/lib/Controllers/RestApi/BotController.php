<?php

namespace iTRON\cf7Telegram\Controllers\RestApi;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Bot;
use iTRON\wpPostAble\Exceptions\wppaException;
use Throwable;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Response;
use WP_REST_Server;

class BotController extends Controller {
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/ping',
			[
				'args'   => [
					'id' => self::id_arg_schema(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'ping' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'deprecated_ping' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
			]
		);

		// Fetch updates endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/fetch_updates',
			[
				'args'   => [
					'id' => self::id_arg_schema(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'fetch_updates' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'deprecated_fetch_updates' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/token',
			[
				'args' => [
					'id' => self::id_arg_schema(),
					'token' => [
						'description'       => 'Replacement Telegram bot token.',
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ self::class, 'validate_token' ],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'replace_token' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
			]
		);
	}

	public static function sanitize_positive_id( $value ): int {
		return function_exists( 'absint' ) ? absint( $value ) : abs( (int) $value );
	}

	public static function validate_positive_id( $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = trim( (string) $value );
		return preg_match( '/^[0-9]+$/', $value ) && (int) $value > 0;
	}

	public static function validate_token( $value ): bool {
		return is_scalar( $value ) && '' !== trim( (string) $value );
	}

	public function ping( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		$bot = $this->loadBotFromRequest( $request );
		if ( $bot instanceof WP_Error ) {
			return $bot;
		}

		try {
			return rest_ensure_response( [ 'online' => $bot->ping(), 'botName' => $bot->getTitle() ] );
		} catch ( Throwable $exception ) {
			return $this->error( 'rest_bot_ping_failed', 'Bot status could not be checked.', 500 );
		}
	}

	public function deprecated_ping( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		return $this->withDeprecationHeaders( $this->ping( $request ) );
	}

	/**
	 * Fetch updates REST API endpoint.
	 */
	public function fetch_updates( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		$bot = $this->loadBotFromRequest( $request );
		if ( $bot instanceof WP_Error ) {
			return $bot;
		}

		try {
			return rest_ensure_response( $bot->fetchUpdates() );
		} catch ( Throwable $exception ) {
			return $this->error( 'rest_fetch_updates_failed', 'Telegram updates could not be checked.', 500 );
		}
	}

	public function deprecated_fetch_updates( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		return $this->withDeprecationHeaders( $this->fetch_updates( $request ) );
	}

	public function replace_token( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		$bot = $this->loadBotFromRequest( $request );
		if ( $bot instanceof WP_Error ) {
			return $bot;
		}

		try {
			$result = $bot->replaceTokenIfValid( sanitize_text_field( (string) $this->getRequestParam( $request, 'token' ) ) );
		} catch ( \iTRON\cf7Telegram\Exceptions\Telegram $exception ) {
			return new WP_Error(
				'rest_bot_token_invalid',
				$exception->getMessage(),
				[ 'status' => 400 ]
			);
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'rest_bot_token_update_failed',
				'Bot token could not be updated.',
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( $result );
	}

	private static function id_arg_schema(): array {
		return [
			'description'       => 'Unique identifier for the bot.',
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => [ self::class, 'sanitize_positive_id' ],
			'validate_callback' => [ self::class, 'validate_positive_id' ],
		];
	}

	private function loadBotFromRequest( $request ): Bot|WP_Error {
		$id = $this->getRequestParam( $request, 'id' );

		if ( ! self::validate_positive_id( $id ) ) {
			return $this->error( 'rest_invalid_param', 'Invalid bot ID.', 400 );
		}

		$id = self::sanitize_positive_id( $id );

		try {
			return new Bot( $id );
		} catch ( wppaException $exception ) {
			return $this->error( 'rest_post_invalid_id', 'Invalid bot ID.', 404 );
		}
	}

	private function getRequestParam( $request, string $key ) {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return $request->get_param( $key );
		}

		if ( is_array( $request ) || $request instanceof \ArrayAccess ) {
			return $request[ $key ] ?? null;
		}

		return null;
	}

	private function withDeprecationHeaders( $response ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$response = rest_ensure_response( $response );
		$response->header( 'Deprecation', 'true' );
		$response->header( 'X-CF7TG-Deprecated-Route', 'Use POST for this mutating endpoint.' );

		return $response;
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			[ 'status' => $status ]
		);
	}

	/**
	 * @param $post
	 * @param $request
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $post, $request ): WP_REST_Response {
		$response = parent::prepare_item_for_response( $post, $request );

		$base = sprintf( '%s/%s', $this->namespace, $this->rest_base );
		$response->add_link( 'ping', rest_url( trailingslashit( $base ) . $post->ID . '/ping' ) );
		$response->add_link( 'fetch_updates', rest_url( trailingslashit( $base ) . $post->ID . '/fetch_updates' ) );
		$response->add_link( 'replace_token', rest_url( trailingslashit( $base ) . $post->ID . '/token' ) );

		return $response;
	}
}
