<?php

namespace iTRON\cf7Telegram\RestApiControllers;

class CF7FormController extends AbstractController {

	/**
	 * @TODO
	 *
	 * @param $request
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ): bool {
		return true;
	}

	/**
	 * @TODO
	 *
	 * @param $request
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ): bool {
		return true;
	}
}
