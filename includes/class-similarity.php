<?php
/**
 * Recognising one story reported by several sources.
 *
 * Five outlets covering one event produce five queue items under five
 * headlines, and nothing connected them: a moderator read, judged and
 * rejected the same story four times over. Headlines about one event share
 * the words that carry it - the names, the places, the numbers - even when
 * the phrasing around them differs, and that overlap is what this measures.
 *
 * Persian has to be normalised before any of it means anything. One word can
 * be typed with an Arabic yeh or kaf, with or without diacritics, joined by a
 * zero-width non-joiner or split by a space, in Persian or Latin digits.
 *
 * Free of WordPress state on purpose, so it is unit testable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Similarity {

	/**
	 * Score at or above which two headlines are one story.
	 */
	const THRESHOLD = 0.5;

	/**
	 * Words two headlines must share before they are compared at all.
	 *
	 * Without this, two three-word headlines sharing only "Tehran" would score
	 * well above the threshold and be filed as one story.
	 */
	const MIN_SHARED = 2;

	/**
	 * Words that appear in nearly every headline and say nothing about which
	 * story it is.
	 */
	const STOPWORDS = array(
		// Persian.
		'و', 'در', 'به', 'از', 'که', 'این', 'آن', 'ان', 'را', 'با', 'برای', 'تا', 'بر',
		'هم', 'نیز', 'یا', 'اما', 'یک', 'شد', 'شده', 'شود', 'است', 'بود', 'کرد', 'کرده',
		'کند', 'می', 'نمی', 'ها', 'های', 'ای', 'اند', 'ام', 'اید', 'ایم', 'خود', 'پس',
		'پیش', 'روی', 'درباره', 'دربارهٔ', 'چه', 'چرا', 'چگونه', 'هر', 'همه', 'باید',
		'خبر', 'گزارش',
		// English.
		'the', 'a', 'an', 'of', 'to', 'in', 'on', 'for', 'and', 'or', 'is', 'are', 'was',
		'were', 'be', 'with', 'at', 'by', 'from', 'as', 'it', 'its', 'that', 'this', 'after',
		'over', 'into', 'about', 'says', 'said', 'new',
	);

	/**
	 * Fold the spellings of one word into one form.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );

		$text = strtr(
			$text,
			array(
				'ي' => 'ی',
				'ى' => 'ی',
				'ئ' => 'ی',
				'ك' => 'ک',
				'ة' => 'ه',
				'ۀ' => 'ه',
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ؤ' => 'و',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);

		// Diacritics and the tatweel stretch a word without changing it.
		$text = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );

		// A zero-width non-joiner joins parts of one word visually; as a token
		// boundary it keeps "می‌گوید" and "می گوید" from being different words.
		$text = str_replace( array( "\u{200C}", "\u{200D}" ), ' ', $text );

		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The distinct words of a text that could identify a story.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	public static function tokens( $text ) {
		static $stop = null;

		if ( null === $stop ) {
			$stop = array_flip( self::STOPWORDS );
		}

		$out = array();

		foreach ( explode( ' ', self::normalize( $text ) ) as $word ) {
			if ( '' === $word || isset( $stop[ $word ] ) ) {
				continue;
			}

			// A single letter carries nothing; a single digit can be the
			// figure the whole story is about.
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );
			if ( $length < 2 && ! ctype_digit( $word ) ) {
				continue;
			}

			$out[ $word ] = true;
		}

		return array_keys( $out );
	}

	/**
	 * How much of their identifying vocabulary two headlines share.
	 *
	 * The Dice coefficient rather than Jaccard: outlets write headlines of
	 * very different lengths, and Dice penalises that difference less.
	 *
	 * @param string $a Headline.
	 * @param string $b Headline.
	 * @return float 0 to 1.
	 */
	public static function score( $a, $b ) {
		return self::score_tokens( self::tokens( $a ), self::tokens( $b ) );
	}

	/**
	 * Score two already tokenised headlines.
	 *
	 * @param array $x Tokens.
	 * @param array $y Tokens.
	 * @return float
	 */
	private static function score_tokens( $x, $y ) {
		if ( empty( $x ) || empty( $y ) ) {
			return 0.0;
		}

		$shared = count( array_intersect( $x, $y ) );

		if ( $shared < self::MIN_SHARED ) {
			return 0.0;
		}

		return round( ( 2 * $shared ) / ( count( $x ) + count( $y ) ), 4 );
	}

	/**
	 * Whether two headlines describe one story.
	 *
	 * @param string $a         Headline.
	 * @param string $b         Headline.
	 * @param float  $threshold Score required.
	 * @return bool
	 */
	public static function same_story( $a, $b, $threshold = self::THRESHOLD ) {
		return self::score( $a, $b ) >= $threshold;
	}

	/**
	 * The candidate a headline most resembles, if any resembles it enough.
	 *
	 * @param string $title      Headline.
	 * @param array  $candidates id => headline.
	 * @param float  $threshold  Score required.
	 * @return int Matching id, or 0.
	 */
	public static function best_match( $title, $candidates, $threshold = self::THRESHOLD ) {
		$tokens = self::tokens( $title );

		if ( empty( $tokens ) ) {
			return 0;
		}

		$best       = 0;
		$best_score = 0.0;

		foreach ( (array) $candidates as $id => $other ) {
			$score = self::score_tokens( $tokens, self::tokens( $other ) );

			if ( $score >= $threshold && $score > $best_score ) {
				$best       = (int) $id;
				$best_score = $score;
			}
		}

		return $best;
	}
}
