<?php

namespace iTRON\cf7Telegram\Collections;

class FormCollection extends Collection {

	function __construct( array $data = [] ) {
		$namespace = explode( '\\', __NAMESPACE__ );
		array_pop( $namespace );

		$collectionType = '\\' . implode( '\\', $namespace ) . '\Form';
		parent::__construct( $collectionType, $data );
	}
}
