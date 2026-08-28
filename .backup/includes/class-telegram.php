<?php
/**
 * Telegram notification integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Telegram {

	/**
	 * Send a Telegram notification for a published post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Post title.
	 * @return bool|WP_Error
	 */
	public function send_post( $post_id, $title ) {
		$token   = trim( (string) get_option( 'wpnc_telegram_token', '' ) );
		$chat_id = trim( (string) get_option( 'wpnc_telegram_chat_id', '' ) );

		if ( empty( $token ) || empty( $chat_id ) ) {
			return false;
		}

		$response = wp_remote_post(
			'https://api.telegram.org/bot' . sanitize_text_field( $token ) . '/sendMessage',
			array(
				'timeout' => 15,
				'body'    => array(
					'chat_id'                  => sanitize_text_field( $chat_id ),
					'text'                     => wp_strip_all_tags( $title ) . "\n\n" . esc_url_raw( get_permalink( $post_id ) ),
					'disable_web_page_preview' => false,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wpnc_telegram_http_error',
				sprintf( __( 'Telegram returned HTTP %d.', 'wp-news-collector' ), $code )
			);
		}

		return true;
	}
}
