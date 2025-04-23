<?php

namespace iTRON\cf7Telegram\Collections;

class ChatCollection extends Collection {

	function __construct( array $data = [] ) {
		$namespace = explode( '\\', __NAMESPACE__ );
		array_pop( $namespace );

		$collectionType = '\\' . implode( '\\', $namespace ) . '\Chat';
		parent::__construct( $collectionType, $data );
	}
}
