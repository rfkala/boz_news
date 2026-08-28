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

			$parts      = array_map( 'trim', explode( '|', $line ) );
			$url        = esc_url_raw( $parts[0] ?? '' );
			$source_key = isset( $parts[2] ) ? sanitize_key( $parts[2] ) : '';

			if ( ! $this->is_safe_url( $url ) ) {
				$sources[] = array(
					'url'         => $url,
					'category_id' => absint( $parts[1] ?? 0 ),
					'source_key'  => $source_key,
					'valid'       => false,
				);
				continue;
			}

			$sources[] = array(
				'url'         => $url,
				'category_id' => absint( $parts[1] ?? 0 ),
				'source_key'  => $source_key,
				'valid'       => true,
			);
		}

		return $sources;
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
				'pub_date'     => $feed_item->get_date( 'Y-m-d H:i:s' ) ? $feed_item->get_date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
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
