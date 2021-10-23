<?php

use iTRON\wpConnections\ConnectionCollection;

trait wppaCollectionFromConnectionsTrait {

	public function fromConnections( ConnectionCollection $connections, string $sourceColumn = 'from' ): self {
		// Chat IDs obtained
		$ids = $connections->column( $sourceColumn );

		$classname = $this->getType();

		foreach ( $ids as $id ) {
			$this->add( new $classname( $id ) );
		}

		return $this;
	}
}
