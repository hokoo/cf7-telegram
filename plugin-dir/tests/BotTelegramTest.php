<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Exceptions\Telegram;
use iTRON\cf7Telegram\Telegram\TelegramDeliveryResult;
use iTRON\cf7Telegram\Telegram\TelegramGateway;

final class BotTelegramTest extends Cf7tg_TestCase {
	private const OLD_TOKEN = '123456789:OLD_TEST_TOKEN';
	private const NEW_TOKEN = '123456789:NEW_TEST_TOKEN';

	public function testInvalidCandidatePreservesTokenIdentityAndRelations(): void {
		$oldGateway = $this->gateway();
		$candidateGateway = $this->gateway();
		$candidateGateway->queue( 'getMe', TelegramDeliveryResult::failure( 401, 401, 'Unauthorized' ) );
		$this->installGateways( [ self::OLD_TOKEN => $oldGateway, self::NEW_TOKEN => $candidateGateway ] );

		$bot = $this->bot( self::OLD_TOKEN, '1001' );
		$this->seedBotRelations( $bot );

		try {
			$bot->replaceTokenIfValid( self::NEW_TOKEN );
			$this->assertTrue( false, 'Expected invalid token exception.' );
		} catch ( Telegram $exception ) {
			$this->assertSame( 401, $exception->getCode() );
		}

		$this->assertSame( self::OLD_TOKEN, $bot->getToken() );
		$this->assertSame( '1001', (string) $bot->getParam( Bot::TELEGRAM_BOT_ID_PARAM ) );
		$this->assertSame( 2, count( $GLOBALS['wp_connection_rows'] ) );
		$logs = json_encode( $GLOBALS['wpdb_inserts'] );
		$this->assertFalse( str_contains( $logs, self::NEW_TOKEN ) );
	}

	public function testSameBotIdentityUpdatesTokenAndPreservesRelations(): void {
		$candidateGateway = $this->gateway();
		$candidateGateway->queue( 'getMe', $this->success( [ 'id' => 1001, 'username' => 'SameBot' ] ) );
		$this->installGateways( [ self::OLD_TOKEN => $this->gateway(), self::NEW_TOKEN => $candidateGateway ] );

		$bot = $this->bot( self::OLD_TOKEN, '1001' );
		$this->seedBotRelations( $bot );
		$result = $bot->replaceTokenIfValid( self::NEW_TOKEN );

		$this->assertSame( self::NEW_TOKEN, $bot->getToken() );
		$this->assertFalse( $result['identityChanged'] );
		$this->assertFalse( $result['relationsReset'] );
		$this->assertSame( 2, count( $GLOBALS['wp_connection_rows'] ) );
		$this->assertSame( 'SameBot', $bot->getTitle() );
	}

	public function testDifferentKnownBotIdentityResetsOwnedRelationsAfterCommit(): void {
		$candidateGateway = $this->gateway();
		$candidateGateway->queue( 'getMe', $this->success( [ 'id' => 2002, 'username' => 'OtherBot' ] ) );
		$this->installGateways( [ self::OLD_TOKEN => $this->gateway(), self::NEW_TOKEN => $candidateGateway ] );

		$bot = $this->bot( self::OLD_TOKEN, '1001' );
		$this->seedBotRelations( $bot );
		$result = $bot->replaceTokenIfValid( self::NEW_TOKEN );

		$this->assertSame( self::NEW_TOKEN, $bot->getToken() );
		$this->assertTrue( $result['identityChanged'] );
		$this->assertTrue( $result['relationsReset'] );
		$this->assertSame( 0, count( $GLOBALS['wp_connection_rows'] ) );
	}

	public function testPingPersistsTelegramIdentity(): void {
		$gateway = $this->gateway();
		$gateway->queue( 'getMe', $this->success( [ 'id' => 3003, 'username' => 'PingBot' ] ) );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN );

		$this->assertTrue( $bot->ping() );
		$this->assertSame( '3003', (string) $bot->getParam( Bot::TELEGRAM_BOT_ID_PARAM ) );
		$this->assertSame( 'PingBot', $bot->getTitle() );
	}

	public function testActiveWebhookPreventsGetUpdates(): void {
		$gateway = $this->gateway();
		$gateway->queue( 'getWebhookInfo', $this->success( [ 'url' => 'https://secret.example/hook', 'pending_update_count' => 2 ] ) );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN, '1001' );

		$result = $bot->fetchUpdates();

		$this->assertTrue( $result->hasWebhookConflict );
		$this->assertSame( true, $result->webhookInfo['urlSet'] );
		$this->assertSame( [ 'getWebhookInfo' ], array_column( $gateway->calls, 'method' ) );
		$this->assertFalse( str_contains( json_encode( $GLOBALS['wpdb_inserts'] ), 'secret.example' ) );
	}

	public function testGroupCommandForCurrentBotCreatesChatAndAdvancesOffset(): void {
		$gateway = $this->gatewayWithUpdates( [
			$this->update( 10, '/cf7tg_start@TestBot', '-100700001' ),
		] );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN, '1001', 'TestBot' );

		$result = $bot->fetchUpdates();

		$this->assertTrue( $result->hasNewChats );
		$this->assertTrue( $result->hasNewConnections );
		$this->assertSame( 10, $bot->getLastUpdateID() );
		$this->assertSame( 1, count( get_posts( [ 'post_type' => Client::CPT_CHAT, 'post_status' => 'any', 'posts_per_page' => -1 ] ) ) );
	}

	public function testCommandForAnotherBotIsConsumedWithoutCreatingChat(): void {
		$gateway = $this->gatewayWithUpdates( [ $this->update( 11, '/cf7tg_start@OtherBot', '700001' ) ] );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN, '1001', 'TestBot' );

		$result = $bot->fetchUpdates();

		$this->assertFalse( $result->hasNewChats );
		$this->assertSame( 11, $bot->getLastUpdateID() );
		$this->assertSame( 0, count( get_posts( [ 'post_type' => Client::CPT_CHAT, 'post_status' => 'any', 'posts_per_page' => -1 ] ) ) );
	}

	public function testFailedApplicableUpdateStopsBeforeItAndLaterUpdates(): void {
		$gateway = $this->gatewayWithUpdates( [
			[ 'update_id' => 10 ],
			$this->update( 11, '/cf7tg_start@TestBot', '' ),
			$this->update( 12, '/cf7tg_start@TestBot', '700002' ),
		] );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN, '1001', 'TestBot' );

		$result = $bot->fetchUpdates();

		$this->assertSame( 10, $bot->getLastUpdateID() );
		$this->assertSame( 1, count( $result->errors ) );
		$this->assertSame( 0, count( get_posts( [ 'post_type' => Client::CPT_CHAT, 'post_status' => 'any', 'posts_per_page' => -1 ] ) ) );
	}

	public function testParseFailureRetriesOnceAsPlaintextUsingTelegramErrorCode(): void {
		$gateway = $this->gateway();
		$gateway->queue( 'sendMessage', TelegramDeliveryResult::failure( 200, 400, "Bad Request: can't parse entities" ) );
		$gateway->queue( 'sendMessage', $this->success( [ 'message_id' => 1 ] ) );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN, '1001' );
		$chat = new Chat();
		$chat->setChatID( '700001' )->publish();

		$results = $bot->sendMessage( $chat, '*Hello*', 'Markdown', false );

		$this->assertSame( 2, count( $gateway->calls ) );
		$this->assertArrayHasKey( 'parse_mode', $gateway->calls[0]['args'] );
		$this->assertArrayNotHasKey( 'parse_mode', $gateway->calls[1]['args'] );
		$this->assertSame( 'Hello', $gateway->calls[1]['args']['text'] );
		$this->assertTrue( $results[0]->ok );
	}

	public function testRecipientFailureDoesNotStopLaterDeliveryAndLogsNoTelegramPii(): void {
		$gateway = $this->gateway();
		$gateway->queue( 'sendMessage', TelegramDeliveryResult::failure( 200, 400, 'Bad Request: chat not found' ) );
		$gateway->queue( 'sendMessage', $this->success( [ 'message_id' => 2 ] ) );
		$this->installGateways( [ self::OLD_TOKEN => $gateway ] );
		$bot = $this->bot( self::OLD_TOKEN, '1001' );

		$channel = new Channel();
		$channel->setTitle( 'Internal channel' )->publish();
		$bot->connectChannel( $channel );

		$firstChat = new Chat();
		$firstChat->setChatID( '700001' )->setTitle( 'Private Alice' )->publish();
		$secondChat = new Chat();
		$secondChat->setChatID( '700002' )->setTitle( 'Private Bob' )->publish();
		$channel->connectChat( $firstChat );
		$channel->connectChat( $secondChat );

		$legacyActionCount = 0;
		add_filter(
			'wpcf7tg_sendMessage',
			static function ( array $args ): array {
				$args['disable_notification'] = true;
				return $args;
			}
		);
		add_action(
			'wpcf7tg_message_sent',
			static function () use ( &$legacyActionCount ): void {
				$legacyActionCount++;
			}
		);

		$deliveries = $channel->doSendOut( 'Hello', '', new stdClass() );

		$this->assertSame( 2, count( $deliveries ) );
		$this->assertFalse( $deliveries[0]['results'][0]->ok );
		$this->assertTrue( $deliveries[1]['results'][0]->ok );
		$this->assertSame( 2, count( $gateway->calls ) );
		$this->assertSame( 2, $legacyActionCount );
		$this->assertSame( true, $gateway->calls[1]['args']['disable_notification'] );

		$logs = json_encode( $GLOBALS['wpdb_inserts'] );
		$this->assertFalse( str_contains( $logs, '700001' ) );
		$this->assertFalse( str_contains( $logs, 'Private Alice' ) );
		$this->assertFalse( str_contains( $logs, self::OLD_TOKEN ) );
	}

	private function bot( string $token, string $identity = '', string $title = 'TestBot' ): Bot {
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

	private function installGateways( array $gateways ): void {
		add_filter(
			'cf7tg_telegram_gateway',
			static fn( TelegramGateway $default, string $token ): TelegramGateway => $gateways[ $token ] ?? $default,
			10,
			2
		);
	}

	private function seedBotRelations( Bot $bot ): void {
		$GLOBALS['wp_connection_rows'] = [
			(object) [ 'ID' => 1, 'relation' => Client::BOT2CHAT, 'from' => $bot->getPost()->ID, 'to' => 101, 'order' => 0, 'title' => '' ],
			(object) [ 'ID' => 2, 'relation' => Client::BOT2CHANNEL, 'from' => $bot->getPost()->ID, 'to' => 201, 'order' => 0, 'title' => '' ],
		];
	}

	private function gatewayWithUpdates( array $updates ): Cf7tg_BotTelegramGateway {
		$gateway = $this->gateway();
		$gateway->queue( 'getWebhookInfo', $this->success( [ 'url' => '' ] ) );
		$gateway->queue( 'getUpdates', $this->success( $updates ) );
		return $gateway;
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

	private function gateway(): Cf7tg_BotTelegramGateway {
		return new Cf7tg_BotTelegramGateway();
	}
}

final class Cf7tg_BotTelegramGateway implements TelegramGateway {
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
