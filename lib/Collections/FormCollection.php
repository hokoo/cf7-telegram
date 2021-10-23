<?php

namespace iTRON\cf7Telegram;

use Ramsey\Collection\Collection;
use wppaCollectionFromConnectionsTrait;

class FormCollection extends Collection {
	use wppaCollectionFromConnectionsTrait;

	function __construct( array $data = [] ) {
		$collectionType = __NAMESPACE__ . '\Form';
		parent::__construct( $collectionType, $data );
	}
}
