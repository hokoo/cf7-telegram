<?php
/**
 * Prints a JSON state snapshot for E1 lifecycle smoke tests.
 *
 * Intended to be executed with WP-CLI only:
 * wp eval-file /e1-tests/wp-state-snapshot.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_file = WP_PLUGIN_DIR . '/cf7-telegram/cf7-telegram.php';
$plugin_data = file_exists( $plugin_file ) ? get_plugin_data( $plugin_file, false, false ) : [];
$active      = is_plugin_active( 'cf7-telegram/cf7-telegram.php' );
$cron_array  = _get_cron_array();
$cron_hooks  = [
	'cf7tg_cleanup',
	'cf7tg_migrations',
];
$cron        = [];

foreach ( $cron_hooks as $hook ) {
	$cron[ $hook ] = [
		'total'      => 0,
		'recurring'  => 0,
		'single'     => 0,
		'events'     => [],
		'duplicates' => [],
	];
}

foreach ( $cron_array as $timestamp => $hooks ) {
	foreach ( $cron_hooks as $hook ) {
		if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
			continue;
		}

		foreach ( $hooks[ $hook ] as $event_key => $event ) {
			$schedule  = $event['schedule'] ?? false;
			$args      = $event['args'] ?? [];
			$signature = $hook . '|' . (string) $schedule . '|' . md5( wp_json_encode( $args ) );

			$cron[ $hook ]['total']++;
			if ( $schedule ) {
				$cron[ $hook ]['recurring']++;
			} else {
				$cron[ $hook ]['single']++;
			}

			if ( isset( $cron[ $hook ]['seen'][ $signature ] ) ) {
				$cron[ $hook ]['duplicates'][] = $signature;
			}
			$cron[ $hook ]['seen'][ $signature ] = true;

			$cron[ $hook ]['events'][] = [
				'timestamp' => (int) $timestamp,
				'gmt'       => gmdate( 'c', (int) $timestamp ),
				'schedule'  => $schedule ?: null,
				'args'      => $args,
				'event_key' => (string) $event_key,
			];
		}
	}
}

foreach ( $cron_hooks as $hook ) {
	unset( $cron[ $hook ]['seen'] );
}

$option_prefixes = [
	'cf7tg_',
	'cf7t_',
	'wpcf7_telegram_',
];
$options         = [];

foreach ( $option_prefixes as $prefix ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$options[ $prefix ] = [
		'count' => is_array( $names ) ? count( $names ) : 0,
		'names' => array_values( array_map( 'strval', is_array( $names ) ? $names : [] ) ),
	];
}

$post_types  = [
	'cf7tg_bot',
	'cf7tg_chat',
	'cf7tg_channel',
	'wpcf7_contact_form',
];
$post_counts = [];

foreach ( $post_types as $post_type ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_counts[ $post_type ] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
			$post_type
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

$table_names = [
	'post_connections_cf7_telegram'      => $wpdb->prefix . 'post_connections_cf7_telegram',
	'post_connections_meta_cf7_telegram' => $wpdb->prefix . 'post_connections_meta_cf7_telegram',
	'cf7tg_log'                          => $wpdb->prefix . 'cf7tg_log',
];
$tables      = [];

foreach ( $table_names as $key => $table_name ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$table_name
		)
	);
	$count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" ) : null;
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$tables[ $key ] = [
		'name'   => $table_name,
		'exists' => (bool) $exists,
		'count'  => $count,
	];
}

echo wp_json_encode(
	[
		'captured_at_gmt'  => gmdate( 'c' ),
		'wordpress'        => [
			'version' => get_bloginfo( 'version' ),
			'url'     => home_url(),
		],
		'php_version'      => PHP_VERSION,
		'plugin'           => [
			'file_exists' => file_exists( $plugin_file ),
			'active'      => $active,
			'version'     => $plugin_data['Version'] ?? null,
			'name'        => $plugin_data['Name'] ?? null,
		],
		'active_plugins'   => array_values( array_map( 'strval', (array) get_option( 'active_plugins', [] ) ) ),
		'cron'             => $cron,
		'options'          => $options,
		'post_counts'      => $post_counts,
		'tables'           => $tables,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
