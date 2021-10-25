<?php

namespace iTRON\cf7Telegram;

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

		$this->wpPostAble( 'wpcf7_contact_form', $form_id );
	}

	/**
	 * @throws ConnectionWrongData
	 * @throws MissingParameters
	 */
	public function connectChannel( Channel $channel ): Entity {
		$channel->addForm( $this );
		return $this;
	}

	public function disconnectChannel( Channel $channel = null ): Entity {
		$channelID = isset ( $channel ) ? $channel->post->ID : null;
		$this->connectionsClient
			->getForm2ChannelRelation()
			->detachConnections( new Query\Connection( $this->post->ID, $channelID ) );

		return $this;
	}
}
