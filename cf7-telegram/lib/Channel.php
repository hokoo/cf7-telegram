<?php

namespace iTRON\cf7Telegram;

use iTRON\cf7Telegram\Collections\BotCollection;
use iTRON\cf7Telegram\Collections\ChatCollection;
use iTRON\cf7Telegram\Collections\FormCollection;
use iTRON\cf7Telegram\Exceptions\Telegram;
use iTRON\wpConnections\Abstracts\Connection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Query;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use OutOfBoundsException;

class Channel extends Entity implements wpPostAble{
	use wpPostAbleTrait;

	public ChatCollection $chats;
	public FormCollection $forms;
	public ?Bot $bot = null;

	/**
	 * @throws wppaCreatePostException
	 * @throws wppaLoadPostException
	 */
	public function __construct( int $post_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( Client::CPT_CHANNEL, $post_id );
		$this->load();
	}

	/**
	 * Loads and initiates all Channel data from WP post.
	 */
	protected function load(){}

    /**
     * @throws RelationNotFound
     */
    public function getChats(): ChatCollection {
		if ( isset( $this->chats ) ) {
			return $this->chats;
		}

		$wpConnections = $this->client
			->getChat2ChannelRelation()
			->findConnections( new Query\Connection( 0, $this->getPost()->ID ) );

		$this->chats = new ChatCollection();
		return $this->chats->createByConnections( $wpConnections );
	}

    /**
     * @throws RelationNotFound
     */
    public function getForms(): FormCollection {
		if ( isset( $this->forms ) ) {
			return $this->forms;
		}

		$wpConnections = $this->client
			->getForm2ChannelRelation()
			->findConnections( new Query\Connection( 0, $this->getPost()->ID ) );

		$this->forms = new FormCollection();
		return $this->forms->createByConnections( $wpConnections );
	}

    /**
     * @throws RelationNotFound
     */
    public function getBot() {
		if ( isset( $this->bot ) ) {
			return $this->bot;
		}

		$wpConnections = $this->client
			->getBot2ChannelRelation()
			->findConnections( new Query\Connection( 0, $this->getPost()->ID ) );

		$bot = new BotCollection();

		try {
			$this->bot = $bot->createByConnections( $wpConnections )->first();
		} catch ( OutOfBoundsException $e ) {
			$this->bot = null;
		}

		return $this->bot;
	}

    /**
     * @throws MissingParameters
     * @throws ConnectionWrongData
     * @throws RelationNotFound
     */
	public function connectChat( Chat $chat ): Connection {
		return $this->client
			->getChat2ChannelRelation()
			->createConnection( new Query\Connection( $chat->getPost()->ID, $this->getPost()->ID ) );
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectChat(Chat $chat ): Channel {
		$this->client
			->getChat2ChannelRelation()
			->detachConnections( new Query\Connection( $chat->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

	/**
	 * @throws RelationNotFound
	 */
	public function hasChat( Chat $chat ): bool {
		return $this->getChats()->contains( $chat );
	}

    /**
     * @throws MissingParameters
     * @throws ConnectionWrongData
     * @throws RelationNotFound
     */
	public function connectForm( Form $form ): Channel {
		$this->client
			->getForm2ChannelRelation()
			->createConnection( new Query\Connection( $form->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectForm(Form $form ): Channel {
		$this->client
			->getForm2ChannelRelation()
			->detachConnections( new Query\Connection( $form->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * Set connection to a bot.
     * If there is already a bot connected, it will be disconnected and a new connection will be created.
     * It will remove also all connection metadata if there is any.
     *
     * @throws MissingParameters
     * @throws ConnectionWrongData
     * @throws RelationNotFound
     */
	public function connectBot( Bot $bot ): Channel {
		$this->disconnectBot();

		$this->client
			->getBot2ChannelRelation()
			->createConnection( new Query\Connection( $bot->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectBot(): Channel {
		if ( $this->getBot() ) {
			$query = new Query\Connection();
			$query->set( 'from', $this->getBot()->getPost()->ID );
			$query->set( 'to', $this->getPost()->ID );
			$this->client->getBot2ChannelRelation()->detachConnections( $query );
		}

		return $this;
	}

	/**
	 * @throws RelationNotFound
	 */
	public function hasBot( Bot $bot = null ): bool {
		if ( is_null( $this->getBot() ) ) {
			return false;
		}

		if ( is_null( $bot ) ) {
			return true;
		}

		return $this->getBot()->getPost()->ID === $bot->getPost()->ID;
	}

	/**
	 * @throws RelationNotFound
	 * not @throws Telegram exception due to throwOnError is set to false.
	 */
    public function doSendOut(string $message, string $mode ) {
		$chats = $this->getChats();

		if ( $chats->isEmpty() ) {
			return;
		}

		foreach ( $chats as $chat ) {
			/** @var Chat $chat */
			$this->getBot()->sendMessage( $chat->getChatID(), $message, $mode, false, [ $this ] );
		}
	}

	/**
	 * Nothing to release.
	 */
	protected function connectChannel( Channel $channel ): Entity {
		return $this;
	}

	protected function disconnectChannel( Channel $channel = null ): Entity {
		return $this;
	}
}
