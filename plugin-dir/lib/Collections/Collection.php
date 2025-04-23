<?php

namespace iTRON\cf7Telegram\Collections;

use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpPostAble\wpPostAble;
use Ramsey\Collection\CollectionInterface;

abstract class Collection extends \Ramsey\Collection\Collection {
	public function createByConnections( ConnectionCollection $connections, string $sourceColumn = 'from' ): self {
		// Chat IDs obtained
		$ids = $connections->column( $sourceColumn );

		return $this->createByIDs( $ids );
	}

	/**
	 * @TODO Logging exceptions
	 */
	public function createByIDs( array $ids ): self {
		$classname = $this->getType();

		foreach ( $ids as $id ) {
			$this->add( new $classname( $id ) );
		}

		return $this;
	}

	/**
	 * @param array $ids
	 *
	 * @return CollectionInterface
	 */
	public function filterByIDs( array $ids ): CollectionInterface {
		return $this->filter( function ( wpPostAble $collectionItem ) use ( $ids ) {
			return in_array( $collectionItem->getPost()->ID, $ids, false );
		} );
	}

	public function contains( $element, bool $strict = true ): bool {
		/** @var wpPostAble $element */
		return ! $this->filterByIDs( [ $element->getPost()->ID ] )->isEmpty();
	}
}
