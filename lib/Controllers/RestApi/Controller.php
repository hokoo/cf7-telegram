<?php

namespace iTRON\cf7Telegram\Controllers\RestApi;

use WP_REST_Posts_Controller;

abstract class Controller extends WP_REST_Posts_Controller{
    public function get_item( $request ) {
        $pre = parent::get_item( $request );
        $data = $pre->get_data();
        $data['foo'] = 'bar';
        $pre->set_data( $data );

        return $pre;
    }

    /**
     * Posts can not be visible without having capabilities.
     *
     * @param $post
     * @return bool
     */
    public function check_read_permission( $post ): bool {
        $post_type = get_post_type_object( $post->post_type );
        return 'publish' === $post->post_status && current_user_can( $post_type->cap->read_post, $post->ID );
    }

    /**
     * @param $request
     *
     * @return bool
     */
    public function get_items_permissions_check( $request ): bool {
        $post_type = get_post_type_object( $this->post_type );

        return current_user_can( $post_type->cap->read_post );
    }
}
