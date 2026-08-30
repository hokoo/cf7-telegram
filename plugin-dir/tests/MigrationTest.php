<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Controllers\Migration;

final class MigrationTest extends Cf7tg_TestCase {
	public function testVerifyUpgradingSchedulesMigrationForSinglePluginUpdate(): void {
		$upgrader = new stdClass();
		update_option( 'cf7tg_version', '0.10.2', false );

		Migration::getInstance()->verifyUpgrading(
			$upgrader,
			[
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => WPCF7TG_PLUGIN_NAME,
			]
		);

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );

		$this->assertSame( 1, count( $events ) );
		$this->assertSame( false, $events[0]['schedule'] );
		$this->assertSame( [ 'plugin_update', '0.10.2', WPCF7TG_VERSION ], $events[0]['args'] );
		$this->assertFalse( $this->containsObject( $events[0]['args'] ) );

		$state = Migration::getMigrationState();
		$this->assertSame( 'scheduled', $state['status'] );
		$this->assertSame( 'plugin_update', $state['reason'] );
		$this->assertSame( '0.10.2', $state['source_version'] );
		$this->assertSame( WPCF7TG_VERSION, $state['target_version'] );
	}

	public function testVerifyUpgradingSchedulesMigrationForBulkPluginUpdate(): void {
		$upgrader = new stdClass();
		update_option( 'cf7tg_version', '0.11', false );

		Migration::getInstance()->verifyUpgrading(
			$upgrader,
			[
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => [
					'akismet/akismet.php',
					WPCF7TG_PLUGIN_NAME,
				],
			]
		);

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );

		$this->assertSame( 1, count( $events ) );
		$this->assertSame( [ 'plugin_update', '0.11', WPCF7TG_VERSION ], $events[0]['args'] );
		$this->assertFalse( $this->containsObject( $events[0]['args'] ) );
	}

	public function testVerifyUpgradingDoesNotDuplicateMigrationForSameUpdateRequest(): void {
		$upgrader = new stdClass();
		$hook_extra = [
			'action' => 'update',
			'type'   => 'plugin',
			'plugin' => WPCF7TG_PLUGIN_NAME,
		];

		Migration::getInstance()->verifyUpgrading( $upgrader, $hook_extra );
		Migration::getInstance()->verifyUpgrading( $upgrader, $hook_extra );

		$this->assertSame( 1, count( $this->cronEvents( Migration::MIGRATION_HOOK ) ) );
	}

	public function testVerifyUpgradingIgnoresUnrelatedUpdates(): void {
		$upgrader = new stdClass();

		Migration::getInstance()->verifyUpgrading(
			$upgrader,
			[
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'akismet/akismet.php',
			]
		);
		Migration::getInstance()->verifyUpgrading(
			$upgrader,
			[
				'action' => 'install',
				'type'   => 'plugin',
				'plugin' => WPCF7TG_PLUGIN_NAME,
			]
		);
		Migration::getInstance()->verifyUpgrading(
			$upgrader,
			[
				'action' => 'update',
				'type'   => 'theme',
				'plugin' => WPCF7TG_PLUGIN_NAME,
			]
		);

		$this->assertSame( [], $this->cronEvents( Migration::MIGRATION_HOOK ) );
	}

	public function testEnsureLegacyMigrationScheduledBackfillsMissingUpgradeEvent(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		update_option( 'wpcf7_telegram_chats', [], false );
		update_option( 'cf7tg_version', WPCF7TG_VERSION, false );

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );

		$this->assertSame( 1, count( $events ) );
		$this->assertSame( [ 'self_heal', '0.0', WPCF7TG_VERSION ], $events[0]['args'] );
		$this->assertFalse( $this->containsObject( $events[0]['args'] ) );
		$this->assertSame( false, get_option( Migration::FIX_1_0_FLAG ) );
	}

	public function testEnsureLegacyMigrationScheduledDoesNotScheduleWithoutLegacyState(): void {
		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$this->assertSame( [], $this->cronEvents( Migration::MIGRATION_HOOK ) );
	}

	public function testEnsureLegacyMigrationScheduledBackfillsDurableStateForExistingModernInstall(): void {
		update_option( 'cf7tg_version', '1.0.0', false );

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );
		$this->assertSame( 1, count( $events ) );
		$this->assertSame( [ 'self_heal', '1.0.0', WPCF7TG_VERSION ], $events[0]['args'] );
	}

	public function testEnsureLegacyMigrationScheduledDoesNotDuplicateExistingMigrationEvent(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		wp_schedule_single_event( time(), Migration::MIGRATION_HOOK, [ 'self_heal', '0.0', WPCF7TG_VERSION ] );

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$this->assertSame( 1, count( $this->cronEvents( Migration::MIGRATION_HOOK ) ) );
	}

	public function testEnsureLegacyMigrationScheduledSkipsDurableCompletedMigration(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		update_option( 'cf7tg_migration_1.0-alpha', [ 'old_version' => '0.0', 'new_version' => WPCF7TG_VERSION ], false );
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => 'completed',
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
			],
			false
		);

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$this->assertSame( [], $this->cronEvents( Migration::MIGRATION_HOOK ) );
	}

	public function testHistoricalCompletionMarkerDoesNotSuppressLegacyRepair(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		update_option( 'cf7tg_migration_1.0-alpha', [ 'old_version' => '0.0', 'new_version' => WPCF7TG_VERSION ], false );

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );
		$this->assertSame( 1, count( $events ) );
		$this->assertSame( [ 'self_heal', '0.0', WPCF7TG_VERSION ], $events[0]['args'] );
	}

	public function testLegacyObjectCronContextForcesRepairSourceVersion(): void {
		update_option( 'wpcf7_telegram_last_update_id', 100, false );

		$method = new ReflectionMethod( Migration::class, 'normalizeMigrationContext' );
		$method->setAccessible( true );
		$context = $method->invoke( null, [ new stdClass(), WPCF7TG_VERSION ] );

		$this->assertSame( 'legacy_cron', $context['reason'] );
		$this->assertSame( '0.0', $context['source_version'] );
		$this->assertSame( WPCF7TG_VERSION, $context['target_version'] );
	}

	public function testLegacyEvidenceForcesRepairSourceVersionBelowOneAlpha(): void {
		update_option( 'cf7tg_version', WPCF7TG_VERSION, false );
		update_option( 'wpcf7_telegram_last_update_id', 100, false );

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );

		$this->assertSame( 1, count( $events ) );
		$this->assertSame( '0.0', $events[0]['args'][1] );
	}

	public function testStaleMigrationLockIsRecoveredAndRepairIsScheduled(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		add_option(
			Migration::LOCK_OPTION,
			[
				'token'     => 'stale',
				'locked_at' => time() - Migration::LOCK_TTL - 1,
			],
			'',
			false
		);
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => 'running',
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
				'attempts'       => 1,
				'current_step'   => '1.0-alpha',
				'steps'          => [],
				'errors'         => [],
				'lock'           => [
					'token'     => 'stale',
					'locked_at' => time() - Migration::LOCK_TTL - 1,
				],
			],
			false
		);

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$this->assertSame( false, get_option( Migration::LOCK_OPTION ) );
		$this->assertSame( 1, count( $this->cronEvents( Migration::MIGRATION_HOOK ) ) );
		$this->assertSame( 'scheduled', Migration::getMigrationState()['status'] );
	}

	public function testActiveMigrationLockDefersSelfHealScheduling(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		add_option(
			Migration::LOCK_OPTION,
			[
				'token'     => 'active',
				'locked_at' => time(),
			],
			'',
			false
		);

		Migration::getInstance()->ensureLegacyMigrationScheduled();

		$this->assertSame( [], $this->cronEvents( Migration::MIGRATION_HOOK ) );
	}

	public function testRunnerDoesNotReleaseLockOwnedByAnotherProcess(): void {
		$migration = Migration::getInstance();
		$acquire = new ReflectionMethod( Migration::class, 'acquireMigrationLock' );
		$acquire->setAccessible( true );
		$release = new ReflectionMethod( Migration::class, 'releaseMigrationLock' );
		$release->setAccessible( true );

		$this->assertTrue( $acquire->invoke( $migration ) );
		update_option(
			Migration::LOCK_OPTION,
			[
				'token'     => 'replacement-owner',
				'locked_at' => time(),
			],
			false
		);

		$release->invoke( $migration );

		$this->assertSame( 'replacement-owner', get_option( Migration::LOCK_OPTION )['token'] );
	}

	public function testFailedMigrationStepDoesNotCompleteAndRetrySkipsCompletedSteps(): void {
		$firstRuns = 0;
		$secondRuns = 0;
		$secondShouldFail = true;

		Migration::registerMigration(
			'0.1',
			static function () use ( &$firstRuns ): void {
				$firstRuns++;
			}
		);

		Migration::registerMigration(
			'0.2',
			static function () use ( &$secondRuns, &$secondShouldFail ): void {
				$secondRuns++;
				if ( $secondShouldFail ) {
					$secondShouldFail = false;
					throw new RuntimeException( 'Synthetic migration failure.' );
				}
			}
		);

		Migration::getInstance()->migrate( [], '0.0', WPCF7TG_VERSION );

		$state = Migration::getMigrationState();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 1, $firstRuns );
		$this->assertSame( 1, $secondRuns );
		$this->assertSame( 'completed', $state['steps']['0.1']['status'] );
		$this->assertSame( 'failed', $state['steps']['0.2']['status'] );
		$this->assertSame( false, get_option( 'cf7tg_migration_0.2' ) );
		$this->assertSame( false, get_option( 'cf7tg_version' ) );

		Migration::getInstance()->migrate( [], '0.0', WPCF7TG_VERSION );

		$state = Migration::getMigrationState();
		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 1, $firstRuns );
		$this->assertSame( 2, $secondRuns );
		$this->assertSame( WPCF7TG_VERSION, get_option( 'cf7tg_version' ) );
		$this->assertSame( true, get_option( Migration::FIX_1_0_FLAG ) );

		Migration::getInstance()->migrate( [], '0.0', WPCF7TG_VERSION );

		$this->assertSame( 1, $firstRuns );
		$this->assertSame( 2, $secondRuns );
	}

	private function containsObject( array $value ): bool {
		foreach ( $value as $item ) {
			if ( is_object( $item ) ) {
				return true;
			}

			if ( is_array( $item ) && $this->containsObject( $item ) ) {
				return true;
			}
		}

		return false;
	}
}
