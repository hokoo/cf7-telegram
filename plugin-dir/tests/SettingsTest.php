<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Controllers\CPT;
use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Settings;

final class SettingsTest extends Cf7tg_TestCase {
	public function testFailedMigrationStateExposesRetryAndLastError(): void {
		$this->seedFailedMigrationState();

		$data = Settings::getMigrationUiData();

		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( true, $data['can_retry'] );
		$this->assertSame( true, $data['is_failed'] );
		$this->assertSame( 'migration_failed', $data['last_error']['category'] );
		$this->assertSame( '1.0-alpha', $data['last_error']['step'] );
		$this->assertSame( 'A migration step could not be completed.', $data['last_error']['message'] );
		$this->assertSame( true, Settings::shouldShowMigrationActionButton() );
	}

	public function testCompletedMigrationStateHidesRecovery(): void {
		update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_TEST_TOKEN', false );
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => Migration::STATUS_COMPLETED,
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
				'attempts'       => 1,
				'steps'          => [
					'1.0-alpha' => [
						'status'   => Migration::STATUS_COMPLETED,
						'attempts' => 1,
					],
				],
				'errors'         => [],
			],
			false
		);

		$data = Settings::getMigrationUiData();

		$this->assertSame( false, $data['can_retry'] );
		$this->assertSame( true, $data['is_completed'] );
		$this->assertSame( false, Settings::shouldShowMigrationActionButton() );
	}

	public function testRequestMigrationRetrySchedulesFromFailedStateWithoutWritingSuccessFlag(): void {
		$this->seedFailedMigrationState();

		$this->assertSame( true, Settings::requestMigrationRetry() );

		$events = $this->cronEvents( Migration::MIGRATION_HOOK );
		$this->assertSame( 1, count( $events ) );
		$this->assertSame( [ 'admin_retry', '0.0', WPCF7TG_VERSION ], $events[0]['args'] );
		$this->assertSame( false, get_option( Migration::FIX_1_0_FLAG ) );

		$state = Migration::getMigrationState();
		$this->assertSame( Migration::STATUS_SCHEDULED, $state['status'] );
		$this->assertSame( 2, $state['attempts'] );
		$this->assertSame( 'admin_retry', $state['reason'] );
	}

	public function testScheduledMigrationCannotBeEnqueuedAgain(): void {
		$this->seedFailedMigrationState();
		$this->assertSame( true, Settings::requestMigrationRetry() );

		$this->assertSame( false, Settings::requestMigrationRetry() );
		$this->assertSame( 1, count( $this->cronEvents( Migration::MIGRATION_HOOK ) ) );
	}

	public function testStaleScheduledStateWithoutCronEventExposesRetryAndRecreatesEvent(): void {
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => Migration::STATUS_SCHEDULED,
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
				'attempts'       => 0,
				'steps'          => [],
				'errors'         => [],
			],
			false
		);

		$data = Settings::getMigrationUiData();

		$this->assertSame( true, $data['can_retry'] );
		$this->assertSame( false, $data['is_scheduled'] );
		$this->assertSame( true, Settings::requestMigrationRetry() );
		$this->assertSame( 1, count( $this->cronEvents( Migration::MIGRATION_HOOK ) ) );
		$this->assertSame( false, Settings::requestMigrationRetry() );
		$this->assertSame( 1, count( $this->cronEvents( Migration::MIGRATION_HOOK ) ) );
	}

	public function testRunningMigrationCannotBeEnqueuedAgain(): void {
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => Migration::STATUS_RUNNING,
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
				'attempts'       => 1,
				'steps'          => [],
				'errors'         => [],
			],
			false
		);

		$this->assertSame( false, Settings::requestMigrationRetry() );
		$this->assertSame( [], $this->cronEvents( Migration::MIGRATION_HOOK ) );
	}

	public function testSettingsActionCapabilityUsesCf7Capability(): void {
		CPT::get_instance()->cf7_orig_capabilities['edit_posts'] = 'edit_posts';

		$this->assertSame( 'edit_posts', Settings::getCaps() );
	}

	private function seedFailedMigrationState(): void {
		update_option(
			Migration::STATE_OPTION,
			[
				'schema'         => Migration::STATE_SCHEMA,
				'status'         => Migration::STATUS_FAILED,
				'reason'         => 'self_heal',
				'source_version' => '0.0',
				'target_version' => WPCF7TG_VERSION,
				'attempts'       => 2,
				'current_step'   => '1.0-alpha',
				'steps'          => [
					'1.0-alpha' => [
						'status'     => Migration::STATUS_FAILED,
						'attempts'   => 1,
						'last_error' => [
							'message' => 'Synthetic failure.',
						],
					],
				],
				'errors'         => [
					[
						'step'    => '1.0-alpha',
						'message' => 'Synthetic failure.',
						'code'    => '100',
						'time'    => 123,
					],
				],
			],
			false
		);
	}
}
