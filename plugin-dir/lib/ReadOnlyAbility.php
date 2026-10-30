<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Preserve integer input types across Core's PHP, REST, and MCP execution paths. */
class ReadOnlyAbility extends \WP_Ability {
	public function validate_input( $input = null ) {
		$valid = parent::validate_input( $input );
		if ( true !== $valid || ! is_array( $input ) ) return $valid;

		$properties = $this->get_input_schema()['properties'] ?? [];
		foreach ( [ 'page', 'per_page', 'form_id', 'since', 'until', 'min_level', 'before_id', 'limit' ] as $key ) {
			if ( ( $properties[ $key ]['type'] ?? null ) === 'integer' && array_key_exists( $key, $input ) && ! is_int( $input[ $key ] ) ) {
				return new \WP_Error( 'ability_invalid_input', __( 'Ability input has an invalid integer type.', 'cf7-telegram' ) );
			}
		}
		return true;
	}
}
