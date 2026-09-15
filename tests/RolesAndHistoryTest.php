<?php
/**
 * Who may do what, and what gets written down about it.
 */

use PHPUnit\Framework\TestCase;

class RolesAndHistoryTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	private function tabs() {
		return array(
			'dashboard'  => 'Dashboard',
			'moderation' => 'Moderation Queue',
			'settings'   => 'Settings',
			'logs'       => 'Logs & Tools',
		);
	}

	public function test_an_administrator_sees_every_screen() {
		$this->assertSame( $this->tabs(), WPNC_Roles::visible_tabs( $this->tabs(), true ) );
	}

	public function test_a_moderator_sees_the_queue_but_not_settings_or_tools() {
		// Settings hold API keys and bot tokens; Logs & Tools can clear locks
		// and pause sources. Neither is moderation.
		$this->assertSame(
			array(
				'dashboard'  => 'Dashboard',
				'moderation' => 'Moderation Queue',
			),
			WPNC_Roles::visible_tabs( $this->tabs(), false )
		);
	}

	public function test_moderating_is_a_capability_of_its_own() {
		$this->assertSame( 'wpnc_moderate_news', WPNC_Roles::MODERATE );
		$this->assertNotSame( 'manage_options', WPNC_Roles::MODERATE );
	}

	public function test_an_import_names_its_source() {
		$this->assertSame(
			sprintf( wpnc__( 'Imported from %s', 'از %s وارد شد' ), 'IRNA' ),
			WPNC_History::describe( 'imported', array( 'source' => 'IRNA' ) )
		);
		$this->assertSame( wpnc__( 'Imported', 'وارد شد' ), WPNC_History::describe( 'imported', array() ) );
	}

	public function test_an_approval_names_where_it_went() {
		$site     = WPNC_Channels::get( 'site' );
		$telegram = WPNC_Channels::get( 'telegram' );

		$this->assertSame(
			sprintf( wpnc__( 'Approved and sent to %s', 'تأیید و به %s ارسال شد' ), $site['label'] . wpnc__( ', ', '، ' ) . $telegram['label'] ),
			WPNC_History::describe( 'approved', array( 'channels' => array( 'site', 'telegram' ) ) )
		);
	}

	public function test_a_scheduled_approval_says_when_rather_than_where() {
		$this->assertSame(
			sprintf( wpnc__( 'Approved and scheduled for %s', 'تأیید و برای %s زمان‌بندی شد' ), '20 Sep 14:30' ),
			WPNC_History::describe( 'approved', array( 'channels' => array( 'site' ), 'scheduled' => '20 Sep 14:30' ) )
		);
	}

	public function test_an_assistant_action_is_named() {
		$actions = WPNC_AI_Rewriter::actions();

		$this->assertSame(
			sprintf( wpnc__( 'Assistant: %s', 'دستیار: %s' ), $actions['headline'] ),
			WPNC_History::describe( 'ai', array( 'ai_action' => 'headline' ) )
		);
	}

	public function test_a_failure_carries_its_reason() {
		$this->assertSame(
			sprintf( wpnc__( 'Publishing failed: %s', 'انتشار ناموفق بود: %s' ), 'Image server refused' ),
			WPNC_History::describe( 'failed', array( 'error' => 'Image server refused' ) )
		);
	}

	public function test_an_unknown_action_is_shown_rather_than_hidden() {
		$this->assertSame( 'something_new', WPNC_History::describe( 'something_new', array() ) );
	}

	public function test_stored_detail_is_short_flat_text() {
		$clean = WPNC_History::clean_detail(
			array(
				'source'   => '<b>IRNA</b>',
				'channels' => array( 'site', array( 'nested' ), 'telegram' ),
				'error'    => str_repeat( 'x', 1000 ),
				'object'   => new stdClass(),
				'Bad Key!' => 'kept under a clean key',
			)
		);

		$this->assertSame( 'IRNA', $clean['source'] );
		$this->assertSame( array( 'site', 'telegram' ), $clean['channels'] );
		$this->assertSame( 300, mb_strlen( $clean['error'] ) );
		$this->assertArrayNotHasKey( 'object', $clean );
		$this->assertArrayHasKey( 'badkey', $clean );
	}
}
