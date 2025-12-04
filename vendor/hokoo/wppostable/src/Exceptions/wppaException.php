<?php
namespace iTRON\wpPostAble\Exceptions;

use iTRON\wpPostAble\wpPostAble;
use Throwable;

class wppaException extends \Exception {

	public $postable;

	function __construct( wpPostAble $postable, $message = "", $code = 0, Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->postable = $postable;
	}

	function getPostable(): wpPostAble {
		return $this->postable;
	}
}