<?php

namespace iTRON\cf7Telegram\Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class TelegramDeliveryResult implements \JsonSerializable {
	public const ERROR_TRANSPORT = 'transport';
	public const ERROR_HTTP = 'http';
	public const ERROR_TELEGRAM = 'telegram';
	public const ERROR_MALFORMED_RESPONSE = 'malformed_response';

	public bool $ok;
	public int $status;
	public int $errorCode;
	public string $description;
	public ?int $retryAfter;
	public string $errorType;
	public mixed $result = null;

	public function __construct( bool $ok, int $status = 0, int $errorCode = 0, string $description = '', ?int $retryAfter = null, string $errorType = '' ) {
		$this->ok          = $ok;
		$this->status      = $status;
		$this->errorCode   = $errorCode;
		$this->description = TelegramRedactor::text( $description );
		$this->retryAfter  = $retryAfter;
		$this->errorType   = $errorType;
	}

	public static function success( int $status = 200 ): self {
		return new self( true, $status );
	}

	public static function failure( int $status, int $errorCode, string $description, ?int $retryAfter = null, string $errorType = self::ERROR_TELEGRAM ): self {
		return new self( false, $status, $errorCode, $description, $retryAfter, $errorType );
	}

	public function jsonSerialize(): array {
		return [
			'ok'          => $this->ok,
			'status'      => $this->status,
			'errorCode'   => $this->errorCode,
			'description' => $this->description,
			'retryAfter'  => $this->retryAfter,
			'errorType'   => $this->errorType,
			'result'      => $this->result,
		];
	}
}
