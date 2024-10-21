<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;

class RestApi {

	public static function init() {
		add_action( 'rest_api_init', [ self::class, 'registerFields' ] );
	}

	public static function registerFields() {
		register_rest_field( Client::CPT_BOT, 'token', array(
			'get_callback' => function( $object ) {
				$bot = new Bot( $object['id'] );
				return empty( $bot->getToken() ) ? 'empty' : 'set';
			},
			'update_callback' => function( $updatedValue, $wp_post, $field, $request, $cpt ) {
				$chat = new Bot( $wp_post->ID );
				$chat->setToken( $updatedValue );
				return true;
			},
			'schema' => array(
				'description' => __( 'Poop Foo Data.' ),
				'type'        => 'string'
			),
		) );

		register_rest_field( Client::CPT_CHAT, 'chatID', array(
			'get_callback' => function( $object ) {
				$bot = new Chat( $object['id'] );
				return $bot->getChatID();
			},
			'update_callback' => function( $updatedValue, $wp_post, $field, $request, $cpt ) {
				$chat = new Chat( $wp_post->ID );
				$chat->setChatID( $updatedValue );
				return true;
			},
			'schema' => array(
				'description' => __( 'Poop Foo Data.' ),
				'type'        => 'string'
			),
		) );
	}
}
