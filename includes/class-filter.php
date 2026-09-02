<?php
/**
 * Keyword include/exclude filtering.
 *
 * Deliberately free of WordPress state: the rules that decide whether a story
 * is imported at all should be testable without booting WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Filter {

	/**
	 * Decide whether an item survives the keyword filters.
	 *
	 * Rules, which the settings screen now also states out loud:
	 * - Exclude wins. Any exclude match drops the item.
	 * - Include is any-of. An empty include list keeps everything.
	 * - Matching is case-insensitive and matches inside words.
	 *
	 * @param string $title       Item title.
	 * @param string $description Item description, HTML allowed.
	 * @param string $include     Comma separated include words.
	 * @param string $exclude     Comma separated exclude words.
	 * @return bool
	 */
	public static function passes( $title, $description, $include, $exclude ) {
		$content = self::lower( $title . ' ' . self::strip( $description ) );

		foreach ( self::words( $exclude ) as $word ) {
			if ( false !== strpos( $content, self::lower( $word ) ) ) {
				return false;
			}
		}

		$include_words = self::words( $include );
		if ( empty( $include_words ) ) {
			return true;
		}

		foreach ( $include_words as $word ) {
			if ( false !== strpos( $content, self::lower( $word ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split a comma separated list into non-empty trimmed words.
	 *
	 * @param string $words Raw list.
	 * @return array
	 */
	public static function words( $words ) {
		$parts = array_map( 'trim', explode( ',', (string) $words ) );

		return array_values(
			array_filter(
				$parts,
				function ( $word ) {
					return '' !== $word;
				}
			)
		);
	}

	/**
	 * Lowercase, multibyte aware so Persian and Arabic text compare correctly.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Strip tags without depending on WordPress being loaded.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function strip( $value ) {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $value );
		}

		return trim( wp_specialchars_decode( strip_tags( (string) $value ) ) );
	}
}
