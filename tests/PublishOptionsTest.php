<?php
/**
 * Per-item publishing overrides.
 *
 * The rule these all circle is that an absent override means "inherit", not
 * "set to empty". Getting that backwards would pin every item edited today to
 * whatever the settings happened to say today, and changing a setting later
 * would then move nothing.
 */

use PHPUnit\Framework\TestCase;

class PublishOptionsTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	private function defaults() {
		return array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_author' => 3,
			'category_id' => 7,
		);
	}

	public function test_an_item_with_no_opinion_inherits_every_default() {
		$this->assertSame( $this->defaults(), WPNC_Publish_Options::merge( array(), $this->defaults() ) );
	}

	public function test_an_override_wins_only_for_the_field_it_names() {
		$merged = WPNC_Publish_Options::merge( array( 'post_status' => 'draft' ), $this->defaults() );

		$this->assertSame( 'draft', $merged['post_status'] );
		$this->assertSame( 'post', $merged['post_type'] );
		$this->assertSame( 3, $merged['post_author'] );
		$this->assertSame( 7, $merged['category_id'] );
	}

	public function test_a_blank_override_means_inherit_rather_than_empty() {
		// This is the whole point: a select left on "use the default" must not
		// pin the item to today's settings.
		$merged = WPNC_Publish_Options::merge(
			array(
				'post_type'   => '',
				'post_status' => '',
				'post_author' => 0,
				'category_id' => '',
			),
			$this->defaults()
		);

		$this->assertSame( $this->defaults(), $merged );
	}

	public function test_an_unknown_status_is_dropped_rather_than_stored() {
		$merged = WPNC_Publish_Options::merge( array( 'post_status' => 'trash' ), $this->defaults() );

		$this->assertSame( 'publish', $merged['post_status'], 'an unusable status must fall back, not be written to a post' );
	}

	public function test_sanitize_keeps_only_fields_that_carry_an_opinion() {
		$clean = WPNC_Publish_Options::sanitize(
			array(
				'post_type'   => 'news',
				'post_status' => '',
				'post_author' => '12',
				'category_id' => 0,
				'nonsense'    => 'x',
			)
		);

		$this->assertSame( array( 'post_type' => 'news', 'post_author' => 12 ), $clean );
	}

	public function test_nothing_at_all_is_stored_as_nothing() {
		// An item that inherits everything stores an empty string, so
		// "inherits" stays distinguishable from "was pinned to these values".
		$this->assertSame( '', WPNC_Publish_Options::encode( array() ) );
		$this->assertSame( '', WPNC_Publish_Options::encode( array( 'post_status' => '' ) ) );
		$this->assertSame( '', WPNC_Publish_Options::encode( 'not an array' ) );
	}

	public function test_a_stored_override_survives_the_round_trip() {
		$json = WPNC_Publish_Options::encode(
			array(
				'post_type'   => 'news',
				'post_status' => 'draft',
				'post_author' => 4,
				'category_id' => 9,
			)
		);

		$this->assertSame(
			array(
				'post_type'   => 'news',
				'post_status' => 'draft',
				'post_author' => 4,
				'category_id' => 9,
			),
			WPNC_Publish_Options::decode( $json )
		);
	}

	public function test_a_corrupt_column_reads_as_no_overrides_rather_than_fataling() {
		$this->assertSame( array(), WPNC_Publish_Options::decode( '' ) );
		$this->assertSame( array(), WPNC_Publish_Options::decode( null ) );
		$this->assertSame( array(), WPNC_Publish_Options::decode( '{ not json' ) );
		$this->assertSame( array(), WPNC_Publish_Options::decode( '"a string"' ) );
	}

	public function test_defaults_come_from_the_settings() {
		update_option( 'wpnc_target_post_type', 'news' );
		update_option( 'wpnc_post_status', 'draft' );
		update_option( 'wpnc_post_author', '5' );
		update_option( 'wpnc_default_category', '11' );

		$this->assertSame(
			array(
				'post_type'   => 'news',
				'post_status' => 'draft',
				'post_author' => 5,
				'category_id' => 11,
			),
			WPNC_Publish_Options::defaults()
		);
	}

	public function test_a_settings_status_that_is_no_longer_valid_falls_back() {
		update_option( 'wpnc_post_status', 'trash' );

		$this->assertSame( 'publish', WPNC_Publish_Options::defaults()['post_status'] );
	}
}
