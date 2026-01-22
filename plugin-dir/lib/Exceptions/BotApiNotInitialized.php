<?php

namespace iTRON\cf7Telegram\Exceptions;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class BotApiNotInitialized extends Exception {
	public function __construct() {
		parent::__construct( 'Bot API not initialized' );
	}
}
