<?php

namespace iTRON\cf7Telegram\Collections;

class BotCollection extends Collection {

	function __construct( array $data = [] ) {
		$namespace = explode( '\\', __NAMESPACE__ );
		array_pop( $namespace );

		$collectionType = '\\' . implode( '\\', $namespace ) . '\Bot';
		parent::__construct( $collectionType, $data );
	}
}
