<?php

namespace iTRON\cf7Telegram;

use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;

class Form extends Entity implements WPPostAble{
	use WPPostAbleTrait;

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $form_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( 'wpcf7_contact_form', $form_id );
	}
}
