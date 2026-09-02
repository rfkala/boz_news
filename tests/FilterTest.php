<?php
/**
 * The include/exclude rules decide whether a story is imported at all, and
 * they were previously buried in a private method with no coverage.
 */

use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase {

	public function test_empty_include_list_keeps_everything() {
		$this->assertTrue( WPNC_Filter::passes( 'Anything at all', 'Body text', '', '' ) );
	}

	public function test_include_list_is_any_of_not_all_of() {
		$this->assertTrue(
			WPNC_Filter::passes( 'Tehran market report', 'Body', 'tehran, tokyo', '' ),
			'One match out of several include words is enough.'
		);

		$this->assertFalse(
			WPNC_Filter::passes( 'Paris market report', 'Body', 'tehran, tokyo', '' ),
			'No include word matched, so the item is dropped.'
		);
	}

	public function test_exclude_wins_over_include() {
		$this->assertFalse(
			WPNC_Filter::passes( 'Tehran sponsored content', 'Body', 'tehran', 'sponsored' ),
			'An exclude match must drop the item even when an include word matched.'
		);
	}

	public function test_matching_is_case_insensitive() {
		$this->assertTrue( WPNC_Filter::passes( 'TEHRAN Market', 'Body', 'tehran', '' ) );
		$this->assertFalse( WPNC_Filter::passes( 'Market', 'SPONSORED post', '', 'sponsored' ) );
	}

	public function test_matching_looks_inside_the_description_too() {
		$this->assertTrue( WPNC_Filter::passes( 'Headline', 'A story about tehran.', 'tehran', '' ) );
	}

	public function test_tags_between_characters_do_not_break_a_match() {
		$this->assertTrue(
			WPNC_Filter::passes( 'Headline', '<p>About <b>tehran</b> today</p>', 'tehran', '' )
		);

		$this->assertFalse(
			WPNC_Filter::passes( 'Headline', '<p>A <b>sponsored</b> post</p>', '', 'sponsored' )
		);
	}

	public function test_markup_attributes_are_not_searchable() {
		// Tags are stripped before matching, so an exclude word that only
		// appears inside an href never matches. Pinned deliberately: someone
		// will eventually try to block a domain this way and needs to find
		// out here rather than from a feed that imports anyway.
		$this->assertTrue(
			WPNC_Filter::passes( 'Headline', '<a href="https://spam.example">link</a>', '', 'spam.example' ),
			'Attribute text is not part of the searchable content.'
		);

		$this->assertFalse(
			WPNC_Filter::passes( 'Headline', '<a href="https://spam.example">spam.example</a>', '', 'spam.example' ),
			'The same domain as visible text is matched normally.'
		);
	}

	public function test_matching_is_substring_not_whole_word() {
		// This is a real property of the filter, not an accident: "iran"
		// matches "iranian". The settings screen says so.
		$this->assertTrue( WPNC_Filter::passes( 'Iranian economy', 'Body', 'iran', '' ) );
	}

	public function test_persian_text_matches_case_insensitively() {
		$this->assertTrue(
			WPNC_Filter::passes( 'بازار تهران امروز', 'متن خبر', 'تهران', '' ),
			'Persian has no case, but the multibyte lowercase must not corrupt it.'
		);

		$this->assertFalse(
			WPNC_Filter::passes( 'بازار تهران امروز', 'متن خبر', '', 'تهران' )
		);
	}

	public function test_trailing_and_repeated_commas_do_not_create_empty_words() {
		// An empty word would substring-match everything and silently drop or
		// keep the entire feed.
		$this->assertSame( array( 'a', 'b' ), WPNC_Filter::words( 'a, ,b,,' ) );

		$this->assertTrue(
			WPNC_Filter::passes( 'Unrelated headline', 'Body', '', 'spam,,' ),
			'A stray comma in the exclude list must not drop every item.'
		);

		$this->assertFalse(
			WPNC_Filter::passes( 'Unrelated headline', 'Body', 'tehran,,', '' ),
			'A stray comma in the include list must not keep every item.'
		);
	}

	public function test_words_trims_surrounding_whitespace() {
		$this->assertSame( array( 'one', 'two' ), WPNC_Filter::words( '  one ,  two  ' ) );
	}

	public function test_empty_string_yields_no_words() {
		$this->assertSame( array(), WPNC_Filter::words( '' ) );
		$this->assertSame( array(), WPNC_Filter::words( ' , , ' ) );
	}
}
