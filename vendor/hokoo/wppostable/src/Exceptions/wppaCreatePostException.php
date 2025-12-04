<?php


namespace iTRON\wpPostAble\Exceptions;

use iTRON\wpPostAble\wpPostAble;
use Throwable;
use WP_Error;

class wppaCreatePostException extends wppaException implements wpException {

	public $error;

	function __construct( wpPostAble $postable, WP_Error $error, $message = "", $code = 0, Throwable $previous = null ) {
		parent::__construct( $postable, $message, $code, $previous );
		$this->error = $error;
	}

	function getError(): WP_Error {
		return $this->error;
	}
}
