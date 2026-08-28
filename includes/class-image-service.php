<?php
/**
 * Image and full-text extraction helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Image_Service {

	/**
	 * Where article body paragraphs usually live.
	 */
	const CONTENT_QUERY = '//article//p | //main//p | //div[contains(@class, "content")]//p | //div[contains(@class, "post")]//p';

	/**
	 * @var WPNC_Feed_Reader
	 */
	private $feed_reader;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->feed_reader = new WPNC_Feed_Reader();
	}

	/**
	 * Extract image URL from a feed item or article page.
	 *
	 * @param SimplePie_Item $item      Feed item.
	 * @param string         $main_link Article URL.
	 * @return string
	 */
	public function extract_image( $item, $main_link ) {
		if ( $item && ( $enclosure = $item->get_enclosure() ) ) {
			$link = $enclosure->get_link();
			$type = $enclosure->get_type();
			if ( $link && 0 === strpos( (string) $type, 'image/' ) && $this->feed_reader->is_safe_url( $link ) ) {
				return esc_url_raw( $link );
			}
		}

		if ( ! $this->feed_reader->is_safe_url( $main_link ) ) {
			return '';
		}

		$html = $this->remote_get_body( $main_link );
		if ( empty( $html ) ) {
			return '';
		}

		$doc = $this->load_dom( $html );
		if ( ! $doc ) {
			return '';
		}

		$xpath   = new DOMXPath( $doc );
		$queries = array(
			'//meta[@property="og:image"]/@content',
			'//meta[@name="twitter:image"]/@content',
			'//article//img/@src',
		);

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length > 0 ) {
				$url = $this->node_to_url( $nodes->item( 0 ) );
				if ( $this->feed_reader->is_safe_url( $url ) ) {
					return esc_url_raw( $url );
				}
			}
		}

		return '';
	}

	/**
	 * Extract article body text.
	 *
	 * @param string $url Article URL.
	 * @return string
	 */
	public function extract_full_text( $url ) {
		if ( ! $this->feed_reader->is_safe_url( $url ) ) {
			return '';
		}

		$html = $this->remote_get_body( $url );
		if ( empty( $html ) ) {
			return '';
		}

		$doc = $this->load_dom( $html );
		if ( ! $doc ) {
			return '';
		}

		$xpath      = new DOMXPath( $doc );
		$paragraphs = $xpath->query( self::CONTENT_QUERY );
		if ( ! $paragraphs || 0 === $paragraphs->length ) {
			return '';
		}

		$content = '';
		$count   = 0;

		foreach ( $paragraphs as $paragraph ) {
			$text = trim( preg_replace( '/\s+/', ' ', $paragraph->nodeValue ) );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) < 50 : strlen( $text ) < 50 ) {
				continue;
			}

			$content .= '<p>' . esc_html( $text ) . '</p>';
			$count++;

			if ( $count >= 30 ) {
				break;
			}
		}

		return $content;
	}

	/**
	 * Sideload an image and set it as featured image.
	 *
	 * @param string $image_url Image URL.
	 * @param int    $post_id   Post ID.
	 * @param string $title     Attachment title.
	 * @return int|WP_Error
	 */
	public function sideload_featured_image( $image_url, $post_id, $title ) {
		if ( ! $this->feed_reader->is_safe_url( $image_url ) ) {
			return new WP_Error( 'wpnc_invalid_image_url', __( 'Invalid image URL.', 'wp-news-collector' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( esc_url_raw( $image_url ), absint( $post_id ), sanitize_text_field( $title ), 'id' );

		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Fetch a remote document body with bounded response size.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function remote_get_body( $url ) {
		$timeout = WPNC_Settings::get_timeout();

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 3,
				'limit_response_size' => 1024 * 1024,
				'reject_unsafe_urls'  => true,
				'user-agent'          => 'WP News Collector/' . WPNC_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Load HTML into DOMDocument.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	private function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		return $loaded ? $doc : null;
	}

	/**
	 * Read a URL from DOM node or attribute node.
	 *
	 * @param DOMNode $node DOM node.
	 * @return string
	 */
	private function node_to_url( $node ) {
		if ( ! $node ) {
			return '';
		}

		if ( $node instanceof DOMAttr ) {
			return $node->value;
		}

		if ( $node->attributes ) {
			foreach ( array( 'content', 'src', 'href' ) as $attribute ) {
				if ( $node->attributes->getNamedItem( $attribute ) ) {
					return $node->attributes->getNamedItem( $attribute )->nodeValue;
				}
			}
		}

		return trim( $node->nodeValue );
	}
}
