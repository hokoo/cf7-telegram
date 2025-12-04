<?php
namespace iTRON\wpPostAble\Exceptions;

use iTRON\wpPostAble\wpPostAble;
use Throwable;

class wppaLoadPostException extends wppaException {
	public $post_id;

	function __construct( $post_id, wpPostAble $postable, $message = "", $code = 0, Throwable $previous = null ) {
		parent::__construct( $postable, $message, $code, $previous );
		$this->post_id = $post_id;
	}

	function getPostID(){
		return $this->post_id;
	}
}
