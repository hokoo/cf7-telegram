<?php

namespace iTRON\cf7Telegram;

use iTRON\wpConnections\Query;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
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

		$this->wpPostAble( Client::CPT_CHAT, $chat_id );
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

	/**
	 * @throws ConnectionWrongData
	 * @throws MissingParameters
	 */
	public function connectChannel( Channel $channel ): Entity {
		$channel->addChat( $this );
		return $this;
	}

	public function disconnectChannel( Channel $channel = null ): Entity {
		$channelID = isset ( $channel ) ? $channel->getPost()->ID : null;
		$this->client
			->getChat2ChannelRelation()
			->detachConnections( new Query\Connection( $this->getPost()->ID, $channelID ) );

		return $this;
	}
}
