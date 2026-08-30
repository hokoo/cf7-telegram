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

$render_contact_form = static function ( int $form_id ): array {
	$result = [
		'available'                    => false,
		'contains_literal_telegram_tag' => null,
		'rendered_html_sha256'          => '',
		'error'                        => '',
	];

	if ( ! class_exists( 'WPCF7_ContactForm' ) || ! method_exists( 'WPCF7_ContactForm', 'get_instance' ) ) {
		$result['error'] = 'Contact Form 7 renderer is not available.';
		return $result;
	}

	try {
		$contact_form = WPCF7_ContactForm::get_instance( $form_id );

		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'form_html' ) ) {
			$result['error'] = 'Contact Form 7 form instance cannot render HTML.';
			return $result;
		}

		$buffer_level = ob_get_level();
		ob_start();
		$rendered = $contact_form->form_html();
		$captured = ob_get_clean();

		if ( ! is_string( $rendered ) ) {
			$rendered = $captured;
		}

		$result['available'] = true;
		$result['contains_literal_telegram_tag'] = str_contains( $rendered, '[telegram]' );
		$result['rendered_html_sha256'] = hash( 'sha256', $rendered );
	} catch ( Throwable $e ) {
		while ( ob_get_level() > ( $buffer_level ?? ob_get_level() ) ) {
			ob_end_clean();
		}

		$result['error'] = $e->getMessage();
	}

	return $result;
};

$migration_state = static function (): array {
	$state = get_option( 'cf7tg_migration_state', [] );

	return is_array( $state ) ? $state : [];
};

$run_cleanup_repair_probe = static function (): array {
	$maintenance_class = '\iTRON\cf7Telegram\Maintenance';
	$result = [
		'available'                 => false,
		'test_chat_id'              => 0,
		'exists_after_scheduled'    => null,
		'exists_after_dry_run'      => null,
		'dry_run_result'            => null,
		'dry_run_preserved_test_id' => false,
		'cleaned_up'                => false,
		'error'                     => '',
	];

	if (
		! class_exists( $maintenance_class )
		|| ! method_exists( $maintenance_class, 'runScheduledCleanup' )
		|| ! method_exists( $maintenance_class, 'runRepair' )
	) {
		$result['error'] = 'Maintenance repair API is not available.';
		return $result;
	}

	$post_id = wp_insert_post(
		[
			'post_type'   => 'cf7tg_chat',
			'post_status' => 'publish',
			'post_title'  => 'E2 cleanup preservation probe',
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		$result['error'] = $post_id->get_error_message();
		return $result;
	}

	$result['available'] = true;
	$result['test_chat_id'] = (int) $post_id;

	try {
		call_user_func( [ $maintenance_class, 'runScheduledCleanup' ] );
		$result['exists_after_scheduled'] = get_post( $post_id ) instanceof WP_Post;

		$dry_run_mode = defined( $maintenance_class . '::REPAIR_MODE_DRY_RUN' )
			? constant( $maintenance_class . '::REPAIR_MODE_DRY_RUN' )
			: 'dry-run';
		$dry_run = call_user_func( [ $maintenance_class, 'runRepair' ], $dry_run_mode );
		$result['dry_run_result'] = is_array( $dry_run ) ? $dry_run : null;
		$result['exists_after_dry_run'] = get_post( $post_id ) instanceof WP_Post;
		$preserved_ids = $dry_run['preserved']['ambiguous_orphan_chat_ids'] ?? [];
		$result['dry_run_preserved_test_id'] = in_array( (int) $post_id, array_map( 'intval', is_array( $preserved_ids ) ? $preserved_ids : [] ), true );
	} catch ( Throwable $e ) {
		$result['error'] = $e->getMessage();
	} finally {
		if ( get_post( $post_id ) instanceof WP_Post ) {
			wp_delete_post( $post_id, true );
		}

		$result['cleaned_up'] = ! ( get_post( $post_id ) instanceof WP_Post );
	}

	return $result;
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

		$render = $render_contact_form( $form_id );

		$add_check(
			'form_' . $form_id . '_telegram_tag_no_literal_render_output',
			$current
				&& $current['post_content_has_tag']
				&& $current['_form_has_tag']
				&& $render['available']
				&& false === $render['contains_literal_telegram_tag'],
			'Actual Contact Form 7 rendering keeps stored [telegram] content/meta but emits no literal [telegram] HTML.',
			[
				'dependency' => 'E2.4 approved [telegram] preservation/admin recovery contract',
				'form_id'    => $form_id,
				'actual'     => [
					'post_content_has_tag'            => $current['post_content_has_tag'] ?? null,
					'_form_has_tag'                   => $current['_form_has_tag'] ?? null,
					'renderer_available'              => $render['available'],
					'render_contains_literal_tag'     => $render['contains_literal_telegram_tag'],
					'rendered_html_sha256'            => $render['rendered_html_sha256'],
					'render_error'                    => $render['error'],
				],
			]
		);
	}
}

if ( in_array( $stage, [ 'after-migration-run', 'after-second-migration-run', 'rerun' ], true ) ) {
	$repair_probe = $run_cleanup_repair_probe();
	$dry_run = is_array( $repair_probe['dry_run_result'] ?? null ) ? $repair_probe['dry_run_result'] : [];

	$add_check(
		'cleanup_scheduled_preserves_orphan_like_chat',
		$repair_probe['available']
			&& true === $repair_probe['exists_after_scheduled']
			&& true === $repair_probe['cleaned_up'],
		'Scheduled cleanup preserves an orphan-like cf7tg_chat and the characterization probe cleans up its test record.',
		[
			'dependency' => 'E2.5 server-owned deletion and dry-run behavior',
			'actual'     => [
				'test_chat_id'           => $repair_probe['test_chat_id'],
				'exists_after_scheduled' => $repair_probe['exists_after_scheduled'],
				'cleaned_up'             => $repair_probe['cleaned_up'],
				'error'                  => $repair_probe['error'],
			],
		]
	);

	$add_check(
		'cleanup_dry_run_preserves_orphan_like_chat',
		$repair_probe['available']
			&& true === $repair_probe['exists_after_dry_run']
			&& true === $repair_probe['dry_run_preserved_test_id']
			&& 0 === (int) ( $dry_run['applied']['deleted_chats'] ?? -1 )
			&& true === $repair_probe['cleaned_up'],
		'Cleanup dry-run reports an orphan-like cf7tg_chat as preserved and does not delete it.',
		[
			'dependency' => 'E2.5 server-owned deletion and dry-run behavior',
			'actual'     => [
				'test_chat_id'              => $repair_probe['test_chat_id'],
				'exists_after_dry_run'      => $repair_probe['exists_after_dry_run'],
				'dry_run_preserved_test_id' => $repair_probe['dry_run_preserved_test_id'],
				'applied_deleted_chats'     => $dry_run['applied']['deleted_chats'] ?? null,
				'cleaned_up'                => $repair_probe['cleaned_up'],
				'error'                     => $repair_probe['error'],
			],
		]
	);
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
