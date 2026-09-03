<?php
/**
 * OpenAI integration.
 *
 * Two entry points, deliberately separate:
 *
 * - rewrite() runs unattended during a fetch and must return a strict shape.
 * - transform() is driven by an editor pressing a button, works on HTML, and
 *   returns whatever the instruction asked for.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_AI_Rewriter {

	/**
	 * Tags an AI response may keep. Everything else is stripped, so a model
	 * cannot inject markup the editor would then save into a post.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		return array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			'a'          => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array( 'cite' => array() ),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'figure'     => array( 'class' => array() ),
			'figcaption' => array(),
			'img'        => array(
				'src'    => array(),
				'alt'    => array(),
				'width'  => array(),
				'height' => array(),
			),
		);
	}

	/**
	 * The built-in editor actions.
	 *
	 * Each is a system instruction rather than a hardcoded prompt string, so
	 * the free-form action and these share one code path.
	 *
	 * @return array action key => bilingual label
	 */
	public static function actions() {
		return array(
			'rewrite'   => wpnc__( 'Rewrite', 'بازنویسی' ),
			'expand'    => wpnc__( 'Expand', 'گسترش' ),
			'shorten'   => wpnc__( 'Shorten', 'کوتاه کردن' ),
			'translate' => wpnc__( 'Translate', 'ترجمه' ),
			'headline'  => wpnc__( 'Suggest titles', 'پیشنهاد عنوان' ),
			'tags'      => wpnc__( 'Suggest tags', 'پیشنهاد برچسب' ),
		);
	}

	/**
	 * Instruction for a built-in action.
	 *
	 * @param string $action   Action key.
	 * @param string $language Target language, or empty to keep the original.
	 * @return string
	 */
	private function instruction_for( $action, $language ) {
		$into = $language ? ' Write the result in ' . $language . '.' : ' Keep the original language.';

		$map = array(
			'rewrite'   => 'Rewrite the article so the wording is original and no sentence is copied, while every fact, name, number and quote stays exactly as given. Keep roughly the same length.',
			'expand'    => 'Expand the article with more detail and context drawn only from what is already stated. Do not invent facts, names, numbers, quotes or sources.',
			'shorten'   => 'Shorten the article to its essential points, keeping every key fact.',
			'translate' => 'Translate the article faithfully. Do not summarise, add or remove anything.',
			'headline'  => 'Suggest five alternative headlines for this article, as an unordered list. Output only the list.',
			'tags'      => 'Extract up to eight short topical tags for this article. Output only a comma separated list, nothing else.',
		);

		$base = isset( $map[ $action ] ) ? $map[ $action ] : $map['rewrite'];

		return $base . $into;
	}

	/**
	 * Run an editor-driven transformation over a piece of article HTML.
	 *
	 * @param array $args {
	 *     @type string $content     The HTML to work on.
	 *     @type string $title       Optional title, given as context.
	 *     @type string $action      One of actions(), or 'custom'.
	 *     @type string $instruction Free-form instruction, used when action is 'custom'.
	 *     @type string $language    Target language, or empty.
	 * }
	 * @return array|WP_Error { content, action }
	 */
	public function transform( $args ) {
		$content     = isset( $args['content'] ) ? (string) $args['content'] : '';
		$title       = isset( $args['title'] ) ? (string) $args['title'] : '';
		$action      = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : 'rewrite';
		$instruction = isset( $args['instruction'] ) ? trim( (string) $args['instruction'] ) : '';
		$language    = isset( $args['language'] ) ? sanitize_text_field( $args['language'] ) : '';

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error(
				'wpnc_ai_empty',
				wpnc__( 'There is no text to work on yet.', 'هنوز متنی برای کار کردن روی آن وجود ندارد.' )
			);
		}

		if ( 'custom' === $action ) {
			if ( '' === $instruction ) {
				return new WP_Error(
					'wpnc_ai_no_instruction',
					wpnc__( 'Tell the assistant what to change.', 'به دستیار بگویید چه تغییری می‌خواهید.' )
				);
			}
			$system = 'You are editing a news article for a WordPress site. Apply the editor\'s instruction to the article. '
				. 'Never invent facts, names, numbers, quotes or sources that are not already present. '
				. ( $language ? 'Write the result in ' . $language . '.' : 'Keep the original language.' );
			$user   = "Instruction: " . $instruction;
		} else {
			$system = 'You are editing a news article for a WordPress site. '
				. $this->instruction_for( $action, $language );
			$user   = '';
		}

		$system .= ' Return only the resulting article body as simple HTML using p, h2, h3, ul, ol, li, blockquote, strong, em and a tags. '
			. 'Do not wrap it in a code fence and do not add commentary.';

		$prompt = '';
		if ( '' !== $title ) {
			$prompt .= "Title: " . wp_strip_all_tags( $title ) . "\n\n";
		}
		if ( '' !== $user ) {
			$prompt .= $user . "\n\n";
		}
		$prompt .= "Article:\n" . $content;

		$result = $this->call(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			0.5
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'content' => $this->clean_html( $result ),
			'action'  => $action,
		);
	}

	/**
	 * Rewrite a news item during an unattended fetch.
	 *
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @return array|WP_Error
	 */
	public function rewrite( $title, $description ) {
		$target_language    = sanitize_text_field( get_option( 'wpnc_target_language', '' ) );
		$translation_prompt = $target_language ? ' Translate it into ' . $target_language . '.' : '';

		$prompt = "Rewrite the following news title and description to be unique, SEO friendly, and preserve the main facts.{$translation_prompt} Extract up to 5 relevant SEO tags as a comma-separated string. Return only JSON with keys title, description, and tags.\n\nOriginal Title: "
			. wp_strip_all_tags( $title )
			. "\nOriginal Description: "
			. wp_strip_all_tags( $description );

		$result = $this->call(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			0.4,
			array( 'type' => 'json_object' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = json_decode( trim( $result ), true );

		if ( ! is_array( $parsed ) || empty( $parsed['title'] ) || empty( $parsed['description'] ) ) {
			return new WP_Error(
				'wpnc_openai_invalid_json',
				wpnc__( 'OpenAI did not return a valid rewrite payload.', 'پاسخ اوپن‌ای‌آی قابل استفاده نبود.' )
			);
		}

		return array(
			'title'       => sanitize_text_field( $parsed['title'] ),
			'description' => wp_kses_post( $parsed['description'] ),
			'tags'        => sanitize_text_field( $parsed['tags'] ?? '' ),
		);
	}

	/**
	 * Whether an API key is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) get_option( 'wpnc_openai_api_key', '' ) );
	}

	/**
	 * One place that talks to OpenAI.
	 *
	 * @param array      $messages        Chat messages.
	 * @param float      $temperature     Sampling temperature.
	 * @param array|null $response_format Optional response_format payload.
	 * @return string|WP_Error Message content.
	 */
	private function call( $messages, $temperature = 0.5, $response_format = null ) {
		$api_key = trim( (string) get_option( 'wpnc_openai_api_key', '' ) );
		if ( '' === $api_key ) {
			return new WP_Error(
				'wpnc_openai_missing_key',
				wpnc__( 'OpenAI API key is missing.', 'کلید API اوپن‌ای‌آی وارد نشده است.' )
			);
		}

		$body = array(
			'model'       => sanitize_text_field( get_option( 'wpnc_openai_model', 'gpt-4o-mini' ) ),
			'messages'    => $messages,
			'temperature' => $temperature,
		);

		if ( is_array( $response_format ) ) {
			$body['response_format'] = $response_format;
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => WPNC_Settings::get_timeout( 45 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			// The API explains refusals and quota problems in the body; passing
			// that through is the difference between a fixable message and
			// "HTTP 429".
			$decoded = json_decode( $raw, true );
			$detail  = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';

			return new WP_Error(
				'wpnc_openai_http_error',
				'' !== $detail
					? sprintf(
						/* translators: 1: HTTP status, 2: message from OpenAI */
						wpnc__( 'OpenAI returned HTTP %1$d: %2$s', 'اوپن‌ای‌آی کد HTTP %1$d برگرداند: %2$s' ),
						$code,
						$detail
					)
					: sprintf(
						/* translators: %d: HTTP status */
						wpnc__( 'OpenAI returned HTTP %d.', 'اوپن‌ای‌آی کد HTTP %d برگرداند.' ),
						$code
					)
			);
		}

		$data    = json_decode( $raw, true );
		$content = isset( $data['choices'][0]['message']['content'] )
			? (string) $data['choices'][0]['message']['content']
			: '';

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'wpnc_openai_empty',
				wpnc__( 'OpenAI returned an empty response.', 'اوپن‌ای‌آی پاسخ خالی برگرداند.' )
			);
		}

		return $content;
	}

	/**
	 * Normalise a model's HTML answer into something safe to store.
	 *
	 * @param string $html Raw model output.
	 * @return string
	 */
	private function clean_html( $html ) {
		$html = trim( (string) $html );

		// Models wrap answers in a fence often enough to be worth handling.
		$html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/', '', $html );

		$html = wp_kses( $html, self::allowed_html() );

		// Plain-text answers arrive without markup; keep the paragraphing.
		if ( false === strpos( $html, '<' ) ) {
			$html = wpautop( $html );
		}

		return trim( $html );
	}
}
