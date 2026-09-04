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
				'base'          => 'https://api.openai.com/v1',
				'path'          => '/chat/completions',
				'default_model' => 'gpt-4o-mini',
				'keys_url'      => 'https://platform.openai.com/api-keys',
			),
			'groq'   => array(
				'label'         => 'Groq',
				'base'          => 'https://api.groq.com/openai/v1',
				'path'          => '/chat/completions',
				'default_model' => 'llama-3.3-70b-versatile',
				'keys_url'      => 'https://console.groq.com/keys',
			),
			'gemini' => array(
				'label'         => 'Google Gemini',
				'base'          => 'https://generativelanguage.googleapis.com/v1beta',
				'path'          => '/models/{model}:generateContent',
				'default_model' => 'gemini-2.0-flash',
				'keys_url'      => 'https://aistudio.google.com/app/apikey',
			),
			'claude' => array(
				'label'         => 'Anthropic Claude',
				'base'          => 'https://api.anthropic.com/v1',
				'path'          => '/messages',
				'default_model' => 'claude-sonnet-4-5',
				'keys_url'      => 'https://console.anthropic.com/settings/keys',
			),
			// A reseller that fronts the other providers under its own
			// address, in the OpenAI format, so it needs no adapter of its
			// own - only an entry.
			//
			// The documented host is api.gapgpt.app. That one is deliberately
			// NOT the default: TCP connects to it fine and then the TLS
			// handshake never completes, which is what SNI filtering looks
			// like rather than an outage. The alternate host, which sits
			// behind a CDN, answered 401 in 0.5-1.2s across five consecutive
			// attempts with a well-formed {"error":{"message":...}} body -
			// the shape read_error() already parses. Verified 2026-09-04 over
			// IPv4.
			//
			// Over IPv4 specifically: the test machine had blackholed IPv6,
			// which made both hosts look dead until the address family was
			// forced. If this ever appears to stop working, check that before
			// concluding the host is blocked.
			//
			// If you are tempted to "correct" this to the documented host,
			// check that it completes a TLS handshake from the server first.
			'gapgpt' => array(
				'label'         => 'GapGPT',
				'base'          => 'https://api.gapapi.com/v1',
				'path'          => '/chat/completions',
				'default_model' => 'gpt-4o-mini',
				'keys_url'      => 'https://gapgpt.app/platform-v2/docs/quickstart',
			),
			// Anything that speaks the OpenAI chat-completions format: a
			// self-hosted model, a gateway, a reseller. It has no default
			// base because there is nothing sensible to guess.
			'custom' => array(
				'label'         => wpnc__( 'OpenAI-compatible endpoint', 'درگاه سازگار با OpenAI' ),
				'base'          => '',
				'path'          => '/chat/completions',
				'default_model' => '',
				'keys_url'      => '',
			),
		);
	}

	/**
	 * The URL a request goes to.
	 *
	 * The base is separated from the path so it can be overridden: a provider
	 * that refuses the server's requests outright - a whole region blocked,
	 * an egress firewall - is not reachable at its own address no matter how
	 * many keys are tried, and pointing the same wire format at a gateway,
	 * a reseller or a locally hosted model is the only thing that helps.
	 *
	 * @param string $slug     Provider slug.
	 * @param string $base     Base URL override, empty to use the default.
	 * @param string $model    Model name, substituted into providers that
	 *                         carry it in the path.
	 * @return string Empty when nothing supplies a base.
	 */
	public static function endpoint( $slug, $base = '', $model = '' ) {
		$provider = self::get( $slug );
		$base     = trim( (string) $base );

		if ( '' === $base ) {
			$base = $provider['base'];
		}

		if ( '' === $base ) {
			return '';
		}

		$url = rtrim( $base, '/' ) . $provider['path'];

		return str_replace( '{model}', rawurlencode( $model ), $url );
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
	 * @param array  $options  temperature, max_tokens, json, base.
	 * @return array { url, headers, body } - body is already JSON encoded.
	 */
	public static function build_request( $slug, $key, $model, $messages, $options = array() ) {
		$temperature = isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.5;
		$max_tokens  = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 4096;
		$want_json   = ! empty( $options['json'] );
		$base        = isset( $options['base'] ) ? $options['base'] : '';

		$url     = self::endpoint( $slug, $base, $model );
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
	 * How long a transport error says it waited, in seconds.
	 *
	 * cURL puts the real figure in its message ("Operation timed out after
	 * 12002 milliseconds"), and that number is worth reading rather than
	 * discarding: compared against the timeout that was actually asked for,
	 * it is the difference between "the model is slow" and "something on this
	 * server is cutting outbound requests short", which need opposite fixes.
	 *
	 * @param string $message Transport error message.
	 * @return float Seconds waited, or 0.0 when this was not a timeout.
	 */
	public static function timeout_seconds( $message ) {
		$message = strtolower( (string) $message );

		if ( false === strpos( $message, 'timed out' ) && false === strpos( $message, 'timeout' ) ) {
			return 0.0;
		}

		if ( preg_match( '/after\s+([0-9]+(?:\.[0-9]+)?)\s*milliseconds/', $message, $m ) ) {
			return round( ( (float) $m[1] ) / 1000, 3 );
		}

		if ( preg_match( '/after\s+([0-9]+(?:\.[0-9]+)?)\s*seconds/', $message, $m ) ) {
			return (float) $m[1];
		}

		// A timeout whose duration we cannot read is still a timeout; -1
		// says so without inventing a figure.
		return -1.0;
	}

	/**
	 * Whether the provider refused the request because of where it came from.
	 *
	 * This is the difference between "your key is spent" and "we do not serve
	 * this address", and the plugin used to report the second as the first:
	 * every key was tried, every key was stood down for half an hour, and the
	 * message blamed the keys. The keys were fine.
	 *
	 * The wording identifies it rather than the status code, because the four
	 * providers disagree on the code - a 403 from one, a 400 from another -
	 * but all of them name the location in the message.
	 *
	 * @param int    $status HTTP status.
	 * @param string $detail Provider message.
	 * @return bool
	 */
	public static function is_location_block( $status, $detail = '' ) {
		$status = absint( $status );
		$detail = strtolower( trim( (string) $detail ) );

		// Explicit wording counts whatever the status is: Gemini answers 400
		// for this while the others answer 403.
		$phrases = array(
			'country, region, or territory',
			'unsupported_country',
			'user location is not supported',
			'location is not supported',
			'not available in your',
			'not supported in your',
			'region is not supported',
			'unavailable in your region',
		);

		foreach ( $phrases as $phrase ) {
			if ( '' !== $detail && false !== strpos( $detail, $phrase ) ) {
				return true;
			}
		}

		if ( 403 !== $status ) {
			return false;
		}

		// A 403 that names the credential or the account is a key problem,
		// and the next key may well work. Everything else is about the
		// caller rather than the credential - including the bare "Forbidden"
		// an edge network returns before the API is reached at all, which is
		// the shape this arrives in most often and the one that used to burn
		// the whole pool.
		$key_causes = array( 'key', 'token', 'quota', 'credit', 'billing', 'permission', 'scope', 'model', 'organization', 'suspended', 'deactivated' );

		foreach ( $key_causes as $needle ) {
			if ( '' !== $detail && false !== strpos( $detail, $needle ) ) {
				return false;
			}
		}

		return true;
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

		// A refusal aimed at where the request came from will be repeated for
		// every key. Rotating through them proves nothing and stands the whole
		// pool down for half an hour, so the pool is still asleep once the
		// routing is fixed.
		if ( self::is_location_block( $status, $detail ) ) {
			return false;
		}

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
