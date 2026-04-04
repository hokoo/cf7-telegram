<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Controllers\Migration;

Migration::registerMigration(
	'0.7',
	function () {
		list( $old_version, $new_version ) = func_get_args();

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
