<?php

/**
 * Retrieves the timezone from site settings as a `DateTimeZone` object.
 *
 * Timezone can be based on a PHP timezone string or a ±HH:MM offset.
 *
 * @since 5.3.0
 *
 * @return DateTimeZone Timezone object.
 */
function wp_timezone() {
	return new DateTimeZone( wp_timezone_string() );
}
