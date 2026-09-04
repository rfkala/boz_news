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

		$kind = self::action_kind( $action );

		// Translating into the language it is already in burns a request to
		// get the same text back, and the editor cannot tell that is what
		// happened. Better to say so and spend nothing.
		if ( 'translate' === $action ) {
			if ( '' === $language ) {
				return new WP_Error(
					'wpnc_ai_no_target_language',
					wpnc__(
						'Set a target language under Settings before translating.',
						'پیش از ترجمه، «زبان هدف» را در تنظیمات مشخص کنید.'
					)
				);
			}

			if ( self::already_in_language( $content, $language ) ) {
				return new WP_Error(
					'wpnc_ai_same_language',
					sprintf(
						/* translators: %s: target language name */
						wpnc__(
							'This text is already in %s, so there is nothing to translate.',
							'این متن از قبل به زبان %s است و نیازی به ترجمه ندارد.'
						),
						$language
					)
				);
			}
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

		// The output instruction has to match what was asked for. Appending
		// "return the article body" to a request for headlines is what made
		// suggestions arrive as a replacement article.
		if ( 'titles' === $kind ) {
			$system .= ' Return only the headlines, one per line, with no numbering, bullets, quotes or commentary.';
		} elseif ( 'tags' === $kind ) {
			$system .= ' Return only the tags as a single comma separated line, with no numbering, bullets or commentary.';
		} else {
			$system .= ' Return only the resulting article body as simple HTML using p, h2, h3, ul, ol, li, blockquote, strong, em and a tags. '
				. 'Do not wrap it in a code fence and do not add commentary.';
		}

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

		if ( 'body' !== $kind ) {
			$suggestions = 'titles' === $kind ? self::parse_titles( $result ) : self::parse_tags( $result );

			if ( empty( $suggestions ) ) {
				return new WP_Error(
					'wpnc_ai_no_suggestions',
					wpnc__(
						'The assistant did not return anything usable. Try again.',
						'دستیار چیز قابل استفاده‌ای برنگرداند. دوباره تلاش کنید.'
					)
				);
			}

			return array(
				'kind'        => $kind,
				'suggestions' => $suggestions,
				'action'      => $action,
			);
		}

		return array(
			'kind'    => 'body',
			'content' => $this->clean_html( $result ),
			'action'  => $action,
		);
	}

	/**
	 * What an action produces.
	 *
	 * Headlines and tags are suggestions to choose from, not a new article.
	 * Treating them as one is how they used to end up replacing the body.
	 *
	 * @param string $action Action slug.
	 * @return string body, titles or tags.
	 */
	public static function action_kind( $action ) {
		$kinds = array(
			'headline' => 'titles',
			'tags'     => 'tags',
		);

		return isset( $kinds[ $action ] ) ? $kinds[ $action ] : 'body';
	}

	/**
	 * Read a list of headlines out of whatever shape the model used.
	 *
	 * Models are asked for one per line and still return bullets, numbers or
	 * a stray list element often enough that stripping them is cheaper than
	 * re-prompting.
	 *
	 * @param string $text Model output.
	 * @return array
	 */
	public static function parse_titles( $text ) {
		$text  = wp_strip_all_tags( str_replace( array( '</li>', '</p>', '<br>', '<br/>', '<br />' ), "\n", (string) $text ) );
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$out   = array();

		foreach ( $lines as $line ) {
			$line = self::strip_list_marker( $line );

			if ( '' === $line ) {
				continue;
			}

			$out[] = $line;
		}

		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
	}

	/**
	 * Read a tag list out of the model's answer.
	 *
	 * Splits on both commas and newlines: the prompt asks for one comma
	 * separated line and a model will sometimes answer with a list anyway.
	 *
	 * @param string $text Model output.
	 * @return array
	 */
	public static function parse_tags( $text ) {
		$text  = wp_strip_all_tags( str_replace( array( '</li>', '</p>', '<br>', '<br/>', '<br />' ), "\n", (string) $text ) );
		$parts = preg_split( '/[,،\r\n]+/u', $text );
		$out   = array();

		foreach ( $parts as $part ) {
			$part = self::strip_list_marker( $part );

			// A tag long enough to be a sentence is the model explaining
			// itself, not a tag.
			if ( '' === $part || WPNC_Template::word_count( $part ) > 4 ) {
				continue;
			}

			$out[] = $part;
		}

		return array_slice( array_values( array_unique( $out ) ), 0, 8 );
	}

	/**
	 * Strip the bullet, number or quotes a model wrapped an item in.
	 *
	 * @param string $line One line of model output.
	 * @return string
	 */
	private static function strip_list_marker( $line ) {
		$line = trim( (string) $line );
		$line = preg_replace( '/^\s*(?:[-*•–—]|\d+[.)])\s*/u', '', $line );
		$line = trim( $line, " \t\n\r\0\x0B\"'«»“”‘’" );

		return trim( $line );
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
				'wpnc_ai_invalid_json',
				wpnc__(
					'The assistant did not return a usable rewrite.',
					'دستیار پاسخ قابل استفاده‌ای برنگرداند.'
				)
			);
		}

		return array(
			'title'       => sanitize_text_field( $parsed['title'] ),
			'description' => wp_kses_post( $parsed['description'] ),
			'tags'        => sanitize_text_field( $parsed['tags'] ?? '' ),
		);
	}

	/**
	 * The provider the panel is set to use.
	 *
	 * @return string
	 */
	public static function provider() {
		$slug = sanitize_key( get_option( 'wpnc_ai_provider', 'openai' ) );

		return WPNC_AI_Providers::exists( $slug ) ? $slug : 'openai';
	}

	/**
	 * Model for the active provider, falling back to that provider's default
	 * rather than to another provider's - a Groq model name sent to Anthropic
	 * fails in a way that reads like a broken key.
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function model( $slug = '' ) {
		$slug     = '' === $slug ? self::provider() : $slug;
		$provider = WPNC_AI_Providers::get( $slug );
		$models   = get_option( 'wpnc_ai_models', array() );
		$model    = is_array( $models ) && isset( $models[ $slug ] ) ? trim( (string) $models[ $slug ] ) : '';

		return '' !== $model ? $model : $provider['default_model'];
	}

	/**
	 * The writing system a piece of text is predominantly in.
	 *
	 * Persian and Arabic share a script, and this plugin exists partly to
	 * translate Arabic sources into Persian, so lumping them together would
	 * refuse exactly the translation someone wanted. They are told apart by
	 * the letters only one of them uses: pe, che, zhe, gaf, and the Persian
	 * forms of kaf and ye, against the Arabic teh marbuta, kaf, yeh and alef
	 * maksura.
	 *
	 * @param string $text Text, markup tolerated.
	 * @return string persian, arabic, latin, cyrillic, or empty when there is
	 *                not enough text to tell.
	 */
	public static function dominant_script( $text ) {
		$text = wp_strip_all_tags( (string) $text );

		$counts = array(
			'arabic'   => preg_match_all( '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text ),
			'latin'    => preg_match_all( '/[A-Za-z\x{00C0}-\x{024F}]/u', $text ),
			'cyrillic' => preg_match_all( '/[\x{0400}-\x{04FF}]/u', $text ),
		);

		arsort( $counts );
		$script = key( $counts );
		$top    = current( $counts );

		// Too little to judge. A headline's worth of letters is enough; a
		// stray word is not, and guessing from one would be worse than
		// admitting the question is open.
		if ( $top < 10 ) {
			return '';
		}

		if ( 'arabic' !== $script ) {
			return $script;
		}

		$persian = preg_match_all( '/[\x{067E}\x{0686}\x{0698}\x{06AF}\x{06A9}\x{06CC}]/u', $text );
		$arabic  = preg_match_all( '/[\x{0629}\x{0643}\x{064A}\x{0649}]/u', $text );

		return $persian >= $arabic ? 'persian' : 'arabic';
	}

	/**
	 * The script a named language is written in.
	 *
	 * The target language is a free-text setting, so this accepts the names
	 * people actually type, in either language.
	 *
	 * @param string $language Language name.
	 * @return string persian, arabic, latin, cyrillic, or empty when unknown.
	 */
	public static function language_script( $language ) {
		$language = strtolower( trim( (string) $language ) );

		if ( '' === $language ) {
			return '';
		}

		$map = array(
			'persian'  => array( 'persian', 'farsi', 'parsi', 'fa', 'fa_ir', 'فارسی', 'پارسی' ),
			'arabic'   => array( 'arabic', 'ar', 'عربی', 'العربية', 'عربي' ),
			'cyrillic' => array( 'russian', 'ru', 'روسی', 'ukrainian', 'bulgarian', 'serbian' ),
			'latin'    => array(
				'english', 'en', 'انگلیسی', 'french', 'fr', 'فرانسوی', 'german', 'de', 'آلمانی',
				'spanish', 'es', 'اسپانیایی', 'italian', 'it', 'ایتالیایی', 'portuguese', 'pt',
				'dutch', 'turkish', 'tr', 'ترکی', 'azerbaijani', 'kurdish',
			),
		);

		foreach ( $map as $script => $names ) {
			if ( in_array( $language, $names, true ) ) {
				return $script;
			}
		}

		return '';
	}

	/**
	 * Whether translating this text would be a no-op.
	 *
	 * Deliberately answers false whenever it cannot tell - an unnecessary
	 * translation costs one request, while a wrongly refused one costs the
	 * editor the feature.
	 *
	 * @param string $text     Text to inspect.
	 * @param string $language Target language name.
	 * @return bool
	 */
	public static function already_in_language( $text, $language ) {
		$target = self::language_script( $language );
		$actual = self::dominant_script( $text );

		return '' !== $target && '' !== $actual && $target === $actual;
	}

	/**
	 * The configured address for a provider, if it has been overridden.
	 *
	 * @param string $slug Provider slug, empty for the active one.
	 * @return string Empty to use the provider's own address.
	 */
	public static function base_url( $slug = '' ) {
		$slug  = '' === $slug ? self::provider() : $slug;
		$bases = get_option( 'wpnc_ai_base_urls', array() );

		return is_array( $bases ) && isset( $bases[ $slug ] ) ? trim( (string) $bases[ $slug ] ) : '';
	}

	/**
	 * Whether the active provider has at least one key to try.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return WPNC_AI_Keys::has_keys( self::provider() );
	}

	/**
	 * Send a chat completion, trying each of the provider's keys in turn.
	 *
	 * @param array      $messages        Chat messages.
	 * @param float      $temperature     Sampling temperature.
	 * @param array|null $response_format Retained for callers that want JSON.
	 * @return string|WP_Error Message content.
	 */
	private function call( $messages, $temperature = 0.5, $response_format = null ) {
		$slug     = self::provider();
		$provider = WPNC_AI_Providers::get( $slug );
		$model    = self::model( $slug );
		$keys     = WPNC_AI_Keys::for_provider( $slug );
		$order    = WPNC_AI_Keys::order( $slug );

		if ( empty( $order ) ) {
			return new WP_Error(
				'wpnc_ai_missing_key',
				sprintf(
					/* translators: %s: provider name */
					wpnc__(
						'No API key is set for %s. Add one under Settings.',
						'برای %s کلیدی تنظیم نشده است. در تنظیمات یک کلید اضافه کنید.'
					),
					$provider['label']
				)
			);
		}

		$base = self::base_url( $slug );

		if ( '' === WPNC_AI_Providers::endpoint( $slug, $base, $model ) ) {
			return new WP_Error(
				'wpnc_ai_no_endpoint',
				sprintf(
					/* translators: %s: provider name */
					wpnc__(
						'%s has no address to call. Set its Base URL under Settings.',
						'برای %s آدرسی تعیین نشده است. در تنظیمات، Base URL آن را وارد کنید.'
					),
					$provider['label']
				)
			);
		}

		$options = array(
			'temperature' => $temperature,
			'json'        => is_array( $response_format ),
			'base'        => $base,
		);

		$last_error = null;

		foreach ( $order as $id ) {
			if ( ! isset( $keys[ $id ] ) ) {
				continue;
			}

			$request = WPNC_AI_Providers::build_request( $slug, $keys[ $id ], $model, $messages, $options );

			$response = wp_remote_post(
				$request['url'],
				array(
					'headers' => $request['headers'],
					'body'    => $request['body'],
					'timeout' => WPNC_Settings::get_timeout( 45 ),
				)
			);

			if ( is_wp_error( $response ) ) {
				// A transport failure is the network, not the key, so do not
				// stand the key down for it.
				$last_error = $response;
				continue;
			}

			$status = wp_remote_retrieve_response_code( $response );
			$data   = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $status ) {
				$content = WPNC_AI_Providers::read_content( $slug, $data );

				if ( '' === trim( $content ) ) {
					$last_error = new WP_Error(
						'wpnc_ai_empty',
						sprintf(
							/* translators: %s: provider name */
							wpnc__( '%s returned an empty response.', '%s پاسخ خالی برگرداند.' ),
							$provider['label']
						)
					);
					continue;
				}

				WPNC_AI_Keys::mark_working( $slug, $id );

				return $content;
			}

			$detail = WPNC_AI_Providers::read_error( $slug, $data );

			// Not a key problem: the provider is refusing this server, and
			// every remaining key would be refused identically. Say so, and
			// leave the pool alone.
			if ( WPNC_AI_Providers::is_location_block( $status, $detail ) ) {
				return new WP_Error(
					'wpnc_ai_location_blocked',
					sprintf(
						/* translators: 1: provider name, 2: provider message */
						wpnc__(
							'%1$s will not accept requests from this server (%2$s). Your keys are fine - the address is the problem. Point this provider at a reachable endpoint using Base URL under Settings, or choose one that answers from here.',
							'%1$s درخواست‌های این سرور را نمی‌پذیرد (%2$s). کلیدها سالم‌اند؛ مشکل از آدرس است. در تنظیمات، Base URL این ارائه‌دهنده را به یک درگاه در دسترس تغییر دهید یا ارائه‌دهنده‌ای انتخاب کنید که از اینجا پاسخ می‌دهد.'
						),
						$provider['label'],
						'' !== $detail ? $detail : sprintf( 'HTTP %d', $status )
					)
				);
			}

			$message = sprintf(
				/* translators: 1: provider name, 2: HTTP status, 3: provider message */
				wpnc__( '%1$s returned HTTP %2$d: %3$s', '%1$s کد HTTP %2$d برگرداند: %3$s' ),
				$provider['label'],
				$status,
				'' !== $detail ? $detail : wpnc__( 'no further detail', 'بدون توضیح بیشتر' )
			);

			$last_error = new WP_Error( 'wpnc_ai_http_error', $message );

			if ( WPNC_AI_Providers::should_rotate( $status, $detail ) ) {
				WPNC_AI_Keys::mark_resting( $slug, $id, $detail );
				continue;
			}

			// A request the provider rejected on its merits would be rejected
			// the same way by every other key, so stop here.
			return $last_error;
		}

		if ( $last_error instanceof WP_Error ) {
			return new WP_Error(
				$last_error->get_error_code(),
				sprintf(
					/* translators: 1: key count, 2: provider name, 3: last error */
					wpnc__(
						'All %1$d keys for %2$s failed. Last error: %3$s',
						'هر %1$d کلید %2$s ناموفق بود. آخرین خطا: %3$s'
					),
					count( $order ),
					$provider['label'],
					$last_error->get_error_message()
				)
			);
		}

		return new WP_Error(
			'wpnc_ai_failed',
			wpnc__( 'The assistant could not be reached.', 'دسترسی به دستیار ممکن نشد.' )
		);
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
