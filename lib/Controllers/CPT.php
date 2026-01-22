<?php

namespace iTRON\cf7Telegram\Controllers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\RestApi\BotController;
use iTRON\cf7Telegram\Controllers\RestApi\ChannelController;
use iTRON\cf7Telegram\Controllers\RestApi\ChatController;
use WPCF7_ContactForm;

class CPT {

	private static ?self $instance = null;

	// Available after init:10
	public array $cf7_orig_capabilities = [];
	public string $cf7_orig_capability_type = '';

	private function __construct() {}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_filter( 'register_post_type_args', [ $this, 'obtain_orig_capabilities' ], 10, 2 );
		add_action( 'init', [ $this, 'register' ], 20 );
	}

	public function register(): void {
		register_post_type( Client::CPT_BOT, [
			'labels' => [ 'name' => 'Bots' ],
			'public' => false,
			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
			'capabilities' => $this->cf7_orig_capabilities,
			'capability_type' => $this->cf7_orig_capability_type,
			'rest_controller_class' => BotController::class,
		] );

		register_post_type( Client::CPT_CHAT, [
			'labels' => [ 'name' => 'Chats' ],
			'public' => false,
			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
			'capabilities' => $this->cf7_orig_capabilities,
			'capability_type' => $this->cf7_orig_capability_type,
			'rest_controller_class' => ChatController::class,
		] );

		register_post_type( Client::CPT_CHANNEL, [
			'labels' => [ 'name' => 'Channels' ],
			'public' => false,
			'show_in_menu' => false,
			'publicly_queryable' => false,
			'show_in_rest' => true,
			'capabilities' => $this->cf7_orig_capabilities,
			'capability_type' => $this->cf7_orig_capability_type,
			'rest_controller_class' => ChannelController::class,
		] );
	}

	public function obtain_orig_capabilities( array $args, string $post_type ): array {
		if ( WPCF7_ContactForm::post_type === $post_type ) {
			$this->cf7_orig_capabilities    = (array) $args['capabilities'];
			$this->cf7_orig_capability_type = $args['capability_type'];
		}

		return $args;
	}
}
