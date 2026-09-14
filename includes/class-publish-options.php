<?php
/**
 * Per-item publishing options.
 *
 * Post type, post status, author and category were settings and nothing else,
 * which meant a queue of fifty items all had to be published the same way. One
 * piece that should go out as a draft, or under a different author, meant
 * changing a global setting, approving, and changing it back.
 *
 * These are now defaults rather than rules: an item may carry its own answer
 * for any of them, and carries nothing for the rest.
 *
 * Free of WordPress state on purpose, so the merge rules are unit testable.
 * Whether a post type or a user still exists is decided at publish time, by
 * the publisher, because the answer can change between saving and publishing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Publish_Options {

	/**
	 * The overridable fields.
	 *
	 * @var array
	 */
	const FIELDS = array( 'post_type', 'post_status', 'post_author', 'category_id' );

	/**
	 * Post statuses an item may be created with.
	 *
	 * The same four the publisher has always allowed. Anything else is either
	 * meaningless for a new post or would hide it in a way an editor did not
	 * ask for.
	 *
	 * @return array slug => label.
	 */
	public static function statuses() {
		return array(
			'publish' => wpnc__( 'Published', 'منتشرشده' ),
			'draft'   => wpnc__( 'Draft', 'پیش‌نویس' ),
			'pending' => wpnc__( 'Pending review', 'در انتظار بازبینی' ),
			'private' => wpnc__( 'Private', 'خصوصی' ),
		);
	}

	/**
	 * What an item inherits when it says nothing of its own.
	 *
	 * @return array
	 */
	public static function defaults() {
		$status = sanitize_key( get_option( 'wpnc_post_status', 'publish' ) );

		return array(
			'post_type'   => sanitize_key( get_option( 'wpnc_target_post_type', 'post' ) ),
			'post_status' => isset( self::statuses()[ $status ] ) ? $status : 'publish',
			'post_author' => absint( get_option( 'wpnc_post_author', 0 ) ),
			'category_id' => absint( get_option( 'wpnc_default_category', 0 ) ),
		);
	}

	/**
	 * Clean an incoming override set down to shape.
	 *
	 * Only the shape, not existence: a post type or a user can be removed
	 * between saving an item and publishing it, so the publisher checks that
	 * when it matters and falls back rather than failing.
	 *
	 * An empty value means "no opinion, use the default" and is dropped, which
	 * is what keeps an item from being pinned to whatever the settings
	 * happened to say on the day it was edited.
	 *
	 * @param mixed $raw Raw values.
	 * @return array Only the fields that carry an opinion.
	 */
	public static function sanitize( $raw ) {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$type = isset( $raw['post_type'] ) ? sanitize_key( $raw['post_type'] ) : '';
		if ( '' !== $type ) {
			$clean['post_type'] = $type;
		}

		$status = isset( $raw['post_status'] ) ? sanitize_key( $raw['post_status'] ) : '';
		if ( '' !== $status && isset( self::statuses()[ $status ] ) ) {
			$clean['post_status'] = $status;
		}

		$author = isset( $raw['post_author'] ) ? absint( $raw['post_author'] ) : 0;
		if ( $author > 0 ) {
			$clean['post_author'] = $author;
		}

		$category = isset( $raw['category_id'] ) ? absint( $raw['category_id'] ) : 0;
		if ( $category > 0 ) {
			$clean['category_id'] = $category;
		}

		$publish_at = self::read_publish_at( $raw );
		if ( '' !== $publish_at ) {
			$clean['publish_at'] = $publish_at;
		}

		return $clean;
	}

	/**
	 * The publication time an item asks for, in UTC.
	 *
	 * Accepts the editor's datetime-local value, which is site time, or an
	 * already stored UTC value - and never converts the stored one again.
	 * sanitize() runs on the way in and again on every read, so converting its
	 * own output would move the time by the site offset each time the item
	 * was opened: a story set for 14:30 drifting later with every edit.
	 *
	 * The field being present but empty clears the time, which is how an
	 * editor takes a scheduled item back to "publish on approval".
	 *
	 * @param array $raw Raw values.
	 * @return string UTC MySQL datetime, or empty.
	 */
	private static function read_publish_at( $raw ) {
		if ( isset( $raw['publish_at_local'] ) ) {
			return WPNC_Time::local_input_to_utc( $raw['publish_at_local'] );
		}

		$stored = isset( $raw['publish_at'] ) ? trim( (string) $raw['publish_at'] ) : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $stored, $m ) ) {
			return '';
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $stored : '';
	}

	/**
	 * When an item has been told to go out, if it has.
	 *
	 * Kept out of merge(): the four fields there each have a settings default,
	 * and a time has none. Folding it in would change the shape every caller
	 * of merge() receives.
	 *
	 * @param array $overrides Item overrides.
	 * @return string UTC MySQL datetime, or empty for "on approval".
	 */
	public static function publish_at( $overrides ) {
		$clean = self::sanitize( $overrides );

		return isset( $clean['publish_at'] ) ? $clean['publish_at'] : '';
	}

	/**
	 * Lay an item's overrides over the defaults.
	 *
	 * @param array $overrides Item overrides.
	 * @param array $defaults  Settings defaults.
	 * @return array Every field, resolved.
	 */
	public static function merge( $overrides, $defaults ) {
		$overrides = self::sanitize( $overrides );
		$out       = array();

		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = isset( $overrides[ $field ] )
				? $overrides[ $field ]
				: ( isset( $defaults[ $field ] ) ? $defaults[ $field ] : '' );
		}

		return $out;
	}

	/**
	 * Overrides as stored in the queue row.
	 *
	 * An item with no opinion stores nothing at all rather than a JSON object
	 * full of blanks, so "inherits the settings" stays distinguishable from
	 * "was pinned to values that matched the settings at the time".
	 *
	 * @param mixed $overrides Raw or clean overrides.
	 * @return string JSON, or an empty string.
	 */
	public static function encode( $overrides ) {
		$clean = self::sanitize( $overrides );

		return empty( $clean ) ? '' : wp_json_encode( $clean );
	}

	/**
	 * Read overrides back out of a queue row.
	 *
	 * @param mixed $stored Stored column value.
	 * @return array
	 */
	public static function decode( $stored ) {
		if ( is_array( $stored ) ) {
			return self::sanitize( $stored );
		}

		$stored = trim( (string) $stored );
		if ( '' === $stored ) {
			return array();
		}

		$data = json_decode( $stored, true );

		return is_array( $data ) ? self::sanitize( $data ) : array();
	}
}
