<?php
/**
 * RSS/Atom feed reader.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Feed_Reader {

	/**
	 * Parse feed sources from settings.
	 *
	 * Supported formats:
	 * - https://example.com/feed
	 * - https://example.com/feed|12
	 * - https://example.com/feed|12|source_key
	 *
	 * A line starting with '#' is a comment. A line starting with '!' is a
	 * source that is kept but skipped, which is how a feed can be paused
	 * without losing its category and key.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array
	 */
	public function parse_sources( $raw ) {
		$sources = array();
		$lines   = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ) );

		foreach ( $lines as $line ) {
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$enabled = true;
			if ( 0 === strpos( $line, '!' ) ) {
				$enabled = false;
				$line    = trim( substr( $line, 1 ) );
			}

			if ( '' === $line ) {
				continue;
			}

			$parts      = array_map( 'trim', explode( '|', $line ) );
			$url        = esc_url_raw( isset( $parts[0] ) ? $parts[0] : '' );
			$source_key = isset( $parts[2] ) ? sanitize_key( $parts[2] ) : '';

			$source = array(
				'url'         => $url,
				'raw_url'     => isset( $parts[0] ) ? $parts[0] : '',
				'category_id' => absint( isset( $parts[1] ) ? $parts[1] : 0 ),
				'source_key'  => $source_key,
				'enabled'     => $enabled,
				'valid'       => $this->is_safe_url( $url ),
			);

			$source['id'] = self::source_id( $source );
			$sources[]    = $source;
		}

		return $sources;
	}

	/**
	 * Stable identifier for a source, used to key health and per-source state.
	 *
	 * Prefers the explicit source key so that editing a URL's query string
	 * does not silently reset that source's history.
	 *
	 * @param array $source Source definition.
	 * @return string
	 */
	public static function source_id( $source ) {
		$key = sanitize_key( isset( $source['source_key'] ) ? $source['source_key'] : '' );
		if ( '' !== $key ) {
			return 'key:' . $key;
		}

		$url = isset( $source['url'] ) ? (string) $source['url'] : '';

		return 'url:' . md5( $url );
	}

	/**
	 * Render a parsed source back to its settings line.
	 *
	 * @param array $source Source definition.
	 * @return string
	 */
	public static function to_line( $source ) {
		$line = esc_url_raw( isset( $source['url'] ) ? $source['url'] : '' );
		if ( '' === $line ) {
			return '';
		}

		$category = absint( isset( $source['category_id'] ) ? $source['category_id'] : 0 );
		$key      = sanitize_key( isset( $source['source_key'] ) ? $source['source_key'] : '' );

		if ( $category || '' !== $key ) {
			$line .= '|' . $category;
		}
		if ( '' !== $key ) {
			$line .= '|' . $key;
		}

		return empty( $source['enabled'] ) ? '!' . $line : $line;
	}

	/**
	 * Fetch and normalize a feed.
	 *
	 * @param array $source Source definition.
	 * @param int   $max_items Max items.
	 * @return array|WP_Error
	 */
	public function fetch( $source, $max_items = 20 ) {
		if ( empty( $source['valid'] ) || ! $this->is_safe_url( $source['url'] ?? '' ) ) {
			return new WP_Error( 'wpnc_invalid_feed_url', __( 'Invalid or unsafe feed URL.', 'wp-news-collector' ) );
		}

		require_once ABSPATH . WPINC . '/feed.php';

		$feed = fetch_feed( $source['url'] );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$max_items   = max( 1, min( 100, absint( $max_items ) ) );
		$feed_title  = sanitize_text_field( $feed->get_title() );
		$feed_items  = $feed->get_items( 0, min( $feed->get_item_quantity( $max_items ), $max_items ) );
		$items       = array();
		$source_name = $feed_title ? $feed_title : wp_parse_url( $source['url'], PHP_URL_HOST );

		foreach ( $feed_items as $feed_item ) {
			$permalink = esc_url_raw( $feed_item->get_permalink() );
			$guid      = sanitize_text_field( $feed_item->get_id() );

			if ( empty( $permalink ) && $this->is_safe_url( $guid ) ) {
				$permalink = esc_url_raw( $guid );
			}

			if ( empty( $permalink ) ) {
				continue;
			}

			$items[] = array(
				'raw_item'     => $feed_item,
				'source_name'  => $source_name,
				'feed_url'     => esc_url_raw( $source['url'] ),
				'source_key'   => sanitize_key( $source['source_key'] ?? '' ),
				'category_id'  => absint( $source['category_id'] ?? 0 ),
				'guid'         => $guid,
				'title'        => sanitize_text_field( $feed_item->get_title() ),
				'description'  => wp_kses_post( $feed_item->get_description() ),
				'main_link'    => $permalink,
				'pub_date'     => $feed_item->get_date( 'Y-m-d H:i:s' ) ? $feed_item->get_date( 'Y-m-d H:i:s' ) : WPNC_Time::now(),
				'image_url'    => '',
				'tags'         => '',
			);
		}

		return array(
			'title' => $source_name,
			'url'   => esc_url_raw( $source['url'] ),
			'items' => $items,
		);
	}

	/**
	 * Check if a URL is safe enough for outbound requests.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_safe_url( $url ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! $this->is_private_ip( $host );
		}

		$addresses = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
		if ( is_array( $addresses ) ) {
			foreach ( $addresses as $address ) {
				if ( $this->is_private_ip( $address ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check private/reserved IP ranges.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private function is_private_ip( $ip ) {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
