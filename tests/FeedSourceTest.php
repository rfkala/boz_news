<?php
/**
 * Source list parsing, the disabled flag, and the SSRF guard.
 *
 * parse_sources() is where a typed URL becomes something the fetcher will
 * request, so both the round trip and the rejection rules are pinned here.
 */

use PHPUnit\Framework\TestCase;

/**
 * The two methods of a SimplePie item that best_body() consults.
 */
class WPNC_Fake_Feed_Item {

	private $description;
	private $content;

	public function __construct( $description, $content ) {
		$this->description = $description;
		$this->content     = $content;
	}

	public function get_description() {
		return $this->description;
	}

	public function get_content() {
		// SimplePie falls back to the description when there is no
		// content:encoded, and the real thing is mirrored here.
		return '' !== $this->content ? $this->content : $this->description;
	}
}

class FeedSourceTest extends TestCase {

	/**
	 * @var WPNC_Feed_Reader
	 */
	private $reader;

	protected function setUp(): void {
		WPNC_Test_Options::reset();
		$this->reader = new WPNC_Feed_Reader();
	}

	public function test_parses_url_category_and_key() {
		$sources = $this->reader->parse_sources( 'https://example.com/feed|5|example_key' );

		$this->assertCount( 1, $sources );
		$this->assertSame( 'https://example.com/feed', $sources[0]['url'] );
		$this->assertSame( 5, $sources[0]['category_id'] );
		$this->assertSame( 'example_key', $sources[0]['source_key'] );
		$this->assertTrue( $sources[0]['enabled'] );
		$this->assertTrue( $sources[0]['valid'] );
	}

	public function test_bare_url_gets_defaults() {
		$sources = $this->reader->parse_sources( 'https://example.com/feed' );

		$this->assertSame( 0, $sources[0]['category_id'] );
		$this->assertSame( '', $sources[0]['source_key'] );
		$this->assertTrue( $sources[0]['enabled'] );
	}

	public function test_hash_comments_are_dropped_entirely() {
		$sources = $this->reader->parse_sources( "# a note\nhttps://example.com/feed" );

		$this->assertCount( 1, $sources );
		$this->assertSame( 'https://example.com/feed', $sources[0]['url'] );
	}

	public function test_bang_prefix_keeps_the_source_but_disables_it() {
		$sources = $this->reader->parse_sources( '!https://example.com/feed|5|example_key' );

		$this->assertCount( 1, $sources );
		$this->assertFalse( $sources[0]['enabled'] );
		$this->assertSame( 5, $sources[0]['category_id'], 'Pausing must not lose the category.' );
		$this->assertSame( 'example_key', $sources[0]['source_key'], 'Pausing must not lose the key.' );
	}

	public function test_blank_and_whitespace_only_lines_are_ignored() {
		$sources = $this->reader->parse_sources( "\n   \nhttps://example.com/feed\n\n" );

		$this->assertCount( 1, $sources );
	}

	public function test_all_three_line_ending_styles_parse() {
		foreach ( array( "\n", "\r\n", "\r" ) as $eol ) {
			$sources = $this->reader->parse_sources(
				'https://a.example/feed' . $eol . 'https://b.example/feed'
			);
			$this->assertCount( 2, $sources );
		}
	}

	public function test_to_line_round_trips_every_variant() {
		$cases = array(
			'https://example.com/feed',
			'https://example.com/feed|5',
			'https://example.com/feed|5|example_key',
			'!https://example.com/feed',
			'!https://example.com/feed|5|example_key',
		);

		foreach ( $cases as $line ) {
			$sources = $this->reader->parse_sources( $line );
			$this->assertSame(
				$line,
				WPNC_Feed_Reader::to_line( $sources[0] ),
				'Saving the settings form must not change this line: ' . $line
			);
		}
	}

	public function test_to_line_emits_the_category_placeholder_when_only_a_key_is_set() {
		$sources = $this->reader->parse_sources( 'https://example.com/feed|0|example_key' );

		$this->assertSame(
			'https://example.com/feed|0|example_key',
			WPNC_Feed_Reader::to_line( $sources[0] ),
			'Dropping the empty category would shift the key into its position.'
		);
	}

	public function test_source_id_prefers_the_key_so_a_url_edit_keeps_history() {
		$a = $this->reader->parse_sources( 'https://example.com/feed|1|mykey' );
		$b = $this->reader->parse_sources( 'https://example.com/feed?v=2|1|mykey' );

		$this->assertSame( $a[0]['id'], $b[0]['id'] );
	}

	public function test_source_id_falls_back_to_the_url_when_there_is_no_key() {
		$a = $this->reader->parse_sources( 'https://a.example/feed' );
		$b = $this->reader->parse_sources( 'https://b.example/feed' );

		$this->assertNotSame( $a[0]['id'], $b[0]['id'] );
		$this->assertStringStartsWith( 'url:', $a[0]['id'] );
	}

	/**
	 * @dataProvider unsafe_urls
	 */
	public function test_unsafe_urls_are_marked_invalid( $url ) {
		$this->assertFalse(
			$this->reader->is_safe_url( $url ),
			$url . ' must not be requested by the server.'
		);
	}

	public function unsafe_urls() {
		return array(
			'loopback name'   => array( 'http://localhost/feed' ),
			'loopback v4'     => array( 'http://127.0.0.1/feed' ),
			'private 10/8'    => array( 'http://10.0.0.5/feed' ),
			'private 192.168' => array( 'http://192.168.1.1/feed' ),
			'link local'      => array( 'http://169.254.169.254/latest/meta-data/' ),
			'file scheme'     => array( 'file:///etc/passwd' ),
			'no scheme'       => array( 'example.com/feed' ),
			'empty'           => array( '' ),
		);
	}

	public function test_ordinary_public_urls_are_allowed() {
		$this->assertTrue( $this->reader->is_safe_url( 'https://example.com/feed' ) );
		$this->assertTrue( $this->reader->is_safe_url( 'http://example.com/feed?x=1' ) );
	}

	public function test_unsafe_lines_are_kept_but_flagged() {
		// They stay in the textarea on purpose so the admin can fix them
		// instead of watching a line disappear.
		$sources = $this->reader->parse_sources( 'http://127.0.0.1/feed|3|local' );

		$this->assertCount( 1, $sources );
		$this->assertFalse( $sources[0]['valid'] );
		$this->assertSame( 'http://127.0.0.1/feed|3|local', WPNC_Feed_Reader::to_line( $sources[0] ) );
	}

	public function test_the_full_article_is_taken_over_the_teaser() {
		// Publishers put the whole article in content:encoded and a teaser in
		// description. Only the teaser was read, so the plugin downloaded the
		// article page to recover text the feed had already sent.
		$item = new WPNC_Fake_Feed_Item(
			'<p>A short teaser.</p>',
			'<p>The whole article, which is considerably longer than the teaser above.</p>'
		);

		$this->assertStringContainsString( 'whole article', WPNC_Feed_Reader::best_body( $item ) );
	}

	public function test_a_feed_that_puts_the_article_in_description_is_respected() {
		$item = new WPNC_Fake_Feed_Item(
			'<p>The whole article, which is considerably longer than the summary.</p>',
			'<p>Summary.</p>'
		);

		$this->assertStringContainsString( 'whole article', WPNC_Feed_Reader::best_body( $item ) );
	}

	public function test_markup_does_not_decide_which_body_is_fuller() {
		// Compared by visible text: a teaser wrapped in a lot of markup is
		// still a teaser.
		$item = new WPNC_Fake_Feed_Item(
			'<div class="a"><p><strong><em>Teaser.</em></strong></p></div>',
			'<p>The real article text, longer than the teaser once tags are set aside.</p>'
		);

		$this->assertStringContainsString( 'real article text', WPNC_Feed_Reader::best_body( $item ) );
	}

	public function test_an_item_without_content_encoded_still_yields_its_description() {
		$item = new WPNC_Fake_Feed_Item( '<p>Only a description here.</p>', '' );

		$this->assertSame( '<p>Only a description here.</p>', WPNC_Feed_Reader::best_body( $item ) );
	}
}
