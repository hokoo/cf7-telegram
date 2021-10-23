<?php

namespace iTRON\cf7Telegram;

use Ramsey\Collection\Collection;
use wppaCollectionFromConnectionsTrait;

class BotCollection extends Collection {
	use wppaCollectionFromConnectionsTrait;

	function __construct( array $data = [] ) {
		$collectionType = __NAMESPACE__ . '\Bot';
		parent::__construct( $collectionType, $data );
	}
}
