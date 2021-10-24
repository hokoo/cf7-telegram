<?php
namespace iTRON\CF7TG;

use Exception;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Relation;
use iTRON\wpConnections\Query;
use iTRON\wpConnections\Exceptions\MissingParameters;

class wpConnectionsClient {
	const CHAT2CHANNEL = 'chat2channel';
	const FORM2CHANNEL = 'form2channel';
	const BOT2CHANNEL = 'bot2channel';

	/**
	 * @var Client
	 */
	private static $client;

	protected function __construct() {}

	protected function __clone() {}

	/**
	 * @throws Exception
	 */
	public function __wakeup() {
		throw new Exception("Cannot unserialize the wpConnectionsClient() instance.");
	}

	public static function getInstance(): Client {
		if ( isset( self::$client ) ) {
			return self::$client;
		}

		self::$client = new Client( 'cf7-telegram' );

		$chat2channel = new Query\Relation();
		$chat2channel
			->set( 'name', self::CHAT2CHANNEL )
			->set( 'from', 'cf7tg_chat' )
			->set( 'to', 'cf7tg_channel' )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		$bot2channel = new Query\Relation();
		$bot2channel
			->set( 'name', self::BOT2CHANNEL )
			->set( 'from', 'cf7tg_bot' )
			->set( 'to', 'cf7tg_channel' )
			->set( 'cardinality', 'm-1' )
			->set( 'duplicatable', false );

		$form2channel = new Query\Relation();
		$form2channel
			->set( 'name', self::FORM2CHANNEL )
			->set( 'from', 'wpcf7_contact_form' )
			->set ( 'to', 'cf7tg_channel' )
			->set( 'cardinality', 'm-m' )
			->set( 'duplicatable', false );

		try {
			self::$client->registerRelation( $chat2channel );
			self::$client->registerRelation( $bot2channel );
			self::$client->registerRelation( $form2channel );
		} catch ( MissingParameters $e ) {
			error_log( "[TELEGRAM] createRelation error: {$e->getMessage()}" );
		}

		return self::$client;
	}

	public function getBot2ChannelRelation(): Relation {
		return self::$client->getRelation( self::BOT2CHANNEL );
	}

	public function getChat2ChannelRelation(): Relation {
		return self::$client->getRelation( self::CHAT2CHANNEL );
	}

	public function getForm2ChannelRelation(): Relation {
		return self::$client->getRelation( self::FORM2CHANNEL );
	}
}
