<?php
/**
 * What search engines are told about a published item.
 */

use PHPUnit\Framework\TestCase;

class SeoTest extends TestCase {

	protected function setUp(): void {
		WPNC_Test_Options::reset();
	}

	public function test_a_short_description_is_left_as_written() {
		$this->assertSame( 'A short summary.', WPNC_SEO::meta_description( '<p>A short summary.</p>' ) );
	}

	public function test_a_long_description_ends_on_a_whole_word_within_the_limit() {
		$text        = str_repeat( 'Lorem ipsum dolor sit amet ', 20 );
		$description = WPNC_SEO::meta_description( $text );

		$this->assertLessThanOrEqual( WPNC_SEO::DESCRIPTION_LIMIT, mb_strlen( $description ) );
		$this->assertStringEndsWith( '…', $description );
		$this->assertMatchesRegularExpression( '/(Lorem|ipsum|dolor|sit|amet)…$/u', $description, 'cut between words, not inside one' );
	}

	public function test_a_persian_description_is_measured_in_letters_not_bytes() {
		// Counted in bytes, a Persian description would be cut at half the
		// length a search result can show.
		$text = str_repeat( 'بازار سهام امروز رشد کرد ', 3 );

		$this->assertSame( trim( $text ), WPNC_SEO::meta_description( $text ) );
	}

	public function test_markup_and_entities_do_not_reach_the_description() {
		$this->assertSame( 'Oil & gas "rally"', WPNC_SEO::meta_description( '<b>Oil &amp; gas</b> &quot;rally&quot;' ) );
	}

	public function test_the_assistant_answer_is_read_even_when_decorated() {
		$parsed = WPNC_SEO::parse_ai( "**Description:** \"The central bank raised rates for the third time.\"\n**Keyword:** interest rates" );

		$this->assertSame( 'The central bank raised rates for the third time.', $parsed['description'] );
		$this->assertSame( 'interest rates', $parsed['keyword'] );
	}

	public function test_persian_labels_are_accepted_too() {
		$parsed = WPNC_SEO::parse_ai( "توضیحات: بانک مرکزی نرخ بهره را افزایش داد.\nکلمه کلیدی: نرخ بهره" );

		$this->assertSame( 'بانک مرکزی نرخ بهره را افزایش داد.', $parsed['description'] );
		$this->assertSame( 'نرخ بهره', $parsed['keyword'] );
	}

	public function test_an_answer_without_the_expected_lines_yields_nothing() {
		$this->assertSame( array( 'description' => '', 'keyword' => '' ), WPNC_SEO::parse_ai( 'Sure! Here is some SEO advice.' ) );
	}

	public function test_structured_data_describes_news_and_names_the_original() {
		$data = WPNC_SEO::news_article(
			array(
				'headline'   => 'Rates rise',
				'url'        => 'https://site.example/rates-rise',
				'source_url' => 'https://original.example/story',
				'published'  => '2026-09-15T10:00:00+00:00',
			)
		);

		$this->assertSame( 'NewsArticle', $data['@type'] );
		$this->assertSame( 'https://original.example/story', $data['isBasedOn'] );
		$this->assertSame( 'https://site.example/rates-rise', $data['mainEntityOfPage']['@id'] );
	}

	public function test_empty_fields_are_left_out_rather_than_sent_blank() {
		$data = WPNC_SEO::news_article( array( 'headline' => 'Rates rise' ) );

		foreach ( array( 'image', 'author', 'publisher', 'isBasedOn', 'description', 'mainEntityOfPage' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $data, $field . ' should be absent when empty' );
		}
	}

	public function test_a_headline_is_held_to_the_length_search_engines_accept() {
		$data = WPNC_SEO::news_article( array( 'headline' => str_repeat( 'خبر ', 100 ) ) );

		$this->assertLessThanOrEqual( WPNC_SEO::HEADLINE_LIMIT, mb_strlen( $data['headline'] ) );
	}

	public function test_a_hostile_headline_cannot_close_the_script_tag() {
		// The headline comes from somebody else's feed and is printed into
		// every page view of the post.
		$tag = WPNC_SEO::json_ld( WPNC_SEO::news_article( array( 'headline' => 'Hello</script><script>alert(1)</script>' ) ) );

		$this->assertSame( 1, substr_count( $tag, '</script>' ), 'only the closing tag of our own block' );
		$this->assertStringEndsWith( '</script>', $tag );
		$this->assertStringNotContainsString( '<script>alert', $tag );
	}

	public function test_a_keyword_is_one_clean_line() {
		$this->assertSame( 'interest rates', WPNC_SEO::clean_keyword( "  <i>interest</i>\n rates  " ) );
	}

	public function test_without_an_seo_plugin_the_page_output_is_ours() {
		// No Yoast, Rank Math, All in One SEO, SEOPress or The SEO Framework
		// constant is defined here, so nobody else prints a description and
		// this plugin must.
		$this->assertSame( '', WPNC_SEO::active_plugin() );
	}
}
