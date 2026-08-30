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
	define( 'WPCF7TG_VERSION', '1.0.13' );
}

if ( ! defined( 'WPCF7TG_FILE' ) ) {
	define( 'WPCF7TG_FILE', dirname( __DIR__ ) . '/cf7-telegram.php' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_status = 'publish';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_content_filtered = '';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public static array $last_args = [];
		public array $posts = [];

		public function __construct( array $args = [] ) {
			self::$last_args = $args;

			$post_type = $args['post_type'] ?? '';
			$posts = $GLOBALS['wp_query_posts'][ $post_type ] ?? cf7tg_test_get_posts( $args, false );
			$limit = $args['posts_per_page'] ?? 10;

			$this->posts = -1 === $limit ? $posts : array_slice( $posts, 0, (int) $limit );
		}

		public function have_posts(): bool {
			return ! empty( $this->posts );
		}
	}
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	class WPCF7_ContactForm {
		public const post_type = 'wpcf7_contact_form';
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
	}
}

class Cf7tg_Test_Wpdb extends wpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public array $tables = [];
	public int $rows_affected = 0;
	public int $insert_id = 0;
	public string $last_error = '';
	private bool $suppress_errors = false;

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function has_cap( string $capability ): bool {
		return false;
	}

	public function suppress_errors( $suppress = null ): bool {
		$previous = $this->suppress_errors;

		if ( null !== $suppress ) {
			$this->suppress_errors = (bool) $suppress;
		}

		return $previous;
	}

	public function insert( string $table, array $data, array $format = [] ): bool {
		$this->last_error = '';
		$this->rows_affected = 1;

		if ( str_contains( $table, 'post_connections_meta_cf7_telegram' ) ) {
			$id = $GLOBALS['wp_next_connection_meta_id']++;
			$GLOBALS['wp_connection_meta_rows'][] = (object) [
				'meta_id'       => $id,
				'connection_id' => (int) $data['connection_id'],
				'meta_key'      => (string) $data['meta_key'],
				'meta_value'    => $data['meta_value'],
			];
			$this->insert_id = $id;
			return true;
		}

		if ( str_contains( $table, 'post_connections_cf7_telegram' ) ) {
			$id = $GLOBALS['wp_next_connection_id']++;
			$GLOBALS['wp_connection_rows'][] = (object) [
				'ID'       => $id,
				'relation' => (string) $data['relation'],
				'from'     => (int) $data['from'],
				'to'       => (int) $data['to'],
				'order'    => (int) ( $data['order'] ?? 0 ),
				'title'    => (string) ( $data['title'] ?? '' ),
			];
			$this->insert_id = $id;
			return true;
		}

		$GLOBALS['wpdb_inserts'][] = [
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		];

		return true;
	}

	public function update( string $table, array $data, array $where ): bool {
		$this->rows_affected = 0;

		if ( ! str_contains( $table, 'post_connections_cf7_telegram' ) || empty( $where['ID'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_connection_rows'] as $row ) {
			if ( (int) $row->ID !== (int) $where['ID'] ) {
				continue;
			}

			foreach ( [ 'from', 'to', 'order' ] as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$row->$key = (int) $data[ $key ];
				}
			}

			foreach ( [ 'relation', 'title' ] as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$row->$key = (string) $data[ $key ];
				}
			}

			$this->rows_affected = 1;
			return true;
		}

		return false;
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$prepared = (string) preg_replace_callback(
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

		return (string) preg_replace( "/''([^']*)''/", "'$1'", $prepared );
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
			return $this->hydrateConnectionRows( $this->filterConnectionRows( $query ) );
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

		if ( preg_match( '/DELETE FROM `?([^`\s]+)`? WHERE `?connection_id`? = (\d+)/i', $query, $matches ) && $this->isConnectionsMetaTable( $matches[1] ) ) {
			$connection_id = (int) $matches[2];
			$before = count( $GLOBALS['wp_connection_meta_rows'] );
			$GLOBALS['wp_connection_meta_rows'] = array_values(
				array_filter(
					$GLOBALS['wp_connection_meta_rows'],
					static fn( object $row ): bool => (int) $row->connection_id !== $connection_id
				)
			);
			$this->rows_affected = $before - count( $GLOBALS['wp_connection_meta_rows'] );
			return $this->rows_affected;
		}

		if ( preg_match( '/DELETE FROM `?([^`\s]+)`? WHERE `?connection_id`? IN \(([^)]*)\)/i', $query, $matches ) && $this->isConnectionsMetaTable( $matches[1] ) ) {
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

		if ( preg_match( '/DELETE FROM `?([^`\s]+)`? WHERE `?ID`? IN \(([^)]*)\)/i', $query, $matches ) && $this->isConnectionsTable( $matches[1] ) ) {
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

		if ( preg_match( '/CREATE TABLE IF NOT EXISTS ([^\s(]+)/', $query, $matches ) ) {
			$table = str_replace( $this->prefix, '', trim( $matches[1], '`' ) );
			$this->tables[] = $table;
		}

		return 0;
	}

	private function filterConnectionRows( string $query ): array {
		$relations = array_merge(
			$this->extractStringInList( $query, 'relation' ),
			$this->extractStringEquals( $query, 'c.relation' ),
			$this->extractStringEquals( $query, 'relation' )
		);
		$fromIDs = array_merge(
			$this->extractIntegerInList( $query, '`from`' ),
			$this->extractIntegerEquals( $query, 'c.from' ),
			$this->extractIntegerEquals( $query, 'from' )
		);
		$toIDs = array_merge(
			$this->extractIntegerInList( $query, '`to`' ),
			$this->extractIntegerEquals( $query, 'c.to' ),
			$this->extractIntegerEquals( $query, 'to' )
		);
		$usesOr = str_contains( $query, ' OR ' );

		return array_values(
			array_filter(
				$GLOBALS['wp_connection_rows'],
				static function ( object $row ) use ( $relations, $fromIDs, $toIDs, $usesOr ): bool {
					if ( ! empty( $relations ) && ! in_array( (string) $row->relation, $relations, true ) ) {
						return false;
					}

					if ( empty( $fromIDs ) && empty( $toIDs ) ) {
						return true;
					}

					if ( $usesOr && ! empty( $fromIDs ) && ! empty( $toIDs ) ) {
						return in_array( (int) $row->from, $fromIDs, true ) || in_array( (int) $row->to, $toIDs, true );
					}

					if ( ! empty( $fromIDs ) && ! in_array( (int) $row->from, $fromIDs, true ) ) {
						return false;
					}

					if ( ! empty( $toIDs ) && ! in_array( (int) $row->to, $toIDs, true ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	private function hydrateConnectionRows( array $connectionRows ): array {
		$rows = [];

		foreach ( $connectionRows as $connection ) {
			$metaRows = array_values(
				array_filter(
					$GLOBALS['wp_connection_meta_rows'],
					static fn( object $meta ): bool => (int) $meta->connection_id === (int) $connection->ID
				)
			);

			if ( empty( $metaRows ) ) {
				$row = clone $connection;
				$row->meta_id = null;
				$row->meta_key = null;
				$row->meta_value = null;
				$rows[] = $row;
				continue;
			}

			foreach ( $metaRows as $meta ) {
				$row = clone $connection;
				$row->meta_id = $meta->meta_id ?? null;
				$row->meta_key = $meta->meta_key ?? null;
				$row->meta_value = $meta->meta_value ?? null;
				$rows[] = $row;
			}
		}

		return $rows;
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

	private function extractStringEquals( string $query, string $column ): array {
		$pattern = '/' . preg_quote( $column, '/' ) . "\s*=\s*'([^']*)'/i";

		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return [];
		}

		return [ stripslashes( $matches[1] ) ];
	}

	private function extractIntegerInList( string $query, string $column ): array {
		$pattern = '/' . preg_quote( $column, '/' ) . '\s+IN\s+\(([^)]*)\)/i';

		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return [];
		}

		return $this->parseIntegerList( $matches[1] );
	}

	private function extractIntegerEquals( string $query, string $column ): array {
		$pattern = '/' . preg_quote( $column, '/' ) . '\s*=\s*(\d+)/i';

		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return [];
		}

		return [ (int) $matches[1] ];
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
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

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, array $defaults = [] ): array {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ): string {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( string $text, bool $strip = false ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = strip_tags( $text );
		return $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['current_user_can'] ?? true;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): object {
		return (object) [
			'cap' => (object) [
				'read_post'  => 'read_post',
				'edit_posts' => 'edit_posts',
			],
		];
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

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ) {
		$GLOBALS['wp_remote_post_requests'][] = [
			'url'  => $url,
			'args' => $args,
		];

		if ( is_callable( $GLOBALS['wp_remote_post_handler'] ?? null ) ) {
			return call_user_func( $GLOBALS['wp_remote_post_handler'], $url, $args );
		}

		return [
			'response' => [
				'code' => 200,
			],
			'headers'  => [],
			'body'     => '{"ok":true,"result":true}',
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, string $header ) {
		$headers = $response['headers'] ?? [];

		if ( ! is_array( $headers ) ) {
			return '';
		}

		foreach ( $headers as $name => $value ) {
			if ( strtolower( (string) $name ) === strtolower( $header ) ) {
				return is_array( $value ) ? reset( $value ) : $value;
			}
		}

		return '';
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
		return cf7tg_test_get_posts( $args );
	}
}

function cf7tg_test_get_posts( array $args = [], bool $respect_limit = true ): array {
	$post_type = $args['post_type'] ?? '';
	$fields = $args['fields'] ?? '';
	$limit = (int) ( $args['posts_per_page'] ?? 10 );
	$status = $args['post_status'] ?? 'publish';
	$search = (string) ( $args['s'] ?? '' );
	$posts = [];

	foreach ( $GLOBALS['wp_posts'] as $post ) {
		if ( '' !== $post_type && $post->post_type !== $post_type ) {
			continue;
		}

		if ( 'any' !== $status && $post->post_status !== $status ) {
			continue;
		}

		if (
			'' !== $search &&
			! str_contains( $post->post_title, $search ) &&
			! str_contains( $post->post_content, $search )
		) {
			continue;
		}

		$posts[] = 'ids' === $fields ? $post->ID : $post;
	}

	if ( $respect_limit && -1 !== $limit ) {
		$posts = array_slice( $posts, 0, $limit );
	}

	return $posts;
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ) {
		return $GLOBALS['wp_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ) {
		$id = $GLOBALS['wp_next_post_id']++;
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_type = (string) ( $postarr['post_type'] ?? 'post' );
		$post->post_status = (string) ( $postarr['post_status'] ?? 'draft' );
		$post->post_title = (string) ( $postarr['post_title'] ?? '' );
		$post->post_content = (string) ( $postarr['post_content'] ?? '' );
		$post->post_content_filtered = (string) ( $postarr['post_content_filtered'] ?? '' );

		$GLOBALS['wp_posts'][ $id ] = $post;
		$GLOBALS['wp_posts_by_type'][ $post->post_type ][] = $id;

		if ( ! empty( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) {
			foreach ( $postarr['meta_input'] as $key => $value ) {
				update_post_meta( $id, (string) $key, $value );
			}
		}

		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $id || empty( $GLOBALS['wp_posts'][ $id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post.' ) : 0;
		}

		$post = $GLOBALS['wp_posts'][ $id ];
		$old_type = $post->post_type;

		foreach ( [ 'post_type', 'post_status', 'post_title', 'post_content', 'post_content_filtered' ] as $field ) {
			if ( array_key_exists( $field, $postarr ) ) {
				$post->$field = (string) $postarr[ $field ];
			}
		}

		if ( $old_type !== $post->post_type ) {
			$GLOBALS['wp_posts_by_type'][ $old_type ] = array_values(
				array_diff( $GLOBALS['wp_posts_by_type'][ $old_type ] ?? [], [ $id ] )
			);
			$GLOBALS['wp_posts_by_type'][ $post->post_type ][] = $id;
		}

		if ( ! empty( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) {
			foreach ( $postarr['meta_input'] as $key => $value ) {
				update_post_meta( $id, (string) $key, $value );
			}
		}

		return $id;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		$meta = $GLOBALS['wp_post_meta'][ $post_id ] ?? [];

		if ( '' === $key ) {
			$result = [];
			foreach ( $meta as $meta_key => $value ) {
				$result[ $meta_key ] = [ $value ];
			}

			return $result;
		}

		if ( ! array_key_exists( $key, $meta ) ) {
			return $single ? '' : [];
		}

		return $single ? $meta[ $key ] : [ $meta[ $key ] ];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['wp_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$result = @unserialize( $value );
		return false === $result && 'b:0;' !== $value ? $value : $result;
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
	$GLOBALS['wp_post_meta'] = [];
	$GLOBALS['wp_deleted_posts'] = [];
	$GLOBALS['wp_connection_rows'] = [];
	$GLOBALS['wp_connection_meta_rows'] = [];
	$GLOBALS['wpdb_inserts'] = [];
	$GLOBALS['wp_remote_post_requests'] = [];
	$GLOBALS['wp_remote_post_handler'] = null;
	$GLOBALS['wp_schedule_errors'] = [];
	$GLOBALS['wp_next_post_id'] = 1;
	$GLOBALS['wp_next_connection_id'] = 1;
	$GLOBALS['wp_next_connection_meta_id'] = 1;
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
