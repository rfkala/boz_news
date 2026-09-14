<?php
/**
 * When two addresses are the same article.
 *
 * Every case here is one the plugin was getting wrong: the same story arriving
 * twice because a feed changed something that does not change the article.
 */

use PHPUnit\Framework\TestCase;

class LinkTest extends TestCase {

	public function test_the_same_article_hashes_the_same_through_harmless_differences() {
		$canonical = WPNC_Link::hash( 'https://example.com/news/1234' );

		$this->assertNotSame( '', $canonical );

		$same = array(
			'http://example.com/news/1234'                  => 'scheme',
			'https://www.example.com/news/1234'             => 'www prefix',
			'https://EXAMPLE.com/news/1234'                 => 'host case',
			'https://example.com/news/1234/'                => 'trailing slash',
			'https://example.com/news/1234#comments'        => 'fragment',
			'https://example.com:443/news/1234'             => 'default port',
			'https://example.com/news/1234?utm_source=tg'   => 'campaign parameter',
			'https://example.com/news/1234?fbclid=abc123'   => 'click identifier',
			'  https://example.com/news/1234  '             => 'surrounding space',
		);

		foreach ( $same as $url => $why ) {
			$this->assertSame( $canonical, WPNC_Link::hash( $url ), 'should match despite ' . $why . ': ' . $url );
		}
	}

	public function test_different_articles_do_not_collide() {
		$first = WPNC_Link::hash( 'https://example.com/news/1234' );

		$different = array(
			'https://example.com/news/1235',
			'https://example.com/News/1234',
			'https://other.com/news/1234',
			'https://example.com/news/1234?page=2',
		);

		foreach ( $different as $url ) {
			$this->assertNotSame( $first, WPNC_Link::hash( $url ), 'should not match: ' . $url );
		}
	}

	public function test_long_persian_addresses_stay_distinct() {
		// The queue's unique key only covers the first 191 characters of the
		// address, and one Persian letter costs six of those once encoded, so
		// two stories can share a prefix and collide. A fixed-width hash of
		// the whole address is what this replaces it with.
		$base = 'https://example.ir/fa/news/' . str_repeat( '%D8%A7%D8%B9%D9%84%D8%A7%D9%85-', 12 );

		$a = WPNC_Link::hash( $base . 'first-story' );
		$b = WPNC_Link::hash( $base . 'second-story' );

		$this->assertNotSame( '', $a );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 32, strlen( $a ) );
	}

	public function test_meaningful_parameters_survive_and_their_order_does_not_matter() {
		$this->assertSame(
			WPNC_Link::hash( 'https://example.com/index.php?id=9&lang=fa' ),
			WPNC_Link::hash( 'https://example.com/index.php?lang=fa&id=9' ),
			'parameter order is not meaningful to a server'
		);

		$this->assertNotSame(
			WPNC_Link::hash( 'https://example.com/index.php?id=9' ),
			WPNC_Link::hash( 'https://example.com/index.php?id=10' )
		);

		// A real parameter alongside a campaign one keeps the real one.
		$this->assertSame(
			WPNC_Link::hash( 'https://example.com/index.php?id=9' ),
			WPNC_Link::hash( 'https://example.com/index.php?id=9&utm_medium=email&fbclid=x' )
		);
	}

	public function test_ambiguous_parameters_are_left_alone() {
		// "ref" and "source" carry real content on some sites, so they are not
		// treated as tracking: dropping them could merge two real articles.
		$this->assertFalse( WPNC_Link::is_tracking_param( 'ref' ) );
		$this->assertFalse( WPNC_Link::is_tracking_param( 'source' ) );
		$this->assertFalse( WPNC_Link::is_tracking_param( 'id' ) );

		$this->assertTrue( WPNC_Link::is_tracking_param( 'utm_source' ) );
		$this->assertTrue( WPNC_Link::is_tracking_param( 'UTM_Campaign' ) );
		$this->assertTrue( WPNC_Link::is_tracking_param( 'fbclid' ) );
	}

	public function test_an_unusable_address_hashes_to_nothing_rather_than_to_something() {
		// An empty hash must never be stored: every unusable address would
		// otherwise share one key and the second story would be dropped as a
		// duplicate of the first.
		foreach ( array( '', '   ', 'not a url', '/relative/path', 'javascript:alert(1)', 'ftp://example.com/x' ) as $url ) {
			$this->assertSame( '', WPNC_Link::hash( $url ), 'should be unusable: ' . $url );
		}
	}

	public function test_a_guid_is_respected_as_given() {
		$this->assertSame( md5( 'tag:example.com,2026:1234' ), WPNC_Link::guid_hash( 'tag:example.com,2026:1234' ) );
		$this->assertSame( md5( '1234' ), WPNC_Link::guid_hash( '  1234  ' ) );
		$this->assertSame( '', WPNC_Link::guid_hash( '' ) );

		// Unlike a link, two guids differing by case are two guids.
		$this->assertNotSame( WPNC_Link::guid_hash( 'ABC' ), WPNC_Link::guid_hash( 'abc' ) );
	}

	public function test_a_port_that_matters_is_kept() {
		$this->assertNotSame(
			WPNC_Link::hash( 'https://example.com/news' ),
			WPNC_Link::hash( 'https://example.com:8080/news' )
		);
	}
}
