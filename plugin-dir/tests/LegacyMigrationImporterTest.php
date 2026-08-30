<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Migrations\LegacyImporter;

final class LegacyMigrationImporterTest extends Cf7tg_TestCase {
	private const LEGACY_TOKEN = '123456789:TEST_LEGACY_TOKEN';

	public function testImportIsNoOpWithoutLegacySourceData(): void {
		$this->initClient();
		wp_insert_post( [ 'post_type' => Client::CPT_BOT, 'post_status' => 'publish', 'post_title' => 'Existing bot' ], true );
		wp_insert_post( [ 'post_type' => Client::CPT_CHAT, 'post_status' => 'publish', 'post_title' => 'Existing chat' ], true );
		wp_insert_post( [ 'post_type' => Client::CPT_CHANNEL, 'post_status' => 'publish', 'post_title' => 'Existing channel' ], true );

		$report = ( new LegacyImporter() )->import();

		$this->assertSame( 1, $this->postCount( Client::CPT_BOT ) );
		$this->assertSame( 1, $this->postCount( Client::CPT_CHAT ) );
		$this->assertSame( 1, $this->postCount( Client::CPT_CHANNEL ) );
		$this->assertSame( 'missing_token', $report['skips'][0]['reason'] );
		$this->assertSame( 0, $this->relationCount( Client::BOT2CHAT ) );
	}

	public function testRepeatedFullImportCreatesStableEntitiesRelationsAndPreservesForms(): void {
		$this->initClient();
		$this->seedLegacySource();
		$formOne = $this->createCf7Form( 'First form', "[text* your-name]\n[telegram]\n[submit \"Send\"]" );
		$formTwo = $this->createCf7Form( 'Second form', "[email* your-email]\n[telegram]\n[submit \"Send\"]" );
		$originalFormOne = $this->formSnapshot( $formOne );
		$originalFormTwo = $this->formSnapshot( $formTwo );
		$this->seedRunningMigrationState();

		$first = ( new LegacyImporter() )->import( [
			'reason'         => 'self_heal',
			'source_version' => '0.0',
			'target_version' => WPCF7TG_VERSION,
		] );
		$second = ( new LegacyImporter() )->import();

		$this->assertSame( 1, $this->postCount( Client::CPT_BOT ) );
		$this->assertSame( 2, $this->postCount( Client::CPT_CHAT ) );
		$this->assertSame( 1, $this->postCount( Client::CPT_CHANNEL ) );
		$this->assertSame( 2, $this->relationCount( Client::BOT2CHAT ) );
		$this->assertSame( 2, $this->relationCount( Client::CHAT2CHANNEL ) );
		$this->assertSame( 1, $this->relationCount( Client::BOT2CHANNEL ) );
		$this->assertSame( 2, $this->relationCount( Client::FORM2CHANNEL ) );
		$this->assertSame( 0, $this->duplicateRelationCount() );
		$this->assertSame( 0, $second['bots']['created'] + $second['channels']['created'] + $second['chats']['created'] + $second['relations']['created'] );
		$this->assertSame( self::LEGACY_TOKEN, get_option( 'wpcf7_telegram_tkn' ) );
		$this->assertSame( $originalFormOne, $this->formSnapshot( $formOne ) );
		$this->assertSame( $originalFormTwo, $this->formSnapshot( $formTwo ) );
		$this->assertSame( 2, $first['forms']['connected'] );

		$state = Migration::getMigrationState();
		$this->assertSame( 'completed', $state['steps'][ LegacyImporter::MIGRATION_VERSION ]['legacy_import']['stage'] );
	}

	public function testMalformedChatRowsAreSkippedWhileValidRowsMigrate(): void {
		$this->initClient();
		update_option( 'wpcf7_telegram_tkn', self::LEGACY_TOKEN, false );
		update_option(
			'wpcf7_telegram_chats',
			[
				[ 'id' => '700001', 'first_name' => 'Valid' ],
				[ 'id' => '', 'first_name' => 'Broken' ],
				[ 'id' => 'not-a-number', 'first_name' => 'Broken' ],
				[ 'id' => '700001', 'first_name' => 'Duplicate' ],
			],
			false
		);

		$report = ( new LegacyImporter() )->import();

		$this->assertSame( 1, $this->postCount( Client::CPT_CHAT ) );
		$this->assertSame( 3, $report['chats']['skipped'] );
		$this->assertSame( 1, $this->relationCount( Client::BOT2CHAT ) );
		$this->assertSame( 1, $this->relationCount( Client::CHAT2CHANNEL ) );
	}

	public function testPartialModernEntitiesAreReusedAndMissingRelationsCreated(): void {
		$this->initClient();
		update_option( 'wpcf7_telegram_tkn', self::LEGACY_TOKEN, false );
		update_option(
			'wpcf7_telegram_chats',
			[
				[ 'id' => '700001', 'first_name' => 'Legacy', 'status' => 'pending' ],
			],
			false
		);

		$bot = new Bot();
		$bot->setToken( self::LEGACY_TOKEN );
		$bot->publish();

		$chat = new Chat();
		$chat->setChatID( '700001' );
		$chat->publish();

		$channel = new Channel();
		$channel->setTitle( 'Channel Name' );
		$channel->publish();

		( new LegacyImporter() )->import();

		$this->assertSame( 1, $this->postCount( Client::CPT_BOT ) );
		$this->assertSame( 1, $this->postCount( Client::CPT_CHAT ) );
		$this->assertSame( 1, $this->postCount( Client::CPT_CHANNEL ) );
		$this->assertSame( 1, $this->relationCount( Client::BOT2CHAT ) );
		$this->assertSame( 1, $this->relationCount( Client::CHAT2CHANNEL ) );
		$this->assertSame( 1, $this->relationCount( Client::BOT2CHANNEL ) );
		$this->assertSame( 'pending', $this->connectionStatus( Client::BOT2CHAT ) );
	}

	public function testBrokenBotChannelRelationIsIgnoredAndAValidChannelIsConnected(): void {
		$this->initClient();
		update_option( 'wpcf7_telegram_tkn', self::LEGACY_TOKEN, false );

		$bot = new Bot();
		$bot->setToken( self::LEGACY_TOKEN );
		$bot->publish();

		$GLOBALS['wp_connection_rows'][] = (object) [
			'ID'       => $GLOBALS['wp_next_connection_id']++,
			'relation' => Client::BOT2CHANNEL,
			'from'     => $bot->getPost()->ID,
			'to'       => 999999,
			'order'    => 0,
			'title'    => '',
		];

		( new LegacyImporter() )->import();

		$this->assertSame( 1, $this->postCount( Client::CPT_CHANNEL ) );
		$this->assertTrue( $this->hasValidRelation( Client::BOT2CHANNEL ) );
	}

	public function testConstantBackedTokenDoesNotWriteSecretToPostDataAndReportsGlobalConstantName(): void {
		$this->initClient();

		if ( ! defined( 'WPFC7TG_BOT_TOKEN' ) ) {
			define( 'WPFC7TG_BOT_TOKEN', '123456789:TEST_CONST_TOKEN' );
		}

		update_option(
			'wpcf7_telegram_chats',
			[
				[ 'id' => '700001', 'first_name' => 'Constant' ],
			],
			false
		);

		( new LegacyImporter() )->import();

		$botID = $this->firstPostID( Client::CPT_BOT );
		$botPost = get_post( $botID );
		$bot = new Bot( $botID );

		$this->assertSame( constant( 'WPFC7TG_BOT_TOKEN' ), $bot->getToken() );
		$this->assertSame( 'WPFC7TG_BOT_TOKEN', $bot->getTokenConstName() );
		$this->assertFalse( str_contains( $botPost->post_content_filtered, constant( 'WPFC7TG_BOT_TOKEN' ) ) );
		$this->assertTrue( str_contains( $botPost->post_content_filtered, Bot::TOKEN_SOURCE_LEGACY_CONST ) );
		$this->assertSame( false, get_option( 'wpcf7_telegram_tkn' ) );
	}

	private function initClient(): void {
		Client::getInstance()->init();
	}

	private function seedLegacySource(): void {
		update_option( 'wpcf7_telegram_tkn', self::LEGACY_TOKEN, false );
		update_option( 'wpcf7_telegram_last_update_id', 987654321, false );
		update_option(
			'wpcf7_telegram_chats',
			[
				[
					'id'         => '700001',
					'status'     => 'active',
					'first_name' => 'First',
					'last_name'  => 'User',
					'username'   => 'first_user',
				],
				[
					'id'         => '-100700002',
					'status'     => 'pending',
					'first_name' => '',
					'last_name'  => '',
					'username'   => 'group_user',
				],
			],
			false
		);
	}

	private function createCf7Form( string $title, string $body ): int {
		$postID = wp_insert_post(
			[
				'post_type'    => Client::CPT_CF7FORM,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $body,
			],
			true
		);
		update_post_meta( $postID, '_form', $body );
		return $postID;
	}

	private function seedRunningMigrationState(): void {
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => 'running',
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
				'attempts'       => 1,
				'current_step'   => LegacyImporter::MIGRATION_VERSION,
				'steps'          => [
					LegacyImporter::MIGRATION_VERSION => [
						'status'   => 'running',
						'attempts' => 1,
					],
				],
				'errors'         => [],
			],
			false
		);
	}

	private function postCount( string $postType ): int {
		return count( get_posts( [ 'post_type' => $postType, 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any' ] ) );
	}

	private function firstPostID( string $postType ): int {
		$ids = get_posts( [ 'post_type' => $postType, 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any' ] );
		return (int) $ids[0];
	}

	private function relationCount( string $relation ): int {
		return count(
			array_filter(
				$GLOBALS['wp_connection_rows'],
				static fn( object $connection ): bool => $relation === $connection->relation
			)
		);
	}

	private function duplicateRelationCount(): int {
		$seen = [];
		$duplicates = 0;

		foreach ( $GLOBALS['wp_connection_rows'] as $connection ) {
			$key = $connection->relation . ':' . $connection->from . ':' . $connection->to;
			if ( isset( $seen[ $key ] ) ) {
				$duplicates++;
				continue;
			}

			$seen[ $key ] = true;
		}

		return $duplicates;
	}

	private function connectionStatus( string $relation ): string {
		foreach ( $GLOBALS['wp_connection_rows'] as $connection ) {
			if ( $relation !== $connection->relation ) {
				continue;
			}

			foreach ( $GLOBALS['wp_connection_meta_rows'] as $meta ) {
				if ( (int) $connection->ID === (int) $meta->connection_id && Chat::STATUS_KEY === $meta->meta_key ) {
					return (string) $meta->meta_value;
				}
			}
		}

		return '';
	}

	private function hasValidRelation( string $relation ): bool {
		foreach ( $GLOBALS['wp_connection_rows'] as $connection ) {
			if ( $relation === $connection->relation && get_post( (int) $connection->from ) && get_post( (int) $connection->to ) ) {
				return true;
			}
		}

		return false;
	}

	private function formSnapshot( int $postID ): array {
		$post = get_post( $postID );

		return [
			'post_content' => $post->post_content,
			'_form'        => get_post_meta( $postID, '_form', true ),
		];
	}
}
