<?php

namespace iTRON\cf7Telegram\Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Throwable;

class WordPressTelegramGateway implements TelegramGateway {
	private string $token;

	public function __construct( string $token ) {
		$this->token = trim( $token );
	}

	public function sendMessage( array $params ): TelegramDeliveryResult {
		return $this->post( 'sendMessage', $params );
	}

	public function getMe(): TelegramDeliveryResult {
		return $this->post( 'getMe' );
	}

	public function getUpdates( array $params = [] ): TelegramDeliveryResult {
		return $this->post( 'getUpdates', $params );
	}

	public function getWebhookInfo(): TelegramDeliveryResult {
		return $this->post( 'getWebhookInfo' );
	}

	private function post( string $method, array $params = [] ): TelegramDeliveryResult {
		try {
			$response = wp_remote_post(
				sprintf( 'https://api.telegram.org/bot%s/%s', rawurlencode( $this->token ), $method ),
				[
					'timeout'     => 15,
					'redirection' => 0,
					'headers'     => [
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					],
					'body'        => wp_json_encode( $params ),
				]
			);
		} catch ( Throwable $exception ) {
			return TelegramDeliveryResult::failure(
				0,
				0,
				$exception->getMessage(),
				null,
				TelegramDeliveryResult::ERROR_TRANSPORT
			);
		}

		if ( is_wp_error( $response ) ) {
			return TelegramDeliveryResult::failure(
				0,
				0,
				$response->get_error_message(),
				null,
				TelegramDeliveryResult::ERROR_TRANSPORT
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$retryAfter = $this->retryAfter( $response, is_array( $body ) ? $body : [] );

		if ( ! is_array( $body ) ) {
			return TelegramDeliveryResult::failure(
				$status,
				0,
				'Invalid Telegram response.',
				$retryAfter,
				TelegramDeliveryResult::ERROR_MALFORMED_RESPONSE
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return TelegramDeliveryResult::failure(
				$status,
				(int) ( $body['error_code'] ?? 0 ),
				(string) ( $body['description'] ?? 'Telegram HTTP request failed.' ),
				$retryAfter,
				TelegramDeliveryResult::ERROR_HTTP
			);
		}

		if ( ! empty( $body['ok'] ) ) {
			$result = TelegramDeliveryResult::success( $status );
			$result->result = $body['result'] ?? null;
			return $result;
		}

		return TelegramDeliveryResult::failure(
			$status,
			(int) ( $body['error_code'] ?? 0 ),
			(string) ( $body['description'] ?? 'Telegram request failed.' ),
			$retryAfter,
			TelegramDeliveryResult::ERROR_TELEGRAM
		);
	}

	private function retryAfter( array $response, array $body ): ?int {
		$retryAfter = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( is_numeric( $retryAfter ) ) {
			return (int) $retryAfter;
		}

		$parameters = is_array( $body['parameters'] ?? null ) ? $body['parameters'] : [];
		return isset( $parameters['retry_after'] ) ? (int) $parameters['retry_after'] : null;
	}
}
