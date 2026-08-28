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
		$pub_date    = $this->sanitize_datetime( $item['pub_date'] ?? '' );

		if ( empty( $title ) || empty( $main_link ) ) {
			return new WP_Error( 'wpnc_publish_missing_data', __( 'Cannot publish an item without title and source URL.', 'wp-news-collector' ) );
		}

		$content = $description . "\n\n" . sprintf(
			'<p class="wpnc-source-link">%s <a href="%s" target="_blank" rel="nofollow noopener">%s</a></p>',
			esc_html__( 'Source:', 'wp-news-collector' ),
			esc_url( $main_link ),
			esc_html( $source_name ? $source_name : $main_link )
		);

		$post_id = wp_insert_post(
			array(
				'post_title'   => wp_strip_all_tags( $title ),
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_author'  => $this->get_post_author(),
				'post_date'    => $pub_date,
				'post_type'    => $post_type,
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
			wp_set_post_tags( $post_id, $tags, true );
		}

		add_post_meta( $post_id, '_wpnc_source_url', $main_link, true );
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
				add_post_meta( $post_id, 'wpnc_source_image', $image_url, true );
				$this->logger->log(
					WPNC_Logger::LEVEL_WARNING,
					__( 'Image sideload failed.', 'wp-news-collector' ),
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
				__( 'Telegram notification failed.', 'wp-news-collector' ),
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
	 * Get post author.
	 *
	 * @return int
	 */
	private function get_post_author() {
		$user_id = get_current_user_id();

		return $user_id ? $user_id : 1;
	}

	/**
	 * Sanitize post date.
	 *
	 * @param string $datetime Datetime.
	 * @return string
	 */
	private function sanitize_datetime( $datetime ) {
		$timestamp = strtotime( $datetime );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' );
	}
}
