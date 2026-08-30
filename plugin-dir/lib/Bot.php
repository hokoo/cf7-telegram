<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Collections\ChannelCollection;
use iTRON\cf7Telegram\Collections\ChatCollection;
use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Exceptions\BotApiNotInitialized;
use iTRON\cf7Telegram\Exceptions\Telegram;
use iTRON\cf7Telegram\Telegram\TelegramDeliveryResult;
use iTRON\cf7Telegram\Telegram\TelegramGateway;
use iTRON\cf7Telegram\Telegram\TelegramMessageFormatter;
use iTRON\cf7Telegram\Telegram\TelegramRedactor;
use iTRON\cf7Telegram\Telegram\WordPressTelegramGateway;
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

class Bot extends Entity implements wpPostAble{
	use WPPostAbleTrait;
	use PropertyInitializationChecker;

	const STATUS_ONLINE  = 'online';
	const STATUS_OFFLINE = 'offline';
	const TOKEN_CONST_MASK = 'WPFC7TG_BOT_TOKEN__%d';
	const LEGACY_TOKEN_CONST = 'WPFC7TG_BOT_TOKEN';
	const TOKEN_SOURCE_PARAM = 'tokenSource';
	const TOKEN_SOURCE_LEGACY_CONST = 'legacy_global_constant';
	const TELEGRAM_BOT_ID_PARAM = 'telegramBotID';
	const EMPTY_TOKEN_MASK = '[%s]'; /** @see isTokenEmpty() method */
	public const FETCH_UPDATES_LOCK_KEY_PATTERN = 'cf7tg_fetch_updates_lock_%d';
	public const FETCH_UPDATES_LOCK_PREFIX = 'cf7tg_fetch_updates_lock_';
	public const FETCH_UPDATES_LOCK_TTL = 60;

	public ChatCollection $chats;

	protected TelegramGateway $api;

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
		if ( $this->isPropertyInitialized( 'api' ) ) {
			unset( $this->api );
		}

		$token = $this->getToken();

		if ( ! is_string( $token ) || $this->isTokenEmpty() ) {
			$this->setBotStatus( self::STATUS_OFFLINE );
			$this->logger->write( 'Bot token is not set.', 'Bot initialization error.', Logger::LEVEL_ATTENTION );
			return;
		}

		$this->api = $this->createGateway( $token );
	}

	private function createGateway( string $token ): TelegramGateway {
		$gateway = new WordPressTelegramGateway( $token );

		$filteredGateway = apply_filters( 'cf7tg_telegram_gateway', $gateway, $token, $this );
		return $filteredGateway instanceof TelegramGateway ? $filteredGateway : $gateway;
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
		if ( $this->usesLegacyTokenConstant() ) {
			return self::LEGACY_TOKEN_CONST;
		}

		return sprintf( self::TOKEN_CONST_MASK, $this->getPost()->ID );
	}

	/**
	 * Checks whether the token is defined by the constant.
	 *
	 * @return bool
	 */
	public function isTokenDefined(): bool {
		return defined( $this->getTokenConstName() ) || $this->isLegacyTokenConstantDefined();
	}

	public function getToken() {
		if ( defined( $this->getTokenConstName() ) ) {
			return constant( $this->getTokenConstName() );
		}

		if ( $this->isLegacyTokenConstantDefined() ) {
			return constant( self::LEGACY_TOKEN_CONST );
		}

		return $this->getParam( 'token' );
	}

	public function usesLegacyTokenConstant(): bool {
		return self::TOKEN_SOURCE_LEGACY_CONST === $this->getParam( self::TOKEN_SOURCE_PARAM );
	}

	public function isLegacyTokenConstantDefined(): bool {
		return $this->usesLegacyTokenConstant() && defined( self::LEGACY_TOKEN_CONST );
	}

	/**
	 * Marks this bot as backed by the historical global constant without storing its value.
	 *
	 * @throws wppaSavePostException
	 */
	public function setLegacyTokenConstantSource(): self {
		$this->setParam( self::TOKEN_SOURCE_PARAM, self::TOKEN_SOURCE_LEGACY_CONST );
		$this->savePost();
		$this->initAPI();
		return $this;
	}

	/**
	 * @throws wppaSavePostException
	 */
	public function setToken( string $token ): self {
		$this->setParam( self::TOKEN_SOURCE_PARAM, '' );
		$this->setParam( 'token', trim( $token ) );
		$this->savePost();
		$this->initAPI();
		return $this;
	}

	/**
	 * Saves a new token only after Telegram accepts it.
	 *
	 * @throws Telegram
	 * @throws wppaSavePostException
	 */
	public function replaceTokenIfValid( string $token ): array {
		$token = trim( $token );

		if ( '' === $token ) {
			throw new Telegram( 'Bot token is empty.' );
		}

		$result = $this->createGateway( $token )->getMe();

		if ( ! $result->ok ) {
			$this->logger->write(
				TelegramRedactor::data(
					[
						'wpPostID'  => $this->getPost()->ID,
						'token'     => $token,
						'status'    => $result->status,
						'errorCode' => $result->errorCode,
						'errorType' => $result->errorType,
					],
					$token
				),
				'Bot token validation failed',
				Logger::LEVEL_WARNING
			);

			throw new Telegram( $result->description, $result->errorCode );
		}

		$identity = $this->telegramBotIdentity( $result->result );
		if ( '' === $identity ) {
			throw new Telegram( 'Telegram did not return a bot identity.' );
		}

		$previousIdentity = (string) $this->getParam( self::TELEGRAM_BOT_ID_PARAM );
		if ( '' === $previousIdentity && hash_equals( (string) $this->getToken(), $token ) ) {
			$previousIdentity = $identity;
		}

		$identityChanged = '' === $previousIdentity ? null : ! hash_equals( $previousIdentity, $identity );

		$this->setParam( self::TOKEN_SOURCE_PARAM, '' );
		$this->setParam( 'token', $token );
		$this->setParam( self::TELEGRAM_BOT_ID_PARAM, $identity );
		$this->setBotPropertiesFromTelegramUser( is_array( $result->result ) ? $result->result : [] );
		$this->savePost();
		$this->api = $this->createGateway( $token );

		$relationsReset = true === $identityChanged ? $this->disconnectAllRelations() : false;

		return [
			'botID'           => $identity,
			'identityChanged' => $identityChanged,
			'relationsReset'  => $relationsReset,
		];
	}

	private function telegramBotIdentity( $user ): string {
		if ( ! is_array( $user ) || ! isset( $user['id'] ) || ! is_scalar( $user['id'] ) ) {
			return '';
		}

		return trim( (string) $user['id'] );
	}

	private function setBotPropertiesFromTelegramUser( array $user ): void {
		if ( ! empty( $user['username'] ) && is_scalar( $user['username'] ) ) {
			$this->setTitle( sanitize_text_field( (string) $user['username'] ) );
		}

		$this->setParam( 'lastStatus', self::STATUS_ONLINE );
	}

	private function disconnectAllRelations(): bool {
		$botID = $this->getPost()->ID;
		$chatCount = $this->client->getBot2ChatRelation()->detachConnections( new Query\Connection( $botID ) );
		$channelCount = $this->client->getBot2ChannelRelation()->detachConnections( new Query\Connection( $botID ) );
		unset( $this->chats );

		return 0 < ( $chatCount + $channelCount );
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

		unset( $this->chats );

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
		unset( $this->chats );

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
	public function sendMessage( Chat $chat, string $message, string $mode, bool $throwOnError = true, array $extra = [] ): array {
		$results = [];
		$mode = TelegramMessageFormatter::normalizeMode( $mode );

		foreach ( TelegramMessageFormatter::chunks( $message ) as $chunkIndex => $chunk ) {
			$args = array_filter(
				[
					'chat_id'                  => $chat->getChatID(),
					'text'                     => $chunk,
					'parse_mode'               => $mode,
					'disable_web_page_preview' => true,
				],
				static fn( $value ): bool => '' !== $value && null !== $value
			);
			$args = apply_filters( 'wpcf7tg_sendMessage', $args, $chat->getChatID(), $mode );
			$args = apply_filters( 'cf7tg_telegram_send_message_args', $args, $chat, $this, $extra );

			$result = $this->requestSendMessage( $args );

			if ( ! $result->ok && $this->shouldRetryAsPlaintext( $result, $mode ) ) {
				$fallbackArgs = $args;
				$fallbackArgs['text'] = TelegramMessageFormatter::plaintext( $chunk, $mode );
				unset( $fallbackArgs['parse_mode'] );
				$result = $this->requestSendMessage( $fallbackArgs );
			}

			$results[] = $result;
			do_action( 'cf7tg_telegram_delivery_result', $result, $chat, $this, $extra );
			do_action( 'wpcf7tg_message_sent', $args, $extra['instance'] ?? null );

			if ( $result->ok ) {
				continue;
			}

			$this->logger->write(
				[
					'chatPostID' => $chat->getPost()->ID,
					'chunk'      => $chunkIndex + 1,
					'status'        => $result->status,
					'errorCode'     => $result->errorCode,
					'retryAfter'    => $result->retryAfter,
					'errorType'     => $result->errorType,
				],
				'Telegram delivery failed.',
				Logger::LEVEL_CRITICAL
			);

			if ( $throwOnError ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new Telegram( TelegramRedactor::text( $result->description, $this->getToken() ), $result->errorCode );
			}
		}

		return $results;
	}

	private function requestSendMessage( array $args ): TelegramDeliveryResult {
		try {
			return $this->getAPI()->sendMessage( $args );
		} catch ( \Throwable $exception ) {
			return TelegramDeliveryResult::failure(
				0,
				(int) $exception->getCode(),
				$exception->getMessage(),
				null,
				TelegramDeliveryResult::ERROR_TRANSPORT
			);
		}
	}

	private function shouldRetryAsPlaintext( TelegramDeliveryResult $result, string $mode ): bool {
		return '' !== $mode
			&& 400 === $result->errorCode
			&& ( str_contains( strtolower( $result->description ), 'parse' ) || str_contains( strtolower( $result->description ), 'entities' ) );
	}

	/**
	 * @throws BotApiNotInitialized
	 */
	public function getAPI(): TelegramGateway {
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

	private function getFetchUpdatesLockKey(): string {
		return sprintf( self::FETCH_UPDATES_LOCK_KEY_PATTERN, $this->getPost()->ID );
	}

	private function acquireFetchUpdatesLock( int $ttl = self::FETCH_UPDATES_LOCK_TTL ): bool {
		if ( Maintenance::hasCleanupLock() ) {
			return false;
		}

		$lockKey  = $this->getFetchUpdatesLockKey();
		$lockedAt = (int) get_option( $lockKey, 0 );
		$now      = time();

		if ( $lockedAt && ( $now - $lockedAt ) < $ttl ) {
			return false;
		}

		if ( $lockedAt ) {
			delete_option( $lockKey );
		}

		if ( ! add_option( $lockKey, $now, '', false ) ) {
			return false;
		}

		if ( Maintenance::hasCleanupLock() ) {
			$this->releaseFetchUpdatesLock();
			return false;
		}

		return true;
	}

	private function releaseFetchUpdatesLock(): void {
		delete_option( $this->getFetchUpdatesLockKey() );
	}

	/**
	 * Checks whether itself is online.
	 */
	public function ping(): bool {
		try {
			$res = $this->getAPI()->getMe();
		} catch ( BotApiNotInitialized $e ) {
			$this->setBotStatus( self::STATUS_OFFLINE );
			$this->logger->write(
				[
					'wpPostID'          => $this->getPost()->ID,
					'error'             => $e->getMessage(),
				],
				'Bot cannot be pinged'
			);
			return false;
		}

		if ( $res->ok ) {
			$user = is_array( $res->result ) ? $res->result : [];
			$identity = $this->telegramBotIdentity( $user );
			if ( '' !== $identity ) {
				$this->setParam( self::TELEGRAM_BOT_ID_PARAM, $identity );
			}
			$this->setBotPropertiesFromTelegramUser( $user );
			$this->savePost();
			return true;
		}

		$this->setBotStatus( self::STATUS_OFFLINE );
		$this->logger->write(
			TelegramRedactor::data(
				[
					'wpPostID'  => $this->getPost()->ID,
					'status'    => $res->status,
					'errorCode' => $res->errorCode,
					'errorType' => $res->errorType,
				],
				$this->getToken()
			),
			'Bot is unreachable'
		);

		return false;
	}

	private function getWebhookInfoResult(): TelegramDeliveryResult {
		try {
			return $this->getAPI()->getWebhookInfo();
		} catch ( BotApiNotInitialized $e ) {
			$this->logger->write(
				[
					'wpPostID' => $this->getPost()->ID,
					'error'    => $e->getMessage(),
				],
				'Bot webhook status cannot be checked',
				Logger::LEVEL_WARNING
			);
			return TelegramDeliveryResult::failure(
				0,
				0,
				$e->getMessage(),
				null,
				TelegramDeliveryResult::ERROR_TRANSPORT
			);
		}
	}

	private function summarizeWebhookInfo( array $webhookInfo ): array {
		return [
			'urlSet'             => ! empty( $webhookInfo['url'] ),
			'pendingUpdateCount' => (int) ( $webhookInfo['pending_update_count'] ?? 0 ),
			'lastErrorDate'      => isset( $webhookInfo['last_error_date'] ) ? (int) $webhookInfo['last_error_date'] : null,
			'maxConnections'     => isset( $webhookInfo['max_connections'] ) ? (int) $webhookInfo['max_connections'] : null,
		];
	}

	private function messageHasBotCommand( array $message ): bool {
		foreach ( (array) ( $message['entities'] ?? [] ) as $entity ) {
			if ( 'bot_command' === ( $entity['type'] ?? '' ) ) {
				return true;
			}
		}

		return str_starts_with( trim( (string) ( $message['text'] ?? '' ) ), '/' );
	}

	private function isStartCommand( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}

		$command = strtok( $text, " \t\r\n" );
		if ( '/' . CF7::CMD === $command ) {
			return true;
		}

		$botName = ltrim( $this->getTitle(), '@' );
		return '' !== $botName && 0 === strcasecmp( '/' . CF7::CMD . '@' . $botName, $command );
	}

	private function processUpdate( array $update, RestBotUpdates $result, array &$processedChatIDs ): void {
		$message = is_array( $update['message'] ?? null ) ? $update['message'] : [];

		if ( empty( $message ) || ! $this->messageHasBotCommand( $message ) ) {
			return;
		}

		if ( ! $this->isStartCommand( (string) ( $message['text'] ?? '' ) ) ) {
			return;
		}

		$tgChatID = Util::sanitizeTelegramChatID( $message['chat']['id'] ?? '' );
		if ( '' === $tgChatID ) {
			throw new \UnexpectedValueException( 'Telegram chat ID is empty after sanitization.' );
		}

		if ( isset( $processedChatIDs[ $tgChatID ] ) ) {
			return;
		}

		$processedChatIDs[ $tgChatID ] = true;
		$chat = Util::getChatByTelegramID( $tgChatID );

		if ( ! $chat ) {
			$chat = Util::createChatFromArray( is_array( $message['chat'] ?? null ) ? $message['chat'] : [] );
			$result->hasNewChats = true;
		}

		if ( $this->getChats()->contains( $chat ) ) {
			if ( '' === $chat->getBotConnectionStatus( $this ) ) {
				$chat->setPending( $this );
				$chat->setDate( (string) ( $message['date'] ?? time() ) );
				$chat->savePost();
			}
			return;
		}

		$connection = $this->connectChat( $chat );
		if ( ! $connection ) {
			throw new \RuntimeException( 'Bot-chat connection was not created.' );
		}

		$chat->setPending( $this );
		$chat->setDate( (string) ( $message['date'] ?? time() ) );
		$chat->savePost();
		$result->hasNewConnections = true;
	}

	/**
	 * @return RestBotUpdates
	 *
	 * @throws Telegram
	 * @throws wppaSavePostException|BotApiNotInitialized
	 */
	public function fetchUpdates(): RestBotUpdates {
		$result = new RestBotUpdates();
		$lastConsumedUpdateID = $this->getLastUpdateID();

		if ( ! $this->acquireFetchUpdatesLock() ) {
			return $result;
		}

		try {
			try {
				$webhookResult = $this->getWebhookInfoResult();
				if ( ! $webhookResult->ok ) {
					$result->errors[] = $webhookResult->jsonSerialize();
					return $result;
				}

				$webhookInfo = is_array( $webhookResult->result ) ? $webhookResult->result : [];
				if ( ! empty( $webhookInfo['url'] ) ) {
					$result->hasWebhookConflict = true;
					$result->webhookInfo = $this->summarizeWebhookInfo( $webhookInfo );
					$this->logger->write(
						[
							'wpPostID'    => $this->getPost()->ID,
							'webhookInfo' => $result->webhookInfo,
						],
						'Bot has an active Telegram webhook. getUpdates skipped.',
						Logger::LEVEL_WARNING
					);
					return $result;
				}

				$updatesResult = $this->getAPI()->getUpdates( [
					'offset'  => $this->getLastUpdateID() + 1,
					'limit'   => 10,
					'timeout' => 0,
				] );

				if ( ! $updatesResult->ok ) {
					$result->errors[] = $updatesResult->jsonSerialize();
					$this->logger->write(
						TelegramRedactor::data(
							[
								'wpPostID'  => $this->getPost()->ID,
								'status'    => $updatesResult->status,
								'errorCode' => $updatesResult->errorCode,
								'errorType' => $updatesResult->errorType,
							],
							$this->getToken()
						),
						'Bot has failed to fetch updates'
					);
					return $result;
				}

				$updates = is_array( $updatesResult->result ) ? $updatesResult->result : [];
			} catch ( BotApiNotInitialized $e ) {
				$this->logger->write(
					[
						'wpPostID'          => $this->getPost()->ID,
						'error'             => $e->getMessage(),
					],
					'Bot has failed to fetch updates'
				);
				return $result;
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

			usort(
				$updates,
				static fn( $left, $right ): int => (int) ( is_array( $left ) ? ( $left['update_id'] ?? 0 ) : 0 ) <=> (int) ( is_array( $right ) ? ( $right['update_id'] ?? 0 ) : 0 )
			);

			$processedChatIDs = [];

			foreach ( $updates as $update ) {
				$update = is_array( $update ) ? $update : [];
				$updateID = (int) ( $update['update_id'] ?? 0 );
				if ( $updateID <= $lastConsumedUpdateID ) {
					continue;
				}

				try {
					$this->processUpdate( $update, $result, $processedChatIDs );
					$lastConsumedUpdateID = $updateID;
				} catch ( \Throwable $e ) {
					$result->errors[] = [
						'errorType' => 'update_processing',
						'updateID'  => $updateID,
					];
					$this->logger->write(
						[
							'wpPostID'        => $this->getPost()->ID,
							'updateID'        => $updateID,
							'error'           => $e->getMessage(),
						],
						'Bot has failed to process a single update',
						Logger::LEVEL_WARNING
					);
					break;
				}
			}

			return $result;
		} finally {
			if ( $lastConsumedUpdateID > $this->getLastUpdateID() ) {
				try {
					$this->setLastUpdateID( $lastConsumedUpdateID );
				} catch ( \Throwable $e ) {
					$this->logger->write(
						[
							'wpPostID'        => $this->getPost()->ID,
							'lastUpdateID'    => $this->getLastUpdateID(),
							'newLastUpdateID' => $lastConsumedUpdateID,
							'error'           => $e->getMessage(),
						],
						'Bot has failed to persist last update ID',
						Logger::LEVEL_CRITICAL
					);
				}
			}

			$this->releaseFetchUpdatesLock();
		}
	}
}
