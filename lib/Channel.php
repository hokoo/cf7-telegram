<?php

namespace iTRON\cf7Telegram\Groups;

use iTRON\CF7TG\wpConnectionsClient;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;

class Channel implements wpPostAble{
	use wpPostAbleTrait;

	/**
	 * @var ConnectionCollection
	 */
	public $chats;

	/**
	 * @var ConnectionCollection
	 */
	public $forms;

	/**
	 * Bot token
	 * @var string
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

	function getChats(): ConnectionCollection {
		return $this->chats ?? $this->chats = wpConnectionsClient::getChat2ChannelRelation()->findConnections();
	}

	function getForms(): ConnectionCollection {
		return $this->forms ?? $this->forms = wpConnectionsClient::getForm2ChannelRelation()->findConnections();
	}
}
