<?php
/**
 * How long a run may take, and how long a fetched feed may be reused.
 *
 * The cache lifetime is the one that mattered most: WordPress stores every
 * feed for twelve hours by default, so an update interval of fifteen minutes
 * re-read the same copy all day and new stories arrived twice a day at best.
 */

use PHPUnit\Framework\TestCase;

class FetchPacingTest extends TestCase {

	/**
	 * @var string
	 */
	private $original_limit;

	protected function setUp(): void {
		WPNC_Test_Options::reset();
		$this->original_limit = (string) ini_get( 'max_execution_time' );
	}

	protected function tearDown(): void {
		ini_set( 'max_execution_time', $this->original_limit );
	}

	public function test_a_feed_is_not_reused_for_longer_than_the_update_interval() {
		$reader = new WPNC_Feed_Reader();

		foreach ( array( '15min', 'hourly', '3hours', 'twicedaily', 'daily' ) as $interval ) {
			update_option( 'wpnc_interval', $interval );

			$lifetime = $reader->cache_lifetime();
			$schedule = WPNC_Settings::interval_seconds();

			$this->assertLessThan(
				$schedule,
				$lifetime,
				$interval . ': a copy kept as long as the interval means a run can see nothing new'
			);
			$this->assertLessThanOrEqual(
				12 * HOUR_IN_SECONDS,
				$lifetime,
				$interval . ': must be well under the twelve hours WordPress defaults to'
			);
		}
	}

	public function test_a_short_interval_still_leaves_room_to_reuse_a_feed() {
		// Not zero either: a manual run started moments after a scheduled one
		// should not re-download every source.
		update_option( 'wpnc_interval', '15min' );

		$lifetime = ( new WPNC_Feed_Reader() )->cache_lifetime();

		$this->assertGreaterThanOrEqual( 60, $lifetime );
		$this->assertSame( 450, $lifetime, 'half of fifteen minutes' );
	}

	public function test_a_long_interval_does_not_serve_a_stale_copy_to_fetch_now() {
		update_option( 'wpnc_interval', 'daily' );

		$this->assertSame(
			30 * MINUTE_IN_SECONDS,
			( new WPNC_Feed_Reader() )->cache_lifetime(),
			'half a day would make Fetch Now meaningless'
		);
	}

	public function test_interval_seconds_reads_the_real_schedule() {
		update_option( 'wpnc_interval', '15min' );
		$this->assertSame( 900, WPNC_Settings::interval_seconds() );

		update_option( 'wpnc_interval', '3hours' );
		$this->assertSame( 10800, WPNC_Settings::interval_seconds() );

		// An unrecognised value falls back rather than returning nonsense.
		update_option( 'wpnc_interval', 'every-picosecond' );
		$this->assertSame( 3600, WPNC_Settings::interval_seconds() );
	}

	public function test_the_time_budget_stays_inside_the_limit_in_force() {
		ini_set( 'max_execution_time', '30' );

		$budget = WPNC_Settings::time_budget();

		$this->assertLessThan( 30, $budget, 'work must stop before the request is killed' );
		$this->assertSame( 20, $budget );
	}

	public function test_a_very_short_limit_still_allows_some_work() {
		// Below the reserve the arithmetic goes negative; the loop would then
		// stop before doing anything at all and no source would ever run.
		ini_set( 'max_execution_time', '5' );

		$this->assertGreaterThan( 0, WPNC_Settings::time_budget() );
		$this->assertSame( 15, WPNC_Settings::time_budget() );
	}

	public function test_no_limit_is_still_bounded() {
		// Cron and the CLI often report 0. Unbounded work holds the fetch
		// lock and starves every source the run has not reached.
		ini_set( 'max_execution_time', '0' );

		$this->assertSame( 300, WPNC_Settings::time_budget() );
	}
}
