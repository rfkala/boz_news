<?php
/**
 * How each source's items are handled.
 *
 * Auto-publish and auto-rewrite were global: every source was trusted exactly
 * as much as every other, so a wire service and an unknown blog either both
 * skipped review or both waited in the queue. A source can now follow the
 * settings, always wait for review, or publish without it - to messengers of
 * its own choosing.
 *
 * Not the per-source XPath rules removed earlier, and deliberately not stored
 * under their option name: sites that had that feature may still carry its
 * old value, in a shape that has nothing to do with this one.
 *
 * Free of WordPress state apart from reading and writing its own option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Source_Policy {

	/**
	 * Option holding every source's rules: { source_id: rules }.
	 */
	const OPTION = 'wpnc_source_policies';

	/**
	 * What happens to a new item from the source.
	 */
	const MODES = array( 'inherit', 'review', 'publish' );

	/**
	 * Whether the assistant rewrites the source's items on import.
	 */
	const REWRITE = array( 'inherit', 'on', 'off' );

	/**
	 * Rules for a source nobody has set rules for.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'     => 'inherit',
			'rewrite'  => 'inherit',
			'channels' => array(),
		);
	}

	/**
	 * Whether a string is the shape WPNC_Feed_Reader::source_id() produces.
	 *
	 * @param string $source_id Candidate id.
	 * @return bool
	 */
	public static function valid_id( $source_id ) {
		return (bool) preg_match( '/^(key:[a-z0-9_\-]+|url:[a-f0-9]{32})$/', (string) $source_id );
	}

	/**
	 * Reduce incoming rules to a shape the fetcher can trust.
	 *
	 * Messengers are kept in the order they are declared, whatever order they
	 * were ticked in, and the site is not among them: publishing to the site
	 * is what the mode already decides.
	 *
	 * @param mixed $raw Raw rules.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw       = is_array( $raw ) ? $raw : array();
		$mode      = isset( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : 'inherit';
		$rewrite   = isset( $raw['rewrite'] ) ? sanitize_key( $raw['rewrite'] ) : 'inherit';
		$requested = isset( $raw['channels'] ) ? array_map( 'sanitize_key', (array) $raw['channels'] ) : array();
		$channels  = array();

		foreach ( WPNC_Channels::slugs() as $slug ) {
			$channel = WPNC_Channels::get( $slug );

			if ( 'bot' === $channel['kind'] && in_array( $slug, $requested, true ) ) {
				$channels[] = $slug;
			}
		}

		return array(
			'mode'     => in_array( $mode, self::MODES, true ) ? $mode : 'inherit',
			'rewrite'  => in_array( $rewrite, self::REWRITE, true ) ? $rewrite : 'inherit',
			'channels' => $channels,
		);
	}

	/**
	 * Every source's stored rules.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * One source's rules, defaults filled in.
	 *
	 * @param string $source_id Stable source id.
	 * @return array
	 */
	public static function for_source( $source_id ) {
		$all = self::all();

		return self::sanitize( isset( $all[ $source_id ] ) ? $all[ $source_id ] : array() );
	}

	/**
	 * Store one source's rules.
	 *
	 * Rules equal to the defaults are removed rather than stored, so "follows
	 * the settings" stays distinguishable from "was set to what the settings
	 * said on the day".
	 *
	 * @param string $source_id Stable source id.
	 * @param mixed  $raw       Raw rules.
	 * @return bool False for an id that is not a source id.
	 */
	public static function save( $source_id, $raw ) {
		if ( ! self::valid_id( $source_id ) ) {
			return false;
		}

		$clean = self::sanitize( $raw );
		$all   = self::all();

		if ( self::defaults() === $clean ) {
			unset( $all[ $source_id ] );
		} else {
			$all[ $source_id ] = $clean;
		}

		update_option( self::OPTION, $all, false );

		return true;
	}

	/**
	 * What the fetcher should actually do with an item from this source.
	 *
	 * @param array $policy       Source rules.
	 * @param bool  $auto_publish The global setting.
	 * @param bool  $auto_rewrite The global setting.
	 * @return array { publish: bool, rewrite: bool, channels: array|null }
	 *               null channels means every messenger that is set up,
	 *               which is what auto-publishing has always done.
	 */
	public static function resolve( $policy, $auto_publish, $auto_rewrite ) {
		$policy = self::sanitize( $policy );

		if ( 'publish' === $policy['mode'] ) {
			$publish = true;
		} elseif ( 'review' === $policy['mode'] ) {
			$publish = false;
		} else {
			$publish = (bool) $auto_publish;
		}

		if ( 'on' === $policy['rewrite'] ) {
			$rewrite = true;
		} elseif ( 'off' === $policy['rewrite'] ) {
			$rewrite = false;
		} else {
			$rewrite = (bool) $auto_rewrite;
		}

		return array(
			'publish'  => $publish,
			'rewrite'  => $rewrite,
			'channels' => empty( $policy['channels'] ) ? null : $policy['channels'],
		);
	}

	/**
	 * A sentence saying what a source's rules amount to.
	 *
	 * @param array $policy Source rules.
	 * @return string
	 */
	public static function describe( $policy ) {
		$policy = self::sanitize( $policy );

		if ( 'review' === $policy['mode'] ) {
			return wpnc__( 'Always goes to the queue for review.', 'همیشه برای بازبینی به صف می‌رود.' );
		}

		if ( 'publish' !== $policy['mode'] ) {
			return wpnc__( 'Follows the settings.', 'طبق تنظیمات.' );
		}

		if ( empty( $policy['channels'] ) ) {
			return wpnc__(
				'Publishes without review, to the site and every messenger that is set up.',
				'بدون بازبینی منتشر می‌شود؛ در سایت و هر پیام‌رسانی که تنظیم شده است.'
			);
		}

		$names = array();
		foreach ( $policy['channels'] as $slug ) {
			$channel = WPNC_Channels::get( $slug );
			$names[] = $channel['label'];
		}

		return sprintf(
			/* translators: %s: comma separated messenger names */
			wpnc__( 'Publishes without review, to the site and %s.', 'بدون بازبینی منتشر می‌شود؛ در سایت و %s.' ),
			implode( wpnc__( ', ', '، ' ), $names )
		);
	}
}
