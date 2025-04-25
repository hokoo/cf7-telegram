<?php

namespace iTRON\cf7Telegram;

use wpdb;

class Logger {

	const LEVEL_INFO = 0;
	const LEVEL_ATTENTION = 1;
	const LEVEL_WARNING = 2;
	const LEVEL_CRITICAL = 3;

	/**
	 * @var string $table
	 */
	private $table = 'cf7tg_log';

	function __construct(){
		$this->mb_create_table();
	}

	public function write( $data, $title = '', $level = self::LEVEL_INFO ){
		$data = is_string( $data ) ? $data : json_encode( $data, JSON_UNESCAPED_UNICODE );
		$source = substr( strrchr( __NAMESPACE__, '\\' ), 1 );
		$data = [
			'source'    	=> $source,
			'date'			=> time(),
			'level'			=> $level,
			'msg'			=> $title,
			'data'			=> $data,
		];

		do_action( 'logger', $data );

		return Util::getWPDB()->insert( Util::getWPDB()->{$this->table},
			$data,
			[ '%s', '%d', '%d', '%s', '%s' ]
		);

	}

	public function mb_create_table( bool $dropFirst = false ){
		$upgradeMethod = $dropFirst ? 'delete_first' : '';

		Util::installTable( $this->table, "
			`ID`        INT(11)     NOT NULL AUTO_INCREMENT,
			`source`    CHAR(50)    NULL DEFAULT NULL,
			`date`      INT(11)     NULL DEFAULT NULL,
			`level`     INT(11)     NULL DEFAULT NULL,
			`msg`       MEDIUMTEXT  NULL DEFAULT NULL,
			`data`      LONGTEXT    NULL DEFAULT NULL,

			PRIMARY KEY (`ID`),
			INDEX `date` (`date`),
			INDEX `level` (`level`)
		", [ 'upgrade_method' => $upgradeMethod ] );
	}
}
