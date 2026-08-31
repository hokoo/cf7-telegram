<?php

namespace iTRON\cf7Telegram\Controllers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Settings;

class RestApi {

	public static function init() {
		add_action( 'rest_api_init', [ self::class, 'registerFields' ] );
	}

	public static function registerFields(): void {
		register_rest_field( Client::CPT_BOT, 'token', array(
			'get_callback' => function( $object ) {
				$bot = new Bot( $object['id'] );
				return $bot->isTokenEmpty() ? Bot::getEmptyToken() : substr( (string) $bot->getToken(), -4 );
			},
			'update_callback' => function( $updatedValue, $wp_post, $field, $request, $cpt ) {
				try {
					$bot = new Bot( (int) $wp_post->ID );
					$bot->replaceTokenIfValid( sanitize_text_field( (string) $updatedValue ) );
				} catch ( \iTRON\cf7Telegram\Exceptions\Telegram $exception ) {
					return new \WP_Error(
						'rest_bot_token_invalid',
						$exception->getMessage(),
						[ 'status' => 400 ]
					);
				} catch ( \Throwable $exception ) {
					return new \WP_Error(
						'rest_bot_token_update_failed',
						'Bot token could not be updated.',
						[ 'status' => 500 ]
					);
				}
				return true;
			},
			'schema' => array(
				'description' => 'Masked bot token, or replacement token when updating.',
				'type'        => 'string',
				'arg_options' => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
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
				try {
					$chat = new Chat( (int) $wp_post->ID );
					$chat->setChatID( sanitize_text_field( (string) $updatedValue ) );
					$chat->savePost();
				} catch ( \Throwable $exception ) {
					return new \WP_Error(
						'rest_chat_id_update_failed',
						'Chat ID could not be updated.',
						[ 'status' => 500 ]
					);
				}
				return true;
			},
			'schema' => array(
				'description' => 'Telegram chat ID.',
				'type'        => 'string',
				'arg_options' => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}
}
