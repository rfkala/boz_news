<?php
/**
 * Shared settings helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return string in the configured admin language (English or Persian).
 *
 * @param string $en English text.
 * @param string $fa Persian text.
 * @return string
 */
function wpnc__( $en, $fa = '' ) {
	if ( $fa && 'en' !== get_option( 'wpnc_admin_lang', 'fa' ) ) {
		return $fa;
	}
	return $en;
}

/**
 * Echo HTML-escaped string in the configured admin language.
 *
 * @param string $en English text.
 * @param string $fa Persian text.
 */
function wpnc_e( $en, $fa = '' ) {
	echo esc_html( wpnc__( $en, $fa ) );
}

class WPNC_Settings {

	/**
	 * Allowed cron intervals.
	 *
	 * @return array
	 */
	public static function intervals() {
		return array( '15min', 'hourly', '3hours', 'twicedaily', 'daily' );
	}

	/**
	 * Sanitize interval setting.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_interval( $value ) {
		$value = sanitize_key( $value );

		return in_array( $value, self::intervals(), true ) ? $value : 'hourly';
	}

	/**
	 * Get configured target post type.
	 *
	 * @return string
	 */
	public static function get_target_post_type() {
		return self::sanitize_post_type( get_option( 'wpnc_target_post_type', 'post' ) );
	}

	/**
	 * Sanitize target post type.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_type( $value ) {
		$value   = sanitize_key( $value );
		$allowed = array( 'post', 'wpnc_news' );

		return in_array( $value, $allowed, true ) ? $value : 'post';
	}

	/**
	 * Sanitize a checkbox.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Cap number of items fetched per source.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_max_items( $value ) {
		return max( 1, min( 100, absint( $value ) ) );
	}

	/**
	 * Cap HTTP timeout.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_timeout( $value ) {
		return max( 3, min( 30, absint( $value ) ) );
	}

	/**
	 * Preserve existing secrets when admin field is left empty.
	 *
	 * @param string $value Raw value.
	 * @param string $option Option name.
	 * @return string
	 */
	public static function sanitize_secret( $value, $option ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return (string) get_option( $option, '' );
		}

		if ( '__delete__' === $value ) {
			return '';
		}

		return sanitize_text_field( $value );
	}
}

/**
 * Time helpers.
 *
 * Every datetime this plugin writes to its own tables is UTC. Local time is
 * produced only at render time. Mixing the two is what made retention delete
 * rows off by the site's GMT offset before 1.3.0.
 */
class WPNC_Time {

	/**
	 * Current time as a UTC MySQL datetime.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Current UTC timestamp.
	 *
	 * @return int
	 */
	public static function timestamp() {
		return time();
	}

	/**
	 * Normalize an arbitrary datetime string to a UTC MySQL datetime.
	 *
	 * Falls back to now when the value is unparseable, which keeps rows
	 * insertable instead of failing on a malformed feed date.
	 *
	 * @param string $datetime Datetime string.
	 * @return string
	 */
	public static function to_utc( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime ) {
			return self::now();
		}

		$timestamp = strtotime( $datetime );

		return false === $timestamp ? self::now() : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * UTC MySQL datetime for a point N days in the past.
	 *
	 * @param int $days Days back.
	 * @return string
	 */
	public static function days_ago( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Render a stored UTC datetime in the site's timezone for display.
	 *
	 * @param string $datetime UTC MySQL datetime.
	 * @param string $format   Optional date format; site format when empty.
	 * @return string
	 */
	public static function for_display( $datetime, $format = '' ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		}

		$timestamp = strtotime( $datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date( $format, $timestamp );
	}

	/**
	 * Site GMT offset in seconds.
	 *
	 * @return int
	 */
	public static function offset_seconds() {
		return (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}
}
