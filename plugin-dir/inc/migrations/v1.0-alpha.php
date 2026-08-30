<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Migrations\LegacyImporter;

Migration::registerMigration(
	'1.0-alpha',
	function ( $old_version, $new_version, array $context = [] ) {
		( new LegacyImporter() )->import( $context );
	}
);
