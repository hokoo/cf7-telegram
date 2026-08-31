<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\RestApi as RestApiFields;
use iTRON\cf7Telegram\Controllers\RestApi\BotController;
use iTRON\cf7Telegram\Telegram\TelegramDeliveryResult;
use iTRON\cf7Telegram\Telegram\TelegramGateway;

final class RestBotControllerTest extends Cf7tg_TestCase {
	private const TOKEN = '123456789:REST_TEST_TOKEN';
	private const NEW_TOKEN = '123456789:REST_NEW_TOKEN';

	public function testRegistersPostCanonicalAndDeprecatedGetCompatibilityRoutes(): void {
		$controller = new BotController( Client::CPT_BOT );
		$controller->register_routes();

		$pingRoute = 'wp/v2/cf7tg_bot/(?P<id>[\d]+)/ping';
		$fetchRoute = 'wp/v2/cf7tg_bot/(?P<id>[\d]+)/fetch_updates';
		$tokenRoute = 'wp/v2/cf7tg_bot/(?P<id>[\d]+)/token';

		$this->assertArrayHasKey( $pingRoute, $GLOBALS['wp_rest_routes'] );
		$this->assertArrayHasKey( $fetchRoute, $GLOBALS['wp_rest_routes'] );
		$this->assertArrayHasKey( $tokenRoute, $GLOBALS['wp_rest_routes'] );

		$pingArgs = $GLOBALS['wp_rest_routes'][ $pingRoute ]['args'];
		$this->assertSame( WP_REST_Server::CREATABLE, $pingArgs[0]['methods'] );
		$this->assertSame( 'ping', $pingArgs[0]['callback'][1] );
		$this->assertSame( 'update_item_permissions_check', $pingArgs[0]['permission_callback'][1] );
		$this->assertSame( WP_REST_Server::READABLE, $pingArgs[1]['methods'] );
		$this->assertSame( 'deprecated_ping', $pingArgs[1]['callback'][1] );
		$this->assertSame( 'update_item_permissions_check', $pingArgs[1]['permission_callback'][1] );
		$this->assertSame( true, $pingArgs['args']['id']['required'] );
		$this->assertSame( [ BotController::class, 'sanitize_positive_id' ], $pingArgs['args']['id']['sanitize_callback'] );
		$this->assertSame( [ BotController::class, 'validate_positive_id' ], $pingArgs['args']['id']['validate_callback'] );
		$this->assertTrue( BotController::validate_positive_id( '1' ) );
		$this->assertFalse( BotController::validate_positive_id( '-1' ) );

		$fetchArgs = $GLOBALS['wp_rest_routes'][ $fetchRoute ]['args'];
		$this->assertSame( WP_REST_Server::CREATABLE, $fetchArgs[0]['methods'] );
		$this->assertSame( 'fetch_updates', $fetchArgs[0]['callback'][1] );
		$this->assertSame( WP_REST_Server::READABLE, $fetchArgs[1]['methods'] );
		$this->assertSame( 'deprecated_fetch_updates', $fetchArgs[1]['callback'][1] );

		$tokenArgs = $GLOBALS['wp_rest_routes'][ $tokenRoute ]['args'];
		$this->assertSame( WP_REST_Server::CREATABLE, $tokenArgs[0]['methods'] );
		$this->assertSame( 'replace_token', $tokenArgs[0]['callback'][1] );
		$this->assertSame( 'update_item_permissions_check', $tokenArgs[0]['permission_callback'][1] );
		$this->assertSame( true, $tokenArgs['args']['id']['required'] );
		$this->assertSame( true, $tokenArgs['args']['token']['required'] );
		$this->assertSame( [ BotController::class, 'validate_token' ], $tokenArgs['args']['token']['validate_callback'] );
	}

	public function testPostPingReturnsResponseShapeAndPersistsBotIdentity(): void {
		$gateway = new Cf7tg_RestBotControllerGateway();
		$gateway->queue( 'getMe', $this->success( [ 'id' => 3003, 'username' => 'RestPingBot' ] ) );
		$this->installGateway( $gateway, self::TOKEN );
		$bot = $this->bot( self::TOKEN );

		$response = ( new BotController( Client::CPT_BOT ) )->ping( $this->request( 'POST', $bot->getPost()->ID ) );

		$this->assertSame( WP_REST_Response::class, get_class( $response ) );
		$this->assertSame( [ 'online' => true, 'botName' => 'RestPingBot' ], $response->get_data() );
		$this->assertArrayNotHasKey( 'Deprecation', $response->get_headers() );
		$this->assertSame( '3003', (string) ( new Bot( $bot->getPost()->ID ) )->getParam( Bot::TELEGRAM_BOT_ID_PARAM ) );
	}

	public function testDeprecatedGetPingAddsHeadersWithoutChangingBody(): void {
		$gateway = new Cf7tg_RestBotControllerGateway();
		$gateway->queue( 'getMe', $this->success( [ 'id' => 3003, 'username' => 'RestPingBot' ] ) );
		$this->installGateway( $gateway, self::TOKEN );
		$bot = $this->bot( self::TOKEN );

		$response = ( new BotController( Client::CPT_BOT ) )->deprecated_ping( $this->request( 'GET', $bot->getPost()->ID ) );

		$this->assertSame( [ 'online' => true, 'botName' => 'RestPingBot' ], $response->get_data() );
		$this->assertSame( 'true', $response->get_headers()['Deprecation'] );
		$this->assertSame( 'Use POST for this mutating endpoint.', $response->get_headers()['X-CF7TG-Deprecated-Route'] );
	}

	public function testPostFetchUpdatesProcessesUpdates(): void {
		$gateway = new Cf7tg_RestBotControllerGateway();
		$gateway->queue( 'getWebhookInfo', $this->success( [ 'url' => '' ] ) );
		$gateway->queue( 'getUpdates', $this->success( [
			$this->update( 21, '/cf7tg_start@RestFetchBot', '-100700001' ),
		] ) );
		$this->installGateway( $gateway, self::TOKEN );
		$bot = $this->bot( self::TOKEN, '1001', 'RestFetchBot' );

		$response = ( new BotController( Client::CPT_BOT ) )->fetch_updates( $this->request( 'POST', $bot->getPost()->ID ) );
		$data = $response->get_data();

		$this->assertSame( WP_REST_Response::class, get_class( $response ) );
		$this->assertTrue( $data->hasNewChats );
		$this->assertTrue( $data->hasNewConnections );
		$this->assertSame( 21, ( new Bot( $bot->getPost()->ID ) )->getLastUpdateID() );
		$this->assertSame( 1, count( get_posts( [ 'post_type' => Client::CPT_CHAT, 'post_status' => 'any', 'posts_per_page' => -1 ] ) ) );
	}

	public function testFetchUpdatesUnexpectedFailureReturnsSafeError(): void {
		$gateway = new Cf7tg_RestBotControllerGateway();
		$gateway->throwOn( 'getWebhookInfo', new RuntimeException( 'secret ' . self::TOKEN ) );
		$this->installGateway( $gateway, self::TOKEN );
		$bot = $this->bot( self::TOKEN, '1001' );

		$response = ( new BotController( Client::CPT_BOT ) )->fetch_updates( $this->request( 'POST', $bot->getPost()->ID ) );

		$this->assertSame( WP_Error::class, get_class( $response ) );
		$this->assertSame( 'rest_fetch_updates_failed', $response->get_error_code() );
		$this->assertSame( 'Telegram updates could not be checked.', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertFalse( str_contains( $response->get_error_message(), self::TOKEN ) );
	}

	public function testInvalidBotIdReturnsSafeError(): void {
		$controller = new BotController( Client::CPT_BOT );

		$notFound = $controller->ping( $this->request( 'POST', 999 ) );
		$invalid = $controller->fetch_updates( $this->request( 'POST', 0 ) );

		$this->assertSame( WP_Error::class, get_class( $notFound ) );
		$this->assertSame( 'rest_post_invalid_id', $notFound->get_error_code() );
		$this->assertSame( 'Invalid bot ID.', $notFound->get_error_message() );
		$this->assertSame( 404, $notFound->get_error_data()['status'] );
		$this->assertSame( WP_Error::class, get_class( $invalid ) );
		$this->assertSame( 'rest_invalid_param', $invalid->get_error_code() );
		$this->assertSame( 'Invalid bot ID.', $invalid->get_error_message() );
		$this->assertSame( 400, $invalid->get_error_data()['status'] );
	}

	public function testControllerGetItemDoesNotAddDebugFooField(): void {
		$bot = $this->bot( self::TOKEN );
		$response = ( new BotController( Client::CPT_BOT ) )->get_item( $this->request( 'GET', $bot->getPost()->ID ) );

		$this->assertSame( WP_REST_Response::class, get_class( $response ) );
		$this->assertArrayNotHasKey( 'foo', $response->get_data() );
	}

	public function testRestFieldTokenUpdateUnexpectedFailureReturnsSafeError(): void {
		$bot = $this->bot( self::TOKEN, '1001' );
		$gateway = new Cf7tg_RestBotControllerGateway();
		$gateway->throwOn( 'getMe', new RuntimeException( 'secret ' . self::NEW_TOKEN ) );
		$this->installGateway( $gateway, self::NEW_TOKEN );
		RestApiFields::registerFields();

		$callback = $GLOBALS['wp_rest_fields'][ Client::CPT_BOT ]['token']['update_callback'];
		$response = $callback( '<b>' . self::NEW_TOKEN . '</b>', $bot->getPost(), 'token', null, Client::CPT_BOT );

		$this->assertSame( WP_Error::class, get_class( $response ) );
		$this->assertSame( 'rest_bot_token_update_failed', $response->get_error_code() );
		$this->assertSame( 'Bot token could not be updated.', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertFalse( str_contains( $response->get_error_message(), self::NEW_TOKEN ) );
	}

	public function testChatIdRestFieldUpdatePersistsSanitizedValue(): void {
		RestApiFields::registerFields();
		$chat = new Chat();
		$chat->setTitle( 'REST chat' )->publish();

		$callback = $GLOBALS['wp_rest_fields'][ Client::CPT_CHAT ]['chatID']['update_callback'];
		$result = $callback( '  -100 700 <b>001</b>  ', $chat->getPost(), 'chatID', null, Client::CPT_CHAT );

		$this->assertSame( true, $result );
		$this->assertSame( '-100700001', ( new Chat( $chat->getPost()->ID ) )->getChatID() );
	}

	private function request( string $method, int $id ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/wp/v2/cf7tg_bot/' . $id );
		$request->set_param( 'id', $id );
		return $request;
	}

	private function bot( string $token, string $identity = '', string $title = 'RestBot' ): Bot {
		Client::getInstance()->init();
		$bot = new Bot();
		$bot->setToken( $token );
		$bot->setTitle( $title );
		if ( '' !== $identity ) {
			$bot->setParam( Bot::TELEGRAM_BOT_ID_PARAM, $identity );
		}
		$bot->publish();
		return $bot;
	}

	private function installGateway( TelegramGateway $gateway, string $token ): void {
		add_filter(
			'cf7tg_telegram_gateway',
			static fn( TelegramGateway $default, string $candidate ): TelegramGateway => $candidate === $token ? $gateway : $default,
			10,
			2
		);
	}

	private function update( int $id, string $text, string $chatID ): array {
		return [
			'update_id' => $id,
			'message'   => [
				'text'     => $text,
				'date'     => 1700000000,
				'entities' => [ [ 'type' => 'bot_command', 'offset' => 0, 'length' => strlen( $text ) ] ],
				'chat'     => [ 'id' => $chatID, 'type' => str_starts_with( $chatID, '-' ) ? 'supergroup' : 'private', 'title' => 'Private title' ],
			],
		];
	}

	private function success( $payload ): TelegramDeliveryResult {
		$result = TelegramDeliveryResult::success();
		$result->result = $payload;
		return $result;
	}
}

final class Cf7tg_RestBotControllerGateway implements TelegramGateway {
	public array $calls = [];
	private array $queues = [];
	private array $throws = [];

	public function queue( string $method, TelegramDeliveryResult $result ): void {
		$this->queues[ $method ][] = $result;
	}

	public function throwOn( string $method, Throwable $exception ): void {
		$this->throws[ $method ] = $exception;
	}

	public function sendMessage( array $params ): TelegramDeliveryResult {
		return $this->call( 'sendMessage', $params );
	}

	public function getMe(): TelegramDeliveryResult {
		return $this->call( 'getMe' );
	}

	public function getUpdates( array $params = [] ): TelegramDeliveryResult {
		return $this->call( 'getUpdates', $params );
	}

	public function getWebhookInfo(): TelegramDeliveryResult {
		return $this->call( 'getWebhookInfo' );
	}

	private function call( string $method, array $args = [] ): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => $method, 'args' => $args ];

		if ( isset( $this->throws[ $method ] ) ) {
			throw $this->throws[ $method ];
		}

		return array_shift( $this->queues[ $method ] ) ?? TelegramDeliveryResult::success();
	}
}
