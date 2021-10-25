<?php

namespace iTRON\cf7Telegram;

use iTRON\wpPostAble\Exceptions\wppaSavePostException;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;

class Chat extends Entity implements WPPostAble{
	use WPPostAbleTrait;

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $chat_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( 'cf7tg_chat', $chat_id );
	}

	/**
	 * @throws wppaSavePostException
	 */
	public function setChatID( string $chatID ): Chat {
		$this->setParam( 'chatID', trim( $chatID ) );
		$this->savePost();
		return $this;
	}

	public function getChatID() {
		return $this->getParam( 'chatID' );
	}
}
