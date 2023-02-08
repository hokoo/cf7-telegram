<?php

namespace iTRON\cf7Telegram;

use iTRON\cf7Telegram\Exceptions\Telegram;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Query;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;
use iTRON\wpPostAble\Exceptions\wppaSavePostException;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\User;

class Bot extends Entity implements wpPostAble{
	use WPPostAbleTrait;

	protected Api $api;

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $bot_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( Client::CPT_BOT, $bot_id );

		$this->initAPI();
	}

	private function initAPI() {
		if ( is_null( $this->getToken() ) ) return;

		try {
			$this->api = new Api( $this->getToken() );
		} catch ( TelegramSDKException $e ) {
			$this->logger->write( $e->getMessage(), 'Bot initialization error.', Logger::LEVEL_CRITICAL );
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
		$this->initAPI();
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
     * @throws RelationNotFound
     */
	public function connectChannel( Channel $channel ): Entity {
		$channel->setBot( $this );
		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectChannel(Channel $channel = null ): Entity {
		$channelID = isset ( $channel ) ? $channel->getPost()->ID : null;
		$this->client
			->getBot2ChannelRelation()
			->detachConnections( new Query\Connection( $this->getPost()->ID, $channelID ) );

		return $this;
	}

	/**
	 * @throws Telegram
	 */
	public function sendMessage( string $chat_id, string $message, string $mode ) {
		try {
			$this->api->sendMessage( [
				'chat_id'                  => $chat_id,
				'text'                     => $message,
				'parse_mode'               => $mode,
				'disable_web_page_preview' => true,
			] );
		} catch ( TelegramSDKException $e ) {
			$this->logger->write( $e->getMessage(), 'An error has occurred during sending message' );
			throw new Telegram( $e->getMessage(), $e->getCode(), $e );
		}
	}

	// @TODO Temporary method
	public function getAPI(): Api {
		return $this->api;
	}

	/**
	 * Checks whether itself is online.
	 */
	public function ping(): bool {
		try {
			$res = $this->api->getMe();
		} catch ( TelegramSDKException $e ){
			$this->logger->write(
				[
					'botTitle'          => $this->getTitle(),
					'wpPostID'          => $this->getPost()->ID,
					'botTokenFirst13'   => substr( $this->getToken(), 0, 13 ),
				],
				'Bot is unreachable'
			);

			return false;
		}

		return true;
	}
}
