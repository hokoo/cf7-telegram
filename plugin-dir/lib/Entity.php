<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

abstract class Entity {
	protected Client $client;

	protected Logger $logger;

	public function __construct() {
		$this->client = Client::getInstance();
		$this->logger = new Logger();
	}

	abstract protected function connectChannel( Channel $channel ): Entity;

	abstract protected function disconnectChannel( Channel $channel = null ): Entity;
}
