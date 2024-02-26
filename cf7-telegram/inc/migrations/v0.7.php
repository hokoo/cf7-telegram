<?php

$migration_version = '0.7';

add_action( 'cf7_telegram_migrations', function ( $old_version, $new_version ) use ( $migration_version ) {
	\iTRON\cf7Telegram\Controllers\Migration::invokeMigration( $old_version,
		$new_version,
		$migration_version,
		function () {
			// Your migration code here
		} );
}, (int) $migration_version * 10, 2 );
