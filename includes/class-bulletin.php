<?php
/**
 * What a news list on the site was asked to show.
 *
 * The shortcode and the block take the same options from two directions: a
 * shortcode writes every value as a string ("yes", "0", "grid"), a block sends
 * typed ones (true, 0, "grid"). Reading both in one place is what keeps the
 * two from drifting into two slightly different lists.
 *
 * Free of WordPress state on purpose, so it is unit testable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Bulletin {

	/**
	 * How a list can be laid out.
	 */
	const LAYOUTS = array( 'list', 'grid' );

	/**
	 * A list that was asked for nothing in particular.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'limit'    => 10,
			'category' => '',
			'layout'   => 'list',
			'image'    => 'yes',
			'excerpt'  => 30,
			'source'   => 'yes',
		);
	}

	/**
	 * Options as the renderer needs them.
	 *
	 * @param mixed $raw Shortcode attributes, block attributes or posted values.
	 * @return array limit, category, layout, image (bool), excerpt, source (bool).
	 */
	public static function args( $raw ) {
		$raw    = array_merge( self::defaults(), is_array( $raw ) ? $raw : array() );
		$layout = strtolower( trim( (string) $raw['layout'] ) );

		return array(
			'limit'    => max( 1, min( 50, absint( $raw['limit'] ) ) ),
			'category' => sanitize_text_field( (string) $raw['category'] ),
			'layout'   => in_array( $layout, self::LAYOUTS, true ) ? $layout : 'list',
			'image'    => self::flag( $raw['image'] ),
			'excerpt'  => min( 100, absint( $raw['excerpt'] ) ),
			'source'   => self::flag( $raw['source'] ),
		);
	}

	/**
	 * A switch, whether a block sent a boolean or a shortcode wrote a word.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'no', 'false', 'off' ), true );
	}

	/**
	 * A plain-text summary cut to a number of words.
	 *
	 * @param string $text  Post text or HTML.
	 * @param int    $words Words to keep; 0 for none.
	 * @return string
	 */
	public static function excerpt( $text, $words ) {
		$words = absint( $words );

		if ( 0 === $words ) {
			return '';
		}

		$plain = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );

		return '' === $plain ? '' : wp_trim_words( $plain, $words, '…' );
	}
}
