<?php
/**
 * Reading a set of probes.
 *
 * The plugin tells an editor that something on their server is cutting
 * requests short. That is a strong claim about somebody else's hosting, so
 * the reasoning behind it is pinned here - and this is the only way to test
 * it, since a test cannot make a real host impose a real cap.
 */

use PHPUnit\Framework\TestCase;

class DiagnosticsTest extends TestCase {

	private function probe( $key, $ok, $timed_out = false, $elapsed = 0.3, $host = 'example.com' ) {
		return array(
			'key'       => $key,
			'label'     => $key,
			'host'      => $host,
			'ok'        => $ok,
			'status'    => $ok ? 200 : 0,
			'timed_out' => $timed_out,
			'error'     => $ok ? '' : 'cURL error 28: Operation timed out',
			'elapsed'   => $elapsed,
		);
	}

	public function test_several_addresses_cut_short_of_the_limit_is_the_server() {
		// The case this exists for: 30 seconds allowed, everything dies at 12.
		$probes = array(
			$this->probe( 'ai', false, true, 12.0 ),
			$this->probe( 'control', false, true, 12.1 ),
			$this->probe( 'self', true ),
		);

		$verdict = WPNC_Diagnostics::verdict( $probes, 30 );

		$this->assertSame( 'capped', $verdict['code'] );
		$this->assertStringContainsString( '12', $verdict['message'] );
	}

	public function test_one_address_failing_while_a_neutral_one_answers_is_the_address() {
		$probes = array(
			$this->probe( 'ai', false, true, 12.0, 'api.example-ai.com' ),
			$this->probe( 'control', true ),
			$this->probe( 'self', true ),
		);

		$verdict = WPNC_Diagnostics::verdict( $probes, 30 );

		$this->assertSame( 'endpoint_blocked', $verdict['code'] );
		$this->assertStringContainsString( 'api.example-ai.com', $verdict['message'] );
	}

	public function test_a_slow_answer_that_used_its_whole_allowance_is_not_called_a_cap() {
		// Timed out, but only after the time it was given. Nothing cut it
		// short; it really is that slow, and saying otherwise would send
		// someone to argue with their host about the wrong thing.
		$probes = array(
			$this->probe( 'ai', false, true, 29.6 ),
			$this->probe( 'control', false, true, 29.8 ),
			$this->probe( 'self', true ),
		);

		$this->assertNotSame( 'capped', WPNC_Diagnostics::verdict( $probes, 30 )['code'] );
	}

	public function test_a_single_cut_off_address_is_not_enough_to_blame_the_server() {
		$probes = array(
			$this->probe( 'ai', false, true, 12.0 ),
			$this->probe( 'control', true ),
		);

		$this->assertNotSame( 'capped', WPNC_Diagnostics::verdict( $probes, 30 )['code'] );
	}

	public function test_nothing_answering_at_all_points_at_the_host() {
		$probes = array(
			$this->probe( 'ai', false, false ),
			$this->probe( 'control', false, false ),
			$this->probe( 'self', false, false ),
		);

		$this->assertSame( 'no_outbound', WPNC_Diagnostics::verdict( $probes, 30 )['code'] );
	}

	public function test_everything_answering_sends_the_reader_back_to_generation_time() {
		$probes = array(
			$this->probe( 'ai', true ),
			$this->probe( 'control', true ),
			$this->probe( 'self', true ),
		);

		$verdict = WPNC_Diagnostics::verdict( $probes, 30 );

		$this->assertSame( 'healthy', $verdict['code'] );
	}

	public function test_no_probes_says_so_instead_of_guessing() {
		$this->assertSame( 'unknown', WPNC_Diagnostics::verdict( array(), 30 )['code'] );
	}
}
