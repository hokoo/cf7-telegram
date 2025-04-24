<?php

namespace iTRON\cf7Telegram\Exceptions;

use iTRON\cf7Telegram\Exceptions\Exception;

class BotApiNotInitialized extends Exception {
	public function __construct() {
		parent::__construct( 'Bot API not initialized' );
	}
}
