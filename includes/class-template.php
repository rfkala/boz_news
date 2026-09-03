<?php
/**
 * Post body template.
 *
 * The body used to be hardcoded as "description, then a source line". A site
 * that wants the source first, a lead paragraph, or a credit block had no way
 * to say so without editing the plugin.
 *
 * Free of WordPress state on purpose, so the substitution rules are unit
 * testable without booting WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Template {

	/**
	 * What the plugin did before this class existed. Keeping it as the
	 * default means upgrading changes nothing until someone edits it.
	 */
	const DEFAULT_TEMPLATE = "{content}\n\n<p class=\"wpnc-source-link\">{source_label} {source_link}</p>";

	/**
	 * Placeholders a template may use, with a bilingual description for the
	 * settings screen.
	 *
	 * @return array
	 */
	public static function placeholders() {
		return array(
			'{content}'      => wpnc__( 'The article body', 'متن خبر' ),
			'{title}'        => wpnc__( 'The headline', 'عنوان خبر' ),
			'{excerpt}'      => wpnc__( 'First paragraph of the body', 'پاراگراف اول متن' ),
			'{source_name}'  => wpnc__( 'Name of the feed', 'نام منبع' ),
			'{source_url}'   => wpnc__( 'Link to the original article', 'آدرس خبر اصلی' ),
			'{source_link}'  => wpnc__( 'Ready-made link to the original', 'لینک آمادهٔ خبر اصلی' ),
			'{source_label}' => wpnc__( 'The word "Source:"', 'واژهٔ «منبع:»' ),
			'{date}'         => wpnc__( 'Publication date of the original', 'تاریخ انتشار خبر اصلی' ),
			'{image}'        => wpnc__( 'The image, when one was found', 'تصویر، در صورت وجود' ),
			'{tags}'         => wpnc__( 'Comma separated tags', 'برچسب‌ها با کاما' ),
		);
	}

	/**
	 * Render a template against one item's values.
	 *
	 * Unknown placeholders are left alone rather than blanked: a stray brace
	 * in the article text should survive, and a typo in a placeholder name
	 * should be visible instead of silently swallowing content.
	 *
	 * @param string $template Template string.
	 * @param array  $values   Placeholder name (without braces) => value.
	 * @return string
	 */
	public static function render( $template, $values ) {
		$template = (string) $template;
		if ( '' === trim( $template ) ) {
			$template = self::DEFAULT_TEMPLATE;
		}

		$search  = array();
		$replace = array();

		foreach ( $values as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}

		$out = str_replace( $search, $replace, $template );

		return self::tidy( $out );
	}

	/**
	 * Remove the debris an empty placeholder leaves behind.
	 *
	 * Without this, an item with no image renders an empty paragraph and a
	 * gap, which looks like a broken post rather than a post without an
	 * image.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public static function tidy( $html ) {
		// Paragraphs and figures that ended up with nothing in them.
		$html = preg_replace( '#<(p|figure|figcaption)[^>]*>\s*(?:&nbsp;|\x{00A0}|\s)*</\1>#iu', '', $html );

		// A link element left with no href is worse than no link.
		$html = preg_replace( '#<a[^>]*href=(["\'])\s*\1[^>]*>(.*?)</a>#is', '$2', $html );

		// Collapse the runs of blank lines those removals leave.
		$html = preg_replace( "/(?:[ \t]*\r?\n){3,}/", "\n\n", $html );

		return trim( $html );
	}

	/**
	 * First paragraph of a body, as plain text.
	 *
	 * @param string $html Body HTML.
	 * @param int    $words Maximum words.
	 * @return string
	 */
	public static function excerpt( $html, $words = 55 ) {
		$text = trim( wp_strip_all_tags( (string) $html ) );
		if ( '' === $text ) {
			return '';
		}

		return wp_trim_words( $text, max( 1, absint( $words ) ) );
	}

	/**
	 * Placeholder names a template actually uses.
	 *
	 * @param string $template Template string.
	 * @return array
	 */
	public static function used( $template ) {
		preg_match_all( '/\{([a-z_]+)\}/', (string) $template, $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Whether a template would drop the article body entirely.
	 *
	 * A template without {content} publishes posts with no story in them, so
	 * the settings screen warns rather than letting it happen quietly.
	 *
	 * @param string $template Template string.
	 * @return bool
	 */
	public static function omits_content( $template ) {
		$template = trim( (string) $template );

		return '' !== $template && ! in_array( 'content', self::used( $template ), true );
	}
}
