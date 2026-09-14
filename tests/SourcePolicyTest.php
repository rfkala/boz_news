<?php
/**
 * Per-source rules.
 *
 * The one that matters most is that "review" means review: a source marked
 * for review must never publish itself because the global switch was on.
 */

use PHPUnit\Framework\TestCase;

class SourcePolicyTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	public function test_a_source_with_no_rules_follows_the_settings_both_ways() {
		$this->assertSame(
			array( 'publish' => true, 'rewrite' => false, 'channels' => null ),
			WPNC_Source_Policy::resolve( array(), true, false )
		);
		$this->assertSame(
			array( 'publish' => false, 'rewrite' => true, 'channels' => null ),
			WPNC_Source_Policy::resolve( array(), false, true )
		);
	}

	public function test_review_holds_the_item_even_when_auto_publish_is_on() {
		$resolved = WPNC_Source_Policy::resolve( array( 'mode' => 'review' ), true, false );

		$this->assertFalse( $resolved['publish'] );
	}

	public function test_a_trusted_source_publishes_even_when_auto_publish_is_off() {
		$resolved = WPNC_Source_Policy::resolve( array( 'mode' => 'publish' ), false, false );

		$this->assertTrue( $resolved['publish'] );
	}

	public function test_rewriting_can_be_forced_on_or_off_per_source() {
		$this->assertTrue( WPNC_Source_Policy::resolve( array( 'rewrite' => 'on' ), false, false )['rewrite'] );
		$this->assertFalse( WPNC_Source_Policy::resolve( array( 'rewrite' => 'off' ), false, true )['rewrite'] );
	}

	public function test_only_messengers_are_kept_and_in_the_order_they_are_declared() {
		$clean = WPNC_Source_Policy::sanitize( array( 'channels' => array( 'bale', 'site', 'nonsense', 'telegram' ) ) );

		$this->assertSame( array( 'telegram', 'bale' ), $clean['channels'] );
	}

	public function test_no_chosen_messengers_means_every_one_that_is_set_up() {
		$this->assertNull( WPNC_Source_Policy::resolve( array( 'mode' => 'publish' ), false, false )['channels'] );
		$this->assertSame(
			array( 'telegram' ),
			WPNC_Source_Policy::resolve( array( 'mode' => 'publish', 'channels' => array( 'telegram' ) ), false, false )['channels']
		);
	}

	public function test_an_unknown_mode_falls_back_to_following_the_settings() {
		$this->assertSame( 'inherit', WPNC_Source_Policy::sanitize( array( 'mode' => 'yolo' ) )['mode'] );
	}

	public function test_saving_the_defaults_stores_nothing() {
		WPNC_Source_Policy::save( 'key:irna', array( 'mode' => 'publish' ) );
		$this->assertArrayHasKey( 'key:irna', WPNC_Source_Policy::all() );

		WPNC_Source_Policy::save( 'key:irna', array( 'mode' => 'inherit' ) );
		$this->assertArrayNotHasKey( 'key:irna', WPNC_Source_Policy::all(), 'follows the settings, so nothing is pinned' );
	}

	public function test_only_a_real_source_id_can_carry_rules() {
		$this->assertTrue( WPNC_Source_Policy::valid_id( 'key:irna' ) );
		$this->assertTrue( WPNC_Source_Policy::valid_id( 'url:' . md5( 'https://example.com/feed' ) ) );

		$this->assertFalse( WPNC_Source_Policy::valid_id( 'key:<script>' ) );
		$this->assertFalse( WPNC_Source_Policy::valid_id( '../../wp-config' ) );
		$this->assertFalse( WPNC_Source_Policy::save( 'nonsense', array( 'mode' => 'publish' ) ) );
	}

	public function test_a_source_id_from_the_feed_reader_is_accepted() {
		$this->assertTrue( WPNC_Source_Policy::valid_id( WPNC_Feed_Reader::source_id( array( 'url' => 'https://example.com/feed' ) ) ) );
		$this->assertTrue( WPNC_Source_Policy::valid_id( WPNC_Feed_Reader::source_id( array( 'source_key' => 'irna_news' ) ) ) );
	}
}
