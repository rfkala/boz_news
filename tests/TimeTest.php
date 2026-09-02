<?php
/**
 * Every datetime this plugin stores is UTC. Before 1.3.0 half of them were
 * site-local while retention thresholds were UTC, so cleanup deleted rows off
 * by the site's GMT offset. These tests pin the invariant.
 */

use PHPUnit\Framework\TestCase;

class TimeTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	public function test_now_is_utc_and_mysql_shaped() {
		$now = WPNC_Time::now();

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now );
		$this->assertEqualsWithDelta(
			time(),
			strtotime( $now . ' UTC' ),
			2,
			'now() must be UTC regardless of the PHP default timezone.'
		);
	}

	public function test_now_ignores_the_site_gmt_offset() {
		// The bug this replaces: current_time('mysql') returned local time,
		// which was then compared against a UTC threshold.
		update_option( 'gmt_offset', 3.5 );

		$this->assertEqualsWithDelta( time(), strtotime( WPNC_Time::now() . ' UTC' ), 2 );
	}

	public function test_to_utc_normalises_an_offset_bearing_string() {
		$this->assertSame( '2026-03-01 09:30:00', WPNC_Time::to_utc( '2026-03-01T13:00:00+03:30' ) );
	}

	public function test_to_utc_passes_through_a_utc_string() {
		$this->assertSame( '2026-03-01 09:30:00', WPNC_Time::to_utc( '2026-03-01 09:30:00 UTC' ) );
	}

	public function test_to_utc_falls_back_to_now_for_unusable_input() {
		foreach ( array( '', '   ', 'not a date' ) as $bad ) {
			$this->assertEqualsWithDelta(
				time(),
				strtotime( WPNC_Time::to_utc( $bad ) . ' UTC' ),
				2,
				'A malformed feed date must not make a row uninsertable.'
			);
		}
	}

	public function test_days_ago_is_exact_and_clamps_below_one() {
		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ),
			WPNC_Time::days_ago( 14 )
		);

		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			WPNC_Time::days_ago( 0 ),
			'Zero would mean "delete everything processed today".'
		);
	}

	public function test_offset_seconds_reads_the_site_offset() {
		update_option( 'gmt_offset', 3.5 );
		$this->assertSame( 12600, WPNC_Time::offset_seconds() );

		update_option( 'gmt_offset', -5 );
		$this->assertSame( -18000, WPNC_Time::offset_seconds() );

		update_option( 'gmt_offset', 0 );
		$this->assertSame( 0, WPNC_Time::offset_seconds() );
	}

	public function test_for_display_shifts_stored_utc_into_the_site_timezone() {
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		// wp_date is stubbed as gmdate, so this asserts the UTC reading of the
		// stored value rather than the site rendering itself.
		$this->assertSame( '2026-03-01 09:30', WPNC_Time::for_display( '2026-03-01 09:30:00' ) );
	}

	public function test_for_display_returns_empty_for_the_zero_datetime() {
		$this->assertSame( '', WPNC_Time::for_display( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', WPNC_Time::for_display( '' ) );
	}
}
