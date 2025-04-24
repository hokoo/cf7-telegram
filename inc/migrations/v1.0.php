<?php

use iTRON\cf7Telegram\Controllers\Migration;
use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Chat;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Form;
use iTRON\wpConnections\Query\Connection;

Migration::registerMigration(
	'1.0',
	function () {
		list( $old_version, $new_version, $upgrader ) = func_get_args();

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
		$forms = $query->have_posts() ? $query->posts : [];

		// Try to load chats.
		$chats = get_option( 'wpcf7_telegram_chats' );
		$chats = empty( $chats ) ? [] : (array) $chats;

		// Create a new bot.
		$bot = new Bot();
		$bot->setToken( $token );
		$bot->setLastUpdateID( get_option( 'wpcf7_telegram_last_update_id' ) );
		$bot->savePost();

		// Create a channel.
		$channel = new Channel();
		$channel->connectBot( $bot );
		$channel->setTitle( __( 'Channel Name', 'cf7-telegram' ) );

		// Create chats.
		foreach ( $chats as $legacy_chat ) {
			$chat = new Chat();
			$chat->setChatID( $legacy_chat['id'] );
			$chat->setChatType( ( (int) $legacy_chat['id'] ) > 0 ? 'private' : 'group' );
			$chat->setFirstName( $legacy_chat['first_name'] ?? '' );
			$chat->setLastName( $legacy_chat['last_name'] ?? '' );
			$chat->setUsername( $legacy_chat['username'] ?? '' );
			$chat->setTitle( '' );
			$chat->setTitle( $chat->getName() );
			$chat->savePost();

			Client::getInstance()->getBot2ChatRelation()->createConnection(
				new Connection( $bot->getPost()->ID, $chat->getPost()->ID )
			);

			if ( isset( $legacy_chat['status'] ) && 'pending' === $legacy_chat['status'] ) {
				$chat->setPending( $bot );
			} else {
				$chat->setActivated( $bot );
			}

			$chat->connectChannel( $channel );
		}

		foreach ( $forms as $cf7_form_id ) {
			$channel->connectForm( new Form( $cf7_form_id ) );
		}

		// Removing the [telegram] shortcode from the forms.
		foreach ( $forms as $cf7_form_id ) {
			$form = new Form( $cf7_form_id );
			$form->getPost()->post_content = preg_replace(
				'/\[telegram\]/',
				'',
				$form->getPost()->post_content
			);
			$form->savePost();
		}
	}
);
