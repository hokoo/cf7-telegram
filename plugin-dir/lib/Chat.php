<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\Query;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;

class Chat extends Entity implements WPPostAble{
	use WPPostAbleTrait;

	public const STATUS_KEY = 'status';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_PENDING = 'pending';
	public const STATUS_MUTED = 'muted';

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $chat_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( Client::CPT_CHAT, $chat_id );
	}

	public function setChatID( string $chatID ): Chat {
		$this->setParam( 'chatID', trim( $chatID ) );
		return $this;
	}

	public function getChatID() {
		return $this->getParam( 'chatID' );
	}

	public function setChatType( string $chatType ): Chat {
		$this->setParam( 'chatType', trim( $chatType ) );
		return $this;
	}

	public function getChatType() {
		return $this->getParam( 'chatType' );
	}

	public function setUsername( string $username ): Chat {
		$this->setParam( 'username', trim( $username ) );
		return $this;
	}

	public function getUsername() {
		return $this->getParam( 'username' );
	}

	public function setFirstName( string $firstName ): Chat {
		$this->setParam( 'firstName', trim( $firstName ) );
		return $this;
	}

	public function getFirstName() {
		return $this->getParam( 'firstName' );
	}

	public function setLastName( string $lastName ): Chat {
		$this->setParam( 'lastName', trim( $lastName ) );
		return $this;
	}

	public function getLastName() {
		return $this->getParam( 'lastName' );
	}

	public function getName(): string {
		return trim( $this->getTitle() ?: $this->getFirstName() . ' ' . $this->getLastName() );
	}

	public function isPrivateChat(): bool {
		return 'private' === $this->getChatType();
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws MissingParameters
     * @throws RelationNotFound
     */
	public function connectChannel( Channel $channel ): Entity {
		$channel->connectChat( $this );
		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectChannel(Channel $channel = null ): Entity {
		$channelID = isset ( $channel ) ? $channel->getPost()->ID : null;
		$this->client
			->getChat2ChannelRelation()
			->detachConnections( new Query\Connection( $this->getPost()->ID, $channelID ) );

		return $this;
	}

	public function setDate( string $timestamp ): Chat {
		$this->setParam( 'timestamp_connected', $timestamp );
		return $this;
	}

	public function getDate(): string {
		$timestamp = $this->getParam( 'timestamp_connected' );

		// Return pretty date.
		return gmdate( 'd.m.Y H:i', strtotime( $timestamp ) );
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	public function setPending( Bot $bot ): Chat {
		$this->setBotConnectionStatus( $bot, self::STATUS_PENDING );
		return $this;
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	public function setActivated( Bot $bot ): Chat {
		$this->setBotConnectionStatus( $bot, self::STATUS_ACTIVE );
		return $this;
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	public function setMuted( Bot $bot ): Chat {
		$this->setBotConnectionStatus( $bot, self::STATUS_MUTED );
		return $this;
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	private function setChannelConnectionStatus( Channel $channel, string $status ): Chat {
		$connection = $this->getChannelConnection( $channel );
		$connection->meta->where( 'key', self::STATUS_KEY )->clear();
		$connection->meta->add( new Meta( self::STATUS_KEY, $status ) );
		$connection->update();
		return $this;
	}

	/**
	 * @throws ConnectionNotFound
	 * @throws ConnectionWrongData
	 * @throws RelationNotFound
	 */
	private function setBotConnectionStatus( Bot $bot, string $status ): Chat {
		$connection = $this->getBotConnection( $bot );
		$connection->meta->where( 'key', self::STATUS_KEY )->clear();
		$connection->meta->add( new Meta( self::STATUS_KEY, $status ) );
		$connection->update();
		return $this;
	}

	/**
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	public function getConnectionStatus( Channel $channel ): string {
		$connection = $this->getChannelConnection( $channel );
		$meta = $connection->meta->where( 'key', self::STATUS_KEY )->first();
		return $meta ? $meta->value : self::STATUS_PENDING;
	}

	/**
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	private function getChannelConnection( Channel $channel ): Connection {
		// Check if connection exists.
		$connections = $this->client->getChat2ChannelRelation()->findConnections( new Query\Connection( $this->getPost()->ID, $channel->getPost()->ID ) );

		if ( $connections->isEmpty() ) {
			throw new ConnectionNotFound();
		}

		return $connections->first();
	}

	/**
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	private function getBotConnection( Bot $bot ): Connection {
		// Check if connection exists.
		$connections = $this->client->getBot2ChatRelation()->findConnections( new Query\Connection( $bot->getPost()->ID, $this->getPost()->ID ) );

		if ( $connections->isEmpty() ) {
			throw new ConnectionNotFound();
		}

		return $connections->first();
	}
}
