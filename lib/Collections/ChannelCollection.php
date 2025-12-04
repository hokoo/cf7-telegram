<?php

namespace iTRON\cf7Telegram\Collections;

class ChannelCollection extends Collection {

	function __construct( array $data = [] ) {
		$namespace = explode( '\\', __NAMESPACE__ );
		array_pop( $namespace );

		$collectionType = '\\' . implode( '\\', $namespace ) . '\Channel';
		parent::__construct( $collectionType, $data );
	}
}
