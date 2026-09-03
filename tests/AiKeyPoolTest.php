<?php
/**
 * Key rotation order.
 *
 * The whole point of the pool is "when this one runs out, use the next", so
 * the ordering rules are pinned rather than trusted.
 */

use PHPUnit\Framework\TestCase;

class AiKeyPoolTest extends TestCase {

	const NOW = 1800000000;

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	private function pool( $keys ) {
		update_option( WPNC_AI_Keys::OPTION, array( 'openai' => $keys ) );
	}

	private function state( $state ) {
		update_option( WPNC_AI_Keys::STATE, array( 'openai' => $state ) );
	}

	public function test_an_empty_pool_yields_no_order() {
		$this->assertSame( array(), WPNC_AI_Keys::order( 'openai', self::NOW ) );
	}

	public function test_keys_are_tried_in_the_order_they_were_added() {
		$this->pool( array( 'a' => 'k1', 'b' => 'k2', 'c' => 'k3' ) );

		$this->assertSame( array( 'a', 'b', 'c' ), WPNC_AI_Keys::order( 'openai', self::NOW ) );
	}

	public function test_the_pool_starts_at_the_key_that_last_worked() {
		$this->pool( array( 'a' => 'k1', 'b' => 'k2', 'c' => 'k3' ) );
		$this->state( array( 'current' => 'b' ) );

		// Wraps, so 'a' is still reachable without being tried first.
		$this->assertSame( array( 'b', 'c', 'a' ), WPNC_AI_Keys::order( 'openai', self::NOW ) );
	}

	public function test_a_resting_key_goes_to_the_back() {
		$this->pool( array( 'a' => 'k1', 'b' => 'k2', 'c' => 'k3' ) );
		$this->state( array( 'resting' => array( 'a' => self::NOW + 600 ) ) );

		$this->assertSame( array( 'b', 'c', 'a' ), WPNC_AI_Keys::order( 'openai', self::NOW ) );
	}

	public function test_a_rest_period_that_has_expired_no_longer_counts() {
		$this->pool( array( 'a' => 'k1', 'b' => 'k2' ) );
		$this->state( array( 'resting' => array( 'a' => self::NOW - 1 ) ) );

		$this->assertSame( array( 'a', 'b' ), WPNC_AI_Keys::order( 'openai', self::NOW ) );
	}

	public function test_every_key_resting_still_produces_an_order() {
		// Returning nothing here would report "no keys configured" to someone
		// who has several, which is the wrong diagnosis entirely.
		$this->pool( array( 'a' => 'k1', 'b' => 'k2' ) );
		$this->state( array( 'resting' => array( 'a' => self::NOW + 600, 'b' => self::NOW + 600 ) ) );

		$this->assertSame( array( 'a', 'b' ), WPNC_AI_Keys::order( 'openai', self::NOW ) );
	}

	public function test_blank_keys_are_not_part_of_the_pool() {
		$this->pool( array( 'a' => 'k1', 'b' => '   ', 'c' => '' ) );

		$this->assertSame( array( 'a' ), WPNC_AI_Keys::order( 'openai', self::NOW ) );
		$this->assertSame( array( 'a' => 'k1' ), WPNC_AI_Keys::for_provider( 'openai' ) );
	}

	public function test_a_working_key_becomes_the_starting_point_and_stops_resting() {
		$this->pool( array( 'a' => 'k1', 'b' => 'k2' ) );
		$this->state( array( 'resting' => array( 'b' => self::NOW + 600 ) ) );

		WPNC_AI_Keys::mark_working( 'openai', 'b' );

		$state = WPNC_AI_Keys::state();
		$this->assertSame( 'b', $state['openai']['current'] );
		$this->assertArrayNotHasKey( 'b', $state['openai']['resting'] );
	}

	public function test_waking_puts_every_key_back_into_service() {
		$this->pool( array( 'a' => 'k1', 'b' => 'k2' ) );
		$this->state( array( 'resting' => array( 'a' => self::NOW + 600, 'b' => self::NOW + 600 ) ) );

		WPNC_AI_Keys::wake( 'openai' );

		$state = WPNC_AI_Keys::state();
		$this->assertArrayNotHasKey( 'resting', $state['openai'] );
	}

	public function test_has_keys_reflects_the_pool() {
		$this->assertFalse( WPNC_AI_Keys::has_keys( 'openai' ) );

		$this->pool( array( 'a' => 'k1' ) );
		$this->assertTrue( WPNC_AI_Keys::has_keys( 'openai' ) );
		$this->assertFalse( WPNC_AI_Keys::has_keys( 'groq' ), 'Providers must not share a pool.' );
	}

	public function test_status_masks_the_key_and_reports_resting() {
		$this->pool( array( 'a' => 'sk-proj-abcdefghijklmnop1234' ) );
		$this->state( array( 'resting' => array( 'a' => self::NOW + 600 ), 'reasons' => array( 'a' => 'no credit' ) ) );

		$status = WPNC_AI_Keys::status( 'openai' );

        $this->assertArrayHasKey( 'a', $status );
		$this->assertStringNotContainsString( 'abcdefghij', $status['a']['masked'] );
		$this->assertSame( 'no credit', $status['a']['reason'] );
	}

	public function test_new_ids_are_distinct() {
		$this->assertNotSame( WPNC_AI_Keys::new_id(), WPNC_AI_Keys::new_id() );
	}

	public function test_a_corrupt_option_does_not_take_the_pool_down() {
		update_option( WPNC_AI_Keys::OPTION, 'not an array' );
		$this->assertSame( array(), WPNC_AI_Keys::for_provider( 'openai' ) );

		update_option( WPNC_AI_Keys::STATE, 'not an array' );
		$this->assertSame( array(), WPNC_AI_Keys::state() );
	}
}
