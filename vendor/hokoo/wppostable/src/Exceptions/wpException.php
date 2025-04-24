<?php
namespace iTRON\wpPostAble\Exceptions;

use WP_Error;

interface wpException{
	public function getError(): WP_Error;
}
