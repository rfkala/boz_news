<?php
/**
 * What search engines read about a published item.
 *
 * News lives on search. A post imported and published by this plugin had no
 * meta description of its own - an SEO plugin invented one from the first
 * line of the text, which for an aggregated story is often the dateline - and
 * it was described to search engines as a generic article rather than as
 * news.
 *
 * Where Yoast or Rank Math is installed, this writes into their fields and
 * leaves the page output to them: two descriptions or two schema blocks are
 * worse than one. Without either, it prints its own.
 *
 * The text handling and the schema shape are pure, so they are testable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_SEO {

	/**
	 * Characters of description a search result actually shows.
	 */
	const DESCRIPTION_LIMIT = 155;

	/**
	 * Longest headline Google accepts in NewsArticle markup.
	 */
	const HEADLINE_LIMIT = 110;

	/**
	 * Our own copy of the description, kept whatever SEO plugin is installed.
	 */
	const META_DESCRIPTION = '_wpnc_seo_description';

	/**
	 * Our own copy of the focus keyword.
	 */
	const META_KEYWORD = '_wpnc_seo_keyword';

	/**
	 * Register the hooks.
	 */
	public static function boot() {
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 5 );

		// Yoast describes every post as an Article. For posts this plugin
		// published, it is news.
		add_filter( 'wpseo_schema_article_type', array( __CLASS__, 'yoast_article_type' ), 10, 2 );
	}

	/**
	 * A description of search-result length, ending on a whole word.
	 *
	 * @param string $text  Source text or HTML.
	 * @param int    $limit Longest result, in characters.
	 * @return string
	 */
	public static function meta_description( $text, $limit = self::DESCRIPTION_LIMIT ) {
		$text  = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text  = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$limit = max( 20, absint( $limit ) );

		if ( self::length( $text ) <= $limit ) {
			return $text;
		}

		$cut   = self::cut( $text, $limit - 1 );
		$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );

		// Back to the last space, unless that would throw away most of it.
		if ( false !== $space && $space > ( $limit / 2 ) ) {
			$cut = self::cut( $cut, $space );
		}

		return rtrim( $cut, " \t,.;:،؛" ) . '…';
	}

	/**
	 * A focus keyword: one line, no markup, a sensible length.
	 *
	 * @param string $value Raw keyword.
	 * @return string
	 */
	public static function clean_keyword( $value ) {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );

		return trim( self::cut( $value, 80 ), " \"'" );
	}

	/**
	 * Read the assistant's two-line answer.
	 *
	 * Asked for "DESCRIPTION:" and "KEYWORD:" lines; models add bold markers,
	 * quotes and the odd translated label often enough to be worth accepting.
	 *
	 * @param string $text Model output.
	 * @return array { description, keyword }
	 */
	public static function parse_ai( $text ) {
		$description = '';
		$keyword     = '';

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^[\*\s]*(?:meta\s+)?(?:description|توضیحات)[\*\s]*[:：]\s*(.+)$/iu', $line, $m ) ) {
				$description = trim( $m[1], " \"'*" );
			} elseif ( preg_match( '/^[\*\s]*(?:focus\s+)?(?:keyword|key\s+phrase|کلمه\s+کلیدی|کلیدواژه)[\*\s]*[:：]\s*(.+)$/iu', $line, $m ) ) {
				$keyword = trim( $m[1], " \"'*" );
			}
		}

		return array(
			'description' => '' !== $description ? self::meta_description( $description ) : '',
			'keyword'     => self::clean_keyword( $keyword ),
		);
	}

	/**
	 * NewsArticle structured data for one post.
	 *
	 * isBasedOn names the original: this is an aggregated story, and saying so
	 * in the markup is both accurate and what a search engine should be told.
	 * Fields with nothing in them are left out rather than sent empty.
	 *
	 * @param array $args headline, description, url, image, published,
	 *                    modified, author, publisher, logo, source_url,
	 *                    language.
	 * @return array
	 */
	public static function news_article( $args ) {
		$args = array_merge(
			array(
				'headline'    => '',
				'description' => '',
				'url'         => '',
				'image'       => '',
				'published'   => '',
				'modified'    => '',
				'author'      => '',
				'publisher'   => '',
				'logo'        => '',
				'source_url'  => '',
				'language'    => '',
			),
			(array) $args
		);

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'NewsArticle',
			'headline' => self::cut( trim( wp_strip_all_tags( (string) $args['headline'] ) ), self::HEADLINE_LIMIT ),
		);

		if ( '' !== $args['url'] ) {
			$data['mainEntityOfPage'] = array(
				'@type' => 'WebPage',
				'@id'   => (string) $args['url'],
			);
		}

		if ( '' !== $args['description'] ) {
			$data['description'] = (string) $args['description'];
		}

		if ( '' !== $args['image'] ) {
			$data['image'] = array( (string) $args['image'] );
		}

		if ( '' !== $args['published'] ) {
			$data['datePublished'] = (string) $args['published'];
		}

		if ( '' !== $args['modified'] ) {
			$data['dateModified'] = (string) $args['modified'];
		}

		if ( '' !== $args['author'] ) {
			$data['author'] = array(
				'@type' => 'Person',
				'name'  => (string) $args['author'],
			);
		}

		if ( '' !== $args['publisher'] ) {
			$data['publisher'] = array(
				'@type' => 'Organization',
				'name'  => (string) $args['publisher'],
			);

			if ( '' !== $args['logo'] ) {
				$data['publisher']['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => (string) $args['logo'],
				);
			}
		}

		if ( '' !== $args['source_url'] ) {
			$data['isBasedOn'] = (string) $args['source_url'];
		}

		if ( '' !== $args['language'] ) {
			$data['inLanguage'] = (string) $args['language'];
		}

		return $data;
	}

	/**
	 * Structured data as a script tag that cannot be broken out of.
	 *
	 * The headline comes from somebody else's feed. Without JSON_HEX_TAG a
	 * headline containing "</script>" would close the tag and run whatever
	 * followed it on every page view.
	 *
	 * @param array $data Structured data.
	 * @return string
	 */
	public static function json_ld( $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );

		return false === $json ? '' : '<script type="application/ld+json">' . $json . '</script>';
	}

	/**
	 * Which SEO plugin, if any, owns the page output.
	 *
	 * @return string yoast, rankmath or empty.
	 */
	public static function active_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}

		return '';
	}

	/**
	 * Store a post's description and keyword.
	 *
	 * Our own copy always. The installed SEO plugin's fields only when the
	 * description was actually written - by an editor or by the assistant -
	 * because overwriting its own template with an automatic cut of the first
	 * paragraph would take away the one setting its owner chose.
	 *
	 * @param int    $post_id     Post id.
	 * @param string $description Description.
	 * @param string $keyword     Focus keyword.
	 * @param bool   $written     Whether a person or the assistant wrote it.
	 */
	public static function write_meta( $post_id, $description, $keyword, $written ) {
		$description = self::meta_description( $description );
		$keyword     = self::clean_keyword( $keyword );

		if ( '' !== $description ) {
			update_post_meta( $post_id, self::META_DESCRIPTION, $description );
		}

		if ( '' !== $keyword ) {
			update_post_meta( $post_id, self::META_KEYWORD, $keyword );
		}

		$fields = array(
			'yoast'    => array( '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ),
			'rankmath' => array( 'rank_math_description', 'rank_math_focus_keyword' ),
		);

		$plugin = self::active_plugin();

		if ( ! isset( $fields[ $plugin ] ) ) {
			return;
		}

		if ( $written && '' !== $description ) {
			update_post_meta( $post_id, $fields[ $plugin ][0], $description );
		}

		if ( '' !== $keyword ) {
			update_post_meta( $post_id, $fields[ $plugin ][1], $keyword );
		}
	}

	/**
	 * Description and NewsArticle markup for a post this plugin published.
	 */
	public static function print_head() {
		if ( ! is_singular() || '' !== self::active_plugin() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof WP_Post ) || ! get_post_meta( $post->ID, '_wpnc_source_url', true ) ) {
			return;
		}

		$description = (string) get_post_meta( $post->ID, self::META_DESCRIPTION, true );

		if ( '' === $description ) {
			$description = self::meta_description( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content );
		}

		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		echo self::json_ld( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG.
			self::news_article(
				array(
					'headline'    => get_the_title( $post ),
					'description' => $description,
					'url'         => get_permalink( $post ),
					'image'       => has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, 'full' ) : '',
					'published'   => (string) get_post_time( 'c', true, $post ),
					'modified'    => (string) get_post_modified_time( 'c', true, $post ),
					'author'      => (string) get_the_author_meta( 'display_name', $post->post_author ),
					'publisher'   => (string) get_bloginfo( 'name' ),
					'logo'        => (string) get_site_icon_url( 512 ),
					'source_url'  => (string) get_post_meta( $post->ID, '_wpnc_source_url', true ),
					'language'    => (string) get_bloginfo( 'language' ),
				)
			)
		) . "\n";
	}

	/**
	 * Tell Yoast a post this plugin published is news.
	 *
	 * @param string|array $type    Schema type Yoast chose.
	 * @param int          $post_id Post id.
	 * @return string|array
	 */
	public static function yoast_article_type( $type, $post_id = 0 ) {
		return ( $post_id && get_post_meta( $post_id, '_wpnc_source_url', true ) ) ? 'NewsArticle' : $type;
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * The first characters of a text.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters.
	 * @return string
	 */
	private static function cut( $text, $length ) {
		$length = max( 0, (int) $length );

		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length, 'UTF-8' ) : substr( (string) $text, 0, $length );
	}
}
