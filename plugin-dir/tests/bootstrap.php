<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'WPCF7TG_PLUGIN_NAME' ) ) {
	define( 'WPCF7TG_PLUGIN_NAME', 'cf7-telegram/cf7-telegram.php' );
}

if ( ! defined( 'WPCF7TG_VERSION' ) ) {
	define( 'WPCF7TG_VERSION', '1.0.10' );
}

if ( ! defined( 'WPCF7TG_FILE' ) ) {
	define( 'WPCF7TG_FILE', dirname( __DIR__ ) . '/cf7-telegram.php' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_status = 'publish';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public static array $last_args = [];
		public array $posts = [];

		public function __construct( array $args = [] ) {
			self::$last_args = $args;

			$post_type = $args['post_type'] ?? '';
			$posts = $GLOBALS['wp_query_posts'][ $post_type ] ?? [];
			$limit = $args['posts_per_page'] ?? 10;

			$this->posts = -1 === $limit ? $posts : array_slice( $posts, 0, (int) $limit );
		}
	}
}

class Cf7tg_Test_Wpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public array $tables = [];
	public int $rows_affected = 0;

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function has_cap( string $capability ): bool {
		return false;
	}

	public function insert( string $table, array $data, array $format = [] ): bool {
		$GLOBALS['wpdb_inserts'][] = [
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		];

		return true;
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		return (string) preg_replace_callback(
			'/%[isd]/',
			function ( array $matches ) use ( &$args ): string {
				$value = array_shift( $args );

				if ( '%d' === $matches[0] ) {
					return (string) (int) $value;
				}

				if ( '%i' === $matches[0] ) {
					return '`' . str_replace( '`', '``', (string) $value ) . '`';
				}

				return "'" . str_replace( "'", "\\'", (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_col( string $query ): array {
		if ( preg_match( "/SELECT option_name FROM `?wp_options`? WHERE option_name LIKE '([^']*)%'/", $query, $matches ) ) {
			$prefix = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], stripslashes( $matches[1] ) );

			return array_values(
				array_filter(
					array_keys( $GLOBALS['wp_options'] ),
					static fn( string $name ): bool => str_starts_with( $name, $prefix )
				)
			);
		}

		if ( preg_match( '/SELECT ID FROM `?([^`\s]+)`?/i', $query, $matches ) && $this->isConnectionsTable( $matches[1] ) ) {
			return array_map(
				static fn( object $row ): int => (int) $row->ID,
				$this->filterConnectionRows( $query )
			);
		}

		return [];
	}

	public function get_results( string $query ): array {
		if ( preg_match( '/FROM `?([^`\s]+)`?/i', $query, $matches ) && $this->isConnectionsTable( $matches[1] ) ) {
			return $this->filterConnectionRows( $query );
		}

		return [];
	}

	public function get_var( string $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches ) ) {
			$table = stripslashes( $matches[1] );
			return in_array( $table, $this->tables, true ) ? $table : null;
		}

		return null;
	}

	public function query( string $query ): int {
		$this->rows_affected = 0;

		if ( preg_match( '/DELETE FROM `?([^`\s]+)`? WHERE connection_id IN \(([^)]*)\)/i', $query, $matches ) && $this->isConnectionsMetaTable( $matches[1] ) ) {
			$ids = $this->parseIntegerList( $matches[2] );
			$before = count( $GLOBALS['wp_connection_meta_rows'] );
			$GLOBALS['wp_connection_meta_rows'] = array_values(
				array_filter(
					$GLOBALS['wp_connection_meta_rows'],
					static fn( object $row ): bool => ! in_array( (int) $row->connection_id, $ids, true )
				)
			);
			$this->rows_affected = $before - count( $GLOBALS['wp_connection_meta_rows'] );
			return $this->rows_affected;
		}

		if ( preg_match( '/DELETE FROM `?([^`\s]+)`? WHERE ID IN \(([^)]*)\)/i', $query, $matches ) && $this->isConnectionsTable( $matches[1] ) ) {
			$ids = $this->parseIntegerList( $matches[2] );
			$before = count( $GLOBALS['wp_connection_rows'] );
			$GLOBALS['wp_connection_rows'] = array_values(
				array_filter(
					$GLOBALS['wp_connection_rows'],
					static fn( object $row ): bool => ! in_array( (int) $row->ID, $ids, true )
				)
			);
			$this->rows_affected = $before - count( $GLOBALS['wp_connection_rows'] );
			return $this->rows_affected;
		}

		if ( preg_match( '/DROP TABLE IF EXISTS `?([^`\s;]+)`?/', $query, $matches ) ) {
			$this->tables = array_values( array_diff( $this->tables, [ $matches[1] ] ) );
		}

		return 0;
	}

	private function filterConnectionRows( string $query ): array {
		$relations = $this->extractStringInList( $query, 'relation' );
		$fromIDs = $this->extractIntegerInList( $query, '`from`' );
		$toIDs = $this->extractIntegerInList( $query, '`to`' );

		return array_values(
			array_filter(
				$GLOBALS['wp_connection_rows'],
				static function ( object $row ) use ( $relations, $fromIDs, $toIDs ): bool {
					if ( ! empty( $relations ) && ! in_array( (string) $row->relation, $relations, true ) ) {
						return false;
					}

					if ( empty( $fromIDs ) && empty( $toIDs ) ) {
						return true;
					}

					return in_array( (int) $row->from, $fromIDs, true ) || in_array( (int) $row->to, $toIDs, true );
				}
			)
		);
	}

	private function extractStringInList( string $query, string $column ): array {
		$pattern = '/' . preg_quote( $column, '/' ) . '\s+IN\s+\(([^)]*)\)/i';

		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return [];
		}

		return array_map(
			static fn( string $value ): string => stripslashes( trim( $value, " \t\n\r\0\x0B'" ) ),
			array_filter( array_map( 'trim', explode( ',', $matches[1] ) ), 'strlen' )
		);
	}

	private function extractIntegerInList( string $query, string $column ): array {
		$pattern = '/' . preg_quote( $column, '/' ) . '\s+IN\s+\(([^)]*)\)/i';

		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return [];
		}

		return $this->parseIntegerList( $matches[1] );
	}

	private function parseIntegerList( string $list ): array {
		return array_map(
			'intval',
			array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' )
		);
	}

	private function isConnectionsTable( string $table ): bool {
		return $table === $this->prefix . 'post_connections_cf7_telegram';
	}

	private function isConnectionsMetaTable( string $table ): bool {
		return $table === $this->prefix . 'post_connections_meta_cf7_telegram';
	}
}

if ( ! function_exists( 'cf7tg_test_unique_callback_id' ) ) {
	function cf7tg_test_unique_callback_id( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $class . '::' . (string) $callback[1];
		}

		if ( $callback instanceof Closure ) {
			return spl_object_hash( $callback );
		}

		return md5( serialize( $callback ) );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$id = cf7tg_test_unique_callback_id( $callback );
		$GLOBALS['wp_filter'][ $hook_name ][ $priority ][ $id ] = [
			'function'      => $callback,
			'accepted_args' => $accepted_args,
		];

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		if ( empty( $GLOBALS['wp_filter'][ $hook_name ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['wp_filter'][ $hook_name ] );

		foreach ( $GLOBALS['wp_filter'][ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$call_args = array_slice( array_merge( [ $value ], $args ), 0, $callback['accepted_args'] );
				$value = call_user_func_array( $callback['function'], $call_args );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		if ( empty( $GLOBALS['wp_filter'][ $hook_name ] ) ) {
			return;
		}

		ksort( $GLOBALS['wp_filter'][ $hook_name ] );

		foreach ( $GLOBALS['wp_filter'][ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$call_args = array_slice( $args, 0, $callback['accepted_args'] );
				call_user_func_array( $callback['function'], $call_args );
			}
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['current_user_can'] ?? true;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string $message ): void {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action, string $query_arg = '_wpnonce' ): bool {
		$GLOBALS['checked_admin_referer'] = compact( 'action', 'query_arg' );
		return true;
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	function wp_get_referer() {
		return $GLOBALS['wp_referer'] ?? false;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location ): bool {
		$GLOBALS['wp_safe_redirect_location'] = $location;
		return true;
	}
}

if ( ! function_exists( 'wpcf7_add_form_tag' ) ) {
	function wpcf7_add_form_tag( $tag, $callback, array $features = [] ): bool {
		$GLOBALS['wpcf7_form_tags'][ $tag ] = [
			'callback' => $callback,
			'features' => $features,
		];

		return true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( $title );
		$title = preg_replace( '/[^a-z0-9_-]+/', '-', $title ) ?: '';
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\\-]/', '', $key ) ?: '';
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['wp_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( $option, $GLOBALS['wp_options'] ) ) {
			return false;
		}

		$GLOBALS['wp_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		$exists = array_key_exists( $option, $GLOBALS['wp_options'] );
		unset( $GLOBALS['wp_options'][ $option ] );
		return $exists;
	}
}

if ( ! function_exists( 'wp_get_schedules' ) ) {
	function wp_get_schedules(): array {
		$schedules = [
			'hourly'     => [
				'interval' => HOUR_IN_SECONDS,
				'display'  => 'Once Hourly',
			],
			'twicedaily' => [
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => 'Twice Daily',
			],
			'daily'      => [
				'interval' => 24 * HOUR_IN_SECONDS,
				'display'  => 'Once Daily',
			],
		];

		return apply_filters( 'cron_schedules', $schedules );
	}
}

if ( ! function_exists( '_get_cron_array' ) ) {
	function _get_cron_array(): array {
		$cron = get_option( 'cron', [] );

		if ( ! is_array( $cron ) ) {
			return [];
		}

		unset( $cron['version'] );
		ksort( $cron );

		return $cron;
	}
}

if ( ! function_exists( '_set_cron_array' ) ) {
	function _set_cron_array( array $cron, bool $wp_error = false ) {
		$cron['version'] = 2;
		update_option( 'cron', $cron, true );
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, string $hook, array $args = [], bool $wp_error = false ) {
		if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
			return $wp_error ? new WP_Error( 'invalid_timestamp', 'Event timestamp must be valid.' ) : false;
		}

		$timestamp = (int) $timestamp;
		$key = md5( serialize( $args ) );
		$crons = _get_cron_array();
		$event = (object) [
			'hook'      => $hook,
			'timestamp' => $timestamp,
			'schedule'  => false,
			'args'      => $args,
		];
		$pre = apply_filters( 'pre_schedule_event', null, $event, $wp_error );

		if ( null !== $pre ) {
			return $pre;
		}

		$min_timestamp = $timestamp < time() + 10 * MINUTE_IN_SECONDS ? 0 : $timestamp - 10 * MINUTE_IN_SECONDS;
		$max_timestamp = $timestamp < time() ? time() + 10 * MINUTE_IN_SECONDS : $timestamp + 10 * MINUTE_IN_SECONDS;

		foreach ( $crons as $event_timestamp => $cron ) {
			if ( $event_timestamp < $min_timestamp ) {
				continue;
			}

			if ( $event_timestamp > $max_timestamp ) {
				break;
			}

			if ( isset( $cron[ $hook ][ $key ] ) ) {
				return $wp_error ? new WP_Error( 'duplicate_event', 'A duplicate event already exists.' ) : false;
			}
		}

		$crons[ $timestamp ][ $hook ][ $key ] = [
			'schedule' => false,
			'args'     => $args,
		];
		ksort( $crons );

		return _set_cron_array( $crons, $wp_error );
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ) {
		if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
			return $wp_error ? new WP_Error( 'invalid_timestamp', 'Event timestamp must be valid.' ) : false;
		}

		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $recurrence ] ) ) {
			$error = new WP_Error( 'invalid_schedule', 'Event schedule does not exist.' );
			$GLOBALS['wp_schedule_errors'][] = $error;
			return $wp_error ? $error : false;
		}

		$timestamp = (int) $timestamp;
		$key = md5( serialize( $args ) );
		$crons = _get_cron_array();
		$event = (object) [
			'hook'      => $hook,
			'timestamp' => $timestamp,
			'schedule'  => $recurrence,
			'args'      => $args,
			'interval'  => $schedules[ $recurrence ]['interval'],
		];
		$pre = apply_filters( 'pre_schedule_event', null, $event, $wp_error );

		if ( null !== $pre ) {
			if ( is_wp_error( $pre ) ) {
				$GLOBALS['wp_schedule_errors'][] = $pre;
			}

			return $pre;
		}

		$crons[ $timestamp ][ $hook ][ $key ] = [
			'schedule' => $recurrence,
			'args'     => $args,
			'interval' => $schedules[ $recurrence ]['interval'],
		];
		ksort( $crons );

		return _set_cron_array( $crons, $wp_error );
	}
}

if ( ! function_exists( 'wp_get_scheduled_event' ) ) {
	function wp_get_scheduled_event( string $hook, array $args = [], $timestamp = null ) {
		$crons = _get_cron_array();
		$key = md5( serialize( $args ) );

		if ( null === $timestamp ) {
			foreach ( $crons as $event_timestamp => $cron ) {
				if ( isset( $cron[ $hook ][ $key ] ) ) {
					$timestamp = $event_timestamp;
					break;
				}
			}
		}

		if ( null === $timestamp || ! isset( $crons[ $timestamp ][ $hook ][ $key ] ) ) {
			return false;
		}

		$event = (object) [
			'hook'      => $hook,
			'timestamp' => (int) $timestamp,
			'schedule'  => $crons[ $timestamp ][ $hook ][ $key ]['schedule'],
			'args'      => $args,
		];

		if ( isset( $crons[ $timestamp ][ $hook ][ $key ]['interval'] ) ) {
			$event->interval = $crons[ $timestamp ][ $hook ][ $key ]['interval'];
		}

		return $event;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ) {
		$event = wp_get_scheduled_event( $hook, $args );
		return $event ? $event->timestamp : false;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = [] ): int {
		$crons = _get_cron_array();
		$key = md5( serialize( $args ) );
		$removed = 0;

		foreach ( $crons as $timestamp => $cron ) {
			if ( ! isset( $cron[ $hook ][ $key ] ) ) {
				continue;
			}

			unset( $crons[ $timestamp ][ $hook ][ $key ] );
			$removed++;

			if ( empty( $crons[ $timestamp ][ $hook ] ) ) {
				unset( $crons[ $timestamp ][ $hook ] );
			}

			if ( empty( $crons[ $timestamp ] ) ) {
				unset( $crons[ $timestamp ] );
			}
		}

		_set_cron_array( $crons );

		return $removed;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array {
		$post_type = $args['post_type'] ?? '';
		return $GLOBALS['wp_posts_by_type'][ $post_type ] ?? [];
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ) {
		return $GLOBALS['wp_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force_delete = false ) {
		$post = $GLOBALS['wp_posts'][ $post_id ] ?? null;

		if ( $post instanceof WP_Post ) {
			do_action( 'before_delete_post', $post_id, $post );
		}

		$GLOBALS['wp_deleted_posts'][] = $post_id;
		foreach ( $GLOBALS['wp_posts_by_type'] as &$post_ids ) {
			$post_ids = array_values( array_diff( $post_ids, [ $post_id ] ) );
		}
		unset( $post_ids );
		unset( $GLOBALS['wp_posts'][ $post_id ] );
		return true;
	}
}

function cf7tg_test_reset_environment(): void {
	$GLOBALS['wp_filter'] = [];
	$GLOBALS['wp_options'] = [];
	$GLOBALS['wp_posts'] = [];
	$GLOBALS['wp_posts_by_type'] = [];
	$GLOBALS['wp_query_posts'] = [];
	$GLOBALS['wp_deleted_posts'] = [];
	$GLOBALS['wp_connection_rows'] = [];
	$GLOBALS['wp_connection_meta_rows'] = [];
	$GLOBALS['wpdb_inserts'] = [];
	$GLOBALS['wp_schedule_errors'] = [];
	$GLOBALS['wpcf7_form_tags'] = [];
	$GLOBALS['current_user_can'] = true;
	$GLOBALS['checked_admin_referer'] = null;
	$GLOBALS['wp_safe_redirect_location'] = null;
	$GLOBALS['wp_referer'] = false;
	$GLOBALS['wpdb'] = new Cf7tg_Test_Wpdb();
}

function cf7tg_test_cron_events( string $hook ): array {
	$events = [];

	foreach ( _get_cron_array() as $timestamp => $cron ) {
		if ( ! isset( $cron[ $hook ] ) ) {
			continue;
		}

		foreach ( $cron[ $hook ] as $event_key => $event ) {
			$events[] = array_merge(
				[
					'timestamp' => (int) $timestamp,
					'event_key' => (string) $event_key,
				],
				$event
			);
		}
	}

	return $events;
}

cf7tg_test_reset_environment();

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
