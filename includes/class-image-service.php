<?php
/**
 * Image and full-text extraction helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Image_Service {

	/**
	 * Candidate containers for an article body, best first.
	 *
	 * Taking the container rather than loose paragraphs is what lets the
	 * structure survive: headings, lists, quotes and images stay in place.
	 */
	const BODY_QUERIES = array(
		'//article',
		'//*[@itemprop="articleBody"]',
		'//div[contains(@class, "entry-content")]',
		'//div[contains(@class, "post-content")]',
		'//div[contains(@class, "article-body")]',
		'//div[contains(@class, "article-content")]',
		'//div[contains(@class, "content")]',
		'//main',
	);

	/**
	 * Elements that are never part of an article body.
	 */
	const STRIP_TAGS = array(
		'script', 'style', 'noscript', 'iframe', 'form', 'nav', 'aside',
		'header', 'footer', 'button', 'svg',
	);

	/**
	 * Fallback when no container looks like a body.
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
	 * Why the last extract_full_text() call returned nothing.
	 *
	 * @var string
	 */
	private $last_failure = '';

	/**
	 * Reason the last extraction produced no text, or an empty string.
	 *
	 * @return string
	 */
	public function last_failure() {
		return $this->last_failure;
	}

	/**
	 * Extract article body text.
	 *
	 * Returns '' for every failure. The reason is recorded separately so the
	 * caller can log it rather than silently keeping the feed summary and
	 * leaving the admin to wonder whether the setting works at all.
	 *
	 * @param string $url Article URL.
	 * @return string
	 */
	public function extract_full_text( $url ) {
		$this->last_failure = '';

		if ( ! $this->feed_reader->is_safe_url( $url ) ) {
			$this->last_failure = 'unsafe_url';
			return '';
		}

		$html = $this->remote_get_body( $url );
		if ( empty( $html ) ) {
			$this->last_failure = 'no_response';
			return '';
		}

		$doc = $this->load_dom( $html );
		if ( ! $doc ) {
			$this->last_failure = 'unparseable_html';
			return '';
		}

		$xpath = new DOMXPath( $doc );
		$body  = $this->find_body( $xpath, $url );

		if ( $body ) {
			$html = $this->node_to_html( $doc, $body, $url );
			if ( '' !== $html ) {
				return $html;
			}
		}

		// Nothing looked like an article container, so fall back to loose
		// paragraphs. Still keeps inline markup, unlike the old behaviour.
		$paragraphs = $xpath->query( self::CONTENT_QUERY );
		if ( ! $paragraphs || 0 === $paragraphs->length ) {
			$this->last_failure = 'no_matching_paragraphs';
			return '';
		}

		$content = '';
		$count   = 0;

		foreach ( $paragraphs as $paragraph ) {
			$text = trim( preg_replace( '/\s+/', ' ', $paragraph->textContent ) );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) < 50 : strlen( $text ) < 50 ) {
				continue;
			}

			$content .= $this->node_to_html( $doc, $paragraph, $url );
			$count++;

			if ( $count >= 40 ) {
				break;
			}
		}

		if ( '' === $content ) {
			$this->last_failure = 'paragraphs_too_short';
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
			return new WP_Error( 'wpnc_invalid_image_url', wpnc__( 'Invalid image URL.', 'آدرس تصویر نامعتبر است.' ) );
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
	 * Pick the node most likely to be the article body.
	 *
	 * Scores candidates by the amount of paragraph text they hold, so a
	 * sidebar or comment block does not win just by matching first.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @param string   $url   Article URL, for resolving relative links.
	 * @return DOMNode|null
	 */
	private function find_body( $xpath, $url ) {
		$best  = null;
		$score = 0;

		foreach ( self::BODY_QUERIES as $query ) {
			$nodes = $xpath->query( $query );
			if ( ! $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$length = 0;
				foreach ( $node->getElementsByTagName( 'p' ) as $paragraph ) {
					$text = trim( $paragraph->textContent );
					if ( 40 < strlen( $text ) ) {
						$length += strlen( $text );
					}
				}

				if ( $length > $score ) {
					$score = $length;
					$best  = $node;
				}
			}
		}

		// Below this there is no article worth calling full text.
		return $score >= 200 ? $best : null;
	}

	/**
	 * Serialise a node to sanitised HTML with absolute URLs.
	 *
	 * @param DOMDocument $doc  Owner document.
	 * @param DOMNode     $node Node to serialise.
	 * @param string      $url  Page URL, used to absolutise src and href.
	 * @return string
	 */
	private function node_to_html( $doc, $node, $url ) {
		$clone = $node->cloneNode( true );

		// Drop chrome that lives inside the body container.
		foreach ( self::STRIP_TAGS as $tag ) {
			$found = $clone->getElementsByTagName( $tag );
			for ( $i = $found->length - 1; $i >= 0; $i-- ) {
				$element = $found->item( $i );
				if ( $element && $element->parentNode ) {
					$element->parentNode->removeChild( $element );
				}
			}
		}

		$this->absolutise( $clone, $url );

		$html = '';
		if ( XML_ELEMENT_NODE === $clone->nodeType && in_array( strtolower( $clone->nodeName ), array( 'article', 'div', 'main', 'section' ), true ) ) {
			// Unwrap the container so the stored content is its children.
			foreach ( $clone->childNodes as $child ) {
				$html .= $doc->saveHTML( $child );
			}
		} else {
			$html = $doc->saveHTML( $clone );
		}

		$html = wp_kses( $html, WPNC_AI_Rewriter::allowed_html() );
		$html = preg_replace( '/(?:\s*<p>\s*(?:&nbsp;)?\s*<\/p>)+/i', '', $html );

		return trim( $html );
	}

	/**
	 * Rewrite relative src and href values against the page URL, so images
	 * and links still resolve once the content lives on another site.
	 *
	 * @param DOMNode $node Node to walk.
	 * @param string  $url  Page URL.
	 */
	private function absolutise( $node, $url ) {
		if ( ! ( $node instanceof DOMElement ) && ! ( $node instanceof DOMDocument ) ) {
			return;
		}

		$base = wp_parse_url( $url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return;
		}

		$origin = $base['scheme'] . '://' . $base['host'];
		$dir    = isset( $base['path'] ) ? preg_replace( '#/[^/]*$#', '/', $base['path'] ) : '/';

		foreach ( array( 'a' => 'href', 'img' => 'src' ) as $tag => $attribute ) {
			foreach ( $node->getElementsByTagName( $tag ) as $element ) {
				$value = trim( (string) $element->getAttribute( $attribute ) );

				// Lazy-loaded images keep the real URL in a data attribute.
				if ( 'img' === $tag && ( '' === $value || 0 === strpos( $value, 'data:' ) ) ) {
					foreach ( array( 'data-src', 'data-original', 'data-lazy-src' ) as $alt ) {
						$lazy = trim( (string) $element->getAttribute( $alt ) );
						if ( '' !== $lazy ) {
							$value = $lazy;
							break;
						}
					}
				}

				if ( '' === $value || 0 === strpos( $value, 'data:' ) || 0 === strpos( $value, '#' ) ) {
					continue;
				}

				if ( 0 === strpos( $value, '//' ) ) {
					$value = $base['scheme'] . ':' . $value;
				} elseif ( 0 === strpos( $value, '/' ) ) {
					$value = $origin . $value;
				} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
					$value = $origin . $dir . $value;
				}

				$element->setAttribute( $attribute, esc_url_raw( $value ) );
			}
		}
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
