<?php
/**
 * The pure parts of the assistant: what an action produces, how a model's
 * answer is read back, and whether a translation would do anything at all.
 */

use PHPUnit\Framework\TestCase;

class AiRewriterTest extends TestCase {

	/* ----------------------------------------------------------------
	   Script detection
	   ---------------------------------------------------------------- */

	public function test_persian_and_arabic_are_not_treated_as_one_language() {
		// They share a script. Collapsing them would refuse exactly the
		// translation this plugin is most likely to be asked for.
		$persian = 'دولت اعلام کرد که این طرح از هفته آینده اجرا می‌شود و همه شهروندان می‌توانند از آن استفاده کنند';
		$arabic  = 'أعلنت الحكومة أن هذه الخطة ستنفذ الأسبوع المقبل ويمكن لجميع المواطنين الاستفادة منها';

		$this->assertSame( 'persian', WPNC_AI_Rewriter::dominant_script( $persian ) );
		$this->assertSame( 'arabic', WPNC_AI_Rewriter::dominant_script( $arabic ) );
	}

	public function test_latin_text_is_recognised() {
		$this->assertSame(
			'latin',
			WPNC_AI_Rewriter::dominant_script( 'The government said the plan would take effect next week.' )
		);
	}

	public function test_markup_does_not_change_the_verdict() {
		$this->assertSame(
			'persian',
			WPNC_AI_Rewriter::dominant_script( '<p class="lead">دولت اعلام کرد که این طرح از هفته آینده اجرا می‌شود</p>' )
		);
	}

	public function test_too_little_text_is_admitted_rather_than_guessed() {
		$this->assertSame( '', WPNC_AI_Rewriter::dominant_script( 'خبر' ) );
		$this->assertSame( '', WPNC_AI_Rewriter::dominant_script( '' ) );
		$this->assertSame( '', WPNC_AI_Rewriter::dominant_script( '12345 -- ???' ) );
	}

	/* ----------------------------------------------------------------
	   Language names
	   ---------------------------------------------------------------- */

	public function test_the_target_language_is_read_in_either_language() {
		foreach ( array( 'Persian', 'farsi', 'FA', 'فارسی' ) as $name ) {
			$this->assertSame( 'persian', WPNC_AI_Rewriter::language_script( $name ), $name );
		}

		$this->assertSame( 'latin', WPNC_AI_Rewriter::language_script( 'English' ) );
		$this->assertSame( 'arabic', WPNC_AI_Rewriter::language_script( 'عربی' ) );
		$this->assertSame( '', WPNC_AI_Rewriter::language_script( 'Klingon' ) );
		$this->assertSame( '', WPNC_AI_Rewriter::language_script( '' ) );
	}

	/* ----------------------------------------------------------------
	   The no-op translation
	   ---------------------------------------------------------------- */

	public function test_translating_persian_into_persian_is_recognised_as_pointless() {
		$this->assertTrue(
			WPNC_AI_Rewriter::already_in_language(
				'دولت اعلام کرد که این طرح از هفته آینده اجرا می‌شود و همه می‌توانند استفاده کنند',
				'Persian'
			)
		);
	}

	public function test_a_real_translation_is_not_refused() {
		$arabic  = 'أعلنت الحكومة أن هذه الخطة ستنفذ الأسبوع المقبل ويمكن لجميع المواطنين الاستفادة منها';
		$english = 'The government said the plan would take effect next week for every citizen.';

		$this->assertFalse( WPNC_AI_Rewriter::already_in_language( $arabic, 'Persian' ) );
		$this->assertFalse( WPNC_AI_Rewriter::already_in_language( $english, 'Persian' ) );
		$this->assertFalse(
			WPNC_AI_Rewriter::already_in_language(
				'دولت اعلام کرد که این طرح از هفته آینده اجرا می‌شود و همه می‌توانند استفاده کنند',
				'English'
			)
		);
	}

	public function test_an_unknown_language_never_blocks_the_request() {
		// Refusing wrongly costs the editor the feature; translating
		// unnecessarily costs one request. Prefer the cheaper mistake.
		$this->assertFalse( WPNC_AI_Rewriter::already_in_language( 'hello there friend', 'Klingon' ) );
		$this->assertFalse( WPNC_AI_Rewriter::already_in_language( 'short', 'Persian' ) );
	}

	/* ----------------------------------------------------------------
	   What each action produces
	   ---------------------------------------------------------------- */

	public function test_headlines_and_tags_are_suggestions_not_a_new_article() {
		$this->assertSame( 'titles', WPNC_AI_Rewriter::action_kind( 'headline' ) );
		$this->assertSame( 'tags', WPNC_AI_Rewriter::action_kind( 'tags' ) );

		foreach ( array( 'rewrite', 'expand', 'shorten', 'translate', 'custom', 'nonsense' ) as $action ) {
			$this->assertSame( 'body', WPNC_AI_Rewriter::action_kind( $action ), $action );
		}
	}

	/* ----------------------------------------------------------------
	   Reading the model's answer
	   ---------------------------------------------------------------- */

	public function test_headlines_are_read_out_of_a_plain_list() {
		$this->assertSame(
			array( 'First headline', 'Second headline', 'Third headline' ),
			WPNC_AI_Rewriter::parse_titles( "First headline\nSecond headline\nThird headline" )
		);
	}

	public function test_headlines_survive_the_bullets_and_numbers_models_add_anyway() {
		$this->assertSame(
			array( 'First headline', 'Second headline', 'Third headline' ),
			WPNC_AI_Rewriter::parse_titles( "1. First headline\n2) Second headline\n- Third headline" )
		);

		$this->assertSame(
			array( 'First headline', 'Second headline' ),
			WPNC_AI_Rewriter::parse_titles( '<ul><li>First headline</li><li>Second headline</li></ul>' )
		);

		$this->assertSame(
			array( 'A quoted headline' ),
			WPNC_AI_Rewriter::parse_titles( '"A quoted headline"' )
		);
	}

	public function test_no_more_than_five_headlines_are_offered() {
		$many = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$many[] = 'Headline number ' . $i;
		}

		$this->assertCount( 5, WPNC_AI_Rewriter::parse_titles( implode( "\n", $many ) ) );
	}

	public function test_tags_are_split_on_commas_including_the_persian_one() {
		$this->assertSame(
			array( 'اقتصاد', 'بازار', 'ارز' ),
			WPNC_AI_Rewriter::parse_tags( 'اقتصاد، بازار، ارز' )
		);

		$this->assertSame(
			array( 'economy', 'markets', 'currency' ),
			WPNC_AI_Rewriter::parse_tags( 'economy, markets, currency' )
		);
	}

	public function test_a_tag_list_returned_as_bullets_is_still_read() {
		$this->assertSame(
			array( 'economy', 'markets' ),
			WPNC_AI_Rewriter::parse_tags( "- economy\n- markets" )
		);
	}

	public function test_a_sentence_of_commentary_is_not_stored_as_a_tag() {
		$tags = WPNC_AI_Rewriter::parse_tags(
			'economy, markets, Here are the tags I extracted from the article for you'
		);

		$this->assertSame( array( 'economy', 'markets' ), $tags );
	}

	public function test_duplicate_tags_are_offered_once() {
		$this->assertSame(
			array( 'economy', 'markets' ),
			WPNC_AI_Rewriter::parse_tags( 'economy, markets, economy' )
		);
	}

	public function test_no_more_than_eight_tags_are_offered() {
		$this->assertCount(
			8,
			WPNC_AI_Rewriter::parse_tags( 'a1, a2, a3, a4, a5, a6, a7, a8, a9, a10' )
		);
	}

	public function test_an_empty_answer_yields_nothing_rather_than_a_blank_entry() {
		$this->assertSame( array(), WPNC_AI_Rewriter::parse_titles( "\n\n   \n" ) );
		$this->assertSame( array(), WPNC_AI_Rewriter::parse_tags( ' , , ' ) );
	}

	/* ----------------------------------------------------------------
	   Structure the assistant is allowed to produce
	   ---------------------------------------------------------------- */

	public function test_structural_tags_survive_the_filter() {
		// "Put these in a table" was impossible to satisfy: the model's
		// answer came back correct and wp_kses then flattened it to running
		// text, so the instruction looked like it had been ignored.
		$allowed = WPNC_AI_Rewriter::allowed_html();

		foreach ( array( 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'caption', 'pre', 'code', 'dl', 'dt', 'dd' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $allowed, $tag . ' would be stripped from the answer' );
		}
	}

	public function test_table_cells_may_span() {
		$allowed = WPNC_AI_Rewriter::allowed_html();

		$this->assertArrayHasKey( 'colspan', $allowed['td'] );
		$this->assertArrayHasKey( 'rowspan', $allowed['td'] );
		$this->assertArrayHasKey( 'scope', $allowed['th'] );
	}

	public function test_nothing_that_executes_is_allowed_in() {
		$allowed = WPNC_AI_Rewriter::allowed_html();

		foreach ( array( 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input' ) as $tag ) {
			$this->assertArrayNotHasKey( $tag, $allowed, $tag . ' must never come back from a model' );
		}

		// Nor an event handler smuggled onto something that is allowed.
		foreach ( $allowed as $tag => $attrs ) {
			foreach ( array_keys( (array) $attrs ) as $attr ) {
				$this->assertStringStartsNotWith( 'on', $attr, $tag . ' allows the event handler ' . $attr );
			}
		}
	}

	public function test_add_structure_is_offered_as_an_action() {
		$this->assertArrayHasKey( 'format', WPNC_AI_Rewriter::actions() );
		$this->assertSame( 'body', WPNC_AI_Rewriter::action_kind( 'format' ), 'it rewrites the article, so it replaces the body' );
	}
}
