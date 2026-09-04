<?php
/**
 * Delivery destinations.
 *
 * The question these answer is which buttons an editor is allowed to see. A
 * destination that is offered and then fails costs a queue row: the item is
 * approved and gone before anyone learns the send did not happen.
 */

use PHPUnit\Framework\TestCase;

class ChannelsTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	private function configure( $slug, $token = 'bot-token-123', $chat = '-1001234567890' ) {
		$channel = WPNC_Channels::get( $slug );
		update_option( $channel['token'], $token );
		update_option( $channel['chat'], $chat );
	}

	/* ----------------------------------------------------------------
	   The registry
	   ---------------------------------------------------------------- */

	public function test_every_channel_declares_what_sending_needs() {
		foreach ( WPNC_Channels::all() as $slug => $channel ) {
			foreach ( array( 'label', 'kind', 'api', 'token', 'chat' ) as $field ) {
				$this->assertArrayHasKey( $field, $channel, $slug . ' is missing ' . $field );
			}

			$this->assertNotSame( '', $channel['label'], $slug . ' has no label' );

			if ( 'site' === $channel['kind'] ) {
				continue;
			}

			$this->assertStringStartsWith( 'https://', $channel['api'], $slug . ' must use TLS' );
			$this->assertNotSame( '', $channel['token'], $slug . ' has no token option' );
			$this->assertNotSame( '', $channel['chat'], $slug . ' has no chat option' );
		}
	}

	public function test_telegram_and_bale_are_separate_services() {
		$telegram = WPNC_Channels::get( 'telegram' );
		$bale     = WPNC_Channels::get( 'bale' );

		$this->assertNotSame( $telegram['api'], $bale['api'] );
		$this->assertNotSame( $telegram['token'], $bale['token'] );
		$this->assertNotSame( $telegram['chat'], $bale['chat'] );
	}

	/* ----------------------------------------------------------------
	   Readiness - what enables a button
	   ---------------------------------------------------------------- */

	public function test_the_site_needs_no_setting_up() {
		$this->assertTrue( WPNC_Channels::is_configured( 'site' ) );
		$this->assertTrue( WPNC_Channels::is_ready( 'site' ) );
	}

	public function test_half_a_credential_is_not_configured() {
		$channel = WPNC_Channels::get( 'telegram' );

		update_option( $channel['token'], 'bot-token-123' );
		$this->assertFalse( WPNC_Channels::is_configured( 'telegram' ) );

		update_option( $channel['token'], '' );
		update_option( $channel['chat'], '-100123' );
		$this->assertFalse( WPNC_Channels::is_configured( 'telegram' ) );
	}

	public function test_credentials_alone_do_not_enable_a_destination() {
		$this->configure( 'telegram' );

		$this->assertTrue( WPNC_Channels::is_configured( 'telegram' ) );
		$this->assertFalse( WPNC_Channels::is_verified( 'telegram' ) );
		$this->assertFalse( WPNC_Channels::is_ready( 'telegram' ) );
	}

	public function test_a_passing_test_enables_it() {
		$this->configure( 'telegram' );
		WPNC_Channels::mark_verified( 'telegram' );

		$this->assertTrue( WPNC_Channels::is_ready( 'telegram' ) );
	}

	public function test_editing_the_token_invalidates_the_test_that_passed() {
		// The whole point of storing a fingerprint. Without it a channel keeps
		// claiming to work on the strength of a test run against credentials
		// that no longer exist.
		$this->configure( 'telegram' );
		WPNC_Channels::mark_verified( 'telegram' );
		$this->assertTrue( WPNC_Channels::is_ready( 'telegram' ) );

		update_option( WPNC_Channels::get( 'telegram' )['token'], 'a-different-token' );

		$this->assertFalse( WPNC_Channels::is_verified( 'telegram' ) );
		$this->assertFalse( WPNC_Channels::is_ready( 'telegram' ) );
	}

	public function test_editing_the_chat_id_invalidates_it_too() {
		$this->configure( 'telegram' );
		WPNC_Channels::mark_verified( 'telegram' );

		update_option( WPNC_Channels::get( 'telegram' )['chat'], '@somewhere_else' );

		$this->assertFalse( WPNC_Channels::is_ready( 'telegram' ) );
	}

	public function test_verifying_one_channel_says_nothing_about_another() {
		$this->configure( 'telegram' );
		$this->configure( 'bale' );
		WPNC_Channels::mark_verified( 'telegram' );

		$this->assertTrue( WPNC_Channels::is_ready( 'telegram' ) );
		$this->assertFalse( WPNC_Channels::is_ready( 'bale' ) );
	}

	public function test_a_failed_test_can_take_the_destination_away_again() {
		$this->configure( 'telegram' );
		WPNC_Channels::mark_verified( 'telegram' );

		WPNC_Channels::clear_verified( 'telegram' );

		$this->assertFalse( WPNC_Channels::is_ready( 'telegram' ) );
	}

	/* ----------------------------------------------------------------
	   Turning a request into destinations
	   ---------------------------------------------------------------- */

	public function test_a_request_for_an_unready_destination_is_dropped() {
		$this->configure( 'telegram' );
		// Configured but never tested.

		$this->assertSame(
			array( 'site' ),
			WPNC_Channels::sanitize_selection( array( 'site', 'telegram' ) )
		);
	}

	public function test_all_means_everything_currently_ready() {
		$this->configure( 'telegram' );
		$this->configure( 'bale' );
		WPNC_Channels::mark_verified( 'bale' );

		$this->assertSame( array( 'site', 'bale' ), WPNC_Channels::sanitize_selection( 'all' ) );
	}

	public function test_an_invented_destination_is_ignored() {
		$this->assertSame(
			array( 'site' ),
			WPNC_Channels::sanitize_selection( array( 'site', 'carrier-pigeon' ) )
		);
	}

	public function test_selecting_nothing_usable_yields_nothing() {
		$this->configure( 'bale' );

		$this->assertSame( array(), WPNC_Channels::sanitize_selection( array( 'bale' ) ) );
	}

	public function test_the_result_is_ordered_by_the_registry_not_the_request() {
		$this->configure( 'bale' );
		WPNC_Channels::mark_verified( 'bale' );

		$this->assertSame(
			array( 'site', 'bale' ),
			WPNC_Channels::sanitize_selection( array( 'bale', 'site' ) )
		);
	}

	/* ----------------------------------------------------------------
	   The transport
	   ---------------------------------------------------------------- */

	public function test_each_service_is_called_at_its_own_address() {
		$this->assertSame(
			'https://api.telegram.org/bot123:ABC/sendMessage',
			WPNC_Messenger::api_url( 'telegram', 'sendMessage', '123:ABC' )
		);

		$this->assertSame(
			'https://tapi.bale.ai/bot123:ABC/sendMessage',
			WPNC_Messenger::api_url( 'bale', 'sendMessage', '123:ABC' )
		);
	}

	public function test_the_site_has_no_api_to_call() {
		$this->assertSame( '', WPNC_Messenger::api_url( 'site', 'sendMessage', 'x' ) );
		$this->assertSame( '', WPNC_Messenger::api_url( 'nonsense', 'sendMessage', 'x' ) );
	}

	public function test_a_refusal_carrying_http_200_is_still_a_refusal() {
		// Both services answer 200 with ok:false, so the status code alone
		// would report every rejection as a success.
		$this->assertSame(
			'chat not found',
			WPNC_Messenger::read_result( 200, array( 'ok' => false, 'description' => 'chat not found' ) )
		);

		$this->assertTrue( WPNC_Messenger::read_result( 200, array( 'ok' => true, 'result' => array() ) ) );
	}

	public function test_an_unreadable_body_falls_back_to_the_status() {
		$this->assertTrue( WPNC_Messenger::read_result( 200, null ) );
		$this->assertSame( 'HTTP 502', WPNC_Messenger::read_result( 502, null ) );
		$this->assertSame( 'HTTP 401', WPNC_Messenger::read_result( 401, array( 'ok' => false ) ) );
	}

	public function test_the_message_pairs_a_headline_with_a_link() {
		$this->assertSame(
			"A headline\n\nhttps://example.com/post",
			WPNC_Messenger::compose( 'A headline', 'https://example.com/post' )
		);

		$this->assertSame( 'A headline', WPNC_Messenger::compose( '<b>A headline</b>', '' ) );
		$this->assertSame( 'https://example.com/post', WPNC_Messenger::compose( '', 'https://example.com/post' ) );
	}

	public function test_a_bot_token_is_never_passed_through_to_an_error_message() {
		// The token lives in the URL, and WordPress puts the URL in transport
		// errors, so an unredacted message would print it on screen.
		$this->assertSame(
			'cURL error 28 for https://api.telegram.org/bot***/getMe',
			WPNC_Messenger::redact(
				'cURL error 28 for https://api.telegram.org/bot123:SECRET/getMe',
				'123:SECRET'
			)
		);

		$this->assertSame( 'no token here', WPNC_Messenger::redact( 'no token here', '' ) );
	}
}
