<?php
$migration_version = '0.7';

\iTRON\cf7Telegram\Controllers\Migration::registerMigration(
	$migration_version,
	function ( $old_version, $new_version ) use ( $migration_version ) {
		do_action('logger', ['Migration to 0.7', $migration_version, $old_version, $new_version ] );

		$chats = get_option( 'wpcf7_telegram_chats' );
		if ( ! empty( $chats ) && is_string( $chats ) ) :
			$list = explode( ',', $chats );
			$chats = array();

			foreach( $list as $item )
				$chats[ $item ] = array( 'id' => $item, 'status' => 'active', 'first_name' => '', 'last_name' => '' );

			update_option( 'wpcf7_telegram_chats', $chats, false );
		endif;
	}
);
