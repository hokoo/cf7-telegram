<?php

namespace iTRON\cf7Telegram;

use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Query;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use iTRON\wpPostAble\Exceptions\wppaCreatePostException;
use iTRON\wpPostAble\Exceptions\wppaLoadPostException;

class Form extends Entity implements WPPostAble{
	use WPPostAbleTrait;

	/**
	 * @throws wppaLoadPostException
	 * @throws wppaCreatePostException
	 */
	public function __construct( int $form_id = 0 ) {
		parent::__construct();

		$this->wpPostAble( Client::CPT_CF7FORM, $form_id );
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws MissingParameters
     * @throws RelationNotFound
     */
	public function connectChannel( Channel $channel ): Entity {
		$channel->connectForm( $this );
		return $this;
	}

    /**
     * @throws RelationNotFound
     */
    public function disconnectChannel( Channel $channel = null ): Entity {
		$channelID = isset ( $channel ) ? $channel->getPost()->ID : null;
		$this->client
			->getForm2ChannelRelation()
			->detachConnections( new Query\Connection( $this->getPost()->ID, $channelID ) );

		return $this;
	}
}
