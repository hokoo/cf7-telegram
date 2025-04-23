<?php

add_action( 'plugins_loaded', 'wpcf7tg_wp5_functions' );
function wpcf7tg_wp5_functions(){
	if ( version_compare( get_bloginfo('version'), '5.3', '<' ) ) wpcf7tg_wp_5_3_functions();
}

function wpcf7tg_wp_5_3_functions(){
	
	$functions = array( 'current_datetime', 'wp_timezone_string', 'wp_timezone', 'wp_date' );
	foreach( $functions as $f )	
	if ( ! function_exists( $f ) ) require_once( __DIR__ . "/{$f}.php" );
}