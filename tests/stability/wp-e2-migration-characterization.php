<?php
/**
 * Emits E2 migration characterization evidence for an isolated smoke site.
 *
 * Intended to be executed with WP-CLI only:
 * wp eval-file /e1-tests/wp-e2-migration-characterization.php after-migration-run
 * wp eval-file /e1-tests/wp-e2-migration-characterization.php rerun
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;

$stage = $args[0] ?? 'state';
$connections_table = $wpdb->prefix . 'post_connections_cf7_telegram';
$meta_table        = $wpdb->prefix . 'post_connections_meta_cf7_telegram';
$expectations      = get_option( 'cf7tg_e2_fixture_expectations', [] );

if ( ! is_array( $expectations ) ) {
	$expectations = [];
}

$checks = [];

$add_check = static function ( string $id, bool $passed, string $message, array $extra = [] ) use ( &$checks ): void {
	$checks[] = array_merge(
		[
			'id'      => $id,
			'status'  => $passed ? 'pass' : 'expected_fail',
			'message' => $message,
		],
		$extra
	);
};

$table_exists = static function ( string $table ) use ( $wpdb ): bool {
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
};

$post_count = static function ( string $post_type ) use ( $wpdb ): int {
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft')",
			$post_type
		)
	);
};

$relation_count = static function ( string $relation ) use ( $wpdb, $connections_table, $table_exists ): int {
	if ( ! $table_exists( $connections_table ) ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM `{$connections_table}` WHERE relation = %s",
			$relation
		)
	);
};

$duplicate_relation_count = static function () use ( $wpdb, $connections_table, $table_exists ): int {
	if ( ! $table_exists( $connections_table ) ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM (
			SELECT relation, `from`, `to`, COUNT(*) AS duplicate_count
			FROM `{$connections_table}`
			GROUP BY relation, `from`, `to`
			HAVING duplicate_count > 1
		) duplicates"
	);
};

$form_state = static function () use ( $wpdb ): array {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_content, pm.meta_value AS form_meta
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			WHERE p.post_type = %s
			ORDER BY p.ID",
			'_form',
			'wpcf7_contact_form'
		),
		ARRAY_A
	);

	return array_map(
		static function ( array $row ): array {
			$content = (string) ( $row['post_content'] ?? '' );
			$meta    = (string) ( $row['form_meta'] ?? '' );

			return [
				'id'                      => (int) $row['ID'],
				'title'                   => (string) $row['post_title'],
				'post_content_sha256'     => hash( 'sha256', $content ),
				'_form_sha256'            => hash( 'sha256', $meta ),
				'post_content_has_tag'    => str_contains( $content, '[telegram]' ),
				'_form_has_tag'           => str_contains( $meta, '[telegram]' ),
			];
		},
		is_array( $rows ) ? $rows : []
	);
};

$migration_state = static function (): array {
	$state = get_option( 'cf7tg_migration_state', [] );

	return is_array( $state ) ? $state : [];
};

$state_fingerprint = static function () use ( $post_count, $relation_count, $duplicate_relation_count, $form_state, $migration_state ): string {
	$state = [
		'posts'      => [
			'bot'     => $post_count( 'cf7tg_bot' ),
			'chat'    => $post_count( 'cf7tg_chat' ),
			'channel' => $post_count( 'cf7tg_channel' ),
			'form'    => $post_count( 'wpcf7_contact_form' ),
		],
		'relations'  => [
			'bot2chat'     => $relation_count( 'bot2chat' ),
			'chat2channel' => $relation_count( 'chat2channel' ),
			'bot2channel'  => $relation_count( 'bot2channel' ),
			'form2channel' => $relation_count( 'form2channel' ),
			'duplicates'   => $duplicate_relation_count(),
		],
		'forms'      => $form_state(),
		'migrations' => [
			'v1_0_alpha' => (bool) get_option( 'cf7tg_migration_1.0-alpha', false ),
			'state'      => $migration_state(),
		],
	];

	return hash( 'sha256', wp_json_encode( $state ) );
};

if ( 'rerun' === $stage ) {
	$before = $state_fingerprint();
	$before_state = $migration_state();
	do_action( 'cf7tg_migrations', [], '0.0' );
	$after = $state_fingerprint();
	$after_state = $migration_state();

	$add_check(
		'second_run_no_state_change',
		$before === $after,
		'Running cf7tg_migrations a second time does not change migrated entities, relations, forms, or migration flags.',
		[
			'dependency' => 'E2.3 idempotent migration implementation',
			'before'     => $before,
			'after'      => $after,
		]
	);

	$add_check(
		'migration_state_second_run_stable',
		( $before_state['status'] ?? null ) === ( $after_state['status'] ?? null )
			&& (int) ( $before_state['attempts'] ?? -1 ) === (int) ( $after_state['attempts'] ?? -2 )
			&& ( $before_state['steps'] ?? null ) === ( $after_state['steps'] ?? null )
			&& ( $after_state['errors'] ?? null ) === [],
		'Durable migration state status, attempts, completed steps, and errors remain stable on a second migration run.',
		[
			'dependency' => 'E2.3 idempotent migration implementation',
			'before'     => $before_state,
			'after'      => $after_state,
		]
	);
}

$legacy = $expectations['legacy'] ?? [];
$forms  = is_array( $expectations['forms'] ?? null ) ? $expectations['forms'] : [];

$scheduled_migrations = 0;
foreach ( _get_cron_array() as $hooks ) {
	if ( ! empty( $hooks['cf7tg_migrations'] ) && is_array( $hooks['cf7tg_migrations'] ) ) {
		$scheduled_migrations += count( $hooks['cf7tg_migrations'] );
	}
}

if ( 'after-upgrade' === $stage && ! empty( $legacy['has_legacy_state'] ) ) {
	$state = $migration_state();

	$add_check(
		'migration_event_scheduled_or_self_healed',
		$scheduled_migrations >= 1,
		'Legacy upgrade state schedules or self-heals a cf7tg_migrations event after candidate activation.',
		[
			'dependency' => 'E2.2 durable self-healing migration scheduler',
			'actual'     => $scheduled_migrations,
		]
	);

	$add_check(
		'migration_state_scheduled',
		1 === (int) ( $state['schema'] ?? 0 )
			&& 'scheduled' === ( $state['status'] ?? '' )
			&& 0 === (int) ( $state['attempts'] ?? -1 )
			&& isset( $state['steps'] ) && [] === $state['steps']
			&& isset( $state['errors'] ) && [] === $state['errors']
			&& in_array( $state['reason'] ?? '', [ 'plugin_update', 'self_heal' ], true )
			&& '' !== (string) ( $state['source_version'] ?? '' )
			&& '' !== (string) ( $state['target_version'] ?? '' ),
		'Durable migration state records a scheduled run with zero attempts, no steps, and no errors after upgrade.',
		[
			'dependency' => 'E2.2 durable self-healing migration scheduler',
			'actual'     => $state,
		]
	);
}

if ( in_array( $stage, [ 'after-migration-run', 'after-second-migration-run', 'rerun' ], true ) && ! empty( $legacy['has_legacy_state'] ) ) {
	$expected_chats    = (int) ( $legacy['valid_chat_rows'] ?? 0 );
	$expected_forms    = count( $forms );
	$actual_posts      = [
		'bot'     => $post_count( 'cf7tg_bot' ),
		'chat'    => $post_count( 'cf7tg_chat' ),
		'channel' => $post_count( 'cf7tg_channel' ),
	];
	$actual_relations  = [
		'bot2chat'     => $relation_count( 'bot2chat' ),
		'chat2channel' => $relation_count( 'chat2channel' ),
		'bot2channel'  => $relation_count( 'bot2channel' ),
		'form2channel' => $relation_count( 'form2channel' ),
	];
	$duplicate_count   = $duplicate_relation_count();
	$migration_done    = (bool) get_option( 'cf7tg_migration_1.0-alpha', false );
	$state             = $migration_state();
	$step_state        = isset( $state['steps']['1.0-alpha'] ) && is_array( $state['steps']['1.0-alpha'] )
		? $state['steps']['1.0-alpha']
		: [];

	$add_check(
		'migration_state_completed',
		1 === (int) ( $state['schema'] ?? 0 )
			&& 'completed' === ( $state['status'] ?? '' )
			&& 1 <= (int) ( $state['attempts'] ?? 0 )
			&& '' === (string) ( $state['current_step'] ?? 'unexpected' )
			&& isset( $state['errors'] ) && [] === $state['errors']
			&& 'completed' === ( $step_state['status'] ?? '' )
			&& 1 <= (int) ( $step_state['attempts'] ?? 0 ),
		'Durable migration state records completed status, completed 1.0-alpha step, attempts, and no errors after migration run.',
		[
			'dependency' => 'E2.2 durable migration state contract',
			'actual'     => [
				'state' => $state,
				'step'  => $step_state,
			],
		]
	);

	$add_check(
		'migration_flag_set_only_after_success',
		$migration_done && $actual_posts['bot'] >= 1 && $actual_posts['channel'] >= 1 && $actual_posts['chat'] >= $expected_chats,
		'Migration completion flag is present only with expected migrated entities.',
		[
			'dependency' => 'E2.3 entity migration, malformed input, and partial retry',
			'actual'     => [
				'migration_done' => $migration_done,
				'posts'          => $actual_posts,
			],
		]
	);

	$add_check(
		'expected_entity_counts',
		1 === $actual_posts['bot'] && 1 === $actual_posts['channel'] && $expected_chats === $actual_posts['chat'],
		'Migration creates exactly one bot, one channel, and one chat per valid legacy chat row.',
		[
			'dependency' => 'E2.3 entity migration, malformed input, and partial retry',
			'expected'   => [
				'bot'     => 1,
				'channel' => 1,
				'chat'    => $expected_chats,
			],
			'actual'     => $actual_posts,
		]
	);

	$add_check(
		'expected_relation_counts',
		$expected_chats === $actual_relations['bot2chat']
			&& $expected_chats === $actual_relations['chat2channel']
			&& 1 === $actual_relations['bot2channel']
			&& $expected_forms === $actual_relations['form2channel'],
		'Migration creates expected bot/chat/channel/form relation counts.',
		[
			'dependency' => 'E2.3 entity migration, malformed input, and partial retry',
			'expected'   => [
				'bot2chat'     => $expected_chats,
				'chat2channel' => $expected_chats,
				'bot2channel'  => 1,
				'form2channel' => $expected_forms,
			],
			'actual'     => $actual_relations,
		]
	);

	$add_check(
		'no_duplicate_relations',
		0 === $duplicate_count,
		'Relation table has no duplicate relation/from/to rows.',
		[
			'dependency' => 'E2.3 idempotent migration implementation',
			'actual'     => $duplicate_count,
		]
	);
}

if ( in_array( $stage, [ 'after-migration-run', 'after-second-migration-run', 'rerun' ], true ) && $forms ) {
	$current_forms = [];
	foreach ( $form_state() as $form ) {
		$current_forms[ $form['id'] ] = $form;
	}

	foreach ( $forms as $expected_form ) {
		$form_id = (int) ( $expected_form['id'] ?? 0 );
		$current = $current_forms[ $form_id ] ?? null;

		$add_check(
			'form_' . $form_id . '_post_content_preserved',
			$current && ( $expected_form['post_content_sha256'] ?? null ) === $current['post_content_sha256'],
			'CF7 form post_content is preserved during migration.',
			[
				'dependency' => 'E2.4 approved [telegram] preservation/admin recovery contract',
				'form_id'    => $form_id,
				'expected'   => $expected_form['post_content_sha256'] ?? null,
				'actual'     => $current['post_content_sha256'] ?? null,
			]
		);

		$add_check(
			'form_' . $form_id . '_meta_preserved',
			$current && ( $expected_form['_form_sha256'] ?? null ) === $current['_form_sha256'],
			'CF7 form _form meta is preserved during migration.',
			[
				'dependency' => 'E2.4 approved [telegram] preservation/admin recovery contract',
				'form_id'    => $form_id,
				'expected'   => $expected_form['_form_sha256'] ?? null,
				'actual'     => $current['_form_sha256'] ?? null,
			]
		);

		$add_check(
			'form_' . $form_id . '_telegram_tag_retained',
			$current && $current['post_content_has_tag'] && $current['_form_has_tag'],
			'Approved contract: migration does not automatically remove literal [telegram] tags from CF7 content/meta.',
			[
				'dependency' => 'E2.4 approved [telegram] preservation/admin recovery contract',
				'form_id'    => $form_id,
				'actual'     => $current ? [
					'post_content_has_tag' => $current['post_content_has_tag'],
					'_form_has_tag'        => $current['_form_has_tag'],
				] : null,
			]
		);
	}
}

if ( 'partial-modern' === ( $expectations['fixture'] ?? '' ) ) {
	$add_check(
		'partial_modern_not_duplicated',
		1 === $post_count( 'cf7tg_bot' ) && 1 === $post_count( 'cf7tg_chat' ) && 1 === $post_count( 'cf7tg_channel' ),
		'Partial modern fixture remains a single bot/chat/channel set and is not duplicated by legacy migration.',
		[
			'dependency' => 'E2.3 entity migration, malformed input, and partial retry',
			'actual'     => [
				'bot'     => $post_count( 'cf7tg_bot' ),
				'chat'    => $post_count( 'cf7tg_chat' ),
				'channel' => $post_count( 'cf7tg_channel' ),
			],
		]
	);
}

echo wp_json_encode(
	[
		'stage'        => $stage,
		'fixture'      => $expectations['fixture'] ?? getenv( 'CF7TG_E1_FIXTURE' ),
		'fingerprint'  => $state_fingerprint(),
		'expectations' => $expectations,
		'checks'       => $checks,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
