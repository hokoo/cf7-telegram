<?php
/**
 * Seeds anonymized E1 smoke fixtures in an ephemeral WordPress site.
 *
 * Intended to be executed with WP-CLI only:
 * wp eval-file /e1-tests/wp-seed-fixture.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$fixture = getenv( 'CF7TG_E1_FIXTURE' ) ?: 'legacy-heavy';

if ( 'none' === $fixture ) {
	echo wp_json_encode(
		[
			'fixture' => $fixture,
			'seeded'  => false,
			'note'    => 'Fixture seeding disabled.',
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
	return;
}

$result = [
	'fixture' => $fixture,
	'seeded'  => true,
	'forms'   => [],
	'chats'   => [],
	'options' => [],
];

$e2_expectations = [
	'fixture' => $fixture,
];

if ( in_array( $fixture, [ 'legacy-basic', 'legacy-heavy', 'damaged-legacy' ], true ) ) {
	update_option( 'wpcf7_telegram_tkn', '123456789:REDACTED_E1_FIXTURE_TOKEN', false );
	update_option( 'wpcf7_telegram_last_update_id', 987654321, false );

	$result['options'][] = 'wpcf7_telegram_tkn';
	$result['options'][] = 'wpcf7_telegram_last_update_id';

	$chat_count = 'legacy-heavy' === $fixture ? 12 : 3;
	$chats      = [];

	for ( $i = 1; $i <= $chat_count; $i++ ) {
		$chat_id = 700000 + $i;
		$chats[ (string) $chat_id ] = [
			'id'         => (string) $chat_id,
			'status'     => 0 === $i % 5 ? 'pending' : 'active',
			'first_name' => 'E1 User ' . $i,
			'last_name'  => 0 === $i % 3 ? 'Markdown_[test]*' : '',
			'username'   => 'e1_fixture_' . $i,
		];
		$result['chats'][] = (string) $chat_id;
	}

	if ( 'legacy-heavy' === $fixture ) {
		$chats['-100700001'] = [
			'id'         => '-100700001',
			'status'     => 'active',
			'first_name' => '',
			'last_name'  => '',
			'username'   => 'e1_group_fixture',
		];
		$result['chats'][] = '-100700001';
	}

	if ( 'damaged-legacy' === $fixture ) {
		$chats['broken'] = [
			'id'         => '',
			'status'     => 'pending',
			'first_name' => 'Broken',
			'last_name'  => null,
		];
	}

	update_option( 'wpcf7_telegram_chats', $chats, false );
	$result['options'][] = 'wpcf7_telegram_chats';

	$valid_chats = array_filter(
		$chats,
		static function ( array $chat ): bool {
			return isset( $chat['id'] ) && '' !== trim( (string) $chat['id'] );
		}
	);

	$e2_expectations['legacy'] = [
		'has_legacy_state'     => true,
		'total_chat_rows'      => count( $chats ),
		'valid_chat_rows'      => count( $valid_chats ),
		'malformed_chat_rows'  => count( $chats ) - count( $valid_chats ),
		'pending_chat_rows'    => count(
			array_filter(
				$valid_chats,
				static fn( array $chat ): bool => 'pending' === ( $chat['status'] ?? '' )
			)
		),
		'active_chat_rows'     => count(
			array_filter(
				$valid_chats,
				static fn( array $chat ): bool => 'pending' !== ( $chat['status'] ?? '' )
			)
		),
		'expected_bot_count'   => 1,
		'expected_channel_count' => 1,
	];

	$form_definitions = [
		[
			'title' => 'E1 Fixture Form 1',
			'body'  => "[text* your-name]\n[email* your-email]\n[textarea your-message]\n[telegram]\n[submit \"Send\"]",
		],
		[
			'title' => 'E1 Fixture Form 2',
			'body'  => "[text subject \"special chars: _ * [ ] ( ) ~ ` > # + - = | { } . !\"]\n[telegram]\n[submit \"Send\"]",
		],
	];

	foreach ( $form_definitions as $definition ) {
		$post_id = wp_insert_post(
			[
				'post_type'    => 'wpcf7_contact_form',
				'post_status'  => 'publish',
				'post_title'   => $definition['title'],
				'post_content' => $definition['body'],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$result['forms'][] = [
				'title' => $definition['title'],
				'error' => $post_id->get_error_message(),
			];
			continue;
		}

		update_post_meta( $post_id, '_form', $definition['body'] );
		$result['forms'][] = [
			'id'    => (int) $post_id,
			'title' => $definition['title'],
		];
		$e2_expectations['forms'][] = [
			'id'                    => (int) $post_id,
			'title'                 => $definition['title'],
			'post_content_sha256'   => hash( 'sha256', $definition['body'] ),
			'_form_sha256'          => hash( 'sha256', $definition['body'] ),
			'contains_telegram_tag' => str_contains( $definition['body'], '[telegram]' ),
		];
	}
}

if ( 'partial-modern' === $fixture ) {
	foreach ( [ 'cf7tg_bot', 'cf7tg_chat', 'cf7tg_channel' ] as $post_type ) {
		$post_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'E1 partial fixture ' . $post_type,
			],
			true
		);

		$result['forms'][] = is_wp_error( $post_id )
			? [ 'post_type' => $post_type, 'error' => $post_id->get_error_message() ]
			: [ 'post_type' => $post_type, 'id' => (int) $post_id ];
	}

	update_option( 'cf7tg_version', '1.0.0', false );
	$result['options'][] = 'cf7tg_version';
	$e2_expectations['legacy'] = [
		'has_legacy_state'      => false,
		'valid_chat_rows'       => 0,
		'malformed_chat_rows'   => 0,
		'expected_bot_count'    => 1,
		'expected_channel_count' => 1,
	];
}

if ( ! empty( $e2_expectations['legacy'] ) || ! empty( $e2_expectations['forms'] ) ) {
	update_option( 'cf7tg_e2_fixture_expectations', $e2_expectations, false );
	$result['options'][] = 'cf7tg_e2_fixture_expectations';
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
