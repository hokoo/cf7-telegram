<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Bot;
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
}
