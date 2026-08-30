<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Compatibility;

final class CompatibilityTest extends Cf7tg_TestCase {
	public function testTelegramFormTagIsRegisteredAsNoOutputCompatibilityTag(): void {
		Compatibility::init();

		do_action( 'wpcf7_init' );

		$this->assertArrayHasKey( 'telegram', $GLOBALS['wpcf7_form_tags'] );
		$this->assertSame( [ 'display-block' => true ], $GLOBALS['wpcf7_form_tags']['telegram']['features'] );
		$this->assertSame( '', call_user_func( $GLOBALS['wpcf7_form_tags']['telegram']['callback'] ) );
	}

	public function testTelegramTagRegistrationNoOpsWhenCf7ApiIsMissing(): void {
		$this->assertSame( '', Compatibility::renderTelegramTag() );
	}
}
