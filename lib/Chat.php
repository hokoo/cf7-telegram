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
	public const CUSTOM_NAME_PARAM = 'customName';
	public const TELEGRAM_TITLE_PARAM = 'telegramTitle';

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $chat_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( Client::CPT_CHAT, $chat_id );
	}

	public function setChatID( string $chatID ): Chat {
		$this->setParam( 'chatID', Util::sanitizeTelegramChatID( $chatID ) );
		return $this;
	}

	public function getChatID() {
		return $this->getParam( 'chatID' );
	}

	public function setChatType( string $chatType ): Chat {
		$this->setParam( 'chatType', Util::sanitizeTelegramChatType( $chatType ) );
		return $this;
	}

	public function getChatType() {
		return $this->getParam( 'chatType' );
	}

	public function setUsername( string $username ): Chat {
		$this->setParam( 'username', Util::sanitizeTelegramText( $username ) );
		return $this;
	}

	public function getUsername() {
		return $this->getParam( 'username' );
	}

	public function setFirstName( string $firstName ): Chat {
		$this->setParam( 'firstName', Util::sanitizeTelegramText( $firstName ) );
		return $this;
	}

	public function getFirstName() {
		return $this->getParam( 'firstName' );
	}

	public function setLastName( string $lastName ): Chat {
		$this->setParam( 'lastName', Util::sanitizeTelegramText( $lastName ) );
		return $this;
	}

	public function getLastName() {
		return $this->getParam( 'lastName' );
	}

	public function setTelegramTitle( string $title ): Chat {
		$this->setParam( self::TELEGRAM_TITLE_PARAM, Util::sanitizeTelegramText( $title ) );
		return $this;
	}

	public function getTelegramTitle(): string {
		return Util::sanitizeTelegramText( $this->getParam( self::TELEGRAM_TITLE_PARAM ) );
	}

	public function setCustomName( string $name ): Chat {
		$name = Util::sanitizeTelegramText( $name );
		$this->setParam( self::CUSTOM_NAME_PARAM, $name );
		$this->setTitle( $name );
		return $this;
	}

	public function getCustomName(): string {
		return Util::sanitizeTelegramText( $this->getParam( self::CUSTOM_NAME_PARAM ) );
	}

	public function hasCustomName(): bool {
		return '' !== $this->getCustomName();
	}

	public function getTelegramName(): string {
		$title = $this->getTelegramTitle();
		if ( '' !== $title ) {
			return $title;
		}

		$name = trim( $this->getFirstName() . ' ' . $this->getLastName() );
		if ( '' !== $name ) {
			return $name;
		}

		$username = $this->getUsername();
		if ( '' !== $username ) {
			return '@' . ltrim( $username, '@' );
		}

		return $this->getChatID() ?: __( 'Telegram Chat', 'cf7-telegram' );
	}

	public function restoreTelegramName(): Chat {
		$this->setParam( self::CUSTOM_NAME_PARAM, '' );
		$this->setTitle( $this->getTelegramName() );
		return $this;
	}

	public function setTelegramData( $tg_chat ): Chat {
		$chatID = Util::sanitizeTelegramChatID( Util::telegramValue( $tg_chat, 'id' ) );
		if ( '' !== $chatID ) {
			$this->setChatID( $chatID );
		}

		$this
			->setChatType( Util::telegramValue( $tg_chat, 'type', '' ) )
			->setFirstName( Util::telegramValue( $tg_chat, 'first_name', '' ) )
			->setLastName( Util::telegramValue( $tg_chat, 'last_name', '' ) )
			->setUsername( Util::telegramValue( $tg_chat, 'username', '' ) )
			->setTelegramTitle( Util::telegramValue( $tg_chat, 'title', '' ) );

		if ( ! $this->hasCustomName() ) {
			$this->restoreTelegramName();
		}

		return $this;
	}

	public function getName(): string {
		return trim( $this->getTitle() ?: $this->getTelegramName() );
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
		$statusMeta = $connection->meta->where( 'key', self::STATUS_KEY );
		$meta = $statusMeta->isEmpty() ? null : $statusMeta->first();
		return $meta ? $meta->value : self::STATUS_PENDING;
	}

	/**
	 * Returns an empty status when a bot relation exists but its metadata was not persisted.
	 *
	 * @throws ConnectionNotFound
	 * @throws RelationNotFound
	 */
	public function getBotConnectionStatus( Bot $bot ): string {
		$connection = $this->getBotConnection( $bot );
		$statusMeta = $connection->meta->where( 'key', self::STATUS_KEY );
		$meta = $statusMeta->isEmpty() ? null : $statusMeta->first();
		return $meta ? (string) $meta->value : '';
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
