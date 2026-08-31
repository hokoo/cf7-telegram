<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Logger;

final class LoggerTest extends Cf7tg_TestCase {
	public function testWriteRedactsSensitiveValuesBeforeHookAndDatabaseInsert(): void {
		$token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi';
		$urlEncodedToken = '123456789%3AABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi';
		$base64Token = base64_encode( $token );
		$chatID = '-1001234567890';
		$email = 'person@example.test';
		$phone = '+1 415 555 2671';
		$authorization = 'Bearer raw-authorization-secret';
		$password = 'hunter2';
		$hookPayload = null;

		add_action(
			'logger',
			static function ( array $payload ) use ( &$hookPayload ): void {
				$hookPayload = $payload;
			}
		);

		$logger = $this->loggerWithoutTableInstall();
		$logger->write(
			[
				'token'         => $token,
				'Authorization' => $authorization,
				'password'      => $password,
				'nested'        => [
					'chat_id' => $chatID,
					'email'   => $email,
					'phone'   => $phone,
					'message' => implode(
						' ',
						[
							'token=' . $token,
							'encoded=' . $urlEncodedToken,
							'base64=' . $base64Token,
							'email=' . $email,
							'phone=' . $phone,
							'chat_id=' . $chatID,
							'password=' . $password,
							'diagnostic=kept',
						]
					),
				],
				'status'        => 'diagnostic=kept',
			],
			'token=' . $token . ' email=' . $email,
			Logger::LEVEL_WARNING
		);

		$this->assertSame( $hookPayload, $GLOBALS['wpdb_inserts'][0]['data'] );

		$logged = json_encode( [ $hookPayload, $GLOBALS['wpdb_inserts'][0]['data'] ] );
		foreach ( [ $token, $urlEncodedToken, $base64Token, $chatID, $email, $phone, $authorization, $password ] as $rawValue ) {
			$this->assertFalse( str_contains( $logged, $rawValue ), 'Raw sensitive value leaked into logger payload: ' . $rawValue );
		}

		$this->assertTrue( str_contains( $logged, '[redacted]' ) );
		$this->assertTrue( str_contains( $logged, 'diagnostic=kept' ) );
	}

	public function testSensitiveKeyFilterAddsKeysWithoutDisablingDefaults(): void {
		add_filter(
			'cf7tg/logSensitiveKeys',
			static fn(): array => [ 'customcredential' ]
		);

		$logger = $this->loggerWithoutTableInstall();
		$logger->write(
			[
				'token'             => 'plain-token-value',
				'customCredential'  => 'custom-secret-value',
				'nonSensitiveValue' => 'diagnostic=kept',
			]
		);

		$logged = json_encode( $GLOBALS['wpdb_inserts'][0]['data'] );

		$this->assertFalse( str_contains( $logged, 'plain-token-value' ) );
		$this->assertFalse( str_contains( $logged, 'custom-secret-value' ) );
		$this->assertTrue( str_contains( $logged, 'diagnostic=kept' ) );
	}

	private function loggerWithoutTableInstall(): Logger {
		$GLOBALS['wpdb']->tables[] = 'cf7tg_log';
		$GLOBALS['wpdb']->cf7tg_log = 'wp_cf7tg_log';

		$reflection = new ReflectionClass( Logger::class );

		return $reflection->newInstanceWithoutConstructor();
	}
}
