<?php
/**
 * OpenAI rewrite integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_AI_Rewriter {

	/**
	 * Rewrite a news item.
	 *
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @return array|WP_Error
	 */
	public function rewrite( $title, $description ) {
		$api_key = trim( (string) get_option( 'wpnc_openai_api_key', '' ) );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'wpnc_openai_missing_key', __( 'OpenAI API key is missing.', 'wp-news-collector' ) );
		}

		$target_language    = sanitize_text_field( get_option( 'wpnc_target_language', '' ) );
		$translation_prompt = $target_language ? ' Translate it into ' . $target_language . '.' : '';
		$model              = sanitize_text_field( get_option( 'wpnc_openai_model', 'gpt-4o-mini' ) );

		$prompt = "Rewrite the following news title and description to be unique, SEO friendly, and preserve the main facts.{$translation_prompt} Extract up to 5 relevant SEO tags as a comma-separated string. Return only JSON with keys title, description, and tags.\n\nOriginal Title: "
			. wp_strip_all_tags( $title )
			. "\nOriginal Description: "
			. wp_strip_all_tags( $description );

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => $model,
						'messages'        => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						'temperature'     => 0.4,
						'response_format' => array( 'type' => 'json_object' ),
					)
				),
				'timeout' => max( 10, min( 45, absint( get_option( 'wpnc_request_timeout', 20 ) ) + 15 ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'wpnc_openai_http_error',
				sprintf( __( 'OpenAI returned HTTP %d.', 'wp-news-collector' ), $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$json = $data['choices'][0]['message']['content'] ?? '';
		$parsed = json_decode( trim( (string) $json ), true );

		if ( ! is_array( $parsed ) || empty( $parsed['title'] ) || empty( $parsed['description'] ) ) {
			return new WP_Error( 'wpnc_openai_invalid_json', __( 'OpenAI did not return a valid rewrite payload.', 'wp-news-collector' ) );
		}

		return array(
			'title'       => sanitize_text_field( $parsed['title'] ),
			'description' => wp_kses_post( $parsed['description'] ),
			'tags'        => sanitize_text_field( $parsed['tags'] ?? '' ),
		);
	}
}
