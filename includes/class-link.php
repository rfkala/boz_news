<?php
/**
 * Deciding when two addresses are the same article.
 *
 * The queue used to answer that with a plain string comparison, which meant a
 * feed that appended a campaign parameter, switched to https, or dropped the
 * www prefix delivered the same story again as a new one. It also meant a
 * story could only be remembered for as long as its row survived retention.
 *
 * Free of WordPress state on purpose, so the rules are unit testable without
 * a database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Link {

	/**
	 * Query parameters that describe how a reader arrived, not what they are
	 * reading. Two addresses differing only in these are one article.
	 *
	 * Deliberately conservative: "ref" and "source" are left alone because
	 * some sites route real content through them.
	 */
	const TRACKING_PARAMS = array(
		'fbclid',
		'gclid',
		'dclid',
		'yclid',
		'msclkid',
		'twclid',
		'igshid',
		'mc_cid',
		'mc_eid',
		'_openstat',
		'spm',
		'xtor',
		'ncid',
		'cmpid',
	);

	/**
	 * Reduce an address to the form two copies of one article share.
	 *
	 * @param string $url Raw URL.
	 * @return string Empty when this is not a usable http(s) address.
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = strtolower( $parts['host'] );

		// One site, not two. A feed that moves between these is the commonest
		// way the same story arrives twice.
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$authority = $host;
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( $port && 80 !== $port && 443 !== $port ) {
			$authority .= ':' . $port;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		// A trailing slash is a formatting choice, not a different page.
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		if ( '' === $path ) {
			$path = '/';
		}

		$query = self::clean_query( isset( $parts['query'] ) ? $parts['query'] : '' );

		// The scheme is dropped entirely rather than normalised: a site moving
		// to https republishes nothing, and the fragment never identifies a
		// separate article.
		return $authority . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Drop tracking parameters and put the rest in a fixed order.
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	private static function clean_query( $query ) {
		$query = (string) $query;

		if ( '' === $query ) {
			return '';
		}

		$kept = array();

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$eq   = strpos( $pair, '=' );
			$name = false === $eq ? $pair : substr( $pair, 0, $eq );

			if ( self::is_tracking_param( $name ) ) {
				continue;
			}

			$kept[] = $pair;
		}

		// Parameter order is not meaningful to a server, but it is to a string
		// comparison.
		sort( $kept );

		return implode( '&', $kept );
	}

	/**
	 * Whether a query parameter only records how the reader got here.
	 *
	 * @param string $name Parameter name.
	 * @return bool
	 */
	public static function is_tracking_param( $name ) {
		$name = strtolower( trim( (string) $name ) );

		if ( '' === $name ) {
			return false;
		}

		if ( 0 === strpos( $name, 'utm_' ) ) {
			return true;
		}

		return in_array( $name, self::TRACKING_PARAMS, true );
	}

	/**
	 * A fixed-width key for one article address.
	 *
	 * Fixed width is the point: it indexes as a primary key, where the URL
	 * itself only ever fitted a truncated prefix.
	 *
	 * @param string $url Raw URL.
	 * @return string 32 hex characters, or empty for an unusable address.
	 */
	public static function hash( $url ) {
		$normalized = self::normalize( $url );

		return '' === $normalized ? '' : md5( $normalized );
	}

	/**
	 * A key for a feed's own item identifier.
	 *
	 * A guid is opaque by specification, so it is compared as given rather
	 * than normalised as a URL - a feed that uses a tag: URI or a bare number
	 * is still entitled to have it respected.
	 *
	 * @param string $guid Feed item guid.
	 * @return string 32 hex characters, or empty.
	 */
	public static function guid_hash( $guid ) {
		$guid = trim( (string) $guid );

		return '' === $guid ? '' : md5( $guid );
	}
}
