<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Maintenance;

final class MaintenanceTest extends Cf7tg_TestCase {
	public function testRegisterScheduleAddsCleanupInterval(): void {
		$schedules = Maintenance::registerSchedule( [] );

		$this->assertArrayHasKey( Maintenance::CRON_SCHEDULE, $schedules );
		$this->assertSame( Maintenance::DEFAULT_INTERVAL, $schedules[ Maintenance::CRON_SCHEDULE ]['interval'] );
		$this->assertSame( 'CF7 Telegram cleanup every 1440 minutes', $schedules[ Maintenance::CRON_SCHEDULE ]['display'] );
	}

	public function testRegisterScheduleClampsIntervalToOneMinute(): void {
		add_filter(
			'cf7tg/cleanupInterval',
			static fn(): int => 10
		);

		$schedules = Maintenance::registerSchedule( [] );

		$this->assertSame( MINUTE_IN_SECONDS, $schedules[ Maintenance::CRON_SCHEDULE ]['interval'] );
		$this->assertSame( 'CF7 Telegram cleanup every 1 minutes', $schedules[ Maintenance::CRON_SCHEDULE ]['display'] );
	}

	public function testActivateSchedulesImmediateAndRecurringCleanup(): void {
		Maintenance::activate();

		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, false ) );
		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
		$this->assertSame( [], $GLOBALS['wp_schedule_errors'] );
	}

	public function testEnsureScheduledDoesNotDuplicateEvents(): void {
		Maintenance::ensureScheduled();
		Maintenance::ensureScheduled();
		Maintenance::ensureScheduled();

		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, false ) );
		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
	}

	public function testEnsureScheduledRestoresRecurringCleanupWhenImmediateExists(): void {
		wp_schedule_single_event( time(), Maintenance::CRON_HOOK );

		Maintenance::ensureScheduled();

		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, false ) );
		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
		$this->assertSame( [], $GLOBALS['wp_schedule_errors'] );
	}

	public function testEnsureScheduledDoesNotCreateImmediateCleanupWhenRecurringExists(): void {
		add_filter(
			'cron_schedules',
			[ Maintenance::class, 'registerSchedule' ]
		);
		wp_schedule_event( time() + MINUTE_IN_SECONDS, Maintenance::CRON_SCHEDULE, Maintenance::CRON_HOOK );

		Maintenance::ensureScheduled();

		$this->assertSame( 0, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, false ) );
		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
		$this->assertArrayNotHasKey( Maintenance::CRON_LAST_ERROR_OPTION, $GLOBALS['wp_options'] );
	}

	public function testEnsureScheduledReschedulesRecurringCleanupWhenIntervalChanges(): void {
		$interval = HOUR_IN_SECONDS;
		add_filter(
			'cf7tg/cleanupInterval',
			static function () use ( &$interval ): int {
				return $interval;
			}
		);

		Maintenance::ensureScheduled();
		$interval = 2 * HOUR_IN_SECONDS;
		Maintenance::ensureScheduled();

		$recurring = array_values(
			array_filter(
				$this->cronEvents( Maintenance::CRON_HOOK ),
				static fn( array $event ): bool => Maintenance::CRON_SCHEDULE === ( $event['schedule'] ?? null )
			)
		);

		$this->assertSame( 1, count( $recurring ) );
		$this->assertSame( 2 * HOUR_IN_SECONDS, $recurring[0]['interval'] );
	}

	public function testEnsureScheduledCollapsesDuplicateRecurringCleanupEvents(): void {
		add_filter(
			'cron_schedules',
			[ Maintenance::class, 'registerSchedule' ]
		);
		wp_schedule_event( time() + HOUR_IN_SECONDS, Maintenance::CRON_SCHEDULE, Maintenance::CRON_HOOK );
		wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, Maintenance::CRON_SCHEDULE, Maintenance::CRON_HOOK );

		Maintenance::ensureScheduled();

		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
	}

	public function testEnsureScheduledReplacesLegacyRecurringSchedule(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Maintenance::CRON_HOOK );

		Maintenance::ensureScheduled();

		$this->assertSame( 0, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, 'daily' ) );
		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
	}

	public function testEnsureScheduledStoresRecurringScheduleFailure(): void {
		add_filter(
			'pre_schedule_event',
			static function ( $pre, object $event ) {
				if ( Maintenance::CRON_HOOK === $event->hook && Maintenance::CRON_SCHEDULE === $event->schedule ) {
					return new WP_Error( 'could_not_set', 'Cron option was not saved.' );
				}

				return $pre;
			},
			10,
			2
		);

		Maintenance::ensureScheduled();

		$this->assertSame( 1, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, false ) );
		$this->assertSame( 0, $this->countCronEventsBySchedule( Maintenance::CRON_HOOK, Maintenance::CRON_SCHEDULE ) );
		$this->assertArrayHasKey( Maintenance::CRON_LAST_ERROR_OPTION, $GLOBALS['wp_options'] );
		$this->assertSame( 'recurring', $GLOBALS['wp_options'][ Maintenance::CRON_LAST_ERROR_OPTION ]['type'] );
		$this->assertSame( 'could_not_set', $GLOBALS['wp_options'][ Maintenance::CRON_LAST_ERROR_OPTION ]['code'] );
	}

	public function testDeactivateClearsScheduledCleanupAndLocks(): void {
		Maintenance::ensureScheduled();
		add_option( Maintenance::CLEANUP_LOCK_OPTION, time(), '', false );
		add_option( Bot::FETCH_UPDATES_LOCK_PREFIX . '10', time(), '', false );
		add_option( Bot::FETCH_UPDATES_LOCK_PREFIX . '20', time(), '', false );

		Maintenance::deactivate();

		$this->assertSame( [], $this->cronEvents( Maintenance::CRON_HOOK ) );
		$this->assertArrayNotHasKey( Maintenance::CLEANUP_LOCK_OPTION, $GLOBALS['wp_options'] );
		$this->assertArrayNotHasKey( Bot::FETCH_UPDATES_LOCK_PREFIX . '10', $GLOBALS['wp_options'] );
		$this->assertArrayNotHasKey( Bot::FETCH_UPDATES_LOCK_PREFIX . '20', $GLOBALS['wp_options'] );
	}

	public function testUninstallCleansPluginOptionsWithoutRemovedEarlyFlagConstant(): void {
		add_option( 'cf7tg_custom', 'value', '', false );
		add_option( 'cf7t_early_access', '1', '', false );
		add_option( 'wpcf7_telegram_tkn', 'secret', '', false );
		add_option( Migration::FIX_1_0_FLAG, true, '', false );
		add_option( 'unrelated_option', 'keep', '', false );

		Maintenance::uninstall();

		$this->assertArrayNotHasKey( 'cf7tg_custom', $GLOBALS['wp_options'] );
		$this->assertArrayNotHasKey( 'cf7t_early_access', $GLOBALS['wp_options'] );
		$this->assertArrayNotHasKey( 'wpcf7_telegram_tkn', $GLOBALS['wp_options'] );
		$this->assertArrayNotHasKey( Migration::FIX_1_0_FLAG, $GLOBALS['wp_options'] );
		$this->assertSame( 'keep', $GLOBALS['wp_options']['unrelated_option'] );
	}

	public function testCleanupLockExpiresAndDeletesStaleLock(): void {
		add_option( Maintenance::CLEANUP_LOCK_OPTION, time() - Maintenance::CLEANUP_LOCK_TTL - 1, '', false );

		$this->assertFalse( Maintenance::hasCleanupLock() );
		$this->assertArrayNotHasKey( Maintenance::CLEANUP_LOCK_OPTION, $GLOBALS['wp_options'] );
	}

	public function testCleanupLockDetectsActiveLock(): void {
		add_option( Maintenance::CLEANUP_LOCK_OPTION, time(), '', false );

		$this->assertTrue( Maintenance::hasCleanupLock() );
		$this->assertArrayHasKey( Maintenance::CLEANUP_LOCK_OPTION, $GLOBALS['wp_options'] );
	}

	public function testRepairDryRunReportsBrokenRelationsAndPreservedOrphanChatsWithoutMutation(): void {
		$this->seedRepairFixture();
		$connectionsBefore = $GLOBALS['wp_connection_rows'];
		$metaBefore = $GLOBALS['wp_connection_meta_rows'];
		$postsBefore = $GLOBALS['wp_posts'];

		$result = Maintenance::runRepair();

		$this->assertSame( Maintenance::REPAIR_MODE_DRY_RUN, $result['mode'] );
		$this->assertSame( [ 2 ], $result['planned']['delete_connection_ids'] );
		$this->assertSame( 1, $result['planned']['delete_connections'] );
		$this->assertSame( [], $result['planned']['delete_chat_ids'] );
		$this->assertSame( 0, $result['planned']['delete_chats'] );
		$this->assertSame( [ 21 ], $result['preserved']['ambiguous_orphan_chat_ids'] );
		$this->assertSame( 1, $result['preserved']['ambiguous_orphan_chats'] );
		$this->assertSame( 0, $result['applied']['deleted_connections'] );
		$this->assertSame( 0, $result['applied']['deleted_chats'] );
		$this->assertSame( $connectionsBefore, $GLOBALS['wp_connection_rows'] );
		$this->assertSame( $metaBefore, $GLOBALS['wp_connection_meta_rows'] );
		$this->assertSame( $postsBefore, $GLOBALS['wp_posts'] );
		$this->assertSame( [], $GLOBALS['wp_deleted_posts'] );
	}

	public function testRepairApplyUsesThePlannedBrokenRelationsAndPreservesOrphanChats(): void {
		$this->seedRepairFixture();
		$plan = Maintenance::buildRepairPlan();

		$result = Maintenance::runRepair( Maintenance::REPAIR_MODE_APPLY );

		$this->assertSame( $plan['planned'], $result['planned'] );
		$this->assertSame( [ 21 ], $result['preserved']['ambiguous_orphan_chat_ids'] );
		$this->assertSame( 1, $result['applied']['deleted_connections'] );
		$this->assertSame( 0, $result['applied']['deleted_chats'] );
		$this->assertSame( [ 1, 3, 4 ], $this->connectionIDs() );
		$this->assertSame( [ 1, 3, 4 ], $this->connectionMetaIDs() );
		$this->assertArrayHasKey( 20, $GLOBALS['wp_posts'] );
		$this->assertArrayHasKey( 21, $GLOBALS['wp_posts'] );
		$this->assertSame( [], $GLOBALS['wp_deleted_posts'] );
	}

	public function testScheduledCleanupOnlyReportsRepairPlanWithoutDeletingData(): void {
		$this->seedRepairFixture();

		Maintenance::runScheduledCleanup();

		$this->assertSame( [ 1, 2, 3, 4 ], $this->connectionIDs() );
		$this->assertSame( [ 1, 2, 3, 4 ], $this->connectionMetaIDs() );
		$this->assertArrayHasKey( 21, $GLOBALS['wp_posts'] );
		$this->assertSame( [], $GLOBALS['wp_deleted_posts'] );
		$this->assertArrayNotHasKey( Maintenance::CLEANUP_LOCK_OPTION, $GLOBALS['wp_options'] );
	}

	public function testExplicitPluginPostDeletionCascadesOnlyOwnedRelationsAndMeta(): void {
		$this->seedConnectionTables();
		$this->seedPost( 10, Client::CPT_BOT );
		$this->seedPost( 11, Client::CPT_BOT );
		$this->seedPost( 20, Client::CPT_CHAT );
		$this->seedPost( 21, Client::CPT_CHAT );
		$this->seedPost( 30, Client::CPT_CHANNEL );
		$this->seedPost( 40, Client::CPT_CF7FORM );
		$this->seedConnection( 1, Client::BOT2CHAT, 10, 20 );
		$this->seedConnection( 2, Client::BOT2CHANNEL, 10, 30 );
		$this->seedConnection( 3, Client::CHAT2CHANNEL, 20, 30 );
		$this->seedConnection( 4, Client::FORM2CHANNEL, 40, 30 );
		$this->seedConnection( 5, Client::BOT2CHAT, 11, 21 );
		$this->seedConnection( 6, 'foreign-relation', 10, 999 );
		$this->seedConnectionMeta( 1, 1 );
		$this->seedConnectionMeta( 2, 2 );
		$this->seedConnectionMeta( 3, 3 );
		$this->seedConnectionMeta( 4, 4 );
		$this->seedConnectionMeta( 5, 5 );
		$this->seedConnectionMeta( 6, 6 );
		Maintenance::init();

		wp_delete_post( 10, true );

		$this->assertArrayNotHasKey( 10, $GLOBALS['wp_posts'] );
		$this->assertSame( [ 3, 4, 5, 6 ], $this->connectionIDs() );
		$this->assertSame( [ 3, 4, 5, 6 ], $this->connectionMetaIDs() );
	}

	private function seedRepairFixture(): void {
		$this->seedConnectionTables();
		$this->seedPost( 10, Client::CPT_BOT );
		$this->seedPost( 20, Client::CPT_CHAT );
		$this->seedPost( 21, Client::CPT_CHAT );
		$this->seedPost( 30, Client::CPT_CHANNEL );
		$this->seedConnection( 1, Client::BOT2CHAT, 10, 20 );
		$this->seedConnection( 2, Client::BOT2CHAT, 999, 21 );
		$this->seedConnection( 3, Client::CHAT2CHANNEL, 21, 30 );
		$this->seedConnection( 4, 'foreign-relation', 999, 21 );
		$this->seedConnectionMeta( 1, 1 );
		$this->seedConnectionMeta( 2, 2 );
		$this->seedConnectionMeta( 3, 3 );
		$this->seedConnectionMeta( 4, 4 );
	}

	private function seedConnectionTables(): void {
		$GLOBALS['wpdb']->tables = [
			'wp_post_connections_cf7_telegram',
			'wp_post_connections_meta_cf7_telegram',
		];
	}

	private function seedPost( int $postID, string $postType, string $postStatus = 'publish' ): void {
		$post = new WP_Post();
		$post->ID = $postID;
		$post->post_type = $postType;
		$post->post_status = $postStatus;

		$GLOBALS['wp_posts'][ $postID ] = $post;
		$GLOBALS['wp_posts_by_type'][ $postType ][] = $postID;
	}

	private function seedConnection( int $connectionID, string $relation, int $from, int $to ): void {
		$GLOBALS['wp_connection_rows'][] = (object) [
			'ID'       => $connectionID,
			'relation' => $relation,
			'from'     => $from,
			'to'       => $to,
		];
	}

	private function seedConnectionMeta( int $metaID, int $connectionID ): void {
		$GLOBALS['wp_connection_meta_rows'][] = (object) [
			'meta_id'       => $metaID,
			'connection_id' => $connectionID,
		];
	}

	private function connectionIDs(): array {
		$ids = array_map(
			static fn( object $row ): int => (int) $row->ID,
			$GLOBALS['wp_connection_rows']
		);
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	private function connectionMetaIDs(): array {
		$ids = array_map(
			static fn( object $row ): int => (int) $row->connection_id,
			$GLOBALS['wp_connection_meta_rows']
		);
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}
}
