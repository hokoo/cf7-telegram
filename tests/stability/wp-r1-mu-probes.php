<?php
/** Isolated E4 probes loaded by WordPress before ordinary plugins finish booting. */
if ( '1' === getenv( 'CF7TG_R1_EARLY_PROBE' ) ) {
	add_action( 'init', static function (): void {
		$before_ready = doing_action( 'init' ) && ! \iTRON\cf7Telegram\Client::hasInitializedConnections();
		$previous_user = get_current_user_id();
		$admin = get_user_by( 'login', 'admin' );
		wp_set_current_user( $admin ? (int) $admin->ID : 0 );
		$list = \iTRON\cf7Telegram\Abilities::getConfiguration( [ 'resource' => 'forms' ] );
		$diagnosis = \iTRON\cf7Telegram\Abilities::diagnoseForm( [ 'form_id' => 1 ] );
		$recent = \iTRON\cf7Telegram\Abilities::getRecentErrors( [ 'since' => 0 ] );
		$list_ability = wp_get_ability( 'cf7-telegram/get-configuration' );
		$diagnose_ability = wp_get_ability( 'cf7-telegram/diagnose-form' );
		$recent_ability = wp_get_ability( 'cf7-telegram/get-recent-errors' );
		$http = $gateway = $mutations = 0;
		$count_http = static function ( $pre ) use ( &$http ) { ++$http; return new WP_Error( 'r1_early_http_blocked' ); };
		$count_gateway = static function ( $value ) use ( &$gateway ) { ++$gateway; return $value; };
		$count_sql = static function ( string $sql ) use ( &$mutations ): string {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql ) ) ++$mutations;
			return $sql;
		};
		add_filter( 'pre_http_request', $count_http, 1 );
		add_filter( 'cf7tg_telegram_gateway', $count_gateway, 1 );
		add_filter( 'query', $count_sql, PHP_INT_MAX );
		$native_list = $list_ability ? $list_ability->execute( [ 'resource' => 'forms' ] ) : null;
		$native_diagnosis = $diagnose_ability ? $diagnose_ability->execute( [ 'form_id' => 1 ] ) : null;
		$native_recent = $recent_ability ? $recent_ability->execute( [ 'since' => 0 ] ) : null;
		wp_set_current_user( 0 );
		$anonymous_list = $list_ability ? $list_ability->execute( [ 'resource' => 'forms' ] ) : null;
		$anonymous_diagnosis = $diagnose_ability ? $diagnose_ability->execute( [ 'form_id' => 1 ] ) : null;
		$anonymous_recent = $recent_ability ? $recent_ability->execute( [ 'since' => 0 ] ) : null;
		remove_filter( 'pre_http_request', $count_http, 1 );
		remove_filter( 'cf7tg_telegram_gateway', $count_gateway, 1 );
		remove_filter( 'query', $count_sql, PHP_INT_MAX );
		$GLOBALS['cf7tg_r1_early_probe'] = $before_ready && is_wp_error( $list ) && is_wp_error( $diagnosis ) && is_wp_error( $recent ) &&
			'cf7tg_not_ready' === $list->get_error_code() && 'cf7tg_not_ready' === $diagnosis->get_error_code() &&
			'cf7tg_not_ready' === $recent->get_error_code() && 503 === ( $recent->get_error_data()['status'] ?? null ) &&
			503 === ( $list->get_error_data()['status'] ?? null ) && 503 === ( $diagnosis->get_error_data()['status'] ?? null ) &&
			is_wp_error( $native_list ) && is_wp_error( $native_diagnosis ) && is_wp_error( $native_recent ) &&
			'cf7tg_not_ready' === $native_recent->get_error_code() && 503 === ( $native_recent->get_error_data()['status'] ?? null ) &&
			'cf7tg_not_ready' === $native_list->get_error_code() && 'cf7tg_not_ready' === $native_diagnosis->get_error_code() &&
			503 === ( $native_list->get_error_data()['status'] ?? null ) && 503 === ( $native_diagnosis->get_error_data()['status'] ?? null ) &&
			is_wp_error( $anonymous_list ) && is_wp_error( $anonymous_diagnosis ) && is_wp_error( $anonymous_recent ) &&
			'ability_invalid_permissions' === $anonymous_recent->get_error_code() &&
			'ability_invalid_permissions' === $anonymous_list->get_error_code() &&
			'ability_invalid_permissions' === $anonymous_diagnosis->get_error_code() &&
			0 === $http && 0 === $gateway && 0 === $mutations;
		wp_set_current_user( $previous_user );
	}, 0 );
}

add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
	if ( null === $result && 'GET' === $request->get_method() && get_option( 'cf7tg_r1_force_not_ready' ) &&
		preg_match( '~^/wp-abilities/v1/abilities/cf7-telegram/(?:get-configuration|diagnose-form|get-recent-errors)/run/?$~', $request->get_route() ) ) {
		unregister_post_type( \iTRON\cf7Telegram\Client::CPT_CHAT );
	}
	return $result;
}, 1, 3 );

add_filter( 'map_meta_cap', static function ( array $caps, string $cap, int $user_id, array $args ): array {
	$hidden = (int) get_option( 'cf7tg_r1_hidden_post_id', 0 );
	if ( $hidden < 1 || $hidden !== (int) ( $args[0] ?? 0 ) ) return $caps;
	$type_name = get_post_type( $hidden );
	$type = $type_name ? get_post_type_object( $type_name ) : null;
	return $type && in_array( $cap, [ 'read_post', $type->cap->read_post ], true ) ? [ 'do_not_allow' ] : $caps;
}, PHP_INT_MAX, 4 );

add_action( 'wp_before_execute_ability', static function ( $name ): void {
	if ( 'cf7-telegram/get-recent-errors' === $name ) $GLOBALS['cf7tg_r2_rest_executions'] = ( $GLOBALS['cf7tg_r2_rest_executions'] ?? 0 ) + 1;
} );
add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
	if ( preg_match( '~^/wp-abilities/v1/abilities/cf7-telegram/get-recent-errors/run/?$~', $request->get_route() ) ) {
		$response->header( 'X-CF7TG-R2-Executions', (string) ( $GLOBALS['cf7tg_r2_rest_executions'] ?? 0 ) );
	}
	return $response;
}, PHP_INT_MAX, 3 );
