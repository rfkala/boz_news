<?php
/**
 * One story, several headlines.
 *
 * The cost of getting this wrong runs both ways. Too loose, and two different
 * stories from the same city are filed as one, so a moderator rejecting the
 * duplicates throws away real news. Too strict, and the queue goes on showing
 * the same event five times. The cases here pin both edges.
 */

use PHPUnit\Framework\TestCase;

class SimilarityTest extends TestCase {

	public function test_arabic_letters_fold_into_their_persian_forms() {
		$this->assertSame( WPNC_Similarity::normalize( 'کیفیت' ), WPNC_Similarity::normalize( 'كيفيت' ) );
	}

	public function test_persian_and_arabic_digits_read_as_the_same_number() {
		$this->assertSame( '1403', WPNC_Similarity::normalize( '۱۴۰۳' ) );
		$this->assertSame( '1403', WPNC_Similarity::normalize( '١٤٠٣' ) );
	}

	public function test_a_zero_width_non_joiner_and_a_space_are_one_word_boundary() {
		$this->assertSame(
			WPNC_Similarity::normalize( 'می گوید' ),
			WPNC_Similarity::normalize( "می\u{200C}گوید" )
		);
	}

	public function test_diacritics_do_not_make_a_different_word() {
		$this->assertSame( WPNC_Similarity::normalize( 'خبر' ), WPNC_Similarity::normalize( 'خَبَر' ) );
	}

	public function test_two_outlets_reporting_one_earthquake_are_one_story() {
		$this->assertTrue(
			WPNC_Similarity::same_story(
				'زلزله ۵ ریشتری تهران را لرزاند',
				'زمین‌لرزه ۵ ریشتری در تهران'
			)
		);
	}

	public function test_a_different_story_from_the_same_city_is_kept_apart() {
		// Sharing "Tehran" is not sharing a story.
		$this->assertFalse(
			WPNC_Similarity::same_story(
				'زلزله ۵ ریشتری تهران را لرزاند',
				'قیمت دلار در بازار تهران کاهش یافت'
			)
		);
	}

	public function test_english_headlines_in_different_words_match_too() {
		$this->assertTrue(
			WPNC_Similarity::same_story(
				'Apple unveils iPhone at September event',
				'iPhone unveiled by Apple at its September event'
			)
		);
	}

	public function test_one_shared_word_is_never_enough() {
		$this->assertSame( 0.0, WPNC_Similarity::score( 'Tehran traffic', 'Tehran weather' ) );
	}

	public function test_stopwords_alone_do_not_make_a_match() {
		$this->assertSame( 0.0, WPNC_Similarity::score( 'این خبر در تهران', 'آن گزارش از اصفهان' ) );
	}

	public function test_the_closest_candidate_wins() {
		$candidates = array(
			10 => 'قیمت دلار در بازار تهران کاهش یافت',
			11 => 'زمین‌لرزه ۵ ریشتری در تهران',
			12 => 'زلزله ۵ ریشتری تهران را لرزاند؛ خسارتی گزارش نشد',
		);

		$this->assertSame( 12, WPNC_Similarity::best_match( 'زلزله ۵ ریشتری تهران را لرزاند', $candidates ) );
	}

	public function test_nothing_close_enough_means_no_group() {
		$this->assertSame( 0, WPNC_Similarity::best_match( 'Apple unveils iPhone', array( 5 => 'Oil prices fall in Asia' ) ) );
		$this->assertSame( 0, WPNC_Similarity::best_match( '', array( 5 => 'anything' ) ) );
	}
}
