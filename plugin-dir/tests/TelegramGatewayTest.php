<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Telegram\TelegramDeliveryResult;
use iTRON\cf7Telegram\Telegram\TelegramGateway;
use iTRON\cf7Telegram\Telegram\WordPressTelegramGateway;

final class TelegramGatewayTest extends Cf7tg_TestCase {
	public function testWordPressGatewaySendsJsonAndPreservesSuccessPayload(): void {
		$GLOBALS['wp_remote_post_handler'] = static function (): array {
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'ok'     => true,
						'result' => [
							'id'       => 123,
							'username' => 'cf7_test_bot',
						],
					]
				),
			];
		};

		$result = ( new WordPressTelegramGateway( '123456789:TEST_SECRET_TOKEN_VALUE' ) )->sendMessage(
			[
				'chat_id' => '700001',
				'text'    => 'Hello',
			]
		);

		$this->assertTrue( $result->ok );
		$this->assertSame( 200, $result->status );
		$this->assertSame( [ 'id' => 123, 'username' => 'cf7_test_bot' ], $result->result );
		$this->assertSame( 'sendMessage', basename( $GLOBALS['wp_remote_post_requests'][0]['url'] ) );
		$this->assertSame( '{"chat_id":"700001","text":"Hello"}', $GLOBALS['wp_remote_post_requests'][0]['args']['body'] );
	}

	public function testWordPressGatewayNormalizesWpErrorWithoutLeakingToken(): void {
		$token = '123456789:TEST_SECRET_TOKEN_VALUE';
		$GLOBALS['wp_remote_post_handler'] = static function ( string $url ): WP_Error {
			return new WP_Error( 'http_request_failed', 'Could not connect to ' . $url );
		};

		$result = ( new WordPressTelegramGateway( $token ) )->getMe();

		$this->assertFalse( $result->ok );
		$this->assertSame( TelegramDeliveryResult::ERROR_TRANSPORT, $result->errorType );
		$this->assertSame( 0, $result->status );
		$this->assertFalse( str_contains( $result->description, $token ) );
		$this->assertFalse( str_contains( $result->description, rawurlencode( $token ) ) );
		$this->assertTrue( str_contains( $result->description, '[telegram-token]' ) );
	}

	public function testWordPressGatewayNormalizesThrownTransportException(): void {
		$token = '123456789:TEST_SECRET_TOKEN_VALUE';
		$GLOBALS['wp_remote_post_handler'] = static function () use ( $token ): void {
			throw new RuntimeException( 'Socket failure for ' . $token );
		};

		$result = ( new WordPressTelegramGateway( $token ) )->getWebhookInfo();

		$this->assertFalse( $result->ok );
		$this->assertSame( TelegramDeliveryResult::ERROR_TRANSPORT, $result->errorType );
		$this->assertFalse( str_contains( $result->description, $token ) );
	}

	public function testWordPressGatewayLoadsChatByTelegramChatId(): void {
		$GLOBALS['wp_remote_post_handler'] = static fn(): array => [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'ok'     => true,
					'result' => [
						'id'    => 700001,
						'title' => 'Current chat',
					],
				]
			),
		];

		$result = ( new WordPressTelegramGateway( '123456789:TEST_SECRET_TOKEN_VALUE' ) )->getChat( '700001' );

		$this->assertTrue( $result->ok );
		$this->assertSame( 'getChat', basename( $GLOBALS['wp_remote_post_requests'][0]['url'] ) );
		$this->assertSame( '{"chat_id":"700001"}', $GLOBALS['wp_remote_post_requests'][0]['args']['body'] );
		$this->assertSame( 'Current chat', $result->result['title'] );
	}

	public function testWordPressGatewayNormalizesMalformedJson(): void {
		$GLOBALS['wp_remote_post_handler'] = static fn(): array => [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => '<html>bad gateway</html>',
		];

		$result = ( new WordPressTelegramGateway( '123456789:TEST_SECRET_TOKEN_VALUE' ) )->getUpdates();

		$this->assertFalse( $result->ok );
		$this->assertSame( TelegramDeliveryResult::ERROR_MALFORMED_RESPONSE, $result->errorType );
		$this->assertSame( 200, $result->status );
	}

	public function testWordPressGatewayNormalizesNon2xxHttpResponsesAndRetryAfterHeader(): void {
		$GLOBALS['wp_remote_post_handler'] = static fn(): array => [
			'response' => [ 'code' => 429 ],
			'headers'  => [ 'retry-after' => '12' ],
			'body'     => wp_json_encode(
				[
					'ok'          => false,
					'error_code'  => 429,
					'description' => 'Too Many Requests',
				]
			),
		];

		$result = ( new WordPressTelegramGateway( '123456789:TEST_SECRET_TOKEN_VALUE' ) )->getUpdates();

		$this->assertFalse( $result->ok );
		$this->assertSame( TelegramDeliveryResult::ERROR_HTTP, $result->errorType );
		$this->assertSame( 429, $result->status );
		$this->assertSame( 429, $result->errorCode );
		$this->assertSame( 12, $result->retryAfter );
	}

	public function testWordPressGatewayNormalizesTelegramOkFalseAndRetryAfterBody(): void {
		$GLOBALS['wp_remote_post_handler'] = static fn(): array => [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'ok'          => false,
					'error_code'  => 400,
					'description' => 'Bad Request: chat not found',
					'parameters'  => [
						'retry_after' => 4,
					],
				]
			),
		];

		$result = ( new WordPressTelegramGateway( '123456789:TEST_SECRET_TOKEN_VALUE' ) )->sendMessage( [ 'chat_id' => '1' ] );

		$this->assertFalse( $result->ok );
		$this->assertSame( TelegramDeliveryResult::ERROR_TELEGRAM, $result->errorType );
		$this->assertSame( 200, $result->status );
		$this->assertSame( 400, $result->errorCode );
		$this->assertSame( 4, $result->retryAfter );
	}

	public function testRecordingFakeGatewayPreservesOrderedCallsAndQueuedResults(): void {
		$gateway = new Cf7tg_RecordingTelegramGateway();
		$gateway->queue( 'getMe', $this->successResult( [ 'username' => 'cf7_test_bot' ] ) );
		$gateway->queue( 'sendMessage', TelegramDeliveryResult::failure( 400, 400, 'Bad Request' ) );

		$getMe = $gateway->getMe();
		$send = $gateway->sendMessage( [ 'chat_id' => '700001', 'text' => 'Hello' ] );

		$this->assertTrue( $getMe->ok );
		$this->assertFalse( $send->ok );
		$this->assertSame( 'getMe', $gateway->calls[0]['method'] );
		$this->assertSame( 'sendMessage', $gateway->calls[1]['method'] );
		$this->assertSame( [ 'chat_id' => '700001', 'text' => 'Hello' ], $gateway->calls[1]['args'] );
	}

	private function successResult( $result ): TelegramDeliveryResult {
		$response = TelegramDeliveryResult::success();
		$response->result = $result;

		return $response;
	}
}

final class Cf7tg_RecordingTelegramGateway implements TelegramGateway {
	public array $calls = [];
	private array $queues = [];

	public function queue( string $method, TelegramDeliveryResult $result ): void {
		$this->queues[ $method ][] = $result;
	}

	public function sendMessage( array $params ): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => 'sendMessage', 'args' => $params ];
		return $this->next( 'sendMessage' );
	}

	public function getMe(): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => 'getMe', 'args' => [] ];
		return $this->next( 'getMe' );
	}

	public function getChat( string $chatID ): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => 'getChat', 'args' => [ 'chat_id' => $chatID ] ];
		return $this->next( 'getChat' );
	}

	public function getUpdates( array $params = [] ): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => 'getUpdates', 'args' => $params ];
		return $this->next( 'getUpdates' );
	}

	public function getWebhookInfo(): TelegramDeliveryResult {
		$this->calls[] = [ 'method' => 'getWebhookInfo', 'args' => [] ];
		return $this->next( 'getWebhookInfo' );
	}

	private function next( string $method ): TelegramDeliveryResult {
		return array_shift( $this->queues[ $method ] ) ?? TelegramDeliveryResult::success();
	}
}
