<?php

namespace iTRON\cf7Telegram\Controllers\RestApi;

use iTRON\cf7Telegram\Bot;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use WP_REST_Response;
use WP_REST_Server;

class BotController extends Controller{
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/ping',
			[
				'args'   => [
					'id' => [
						'description' => __( 'Unique identifier for the object.' ),
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
	}

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function ping( $request ) {
		$bot = new Bot( $request['id'] );
		return rest_ensure_response( [ 'online' => $bot->ping() ] );
	}

	/**
	 * @TODO
	 *
	 * @param $request
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ): bool {
		return true;
	}

	public function prepare_item_for_response( $post, $request ): WP_REST_Response {
		$response = parent::prepare_item_for_response( $post, $request );

		$base = sprintf( '%s/%s', $this->namespace, $this->rest_base );
		$response->add_link( 'ping', rest_url( trailingslashit( $base ) . $post->ID . '/ping' ) );

		return $response;
	}
}
