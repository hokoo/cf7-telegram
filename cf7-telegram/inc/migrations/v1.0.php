<?php
$migration_version = '1.0';

\iTRON\cf7Telegram\Controllers\Migration::registerMigration(
	$migration_version,
	function ( $old_version, $new_version ) use ( $migration_version ) {
		do_action('logger', ['Migration to 1.0', $migration_version, $old_version, $new_version ] );
	}
);
