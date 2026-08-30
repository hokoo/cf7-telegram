<?php

namespace iTRON\cf7Telegram\Controllers\RestApi;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use iTRON\cf7Telegram\Bot;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
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
					'id' => [
						'description' => 'Unique identifier for the object.',
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'ping' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
				],
			]
		);

		// Fetch updates endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/fetch_updates',
			[
				'args'   => [
					'id' => [
						'description' => 'Last update ID.',
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'fetch_updates' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/token',
			[
				'args' => [
					'id' => [
						'description' => 'Unique identifier for the bot.',
						'type'        => 'integer',
					],
					'token' => [
						'required' => true,
						'type'     => 'string',
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

	/**
	 * @throws wppaCreatePostException
	 */
	public function ping( $request ) {
		try {
			$bot = new Bot( $request['id'] );
		} catch ( wppaLoadPostException $exception ) {
			// Apparently the wrong post ID has been provided which does not belong Bot CPT.
			return new WP_Error(
				'rest_post_invalid_id',
				'Invalid post ID',
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( [ 'online' => $bot->ping(), 'botName' => $bot->getTitle() ] );
	}

	/**
	 * Fetch updates REST API endpoint.
	 */
	public function fetch_updates( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		try {
			$bot = new Bot( $request['id'] );
		} catch ( wppaException $exception ) {
			// Apparently the wrong post ID has been provided which does not belong Bot CPT.
			return new WP_Error(
				'rest_post_invalid_id',
				$exception->getMessage(),
				[ 'status' => 404 ]
			);
		}

		try {
			$result = rest_ensure_response( $bot->fetchUpdates() );
		} catch ( Exception $exception ) {
			$result = new WP_Error(
				'rest_fetch_updates_failed',
				$exception->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return $result;
	}

	public function replace_token( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		try {
			$bot = new Bot( (int) $request['id'] );
		} catch ( wppaException $exception ) {
			return new WP_Error(
				'rest_post_invalid_id',
				'Invalid bot ID.',
				[ 'status' => 404 ]
			);
		}

		try {
			$result = $bot->replaceTokenIfValid( (string) $request['token'] );
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
