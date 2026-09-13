<?php
/**
 * Featured image selection.
 *
 * The rules here decide which picture a post gets. Before they existed, a
 * relative og:image, a lazy-loaded body image, an enclosure without a
 * declared type, or a CDN address without an extension each meant no picture
 * at all - which is what "sometimes it comes, sometimes it does not" was.
 */

use PHPUnit\Framework\TestCase;

class ImagePickerTest extends TestCase {

	const PAGE = 'https://news.example.ir/story/2024/item.html';

	protected function setUp(): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'ext-dom is not available.' );
		}
	}

	public function test_protocol_relative_addresses_take_the_page_scheme() {
		$this->assertSame( 'https://cdn.example.com/a.jpg', WPNC_Image_Picker::absolute( '//cdn.example.com/a.jpg', self::PAGE ) );
	}

	public function test_root_relative_addresses_take_the_page_origin() {
		$this->assertSame( 'https://news.example.ir/uploads/a.jpg', WPNC_Image_Picker::absolute( '/uploads/a.jpg', self::PAGE ) );
	}

	public function test_relative_addresses_resolve_against_the_page_directory() {
		$this->assertSame( 'https://news.example.ir/story/2024/img/a.jpg', WPNC_Image_Picker::absolute( 'img/a.jpg', self::PAGE ) );
		$this->assertSame( 'https://news.example.ir/story/img/a.jpg', WPNC_Image_Picker::absolute( '../img/a.jpg', self::PAGE ) );
	}

	public function test_entities_and_spaces_in_an_address_survive() {
		$this->assertSame(
			'https://news.example.ir/uploads/a%20b.jpg?w=800&h=600',
			WPNC_Image_Picker::absolute( '/uploads/a b.jpg?w=800&amp;h=600', self::PAGE )
		);
	}

	public function test_addresses_that_are_not_pictures_resolve_to_nothing() {
		$this->assertSame( '', WPNC_Image_Picker::absolute( 'data:image/gif;base64,R0lGODlh', self::PAGE ) );
		$this->assertSame( '', WPNC_Image_Picker::absolute( 'javascript:alert(1)', self::PAGE ) );
		$this->assertSame( '', WPNC_Image_Picker::absolute( '', self::PAGE ) );
		$this->assertSame( 'https://other.example/a.jpg', WPNC_Image_Picker::absolute( 'https://other.example/a.jpg', self::PAGE ) );
	}

	public function test_page_furniture_is_not_taken_for_the_story_picture() {
		$this->assertTrue( WPNC_Image_Picker::is_junk( 'https://secure.gravatar.com/avatar/abc' ) );
		$this->assertTrue( WPNC_Image_Picker::is_junk( 'https://news.example.ir/themes/site/logo.png' ) );
		$this->assertTrue( WPNC_Image_Picker::is_junk( 'https://news.example.ir/icons/share.svg' ) );
		$this->assertFalse( WPNC_Image_Picker::is_junk( 'https://news.example.ir/uploads/2024/05/story-photo.jpg' ) );

		// Only the file name counts, not the folder it sits in.
		$this->assertFalse( WPNC_Image_Picker::is_junk( 'https://news.example.ir/lazy/uploads/photo.jpg' ) );
	}

	public function test_an_enclosure_is_an_image_by_type_medium_or_extension() {
		$this->assertTrue( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/a', 'image/jpeg' ) );
		$this->assertTrue( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/a', '', 'image' ) );
		$this->assertTrue( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/a.JPG' ) );
		$this->assertTrue( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/a.jpg', 'application/octet-stream' ) );
	}

	public function test_an_enclosure_declared_as_audio_or_video_is_not_an_image() {
		$this->assertFalse( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/a.mp3', 'audio/mpeg' ) );
		$this->assertFalse( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/a.jpg', 'video/mp4' ) );
		$this->assertFalse( WPNC_Image_Picker::enclosure_is_image( 'https://x.example/watch' ) );
	}

	public function test_the_first_picture_in_item_html_is_found_and_resolved() {
		$html = '<p>متن خبر</p><img src="/uploads/a.jpg" alt="">';

		$this->assertSame( 'https://news.example.ir/uploads/a.jpg', WPNC_Image_Picker::from_html( $html, self::PAGE ) );
	}

	public function test_a_lazy_loaded_picture_is_read_from_its_data_attribute() {
		$html = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="https://cdn.example.com/real.jpg">';
		$this->assertSame( 'https://cdn.example.com/real.jpg', WPNC_Image_Picker::from_html( $html, self::PAGE ) );

		// A placeholder that is a real file rather than a data URI.
		$html = '<img src="/images/lazy-placeholder.gif" data-src="/uploads/real.jpg">';
		$this->assertSame( 'https://news.example.ir/uploads/real.jpg', WPNC_Image_Picker::from_html( $html, self::PAGE ) );
	}

	public function test_tracking_pixels_are_skipped() {
		$html = '<img src="https://news.example.ir/t.gif" width="1" height="1"><img src="https://news.example.ir/uploads/b.jpg">';

		$this->assertSame( 'https://news.example.ir/uploads/b.jpg', WPNC_Image_Picker::from_html( $html, self::PAGE ) );
	}

	public function test_html_without_a_picture_yields_nothing() {
		$this->assertSame( '', WPNC_Image_Picker::from_html( '<p>بدون تصویر</p>', self::PAGE ) );
	}

	public function test_a_secure_og_image_is_preferred() {
		$html = '<html><head><meta property="og:image" content="http://news.example.ir/a.jpg"><meta property="og:image:secure_url" content="https://news.example.ir/a.jpg"></head><body></body></html>';

		$this->assertSame( 'https://news.example.ir/a.jpg', WPNC_Image_Picker::from_page( $html, self::PAGE ) );
	}

	public function test_a_relative_og_image_is_resolved_instead_of_rejected() {
		$html = '<html><head><meta property="og:image" content="/uploads/hero.jpg"></head><body></body></html>';

		$this->assertSame( 'https://news.example.ir/uploads/hero.jpg', WPNC_Image_Picker::from_page( $html, self::PAGE ) );
	}

	public function test_a_logo_declared_first_does_not_hide_the_real_picture() {
		$html = '<html><head><meta property="og:image" content="https://news.example.ir/logo.png"><meta property="og:image" content="https://news.example.ir/uploads/hero.jpg"></head><body></body></html>';

		$this->assertSame( 'https://news.example.ir/uploads/hero.jpg', WPNC_Image_Picker::from_page( $html, self::PAGE ) );
	}

	public function test_twitter_image_is_the_fallback_for_a_page_without_og_image() {
		$html = '<html><head><meta name="twitter:image" content="https://news.example.ir/t.jpg"></head><body></body></html>';

		$this->assertSame( 'https://news.example.ir/t.jpg', WPNC_Image_Picker::from_page( $html, self::PAGE ) );
	}

	public function test_a_lazy_article_image_is_the_last_resort() {
		$html = '<html><body><article><img src="data:image/gif;base64,AAAA" data-src="/u/p.jpg"></article></body></html>';

		$this->assertSame( 'https://news.example.ir/u/p.jpg', WPNC_Image_Picker::from_page( $html, self::PAGE ) );
	}

	public function test_the_largest_srcset_candidate_wins() {
		$this->assertSame( 'b.jpg', WPNC_Image_Picker::largest_from_srcset( 'a.jpg 300w, b.jpg 1200w, c.jpg 800w' ) );
		$this->assertSame( 'b.jpg', WPNC_Image_Picker::largest_from_srcset( 'a.jpg 1x, b.jpg 2x' ) );
		$this->assertSame( 'only.jpg', WPNC_Image_Picker::largest_from_srcset( 'only.jpg' ) );
	}

	public function test_the_bytes_decide_the_type_not_the_address() {
		$this->assertSame( 'image/jpeg', WPNC_Image_Picker::resolve_mime( 'image/jpeg' ) );
		$this->assertSame( 'image/png', WPNC_Image_Picker::resolve_mime( 'IMAGE/PNG' ) );
		$this->assertSame( '', WPNC_Image_Picker::resolve_mime( 'image/bmp' ) );

		// An error page is never an image, whatever header came with it.
		$this->assertSame( '', WPNC_Image_Picker::resolve_mime( false, '<!DOCTYPE html>' ) );
	}

	public function test_avif_is_recognised_from_its_brand_when_php_cannot_read_it() {
		$head = "\x00\x00\x00\x1cftypavif\x00\x00\x00\x00";

		$this->assertSame( 'image/avif', WPNC_Image_Picker::resolve_mime( false, $head ) );
	}

	public function test_an_address_without_an_extension_still_gets_a_file_name() {
		$this->assertSame( '12345.webp', WPNC_Image_Picker::filename( 'https://cdn.example.com/image/12345?w=800', 'image/webp' ) );
	}

	public function test_the_extension_follows_the_bytes() {
		$this->assertSame( 'photo.png', WPNC_Image_Picker::filename( 'https://news.example.ir/uploads/photo.jpg', 'image/png' ) );
	}

	public function test_a_persian_file_name_becomes_a_safe_ascii_one() {
		$name = WPNC_Image_Picker::filename( 'https://news.example.ir/uploads/%D8%B9%DA%A9%D8%B3-%D8%AE%D8%A8%D8%B1.jpg', 'image/jpeg' );

		$this->assertMatchesRegularExpression( '/^wpnc-image-[0-9a-f]{10}\.jpg$/', $name );
	}

	public function test_an_unsupported_type_gets_no_file_name() {
		$this->assertSame( '', WPNC_Image_Picker::filename( 'https://news.example.ir/a.jpg', 'text/html' ) );
	}

	public function test_sizes_scheme_www_and_query_do_not_make_a_different_picture() {
		$this->assertTrue(
			WPNC_Image_Picker::same_image(
				'https://news.example.ir/uploads/photo.jpg',
				'http://www.news.example.ir/uploads/photo-1024x683.jpg?ver=2'
			)
		);
		$this->assertFalse( WPNC_Image_Picker::same_image( 'https://news.example.ir/uploads/photo.jpg', 'https://news.example.ir/uploads/other.jpg' ) );
		$this->assertFalse( WPNC_Image_Picker::same_image( '', '' ) );
	}

	public function test_the_featured_image_is_removed_from_the_body_with_its_figure() {
		$html = '<p>اول</p><figure><img src="https://news.example.ir/uploads/hero.jpg"><figcaption>عکس خبر</figcaption></figure><p>دوم</p>';
		$out  = WPNC_Image_Picker::strip_duplicate( $html, 'https://news.example.ir/uploads/hero.jpg', self::PAGE );

		$this->assertStringNotContainsString( 'hero.jpg', $out );
		$this->assertStringNotContainsString( '<figure', $out );
		$this->assertStringNotContainsString( 'figcaption', $out );
		$this->assertStringContainsString( 'اول', $out, 'Persian text must survive the round trip.' );
		$this->assertStringContainsString( 'دوم', $out );
	}

	public function test_a_paragraph_that_only_held_the_picture_goes_with_it() {
		$html = '<p><img src="/uploads/hero.jpg"></p><p>متن</p>';
		$out  = WPNC_Image_Picker::strip_duplicate( $html, 'https://news.example.ir/uploads/hero.jpg', self::PAGE );

		$this->assertSame( '<p>متن</p>', $out );
	}

	public function test_a_picture_inside_a_sentence_leaves_the_sentence() {
		$html = '<p><a href="https://news.example.ir/uploads/hero.jpg"><img src="https://news.example.ir/uploads/hero-800x533.jpg"></a> متن کنار عکس</p>';
		$out  = WPNC_Image_Picker::strip_duplicate( $html, 'https://news.example.ir/uploads/hero.jpg', self::PAGE );

		$this->assertStringNotContainsString( '<img', $out );
		$this->assertStringNotContainsString( '<a ', $out );
		$this->assertStringContainsString( 'متن کنار عکس', $out );
	}

	public function test_other_pictures_in_the_body_are_left_alone() {
		$html = "<p>متن</p>\n<img src='https://news.example.ir/uploads/other.jpg' />";

		$this->assertSame(
			$html,
			WPNC_Image_Picker::strip_duplicate( $html, 'https://news.example.ir/uploads/hero.jpg', self::PAGE ),
			'Nothing matched, so the markup must come back byte for byte.'
		);
	}

	public function test_no_featured_image_means_no_change() {
		$html = '<p><img src="https://news.example.ir/uploads/hero.jpg"></p>';

		$this->assertSame( $html, WPNC_Image_Picker::strip_duplicate( $html, '', self::PAGE ) );
	}

	public function test_spaces_are_encoded_rather_than_deleted() {
		$this->assertSame( 'https://news.example.ir/a%20b.jpg', WPNC_Image_Picker::encode_spaces( " https://news.example.ir/a b.jpg\n" ) );
	}
}
