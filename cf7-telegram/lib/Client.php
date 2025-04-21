<?php

namespace iTRON\cf7Telegram;

use iTRON\cf7Telegram\Collections\ChannelCollection;
use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Controllers\RestApi;
use iTRON\wpConnections;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Query;
use WP_Query;
use Exception;
use WPCF7_ContactForm;

class Client {
	private static Client $instance;
	private static wpConnections\Client $connectionsClient;
	private ChannelCollection $channels;
	private Logger $logger;

    const WPCONNECTIONS_CLIENT = 'cf7-telegram';
	const CPT_CHAT = 'cf7tg_chat';
	const CPT_BOT = 'cf7tg_bot';
	const CPT_CHANNEL = 'cf7tg_channel';
	const CPT_CF7FORM = 'wpcf7_contact_form';
	const CHAT2CHANNEL = 'chat2channel';
	const FORM2CHANNEL = 'form2channel';
	const BOT2CHANNEL = 'bot2channel';
	const BOT2CHAT = 'bot2chat';

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
	}

	private function registerConnectionsClient() {
		$chat2channel = new Query\Relation();
		$chat2channel
			->set( 'name', self::CHAT2CHANNEL )
			->set( 'from', self::CPT_CHAT )
			->set( 'to', self::CPT_CHANNEL )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		$bot2channel = new Query\Relation();
		$bot2channel
			->set( 'name', self::BOT2CHANNEL )
			->set( 'from', self::CPT_BOT )
			->set( 'to', self::CPT_CHANNEL )
			->set( 'cardinality', '1-m' )
			->set( 'duplicatable', false );

		$form2channel = new Query\Relation();
		$form2channel
			->set( 'name', self::FORM2CHANNEL )
			->set( 'from', 'wpcf7_contact_form' )
			->set( 'to', self::CPT_CHANNEL )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		$bot2chat = new Query\Relation();
		$bot2chat
			->set( 'name', self::BOT2CHAT )
			->set( 'from', self::CPT_BOT )
			->set( 'to', self::CPT_CHAT )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		try {
			$this->getConnectionsClient()->registerRelation( $chat2channel );
			$this->getConnectionsClient()->registerRelation( $bot2channel );
			$this->getConnectionsClient()->registerRelation( $form2channel );
			$this->getConnectionsClient()->registerRelation( $bot2chat );
		} catch ( wpConnections\Exceptions\Exception $e ) {
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

			add_filter( 'wpConnections/client/'. self::WPCONNECTIONS_CLIENT .'/clientDefaultCapabilities',
				function ( $defaultCapability ) {
					$cf_cpt = get_post_type_object( WPCF7_ContactForm::post_type );
					return $cf_cpt->cap->edit_posts ?? $defaultCapability;
				}
			);

			self::$connectionsClient = new wpConnections\Client( self::WPCONNECTIONS_CLIENT );
		}

		return self::$connectionsClient;
	}

    /**
     * @throws RelationNotFound
     */
    public function getBot2ChannelRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::BOT2CHANNEL );
	}

    /**
     * @throws RelationNotFound
     */
    public function getChat2ChannelRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::CHAT2CHANNEL );
	}

    /**
     * @throws RelationNotFound
     */
    public function getForm2ChannelRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::FORM2CHANNEL );
	}

	/**
	 * @throws RelationNotFound
	 */
	public function getBot2ChatRelation(): wpConnections\Relation {
		return $this->getConnectionsClient()->getRelation( self::BOT2CHAT );
	}
}
