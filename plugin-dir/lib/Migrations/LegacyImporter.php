<?php

namespace iTRON\cf7Telegram\Migrations;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Logger;
use iTRON\cf7Telegram\Util;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\Query;

class LegacyImporter {
	public const MIGRATION_VERSION = '1.0-alpha';
	public const IMPORT_KEY = 'cf7tg:legacy:v1.0-alpha';
	public const PARAM_IMPORT_KEY = 'legacyImportKey';
	public const PARAM_IMPORT_SOURCE = 'legacyImportSource';
	public const SOURCE_DB_OPTION = 'legacy_token_option';
	public const SOURCE_GLOBAL_CONSTANT = 'legacy_global_constant';

	private const DEFAULT_CHANNEL_TITLE = 'Channel Name';

	private Client $client;
	private Logger $logger;

	public function __construct() {
		$this->client = Client::getInstance();
		$this->logger = new Logger();
	}

	public function import( array $context = [] ): array {
		$this->ensureRelationsRegistered();

		$forms = $this->findLegacyFormIDs();
		$tokenSource = $this->resolveTokenSource( $forms );
		$report = $this->emptyReport( $context, $tokenSource['source'] );

		if ( '' === $tokenSource['token'] ) {
			$report['skips'][] = [
				'type'   => 'run',
				'reason' => 'missing_token',
			];
			$this->checkpoint( 'missing_token', $report );
			$this->safeLog( $report, 'Legacy migration skipped: no usable token.', Logger::LEVEL_ATTENTION );
			return $report;
		}

		$this->checkpoint( 'source_loaded', $report );

		$bot = $this->upsertBot( $tokenSource, $report );
		$channel = $this->upsertChannel( $bot, $report );
		$this->ensureConnection( Client::BOT2CHANNEL, $bot->getPost()->ID, $channel->getPost()->ID, $report );

		$this->checkpoint( 'bot_channel_ready', $report );

		foreach ( $this->normalizeLegacyChats( get_option( 'wpcf7_telegram_chats', [] ) ) as $row ) {
			if ( ! empty( $row['skip'] ) ) {
				$report['chats']['skipped']++;
				$report['skips'][] = [
					'type'   => 'chat',
					'index'  => $row['index'],
					'reason' => $row['reason'],
				];
				continue;
			}

			$chat = $this->upsertChat( $row, $report );
			$connection = $this->ensureConnection( Client::BOT2CHAT, $bot->getPost()->ID, $chat->getPost()->ID, $report );
			$this->syncBotChatStatus( $connection, $row['status'] );
			$this->ensureConnection( Client::CHAT2CHANNEL, $chat->getPost()->ID, $channel->getPost()->ID, $report );
		}

		$this->checkpoint( 'chats_ready', $report );

		foreach ( $forms as $formID ) {
			$this->ensureConnection( Client::FORM2CHANNEL, $formID, $channel->getPost()->ID, $report );
			$report['forms']['connected']++;
		}

		$this->checkpoint( 'completed', $report );

		if ( $report['skips'] ) {
			$this->safeLog( $report, 'Legacy migration completed with skipped input.', Logger::LEVEL_WARNING );
		}

		return $report;
	}

	private function emptyReport( array $context, string $tokenSource ): array {
		return [
			'version'      => self::MIGRATION_VERSION,
			'context'      => [
				'reason'         => (string) ( $context['reason'] ?? '' ),
				'source_version' => (string) ( $context['source_version'] ?? '' ),
				'target_version' => (string) ( $context['target_version'] ?? '' ),
			],
			'token_source' => $tokenSource,
			'bots'         => [
				'created' => 0,
				'reused'  => 0,
				'updated' => 0,
			],
			'channels'     => [
				'created' => 0,
				'reused'  => 0,
			],
			'chats'        => [
				'created' => 0,
				'reused'  => 0,
				'updated' => 0,
				'skipped' => 0,
			],
			'relations'    => [
				'created' => 0,
				'reused'  => 0,
			],
			'forms'        => [
				'connected' => 0,
			],
			'skips'        => [],
		];
	}

	private function resolveTokenSource( array $formIDs ): array {
		$dbToken = $this->normalizeToken( get_option( 'wpcf7_telegram_tkn', '' ) );
		$hasLegacySource = '' !== $dbToken
			|| false !== get_option( 'wpcf7_telegram_chats', false )
			|| false !== get_option( 'wpcf7_telegram_last_update_id', false )
			|| ! empty( $formIDs );

		$constantToken = defined( Bot::LEGACY_TOKEN_CONST )
			? $this->normalizeToken( constant( Bot::LEGACY_TOKEN_CONST ) )
			: '';

		if ( $hasLegacySource && '' !== $constantToken ) {
			return [
				'token'  => $constantToken,
				'source' => self::SOURCE_GLOBAL_CONSTANT,
			];
		}

		return [
			'token'  => $dbToken,
			'source' => '' === $dbToken ? '' : self::SOURCE_DB_OPTION,
		];
	}

	private function normalizeToken( $token ): string {
		if ( ! is_scalar( $token ) ) {
			return '';
		}

		return trim( (string) $token );
	}

	private function findLegacyFormIDs(): array {
		$ids = [];

		foreach ( $this->getPostIDs( Client::CPT_CF7FORM ) as $postID ) {
			$post = get_post( $postID );
			if ( ! $post ) {
				continue;
			}

			$formMeta = get_post_meta( $postID, '_form', true );
			if (
				str_contains( (string) $post->post_content, '[telegram]' ) ||
				str_contains( (string) $formMeta, '[telegram]' )
			) {
				$ids[] = $postID;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function normalizeLegacyChats( $legacyChats ): array {
		$rows = [];

		if ( is_scalar( $legacyChats ) && '' !== trim( (string) $legacyChats ) ) {
			$rows[] = [ 'id' => $legacyChats ];
		} elseif ( is_array( $legacyChats ) ) {
			if ( array_key_exists( 'id', $legacyChats ) ) {
				$rows[] = $legacyChats;
			} else {
				foreach ( $legacyChats as $row ) {
					$rows[] = is_array( $row ) ? $row : [ 'id' => $row ];
				}
			}
		}

		$result = [];
		$seen = [];

		foreach ( $rows as $index => $row ) {
			$chatID = Util::sanitizeTelegramChatID( $row['id'] ?? '' );

			if ( '' === $chatID || ! preg_match( '/^-?\d+$/', $chatID ) ) {
				$result[] = [
					'skip'   => true,
					'index'  => $index,
					'reason' => 'invalid_chat_id',
				];
				continue;
			}

			if ( isset( $seen[ $chatID ] ) ) {
				$result[] = [
					'skip'   => true,
					'index'  => $index,
					'reason' => 'duplicate_chat_id',
				];
				continue;
			}

			$seen[ $chatID ] = true;
			$firstName = Util::sanitizeTelegramText( $row['first_name'] ?? '' );
			$lastName = Util::sanitizeTelegramText( $row['last_name'] ?? '' );
			$username = Util::sanitizeTelegramText( $row['username'] ?? '' );
			$title = Util::sanitizeTelegramText( $row['title'] ?? '' );
			$type = Util::sanitizeTelegramChatType( $row['type'] ?? '' );

			if ( '' === $type ) {
				$type = ( (int) $chatID ) > 0 ? 'private' : 'group';
			}

			if ( '' === $title ) {
				$title = trim( $firstName . ' ' . $lastName );
			}

			if ( '' === $title && '' !== $username ) {
				$title = '@' . ltrim( $username, '@' );
			}

			if ( '' === $title ) {
				$title = $chatID;
			}

			$result[] = [
				'skip'       => false,
				'index'      => $index,
				'id'         => $chatID,
				'type'       => $type,
				'first_name' => $firstName,
				'last_name'  => $lastName,
				'username'   => $username,
				'title'      => $title,
				'status'     => 'pending' === ( $row['status'] ?? '' ) ? Chat::STATUS_PENDING : Chat::STATUS_ACTIVE,
			];
		}

		return $result;
	}

	private function upsertBot( array $tokenSource, array &$report ): Bot {
		$bot = $this->findBot( $tokenSource );
		$created = false;

		if ( $bot ) {
			$report['bots']['reused']++;
		} else {
			$bot = new Bot();
			$created = true;
			$report['bots']['created']++;
		}

		if ( $created ) {
			$this->markEntity( $bot, $tokenSource['source'] );
			$bot->publish();
		}

		$changed = false;

		if ( self::SOURCE_GLOBAL_CONSTANT === $tokenSource['source'] ) {
			if ( ! $bot->usesLegacyTokenConstant() && '' === $this->normalizeToken( $bot->getParam( 'token' ) ) ) {
				$bot->setLegacyTokenConstantSource();
				$changed = true;
			}
		} elseif ( '' === $this->normalizeToken( $bot->getToken() ) ) {
			$bot->setToken( $tokenSource['token'] );
			$changed = true;
		}

		$lastUpdateID = get_option( 'wpcf7_telegram_last_update_id', null );
		if ( is_scalar( $lastUpdateID ) && '' !== (string) $lastUpdateID && 0 === $bot->getLastUpdateID() ) {
			$bot->setLastUpdateID( (int) $lastUpdateID );
			$changed = true;
		}

		$this->markEntity( $bot, $tokenSource['source'] );
		$bot->publish();

		if ( $changed ) {
			$report['bots']['updated']++;
		}

		return $bot;
	}

	private function findBot( array $tokenSource ): ?Bot {
		$token = $tokenSource['token'];
		$source = $tokenSource['source'];
		$byImportMarker = null;

		foreach ( $this->getPostIDs( Client::CPT_BOT ) as $postID ) {
			$postData = $this->getPostData( $postID );

			if ( self::IMPORT_KEY === ( $postData[ self::PARAM_IMPORT_KEY ] ?? '' ) ) {
				$byImportMarker = $byImportMarker ?? new Bot( $postID );
			}

			if (
				self::SOURCE_GLOBAL_CONSTANT === $source &&
				Bot::TOKEN_SOURCE_LEGACY_CONST === ( $postData[ Bot::TOKEN_SOURCE_PARAM ] ?? '' )
			) {
				return new Bot( $postID );
			}

			if ( '' !== $token && $token === $this->normalizeToken( $postData['token'] ?? '' ) ) {
				return new Bot( $postID );
			}
		}

		return $byImportMarker;
	}

	private function upsertChannel( Bot $bot, array &$report ): Channel {
		$channel = $this->findChannelForBot( $bot );

		if ( $channel ) {
			$report['channels']['reused']++;
		} else {
			$channel = new Channel();
			$channel->setTitle( __( self::DEFAULT_CHANNEL_TITLE, 'cf7-telegram' ) );
			$report['channels']['created']++;
		}

		$this->markEntity( $channel, self::IMPORT_KEY );
		$channel->publish();

		return $channel;
	}

	private function findChannelForBot( Bot $bot ): ?Channel {
		$connections = $this->getRelation( Client::BOT2CHANNEL )
			->findConnections( new Query\Connection( $bot->getPost()->ID ) );

		foreach ( $connections->getIterator() as $connection ) {
			$post = get_post( (int) $connection->to );
			if ( $post && Client::CPT_CHANNEL === $post->post_type && 'trash' !== $post->post_status ) {
				return new Channel( (int) $connection->to );
			}
		}

		$byTitle = [];
		foreach ( $this->getPostIDs( Client::CPT_CHANNEL ) as $postID ) {
			$post = get_post( $postID );
			$postData = $this->getPostData( $postID );

			if ( self::IMPORT_KEY === ( $postData[ self::PARAM_IMPORT_KEY ] ?? '' ) ) {
				return new Channel( $postID );
			}

			if ( $post && self::DEFAULT_CHANNEL_TITLE === $post->post_title ) {
				$byTitle[] = $postID;
			}
		}

		return 1 === count( $byTitle ) ? new Channel( $byTitle[0] ) : null;
	}

	private function upsertChat( array $row, array &$report ): Chat {
		$chat = $this->findChatByTelegramID( $row['id'] );

		if ( $chat ) {
			$report['chats']['reused']++;
		} else {
			$chat = new Chat();
			$report['chats']['created']++;
		}

		$changed = false;
		$changed = $this->setIfEmpty( $chat, 'getChatID', 'setChatID', $row['id'] ) || $changed;
		$changed = $this->setIfEmpty( $chat, 'getChatType', 'setChatType', $row['type'] ) || $changed;
		$changed = $this->setIfEmpty( $chat, 'getFirstName', 'setFirstName', $row['first_name'] ) || $changed;
		$changed = $this->setIfEmpty( $chat, 'getLastName', 'setLastName', $row['last_name'] ) || $changed;
		$changed = $this->setIfEmpty( $chat, 'getUsername', 'setUsername', $row['username'] ) || $changed;

		if ( '' === trim( $chat->getTitle() ) ) {
			$chat->setTitle( $row['title'] );
			$changed = true;
		}

		$this->markEntity( $chat, self::IMPORT_KEY );
		$chat->publish();

		if ( $changed ) {
			$report['chats']['updated']++;
		}

		return $chat;
	}

	private function setIfEmpty( Chat $chat, string $getter, string $setter, string $value ): bool {
		if ( '' === $value || '' !== trim( (string) $chat->$getter() ) ) {
			return false;
		}

		$chat->$setter( $value );
		return true;
	}

	private function findChatByTelegramID( string $chatID ): ?Chat {
		foreach ( $this->getPostIDs( Client::CPT_CHAT ) as $postID ) {
			$postData = $this->getPostData( $postID );
			if ( $chatID === $this->normalizeToken( $postData['chatID'] ?? '' ) ) {
				return new Chat( $postID );
			}
		}

		return null;
	}

	private function ensureConnection( string $relationName, int $from, int $to, array &$report ): Connection {
		$relation = $this->getRelation( $relationName );
		$query = new Query\Connection( $from, $to );
		$connections = $relation->findConnections( $query );

		if ( ! $connections->isEmpty() ) {
			$report['relations']['reused']++;
			return $connections->first();
		}

		try {
			$connection = $relation->createConnection( $query );
			$report['relations']['created']++;
			return $connection;
		} catch ( ConnectionWrongData $e ) {
			$connections = $relation->findConnections( $query );
			if ( ! $connections->isEmpty() ) {
				$report['relations']['reused']++;
				return $connections->first();
			}

			throw $e;
		}
	}

	private function syncBotChatStatus( Connection $connection, string $status ): void {
		$existing = [];
		foreach ( $connection->meta->getIterator() as $meta ) {
			/** @var Meta $meta */
			if ( Chat::STATUS_KEY !== $meta->getKey() ) {
				$existing[] = $meta;
			}
		}

		$connection->meta->clear();
		foreach ( $existing as $meta ) {
			$connection->meta->add( $meta );
		}

		$connection->meta->add( new Meta( Chat::STATUS_KEY, $status ) );
		$connection->update();
	}

	private function markEntity( $entity, string $source ): void {
		$entity->setParam( self::PARAM_IMPORT_KEY, self::IMPORT_KEY );
		$entity->setParam( self::PARAM_IMPORT_SOURCE, $source );
	}

	private function getRelation( string $relationName ): \iTRON\wpConnections\Relation {
		return $this->client->getConnectionsClient()->getRelation( $relationName );
	}

	private function ensureRelationsRegistered(): void {
		try {
			$this->client->getBot2ChatRelation();
			$this->client->getBot2ChannelRelation();
			$this->client->getChat2ChannelRelation();
			$this->client->getForm2ChannelRelation();
		} catch ( RelationNotFound $e ) {
			$this->client->init();
		}
	}

	private function getPostIDs( string $postType ): array {
		return array_map(
			'intval',
			get_posts( [
				'post_type'      => $postType,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			] )
		);
	}

	private function getPostData( int $postID ): array {
		$post = get_post( $postID );
		if ( ! $post ) {
			return [];
		}

		$data = json_decode( (string) $post->post_content_filtered, true );
		return is_array( $data ) ? $data : [];
	}

	private function checkpoint( string $stage, array $report ): void {
		$state = get_option( Migration::STATE_OPTION, [] );
		if ( ! is_array( $state ) || empty( $state['steps'][ self::MIGRATION_VERSION ] ) ) {
			return;
		}

		$state['steps'][ self::MIGRATION_VERSION ]['legacy_import'] = [
			'stage'      => $stage,
			'updated_at' => time(),
			'report'     => $report,
		];
		$state['updated_at'] = time();

		update_option( Migration::STATE_OPTION, $state, false );
	}

	private function safeLog( array $report, string $title, int $level ): void {
		try {
			$this->logger->write( $report, $title, $level );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
