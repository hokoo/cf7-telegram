<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Migrations\LegacyImporter;

final class WpConfigConstantTest extends Cf7tg_TestCase {
	private const LEGACY_TOKEN = '123456789:TEST_CONST_ONLY_TOKEN';

	public function testConstantOnlyLegacySourceCreatesMarkerWithoutWritingSecret(): void {
		Client::getInstance()->init();

		if ( ! defined( Bot::LEGACY_TOKEN_CONST ) ) {
			define( Bot::LEGACY_TOKEN_CONST, self::LEGACY_TOKEN );
		}

		$report = ( new LegacyImporter() )->import();
		$botIDs = get_posts( [
			'post_type'      => Client::CPT_BOT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$this->assertSame( LegacyImporter::SOURCE_GLOBAL_CONSTANT, $report['token_source'] );
		$this->assertSame( 1, count( $botIDs ) );
		$this->assertSame( 1, count( get_posts( [ 'post_type' => Client::CPT_CHANNEL, 'post_status' => 'any', 'posts_per_page' => -1 ] ) ) );

		$botPost = get_post( (int) $botIDs[0] );
		$bot = new Bot( (int) $botIDs[0] );

		$this->assertSame( constant( Bot::LEGACY_TOKEN_CONST ), $bot->getToken() );
		$this->assertSame( Bot::LEGACY_TOKEN_CONST, $bot->getTokenConstName() );
		$this->assertFalse( str_contains( $botPost->post_content_filtered, self::LEGACY_TOKEN ) );
		$this->assertTrue( str_contains( $botPost->post_content_filtered, Bot::TOKEN_SOURCE_LEGACY_CONST ) );
		$this->assertSame( false, get_option( 'wpcf7_telegram_tkn' ) );
	}

	public function testConstantOnlyLegacySourceSchedulesSelfHeal(): void {
		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );
		$this->assertSame( 1, count( $events ) );
		$this->assertSame( [ 'self_heal', '0.0', WPCF7TG_VERSION ], $events[0]['args'] );
	}
}
