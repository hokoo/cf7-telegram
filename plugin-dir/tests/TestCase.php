<?php

declare( strict_types=1 );

class Cf7tg_AssertionFailed extends Exception {
}

if ( class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	abstract class Cf7tg_TestCase extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			cf7tg_test_reset_environment();
		}

		protected function cronEvents( string $hook ): array {
			return cf7tg_test_cron_events( $hook );
		}

		protected function countCronEventsBySchedule( string $hook, $schedule ): int {
			return count(
				array_filter(
					$this->cronEvents( $hook ),
					static fn( array $event ): bool => $schedule === ( $event['schedule'] ?? null )
				)
			);
		}
	}
} else {
	abstract class Cf7tg_TestCase {
		protected function setUp(): void {
			cf7tg_test_reset_environment();
		}

		protected function tearDown(): void {
		}

		protected function assertTrue( $actual, string $message = '' ): void {
			if ( true !== $actual ) {
				throw new Cf7tg_AssertionFailed( $message ?: 'Failed asserting that value is true.' );
			}
		}

		protected function assertFalse( $actual, string $message = '' ): void {
			if ( false !== $actual ) {
				throw new Cf7tg_AssertionFailed( $message ?: 'Failed asserting that value is false.' );
			}
		}

		protected function assertSame( $expected, $actual, string $message = '' ): void {
			if ( $expected !== $actual ) {
				throw new Cf7tg_AssertionFailed(
					$message ?: sprintf( 'Failed asserting that %s is identical to %s.', var_export( $actual, true ), var_export( $expected, true ) )
				);
			}
		}

		protected function assertArrayHasKey( $key, array $array, string $message = '' ): void {
			if ( ! array_key_exists( $key, $array ) ) {
				throw new Cf7tg_AssertionFailed( $message ?: sprintf( 'Failed asserting that array has key %s.', (string) $key ) );
			}
		}

		protected function assertArrayNotHasKey( $key, array $array, string $message = '' ): void {
			if ( array_key_exists( $key, $array ) ) {
				throw new Cf7tg_AssertionFailed( $message ?: sprintf( 'Failed asserting that array does not have key %s.', (string) $key ) );
			}
		}

		protected function cronEvents( string $hook ): array {
			return cf7tg_test_cron_events( $hook );
		}

		protected function countCronEventsBySchedule( string $hook, $schedule ): int {
			return count(
				array_filter(
					$this->cronEvents( $hook ),
					static fn( array $event ): bool => $schedule === ( $event['schedule'] ?? null )
				)
			);
		}
	}
}
