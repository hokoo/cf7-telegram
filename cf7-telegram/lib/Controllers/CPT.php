<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Client;

class CPT {
	public static function register() {
		register_post_type(Client::CPT_BOT, [
			'labels' => [
				'name'  => 'Bots'
			],
//			'public' => true,
			'public' => false,
//			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
			'rest_controller_class' => '\iTRON\cf7Telegram\RestApiControllers\Bot',
		]);

		register_post_type(Client::CPT_CHAT, [
			'labels' => [
				'name'  => 'Сhats'
			],
			'public' => false,
//			'public' => false,
//			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
		]);

		register_post_type(Client::CPT_CHANNEL, [
			'labels' => [
				'name'  => 'Channels'
			],
			'public' => true,
//			'public' => false,
//			'show_in_menu' => false,
			'publicly_queryable' => true,
			'show_in_rest' => true,
		]);
	}
}
