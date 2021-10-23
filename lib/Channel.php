<?php

namespace iTRON\cf7Telegram;

use iTRON\CF7TG\wpConnectionsClient;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Query;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use OutOfBoundsException;

class Channel implements wpPostAble{
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

		$wpConnections = wpConnectionsClient::getChat2ChannelRelation()->findConnections( new Query\Connection( null, $this->post->ID ) );
		$this->chats = new ChatCollection();
		return $this->chats->fromConnections( $wpConnections );
	}

	public function getForms(): FormCollection {
		if ( isset( $this->forms ) ) {
			return $this->forms;
		}

		$wpConnections = wpConnectionsClient::getForm2ChannelRelation()->findConnections( new Query\Connection( null, $this->post->ID ) );
		$this->forms = new FormCollection();
		return $this->forms->fromConnections( $wpConnections );
	}

	public function getBot() {
		if ( isset( $this->bot ) ) {
			return $this->bot;
		}

		$wpConnections = wpConnectionsClient::getBot2ChannelRelation()->findConnections( new Query\Connection( null, $this->post->ID ) );
		$bot = new FormCollection();

		try {
			$this->bot = $bot->fromConnections( $wpConnections )->first();
		} catch ( OutOfBoundsException $e ) {
			$this->bot = null;
		}

		return $this->bot;
	}
}
