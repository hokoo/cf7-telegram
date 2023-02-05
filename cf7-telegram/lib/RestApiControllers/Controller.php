<?php

namespace iTRON\cf7Telegram\RestApiControllers;

use WP_REST_Posts_Controller;

abstract class Controller extends WP_REST_Posts_Controller{
    public function get_item( $request ) {
        $pre = parent::get_item( $request );
        $data = $pre->get_data();
        $data['foo'] = 'bar';
        $pre->set_data( $data );

        return $pre;
    }
}
