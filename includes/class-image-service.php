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
	 * Largest image the plugin will attach, in bytes.
	 */
	const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

	/**
	 * How many article pages are kept in memory at once.
	 *
	 * Two: the page being worked on, and one spare so that finishing an item
	 * and starting the next does not immediately evict it.
	 */
	const PAGE_CACHE_SIZE = 2;

	/**
	 * Article pages already downloaded during this request.
	 *
	 * @var array
	 */
	private $page_cache = array();

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
	 * Where the last image lookup found its picture, or why it found none.
	 *
	 * @var string
	 */
	private $last_image_note = '';

	/**
	 * Outcome of the last image lookup: feed_media, feed_html, item_html or
	 * page when a picture was found; unsafe_url, page_no_response or
	 * page_no_image when not.
	 *
	 * @return string
	 */
	public function last_image_note() {
		return $this->last_image_note;
	}

	/**
	 * Find the featured image for a feed item.
	 *
	 * Cheapest and most deliberate first: the media the feed attached, then a
	 * picture in the item's own HTML, and only then the article page. The
	 * page used to be the only place anything but a typed enclosure was
	 * looked for, and fetching it can time out, be blocked, or just be slow -
	 * so whether an item got a picture depended on how that page happened to
	 * respond at that moment.
	 *
	 * @param SimplePie_Item|null $item      Feed item, or null to skip the feed.
	 * @param string              $main_link Article URL.
	 * @return string
	 */
	public function extract_image( $item, $main_link ) {
		$this->last_image_note = '';

		if ( is_object( $item ) ) {
			foreach ( $this->feed_media_candidates( $item ) as $candidate ) {
				$url = $this->accept( $candidate, $main_link );
				if ( '' !== $url ) {
					$this->last_image_note = 'feed_media';
					return $url;
				}
			}

			$fragments = array();
			if ( method_exists( $item, 'get_content' ) ) {
				$fragments[] = (string) $item->get_content();
			}
			if ( method_exists( $item, 'get_description' ) ) {
				$fragments[] = (string) $item->get_description();
			}

			foreach ( $fragments as $fragment ) {
				$url = $this->accept( WPNC_Image_Picker::from_html( $fragment, $main_link ), $main_link );
				if ( '' !== $url ) {
					$this->last_image_note = 'feed_html';
					return $url;
				}
			}
		}

		return $this->extract_image_from_page( $main_link );
	}

	/**
	 * Find a picture for an item already in the queue.
	 *
	 * The feed item is gone by then, so the stored text stands in for it.
	 *
	 * @param string $html      Stored item HTML.
	 * @param string $main_link Article URL.
	 * @return string
	 */
	public function find_image_for_text( $html, $main_link ) {
		$this->last_image_note = '';

		$url = $this->accept( WPNC_Image_Picker::from_html( (string) $html, $main_link ), $main_link );
		if ( '' !== $url ) {
			$this->last_image_note = 'item_html';
			return $url;
		}

		return $this->extract_image_from_page( $main_link );
	}

	/**
	 * The picture an article page declares for itself.
	 *
	 * @param string $main_link Article URL.
	 * @return string
	 */
	public function extract_image_from_page( $main_link ) {
		if ( ! $this->feed_reader->is_safe_url( $main_link ) ) {
			$this->last_image_note = 'unsafe_url';
			return '';
		}

		$html = $this->remote_get_body( $main_link );
		if ( '' === $html ) {
			$this->last_image_note = 'page_no_response';
			return '';
		}

		$url = $this->accept( WPNC_Image_Picker::from_page( $html, $main_link ), $main_link );
		if ( '' === $url ) {
			$this->last_image_note = 'page_no_image';
			return '';
		}

		$this->last_image_note = 'page';

		return $url;
	}

	/**
	 * A candidate, resolved and checked, or '' when it will not do.
	 *
	 * @param string $candidate Address as found.
	 * @param string $main_link Article URL.
	 * @return string
	 */
	private function accept( $candidate, $main_link ) {
		$url = WPNC_Image_Picker::absolute( (string) $candidate, $main_link );

		if ( '' === $url || WPNC_Image_Picker::is_junk( $url ) || ! $this->feed_reader->is_safe_url( $url ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Picture addresses a feed item carries as media.
	 *
	 * Every enclosure is read, not only the first - a podcast or video item
	 * often lists its picture second - and full images are preferred to the
	 * thumbnails Media RSS attaches to them.
	 *
	 * @param SimplePie_Item $item Feed item.
	 * @return array
	 */
	private function feed_media_candidates( $item ) {
		if ( ! method_exists( $item, 'get_enclosures' ) ) {
			return array();
		}

		$enclosures = $item->get_enclosures();
		$images     = array();
		$thumbnails = array();

		foreach ( is_array( $enclosures ) ? $enclosures : array() as $enclosure ) {
			if ( ! is_object( $enclosure ) ) {
				continue;
			}

			$link   = method_exists( $enclosure, 'get_link' ) ? (string) $enclosure->get_link() : '';
			$type   = method_exists( $enclosure, 'get_type' ) ? (string) $enclosure->get_type() : '';
			$medium = method_exists( $enclosure, 'get_medium' ) ? (string) $enclosure->get_medium() : '';

			if ( '' !== $link && WPNC_Image_Picker::enclosure_is_image( $link, $type, $medium ) ) {
				$images[] = $link;
			}

			$thumbs = method_exists( $enclosure, 'get_thumbnails' ) ? $enclosure->get_thumbnails() : null;
			foreach ( is_array( $thumbs ) ? $thumbs : array() as $thumbnail ) {
				if ( '' !== trim( (string) $thumbnail ) ) {
					$thumbnails[] = (string) $thumbnail;
				}
			}
		}

		return array_values( array_unique( array_merge( $images, $thumbnails ) ) );
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
	 * Download an image and make it the post's featured image.
	 *
	 * Replaces media_sideload_image(), which failed in three ways that
	 * together made the featured image appear for some sources and never for
	 * others: it refused any address without a .jpg/.png/.gif/.webp
	 * extension, which rules out most CDN URLs; it sent WordPress's own user
	 * agent and no Referer, which hotlink protection and many CDN firewalls
	 * refuse; and it reported every one of those as "Invalid image URL".
	 *
	 * @param string $image_url Image URL.
	 * @param int    $post_id   Post ID.
	 * @param string $title     Attachment title.
	 * @param string $referer   Article the image belongs to.
	 * @return int|WP_Error Attachment ID.
	 */
	public function sideload_featured_image( $image_url, $post_id, $title, $referer = '' ) {
		$image_url = esc_url_raw( WPNC_Image_Picker::encode_spaces( $image_url ) );

		if ( '' === $image_url || ! $this->feed_reader->is_safe_url( $image_url ) ) {
			return new WP_Error(
				'wpnc_image_unsafe_url',
				wpnc__( 'The image address is not a valid public URL.', 'آدرس تصویر یک نشانی عمومی معتبر نیست.' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Already in this site's media library: use it as it is. The default
		// image from Settings used to be downloaded again for every post
		// without a picture of its own, adding another copy each time.
		$existing = attachment_url_to_postid( $image_url );
		if ( $existing ) {
			set_post_thumbnail( $post_id, $existing );
			return (int) $existing;
		}

		$tmp = wp_tempnam( 'wpnc-image' );
		if ( ! $tmp ) {
			return new WP_Error(
				'wpnc_image_no_temp',
				wpnc__( 'A temporary file for the image could not be created.', 'ساخت فایل موقت برای تصویر ممکن نشد.' )
			);
		}

		$headers = array( 'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8' );
		if ( $referer && $this->feed_reader->is_safe_url( $referer ) ) {
			$headers['Referer'] = esc_url_raw( $referer );
		}

		$response = wp_safe_remote_get(
			$image_url,
			array(
				'timeout'             => max( 20, WPNC_Settings::get_timeout( 12 ) ),
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_IMAGE_BYTES,
				'user-agent'          => self::user_agent(),
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );

			return new WP_Error(
				'wpnc_image_download_failed',
				sprintf(
					/* translators: %s: transport error message */
					wpnc__( 'The image could not be downloaded: %s', 'دانلود تصویر ممکن نشد: %s' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			wp_delete_file( $tmp );

			return new WP_Error(
				'wpnc_image_http_status',
				sprintf(
					/* translators: %d: HTTP status code */
					wpnc__(
						'The image server answered HTTP %d instead of sending the picture.',
						'سرور تصویر به‌جای ارسال عکس، کد HTTP %d برگرداند.'
					),
					$status
				),
				array( 'status' => $status )
			);
		}

		clearstatcache( true, $tmp );
		$size = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;

		// A response cut off at the size limit still starts like an image,
		// and would be saved as a picture that never finishes loading.
		if ( $size >= self::MAX_IMAGE_BYTES ) {
			wp_delete_file( $tmp );

			return new WP_Error(
				'wpnc_image_too_large',
				sprintf(
					/* translators: %d: size limit in megabytes */
					wpnc__( 'The image is larger than %d MB, so it was not attached.', 'حجم تصویر بیش از %d مگابایت است و پیوست نشد.' ),
					(int) ( self::MAX_IMAGE_BYTES / 1048576 )
				)
			);
		}

		$head   = '';
		$handle = $size > 0 ? fopen( $tmp, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( $handle ) {
			$head = (string) fread( $handle, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fread
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		}

		$mime = WPNC_Image_Picker::resolve_mime( $size > 0 ? wp_get_image_mime( $tmp ) : false, $head );

		if ( '' === $mime ) {
			$sent = wp_remote_retrieve_header( $response, 'content-type' );
			$sent = is_array( $sent ) ? implode( ', ', $sent ) : (string) $sent;

			wp_delete_file( $tmp );

			return new WP_Error(
				'wpnc_image_not_an_image',
				sprintf(
					/* translators: %s: content type the server sent */
					wpnc__(
						'The address did not return a usable picture (the server sent %s).',
						'آن آدرس عکس قابل استفاده‌ای برنگرداند (سرور %s فرستاد).'
					),
					'' !== $sent ? $sent : wpnc__( 'no content type', 'بدون نوع محتوا' )
				)
			);
		}

		$file = array(
			'name'     => WPNC_Image_Picker::filename( $image_url, $mime ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, absint( $post_id ), sanitize_text_field( $title ) );

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload() leaves the temporary file behind when it fails.
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_wpnc_source_image', $image_url );
		set_post_thumbnail( $post_id, $attachment_id );

		return (int) $attachment_id;
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
		$key = md5( (string) $url );

		// Importing one item asks this class for the article's picture and
		// then for its text, and both used to download the same page. On a
		// run of twenty items that was twenty wasted requests and, on a slow
		// source, most of the time budget.
		if ( array_key_exists( $key, $this->page_cache ) ) {
			return $this->page_cache[ $key ];
		}

		$timeout = WPNC_Settings::get_timeout();

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 3,
				'limit_response_size' => 1024 * 1024,
				'reject_unsafe_urls'  => true,
				'user-agent'          => self::user_agent(),
			)
		);

		$body = '';

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = (string) wp_remote_retrieve_body( $response );
		}

		// One instance serves a whole fetch run, so the cache is cleared once
		// it stops being about the item in hand. A failed fetch is cached too:
		// a page that did not answer for the picture will not answer for the
		// text either, and waiting for it twice helps nobody.
		if ( count( $this->page_cache ) >= self::PAGE_CACHE_SIZE ) {
			$this->page_cache = array();
		}

		$this->page_cache[ $key ] = $body;

		return $body;
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
	 * User agent for fetching article pages and images.
	 *
	 * Many news CDNs and firewalls refuse WordPress's default agent, and the
	 * plugin's own name fared no better - one more reason a picture came from
	 * one source and never from another. Filterable for a site that would
	 * rather identify itself and can live with those refusals.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return (string) apply_filters(
			'wpnc_http_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
		);
	}
}
