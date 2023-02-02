<?php

namespace iTRON\cf7Telegram;

abstract class Entity {
	/**
	 * @var Client $client
	 */
	protected $client;

	/**
	 * @var Logger $logger
	 */
	protected $logger;

	public function __construct() {
		$this->client = Client::getInstance();
		$this->logger = new Logger();
	}

	abstract protected function connectChannel( Channel $channel ): Entity;

	abstract protected function disconnectChannel( Channel $channel = null ): Entity;
}
