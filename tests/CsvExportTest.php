<?php
/**
 * CSV cell escaping.
 *
 * Feed titles are written by other people's servers and land in a file an
 * admin opens in Excel, so a title starting with = is a formula unless it is
 * defused first.
 */

use PHPUnit\Framework\TestCase;

class CsvExportTest extends TestCase {

	/**
	 * @dataProvider dangerous_cells
	 */
	public function test_formula_triggers_are_prefixed( $value ) {
		$this->assertSame(
			"'" . $value,
			WPNC_Queue_Repository::csv_cell( $value ),
			'A spreadsheet would evaluate this cell.'
		);
	}

	public function dangerous_cells() {
		return array(
			'equals'      => array( '=1+1' ),
			'plus'        => array( '+1' ),
			'minus'       => array( '-1' ),
			'at'          => array( '@SUM(A1)' ),
			'tab'         => array( "\tleading tab" ),
			'return'      => array( "\rleading return" ),
			'cmd payload' => array( '=cmd|\' /c calc\'!A0' ),
			'hyperlink'   => array( '=HYPERLINK("https://evil.example","click")' ),
		);
	}

	/**
	 * @dataProvider ordinary_cells
	 */
	public function test_ordinary_values_are_untouched( $value ) {
		$this->assertSame( $value, WPNC_Queue_Repository::csv_cell( $value ) );
	}

	public function ordinary_cells() {
		return array(
			'plain title'  => array( 'Market report for March' ),
			'persian'      => array( 'گزارش بازار' ),
			'url'          => array( 'https://example.com/a-story' ),
			'quotes'       => array( 'He said "no"' ),
			'comma'        => array( 'one, two, three' ),
			'inner equals' => array( 'Revenue = up' ),
			'inner minus'  => array( 'well-known' ),
		);
	}

	public function test_empty_string_stays_empty() {
		$this->assertSame( '', WPNC_Queue_Repository::csv_cell( '' ) );
	}

	public function test_numbers_are_stringified_without_a_prefix() {
		$this->assertSame( '42', WPNC_Queue_Repository::csv_cell( 42 ) );
		$this->assertSame( '0', WPNC_Queue_Repository::csv_cell( 0 ) );
	}

	public function test_negative_numbers_are_prefixed_because_they_cannot_be_told_apart() {
		// A deliberate trade-off: -1 and -1+cmd() both start with a minus, so
		// the export marks both as text. The id and post_id columns are
		// always non-negative, so nothing numeric is affected in practice.
		$this->assertSame( "'-5", WPNC_Queue_Repository::csv_cell( -5 ) );
	}
}
