<?php
/**
 * Reading what a news list was asked to show.
 *
 * The shortcode and the block must produce the same list from the same
 * choices, though one sends words and the other sends typed values.
 */

use PHPUnit\Framework\TestCase;

class BulletinTest extends TestCase {

	public function test_a_list_asked_for_nothing_shows_pictures_and_sources() {
		$args = WPNC_Bulletin::args( array() );

		$this->assertSame( 10, $args['limit'] );
		$this->assertSame( 'list', $args['layout'] );
		$this->assertTrue( $args['image'] );
		$this->assertTrue( $args['source'] );
		$this->assertSame( 30, $args['excerpt'] );
	}

	public function test_the_item_count_is_held_to_a_sane_range() {
		$this->assertSame( 1, WPNC_Bulletin::args( array( 'limit' => 0 ) )['limit'] );
		$this->assertSame( 50, WPNC_Bulletin::args( array( 'limit' => 500 ) )['limit'] );
		$this->assertSame( 1, WPNC_Bulletin::args( array( 'limit' => 'lots' ) )['limit'] );
	}

	public function test_an_unknown_layout_falls_back_to_the_list() {
		$this->assertSame( 'list', WPNC_Bulletin::args( array( 'layout' => 'carousel' ) )['layout'] );
		$this->assertSame( 'grid', WPNC_Bulletin::args( array( 'layout' => ' GRID ' ) )['layout'] );
	}

	public function test_a_switch_reads_the_same_from_a_shortcode_or_a_block() {
		foreach ( array( 'no', 'false', '0', 'off', '', false ) as $off ) {
			$this->assertFalse( WPNC_Bulletin::flag( $off ), var_export( $off, true ) . ' should be off' );
		}

		foreach ( array( 'yes', 'true', '1', 'on', true ) as $on ) {
			$this->assertTrue( WPNC_Bulletin::flag( $on ), var_export( $on, true ) . ' should be on' );
		}
	}

	public function test_the_summary_length_is_bounded_and_zero_hides_it() {
		$this->assertSame( 100, WPNC_Bulletin::args( array( 'excerpt' => 250 ) )['excerpt'] );
		$this->assertSame( 0, WPNC_Bulletin::args( array( 'excerpt' => 0 ) )['excerpt'] );
		$this->assertSame( '', WPNC_Bulletin::excerpt( 'Some text', 0 ) );
	}

	public function test_a_summary_is_plain_text_cut_to_its_words() {
		$this->assertSame( 'one two…', WPNC_Bulletin::excerpt( '<p>one <b>two</b> three four</p>', 2 ) );
		$this->assertSame( 'short', WPNC_Bulletin::excerpt( '<p>short</p>', 30 ) );
	}

	public function test_a_category_keeps_its_slug_but_not_markup() {
		$this->assertSame( 'world', WPNC_Bulletin::args( array( 'category' => '<b>world</b>' ) )['category'] );
	}
}
