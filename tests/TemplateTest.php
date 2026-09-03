<?php
/**
 * Post body template.
 *
 * The template decides what every published post contains, so the two ways it
 * can quietly ruin a post - swallowing the article, or leaving the debris of
 * an empty placeholder - are pinned here.
 */

use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	private function values( $overrides = array() ) {
		return array_merge(
			array(
				'content'      => '<p>The body.</p>',
				'title'        => 'A headline',
				'excerpt'      => 'The body.',
				'source_name'  => 'Example News',
				'source_url'   => 'https://example.com/story',
				'source_label' => 'Source:',
				'source_link'  => '<a href="https://example.com/story">Example News</a>',
				'date'         => '2026-03-01',
				'image'        => '',
				'tags'         => 'one, two',
			),
			$overrides
		);
	}

	public function test_an_empty_template_falls_back_to_the_default() {
		$out = WPNC_Template::render( '', $this->values() );

		$this->assertStringContainsString( 'The body.', $out );
		$this->assertStringContainsString( 'Source:', $out );
	}

	public function test_whitespace_only_template_also_falls_back() {
		$this->assertSame(
			WPNC_Template::render( '', $this->values() ),
			WPNC_Template::render( "  \n\t ", $this->values() )
		);
	}

	public function test_placeholders_are_substituted() {
		$out = WPNC_Template::render( '{title} | {source_name} | {date}', $this->values() );

		$this->assertSame( 'A headline | Example News | 2026-03-01', $out );
	}

	public function test_the_same_placeholder_can_appear_twice() {
		$out = WPNC_Template::render( '{title} — {title}', $this->values() );

		$this->assertSame( 'A headline — A headline', $out );
	}

	public function test_an_unknown_placeholder_is_left_alone() {
		// A typo should be visible, and a stray brace in an article must not
		// swallow the text after it.
		$out = WPNC_Template::render( '{content} {nonsense}', $this->values() );

		$this->assertStringContainsString( '{nonsense}', $out );
	}

	public function test_an_empty_image_leaves_no_empty_paragraph() {
		$out = WPNC_Template::render(
			"<p>{image}</p>\n{content}",
			$this->values( array( 'image' => '' ) )
		);

		$this->assertStringNotContainsString( '<p></p>', $out );
		$this->assertStringContainsString( 'The body.', $out );
	}

	public function test_a_present_image_is_kept() {
		$out = WPNC_Template::render(
			'{image}{content}',
			$this->values( array( 'image' => '<figure><img src="https://example.com/a.jpg" alt="" /></figure>' ) )
		);

		$this->assertStringContainsString( '<img src="https://example.com/a.jpg"', $out );
	}

	public function test_tidy_unwraps_a_link_that_lost_its_href() {
		$this->assertSame(
			'Example News',
			WPNC_Template::tidy( '<a href="">Example News</a>' )
		);
	}

	public function test_tidy_collapses_runs_of_blank_lines() {
		$this->assertSame(
			"one\n\ntwo",
			WPNC_Template::tidy( "one\n\n\n\n\ntwo" )
		);
	}

	public function test_tidy_keeps_paragraphs_that_have_content() {
		$html = '<p>Real text.</p>';

		$this->assertSame( $html, WPNC_Template::tidy( $html ) );
	}

	public function test_used_lists_the_placeholders_a_template_references() {
		$this->assertSame(
			array( 'content', 'source_link' ),
			WPNC_Template::used( '{content} then {source_link} then {content}' )
		);
	}

	public function test_omits_content_catches_a_template_that_drops_the_article() {
		$this->assertTrue(
			WPNC_Template::omits_content( '<p>{source_link}</p>' ),
			'This template would publish posts with no story in them.'
		);

		$this->assertFalse( WPNC_Template::omits_content( '{content}' ) );
	}

	public function test_omits_content_is_false_for_an_empty_template() {
		// Empty means "use the default", and the default has the body.
		$this->assertFalse( WPNC_Template::omits_content( '' ) );
		$this->assertFalse( WPNC_Template::omits_content( '   ' ) );
	}

	public function test_excerpt_strips_markup() {
		$this->assertSame(
			'Hello there',
			WPNC_Template::excerpt( '<p>Hello <strong>there</strong></p>', 10 )
		);
	}

	public function test_excerpt_of_empty_content_is_empty() {
		$this->assertSame( '', WPNC_Template::excerpt( '' ) );
		$this->assertSame( '', WPNC_Template::excerpt( '<p> </p>' ) );
	}
}
