<?php

namespace iTRON\cf7Telegram;

use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;

class Chat extends Entity implements WPPostAble{
	use WPPostAbleTrait;

	const META_CHANNEL_ID = 'cf7tg_channel_id';

	public $username;

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $chat_id = 0 ) {
		$this->wpPostAble( 'cf7tg_chat', $chat_id );
	}
}
