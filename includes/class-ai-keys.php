<?php
/**
 * API key pool.
 *
 * Each provider holds any number of keys. When one is exhausted or rate
 * limited the pool moves to the next and remembers which one worked, so the
 * next request starts there instead of walking the dead keys again.
 *
 * Keys are stored under stable ids rather than by position, so removing the
 * middle row of the settings form cannot silently reassign the others.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_AI_Keys {

	/**
	 * Option holding { provider: { id: key } }.
	 */
	const OPTION = 'wpnc_ai_keys';

	/**
	 * Option holding rotation state: { provider: { current: id, resting: { id: timestamp } } }.
	 */
	const STATE = 'wpnc_ai_key_state';

	/**
	 * How long a key that reported exhaustion is skipped for.
	 *
	 * A rate limit clears in seconds and a spent balance does not, so this is
	 * a compromise: long enough to stop hammering a dead key on every item,
	 * short enough that topping up an account takes effect without anyone
	 * clearing state by hand.
	 */
	const REST_SECONDS = 1800;

	/**
	 * All stored keys, provider => id => key.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( WPNC_AI_Providers::slugs() as $slug ) {
			$clean[ $slug ] = array();

			if ( ! isset( $stored[ $slug ] ) || ! is_array( $stored[ $slug ] ) ) {
				continue;
			}

			foreach ( $stored[ $slug ] as $id => $key ) {
				$key = trim( (string) $key );
				if ( '' !== $key ) {
					$clean[ $slug ][ (string) $id ] = $key;
				}
			}
		}

		return $clean;
	}

	/**
	 * Keys for one provider, id => key.
	 *
	 * @param string $slug Provider slug.
	 * @return array
	 */
	public static function for_provider( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/**
	 * Replace the whole pool.
	 *
	 * @param array $keys provider => id => key.
	 */
	public static function save( $keys ) {
		update_option( self::OPTION, $keys, false );
	}

	/**
	 * A fresh id for a new key.
	 *
	 * @return string
	 */
	public static function new_id() {
		return substr( md5( uniqid( 'wpnc', true ) ), 0, 8 );
	}

	/**
	 * Rotation state, normalised.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Order to try a provider's keys in.
	 *
	 * Starts at the one that last worked, wraps around, and pushes any key
	 * still resting to the back rather than dropping it - a pool where every
	 * key is resting should still make one attempt instead of failing with
	 * "no keys".
	 *
	 * @param string $slug Provider slug.
	 * @param int    $now  Current timestamp.
	 * @return array Ordered ids.
	 */
	public static function order( $slug, $now = 0 ) {
		$keys = self::for_provider( $slug );
		if ( empty( $keys ) ) {
			return array();
		}

		$now     = $now ? absint( $now ) : WPNC_Time::timestamp();
		$state   = self::state();
		$current = isset( $state[ $slug ]['current'] ) ? (string) $state[ $slug ]['current'] : '';
		$resting = isset( $state[ $slug ]['resting'] ) && is_array( $state[ $slug ]['resting'] )
			? $state[ $slug ]['resting']
			: array();

		$ids   = array_keys( $keys );
		$start = array_search( $current, $ids, true );
		if ( false !== $start && $start > 0 ) {
			$ids = array_merge( array_slice( $ids, $start ), array_slice( $ids, 0, $start ) );
		}

		$ready = array();
		$tired = array();

		foreach ( $ids as $id ) {
			$until = isset( $resting[ $id ] ) ? absint( $resting[ $id ] ) : 0;
			if ( $until > $now ) {
				$tired[] = $id;
			} else {
				$ready[] = $id;
			}
		}

		return array_merge( $ready, $tired );
	}

	/**
	 * Record that a key worked, so the next request starts with it.
	 *
	 * @param string $slug Provider slug.
	 * @param string $id   Key id.
	 */
	public static function mark_working( $slug, $id ) {
		$state = self::state();

		$state[ $slug ]['current'] = (string) $id;
		unset( $state[ $slug ]['resting'][ $id ] );

		update_option( self::STATE, $state, false );
	}

	/**
	 * Stand a key down for a while after it reported exhaustion.
	 *
	 * @param string $slug   Provider slug.
	 * @param string $id     Key id.
	 * @param string $reason Provider message, kept for the settings screen.
	 */
	public static function mark_resting( $slug, $id, $reason = '' ) {
		$state = self::state();

		$state[ $slug ]['resting'][ (string) $id ] = WPNC_Time::timestamp() + self::REST_SECONDS;
		$state[ $slug ]['reasons'][ (string) $id ] = sanitize_text_field( substr( (string) $reason, 0, 200 ) );

		update_option( self::STATE, $state, false );
	}

	/**
	 * Put every key back into service immediately.
	 *
	 * @param string $slug Provider slug, or empty for all.
	 */
	public static function wake( $slug = '' ) {
		if ( '' === $slug ) {
			delete_option( self::STATE );
			return;
		}

		$state = self::state();
		unset( $state[ $slug ]['resting'], $state[ $slug ]['reasons'] );

		update_option( self::STATE, $state, false );
	}

	/**
	 * Whether a provider has at least one key.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function has_keys( $slug ) {
		return array() !== self::for_provider( $slug );
	}

	/**
	 * Per-key status for the settings screen.
	 *
	 * @param string $slug Provider slug.
	 * @return array id => { masked, resting, until, reason }
	 */
	public static function status( $slug ) {
		$keys    = self::for_provider( $slug );
		$state   = self::state();
		$now     = WPNC_Time::timestamp();
		$resting = isset( $state[ $slug ]['resting'] ) && is_array( $state[ $slug ]['resting'] )
			? $state[ $slug ]['resting']
			: array();
		$reasons = isset( $state[ $slug ]['reasons'] ) && is_array( $state[ $slug ]['reasons'] )
			? $state[ $slug ]['reasons']
			: array();

		$out = array();
		foreach ( $keys as $id => $key ) {
			$until = isset( $resting[ $id ] ) ? absint( $resting[ $id ] ) : 0;

			$out[ $id ] = array(
				'masked'  => WPNC_AI_Providers::mask( $key ),
				'resting' => $until > $now,
				'until'   => $until,
				'reason'  => isset( $reasons[ $id ] ) ? (string) $reasons[ $id ] : '',
			);
		}

		return $out;
	}
}
