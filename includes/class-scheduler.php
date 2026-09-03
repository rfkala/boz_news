<?php
/**
 * Publication pacing.
 *
 * Approving twenty items published twenty posts at the same second, which
 * reads as a dump to a visitor and to a feed reader. This spaces them out.
 *
 * The slot arithmetic is deliberately free of WordPress state so it can be
 * unit tested; only next_slot() touches the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Scheduler {

	/**
	 * Whether staggered publishing is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 1 === absint( get_option( 'wpnc_stagger_enabled', 0 ) )
			&& self::interval_seconds() > 0;
	}

	/**
	 * Minutes between two published items.
	 *
	 * @return int
	 */
	public static function interval_minutes() {
		return max( 1, min( 1440, absint( get_option( 'wpnc_stagger_minutes', 15 ) ) ) );
	}

	/**
	 * Gap between two published items, in seconds.
	 *
	 * @return int
	 */
	public static function interval_seconds() {
		return self::interval_minutes() * MINUTE_IN_SECONDS;
	}

	/**
	 * Work out when the next item should go out.
	 *
	 * Pure so the rule is testable: the next slot is one interval after the
	 * latest thing already queued, but never in the past, and never sooner
	 * than one interval from now when something is already scheduled ahead.
	 *
	 * @param int $now        Current UTC timestamp.
	 * @param int $last_slot  Timestamp of the furthest already-scheduled post, 0 when none.
	 * @param int $interval   Seconds between items.
	 * @return int UTC timestamp for the next slot.
	 */
	public static function calculate_slot( $now, $last_slot, $interval ) {
		$now      = absint( $now );
		$interval = max( 1, absint( $interval ) );

		if ( $last_slot <= $now ) {
			// Nothing pending ahead of us, so this one goes out now.
			return $now;
		}

		return $last_slot + $interval;
	}

	/**
	 * The next publication slot, in UTC.
	 *
	 * @return int
	 */
	public static function next_slot() {
		global $wpdb;

		$now      = WPNC_Time::timestamp();
		$interval = self::interval_seconds();

		// The furthest-out post this plugin has already scheduled. Only ours,
		// so an editor's own scheduled posts are not counted or displaced.
		$latest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( p.post_date_gmt )
				FROM $wpdb->posts p
				INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status = 'future'",
				'_wpnc_source_url'
			)
		);

		$last_slot = 0;
		if ( $latest && '0000-00-00 00:00:00' !== $latest ) {
			$parsed = strtotime( $latest . ' UTC' );
			if ( false !== $parsed ) {
				$last_slot = $parsed;
			}
		}

		return self::calculate_slot( $now, $last_slot, $interval );
	}

	/**
	 * How many of this plugin's posts are waiting to go out.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM $wpdb->posts p
				INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status = 'future'",
				'_wpnc_source_url'
			)
		);
	}
}
