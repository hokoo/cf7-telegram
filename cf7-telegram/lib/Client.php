<?php

namespace iTRON\cf7Telegram;

use iTRON\cf7Telegram\Collections\ChannelCollection;
use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Controllers\RestApi;
use iTRON\wpConnections;
use WP_Query;
use Exception;

class Client {
	/**
	 * @var Client
	 */
	private static $instance;

	/**
	 * @var wpConnections\Client;
	 */
	private static $connectionsClient;

	/**
	 * @var ChannelCollection $channels
	 */
	private $channels;

	/**
	 * @var Logger $logger
	 */
	private $logger;

    const WPCONNECTIONS_CLIENT = 'cf7-telegram';
	const CPT_CHAT = 'cf7tg_chat';
	const CPT_BOT = 'cf7tg_bot';
	const CPT_CHANNEL = 'cf7tg_channel';
	const CPT_CF7FORM = 'wpcf7_contact_form';
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
        trigger_error( 'Deserializing of iTRON\cf7Telegram\Client() instance is prohibited.', E_USER_NOTICE );
    }

	public static function getInstance(): Client {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init() {
		$this->logger = new Logger();

		$this->registerConnectionsClient();
		CPT::init();
		RestApi::init();

		add_action( 'wpcf7_before_send_mail', [ CF7::class, 'handleSubscribe' ], 99999, 3 );
        add_action( 'admin_enqueue_scripts', function() {
            wp_enqueue_script( 'wp-api' );
        } );

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
			$this->logger->write( $e->getMessage(), 'Can not register the relations.', Logger::LEVEL_CRITICAL );
		}
	}

	public function getChannels(): ChannelCollection {
		if ( ! isset( $this->channels ) ) {
			$q = new WP_Query( [
				'post_type'     => self::CPT_CHANNEL,
				'fields'        => 'ids',
				'posts_per_pge' => -1,
			] );

			$this->channels = new ChannelCollection();
			$this->channels->createByIDs( $q->posts );
		}

		return $this->channels;
	}

	public function getConnectionsClient(): wpConnections\Client {
		if ( empty( self::$connectionsClient ) ) {
			self::$connectionsClient = new wpConnections\Client( self::WPCONNECTIONS_CLIENT );
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
