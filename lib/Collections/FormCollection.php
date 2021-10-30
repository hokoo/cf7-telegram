<?php

namespace iTRON\cf7Telegram\Collections;

use Ramsey\Collection\Collection;
use iTRON\cf7Telegram\wppaCollectionFromConnectionsTrait;

class FormCollection extends Collection {
	use wppaCollectionFromConnectionsTrait;

	function __construct( array $data = [] ) {
		$namespace = explode( '\\', __NAMESPACE__ );
		array_pop( $namespace );

		$collectionType = '\\' . implode( '\\', $namespace ) . '\Form';
		parent::__construct( $collectionType, $data );
	}
}
