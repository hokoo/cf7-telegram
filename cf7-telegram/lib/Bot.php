<?php

namespace iTRON\cf7Telegram;

use iTRON\cf7Telegram\Collections\ChatCollection;
use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Exceptions\BotApiNotInitialized;
use iTRON\cf7Telegram\Exceptions\Telegram;
use iTRON\cf7Telegram\Traits\PropertyInitializationChecker;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\Exception;
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
	use PropertyInitializationChecker;

	const STATUS_ONLINE  = 'online';
	const STATUS_OFFLINE = 'offline';

	public ChatCollection $chats;

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
		if ( is_null( $this->getToken() ) ) {
			$this->setBotStatus( self::STATUS_OFFLINE );
			$this->logger->write( 'Bot token is not set.', 'Bot initialization error.', Logger::LEVEL_ATTENTION );
			return;
		}

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

	public function getLastUpdateID(): int {
		return (int) $this->getParam( 'lastUpdateID' );
	}

	/**
	 * @throws wppaSavePostException
	 */
	public function setLastUpdateID( int $updateID ): Bot {
		$this->setParam( 'lastUpdateID', $updateID );
		$this->savePost();
		return $this;
	}

	public function getLastStatus() {
		return $this->getParam( 'lastStatus' );
	}

	public function setBotStatus( string $status ): Bot {
		$this->setParam( 'lastStatus', trim( $status ) );
		try {
			$this->savePost();
		} catch ( wppaSavePostException $e ) {
			$this->logger->write( $e->getMessage(), 'An error has occurred during saving the post' );
		}

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
	 * @throws RelationNotFound
	 */
	public function getChats(): ChatCollection {
		if ( isset( $this->chats ) ) {
			return $this->chats;
		}

		$wpConnections = $this->client
			->getBot2ChatRelation()
			->findConnections( new Query\Connection( $this->getPost()->ID ) );

		$this->chats = new ChatCollection();
		return $this->chats->createByConnections( $wpConnections );
	}


	public function connectChat( Chat $chat ): self {
		try {
			$this->client
				->getBot2ChatRelation()
				->createConnection( new Query\Connection( $this->getPost()->ID, $chat->getPost()->ID ) );
		} catch ( Exception $e ) {
			$this->logger->write( $e->getMessage(), 'Can not connect the chat.', Logger::LEVEL_CRITICAL );
		}

		return $this;
	}

	/**
	 * @throws Telegram
	 */
	public function sendMessage( Chat $chat, string $message, string $mode, bool $throwOnError = true, array $extra = [] ) {
		try {
			$this->getAPI()->sendMessage( [
				'chat_id'                  => $chat->getChatID(),
				'text'                     => $message,
				'parse_mode'               => $mode,
				'disable_web_page_preview' => true,
			] );
		} catch ( TelegramSDKException|BotApiNotInitialized $e ) {
			$this->logger->write(
				[
					'telegramChatID'=> $chat->getChatID(),
					'chatTitle'     => $chat->getTitle(),
					'chatPostID'    => $chat->getPost()->ID,
					'extras'        => $extra,
				],
				$e->getMessage() . " [chatID:{$chat->getChatID()}]",
				Logger::LEVEL_CRITICAL
			);

			if ( $throwOnError ) {
				throw new Telegram( $e->getMessage(), $e->getCode(), $e );
			}
		}
	}

	/**
	 * @throws BotApiNotInitialized
	 */
	public function getAPI(): Api {
		$approach = 0;
		while ( ! $this->isPropertyInitialized( 'api' ) ) {
			if ( ! $approach++ ) {
				$this->initAPI();
			} else {
				throw new BotApiNotInitialized();
			}

		}
		return $this->api;
	}

	/**
	 * Checks whether itself is online.
	 */
	public function ping(): bool {
		try {
			$res = $this->getAPI()->getMe();
		} catch ( TelegramSDKException $e ) {
			$this->setBotStatus( self::STATUS_OFFLINE );
			$this->logger->write(
				[
					'botTitle'          => $this->getTitle(),
					'wpPostID'          => $this->getPost()->ID,
					'botTokenFirst13'   => substr( $this->getToken(), 0, 13 ),
				],
				'Bot is unreachable'
			);

			return false;
		} catch ( BotApiNotInitialized $e ) {
			$this->logger->write(
				[
					'botTitle' => $this->getTitle(),
					'wpPostID' => $this->getPost()->ID,
					'error'    => $e->getMessage(),
				],
				'Bot cannot be pinged'
			);
			return false;
		}

		if ( $res instanceof User ) {
			$this->setBotStatus( self::STATUS_ONLINE );
			return true;
		}

		$this->setBotStatus( self::STATUS_OFFLINE );
		$this->logger->write(
			[
				'botTitle'          => $this->getTitle(),
				'wpPostID'          => $this->getPost()->ID,
				'botTokenFirst13'   => substr( $this->getToken(), 0, 13 ),
				'response'          => $res,
			],
			'Bot is unreachable'
		);

		return false;
	}

	/**
	 * @throws BotApiNotInitialized
	 */
	public function fetchUpdates() {
		try {
			$updates = $this->getAPI()->getUpdates( [
				'offset'  => $this->getLastUpdateID() + 1,
				'limit'   => 10,
				'timeout' => 0,
			] );
		} catch ( TelegramSDKException $e ) {
			$this->logger->write(
				[
					'botTitle'          => $this->getTitle(),
					'wpPostID'          => $this->getPost()->ID,
					'botTokenFirst13'   => substr( $this->getToken(), 0, 13 ),
					'error'             => $e->getMessage(),
				],
				'Bot has failed to fetch updates'
			);
		}

		if ( empty( $updates ) ) {
			return [];
		}

		$upd = [];
		$update_ids = [];

		foreach ( $updates as $update ) {
			$update_ids []= $update->update_id;

			if ( is_array( @ $update->message->entities ) )
				foreach( $update->message->entities as $ent ) :
					$cmd = substr( $update->message->text, $ent->offset, $ent->length );

					$maybeFound = $this->chats->filter( function ( $chat ) use ( $update ) {
						return $chat->getChatID() === $update->message->chat->id;
					} );

					if ( 'bot_command' == $ent->type && '/' . CF7::CMD === $cmd && $maybeFound->isEmpty() ) :
						$chat = new Chat();
						$upd[ $update->message->chat->id ] = ( array ) $update->message->chat;
						$upd[ $update->message->chat->id ]['date'] = $update->message->date;
						$upd[ $update->message->chat->id ]['status'] = 'pending';
					endif;
				endforeach;

			if ( false === strpos( $update->message->text, 'cf7_start' ) ) continue;
		}

//		$this->setLastUpdateID();
		return $updates;
	}
}
