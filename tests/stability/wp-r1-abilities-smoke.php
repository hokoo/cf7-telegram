<?php
/** Real Core Abilities API assertions in the isolated E4 WordPress install. */
if ( ! defined( 'ABSPATH' ) ) exit( 1 );

use iTRON\cf7Telegram\Abilities;
use iTRON\cf7Telegram\Client;
use iTRON\cf7Telegram\ReadOnlyAbility;
use iTRON\cf7Telegram\Settings;
use iTRON\wpConnections\Query;

$checks = [];
$record = static function ( string $id, bool $pass, array $extra = [] ) use ( &$checks ): void {
	$checks[] = [ 'id' => $id, 'status' => $pass ? 'pass' : 'fail' ] + ( $extra ? [ 'extra' => $extra ] : [] );
};
$error_code = static function ( $result ): string { return is_wp_error( $result ) ? $result->get_error_code() : ''; };
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin ? (int) $admin->ID : 0 );
$record( 'r1-core-available', function_exists( 'wp_get_ability' ) && class_exists( 'WP_Ability' ) );
if ( ! function_exists( 'wp_get_ability' ) ) {
	echo wp_json_encode( [ 'checks' => $checks, 'summary' => [ 'failed' => 1 ] ] ) . "\n";
	exit( 1 );
}

$names = [ 'cf7-telegram/get-configuration', 'cf7-telegram/diagnose-form', 'cf7-telegram/get-recent-errors' ];
$registry = wp_get_abilities();
$registered = array_values( array_filter( array_keys( $registry ), static fn( string $name ): bool => str_starts_with( $name, 'cf7-telegram/' ) ) );
sort( $registered );
$record( 'r2-registered-three', $registered === [ 'cf7-telegram/diagnose-form', 'cf7-telegram/get-configuration', 'cf7-telegram/get-recent-errors' ] &&
	null !== wp_get_ability_category( 'cf7-telegram' ) );
$list = wp_get_ability( $names[0] );
$diagnose = wp_get_ability( $names[1] );
$record( 'r1-core-schema', $list instanceof ReadOnlyAbility && $diagnose instanceof ReadOnlyAbility &&
	false === ( $list->get_input_schema()['additionalProperties'] ?? null ) &&
	false === ( $list->get_output_schema()['additionalProperties'] ?? null ) &&
	false === ( $diagnose->get_input_schema()['additionalProperties'] ?? null ) &&
	false === ( $diagnose->get_output_schema()['additionalProperties'] ?? null ) );

$post = static function ( string $type, string $title, string $json = '{}' ): int {
	return (int) wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_content_filtered' => $json ], true );
};
$edge = static function ( string $relation, int $from, int $to, ?string $status = null ): void {
	$client = Client::getInstance();
	$map = [ Client::FORM2CHANNEL => $client->getForm2ChannelRelation(), Client::BOT2CHANNEL => $client->getBot2ChannelRelation(),
		Client::CHAT2CHANNEL => $client->getChat2ChannelRelation(), Client::BOT2CHAT => $client->getBot2ChatRelation() ];
	$connection = $map[ $relation ]->createConnection( new Query\Connection( $from, $to ) );
	if ( null !== $status ) {
		$connection->meta->add( new \iTRON\wpConnections\Meta( 'status', $status ) );
		$connection->update();
	}
};
$preexisting_forms = get_posts( [ 'post_type' => Client::CPT_CF7FORM, 'post_status' => 'publish',
	'posts_per_page' => -1, 'fields' => 'ids' ] );
$form = $post( Client::CPT_CF7FORM, 'R1 healthy form' );
$empty = $post( Client::CPT_CF7FORM, 'R1 empty form' );
$bridge = $post( Client::CPT_CHANNEL, 'R1 bridge' );
$bot = $post( Client::CPT_BOT, 'R1 bot 123456789:SECRET_TOKEN_SENTINEL', wp_json_encode( [ 'token' => '123456789:SECRET_TOKEN_SENTINEL', 'lastStatus' => 'offline' ] ) );
$topic = $post( Client::CPT_CHAT, 'contact@example.com -100123456789', wp_json_encode( [ 'chatID' => '-100123456789', 'chatType' => 'supergroup', 'messageThreadID' => 22 ] ) );
$topic2 = $post( Client::CPT_CHAT, 'R1 second topic', wp_json_encode( [ 'chatID' => '-100123456789', 'chatType' => 'supergroup', 'messageThreadID' => 23 ] ) );
$pending = $post( Client::CPT_CHAT, 'R1 pending', wp_json_encode( [ 'chatID' => '12345678', 'chatType' => 'private' ] ) );
$invalid = $post( Client::CPT_CHAT, 'R1 invalid', wp_json_encode( [ 'chatID' => '12345679', 'chatType' => 'private', 'messageThreadID' => 44 ] ) );
$edge( Client::FORM2CHANNEL, $form, $bridge );
$edge( Client::BOT2CHANNEL, $bot, $bridge );
foreach ( [ $topic, $topic2, $pending, $invalid ] as $id ) $edge( Client::CHAT2CHANNEL, $id, $bridge );
foreach ( [ $topic, $topic2, $pending, $invalid ] as $id ) $edge( Client::BOT2CHAT, $bot, $id, $id === $pending ? 'pending' : 'active' );
$ready_form = $post( Client::CPT_CF7FORM, 'R1 ready form' );
$ready_bridge = $post( Client::CPT_CHANNEL, 'R1 ready bridge' );
$ready_bot = $post( Client::CPT_BOT, 'R1 ready bot', wp_json_encode( [ 'token' => '123456789:R1_READY_TOKEN', 'lastStatus' => 'offline' ] ) );
$ready_recipient = $post( Client::CPT_CHAT, 'R1 ready recipient', wp_json_encode( [ 'chatID' => '700001', 'chatType' => 'private' ] ) );
$edge( Client::FORM2CHANNEL, $ready_form, $ready_bridge );
$edge( Client::BOT2CHANNEL, $ready_bot, $ready_bridge );
$edge( Client::CHAT2CHANNEL, $ready_recipient, $ready_bridge );
$edge( Client::BOT2CHAT, $ready_bot, $ready_recipient, 'active' );
$bad_address_form = $post( Client::CPT_CF7FORM, 'R1 bad address form' );
$bad_address_bridge = $post( Client::CPT_CHANNEL, 'R1 bad address bridge' );
$bad_address_bot = $post( Client::CPT_BOT, 'R1 bad address bot', wp_json_encode( [ 'token' => '123456789:R1_BAD_ADDRESS_TOKEN' ] ) );
$bad_address_recipient = $post( Client::CPT_CHAT, 'R1 bad address recipient', wp_json_encode( [ 'chatID' => 'abc', 'chatType' => 'private' ] ) );
$edge( Client::FORM2CHANNEL, $bad_address_form, $bad_address_bridge );
$edge( Client::BOT2CHANNEL, $bad_address_bot, $bad_address_bridge );
$edge( Client::CHAT2CHANNEL, $bad_address_recipient, $bad_address_bridge );
$edge( Client::BOT2CHAT, $bad_address_bot, $bad_address_recipient, 'active' );
$mixed_address_form = $post( Client::CPT_CF7FORM, 'R1 mixed address form' );
$edge( Client::FORM2CHANNEL, $mixed_address_form, $ready_bridge );
$edge( Client::FORM2CHANNEL, $mixed_address_form, $bad_address_bridge );
$username_recipient = $post( Client::CPT_CHAT, 'R1 username recipient', wp_json_encode( [ 'chatID' => '@forum_name', 'chatType' => 'supergroup', 'messageThreadID' => 22 ] ) );
$integer_recipient = $post( Client::CPT_CHAT, 'R1 integer recipient', wp_json_encode( [ 'chatID' => 700005, 'chatType' => 'private' ] ) );
foreach ( [ $username_recipient, $integer_recipient ] as $id ) {
	$edge( Client::CHAT2CHANNEL, $id, $bridge );
	$edge( Client::BOT2CHAT, $bot, $id, 'active' );
}
$no_bot_form = $post( Client::CPT_CF7FORM, 'R1 no bot form' );
$no_bot_bridge = $post( Client::CPT_CHANNEL, 'R1 no bot bridge' );
$edge( Client::FORM2CHANNEL, $no_bot_form, $no_bot_bridge );
$no_token_form = $post( Client::CPT_CF7FORM, 'R1 no token form' );
$no_token_bridge = $post( Client::CPT_CHANNEL, 'R1 no token bridge' );
$no_token_bot = $post( Client::CPT_BOT, 'R1 no token bot', wp_json_encode( [ 'token' => '[empty]' ] ) );
$edge( Client::FORM2CHANNEL, $no_token_form, $no_token_bridge );
$edge( Client::BOT2CHANNEL, $no_token_bot, $no_token_bridge );
$malformed_form = $post( Client::CPT_CF7FORM, 'R1 malformed form' );
$malformed_bridge = $post( Client::CPT_CHANNEL, 'R1 malformed bridge' );
$malformed_bot = $post( Client::CPT_BOT, 'R1 malformed bot', '{SECRET_JSON_SENTINEL' );
define( sprintf( \iTRON\cf7Telegram\Bot::TOKEN_CONST_MASK, $malformed_bot ), '123456789:R1_SCOPED_TOKEN_SENTINEL' );
$edge( Client::FORM2CHANNEL, $malformed_form, $malformed_bridge );
$edge( Client::BOT2CHANNEL, $malformed_bot, $malformed_bridge );
$edge( Client::CHAT2CHANNEL, $ready_recipient, $malformed_bridge );
$edge( Client::BOT2CHAT, $malformed_bot, $ready_recipient, 'active' );
$mixed_form = $post( Client::CPT_CF7FORM, 'R1 mixed form' );
$edge( Client::FORM2CHANNEL, $mixed_form, $ready_bridge );
$edge( Client::FORM2CHANNEL, $mixed_form, $malformed_bridge );
$muted = $post( Client::CPT_CHAT, 'R1 muted', wp_json_encode( [ 'chatID' => '700002', 'chatType' => 'private' ] ) );
$unknown = $post( Client::CPT_CHAT, 'R1 unknown', wp_json_encode( [ 'chatID' => '700003', 'chatType' => 'private' ] ) );
$missing_link = $post( Client::CPT_CHAT, 'R1 missing bot relation', wp_json_encode( [ 'chatID' => '700004', 'chatType' => 'private' ] ) );
foreach ( [ $muted, $unknown, $missing_link ] as $id ) $edge( Client::CHAT2CHANNEL, $id, $bridge );
$edge( Client::BOT2CHAT, $bot, $muted, 'muted' );
$edge( Client::BOT2CHAT, $bot, $unknown, 'unrecognized' );
$unlinked_bridge = $post( Client::CPT_CHANNEL, 'R1 unlinked bridge' );
$unlinked_bot = $post( Client::CPT_BOT, 'R1 unlinked bot', wp_json_encode( [ 'token' => '[empty]' ] ) );
$unlinked_recipient = $post( Client::CPT_CHAT, 'R1 unlinked recipient', wp_json_encode( [ 'chatID' => '700999', 'chatType' => 'private' ] ) );
$legacy_bot = $post( Client::CPT_BOT, 'R1 legacy constant bot', wp_json_encode( [ 'tokenSource' => 'legacy_global_constant', 'token' => '[empty]' ] ) );
define( \iTRON\cf7Telegram\Bot::LEGACY_TOKEN_CONST, '123456789:R1_LEGACY_TOKEN_SENTINEL' );
define( sprintf( \iTRON\cf7Telegram\Bot::TOKEN_CONST_MASK, $legacy_bot ), '123456789:R1_SCOPED_OVERRIDE_SENTINEL' );
global $wpdb;
$connection_table = $wpdb->prefix . Client::getInstance()->getConnectionsClient()->getStorage()->get_connections_table();
$raw_edge = static function ( string $relation, int $from, int $to ) use ( $wpdb, $connection_table ): void {
	$wpdb->insert( $connection_table, [ 'relation' => $relation, 'from' => $from, 'to' => $to, 'order' => 0, 'title' => '' ] );
};
$two_bot_form = $post( Client::CPT_CF7FORM, 'R1 two bot form' );
$two_bot_bridge = $post( Client::CPT_CHANNEL, 'R1 two bot bridge' );
$second_bot = $post( Client::CPT_BOT, 'R1 second bot', wp_json_encode( [ 'token' => '123456789:R1_SECOND_TOKEN' ] ) );
$edge( Client::FORM2CHANNEL, $two_bot_form, $two_bot_bridge );
$edge( Client::BOT2CHANNEL, $ready_bot, $two_bot_bridge );
$raw_edge( Client::BOT2CHANNEL, $second_bot, $two_bot_bridge );
$corrupt_form = $post( Client::CPT_CF7FORM, 'R1 corrupt form' );
$wrong_type = $post( 'post', 'R1 wrong CPT' );
$raw_edge( Client::FORM2CHANNEL, $corrupt_form, $wrong_type );
$raw_edge( Client::FORM2CHANNEL, $corrupt_form, 99999999 );

$padding = [];
for ( $i = 0; $i < 111; $i++ ) $padding[] = $post( Client::CPT_CF7FORM, 'R1 page form ' . $i );
$hidden = array_values( array_filter( $padding, static fn( int $id, int $index ): bool => 0 === $index % 9, ARRAY_FILTER_USE_BOTH ) );
$hide = static function ( array $caps, string $cap, int $user_id, array $args ) use ( &$hidden ): array {
	$id = (int) ( $args[0] ?? 0 );
	if ( ! in_array( $id, $hidden, true ) ) return $caps;
	$type_name = get_post_type( $id );
	$type = $type_name ? get_post_type_object( $type_name ) : null;
	return $type && in_array( $cap, [ 'read_post', $type->cap->read_post ], true ) ? [ 'do_not_allow' ] : $caps;
};
add_filter( 'map_meta_cap', $hide, PHP_INT_MAX, 4 );
$hidden_form_denied = ! current_user_can( 'read_post', $hidden[0] );

$call = static fn( string $name, array $input ) => wp_get_ability( $name )->execute( $input );
$request = static function ( string $method, string $name, array $input ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $name . '/run' );
	if ( 'GET' === $method ) {
		$request->set_query_params( [ 'input' => wp_json_encode( $input ) ] );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'input' => $input ] ) );
	}
	return rest_do_request( $request );
};
rest_get_server();
$first = $call( $names[0], [ 'resource' => 'forms', 'page' => 1, 'per_page' => 50 ] );
$pages = [ $first ];
for ( $page = 2; $page <= 4; $page++ ) $pages[] = $call( $names[0], [ 'resource' => 'forms', 'page' => $page, 'per_page' => 50 ] );
$visible = array_values( array_diff( array_merge( $preexisting_forms,
	[ $form, $empty, $ready_form, $bad_address_form, $mixed_address_form, $no_bot_form, $no_token_form, $malformed_form, $mixed_form, $two_bot_form, $corrupt_form ], $padding ), $hidden ) );
sort( $visible, SORT_NUMERIC );
$all_ids = [];
foreach ( $pages as $page ) if ( is_array( $page ) ) $all_ids = array_merge( $all_ids, array_column( $page['items'], 'id' ) );
$record( 'r1-visible-pagination', $hidden_form_denied && is_array( $first ) && count( $visible ) > 100 && $first['total'] === count( $visible ) &&
	$all_ids === $visible && is_array( $pages[3] ) && $pages[3]['items'] === [] );
$rest = $request( 'GET', $names[0], [ 'resource' => 'forms', 'page' => 1, 'per_page' => 50 ] );
$record( 'r1-php-rest-parity', 200 === $rest->get_status() && $rest->get_data() === $first );
$executions = 0;
add_action( 'wp_before_execute_ability', static function ( $name ) use ( &$executions ) {
	if ( str_starts_with( (string) $name, 'cf7-telegram/' ) ) ++$executions;
}, 10, 1 );
$record( 'r1-core-input-validation', '' !== $error_code( $call( $names[0], [ 'resource' => 'forms', 'extra' => true ] ) ) &&
	'' !== $error_code( $call( $names[0], [ 'resource' => 'bogus' ] ) ) &&
	'' !== $error_code( $call( $names[1], [ 'form_id' => 0 ] ) ) && 0 === $executions );
$wrong_method_list = $request( 'POST', $names[0], [ 'resource' => 'forms' ] );
$wrong_method_diagnose = $request( 'POST', $names[1], [ 'form_id' => $form ] );
$invalid_rest_input = $request( 'GET', $names[0], [ 'resource' => 'bogus' ] );
$malformed_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $names[0] . '/run' );
$malformed_request->set_query_params( [ 'input' => '{"resource":' ] );
$malformed_response = rest_do_request( $malformed_request );
$scalar_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $names[0] . '/run' );
$scalar_request->set_query_params( [ 'input' => '"forms"' ] );
$scalar_response = rest_do_request( $scalar_request );
$list_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $names[0] . '/run' );
$list_request->set_query_params( [ 'input' => '[{"resource":"forms"}]' ] );
$list_response = rest_do_request( $list_request );
$unknown_response = $request( 'GET', $names[0], [ 'resource' => 'forms', 'extra' => true ] );
$record( 'r1-rest-method-validation', 405 === $wrong_method_list->get_status() &&
	'rest_ability_invalid_method' === ( $wrong_method_list->get_data()['code'] ?? null ) &&
	405 === $wrong_method_diagnose->get_status() &&
	'rest_ability_invalid_method' === ( $wrong_method_diagnose->get_data()['code'] ?? null ) &&
	400 === $invalid_rest_input->get_status() && 400 === $malformed_response->get_status() &&
	400 === $scalar_response->get_status() && 400 === $list_response->get_status() &&
	400 === $unknown_response->get_status() && 0 === $executions );
remove_filter( 'rest_pre_dispatch', [ Abilities::class, 'decodeRestGetInput' ], 10 );
$unfixed_response = $request( 'GET', $names[0], [ 'resource' => 'forms' ] );
$unfixed_executions = $executions;
add_filter( 'rest_pre_dispatch', [ Abilities::class, 'decodeRestGetInput' ], 10, 3 );
$fixed_response = $request( 'GET', $names[0], [ 'resource' => 'forms', 'page' => 1, 'per_page' => 50 ] );
$other_route = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/other-plugin/other/run' );
$other_route->set_query_params( [ 'input' => '{"resource":"forms"}' ] );
$other_method = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $names[0] . '/run' );
$other_method->set_query_params( [ 'input' => '{"resource":"forms"}' ] );
$preempted = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $names[0] . '/run' );
$preempted->set_query_params( [ 'input' => '{"resource":"forms"}' ] );
$trailing = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $names[0] . '/run/' );
$trailing->set_query_params( [ 'input' => '{"resource":"forms"}', 'keep' => 'untouched' ] );
$record( 'r1-rest-normalization-scope', 400 === $unfixed_response->get_status() && 0 === $unfixed_executions &&
	200 === $fixed_response->get_status() && $first === $fixed_response->get_data() && 1 === $executions &&
	null === Abilities::decodeRestGetInput( null, null, $other_route ) &&
	'{"resource":"forms"}' === $other_route->get_query_params()['input'] &&
	null === Abilities::decodeRestGetInput( null, null, $other_method ) &&
	'{"resource":"forms"}' === $other_method->get_query_params()['input'] &&
	'preempted' === Abilities::decodeRestGetInput( 'preempted', null, $preempted ) &&
	'{"resource":"forms"}' === $preempted->get_query_params()['input'] &&
	null === Abilities::decodeRestGetInput( null, null, $trailing ) &&
	[ 'input' => [ 'resource' => 'forms' ], 'keep' => 'untouched' ] === $trailing->get_query_params() );
$numeric_input = [];
foreach ( [ 'page' => [ $names[0], [ 'resource' => 'forms', 'page' => '1' ] ],
	'per_page' => [ $names[0], [ 'resource' => 'forms', 'per_page' => '1' ] ],
	'form_id' => [ $names[1], [ 'form_id' => (string) $form ] ] ] as $case => [ $name, $input ] ) {
	$before_numeric = $executions;
	$php_numeric = $call( $name, $input );
	$after_php_numeric = $executions;
	$rest_numeric = $request( 'GET', $name, $input );
	$numeric_input[ $case ] = [ 'php_code' => $error_code( $php_numeric ),
		'php_message' => is_wp_error( $php_numeric ) ? $php_numeric->get_error_message() : '',
		'php_status' => is_wp_error( $php_numeric ) ? ( $php_numeric->get_error_data()['status'] ?? null ) : null,
		'php_callback_count' => $after_php_numeric - $before_numeric,
		'rest_status' => $rest_numeric->get_status(), 'rest_code' => $rest_numeric->get_data()['code'] ?? '',
		'rest_callback_count' => $executions - $after_php_numeric ];
}
$numeric_valid = true;
foreach ( $numeric_input as $result ) {
	if ( '' === $result['php_code'] || 400 !== $result['rest_status'] || $result['php_code'] !== $result['rest_code'] ||
		0 !== $result['php_callback_count'] || 0 !== $result['rest_callback_count'] ) $numeric_valid = false;
}
$record( 'r1-numeric-string-input-parity', $numeric_valid && 3 === count( $numeric_input ), $numeric_input );
$resources = [];
foreach ( [ 'forms', 'bridges', 'bots', 'recipients' ] as $resource ) $resources[ $resource ] = $call( $names[0], [ 'resource' => $resource ] );
$recipients = $resources['recipients']['items'] ?? [];
$record( 'r1-four-resources', count( $resources ) === 4 && count( $resources['bridges']['items'] ?? [] ) >= 1 &&
	count( $resources['bots']['items'] ?? [] ) >= 1 && count( $recipients ) >= 4 &&
	( $resources['forms']['total'] ?? 0 ) === count( $visible ) &&
	in_array( $empty, array_column( $resources['forms']['items'], 'id' ), true ) &&
	in_array( $unlinked_bridge, array_column( $resources['bridges']['items'], 'id' ), true ) &&
	in_array( $unlinked_bot, array_column( $resources['bots']['items'], 'id' ), true ) &&
	in_array( $unlinked_recipient, array_column( $recipients, 'id' ), true ) );
$threads = array_column( $recipients, 'message_thread_id', 'id' );
$kinds = array_column( $recipients, 'destination_kind', 'id' );
$record( 'r1-topic-identity', ( $threads[ $topic ] ?? null ) === 22 && ( $threads[ $topic2 ] ?? null ) === 23 &&
	( $kinds[ $invalid ] ?? null ) === 'invalid' && ! isset( $resources['bridges']['items'][0]['revision'] ) );
$recipient_items = array_column( $recipients, null, 'id' );
$record( 'r1-relation-statuses', ( $recipient_items[ $muted ]['bot_connections'][0]['status'] ?? null ) === 'muted' &&
	( $recipient_items[ $unknown ]['bot_connections'][0]['status'] ?? null ) === 'unknown' &&
	( $recipient_items[ $missing_link ]['bot_connections'] ?? null ) === [] );
$bridge_items = array_column( $resources['bridges']['items'] ?? [], null, 'id' );
$record( 'r1-two-bot-cardinality', array_key_exists( $two_bot_bridge, $bridge_items ) &&
	null === $bridge_items[ $two_bot_bridge ]['bot_id'] && false === $bridge_items[ $two_bot_bridge ]['relations_complete'] );
$legacy_token = \iTRON\cf7Telegram\BotToken::value( $legacy_bot, 'legacy_global_constant', '[empty]' );
$record( 'r1-token-precedence', $legacy_token === constant( \iTRON\cf7Telegram\Bot::LEGACY_TOKEN_CONST ) &&
	( new \iTRON\cf7Telegram\Bot( $legacy_bot ) )->getToken() === $legacy_token &&
	\iTRON\cf7Telegram\BotToken::value( $malformed_bot, null, '[empty]' ) ===
		constant( sprintf( \iTRON\cf7Telegram\Bot::TOKEN_CONST_MASK, $malformed_bot ) ) );
$healthy = $call( $names[1], [ 'form_id' => $form ] );
$no_bridge = $call( $names[1], [ 'form_id' => $empty ] );
$codes = is_array( $healthy ) ? array_column( $healthy['issues'], 'code' ) : [];
$record( 'r1-diagnose-status', is_array( $healthy ) && 'partial' === $healthy['configuration_status'] &&
	false === $healthy['delivery_verified'] && in_array( 'recipient_not_active', $codes, true ) &&
	in_array( 'invalid_destination', $codes, true ) && in_array( 'cached_bot_offline', $codes, true ) &&
	'blocked' === ( $no_bridge['configuration_status'] ?? null ) && in_array( 'no_bridge', array_column( $no_bridge['issues'], 'code' ), true ) );
$record( 'r1-diagnose-rest-parity', $request( 'GET', $names[1], [ 'form_id' => $form ] )->get_data() === $healthy );
$bad_list = $resources['bots'];
$bad_list['items'][0]['token_configured'] = 'invalid';
$bad_diagnosis = $healthy;
$bad_diagnosis['issues'][0]['severity'] = 'invalid';
$output_probe = static function ( string $name, array $schema, array $output ): string {
	$probe = new WP_Ability( 'cf7tg-r1-smoke/' . $name, [ 'label' => 'R1 schema probe', 'description' => 'Isolated output validation probe.',
		'category' => 'cf7-telegram', 'output_schema' => $schema,
		'permission_callback' => static fn(): bool => true, 'execute_callback' => static fn() => $output ] );
	$result = $probe->execute();
	return is_wp_error( $result ) ? $result->get_error_code() : '';
};
$record( 'r1-core-nested-output-validation', 'ability_invalid_output' === $output_probe( 'list', $list->get_output_schema(), $bad_list ) &&
	'ability_invalid_output' === $output_probe( 'diagnosis', $diagnose->get_output_schema(), $bad_diagnosis ) );
$ready_diagnosis = $call( $names[1], [ 'form_id' => $ready_form ] );
$bad_address_diagnosis = $call( $names[1], [ 'form_id' => $bad_address_form ] );
$mixed_address_diagnosis = $call( $names[1], [ 'form_id' => $mixed_address_form ] );
$no_bot_diagnosis = $call( $names[1], [ 'form_id' => $no_bot_form ] );
$no_token_diagnosis = $call( $names[1], [ 'form_id' => $no_token_form ] );
$malformed_diagnosis = $call( $names[1], [ 'form_id' => $malformed_form ] );
$all_codes = array_unique( array_merge( $codes, array_column( $no_bridge['issues'] ?? [], 'code' ),
	array_column( $no_bot_diagnosis['issues'] ?? [], 'code' ), array_column( $no_token_diagnosis['issues'] ?? [], 'code' ),
	array_column( $malformed_diagnosis['issues'] ?? [], 'code' ) ) );
sort( $all_codes );
$expected_codes = [ 'broken_relation', 'cached_bot_offline', 'invalid_destination', 'no_bot', 'no_bridge', 'no_recipients', 'recipient_not_active', 'token_missing' ];
sort( $expected_codes );
$record( 'r1-all-diagnosis-codes', $all_codes === $expected_codes &&
	'ready' === ( $ready_diagnosis['configuration_status'] ?? null ) &&
	'blocked' === ( $malformed_diagnosis['configuration_status'] ?? null ) &&
	! in_array( 'token_missing', array_column( $malformed_diagnosis['issues'] ?? [], 'code' ), true ) &&
	true === ( array_column( $resources['bots']['items'] ?? [], 'token_configured', 'id' )[ $malformed_bot ] ?? false ) );
$record( 'r1-offline-warning-only', 'ready' === ( $ready_diagnosis['configuration_status'] ?? null ) &&
	[ 'cached_bot_offline' ] === array_column( $ready_diagnosis['issues'] ?? [], 'code' ) &&
	'warning' === ( $ready_diagnosis['issues'][0]['severity'] ?? null ) );
$record( 'r1-address-validity', ( $recipient_items[ $bad_address_recipient ]['destination_kind'] ?? null ) === 'invalid' &&
	( $recipient_items[ $username_recipient ]['destination_kind'] ?? null ) === 'forum_topic' &&
	( $recipient_items[ $integer_recipient ]['destination_kind'] ?? null ) === 'default' &&
	'blocked' === ( $bad_address_diagnosis['configuration_status'] ?? null ) &&
	[] === ( $bad_address_diagnosis['bridges'][0]['eligible_recipient_ids'] ?? null ) &&
	in_array( 'invalid_destination', array_column( $bad_address_diagnosis['issues'] ?? [], 'code' ), true ) &&
	'partial' === ( $mixed_address_diagnosis['configuration_status'] ?? null ) &&
	[ $ready_recipient ] === ( $mixed_address_diagnosis['bridges'][0]['eligible_recipient_ids'] ?? null ) &&
	[] === ( $mixed_address_diagnosis['bridges'][1]['eligible_recipient_ids'] ?? null ) &&
	in_array( 'invalid_destination', array_column( $mixed_address_diagnosis['issues'] ?? [], 'code' ), true ) &&
	in_array( $username_recipient, $healthy['bridges'][0]['eligible_recipient_ids'] ?? [], true ) &&
	in_array( $integer_recipient, $healthy['bridges'][0]['eligible_recipient_ids'] ?? [], true ) );
$mixed_diagnosis = $call( $names[1], [ 'form_id' => $mixed_form ] );
$two_bot_diagnosis = $call( $names[1], [ 'form_id' => $two_bot_form ] );
$corrupt_diagnosis = $call( $names[1], [ 'form_id' => $corrupt_form ] );
$record( 'r1-mixed-and-corrupt-graph', 'partial' === ( $mixed_diagnosis['configuration_status'] ?? null ) &&
	in_array( $ready_recipient, $mixed_diagnosis['bridges'][0]['eligible_recipient_ids'] ?? [], true ) &&
	in_array( 'broken_relation', array_column( $mixed_diagnosis['issues'] ?? [], 'code' ), true ) &&
	'blocked' === ( $two_bot_diagnosis['configuration_status'] ?? null ) &&
	in_array( 'broken_relation', array_column( $two_bot_diagnosis['issues'] ?? [], 'code' ), true ) &&
	'blocked' === ( $corrupt_diagnosis['configuration_status'] ?? null ) &&
	in_array( 'broken_relation', array_column( $corrupt_diagnosis['issues'] ?? [], 'code' ), true ) );

$storage = Client::getInstance()->getConnectionsClient()->getStorage();
$tables = [ $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->prefix . 'cf7tg_log', $wpdb->prefix . $storage->get_connections_table(),
	$wpdb->prefix . $storage->get_meta_table() ];
$snapshot = static function () use ( $wpdb, $tables ): ?string {
	$rows = [];
	foreach ( $tables as $table ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return null;
		$result = $wpdb->get_results( 'SELECT * FROM `' . str_replace( '`', '``', $table ) . '` ORDER BY 1', ARRAY_A );
		if ( ! is_array( $result ) || $wpdb->last_error ) return null;
		$rows[] = $result;
	}
	return hash( 'sha256', serialize( $rows ) );
};
$http = 0;
$gateway = 0;
$mutations = 0;
add_filter( 'pre_http_request', static function ( $pre ) use ( &$http ) { ++$http; return new WP_Error( 'r1_http_blocked' ); }, 1 );
add_filter( 'cf7tg_telegram_gateway', static function ( $value ) use ( &$gateway ) { ++$gateway; return $value; }, 1 );
$before = $snapshot();
add_filter( 'query', static function ( string $sql ) use ( &$mutations ): string {
	if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql ) ) ++$mutations;
	return $sql;
}, PHP_INT_MAX );
$read1 = $call( $names[0], [ 'resource' => 'bots' ] );
$read2 = $call( $names[1], [ 'form_id' => $form ] );
$other_reads1 = [];
foreach ( [ 'forms', 'bridges', 'recipients' ] as $resource ) $other_reads1[ $resource ] = $call( $names[0], [ 'resource' => $resource ] );
$read_bad_address = $call( $names[1], [ 'form_id' => $bad_address_form ] );
$read_mixed_address = $call( $names[1], [ 'form_id' => $mixed_address_form ] );
$read_missing = $call( $names[1], [ 'form_id' => $no_token_form ] );
$read_malformed = $call( $names[1], [ 'form_id' => $malformed_form ] );
$read3 = $call( $names[0], [ 'resource' => 'bots' ] );
$read4 = $call( $names[1], [ 'form_id' => $form ] );
$other_reads2 = [];
foreach ( [ 'forms', 'bridges', 'recipients' ] as $resource ) $other_reads2[ $resource ] = $call( $names[0], [ 'resource' => $resource ] );
$read_bad_address_again = $call( $names[1], [ 'form_id' => $bad_address_form ] );
$read_mixed_address_again = $call( $names[1], [ 'form_id' => $mixed_address_form ] );
$read_missing_again = $call( $names[1], [ 'form_id' => $no_token_form ] );
$read_malformed_again = $call( $names[1], [ 'form_id' => $malformed_form ] );
$after = $snapshot();
$record( 'r1-readonly-repeated', null !== $before && $before === $after && 0 === $mutations && 0 === $http && 0 === $gateway &&
	$read1 === $read3 && $read2 === $read4 && $other_reads1 === $other_reads2 &&
	$read_bad_address === $read_bad_address_again && $read_mixed_address === $read_mixed_address_again &&
	$read_missing === $read_missing_again && $read_malformed === $read_malformed_again );
$serialized = wp_json_encode( [ $read1, $read2, $other_reads1, $read_bad_address, $read_mixed_address, $read_missing, $read_malformed, $resources,
	$invalid_rest_input->get_data(), $malformed_response->get_data(), $scalar_response->get_data() ] );
$record( 'r1-secret-redaction', is_string( $serialized ) && ! str_contains( $serialized, 'SECRET_TOKEN_SENTINEL' ) &&
	! str_contains( $serialized, 'R1_BAD_ADDRESS_TOKEN' ) &&
	! str_contains( $serialized, 'SECRET_JSON_SENTINEL' ) && ! str_contains( $serialized, 'R1_SCOPED_TOKEN_SENTINEL' ) &&
	! str_contains( $serialized, 'R1_LEGACY_TOKEN_SENTINEL' ) && ! str_contains( $serialized, 'R1_SCOPED_OVERRIDE_SENTINEL' ) &&
	! str_contains( $serialized, '-100123456789' ) && ! str_contains( $serialized, 'contact@example.com' ) );

wp_set_current_user( 0 );
$anonymous_list = $request( 'GET', $names[0], [ 'resource' => 'forms' ] );
$anonymous_diagnosis = $request( 'GET', $names[1], [ 'form_id' => $form ] );
$record( 'r1-anonymous-denied', 'ability_invalid_permissions' === $error_code( $call( $names[0], [ 'resource' => 'forms' ] ) ) &&
	'ability_invalid_permissions' === $error_code( $call( $names[1], [ 'form_id' => $form ] ) ) &&
	in_array( $anonymous_list->get_status(), [ 401, 403 ], true ) &&
	in_array( $anonymous_diagnosis->get_status(), [ 401, 403 ], true ) &&
	'rest_ability_cannot_execute' === ( $anonymous_list->get_data()['code'] ?? null ) &&
	'rest_ability_cannot_execute' === ( $anonymous_diagnosis->get_data()['code'] ?? null ) );
$subscriber = wp_insert_user( [ 'user_login' => 'r1_subscriber', 'user_pass' => wp_generate_password( 24 ),
	'user_email' => 'r1-subscriber@example.test', 'role' => 'subscriber' ] );
wp_set_current_user( is_wp_error( $subscriber ) ? 0 : (int) $subscriber );
$record( 'r1-subscriber-denied', '' !== $error_code( $call( $names[0], [ 'resource' => 'forms' ] ) ) &&
	'' !== $error_code( $call( $names[1], [ 'form_id' => $form ] ) ) );
wp_set_current_user( $admin ? (int) $admin->ID : 0 );
$hidden[] = $topic;
$hidden_topic_denied = ! current_user_can( 'read_post', $topic );
$record( 'r1-diagnose-hidden-graph', $hidden_topic_denied && 'cf7tg_forbidden' === $error_code( $call( $names[1], [ 'form_id' => $form ] ) ) );
$limited_bridge = $call( $names[0], [ 'resource' => 'bridges' ] );
$first_bridge = array_values( array_filter( $limited_bridge['items'] ?? [], static fn( array $item ): bool => $item['id'] === $bridge ) );
$record( 'r1-hidden-relation', count( $first_bridge ) === 1 && ! in_array( $topic, $first_bridge[0]['recipient_ids'], true ) &&
	false === $first_bridge[0]['relations_complete'] );
remove_filter( 'map_meta_cap', $hide, PHP_INT_MAX );
$expected = [ 'get-configuration' => $call( $names[0], [ 'resource' => 'forms', 'per_page' => 1 ] ),
	'diagnose-form' => $call( $names[1], [ 'form_id' => $form ] ) ];

// Keep the R1 graph and its checks intact; exercise the shared log separately.
$recent = wp_get_ability( $names[2] );
$recent_input = [ 'since' => 1000, 'until' => 1001, 'min_level' => 0, 'limit' => 2 ];
$record( 'r2-core-schema', $recent instanceof ReadOnlyAbility &&
	false === ( $recent->get_input_schema()['additionalProperties'] ?? null ) &&
	false === ( $recent->get_output_schema()['additionalProperties'] ?? null ) &&
	true === ( $recent->get_meta()['annotations']['readonly'] ?? null ) &&
	false === ( $recent->get_meta()['annotations']['destructive'] ?? null ) &&
	true === ( $recent->get_meta()['annotations']['idempotent'] ?? null ) );
$log_table = $wpdb->prefix . 'cf7tg_log';
$log_identifier = '`' . str_replace( '`', '``', $log_table ) . '`';
$log_rows = [
	[ 1000, 1, 'Telegram delivery failed.', wp_json_encode( [ 'chatPostID' => 42, 'chunk' => 2, 'status' => 429,
		'errorCode' => 43, 'errorType' => 'telegram', 'retryAfter' => 8, 'token' => '123456789:R2_SECRET_SENTINEL',
		'chatID' => '-100123456789', 'text' => 'R2_SUBMISSION_SENTINEL' ] ) ],
	[ 1000, 0, 'Bot is unreachable', wp_json_encode( [ 'wpPostID' => 77, 'status' => 0, 'errorType' => 'transport' ] ) ],
	[ 1000, 3, 'R2_SECRET_SENTINEL', '{bad JSON' ],
	[ 1001, 2, 'Channel has no bot connected. Telegram send skipped.', wp_json_encode( [ 'channelPostID' => $bridge ] ) ],
];
$log_ids = [];
foreach ( $log_rows as [ $date, $level, $msg, $data ] ) {
	$log_ids[] = $wpdb->insert( $log_table, [ 'source' => 'r2-smoke', 'date' => $date, 'level' => $level, 'msg' => $msg, 'data' => $data ] )
		? (int) $wpdb->insert_id : 0;
}
$page1 = $call( $names[2], $recent_input );
$rest_recent = $request( 'GET', $names[2], $recent_input );
$record( 'r2-core-rest-parity', ! in_array( 0, $log_ids, true ) && is_array( $page1 ) &&
	200 === $rest_recent->get_status() && $rest_recent->get_data() === $page1 &&
	[ $log_ids[3], $log_ids[2] ] === array_column( $page1['items'] ?? [], 'id' ) &&
	$log_ids[2] === ( $page1['next_before_id'] ?? null ) && false === ( $page1['history_complete'] ?? null ) );
$bad_recent = $page1;
$bad_recent['items'][0]['context']['http_status'] = 'invalid-http-status';
$record( 'r2-core-nested-output-validation', 'ability_invalid_output' === $output_probe( 'recent-errors', $recent->get_output_schema(), $bad_recent ) );
$wpdb->insert( $log_table, [ 'source' => 'r2-smoke', 'date' => 1000, 'level' => 3, 'msg' => 'Bot initialization error.', 'data' => 'Bot token is not set.' ] );
$new_id = (int) $wpdb->insert_id;
$page2 = $call( $names[2], $recent_input + [ 'before_id' => $page1['next_before_id'] ] );
$fresh = $call( $names[2], $recent_input );
$record( 'r2-cursor-bounds', [ $log_ids[1], $log_ids[0] ] === array_column( $page2['items'] ?? [], 'id' ) &&
	isset( $page2['next_before_id'] ) === false && array_key_exists( 'next_before_id', $page2 ) &&
	[ $new_id, $log_ids[3] ] === array_column( $fresh['items'] ?? [], 'id' ) &&
	[ $log_ids[3] ] === array_column( $call( $names[2], [ 'since' => 1001, 'until' => 1001 ] )['items'] ?? [], 'id' ) &&
	[ $log_ids[1], $log_ids[0] ] === array_column( $call( $names[2], [ 'since' => 1000, 'until' => 1000, 'min_level' => 0, 'before_id' => $log_ids[2] ] )['items'] ?? [], 'id' ) );
$all_recent = $call( $names[2], [ 'since' => 1000, 'until' => 1001, 'min_level' => 0 ] );
$recent_items = array_column( $all_recent['items'] ?? [], null, 'id' );
$recent_json = wp_json_encode( $all_recent );
$record( 'r2-historic-redaction', ( $recent_items[ $log_ids[0] ]['event'] ?? null ) === 'telegram_delivery_failed' &&
	( $recent_items[ $log_ids[0] ]['context']['recipient_id'] ?? null ) === 42 &&
	( $recent_items[ $log_ids[1] ]['event'] ?? null ) === 'bot_unreachable' &&
	array_key_exists( 'bot_id', $recent_items[ $log_ids[1] ]['context'] ?? [] ) &&
	null === $recent_items[ $log_ids[1] ]['context']['bot_id'] &&
	( $recent_items[ $log_ids[2] ]['event'] ?? null ) === 'other' &&
	( $recent_items[ $log_ids[2] ]['message'] ?? null ) === 'Plugin event' &&
	is_string( $recent_json ) && ! str_contains( $recent_json, 'R2_SECRET_SENTINEL' ) &&
	! str_contains( $recent_json, 'R2_SUBMISSION_SENTINEL' ) && ! str_contains( $recent_json, '-100123456789' ) &&
	! str_contains( $recent_json, 'wpPostID' ) && ! str_contains( $recent_json, 'chatID' ) );
$recent_numeric = [];
foreach ( [ 'since', 'until', 'min_level', 'before_id', 'limit' ] as $key ) {
	$input = $recent_input;
	$input[ $key ] = '1';
	$before_numeric = $executions;
	$php_numeric = $call( $names[2], $input );
	$after_php_numeric = $executions;
	$rest_numeric = $request( 'GET', $names[2], $input );
	$recent_numeric[ $key ] = [ 'php_code' => $error_code( $php_numeric ),
		'php_message' => is_wp_error( $php_numeric ) ? $php_numeric->get_error_message() : '',
		'rest_code' => $rest_numeric->get_data()['code'] ?? '', 'rest_status' => $rest_numeric->get_status(),
		'php_callback_count' => $after_php_numeric - $before_numeric,
		'rest_callback_count' => $executions - $after_php_numeric ];
}
$record( 'r2-numeric-string-input-parity', count( $recent_numeric ) === 5 &&
	! array_filter( $recent_numeric, static fn( array $case ): bool => '' === $case['php_code'] ||
		$case['php_code'] !== $case['rest_code'] || 400 !== $case['rest_status'] ||
		0 !== $case['php_callback_count'] || 0 !== $case['rest_callback_count'] ), $recent_numeric );
$before_method = $executions;
$wrong_recent_method = $request( 'POST', $names[2], $recent_input );
$record( 'r2-wrong-method', 405 === $wrong_recent_method->get_status() &&
	'rest_ability_invalid_method' === ( $wrong_recent_method->get_data()['code'] ?? null ) && $before_method === $executions );
$retention_days = static fn() => 0;
$retention_rows = static fn() => 7;
add_filter( 'cf7tg/logRetentionDays', $retention_days );
add_filter( 'cf7tg/logMaxRows', $retention_rows );
$retained = $call( $names[2], $recent_input );
remove_filter( 'cf7tg/logRetentionDays', $retention_days );
remove_filter( 'cf7tg/logMaxRows', $retention_rows );
$record( 'r2-retention-overrides', ( $retained['retention'] ?? null ) === [ 'days' => 0, 'max_rows' => 7 ] );
$settings_cap = Settings::getCaps();
$settings_primitives = map_meta_cap( $settings_cap, $admin ? (int) $admin->ID : 0 );
$permission_results = [];
foreach ( [ 'settings_only' => [ 'manage_options' ], 'manage_only' => $settings_primitives ] as $case => $denied_caps ) {
	$filter = static function ( array $caps ) use ( $denied_caps ): array {
		foreach ( $denied_caps as $cap ) $caps[ $cap ] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $filter, PHP_INT_MAX );
	$has_settings = current_user_can( $settings_cap );
	$has_manage = current_user_can( 'manage_options' );
	$php_denied = $call( $names[2], $recent_input );
	$rest_denied = $request( 'GET', $names[2], $recent_input );
	remove_filter( 'user_has_cap', $filter, PHP_INT_MAX );
	$permission_results[ $case ] = [ $has_settings, $has_manage, $error_code( $php_denied ), $rest_denied->get_status(), $rest_denied->get_data()['code'] ?? '' ];
}
$record( 'r2-core-capability-split', $settings_cap !== 'manage_options' && ! in_array( 'manage_options', $settings_primitives, true ) &&
	$permission_results['settings_only'] === [ true, false, 'ability_invalid_permissions', 403, 'rest_ability_cannot_execute' ] &&
	$permission_results['manage_only'] === [ false, true, 'ability_invalid_permissions', 403, 'rest_ability_cannot_execute' ],
	[ 'requested' => $settings_cap, 'mapped' => $settings_primitives, 'results' => $permission_results ] );
$backup_table = $wpdb->prefix . 'cf7tg_r2_smoke_backup';
$backup_identifier = '`' . str_replace( '`', '``', $backup_table ) . '`';
$renamed = false !== $wpdb->query( "RENAME TABLE $log_identifier TO $backup_identifier" );
$missing_recent = $renamed ? $call( $names[2], $recent_input ) : null;
$missing_recent_rest = $renamed ? $request( 'GET', $names[2], $recent_input ) : null;
$restored = $renamed && false !== $wpdb->query( "RENAME TABLE $backup_identifier TO $log_identifier" );
$record( 'r2-missing-log-table', $renamed && $restored && is_array( $missing_recent ) &&
	[] === ( $missing_recent['items'] ?? null ) && array_key_exists( 'next_before_id', $missing_recent ) &&
	null === $missing_recent['next_before_id'] && 200 === $missing_recent_rest->get_status() &&
	$missing_recent === $missing_recent_rest->get_data() );
$break_read = static function ( string $sql ): string {
	return str_starts_with( $sql, 'SELECT ID, date, level, msg, data FROM ' ) ? 'SELECT * FROM cf7tg_r2_missing_table' : $sql;
};
add_filter( 'query', $break_read, PHP_INT_MAX );
$storage_error = $call( $names[2], $recent_input );
$storage_rest = $request( 'GET', $names[2], $recent_input );
remove_filter( 'query', $break_read, PHP_INT_MAX );
$record( 'r2-storage-fault', $restored && 'cf7tg_storage_unavailable' === $error_code( $storage_error ) &&
	503 === ( is_wp_error( $storage_error ) ? ( $storage_error->get_error_data()['status'] ?? null ) : null ) &&
	! str_contains( is_wp_error( $storage_error ) ? $storage_error->get_error_message() : '', 'cf7tg_r2_missing_table' ) &&
	503 === $storage_rest->get_status() && 'cf7tg_storage_unavailable' === ( $storage_rest->get_data()['code'] ?? null ) &&
	( is_wp_error( $storage_error ) ? $storage_error->get_error_message() : null ) === ( $storage_rest->get_data()['message'] ?? null ) );
$r2_before = $snapshot();
$r2_mutations = $mutations;
$r2_http = $http;
$r2_gateway = $gateway;
$stable1 = $call( $names[2], $recent_input );
$stable2 = $call( $names[2], $recent_input );
$stable_rest = $request( 'GET', $names[2], $recent_input );
$recent->get_input_schema();
$recent->get_output_schema();
wp_get_abilities();
$r2_after = $snapshot();
$record( 'r2-readonly-repeated', null !== $r2_before && $r2_before === $r2_after &&
	$stable1 === $stable2 && $stable1 === $stable_rest->get_data() &&
	$r2_mutations === $mutations && $r2_http === $http && $r2_gateway === $gateway );
$expected['get-recent-errors'] = $call( $names[2], $recent_input );
$expected['get-recent-errors-sensitive'] = $all_recent;
$expected['get-recent-errors-next'] = $call( $names[2], $recent_input + [ 'before_id' => $expected['get-recent-errors']['next_before_id'] ] );

$failed = count( array_filter( $checks, static fn( array $check ): bool => 'fail' === $check['status'] ) );
echo wp_json_encode( [ 'schema' => 1, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'contact_form_7' => WPCF7_VERSION, 'fixture' => [ 'form_id' => $form, 'empty_form_id' => $empty, 'hidden_recipient_id' => $topic ],
	'expected' => $expected, 'numeric_input' => $numeric_input, 'recent_numeric_input' => $recent_numeric,
	'recent_input' => $recent_input,
	'summary' => [ 'total' => count( $checks ), 'failed' => $failed ],
	'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
