<?php
/**
 * Retrieves the current time as an object with the timezone from settings.
 *
 * @since 5.3.0
 *
 * @return DateTimeImmutable Date and time object.
 */
function current_datetime() {
	return new DateTimeImmutable( 'now', wp_timezone() );
}
