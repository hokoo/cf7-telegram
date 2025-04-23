<?php
$migration_version = '1.0';

\iTRON\cf7Telegram\Controllers\Migration::registerMigration(
	$migration_version,
	function ( $old_version, $new_version ) use ( $migration_version ) {
		do_action('logger', ['Migration to 1.0', $migration_version, $old_version, $new_version ] );

		// Try to load a single token.
		$const = defined( 'WPFC7TG_BOT_TOKEN' ) ? WPFC7TG_BOT_TOKEN : false;
		$db = get_option( 'wpcf7_telegram_tkn' ) ?: false;

		$token = $const ?: $db;

		if ( ! $token ) {
			// No token found, do nothing.
			return;
		}

		// Find Contact Form 7 forms with the shortcode [telegram].
		$query = new WP_Query( [ 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => -1, 's' => '[telegram]', 'fields' => 'ids' ] );

		// Try to load chats.
		$chats = get_option( 'wpcf7_telegram_chats' );

		$migration_data = [
			'bot' => $token,
			'forms' => $query->have_posts() ? $query->posts : [],
			'chats' => empty( $chats ) ? [] : (array) $chats,
		];

		// Schedule the migration.
//		wp_schedule_single_event( time(), 'cf7tg_migrate', $migration_data );

	}
);
