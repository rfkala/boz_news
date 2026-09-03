<?php
/**
 * Publication pacing.
 *
 * The slot arithmetic decides when every approved item appears on the site,
 * and a wrong answer here either dumps everything at once or pushes posts
 * into the past where nobody sees them.
 */

use PHPUnit\Framework\TestCase;

class SchedulerTest extends TestCase {

	const NOW = 1800000000;

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	public function test_nothing_queued_means_publish_now() {
		$this->assertSame(
			self::NOW,
			WPNC_Scheduler::calculate_slot( self::NOW, 0, 900 ),
			'The first approved item should not be held back.'
		);
	}

	public function test_a_slot_in_the_past_also_means_publish_now() {
		// Everything previously scheduled has already gone out.
		$this->assertSame(
			self::NOW,
			WPNC_Scheduler::calculate_slot( self::NOW, self::NOW - 5000, 900 )
		);
	}

	public function test_something_placed_at_this_instant_still_pushes_the_next_one() {
		// This expectation used to be self::NOW, which is precisely the bug:
		// two posts would have shared one instant.
		$this->assertSame(
			self::NOW + 900,
			WPNC_Scheduler::calculate_slot( self::NOW, self::NOW, 900 )
		);
	}

	public function test_a_pending_slot_pushes_the_next_one_an_interval_later() {
		$this->assertSame(
			self::NOW + 1800,
			WPNC_Scheduler::calculate_slot( self::NOW, self::NOW + 900, 900 )
		);
	}

	public function test_approving_a_batch_spaces_every_item() {
		// Walk the same loop the publisher walks, feeding each result back in.
		$interval = 600;
		$last     = 0;
		$slots    = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$last    = WPNC_Scheduler::calculate_slot( self::NOW, $last, $interval );
			$slots[] = $last;
		}

		$this->assertSame(
			array(
				self::NOW,
				self::NOW + $interval,
				self::NOW + $interval * 2,
				self::NOW + $interval * 3,
				self::NOW + $interval * 4,
			),
			$slots,
			'Five approvals should occupy five evenly spaced slots.'
		);
	}

	public function test_no_slot_is_ever_in_the_past() {
		foreach ( array( 0, 1, self::NOW - 100000, self::NOW - 1 ) as $last ) {
			$this->assertGreaterThanOrEqual(
				self::NOW,
				WPNC_Scheduler::calculate_slot( self::NOW, $last, 900 )
			);
		}
	}

	public function test_a_zero_interval_cannot_make_every_item_share_a_slot() {
		// The interval is floored at one second, so a corrupt option degrades
		// to "as fast as possible" rather than stacking items on one instant.
		$this->assertSame(
			self::NOW + 2,
			WPNC_Scheduler::calculate_slot( self::NOW, self::NOW + 1, 0 ),
			'A zero interval must still advance the slot.'
		);
	}

	public function test_interval_minutes_is_clamped() {
		update_option( 'wpnc_stagger_minutes', 0 );
		$this->assertSame( 1, WPNC_Scheduler::interval_minutes() );

		update_option( 'wpnc_stagger_minutes', 99999 );
		$this->assertSame( 1440, WPNC_Scheduler::interval_minutes() );

		update_option( 'wpnc_stagger_minutes', 30 );
		$this->assertSame( 30, WPNC_Scheduler::interval_minutes() );
	}

	public function test_interval_seconds_follows_minutes() {
		update_option( 'wpnc_stagger_minutes', 15 );
		$this->assertSame( 900, WPNC_Scheduler::interval_seconds() );
	}

	public function test_pacing_is_off_unless_explicitly_enabled() {
		$this->assertFalse( WPNC_Scheduler::is_enabled() );

		update_option( 'wpnc_stagger_enabled', 1 );
		$this->assertTrue( WPNC_Scheduler::is_enabled() );

		update_option( 'wpnc_stagger_enabled', 0 );
		$this->assertFalse( WPNC_Scheduler::is_enabled() );
	}
}
