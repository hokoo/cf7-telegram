<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Abilities;
use iTRON\cf7Telegram\Bot;
use iTRON\cf7Telegram\BotToken;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\Controllers\CPT;

final class AbilitiesTest extends Cf7tg_TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_registered_post_types'] = [ Client::CPT_CF7FORM, Client::CPT_CHANNEL, Client::CPT_BOT, Client::CPT_CHAT ];
		CPT::get_instance()->cf7_orig_capabilities = [ 'edit_posts' => 'edit_posts' ];
		Client::getInstance()->init();
		do_action( 'init' );
	}

	private function post( string $type, string $title, array $params = [], string $status = 'publish' ): int {
		return (int) wp_insert_post( [ 'post_type' => $type, 'post_status' => $status, 'post_title' => $title,
			'post_content_filtered' => json_encode( $params ) ] );
	}

	private function edge( string $relation, int $from, int $to, ?string $status = null ): void {
		$id = $GLOBALS['wp_next_connection_id']++;
		$GLOBALS['wp_connection_rows'][] = (object) [ 'ID' => $id, 'relation' => $relation, 'from' => $from, 'to' => $to, 'order' => 0, 'title' => '' ];
		if ( null !== $status ) {
			$GLOBALS['wp_connection_meta_rows'][] = (object) [ 'meta_id' => $GLOBALS['wp_next_connection_meta_id']++,
				'connection_id' => $id, 'meta_key' => 'status', 'meta_value' => $status ];
		}
	}

	private function read( string $resource, array $extra = [] ) {
		return Abilities::getConfiguration( array_merge( [ 'resource' => $resource ], $extra ) );
	}

	public function testRegistrationAndSchemasAreStrict(): void {
		Abilities::init();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( [ 'cf7-telegram/get-configuration' ], array_keys( $GLOBALS['wp_abilities'] ) );
		$this->assertArrayHasKey( 'cf7-telegram', $GLOBALS['wp_ability_categories'] );
		$ability = $GLOBALS['wp_abilities']['cf7-telegram/get-configuration'];
		$this->assertSame( 'cf7-telegram', $ability['category'] );
		$this->assertSame( [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ], $ability['meta']['annotations'] );
		$this->assertSame( true, $ability['meta']['public'] );
		$this->assertSame( true, $ability['meta']['show_in_rest'] );
		$this->assertSame( true, $ability['meta']['mcp']['public'] );
		$this->assertSame( false, $ability['input_schema']['additionalProperties'] );
		$this->assertSame( [ 'resource' ], $ability['input_schema']['required'] );
		$this->assertSame( 100, $ability['input_schema']['properties']['per_page']['maximum'] );
		$this->assertSame( false, $ability['output_schema']['additionalProperties'] );
		$this->assertSame( 4, count( $ability['output_schema']['properties']['items']['items']['oneOf'] ) );
		foreach ( $ability['output_schema']['properties']['items']['items']['oneOf'] as $variant ) {
			$this->assertSame( false, $variant['additionalProperties'] );
			$this->assertFalse( isset( $variant['properties']['revision'] ) );
		}
		$this->assertSame( 'cf7tg_invalid_input', $this->read( 'forms', [ 'extra' => 1 ] )->get_error_code() );
		$this->assertSame( 'cf7tg_invalid_input', Abilities::getConfiguration( [ 'resource' => [] ] )->get_error_code() );
		$this->assertSame( 'cf7tg_invalid_input', $this->read( 'forms', [ 'page' => '1' ] )->get_error_code() );
		$this->assertSame( 'cf7tg_invalid_input', $this->read( 'forms', [ 'page' => null ] )->get_error_code() );
		$this->assertSame( 'cf7tg_invalid_input', $this->read( 'forms', [ 'per_page' => 101 ] )->get_error_code() );
		$this->assertSame( [], $this->read( 'forms', [ 'page' => PHP_INT_MAX ] )['items'] );
	}

	public function testEarlyReadAndPermissions(): void {
		$GLOBALS['wp_did_actions']['init'] = 0;
		$this->assertSame( 'cf7tg_not_ready', Abilities::permission()->get_error_code() );
		$this->assertSame( 'cf7tg_not_ready', $this->read( 'forms' )->get_error_code() );
		$GLOBALS['wp_did_actions']['init'] = 1;
		$GLOBALS['wp_user_logged_in'] = false;
		$this->assertSame( 'cf7tg_forbidden', Abilities::permission()->get_error_code() );
		$GLOBALS['wp_user_logged_in'] = true;
		$GLOBALS['current_user_can'] = false;
		$this->assertSame( 'cf7tg_forbidden', $this->read( 'forms' )->get_error_code() );
		$GLOBALS['current_user_can'] = true;
		$GLOBALS['wp_registered_post_types'] = [];
		$this->assertSame( 'cf7tg_not_ready', $this->read( 'forms' )->get_error_code() );
	}

	public function testReadDoesNotInitializeMissingConnectionsClient(): void {
		$script = 'require ' . var_export( __DIR__ . '/bootstrap.php', true ) . '; '
			. '$GLOBALS["wp_registered_post_types"] = ["wpcf7_contact_form", "cf7tg_channel", "cf7tg_bot", "cf7tg_chat"]; '
			. '\iTRON\cf7Telegram\Controllers\CPT::get_instance()->cf7_orig_capabilities = ["edit_posts" => "edit_posts"]; '
			. 'do_action("init"); $result = \iTRON\cf7Telegram\Abilities::permission(); '
			. 'exit($result instanceof WP_Error && $result->get_error_code() === "cf7tg_not_ready" && !\iTRON\cf7Telegram\Client::hasInitializedConnections() ? 0 : 1);';
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ), $output, $status );
		$this->assertSame( 0, $status );
	}

	public function testFourResourcesAndBrokenOrHiddenRelations(): void {
		$form = $this->post( Client::CPT_CF7FORM, 'Form' );
		$this->post( Client::CPT_CF7FORM, 'Unlinked form' );
		$bridge = $this->post( Client::CPT_CHANNEL, 'Bridge' );
		$this->post( Client::CPT_CHANNEL, 'Unlinked bridge' );
		$bot = $this->post( Client::CPT_BOT, 'Bot', [ 'lastStatus' => 'online', 'token' => '12345:secret' ] );
		$recipient = $this->post( Client::CPT_CHAT, 'Chat', [ 'chatID' => '-100123456789', 'chatType' => 'supergroup', 'messageThreadID' => 22 ] );
		$otherTopic = $this->post( Client::CPT_CHAT, 'Other topic', [ 'chatID' => '-100123456789', 'chatType' => 'supergroup', 'messageThreadID' => 23 ] );
		$this->post( Client::CPT_CHAT, '-100999999999', [ 'chatID' => '-100999999999', 'chatType' => 'supergroup', 'messageThreadID' => 1 ] );
		$this->edge( Client::FORM2CHANNEL, $form, $bridge );
		$this->edge( Client::BOT2CHANNEL, $bot, $bridge );
		$this->edge( Client::CHAT2CHANNEL, $recipient, $bridge );
		$this->edge( Client::BOT2CHAT, $bot, $recipient, 'active' );
		$this->edge( Client::CHAT2CHANNEL, $recipient, 999999 );
		$forms = $this->read( 'forms' );
		$this->assertSame( 2, $forms['total'] );
		$this->assertSame( [ $bridge ], $forms['items'][0]['bridge_ids'] );
		$this->assertSame( [], $forms['items'][1]['bridge_ids'] );
		$bridges = $this->read( 'bridges' );
		$this->assertSame( $bot, $bridges['items'][0]['bot_id'] );
		$this->assertSame( [ $form ], $bridges['items'][0]['form_ids'] );
		$this->assertSame( [ $recipient ], $bridges['items'][0]['recipient_ids'] );
		$this->assertFalse( isset( $bridges['items'][0]['revision'] ) );
		$this->assertSame( null, $bridges['items'][1]['bot_id'] );
		$bots = $this->read( 'bots' );
		$this->assertSame( true, $bots['items'][0]['token_configured'] );
		$this->assertSame( 'online', $bots['items'][0]['cached_status'] );
		$recipients = $this->read( 'recipients' );
		$this->assertSame( 'forum_topic', $recipients['items'][0]['destination_kind'] );
		$this->assertSame( 22, $recipients['items'][0]['message_thread_id'] );
		$this->assertSame( [ [ 'bot_id' => $bot, 'status' => 'active' ] ], $recipients['items'][0]['bot_connections'] );
		$this->assertSame( false, $recipients['items'][0]['relations_complete'] );
		$this->assertSame( $otherTopic, $recipients['items'][1]['id'] );
		$this->assertSame( 23, $recipients['items'][1]['message_thread_id'] );
		$this->assertSame( 'default', $recipients['items'][2]['destination_kind'] );
		$this->assertSame( 'Recipient #' . $recipients['items'][2]['id'], $recipients['items'][2]['label'] );
		$hiddenRecipient = $this->post( Client::CPT_CHAT, 'Hidden', [ 'chatID' => '12345678', 'chatType' => 'private' ] );
		$this->edge( Client::CHAT2CHANNEL, $hiddenRecipient, $bridge );
		$GLOBALS['current_user_can_callback'] = static fn( string $capability, ...$args ): bool => 'read_post' !== $capability || ( $args[0] ?? 0 ) !== $hiddenRecipient;
		$restrictedBridge = $this->read( 'bridges' )['items'][0];
		$this->assertSame( [ $recipient ], $restrictedBridge['recipient_ids'] );
		$this->assertSame( false, $restrictedBridge['relations_complete'] );
	}

	public function testPermissionsAreAppliedBeforePaginationAndConnections(): void {
		$hidden = [];
		for ( $i = 0; $i < 130; $i++ ) {
			$id = $this->post( Client::CPT_CF7FORM, 'Form ' . $i );
			if ( $i % 7 === 0 ) $hidden[] = $id;
		}
		$bridge = $this->post( Client::CPT_CHANNEL, 'Hidden bridge' );
		$visibleForm = 2;
		$this->edge( Client::FORM2CHANNEL, $visibleForm, $bridge );
		$hidden[] = $bridge;
		$GLOBALS['current_user_can_callback'] = static function ( string $capability, ...$args ) use ( $hidden ): bool {
			return 'read_post' !== $capability || ! in_array( $args[0] ?? 0, $hidden, true );
		};
		$visible = array_values( array_diff( array_keys( $GLOBALS['wp_posts'] ), $hidden ) );
		$page1 = $this->read( 'forms', [ 'per_page' => 50 ] );
		$page2 = $this->read( 'forms', [ 'page' => 2, 'per_page' => 50 ] );
		$page3 = $this->read( 'forms', [ 'page' => 3, 'per_page' => 50 ] );
		$page4 = $this->read( 'forms', [ 'page' => 4, 'per_page' => 50 ] );
		$this->assertSame( count( $visible ), $page1['total'] );
		$this->assertTrue( count( $visible ) > 100 );
		$this->assertSame( 3, $page1['total_pages'] );
		$this->assertSame( array_slice( $visible, 0, 50 ), array_column( $page1['items'], 'id' ) );
		$this->assertSame( array_slice( $visible, 50, 50 ), array_column( $page2['items'], 'id' ) );
		$this->assertSame( array_slice( $visible, 100 ), array_column( $page3['items'], 'id' ) );
		$this->assertSame( [], $page4['items'] );
		$this->assertSame( [], $page1['items'][0]['bridge_ids'] );
		$this->assertSame( false, $page1['items'][0]['relations_complete'] );
	}

	public function testMalformedGraphAndDestinationRemainIncomplete(): void {
		$bridge = $this->post( Client::CPT_CHANNEL, 'Bridge' );
		$bot1 = $this->post( Client::CPT_BOT, 'Bot 1' );
		$bot2 = $this->post( Client::CPT_BOT, 'Bot 2' );
		$bad = $this->post( Client::CPT_CHAT, 'Bad', [ 'chatID' => '-100123456789', 'chatType' => 'private', 'messageThreadID' => 22 ] );
		$badBridge = $this->post( Client::CPT_CHANNEL, 'Bad bot bridge' );
		$this->edge( Client::BOT2CHANNEL, $bot1, $bridge );
		$this->edge( Client::BOT2CHANNEL, $bot2, $bridge );
		$this->edge( Client::CHAT2CHANNEL, $bad, $bridge );
		$this->edge( Client::FORM2CHANNEL, $bad, $bridge );
		$this->edge( Client::BOT2CHANNEL, $bad, $badBridge );
		$this->edge( Client::BOT2CHAT, $bot1, $bad, 'active' );
		$GLOBALS['wp_connection_meta_rows'][] = (object) [ 'meta_id' => $GLOBALS['wp_next_connection_meta_id']++,
			'connection_id' => $GLOBALS['wp_next_connection_id'] - 1, 'meta_key' => 'status', 'meta_value' => 'muted' ];
		$this->edge( Client::BOT2CHAT, $bot2, $bad, 'odd' );
		$bridgeItem = $this->read( 'bridges' )['items'][0];
		$this->assertSame( null, $bridgeItem['bot_id'] );
		$this->assertSame( [], $bridgeItem['form_ids'] );
		$this->assertSame( false, $bridgeItem['relations_complete'] );
		$badBotBridge = $this->read( 'bridges' )['items'][1];
		$this->assertSame( null, $badBotBridge['bot_id'] );
		$this->assertSame( false, $badBotBridge['relations_complete'] );
		$recipientItem = $this->read( 'recipients' )['items'][0];
		$this->assertSame( 'invalid', $recipientItem['destination_kind'] );
		$this->assertSame( 22, $recipientItem['message_thread_id'] );
		$this->assertSame( 'unknown', $recipientItem['bot_connections'][0]['status'] );
		$this->assertSame( 'unknown', $recipientItem['bot_connections'][1]['status'] );
	}

	public function testConstantPrecedenceUsesProductionRulesInIsolation(): void {
		$script = 'require ' . var_export( __DIR__ . '/bootstrap.php', true ) . '; '
			. '\iTRON\cf7Telegram\Client::getInstance()->init(); do_action("init"); '
			. '$GLOBALS["wp_registered_post_types"] = ["wpcf7_contact_form", "cf7tg_channel", "cf7tg_bot", "cf7tg_chat"]; '
			. '\iTRON\cf7Telegram\Controllers\CPT::get_instance()->cf7_orig_capabilities = ["edit_posts" => "edit_posts"]; '
			. '$id = wp_insert_post(["post_type" => "cf7tg_bot", "post_status" => "publish", "post_title" => "Bot", '
			. '"post_content_filtered" => json_encode(["tokenSource" => "legacy_global_constant", "token" => "[empty]"])]); '
			. '$legacy = "123456789:LEGACYTOKEN12345678901234567890"; $scoped = "123456789:SCOPEDTOKEN12345678901234567890"; '
			. 'define("WPFC7TG_BOT_TOKEN", $legacy); define(sprintf("WPFC7TG_BOT_TOKEN__%d", $id), $scoped); '
			. '$bot = new \iTRON\cf7Telegram\Bot($id); $read = \iTRON\cf7Telegram\Abilities::getConfiguration(["resource" => "bots"]); '
			. 'exit($bot->getToken() === $legacy && $bot->getTokenConstName() === "WPFC7TG_BOT_TOKEN" '
			. '&& \iTRON\cf7Telegram\BotToken::value($id, null, "saved") === $scoped '
			. '&& \iTRON\cf7Telegram\BotToken::value($id + 1, null, "saved") === "saved" '
			. '&& $read["items"][0]["token_configured"] === true && !str_contains(json_encode($read), $legacy) ? 0 : 1);';
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ), $output, $status );
		$this->assertSame( 0, $status );
	}

	public function testTokenRulesAndReadOnlySecretOutput(): void {
		$GLOBALS['wp_next_post_id'] = 500000;
		$token = '123456789:SAVEDTOKEN12345678901234567890';
		$bot = $this->post( Client::CPT_BOT, 'Bot ' . $token . ' ' . substr( $token, -4 ) . ' [empty]', [ 'token' => $token ] );
		$this->assertSame( $token, BotToken::value( $bot, null, $token ) );
		$this->assertSame( true, BotToken::isEmpty( '[empty]' ) );
		$this->assertSame( true, BotToken::isEmpty( 123 ) );
		$this->assertSame( false, BotToken::isEmpty( 'saved' ) );
		$chat = $this->post( Client::CPT_CHAT, 'Chat -100123456789 email@domain.com', [ 'chatID' => '-100123456789', 'chatType' => 'private' ] );
		$this->post( Client::CPT_BOT, 'Missing token', [ 'token' => '[empty]' ] );
		$gatewayCalls = 0;
		add_filter( 'cf7tg_telegram_gateway', static function ( $gateway ) use ( &$gatewayCalls ) { $gatewayCalls++; return $gateway; } );
		$snapshot = serialize( [ $GLOBALS['wp_posts'], $GLOBALS['wp_post_meta'], $GLOBALS['wp_deleted_posts'], $GLOBALS['wp_connection_rows'], $GLOBALS['wp_connection_meta_rows'],
			$GLOBALS['wp_log_rows'], $GLOBALS['wp_options'], $GLOBALS['wpdb_inserts'], $GLOBALS['wp_remote_post_requests'], _get_cron_array() ] );
		for ( $i = 0; $i < 2; $i++ ) {
			$all = [ $this->read( 'bots' ), $this->read( 'recipients' ), $this->read( 'forms' ), $this->read( 'bridges' ) ];
			$output = json_encode( $all );
			$this->assertFalse( str_contains( $output, $token ) );
			$this->assertFalse( str_contains( $output, substr( $token, -4 ) ) );
			$this->assertFalse( str_contains( $output, '[empty]' ) );
			$this->assertFalse( str_contains( $output, '-100123456789' ) );
			$this->assertFalse( str_contains( $output, 'email@domain.com' ) );
			$this->assertFalse( str_contains( $output, Bot::LEGACY_TOKEN_CONST ) );
			$this->assertSame( true, $all[0]['items'][0]['token_configured'] );
			$this->assertSame( $chat, $all[1]['items'][0]['id'] );
			$this->assertSame( false, $all[0]['items'][1]['token_configured'] );
		}
		$this->assertSame( 0, $gatewayCalls );
		$this->assertSame( $snapshot, serialize( [ $GLOBALS['wp_posts'], $GLOBALS['wp_post_meta'], $GLOBALS['wp_deleted_posts'], $GLOBALS['wp_connection_rows'], $GLOBALS['wp_connection_meta_rows'],
			$GLOBALS['wp_log_rows'], $GLOBALS['wp_options'], $GLOBALS['wpdb_inserts'], $GLOBALS['wp_remote_post_requests'], _get_cron_array() ] ) );
	}

	public function testStorageFailureIsSafeAndMalformedJsonDoesNotCrash(): void {
		$this->post( Client::CPT_CF7FORM, 'Padding' );
		$bot = $this->post( Client::CPT_BOT, 'Broken', [ 'token' => '[empty]' ] );
		$GLOBALS['wp_posts'][ $bot ]->post_content_filtered = '{bad json';
		$snapshot = serialize( [ $GLOBALS['wp_posts'], $GLOBALS['wp_log_rows'], $GLOBALS['wp_options'], $GLOBALS['wpdb_inserts'], $GLOBALS['wp_remote_post_requests'] ] );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertSame( false, $this->read( 'bots' )['items'][0]['token_configured'] );
			$this->assertSame( false, $this->read( 'bots' )['items'][0]['relations_complete'] );
		}
		$this->assertSame( $snapshot, serialize( [ $GLOBALS['wp_posts'], $GLOBALS['wp_log_rows'], $GLOBALS['wp_options'], $GLOBALS['wpdb_inserts'], $GLOBALS['wp_remote_post_requests'] ] ) );
		$GLOBALS['wp_get_results_handler'] = static function ( string $query, Cf7tg_Test_Wpdb $db ): array {
			$db->last_error = 'database contains SECRET_SENTINEL';
			trigger_error( 'SECRET_SENTINEL', E_USER_WARNING );
			return [];
		};
		$error = $this->read( 'bots' );
		$this->assertSame( 'cf7tg_storage_unavailable', $error->get_error_code() );
		$this->assertFalse( str_contains( $error->get_error_message(), 'SECRET_SENTINEL' ) );
	}
}
