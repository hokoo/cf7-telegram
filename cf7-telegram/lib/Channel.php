<?php

namespace iTRON\cf7Telegram;

use iTRON\cf7Telegram\Collections\BotCollection;
use iTRON\cf7Telegram\Collections\ChatCollection;
use iTRON\cf7Telegram\Collections\FormCollection;
use iTRON\cf7Telegram\Exceptions\Telegram;
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
	public Bot $bot;

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
	public function addChat( Chat $chat ): Channel {
		$this->client
			->getChat2ChannelRelation()
			->createConnection( new Query\Connection( $chat->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function removeChat(Chat $chat ): Channel {
		$this->client
			->getChat2ChannelRelation()
			->detachConnections( new Query\Connection( $chat->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws MissingParameters
     * @throws ConnectionWrongData
     * @throws RelationNotFound
     */
	public function addForm( Form $form ): Channel {
		$this->client
			->getForm2ChannelRelation()
			->createConnection( new Query\Connection( $form->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function removeForm(Form $form ): Channel {
		$this->client
			->getForm2ChannelRelation()
			->detachConnections( new Query\Connection( $form->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws MissingParameters
     * @throws ConnectionWrongData
     * @throws RelationNotFound
     */
	public function setBot( Bot $bot ): Channel {
		$this->unsetBot();

		$this->client
			->getBot2ChannelRelation()
			->createConnection( new Query\Connection( $bot->getPost()->ID, $this->getPost()->ID ) );

		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function unsetBot(): Channel {
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
