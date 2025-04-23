<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\RestApi\BotController;
use iTRON\cf7Telegram\Controllers\RestApi\CF7FormController;
use iTRON\cf7Telegram\Controllers\RestApi\ChannelController;
use iTRON\cf7Telegram\Controllers\RestApi\ChatController;
use WPCF7_ContactForm;

class CPT {
	public static function init() {
        // It is crucial to bind the callback at a later point than CF7 has bound.
		add_action( 'init', [ self::class, 'register' ], 20 );
		add_filter( 'register_post_type_args', [ self::class, 'hack_cf7_cpt' ], 10, 2 );
	}

	public static function register() {
        $cf_cpt = get_post_type_object( WPCF7_ContactForm::post_type );

		register_post_type(Client::CPT_BOT, [
			'labels' => [
				'name'  => 'Bots'
			],
			'public' => false,
			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
            'capabilities' => (array) $cf_cpt->cap,
            'capability_type' => $cf_cpt->capability_type,
            'rest_controller_class' => BotController::class,
		]);

		register_post_type(Client::CPT_CHAT, [
			'labels' => [
				'name'  => 'Сhats'
			],
			'public' => false,
			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
            'capabilities' => (array) $cf_cpt->cap,
            'capability_type' => $cf_cpt->capability_type,
            'rest_controller_class' => ChatController::class,
		]);

		register_post_type(Client::CPT_CHANNEL, [
			'labels' => [
				'name'  => 'Channels'
			],
			'public' => false,
			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
            'capabilities' => (array) $cf_cpt->cap,
            'capability_type' => $cf_cpt->capability_type,
            'rest_controller_class' => ChannelController::class,
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
