<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class RestBotUpdates {
	public bool $hasNewChats = false;
	public bool $hasNewConnections = false;
}
