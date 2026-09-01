<?php

namespace iTRON\cf7Telegram\Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

interface TelegramGateway {
	public function sendMessage( array $params ): TelegramDeliveryResult;

	public function getMe(): TelegramDeliveryResult;

	public function getChat( string $chatID ): TelegramDeliveryResult;

	public function getUpdates( array $params = [] ): TelegramDeliveryResult;

	public function getWebhookInfo(): TelegramDeliveryResult;
}
