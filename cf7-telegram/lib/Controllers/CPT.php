<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\RestApiControllers\BotController;
use iTRON\cf7Telegram\RestApiControllers\CF7FormController;

class CPT {
	public static function init() {
		add_action( 'init', [ self::class, 'register' ], 10 );
		add_filter( 'register_post_type_args', [ self::class, 'hack_cf7_cpt' ], 10, 2 );
	}

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
			'rest_controller_class' => BotController::class,
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

	public static function hack_cf7_cpt( array $args, string $post_type ): array {
		if ( Client::CPT_CF7FORM !== $post_type ) {
			return $args;
		}

		$args['show_in_rest'] = true;
		$args['rest_controller_class'] = CF7FormController::class;

		return $args;
	}
}
