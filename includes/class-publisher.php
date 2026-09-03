<?php
/**
 * Post publishing service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Publisher {

	/**
	 * @var WPNC_Image_Service
	 */
	private $image_service;

	/**
	 * @var WPNC_Telegram
	 */
	private $telegram;

	/**
	 * @var WPNC_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->image_service = new WPNC_Image_Service();
		$this->telegram      = new WPNC_Telegram();
		$this->logger        = new WPNC_Logger();
	}

	/**
	 * Publish a normalized news item.
	 *
	 * @param array|object $item      Item.
	 * @param string       $post_type Optional post type override.
	 * @return int|WP_Error
	 */
	public function publish( $item, $post_type = '' ) {
		$item = (array) $item;

		$post_type = $post_type ? sanitize_key( $post_type ) : WPNC_Settings::get_target_post_type();
		$cat_id    = absint( $item['category_id'] ?? 0 );
		if ( ! $cat_id ) {
			$cat_id = absint( get_option( 'wpnc_default_category', 0 ) );
		}

		$title       = sanitize_text_field( $item['title'] ?? '' );
		$description = wp_kses_post( $item['description'] ?? '' );
		$main_link   = esc_url_raw( $item['main_link'] ?? '' );
		$source_name = sanitize_text_field( $item['source_name'] ?? '' );
		$pub_date    = WPNC_Time::to_utc( $item['pub_date'] ?? '' );

		if ( empty( $title ) || empty( $main_link ) ) {
			return new WP_Error( 'wpnc_publish_missing_data', wpnc__( 'Cannot publish an item without title and source URL.', 'انتشار بدون عنوان و آدرس منبع ممکن نیست.' ) );
		}

		$content = $this->build_content(
			array(
				'content'     => $description,
				'title'       => $title,
				'source_name' => $source_name,
				'main_link'   => $main_link,
				'pub_date'    => $pub_date,
				'image_url'   => esc_url_raw( $item['image_url'] ?? '' ),
				'tags'        => sanitize_text_field( $item['tags'] ?? '' ),
			)
		);

		$schedule = $this->schedule_for( $pub_date );

		$post_id = wp_insert_post(
			array(
				'post_title'    => wp_strip_all_tags( $title ),
				'post_content'  => $content,
				'post_status'   => $schedule['status'],
				'post_author'   => $this->get_post_author(),
				'post_date_gmt' => $schedule['date_gmt'],
				'post_type'     => $post_type,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $cat_id ) {
			wp_set_object_terms( $post_id, array( $cat_id ), 'category', false );
		}

		$tags = sanitize_text_field( $item['tags'] ?? '' );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $post_id, $tags, false );
		}

		add_post_meta( $post_id, '_wpnc_source_url', $main_link, true );
		add_post_meta( $post_id, '_wpnc_original_date', $pub_date, true );
		add_post_meta( $post_id, '_wpnc_source_name', $source_name, true );
		if ( ! empty( $item['guid'] ) ) {
			add_post_meta( $post_id, '_wpnc_source_guid', sanitize_text_field( $item['guid'] ), true );
		}

		$image_url = esc_url_raw( $item['image_url'] ?? '' );
		if ( empty( $image_url ) ) {
			$image_url = esc_url_raw( get_option( 'wpnc_default_image', '' ) );
		}

		if ( $image_url ) {
			$attachment_id = $this->image_service->sideload_featured_image( $image_url, $post_id, $title );
			if ( is_wp_error( $attachment_id ) ) {
				add_post_meta( $post_id, '_wpnc_source_image', $image_url, true );
				$this->logger->log(
					WPNC_Logger::LEVEL_WARNING,
					wpnc__( 'Image sideload failed.', 'بارگذاری تصویر ناموفق بود.' ),
					array(
						'post_id' => $post_id,
						'error'   => $attachment_id->get_error_message(),
						'url'     => $image_url,
					),
					$item['source_key'] ?? ''
				);
			}
		}

		$telegram_result = $this->telegram->send_post( $post_id, $title );
		if ( is_wp_error( $telegram_result ) ) {
			$this->logger->log(
				WPNC_Logger::LEVEL_WARNING,
				wpnc__( 'Telegram notification failed.', 'ارسال اعلان تلگرام ناموفق بود.' ),
				array(
					'post_id' => $post_id,
					'error'   => $telegram_result->get_error_message(),
				),
				$item['source_key'] ?? ''
			);
		}

		return $post_id;
	}

	/**
	 * Assemble the post body from the configured template.
	 *
	 * @param array $parts Item values.
	 * @return string
	 */
	/**
	 * Assemble the post body for one item.
	 *
	 * Public because the preview endpoint renders through this. A preview
	 * that assembled the body separately would drift from what is actually
	 * published, and a preview that lies is worse than none.
	 *
	 * @param array $parts content, title, source_name, main_link, pub_date,
	 *                     image_url, tags.
	 * @return string
	 */
	public function build_content( $parts ) {
		$parts = array_merge(
			array(
				'content'     => '',
				'title'       => '',
				'source_name' => '',
				'main_link'   => '',
				'pub_date'    => '',
				'image_url'   => '',
				'tags'        => '',
			),
			(array) $parts
		);

		$link_text = $parts['source_name'] ? $parts['source_name'] : $parts['main_link'];

		$image = '';
		if ( ! empty( $parts['image_url'] ) ) {
			$image = sprintf(
				'<figure class="wpnc-source-image"><img src="%s" alt="%s" /></figure>',
				esc_url( $parts['image_url'] ),
				esc_attr( $parts['title'] )
			);
		}

		return WPNC_Template::render(
			(string) get_option( 'wpnc_content_template', '' ),
			array(
				'content'      => $parts['content'],
				'title'        => esc_html( $parts['title'] ),
				'excerpt'      => esc_html( WPNC_Template::excerpt( $parts['content'] ) ),
				'source_name'  => esc_html( $parts['source_name'] ),
				'source_url'   => esc_url( $parts['main_link'] ),
				'source_label' => esc_html( wpnc__( 'Source:', 'منبع:' ) ),
				'source_link'  => sprintf(
					'<a href="%s" target="_blank" rel="nofollow noopener">%s</a>',
					esc_url( $parts['main_link'] ),
					esc_html( $link_text )
				),
				'date'         => esc_html( WPNC_Time::for_display( $parts['pub_date'] ) ),
				'image'        => $image,
				'tags'         => esc_html( $parts['tags'] ),
			)
		);
	}

	/**
	 * Decide the status and time a new post goes out with.
	 *
	 * With pacing on, items are spaced instead of all landing at once. The
	 * original publication date is kept in meta either way, because the feed
	 * date is still the truth about when the story happened.
	 *
	 * @param string $pub_date Original publication date, UTC.
	 * @return array { status, date_gmt }
	 */
	private function schedule_for( $pub_date ) {
		$status = $this->get_post_status();

		if ( 'publish' !== $status || ! WPNC_Scheduler::is_enabled() ) {
			return array(
				'status'   => $status,
				'date_gmt' => $pub_date,
			);
		}

		$slot = WPNC_Scheduler::next_slot();
		$now  = WPNC_Time::timestamp();

		// With pacing on, the post date is the slot rather than the feed's
		// date. That is what lets the next item pace off this one: stamping
		// an immediately published item with an hours-old feed date put it
		// outside the lookback window, so every item in a batch found
		// nothing ahead of it and published at once. The feed's own date is
		// still kept in _wpnc_original_date.
		if ( $slot <= $now ) {
			return array(
				'status'   => 'publish',
				'date_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
			);
		}

		return array(
			'status'   => 'future',
			'date_gmt' => gmdate( 'Y-m-d H:i:s', $slot ),
		);
	}

	/**
	 * Get post author.
	 *
	 * Cron has no current user, so fall back to the configured author rather
	 * than silently attributing every scheduled import to user 1.
	 *
	 * @return int
	 */
	private function get_post_author() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return $user_id;
		}

		$configured = absint( get_option( 'wpnc_post_author', 0 ) );
		if ( $configured && get_userdata( $configured ) ) {
			return $configured;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * Post status new items are created with.
	 *
	 * @return string
	 */
	private function get_post_status() {
		$status  = sanitize_key( get_option( 'wpnc_post_status', 'publish' ) );
		$allowed = array( 'publish', 'draft', 'pending', 'private' );

		return in_array( $status, $allowed, true ) ? $status : 'publish';
	}
}
