<?php

namespace iTRON\cf7Telegram;

abstract class Entity {
	/**
	 * @var Client $connectionsClient
	 */
	protected $connectionsClient;

	/**
	 * @var Logger $logger
	 */
	protected $logger;

	public function __construct() {
		$this->connectionsClient = Client::getInstance()->getConnectionsClient();
		$this->logger = new Logger();
	}

	abstract protected function connectChannel( Channel $channel ): Entity;

	abstract protected function disconnectChannel( Channel $channel = null ): Entity;
}
