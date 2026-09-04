<?php
/**
 * Where an approved item can be sent.
 *
 * Publishing used to mean one thing - a WordPress post - with a Telegram
 * message fired afterwards if a token happened to be set. This turns the
 * destinations into a list an editor chooses from at the moment of approval.
 *
 * Telegram and Bale are the same bot API under two addresses, so they share
 * every line of transport code and differ only by a host and a pair of
 * options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Channels {

	/**
	 * Option holding which channels have passed a live test.
	 *
	 * Shape: { slug: { at: timestamp, fingerprint: hash } }.
	 */
	const VERIFIED = 'wpnc_channel_verified';

	/**
	 * Every destination the plugin can send to.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'site'     => array(
				'label'  => wpnc__( 'Site', 'سایت' ),
				'kind'   => 'site',
				'api'    => '',
				'token'  => '',
				'chat'   => '',
			),
			'telegram' => array(
				'label'  => wpnc__( 'Telegram', 'تلگرام' ),
				'kind'   => 'bot',
				'api'    => 'https://api.telegram.org',
				'token'  => 'wpnc_telegram_token',
				'chat'   => 'wpnc_telegram_chat_id',
			),
			'bale'     => array(
				'label'  => wpnc__( 'Bale', 'بله' ),
				'kind'   => 'bot',
				'api'    => 'https://tapi.bale.ai',
				'token'  => 'wpnc_bale_token',
				'chat'   => 'wpnc_bale_chat_id',
			),
		);
	}

	/**
	 * Channel slugs, in the order they are offered.
	 *
	 * @return array
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Whether a slug names a channel.
	 *
	 * @param string $slug Channel slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return array_key_exists( (string) $slug, self::all() );
	}

	/**
	 * One channel's definition, or an empty array.
	 *
	 * @param string $slug Channel slug.
	 * @return array
	 */
	public static function get( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/**
	 * The credentials stored for a bot channel.
	 *
	 * @param string $slug Channel slug.
	 * @return array { token, chat_id }, both possibly empty.
	 */
	public static function credentials( $slug ) {
		$channel = self::get( $slug );

		if ( empty( $channel ) || 'bot' !== $channel['kind'] ) {
			return array(
				'token'   => '',
				'chat_id' => '',
			);
		}

		return array(
			'token'   => trim( (string) get_option( $channel['token'], '' ) ),
			'chat_id' => trim( (string) get_option( $channel['chat'], '' ) ),
		);
	}

	/**
	 * Whether a channel has everything it needs to be used at all.
	 *
	 * The site needs nothing; a bot needs both halves of its credentials.
	 *
	 * @param string $slug Channel slug.
	 * @return bool
	 */
	public static function is_configured( $slug ) {
		$channel = self::get( $slug );

		if ( empty( $channel ) ) {
			return false;
		}

		if ( 'site' === $channel['kind'] ) {
			return true;
		}

		$credentials = self::credentials( $slug );

		return '' !== $credentials['token'] && '' !== $credentials['chat_id'];
	}

	/**
	 * A hash of the current credentials.
	 *
	 * Stored alongside a passing test so that editing the token or the chat
	 * id invalidates the result. Without it a channel would keep claiming to
	 * be verified on the strength of a test run against credentials that no
	 * longer exist.
	 *
	 * @param string $slug Channel slug.
	 * @return string
	 */
	public static function fingerprint( $slug ) {
		$credentials = self::credentials( $slug );

		return md5( $credentials['token'] . '|' . $credentials['chat_id'] );
	}

	/**
	 * Whether a live test has passed for the credentials currently stored.
	 *
	 * @param string $slug Channel slug.
	 * @return bool
	 */
	public static function is_verified( $slug ) {
		if ( 'site' === $slug ) {
			return true;
		}

		$state = get_option( self::VERIFIED, array() );

		if ( ! is_array( $state ) || ! isset( $state[ $slug ]['fingerprint'] ) ) {
			return false;
		}

		return hash_equals( (string) $state[ $slug ]['fingerprint'], self::fingerprint( $slug ) );
	}

	/**
	 * Whether an editor may send to this channel.
	 *
	 * Both halves are required: credentials that have never been tested are
	 * a guess, and a passing test against credentials since edited is stale.
	 * This is what enables or disables a button.
	 *
	 * @param string $slug Channel slug.
	 * @return bool
	 */
	public static function is_ready( $slug ) {
		return self::is_configured( $slug ) && self::is_verified( $slug );
	}

	/**
	 * Record that a channel answered a live test.
	 *
	 * @param string $slug Channel slug.
	 * @return void
	 */
	public static function mark_verified( $slug ) {
		$state = get_option( self::VERIFIED, array() );
		$state = is_array( $state ) ? $state : array();

		$state[ $slug ] = array(
			'at'          => WPNC_Time::timestamp(),
			'fingerprint' => self::fingerprint( $slug ),
		);

		update_option( self::VERIFIED, $state, false );
	}

	/**
	 * Forget a channel's test result.
	 *
	 * @param string $slug Channel slug, empty for all.
	 * @return void
	 */
	public static function clear_verified( $slug = '' ) {
		if ( '' === $slug ) {
			delete_option( self::VERIFIED );
			return;
		}

		$state = get_option( self::VERIFIED, array() );

		if ( ! is_array( $state ) ) {
			return;
		}

		unset( $state[ $slug ] );
		update_option( self::VERIFIED, $state, false );
	}

	/**
	 * Channels an editor may currently send to.
	 *
	 * @return array Slugs.
	 */
	public static function ready() {
		return array_values( array_filter( self::slugs(), array( __CLASS__, 'is_ready' ) ) );
	}

	/**
	 * What the buttons need to draw themselves.
	 *
	 * @return array slug => { label, kind, configured, verified, ready }
	 */
	public static function status() {
		$out = array();

		foreach ( self::all() as $slug => $channel ) {
			$out[ $slug ] = array(
				'label'      => $channel['label'],
				'kind'       => $channel['kind'],
				'configured' => self::is_configured( $slug ),
				'verified'   => self::is_verified( $slug ),
				'ready'      => self::is_ready( $slug ),
			);
		}

		return $out;
	}

	/**
	 * Reduce a requested selection to the channels that may actually be used.
	 *
	 * Pure apart from the readiness lookup, and deliberately silent about
	 * what it dropped: the caller reports that, because "you asked for Bale
	 * and Bale is not set up" is a different message from "nothing was
	 * selected".
	 *
	 * @param mixed $requested Slugs, or the string "all".
	 * @return array Slugs, in the order channels are declared.
	 */
	public static function sanitize_selection( $requested ) {
		if ( 'all' === $requested ) {
			return self::ready();
		}

		$requested = is_array( $requested ) ? $requested : array( $requested );
		$requested = array_map( 'sanitize_key', $requested );

		$out = array();
		foreach ( self::slugs() as $slug ) {
			if ( in_array( $slug, $requested, true ) && self::is_ready( $slug ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}
}
