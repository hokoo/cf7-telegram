<?php

namespace iTRON\cf7Telegram;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Query;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use iTRON\wpPostAble\Exceptions\wppaSavePostException;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;

class Bot extends Entity implements wpPostAble{
	use WPPostAbleTrait;

	/**
	 * @var Telegram $api
	 */
	protected $api;

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $bot_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( Client::CPT_BOT, $bot_id );

		if ( is_null( $this->getToken() ) ) return;

		try {
			$this->api = new Telegram( $this->getToken() );
		} catch ( TelegramException $e ) {
			$this->logger->write( $e->getMessage(), 'Can not authorize the bot.', Logger::LEVEL_CRITICAL );
		}
	}

	public function getToken() {
		return $this->getParam( 'token' );
	}

	/**
	 * @throws wppaSavePostException
	 */
	public function setToken( string $token ): Bot {
		$this->setParam( 'token', trim( $token ) );
		$this->savePost();
		return $this;
	}

	public function getLastStatus() {
		return $this->getParam( 'lastStatus' );
	}

	/**
	 * @throws wppaSavePostException
	 */
	public function setBotStatus( string $status ): Bot {
		$this->setParam( 'token', trim( $status ) );
		$this->savePost();
		return $this;
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws MissingParameters
	 */
	public function connectChannel( Channel $channel ): Entity {
		$channel->setBot( $this );
		return $this;
	}

	public function disconnectChannel( Channel $channel = null ): Entity {
		$channelID = isset ( $channel ) ? $channel->post->ID : null;
		$this->connectionsClient
			->getBot2ChannelRelation()
			->detachConnections( new Query\Connection( $this->post->ID, $channelID ) );

		return $this;
	}
}
