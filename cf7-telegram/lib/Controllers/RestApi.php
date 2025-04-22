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
				return $bot->isTokenEmpty() ? Bot::getEmptyToken() : mb_substr( $bot->getToken(), -4 );
			},
			'update_callback' => function( $updatedValue, $wp_post, $field, $request, $cpt ) {
				$chat = new Bot( $wp_post->ID );
				$chat->setToken( $updatedValue );
				return true;
			},
			'schema' => array(
				'description' => '',
				'type'        => 'string'
			),
		) );

		register_rest_field( Client::CPT_BOT, 'isTokenEmpty', array(
			'get_callback' => function( $object ) {
				$bot = new Bot( $object['id'] );
				return $bot->isTokenEmpty();
			},
			'schema' => array(
				'description' => 'Whether the token is empty.',
				'type'        => 'boolean'
			),
		) );

		register_rest_field( Client::CPT_BOT, 'isTokenDefinedByConst', array(
			'get_callback' => function( $object ) {
				$bot = new Bot( $object['id'] );
				return $bot->isTokenDefined();
			},
			'schema' => array(
				'description' => 'Whether the token is defined by PHP constant.',
				'type'        => 'boolean'
			),
		) );

		register_rest_field( Client::CPT_BOT, 'phpConst', array(
			'get_callback' => function( $object ) {
				$bot = new Bot( $object['id'] );
				return $bot->getTokenConstName();
			},
			'schema' => array(
				'description' => 'PHP constant name for the token.',
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
				'description' => '',
				'type'        => 'string'
			),
		) );
	}
}
