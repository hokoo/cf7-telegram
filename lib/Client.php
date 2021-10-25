<?php

namespace iTRON\cf7Telegram;

use Exception;
use iTRON\wpConnections;

class Client {
	/**
	 * @var Client
	 */
	private static $instance;

	/**
	 * @var wpConnections\Client;
	 */
	private static $connectionsClient;

	const CPT_CHAT = 'cf7tg_chat';
	const CPT_BOT = 'cf7tg_bot';
	const CPT_CHANNEL = 'cf7tg_channel';
	const CHAT2CHANNEL = 'chat2channel';
	const FORM2CHANNEL = 'form2channel';
	const BOT2CHANNEL = 'bot2channel';

	/**
	 * Use get_instance() method for instance creating.
	 */
	protected function __construct() {}

	protected function __clone() {}

	/**
	 * @throws Exception
	 */
	public function __wakeup() {
		throw new Exception("Cannot unserialize the \iTRON\cf7Telegram\Client() instance.");
	}

	public static function getInstance(): Client {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init() {
		add_action( 'init', [ $this, 'registerCPT' ] );

		$this->registerConnectionsClient();
	}

	private function registerCPT() {

		register_post_type(self::CPT_BOT, [
			'labels' => [
				'name'  => 'Bots'
			],
			'public' => true,
//			'public' => false,
//			'show_in_menu' => false,
			'publicly_queryable' => true,
		]);

		register_post_type(self::CPT_CHAT, [
			'labels' => [
				'name'  => 'Сhats'
			],
			'public' => true,
//			'public' => false,
//			'show_in_menu' => false,
			'publicly_queryable' => true,
		]);

		register_post_type(self::CPT_CHANNEL, [
			'labels' => [
				'name'  => 'Channels'
			],
			'public' => true,
//			'public' => false,
//			'show_in_menu' => false,
			'publicly_queryable' => true,
		]);

	}

	private function registerConnectionsClient() {
		$chat2channel = new wpConnections\Query\Relation();
		$chat2channel
			->set( 'name', self::CHAT2CHANNEL )
			->set( 'from', self::CPT_CHAT )
			->set( 'to', self::CPT_CHANNEL )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		$bot2channel = new wpConnections\Query\Relation();
		$bot2channel
			->set( 'name', self::BOT2CHANNEL )
			->set( 'from', self::CPT_BOT )
			->set( 'to', self::CPT_CHANNEL )
			->set( 'cardinality', 'm-1' )
			->set( 'duplicatable', false );

		$form2channel = new wpConnections\Query\Relation();
		$form2channel
			->set( 'name', self::FORM2CHANNEL )
			->set( 'from', 'wpcf7_contact_form' )
			->set( 'to', self::CPT_CHANNEL )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		try {
			$this->getConnectionsClient()->registerRelation( $chat2channel );
			$this->getConnectionsClient()->registerRelation( $bot2channel );
			$this->getConnectionsClient()->registerRelation( $form2channel );
		} catch ( wpConnections\Exceptions\MissingParameters $e ) {
			error_log( "[TELEGRAM] createRelation error: {$e->getMessage()}" );
		}
	}

	public function getConnectionsClient(): wpConnections\Client {
		if ( empty( self::$connectionsClient ) ) {
			self::$connectionsClient = new wpConnections\Client( 'cf7-telegram' );
		}

		return self::$connectionsClient;
	}

	public function getBot2ChannelRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::BOT2CHANNEL );
	}

	public function getChat2ChannelRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::CHAT2CHANNEL );
	}

	public function getForm2ChannelRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::FORM2CHANNEL );
	}
}
