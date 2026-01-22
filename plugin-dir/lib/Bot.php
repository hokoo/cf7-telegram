<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Collections\ChannelCollection;
use iTRON\cf7Telegram\Collections\ChatCollection;
use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Exceptions\BotApiNotInitialized;
use iTRON\cf7Telegram\Exceptions\Telegram;
use iTRON\cf7Telegram\Traits\PropertyInitializationChecker;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
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
	const TOKEN_CONST_MASK = 'WPFC7TG_BOT_TOKEN__%d';
	const EMPTY_TOKEN_MASK = '[%s]'; /** @see isTokenEmpty() method */

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

	public static function getEmptyToken(): string {
		$loc = get_locale();
		return sprintf( self::EMPTY_TOKEN_MASK, _x( 'empty', 'Empty token field', 'cf7-telegram' ) );
	}

	/**
	 * Checks whether the token is empty.
	 * Uses trimming to remove the leading and trailing brackets as a way to determine if the token is empty.
	 * Works if the localization language had been changed.
	 *
	 * @return bool
	 */
	public function isTokenEmpty(): bool {
		return
			empty ( $this->getToken() ) ||

			/** @see self::EMPTY_TOKEN_MASK */
			$this->getToken() !== rtrim( ltrim( $this->getToken(), '[' ), ']' );
	}

	public function getTokenConstName(): string {
		return sprintf( self::TOKEN_CONST_MASK, $this->getPost()->ID );
	}

	/**
	 * Checks whether the token is defined by the constant.
	 *
	 * @return bool
	 */
	public function isTokenDefined(): bool {
		return defined( $this->getTokenConstName() );
	}

	public function getToken() {
		return $this->isTokenDefined() ? constant( $this->getTokenConstName() ) : $this->getParam( 'token' );
	}

	/**
	 * @throws wppaSavePostException
	 */
	public function setToken( string $token ): self {
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
	public function setLastUpdateID( int $updateID ): self {
		$this->setParam( 'lastUpdateID', $updateID );
		$this->savePost();
		return $this;
	}

	public function getLastStatus() {
		return $this->getParam( 'lastStatus' );
	}

	public function setBotStatus( string $status ): self {
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
	public function connectChannel( Channel $channel ): self {
		$channel->connectBot( $this );
		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectChannel(Channel $channel = null ): self {
		$channelID = $channel?->getPost()->ID;
		$this->client
			->getBot2ChannelRelation()
			->detachConnections( new Query\Connection( $this->getPost()->ID, $channelID ) );

		return $this;
	}

	/**
	 * @throws RelationNotFound
	 */
	public function getChannels(): ChannelCollection {
		$connections = $this->client->getBot2ChannelRelation()->findConnections( new Query\Connection( $this->getPost()->ID ) );

		return ( new ChannelCollection() )->createByConnections( $connections, 'to' );
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
		return $this->chats->createByConnections( $wpConnections, 'to' );
	}

	public function connectChat( Chat $chat ): Connection|null {
		try {
			$connection = $this->client
				->getBot2ChatRelation()
				->createConnection( new Query\Connection( $this->getPost()->ID, $chat->getPost()->ID ) );
		} catch ( Exception $e ) {
			$this->logger->write( $e->getMessage(), 'Can not connect the chat.', Logger::LEVEL_CRITICAL );
			return null;
		}

		return $connection;
	}

	/**
	 * @throws RelationNotFound
	 */
	public function disconnectChat( Chat $chat ): self {
		$chatID = $chat->getPost()->ID;
		$this->client
			->getBot2ChatRelation()
			->detachConnections( new Query\Connection( $this->getPost()->ID, $chatID ) );

		// Disconnect the chat from all channels of the bot.
		foreach ( $this->getChannels()->getIterator() as $channel ) {
			/** @var Channel $channel */
			if ( ! $channel->hasChat( $chat ) ) {
				continue;
			}

			$channel->disconnectChat( $chat );
		}
		return $this;
	}

	/**
	 * @throws RelationNotFound
	 */
	public function hasChat( Chat $chat ): bool {
		return $this->getChats()->contains( $chat );
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
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
			$this->setTitle( $res->get( 'username' ) );
			$this->savePost();
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
	 * @return RestBotUpdates
	 *
	 * @throws Telegram
	 * @throws wppaSavePostException|BotApiNotInitialized
	 */
	public function fetchUpdates(): RestBotUpdates {
		$result = new RestBotUpdates();

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
			return $result;
		}

		/**
		 * When a new chat is found as an update, it should be immediately connected to the bot.
		 * In case the chat is not exists, it should be created and connected.
		 * In case the chat is already exists but not connected, it should be connected.
		 *
		 * During the process, all channels of the bot should be connected to the chat if not yet.
		 * When the chat is being connected to the channel, status 'pending' should be set to the connection.
		 *
		 * In case the chat is already connected, it should be ignored.
		 */

		try {
			foreach ( $updates as $update ) {
				$message = $update->getMessage();

				if ( $message->isEmpty() || ! $message->hasCommand() ) {
					continue;
				}

				if ( '/' . CF7::CMD !== trim( $message->get( 'text' ) ) ) {
					continue;
				}

				$tgChatID = $update->getChat()->get( 'id' );
				$chat     = Util::getChatByTelegramID( $tgChatID );

				if ( ! $chat ) {
					$chat                = Util::createChat( $update->getChat() );
					$result->hasNewChats = true;
				}

				$wpChatID = $chat->getPost()->ID ?? null;

				if ( ! $this->getChats()->contains( $chat ) ) {
					$this->connectChat( $chat );
					$chat->setPending( $this );
					$chat->setDate( $update->message->date );
					$chat->savePost();

					$result->hasNewConnections = true;
				}
			}
		} catch ( \Error|\Exception $e ) {
			$this->logger->write(
				[
					'botTitle'        => $this->getTitle(),
					'wpPostID'        => $this->getPost()->ID,
					'botTokenFirst13' => substr( $this->getToken(), 0, 13 ),
					'tgChatID'        => $tgChatID ?? '',
					'wpChatID'        => $wpChatID ?? '',
					'error'           => $e->getMessage(),
				],
				'Bot has failed to fetch updates'
			);

			throw new Telegram( 'Failed to process updates' );
		}

		$updateID = max( array_column( $updates, 'update_id' ) );
		$this->setLastUpdateID( max( $updateID, $this->getLastUpdateID() ) );

		return $result;
	}
}
