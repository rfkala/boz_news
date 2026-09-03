<?php
/**
 * AI provider adapters.
 *
 * Four providers with four different wire formats. Everything here is pure:
 * building a request body, reading a response, and classifying an error are
 * decided from arguments alone, so they are unit testable without a network.
 * The actual HTTP call lives in WPNC_AI_Rewriter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_AI_Providers {

	/**
	 * Every provider the plugin can talk to.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'openai' => array(
				'label'         => 'OpenAI',
				'endpoint'      => 'https://api.openai.com/v1/chat/completions',
				'default_model' => 'gpt-4o-mini',
				'keys_url'      => 'https://platform.openai.com/api-keys',
			),
			'groq'   => array(
				'label'         => 'Groq',
				'endpoint'      => 'https://api.groq.com/openai/v1/chat/completions',
				'default_model' => 'llama-3.3-70b-versatile',
				'keys_url'      => 'https://console.groq.com/keys',
			),
			'gemini' => array(
				'label'         => 'Google Gemini',
				'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'default_model' => 'gemini-2.0-flash',
				'keys_url'      => 'https://aistudio.google.com/app/apikey',
			),
			'claude' => array(
				'label'         => 'Anthropic Claude',
				'endpoint'      => 'https://api.anthropic.com/v1/messages',
				'default_model' => 'claude-sonnet-4-5',
				'keys_url'      => 'https://console.anthropic.com/settings/keys',
			),
		);
	}

	/**
	 * Provider keys only.
	 *
	 * @return array
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Whether a slug names a provider this plugin knows.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return array_key_exists( (string) $slug, self::all() );
	}

	/**
	 * One provider's definition, falling back to OpenAI.
	 *
	 * @param string $slug Provider slug.
	 * @return array
	 */
	public static function get( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : $all['openai'];
	}

	/**
	 * Build the HTTP request for a chat completion.
	 *
	 * @param string $slug     Provider slug.
	 * @param string $key      API key.
	 * @param string $model    Model name.
	 * @param array  $messages Messages as role/content pairs.
	 * @param array  $options  temperature, max_tokens, json.
	 * @return array { url, headers, body } - body is already JSON encoded.
	 */
	public static function build_request( $slug, $key, $model, $messages, $options = array() ) {
		$provider    = self::get( $slug );
		$temperature = isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.5;
		$max_tokens  = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 4096;
		$want_json   = ! empty( $options['json'] );

		$url     = str_replace( '{model}', rawurlencode( $model ), $provider['endpoint'] );
		$headers = array( 'Content-Type' => 'application/json' );

		if ( 'gemini' === $slug ) {
			// Gemini separates the system instruction from the turns, and
			// carries the key in the query string rather than a header.
			$system   = '';
			$contents = array();

			foreach ( $messages as $message ) {
				if ( 'system' === $message['role'] ) {
					$system .= ( '' === $system ? '' : "\n\n" ) . $message['content'];
					continue;
				}

				$contents[] = array(
					'role'  => 'assistant' === $message['role'] ? 'model' : 'user',
					'parts' => array( array( 'text' => $message['content'] ) ),
				);
			}

			$body = array(
				'contents'         => $contents,
				'generationConfig' => array(
					'temperature'     => $temperature,
					'maxOutputTokens' => $max_tokens,
				),
			);

			if ( '' !== $system ) {
				$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
			}

			if ( $want_json ) {
				$body['generationConfig']['responseMimeType'] = 'application/json';
			}

			return array(
				'url'     => add_query_arg( 'key', rawurlencode( $key ), $url ),
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			);
		}

		if ( 'claude' === $slug ) {
			// Anthropic takes the system prompt as a top-level field and
			// requires an explicit max_tokens and a version header.
			$system = '';
			$turns  = array();

			foreach ( $messages as $message ) {
				if ( 'system' === $message['role'] ) {
					$system .= ( '' === $system ? '' : "\n\n" ) . $message['content'];
					continue;
				}

				$turns[] = array(
					'role'    => 'assistant' === $message['role'] ? 'assistant' : 'user',
					'content' => $message['content'],
				);
			}

			$body = array(
				'model'       => $model,
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
				'messages'    => $turns,
			);

			if ( '' !== $system ) {
				$body['system'] = $system;
			}

			$headers['x-api-key']         = $key;
			$headers['anthropic-version'] = '2023-06-01';

			return array(
				'url'     => $url,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			);
		}

		// OpenAI, and Groq which mirrors its API.
		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $temperature,
		);

		if ( $want_json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$headers['Authorization'] = 'Bearer ' . $key;

		return array(
			'url'     => $url,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * Pull the assistant's text out of a decoded response body.
	 *
	 * @param string $slug Provider slug.
	 * @param array  $data Decoded JSON body.
	 * @return string Empty when the shape was not what we expect.
	 */
	public static function read_content( $slug, $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		if ( 'gemini' === $slug ) {
			if ( ! isset( $data['candidates'][0]['content']['parts'] ) || ! is_array( $data['candidates'][0]['content']['parts'] ) ) {
				return '';
			}

			$text = '';
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}

			return $text;
		}

		if ( 'claude' === $slug ) {
			if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
				return '';
			}

			$text = '';
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}

			return $text;
		}

		return isset( $data['choices'][0]['message']['content'] )
			? (string) $data['choices'][0]['message']['content']
			: '';
	}

	/**
	 * The provider's own explanation of a failure.
	 *
	 * Passing this through is the difference between a message an admin can
	 * act on and the bare status code they used to get.
	 *
	 * @param string $slug Provider slug.
	 * @param array  $data Decoded JSON body.
	 * @return string
	 */
	public static function read_error( $slug, $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		// All four nest the human-readable text under error.message, though
		// Gemini also uses error.status.
		if ( isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}

		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}

		if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}

		return '';
	}

	/**
	 * Decide whether another key would do better than this one.
	 *
	 * Quota and rate limits are the whole reason the key pool exists, and a
	 * revoked or mistyped key should also step aside rather than failing the
	 * whole request. A 400 is the caller's fault and rotating would just
	 * burn every key on the same bad request.
	 *
	 * @param int    $status HTTP status.
	 * @param string $detail Provider message, lowercased internally.
	 * @return bool
	 */
	public static function should_rotate( $status, $detail = '' ) {
		$status = absint( $status );

		if ( in_array( $status, array( 401, 402, 403, 429 ), true ) ) {
			return true;
		}

		// Some providers answer 400 for an exhausted or disabled key.
		if ( 400 === $status && '' !== $detail ) {
			$detail = strtolower( $detail );
			foreach ( array( 'quota', 'credit', 'billing', 'exhaust', 'insufficient', 'expired', 'suspended' ) as $needle ) {
				if ( false !== strpos( $detail, $needle ) ) {
					return true;
				}
			}
		}

		// Upstream is having a bad time; another key on the same provider is
		// unlikely to help, but it costs one attempt to find out.
		return in_array( $status, array( 500, 502, 503, 529 ), true );
	}

	/**
	 * Show enough of a key to recognise it, and no more.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask( $key ) {
		$key = trim( (string) $key );
		$len = strlen( $key );

		if ( 0 === $len ) {
			return '';
		}

		if ( $len <= 10 ) {
			return str_repeat( '*', $len );
		}

		return substr( $key, 0, 4 ) . str_repeat( '*', 6 ) . substr( $key, -4 );
	}
}
