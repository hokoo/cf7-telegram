<?php

namespace iTRON\cf7Telegram\Controllers\RestApi;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Util;
use iTRON\wpPostAble\Exceptions\wppaException;
use Throwable;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Response;
use WP_REST_Server;

class ChatController extends Controller {
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/name',
			[
				'args' => [
					'id'   => self::id_arg_schema( 'Unique identifier for the chat.' ),
					'bot_id' => self::id_arg_schema( 'Bot that owns this chat row.' ),
					'name' => [
						'description'       => 'Replacement chat display name.',
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => [ self::class, 'sanitize_chat_name' ],
						'validate_callback' => [ self::class, 'validate_chat_name' ],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'rename' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)' . '/restore_name',
			[
				'args' => [
					'id'     => self::id_arg_schema( 'Unique identifier for the chat.' ),
					'bot_id' => self::id_arg_schema( 'Bot used to refresh the Telegram chat.' ),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'restore_name' ],
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

	public static function sanitize_chat_name( $value ): string {
		return Util::sanitizeTelegramText( $value );
	}

	public static function validate_chat_name( $value ): bool {
		return is_scalar( $value ) && '' !== Util::sanitizeTelegramText( $value );
	}

	public function rename( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		$chat = $this->loadChatFromRequest( $request );
		if ( $chat instanceof WP_Error ) {
			return $chat;
		}

		$bot = $this->loadBotFromRequest( $request );
		if ( $bot instanceof WP_Error ) {
			return $bot;
		}

		$nameAvailability = $this->checkNameAvailability( $chat, $bot );
		if ( $nameAvailability instanceof WP_Error ) {
			return $nameAvailability;
		}

		try {
			$chat->setCustomName( (string) $this->getRequestParam( $request, 'name' ) );
			$chat->savePost();
		} catch ( Throwable $exception ) {
			return $this->error( 'rest_chat_name_update_failed', 'Chat name could not be updated.', 500 );
		}

		return $this->chatResponse( $chat, $request );
	}

	public function restore_name( $request ): WP_Error|WP_REST_Response|WP_HTTP_Response {
		$chat = $this->loadChatFromRequest( $request );
		if ( $chat instanceof WP_Error ) {
			return $chat;
		}

		$bot = $this->loadBotFromRequest( $request );
		if ( $bot instanceof WP_Error ) {
			return $bot;
		}

		$nameAvailability = $this->checkNameAvailability( $chat, $bot );
		if ( $nameAvailability instanceof WP_Error ) {
			return $nameAvailability;
		}

		try {
			$result = $bot->getAPI()->getChat( $chat->getChatID() );

			if ( ! $result->ok || ! is_array( $result->result ) ) {
				return $this->error( 'rest_chat_name_restore_failed', 'Telegram chat could not be loaded.', 502 );
			}

			$chat->setTelegramData( $result->result );
			$chat->restoreTelegramName();
			$chat->savePost();
		} catch ( Throwable $exception ) {
			return $this->error( 'rest_chat_name_restore_failed', 'Telegram chat could not be loaded.', 502 );
		}

		return $this->chatResponse( $chat, $request );
	}

	public function prepare_item_for_response( $post, $request ): WP_REST_Response {
		$response = parent::prepare_item_for_response( $post, $request );

		try {
			$chat = new Chat( (int) $post->ID );
			$data = $response->get_data();
			$data['customName'] = $chat->getCustomName();
			$data['telegramName'] = $chat->getTelegramName();
			$response->set_data( $data );

			$base = sprintf( '%s/%s', $this->namespace, $this->rest_base );
			$response->add_link( 'rename', rest_url( trailingslashit( $base ) . $post->ID . '/name' ) );
			$response->add_link( 'restore_name', rest_url( trailingslashit( $base ) . $post->ID . '/restore_name' ) );
		} catch ( Throwable $exception ) {
			return $response;
		}

		return $response;
	}

	private static function id_arg_schema( string $description ): array {
		return [
			'description'       => $description,
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => [ self::class, 'sanitize_positive_id' ],
			'validate_callback' => [ self::class, 'validate_positive_id' ],
		];
	}

	private function loadChatFromRequest( $request ): Chat|WP_Error {
		$id = $this->getRequestParam( $request, 'id' );

		if ( ! self::validate_positive_id( $id ) ) {
			return $this->error( 'rest_invalid_param', 'Invalid chat ID.', 400 );
		}

		try {
			return new Chat( self::sanitize_positive_id( $id ) );
		} catch ( wppaException $exception ) {
			return $this->error( 'rest_post_invalid_id', 'Invalid chat ID.', 404 );
		}
	}

	private function loadBotFromRequest( $request ): Bot|WP_Error {
		$id = $this->getRequestParam( $request, 'bot_id' );

		if ( ! self::validate_positive_id( $id ) ) {
			return $this->error( 'rest_invalid_param', 'Invalid bot ID.', 400 );
		}

		try {
			return new Bot( self::sanitize_positive_id( $id ) );
		} catch ( wppaException $exception ) {
			return $this->error( 'rest_post_invalid_id', 'Invalid bot ID.', 404 );
		}
	}

	private function checkNameAvailability( Chat $chat, Bot $bot ): ?WP_Error {
		try {
			if ( Chat::STATUS_PENDING === $chat->getBotConnectionStatus( $bot ) ) {
				return $this->error( 'rest_chat_name_pending', 'Pending chats cannot be renamed.', 409 );
			}
		} catch ( Throwable $exception ) {
			return $this->error( 'rest_chat_connection_not_found', 'Chat is not connected to this bot.', 404 );
		}

		return null;
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

	private function chatResponse( Chat $chat, $request ): WP_REST_Response {
		return $this->prepare_item_for_response( $chat->getPost(), $request );
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			[ 'status' => $status ]
		);
	}
}
