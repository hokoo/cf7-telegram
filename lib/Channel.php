<?php

namespace iTRON\cf7Telegram;

use iTRON\CF7TG\wpConnectionsClient;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Query;
use iTRON\wpConnections\Relation;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use OutOfBoundsException;

class Channel extends Entity implements wpPostAble{
	use wpPostAbleTrait;

	/**
	 * @var ChatCollection
	 */
	public $chats;

	/**
	 * @var FormCollection
	 */
	public $forms;

	/**
	 * Telegram Bot
	 * @var Bot
	 */
	public $bot;


	/**
	 * @throws wppaCreatePostException
	 * @throws wppaLoadPostException
	 */
	public function __construct( int $post_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( 'cf7tg_channel', $post_id );
		$this->load();
	}

	public function __wakeup() {
		$this->chats = null;
		$this->forms = null;
	}

	/**
	 * Loads and initiates all Channel data from WP post.
	 */
	protected function load(){}

	public function getChats(): ChatCollection {
		if ( isset( $this->chats ) ) {
			return $this->chats;
		}

		$wpConnections = $this->connectionsClient
			->getChat2ChannelRelation()
			->findConnections( new Query\Connection( null, $this->post->ID ) );

		$this->chats = new ChatCollection();
		return $this->chats->fromConnections( $wpConnections );
	}

	public function getForms(): FormCollection {
		if ( isset( $this->forms ) ) {
			return $this->forms;
		}

		$wpConnections = $this->connectionsClient
			->getForm2ChannelRelation()
			->findConnections( new Query\Connection( null, $this->post->ID ) );

		$this->forms = new FormCollection();
		return $this->forms->fromConnections( $wpConnections );
	}

	public function getBot() {
		if ( isset( $this->bot ) ) {
			return $this->bot;
		}

		$wpConnections = $this->connectionsClient
			->getBot2ChannelRelation()
			->findConnections( new Query\Connection( null, $this->post->ID ) );

		$bot = new FormCollection();

		try {
			$this->bot = $bot->fromConnections( $wpConnections )->first();
		} catch ( OutOfBoundsException $e ) {
			$this->bot = null;
		}

		return $this->bot;
	}

	/**
	 * @throws MissingParameters
	 * @throws ConnectionWrongData
	 */
	public function addChat( Chat $chat ): Channel {
		$this->connectionsClient
			->getChat2ChannelRelation()
			->createConnection( new Query\Connection( $chat->post->ID, $this->post->ID ) );

		return $this;
	}

	public function removeChat( Chat $chat ): Channel {
		$this->connectionsClient
			->getChat2ChannelRelation()
			->detachConnections( new Query\Connection( $chat->post->ID, $this->post->ID ) );

		return $this;
	}

	/**
	 * @throws MissingParameters
	 * @throws ConnectionWrongData
	 */
	public function addForm( Form $form ): Channel {
		$this->connectionsClient
			->getForm2ChannelRelation()
			->createConnection( new Query\Connection( $form->post->ID, $this->post->ID ) );

		return $this;
	}

	public function removeForm( Form $form ): Channel {
		$this->connectionsClient
			->getForm2ChannelRelation()
			->detachConnections( new Query\Connection( $form->post->ID, $this->post->ID ) );

		return $this;
	}

	/**
	 * @throws MissingParameters
	 * @throws ConnectionWrongData
	 */
	public function setBot( Bot $bot ): Channel {
		$this->unsetBot();

		$this->connectionsClient
			->getBot2ChannelRelation()
			->createConnection( new Query\Connection( $bot->post->ID, $this->post->ID ) );

		return $this;
	}

	public function unsetBot(): Channel {
		if ( $this->getBot() ) {
			$query = new Query\Connection();
			$query->set( 'from', $this->getBot()->post->ID );
			$query->set( 'to', $this->post->ID );
			$this->connectionsClient->getBot2ChannelRelation()->detachConnections( $query );
		}

		return $this;
	}
}
