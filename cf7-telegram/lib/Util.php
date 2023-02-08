<?php

namespace iTRON\cf7Telegram;

use wpdb;

class Util {

	/**
	 * Runs the SQL query for installing/upgrading a table.
	 *
	 * @param string $tableName
	 * @param string $columns The SQL columns for the CREATE TABLE statement.
	 * @param array $opts (optional) Various other options.
	 *
	 * @return void
	 */
	static function installTable( string $tableName, string $columns, array $opts = [] ) {
		self::getWPDB()->tables[] = $tableName;
		self::getWPDB()->$tableName = self::getWPDB()->prefix . $tableName;

		$full_table_name = self::getWPDB()->$tableName;

		if ( is_string( $opts ) ) {
			$opts = [ 'upgrade_method' => $opts ];
		}

		$opts = wp_parse_args( $opts, [
			'upgrade_method' => 'dbDelta',
			'table_options' => '',
		] );

		$charset_collate = '';
		if ( self::getWPDB()->has_cap( 'collation' ) ) {
			if ( ! empty( $wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}

		$table_options = $charset_collate . ' ' . $opts['table_options'];

		if ( 'dbDelta' == $opts['upgrade_method'] ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( "CREATE TABLE $full_table_name ( $columns ) $table_options" );
			return;
		}

		if ( 'delete_first' == $opts['upgrade_method'] ) {
			self::getWPDB()->query( "DROP TABLE IF EXISTS $full_table_name;" );
		}

		self::getWPDB()->query( "CREATE TABLE IF NOT EXISTS $full_table_name ( $columns ) $table_options;" );
	}

	static function getWPDB(): wpdb {
		global $wpdb;
		return $wpdb;
	}
}
