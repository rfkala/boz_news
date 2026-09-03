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
	 * Pure so the rule is testable. Two cases, and conflating them is what
	 * made a batch of approvals all land on the same instant:
	 *
	 * - Nothing has been placed yet ($last_slot of 0): go out now.
	 * - Something has been placed: go one interval after it, but never
	 *   earlier than now, so an old post does not schedule us into the past.
	 *
	 * @param int $now       Current UTC timestamp.
	 * @param int $last_slot Timestamp of the last item placed, 0 when none.
	 * @param int $interval  Seconds between items.
	 * @return int UTC timestamp for the next slot.
	 */
	public static function calculate_slot( $now, $last_slot, $interval ) {
		$now       = absint( $now );
		$interval  = max( 1, absint( $interval ) );
		$last_slot = absint( $last_slot );

		if ( 0 === $last_slot ) {
			return $now;
		}

		return max( $now, $last_slot + $interval );
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

		// Both statuses matter. Counting only 'future' meant the first item
		// of a batch published immediately as 'publish', left nothing
		// pending, and so the next item published immediately as well.
		//
		// The window keeps the query cheap and stops an archive of old
		// imports from being scanned; anything older than one interval
		// cannot pace us anyway. Only this plugin's own posts are counted,
		// so an editor's scheduled posts are never displaced.
		$window = gmdate( 'Y-m-d H:i:s', $now - $interval );

		$latest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( p.post_date_gmt )
				FROM $wpdb->posts p
				INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status IN ( 'future', 'publish' )
					AND p.post_date_gmt >= %s",
				'_wpnc_source_url',
				$window
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
