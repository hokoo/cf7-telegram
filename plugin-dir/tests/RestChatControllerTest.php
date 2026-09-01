<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\RestApi\ChatController;
use iTRON\cf7Telegram\Telegram\TelegramDeliveryResult;
use iTRON\cf7Telegram\Telegram\TelegramGateway;

final class RestChatControllerTest extends Cf7tg_TestCase {
	private const TOKEN = '123456789:CHAT_RENAME_TOKEN';

	public function testRegistersRenameAndRestoreRoutes(): void {
		$controller = new ChatController( Client::CPT_CHAT );
		$controller->register_routes();

		$nameRoute = 'wp/v2/cf7tg_chat/(?P<id>[\d]+)/name';
		$restoreRoute = 'wp/v2/cf7tg_chat/(?P<id>[\d]+)/restore_name';

		$this->assertArrayHasKey( $nameRoute, $GLOBALS['wp_rest_routes'] );
		$this->assertArrayHasKey( $restoreRoute, $GLOBALS['wp_rest_routes'] );

		$nameArgs = $GLOBALS['wp_rest_routes'][ $nameRoute ]['args'];
		$this->assertSame( WP_REST_Server::CREATABLE, $nameArgs[0]['methods'] );
		$this->assertSame( 'rename', $nameArgs[0]['callback'][1] );
		$this->assertSame( 'update_item_permissions_check', $nameArgs[0]['permission_callback'][1] );
		$this->assertSame( true, $nameArgs['args']['id']['required'] );
		$this->assertSame( true, $nameArgs['args']['name']['required'] );
		$this->assertSame( [ ChatController::class, 'sanitize_chat_name' ], $nameArgs['args']['name']['sanitize_callback'] );
		$this->assertSame( [ ChatController::class, 'validate_chat_name' ], $nameArgs['args']['name']['validate_callback'] );
		$this->assertSame( true, $nameArgs['args']['bot_id']['required'] );
		$this->assertTrue( ChatController::validate_chat_name( 'Support' ) );
		$this->assertFalse( ChatController::validate_chat_name( '   ' ) );

		$restoreArgs = $GLOBALS['wp_rest_routes'][ $restoreRoute ]['args'];
		$this->assertSame( WP_REST_Server::CREATABLE, $restoreArgs[0]['methods'] );
		$this->assertSame( 'restore_name', $restoreArgs[0]['callback'][1] );
		$this->assertSame( true, $restoreArgs['args']['bot_id']['required'] );
	}

	public function testRenameStoresCustomNameAndReturnsChatResponse(): void {
		$bot = $this->bot();
		$chat = $this->chat( [
			'id'         => '700001',
			'type'       => 'private',
			'first_name' => 'Alice',
		] );
		$bot->connectChat( $chat );

		$response = ( new ChatController( Client::CPT_CHAT ) )->rename(
			$this->request( 'POST', $chat->getPost()->ID, [
				'bot_id' => $bot->getPost()->ID,
				'name'   => '  <b>Support Desk</b>  ',
			] )
		);
		$data = $response->get_data();
		$updated = new Chat( $chat->getPost()->ID );

		$this->assertSame( 'Support Desk', $updated->getTitle() );
		$this->assertSame( 'Support Desk', $updated->getCustomName() );
		$this->assertSame( 'Alice', $updated->getTelegramName() );
		$this->assertSame( 'Support Desk', $data['title']['rendered'] );
		$this->assertSame( 'Support Desk', $data['customName'] );
		$this->assertSame( 'Alice', $data['telegramName'] );
	}

	public function testRenameRejectsPendingChat(): void {
		$bot = $this->bot();
		$chat = $this->chat( [
			'id'         => '700001',
			'type'       => 'private',
			'first_name' => 'Alice',
		] );
		$bot->connectChat( $chat );
		$chat->setPending( $bot );

		$response = ( new ChatController( Client::CPT_CHAT ) )->rename(
			$this->request( 'POST', $chat->getPost()->ID, [
				'bot_id' => $bot->getPost()->ID,
				'name'   => 'Support Desk',
			] )
		);

		$this->assertSame( WP_Error::class, get_class( $response ) );
		$this->assertSame( 'rest_chat_name_pending', $response->get_error_code() );
		$this->assertSame( 'Pending chats cannot be renamed.', $response->get_error_message() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( 'Alice', ( new Chat( $chat->getPost()->ID ) )->getTitle() );
	}

	public function testRestoreNameRejectsPendingChatBeforeTelegramRequest(): void {
		$gateway = new Cf7tg_RestChatControllerGateway();
		$this->installGateway( $gateway, self::TOKEN );

		$bot = $this->bot();
		$chat = $this->chat( [
			'id'         => '700001',
			'type'       => 'private',
			'first_name' => 'Alice',
		] );
		$bot->connectChat( $chat );
		$chat->setPending( $bot );

		$response = ( new ChatController( Client::CPT_CHAT ) )->restore_name(
			$this->request( 'POST', $chat->getPost()->ID, [ 'bot_id' => $bot->getPost()->ID ] )
		);

		$this->assertSame( WP_Error::class, get_class( $response ) );
		$this->assertSame( 'rest_chat_name_pending', $response->get_error_code() );
		$this->assertSame( [], $gateway->calls );
	}

	public function testRestoreNameRefreshesCurrentTelegramNameAndClearsCustomName(): void {
		$gateway = new Cf7tg_RestChatControllerGateway();
		$gateway->queue( 'getChat', $this->success( [
			'id'         => '700001',
			'type'       => 'private',
			'first_name' => 'Alice',
			'last_name'  => 'Current',
			'username'   => 'alice_current',
		] ) );
		$this->installGateway( $gateway, self::TOKEN );

		$bot = $this->bot();
		$chat = $this->chat( [
			'id'         => '700001',
			'type'       => 'private',
			'first_name' => 'Alice',
			'last_name'  => 'Old',
		] );
		$bot->connectChat( $chat );
		$chat->setCustomName( 'Manual Label' );
		$chat->savePost();

		$response = ( new ChatController( Client::CPT_CHAT ) )->restore_name(
			$this->request( 'POST', $chat->getPost()->ID, [ 'bot_id' => $bot->getPost()->ID ] )
		);
		$data = $response->get_data();
		$updated = new Chat( $chat->getPost()->ID );

		$this->assertSame( [ 'getChat' ], array_column( $gateway->calls, 'method' ) );
		$this->assertSame( [ 'chat_id' => '700001' ], $gateway->calls[0]['args'] );
		$this->assertSame( '', $updated->getCustomName() );
		$this->assertSame( 'Alice Current', $updated->getTitle() );
		$this->assertSame( 'Alice Current', $updated->getTelegramName() );
		$this->assertSame( 'Alice Current', $data['title']['rendered'] );
		$this->assertSame( '', $data['customName'] );
		$this->assertSame( 'Alice Current', $data['telegramName'] );
	}

	public function testRestoreNameReturnsSafeErrorWhenTelegramChatCannotBeLoaded(): void {
		$gateway = new Cf7tg_RestChatControllerGateway();
		$gateway->queue( 'getChat', TelegramDeliveryResult::failure( 200, 400, 'Bad Request: chat not found' ) );
		$this->installGateway( $gateway, self::TOKEN );

		$bot = $this->bot();
		$chat = $this->chat( [ 'id' => '700001', 'type' => 'private', 'first_name' => 'Alice' ] );
		$bot->connectChat( $chat );

		$response = ( new ChatController( Client::CPT_CHAT ) )->restore_name(
			$this->request( 'POST', $chat->getPost()->ID, [ 'bot_id' => $bot->getPost()->ID ] )
		);

		$this->assertSame( WP_Error::class, get_class( $response ) );
		$this->assertSame( 'rest_chat_name_restore_failed', $response->get_error_code() );
		$this->assertSame( 'Telegram chat could not be loaded.', $response->get_error_message() );
		$this->assertSame( 502, $response->get_error_data()['status'] );
		$this->assertFalse( str_contains( $response->get_error_message(), self::TOKEN ) );
	}

	private function request( string $method, int $id, array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/wp/v2/cf7tg_chat/' . $id );
		$request->set_param( 'id', $id );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	private function bot(): Bot {
		Client::getInstance()->init();
		$bot = new Bot();
		$bot->setToken( self::TOKEN );
		$bot->setTitle( 'ChatRenameBot' );
		$bot->publish();
		return $bot;
	}

	private function chat( array $telegramData ): Chat {
		Client::getInstance()->init();
		$chat = new Chat();
		$chat->setTelegramData( $telegramData );
		$chat->publish();
		return $chat;
	}

	private function installGateway( TelegramGateway $gateway, string $token ): void {
		add_filter(
			'cf7tg_telegram_gateway',
			static fn( TelegramGateway $default, string $candidate ): TelegramGateway => $candidate === $token ? $gateway : $default,
			10,
			2
		);
	}

	private function success( $payload ): TelegramDeliveryResult {
		$result = TelegramDeliveryResult::success();
		$result->result = $payload;
		return $result;
	}
}

final class Cf7tg_RestChatControllerGateway implements TelegramGateway {
	public array $calls = [];
	private array $queues = [];

	public function queue( string $method, TelegramDeliveryResult $result ): void {
		$this->queues[ $method ][] = $result;
	}

	public function sendMessage( array $params ): TelegramDeliveryResult {
		return $this->call( 'sendMessage', $params );
	}

	public function getMe(): TelegramDeliveryResult {
		return $this->call( 'getMe' );
	}

	public function getChat( string $chatID ): TelegramDeliveryResult {
		return $this->call( 'getChat', [ 'chat_id' => $chatID ] );
	}

	public function getUpdates( array $params = [] ): TelegramDeliveryResult {
		return $this->call( 'getUpdates', $params );
	}

	public function getWebhookInfo(): TelegramDeliveryResult {
		return $this->call( 'getWebhookInfo' );
	}

	private function call( string $method, array $args = [] ): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => $method, 'args' => $args ];
		return array_shift( $this->queues[ $method ] ) ?? TelegramDeliveryResult::success();
	}
}
