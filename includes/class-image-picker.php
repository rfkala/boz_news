<?php
/**
 * Choosing, cleaning and comparing featured image candidates.
 *
 * Free of WordPress state on purpose: every decision here is made from its
 * arguments alone, so the rules that decide which picture a post gets are
 * unit testable without a network, a database or a media library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Image_Picker {

	/**
	 * Image types the plugin attaches, and the extension each is saved under.
	 *
	 * The extension comes from the bytes, never from the URL. CDN image
	 * addresses routinely carry no extension at all, and WordPress's own
	 * media_sideload_image() refused every one of those as "Invalid image
	 * URL" - which is a large part of why a picture attached from one source
	 * and never from another.
	 */
	const MIME_EXTENSIONS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/**
	 * Attributes a lazy loader keeps the real address in, best first.
	 */
	const LAZY_ATTRIBUTES = array( 'data-src', 'data-original', 'data-lazy-src', 'data-srcset', 'srcset' );

	/**
	 * Words in a file name that mark it as page furniture rather than the
	 * story's picture. Matched against the file name only, so a directory
	 * that happens to be called "lazy" does not disqualify its contents.
	 */
	const JUNK_NAMES = array( 'logo', 'avatar', 'spacer', 'blank', 'pixel', 'placeholder', 'emoji', 'favicon', 'sprite', 'loading', 'lazy' );

	/**
	 * Hosts that only ever serve tracking pixels or profile pictures.
	 */
	const JUNK_HOSTS = array( 'gravatar.com', 'feeds.feedburner.com', 'pixel.wp.com', 'stats.wp.com', 'doubleclick.net' );

	/**
	 * Resolve an address found in a page against that page's URL.
	 *
	 * The old extractor took og:image and article images exactly as written,
	 * so a protocol-relative "//cdn.example.com/a.jpg" or a root-relative
	 * "/uploads/a.jpg" failed the safety check and the item quietly got no
	 * picture at all.
	 *
	 * @param string $value    Address as it appeared.
	 * @param string $page_url URL of the page it appeared on.
	 * @return string Absolute http(s) URL, or '' when there is none to be had.
	 */
	public static function absolute( $value, $page_url ) {
		$value = self::encode_spaces( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value || 0 === strpos( $value, 'data:' ) || 0 === strpos( $value, '#' ) ) {
			return '';
		}

		// Anything with its own scheme is either already absolute or is not a
		// picture at all - javascript:, mailto:, ftp:.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $value ) ) {
			return preg_match( '#^https?://[^/]#i', $value ) ? $value : '';
		}

		$base = wp_parse_url( (string) $page_url );
		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $base['scheme'] );
		$origin = $scheme . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );

		if ( 0 === strpos( $value, '//' ) ) {
			return $scheme . ':' . $value;
		}

		// Keep any query or fragment out of the dot-segment clean-up.
		$suffix = '';
		$cut    = strcspn( $value, '?#' );
		if ( $cut < strlen( $value ) ) {
			$suffix = substr( $value, $cut );
			$value  = substr( $value, 0, $cut );
		}

		if ( '' === $value ) {
			$path = isset( $base['path'] ) ? $base['path'] : '/';
			return $origin . $path . $suffix;
		}

		if ( 0 !== strpos( $value, '/' ) ) {
			$dir   = isset( $base['path'] ) ? preg_replace( '#/[^/]*$#', '/', $base['path'] ) : '/';
			$value = ( '' === $dir ? '/' : $dir ) . $value;
		}

		return $origin . self::remove_dot_segments( $value ) . $suffix;
	}

	/**
	 * Percent-encode the whitespace esc_url_raw() would otherwise delete.
	 *
	 * esc_url_raw() strips a space rather than encoding it, so an image file
	 * named "photo 1.jpg" became a request for "photo1.jpg" and a 404.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function encode_spaces( $url ) {
		return str_replace( array( "\r", "\n", "\t", ' ' ), array( '', '', '', '%20' ), trim( (string) $url ) );
	}

	/**
	 * Whether an address is page furniture rather than the story's picture.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_junk( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return true;
		}

		$host = strtolower( $parts['host'] );
		foreach ( self::JUNK_HOSTS as $bad ) {
			if ( $host === $bad || substr( $host, -strlen( '.' . $bad ) ) === '.' . $bad ) {
				return true;
			}
		}

		$path = strtolower( rawurldecode( isset( $parts['path'] ) ? $parts['path'] : '' ) );
		$name = (string) substr( $path, (int) strrpos( $path, '/' ) );

		if ( preg_match( '/\.(svgz?|ico)$/', $name ) ) {
			return true;
		}

		foreach ( self::JUNK_NAMES as $token ) {
			if ( false !== strpos( $name, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a feed enclosure is a picture.
	 *
	 * The old check accepted an enclosure only when its declared type began
	 * with "image/". media:content often declares medium="image" and no type
	 * at all, and plenty of feeds send application/octet-stream for a JPEG,
	 * so a picture the feed plainly offered was skipped and the article page
	 * had to be downloaded to look for one instead.
	 *
	 * @param string $url    Enclosure URL.
	 * @param string $type   Declared MIME type.
	 * @param string $medium Media RSS medium.
	 * @return bool
	 */
	public static function enclosure_is_image( $url, $type = '', $medium = '' ) {
		$type   = strtolower( trim( (string) $type ) );
		$medium = strtolower( trim( (string) $medium ) );

		if ( 0 === strpos( $type, 'image/' ) || 'image' === $medium ) {
			return true;
		}

		// Declared as something else - audio, video - and meant it.
		$undeclared = '' === $type || 'application/octet-stream' === $type;
		if ( ! $undeclared || '' !== $medium ) {
			return false;
		}

		$parts = wp_parse_url( (string) $url );
		$path  = strtolower( is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '' );

		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif)$/', $path );
	}

	/**
	 * The first real picture in a fragment of HTML, such as a feed item.
	 *
	 * @param string $html     HTML.
	 * @param string $page_url URL to resolve relative addresses against.
	 * @return string
	 */
	public static function from_html( $html, $page_url ) {
		$html = (string) $html;
		if ( false === stripos( $html, '<img' ) ) {
			return '';
		}

		$doc = self::load( $html );
		if ( ! $doc ) {
			return '';
		}

		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
			$url = self::usable_source( $img, $page_url );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * The picture an article page names for itself.
	 *
	 * The page's own declarations come first, since that is the image the
	 * publisher chose for sharing; an image in the article body is the
	 * fallback. Every match is tried, not only the first - a page whose first
	 * og:image is a logo usually has the real one second.
	 *
	 * @param string $html     Page HTML.
	 * @param string $page_url Page URL.
	 * @return string
	 */
	public static function from_page( $html, $page_url ) {
		$doc = self::load( $html );
		if ( ! $doc ) {
			return '';
		}

		$xpath    = new DOMXPath( $doc );
		$declared = array(
			'//meta[@property="og:image:secure_url"]/@content',
			'//meta[@property="og:image:url"]/@content',
			'//meta[@property="og:image"]/@content',
			'//meta[@name="og:image"]/@content',
			'//meta[@name="twitter:image"]/@content',
			'//meta[@name="twitter:image:src"]/@content',
			'//meta[@property="twitter:image"]/@content',
			'//link[@rel="image_src"]/@href',
			'//meta[@itemprop="image"]/@content',
		);

		foreach ( $declared as $query ) {
			$nodes = $xpath->query( $query );
			if ( ! $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$url = self::absolute( $node->nodeValue, $page_url );
				if ( '' !== $url && ! self::is_junk( $url ) ) {
					return $url;
				}
			}
		}

		$in_body = $xpath->query( '//img[@itemprop="image"] | //article//img | //*[@itemprop="articleBody"]//img | //main//img' );
		if ( $in_body ) {
			foreach ( $in_body as $img ) {
				$url = self::usable_source( $img, $page_url );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/**
	 * The largest candidate in a srcset.
	 *
	 * @param string $srcset srcset attribute value.
	 * @return string
	 */
	public static function largest_from_srcset( $srcset ) {
		$best       = '';
		$best_width = -1.0;

		foreach ( preg_split( '/,\s+/', trim( (string) $srcset ) ) as $entry ) {
			$bits = preg_split( '/\s+/', trim( $entry ) );
			if ( empty( $bits[0] ) ) {
				continue;
			}

			$width = 0.0;
			if ( isset( $bits[1] ) && preg_match( '/^(\d+(?:\.\d+)?)([wx])$/i', $bits[1], $m ) ) {
				// Density descriptors are scaled so 2x outranks any plain entry.
				$width = 'x' === strtolower( $m[2] ) ? (float) $m[1] * 10000 : (float) $m[1];
			}

			if ( $width >= $best_width ) {
				$best       = rtrim( $bits[0], ',' );
				$best_width = $width;
			}
		}

		return $best;
	}

	/**
	 * Decide what a downloaded file really is.
	 *
	 * Only the bytes are trusted. A server's Content-Type header is not
	 * consulted, because an error page served as "image/jpeg" would then be
	 * saved into the media library as a broken picture.
	 *
	 * @param string|false $sniffed    What wp_get_image_mime() read from the file.
	 * @param string       $head_bytes The first bytes of the file.
	 * @return string MIME type, or '' when it is not an image we attach.
	 */
	public static function resolve_mime( $sniffed, $head_bytes = '' ) {
		$sniffed = strtolower( trim( (string) $sniffed ) );

		if ( isset( self::MIME_EXTENSIONS[ $sniffed ] ) ) {
			return $sniffed;
		}

		// Older PHP and WordPress cannot identify AVIF from the bytes. Its
		// ISO-BMFF brand sits at a fixed offset, which is evidence rather
		// than a claim.
		$brand = substr( (string) $head_bytes, 4, 8 );
		if ( 'ftypavif' === $brand || 'ftypavis' === $brand ) {
			return 'image/avif';
		}

		return '';
	}

	/**
	 * A safe file name for a downloaded image.
	 *
	 * Built from ASCII only. A Persian file name is valid on the web but not
	 * on every host's file system, and a name WordPress cannot write is one
	 * more way for an attachment to fail after the download succeeded.
	 *
	 * @param string $url  Image URL.
	 * @param string $mime Resolved MIME type.
	 * @return string Empty when the type is not one we attach.
	 */
	public static function filename( $url, $mime ) {
		if ( ! isset( self::MIME_EXTENSIONS[ $mime ] ) ) {
			return '';
		}

		$parts = wp_parse_url( (string) $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '';
		$slash = strrpos( $path, '/' );
		$base  = rawurldecode( (string) substr( $path, false === $slash ? 0 : $slash + 1 ) );
		$base  = preg_replace( '/\.[A-Za-z0-9]{2,5}$/', '', $base );
		$base  = trim( strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', (string) $base ) ), '-' );

		if ( strlen( $base ) < 3 ) {
			$base = 'wpnc-image-' . substr( md5( (string) $url ), 0, 10 );
		}

		return substr( $base, 0, 60 ) . '.' . self::MIME_EXTENSIONS[ $mime ];
	}

	/**
	 * Whether two addresses are the same picture.
	 *
	 * Scheme, a leading www, the query string, and WordPress's size suffixes
	 * are ignored: photo-1024x683.jpg?ver=2 is the same picture as photo.jpg.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_image( $a, $b ) {
		$first = self::identity( $a );

		return '' !== $first && self::identity( $b ) === $first;
	}

	/**
	 * Remove the featured image from an article body.
	 *
	 * The featured image is a property of the post, not a paragraph of the
	 * story. When the article's lead picture also becomes the featured image,
	 * a theme shows it once as the thumbnail and again at the top of the text.
	 *
	 * A wrapper the image was the only content of - a figure with its caption,
	 * a paragraph, a link - goes with it, since an empty wrapper renders as a
	 * gap. The markup is returned untouched unless something was removed:
	 * re-serialising a body nobody asked to change would rewrite it anyway.
	 *
	 * @param string $html      Body HTML.
	 * @param string $image_url Featured image URL.
	 * @param string $page_url  Article URL, for resolving relative sources.
	 * @return string
	 */
	public static function strip_duplicate( $html, $image_url, $page_url = '' ) {
		$html = (string) $html;

		if ( '' === trim( $html ) || '' === self::identity( $image_url ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$doc = self::load( '<div id="wpnc-strip-root">' . $html . '</div>' );
		if ( ! $doc ) {
			return $html;
		}

		$xpath = new DOMXPath( $doc );
		$roots = $xpath->query( '//div[@id="wpnc-strip-root"]' );
		if ( ! $roots || 0 === $roots->length ) {
			return $html;
		}

		$root   = $roots->item( 0 );
		$images = array();

		// Copied out first: the node list is live and shrinks as we remove.
		foreach ( $root->getElementsByTagName( 'img' ) as $img ) {
			$images[] = $img;
		}

		$removed = 0;

		foreach ( $images as $img ) {
			if ( ! self::image_matches( $img, $image_url, $page_url ) ) {
				continue;
			}

			$target = $img;
			while (
				$target->parentNode
				&& ! $target->parentNode->isSameNode( $root )
				&& in_array( strtolower( $target->parentNode->nodeName ), array( 'a', 'figure', 'p', 'picture', 'span', 'div' ), true )
				&& self::holds_only( $target->parentNode, $target )
			) {
				$target = $target->parentNode;
			}

			if ( $target->parentNode ) {
				$target->parentNode->removeChild( $target );
				$removed++;
			}
		}

		if ( 0 === $removed ) {
			return $html;
		}

		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		return trim( $out );
	}

	/**
	 * First usable address on an img element.
	 *
	 * @param DOMElement $img      Image element.
	 * @param string     $page_url Page URL.
	 * @return string
	 */
	private static function usable_source( $img, $page_url ) {
		if ( self::is_tiny( $img ) ) {
			return '';
		}

		foreach ( self::sources_of( $img, true ) as $candidate ) {
			$url = self::absolute( $candidate, $page_url );
			if ( '' !== $url && ! self::is_junk( $url ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Every address an img element carries.
	 *
	 * @param DOMElement $img          Image element.
	 * @param bool       $largest_only Take only the largest srcset entry.
	 * @return array
	 */
	private static function sources_of( $img, $largest_only ) {
		$out = array( (string) $img->getAttribute( 'src' ) );

		foreach ( self::LAZY_ATTRIBUTES as $attribute ) {
			$value = trim( (string) $img->getAttribute( $attribute ) );
			if ( '' === $value ) {
				continue;
			}

			if ( false === strpos( $attribute, 'srcset' ) ) {
				$out[] = $value;
				continue;
			}

			if ( $largest_only ) {
				$out[] = self::largest_from_srcset( $value );
				continue;
			}

			foreach ( preg_split( '/,\s+/', $value ) as $entry ) {
				$bits  = preg_split( '/\s+/', trim( $entry ) );
				$out[] = rtrim( (string) $bits[0], ',' );
			}
		}

		return array_values( array_filter( array_map( 'trim', $out ), 'strlen' ) );
	}

	/**
	 * Whether any address on an img element is the featured image.
	 *
	 * @param DOMElement $img       Image element.
	 * @param string     $image_url Featured image URL.
	 * @param string     $page_url  Article URL.
	 * @return bool
	 */
	private static function image_matches( $img, $image_url, $page_url ) {
		foreach ( self::sources_of( $img, false ) as $candidate ) {
			$url = '' !== $page_url ? self::absolute( $candidate, $page_url ) : $candidate;
			if ( self::same_image( $url, $image_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Width or height declared small enough to be an icon or a pixel.
	 *
	 * @param DOMElement $img Image element.
	 * @return bool
	 */
	private static function is_tiny( $img ) {
		foreach ( array( 'width', 'height' ) as $dimension ) {
			$value = trim( (string) $img->getAttribute( $dimension ) );
			if ( '' !== $value && ctype_digit( $value ) && (int) $value > 0 && (int) $value < 50 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a parent holds nothing but this child and its trimmings.
	 *
	 * @param DOMNode $parent Parent node.
	 * @param DOMNode $child  Child node.
	 * @return bool
	 */
	private static function holds_only( $parent, $child ) {
		foreach ( $parent->childNodes as $node ) {
			if ( $node->isSameNode( $child ) || XML_COMMENT_NODE === $node->nodeType ) {
				continue;
			}

			if ( XML_TEXT_NODE === $node->nodeType ) {
				// A non-breaking space is the usual filler an editor leaves.
				if ( '' === trim( str_replace( "\xC2\xA0", ' ', $node->nodeValue ) ) ) {
					continue;
				}
				return false;
			}

			// The caption of a removed picture describes nothing any more.
			if ( XML_ELEMENT_NODE === $node->nodeType && in_array( strtolower( $node->nodeName ), array( 'figcaption', 'source', 'br' ), true ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * The part of a URL that says which picture it is.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function identity( $url ) {
		$parts = wp_parse_url( self::encode_spaces( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}

		$host = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) );
		$path = rawurldecode( $parts['path'] );
		$path = preg_replace( '/-\d{2,5}x\d{2,5}(\.[A-Za-z0-9]{2,5})$/', '$1', $path );
		$path = preg_replace( '/-scaled(\.[A-Za-z0-9]{2,5})$/', '$1', $path );

		return $host . strtolower( $path );
	}

	/**
	 * Resolve "." and ".." in a path.
	 *
	 * @param string $path Path beginning with a slash.
	 * @return string
	 */
	private static function remove_dot_segments( $path ) {
		$out = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
				continue;
			}

			$out[] = $segment;
		}

		$result = implode( '/', $out );

		return 0 === strpos( $result, '/' ) ? $result : '/' . $result;
	}

	/**
	 * Parse HTML, tolerating the malformed markup real pages are made of.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	private static function load( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $doc : null;
	}
}
