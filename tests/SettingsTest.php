<?php
/**
 * Settings validators.
 *
 * The point of these is not that the values are clamped - they always were -
 * but that clamping now tells the admin it happened. Before 1.3.0 nothing in
 * this plugin ever called add_settings_error.
 */

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	private function notice_codes() {
		return array_column( WPNC_Test_Options::$notices, 'code' );
	}

	public function test_valid_interval_is_accepted_without_a_notice() {
		$this->assertSame( 'daily', WPNC_Settings::sanitize_interval( 'daily' ) );
		$this->assertSame( array(), $this->notice_codes() );
	}

	public function test_unknown_interval_falls_back_and_says_so() {
		$this->assertSame( 'hourly', WPNC_Settings::sanitize_interval( 'every-picosecond' ) );
		$this->assertContains( 'wpnc_bad_interval', $this->notice_codes() );
	}

	public function test_unknown_post_type_falls_back_and_says_so() {
		$this->assertSame( 'post', WPNC_Settings::sanitize_post_type( 'attachment' ) );
		$this->assertContains( 'wpnc_bad_post_type', $this->notice_codes() );
	}

	public function test_known_post_types_pass_through() {
		$this->assertSame( 'post', WPNC_Settings::sanitize_post_type( 'post' ) );
		$this->assertSame( 'wpnc_news', WPNC_Settings::sanitize_post_type( 'wpnc_news' ) );
		$this->assertSame( array(), $this->notice_codes() );
	}

	public function test_language_is_restricted_to_the_two_it_ships() {
		$this->assertSame( 'en', WPNC_Settings::sanitize_language( 'en' ) );
		$this->assertSame( 'fa', WPNC_Settings::sanitize_language( 'fa' ) );
		$this->assertSame( array(), $this->notice_codes() );

		$this->assertSame( 'fa', WPNC_Settings::sanitize_language( 'de' ) );
		$this->assertContains( 'wpnc_bad_lang', $this->notice_codes() );
	}

	public function test_max_items_is_clamped_and_reported() {
		$this->assertSame( 100, WPNC_Settings::sanitize_max_items( 5000 ) );
		$this->assertContains( 'wpnc_max_items_clamped', $this->notice_codes() );

		WPNC_Test_Options::reset();
		$this->assertSame( 1, WPNC_Settings::sanitize_max_items( 0 ) );
		$this->assertContains( 'wpnc_max_items_clamped', $this->notice_codes() );

		WPNC_Test_Options::reset();
		$this->assertSame( 20, WPNC_Settings::sanitize_max_items( 20 ) );
		$this->assertSame( array(), $this->notice_codes(), 'An in-range value must not raise a notice.' );
	}

	public function test_timeout_is_clamped_and_reported() {
		$this->assertSame( 30, WPNC_Settings::sanitize_timeout( 900 ) );
		$this->assertContains( 'wpnc_timeout_clamped', $this->notice_codes() );

		WPNC_Test_Options::reset();
		$this->assertSame( 3, WPNC_Settings::sanitize_timeout( 1 ) );

		WPNC_Test_Options::reset();
		$this->assertSame( 8, WPNC_Settings::sanitize_timeout( 8 ) );
		$this->assertSame( array(), $this->notice_codes() );
	}

	public function test_get_timeout_is_the_single_clamp_for_the_whole_plugin() {
		update_option( 'wpnc_request_timeout', 12 );

		$this->assertSame( 12, WPNC_Settings::get_timeout() );
		$this->assertSame( 27, WPNC_Settings::get_timeout( 15 ), 'OpenAI gets headroom on top of the same base.' );
	}

	public function test_get_timeout_uses_the_default_when_unset() {
		$this->assertSame( WPNC_Settings::DEFAULT_TIMEOUT, WPNC_Settings::get_timeout() );
	}

	public function test_retention_is_clamped_and_reported() {
		$this->assertSame( 365, WPNC_Settings::sanitize_retention( 10000 ) );
		$this->assertContains( 'wpnc_retention_clamped', $this->notice_codes() );

		WPNC_Test_Options::reset();
		$this->assertSame(
			1,
			WPNC_Settings::sanitize_retention( 0 ),
			'Zero days would mean deleting rows the moment they are processed.'
		);
	}

	public function test_retention_getters_survive_a_corrupt_option() {
		update_option( 'wpnc_queue_retention_days', 'nonsense' );
		$this->assertSame( 1, WPNC_Settings::get_queue_retention() );

		update_option( 'wpnc_log_retention_days', 99999 );
		$this->assertSame( 365, WPNC_Settings::get_log_retention() );
	}

	public function test_category_must_still_exist() {
		WPNC_Test_Options::$values['__terms'] = array( 7 );

		$this->assertSame( 7, WPNC_Settings::sanitize_category( 7 ) );
		$this->assertSame( array(), $this->notice_codes() );

		$this->assertSame( 0, WPNC_Settings::sanitize_category( 999 ) );
		$this->assertContains( 'wpnc_bad_category', $this->notice_codes() );
	}

	public function test_zero_category_means_none_and_is_not_an_error() {
		$this->assertSame( 0, WPNC_Settings::sanitize_category( 0 ) );
		$this->assertSame( array(), $this->notice_codes() );
	}

	public function test_author_must_still_exist() {
		WPNC_Test_Options::$values['__users'] = array( 3 );

		$this->assertSame( 3, WPNC_Settings::sanitize_post_author( 3 ) );

		$this->assertSame( 0, WPNC_Settings::sanitize_post_author( 42 ) );
		$this->assertContains( 'wpnc_bad_author', $this->notice_codes() );
	}

	public function test_post_status_is_restricted() {
		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
			$this->assertSame( $status, WPNC_Settings::sanitize_post_status( $status ) );
		}

		$this->assertSame(
			'publish',
			WPNC_Settings::sanitize_post_status( 'trash' ),
			'A status outside the allowed list must not reach wp_insert_post.'
		);
	}

	public function test_image_url_must_be_a_url() {
		$this->assertSame(
			'https://cdn.example/fallback.jpg',
			WPNC_Settings::sanitize_image_url( 'https://cdn.example/fallback.jpg' )
		);
		$this->assertSame( array(), $this->notice_codes() );

		$this->assertSame( '', WPNC_Settings::sanitize_image_url( 'not a url' ) );
		$this->assertContains( 'wpnc_bad_image', $this->notice_codes() );
	}

	public function test_empty_image_url_is_allowed_silently() {
		$this->assertSame( '', WPNC_Settings::sanitize_image_url( '' ) );
		$this->assertSame( array(), $this->notice_codes() );
	}

	public function test_secret_is_preserved_when_the_field_is_left_blank() {
		update_option( 'wpnc_openai_api_key', 'sk-existing' );

		$this->assertSame(
			'sk-existing',
			WPNC_Settings::sanitize_secret( '', 'wpnc_openai_api_key' ),
			'An empty field must never wipe a saved key.'
		);
	}

	public function test_secret_is_replaced_by_a_new_value() {
		update_option( 'wpnc_openai_api_key', 'sk-existing' );

		$this->assertSame( 'sk-new', WPNC_Settings::sanitize_secret( 'sk-new', 'wpnc_openai_api_key' ) );
	}

	public function test_secret_is_cleared_by_the_delete_sentinel() {
		update_option( 'wpnc_openai_api_key', 'sk-existing' );

		$this->assertSame( '', WPNC_Settings::sanitize_secret( '__delete__', 'wpnc_openai_api_key' ) );
	}

	public function test_checkbox_normalises_to_one_or_zero() {
		$this->assertSame( 1, WPNC_Settings::sanitize_checkbox( '1' ) );
		$this->assertSame( 1, WPNC_Settings::sanitize_checkbox( 'on' ) );
		$this->assertSame( 0, WPNC_Settings::sanitize_checkbox( '' ) );
		$this->assertSame( 0, WPNC_Settings::sanitize_checkbox( '0' ) );
		$this->assertSame( 0, WPNC_Settings::sanitize_checkbox( null ) );
	}
}
