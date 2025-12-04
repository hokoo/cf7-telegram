<?php
/**
 * Use this interface in conjunction wpPostAbleTrait only.
 */

namespace iTRON\wpPostAble;

use WP_Post;

interface wpPostAble{
	public function getPost(): WP_Post;
	public function savePost();
	public function deletePost();
	public function getPostType();
	public function getTitle();
	public function setTitle( string $title );
	public function getStatus();
	public function setStatus( string $status );
	public function setMetaField( string $meta_key, $meta_value );
	public function getMetaField( string $meta_key );
	public function getMetaFields();
	public function publish();
	public function draft();
}
