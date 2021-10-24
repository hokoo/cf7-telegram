<?php

namespace iTRON\cf7Telegram;

use iTRON\CF7TG\wpConnectionsClient;

abstract class Entity {
	/**
	 * @var wpConnectionsClient $connectionsClient
	 */
	protected $connectionsClient;

	public function __construct() {
		$this->connectionsClient = wpConnectionsClient::getInstance();
	}
}
