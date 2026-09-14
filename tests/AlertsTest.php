<?php
/**
 * When the administrator is told something.
 *
 * An alert that repeats every fetch run gets muted, and a muted alert channel
 * is worse than none: it teaches the one person who needs it to ignore it.
 */

use PHPUnit\Framework\TestCase;

class AlertsTest extends TestCase {

	const NOW = 1800000000;

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	public function test_the_first_alert_of_its_kind_always_goes() {
		$this->assertTrue( WPNC_Alerts::due( array(), 'source_down:key:a', self::NOW ) );
	}

	public function test_the_same_alert_is_not_repeated_inside_the_cooldown() {
		$state = array( 'source_down:key:a' => self::NOW - HOUR_IN_SECONDS );

		$this->assertFalse( WPNC_Alerts::due( $state, 'source_down:key:a', self::NOW ) );
	}

	public function test_it_goes_again_once_the_cooldown_has_passed() {
		$state = array( 'source_down:key:a' => self::NOW - WPNC_Alerts::COOLDOWN );

		$this->assertTrue( WPNC_Alerts::due( $state, 'source_down:key:a', self::NOW ) );
	}

	public function test_one_alert_does_not_silence_another() {
		$state = array( 'source_down:key:a' => self::NOW );

		$this->assertTrue( WPNC_Alerts::due( $state, 'source_down:key:b', self::NOW ), 'a second source failing is news' );
	}

	public function test_alerts_are_off_until_a_service_and_a_chat_are_both_chosen() {
		$this->assertNull( WPNC_Alerts::destination() );

		update_option( 'wpnc_alert_channel', 'telegram' );
		$this->assertNull( WPNC_Alerts::destination(), 'a service with nowhere to send is still off' );

		update_option( 'wpnc_alert_chat_id', '123456' );
		$this->assertSame(
			array(
				'slug'    => 'telegram',
				'chat_id' => '123456',
			),
			WPNC_Alerts::destination()
		);
	}

	public function test_the_site_is_not_somewhere_an_alert_can_go() {
		update_option( 'wpnc_alert_channel', 'site' );
		update_option( 'wpnc_alert_chat_id', '123456' );

		$this->assertNull( WPNC_Alerts::destination() );
	}

	public function test_alerts_cannot_be_pointed_at_the_readers_channel() {
		// Publishing "a source is down" to an audience is the one mistake this
		// setting exists to prevent.
		update_option( 'wpnc_telegram_chat_id', '@mynewschannel' );
		update_option( 'wpnc_alert_chat_id', '99999' );

		$this->assertSame( '99999', WPNC_Settings::sanitize_alert_chat_id( '@mynewschannel' ) );
		$this->assertContains( 'wpnc_alert_public_chat', array_column( WPNC_Test_Options::$notices, 'code' ) );
	}

	public function test_a_private_chat_is_accepted_as_the_alert_destination() {
		update_option( 'wpnc_telegram_chat_id', '@mynewschannel' );

		$this->assertSame( '12345678', WPNC_Settings::sanitize_alert_chat_id( '12345678' ) );
	}

	public function test_a_pool_is_exhausted_only_when_every_key_is_resting() {
		update_option( WPNC_AI_Keys::OPTION, array( 'openai' => array( 'a' => 'k1', 'b' => 'k2' ) ) );

		update_option( WPNC_AI_Keys::STATE, array( 'openai' => array( 'resting' => array( 'a' => self::NOW + 600 ) ) ) );
		$this->assertFalse( WPNC_AI_Keys::all_resting( 'openai', self::NOW ), 'one key is still usable' );

		update_option( WPNC_AI_Keys::STATE, array( 'openai' => array( 'resting' => array( 'a' => self::NOW + 600, 'b' => self::NOW + 600 ) ) ) );
		$this->assertTrue( WPNC_AI_Keys::all_resting( 'openai', self::NOW ) );

		update_option( WPNC_AI_Keys::STATE, array( 'openai' => array( 'resting' => array( 'a' => self::NOW - 1, 'b' => self::NOW + 600 ) ) ) );
		$this->assertFalse( WPNC_AI_Keys::all_resting( 'openai', self::NOW ), 'a rest that has ended counts as ready' );
	}

	public function test_an_empty_pool_has_not_run_out_of_anything() {
		$this->assertFalse( WPNC_AI_Keys::all_resting( 'openai', self::NOW ) );
	}

	public function test_resting_the_last_key_announces_it() {
		update_option( WPNC_AI_Keys::OPTION, array( 'openai' => array( 'a' => 'k1' ) ) );

		WPNC_AI_Keys::mark_resting( 'openai', 'a', 'quota' );

		$actions = isset( WPNC_Test_Options::$values['__actions'] ) ? WPNC_Test_Options::$values['__actions'] : array();

		$this->assertContains( array( 'wpnc_ai_pool_exhausted', array( 'openai' ) ), $actions );
	}
}
