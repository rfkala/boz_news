<?php
/**
 * Sending to the bot channels.
 *
 * Telegram and Bale expose the same bot API - /bot<token>/<method>, form
 * encoded, answering {"ok":bool,"description":string} - so one transport
 * serves both and the only difference is the host.
 *
 * Replaces WPNC_Telegram, which hardcoded one host and fired unconditionally
 * from inside the publisher.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Messenger {

	/**
	 * The layout a channel post takes when no template has been set.
	 */
	const DEFAULT_CAPTION = "{title}\n\n{summary}\n\n{link}\n\n{hashtags}";

	/**
	 * The longest caption either service accepts under a photo.
	 */
	const PHOTO_CAPTION_LIMIT = 1024;

	/**
	 * The longest text message either service accepts.
	 */
	const TEXT_LIMIT = 4096;

	/**
	 * Comma separated tags as hashtags a reader can actually tap.
	 *
	 * A hashtag ends at the first space or punctuation mark, so a two-word tag
	 * posted as-is links only its first word. The zero-width non-joiner that
	 * Persian spelling puts inside words breaks a hashtag the same way, and so
	 * does a Persian comma used as the separator.
	 *
	 * @param string $tags Comma separated tags.
	 * @return string Space separated hashtags.
	 */
	public static function hashtags( $tags ) {
		$out = array();

		foreach ( preg_split( '/[,\x{060C}]/u', (string) $tags ) as $tag ) {
			$tag = trim( wp_strip_all_tags( (string) $tag ) );
			$tag = preg_replace( '/[\s\x{200C}\x{200D}\-]+/u', '_', $tag );
			$tag = preg_replace( '/[^\p{L}\p{N}_]+/u', '', (string) $tag );
			$tag = trim( (string) $tag, '_' );

			// Digits alone are not linked as a hashtag by either service.
			if ( '' === $tag || preg_match( '/^[\p{N}_]+$/u', $tag ) ) {
				continue;
			}

			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag, 'UTF-8' ) : strtolower( $tag );

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = '#' . $tag;
			}
		}

		return implode( ' ', array_values( $out ) );
	}

	/**
	 * A channel post, rendered from its template and held to a length.
	 *
	 * The summary gives way first when it is too long, and the link is never
	 * the part that gets cut: a post whose link was trimmed away sends every
	 * reader nowhere.
	 *
	 * @param string $template Template, or empty for the default layout.
	 * @param array  $context  title, summary, link, tags, source.
	 * @param int    $limit    Longest result allowed, in characters.
	 * @return string
	 */
	public static function render_caption( $template, $context, $limit = self::TEXT_LIMIT ) {
		$template = trim( (string) $template );
		$template = '' !== $template ? $template : self::DEFAULT_CAPTION;
		$context  = array_merge(
			array(
				'title'   => '',
				'summary' => '',
				'link'    => '',
				'tags'    => '',
				'source'  => '',
			),
			(array) $context
		);

		$values = array(
			'title'    => trim( wp_strip_all_tags( (string) $context['title'] ) ),
			'summary'  => trim( wp_strip_all_tags( (string) $context['summary'] ) ),
			'link'     => trim( (string) $context['link'] ),
			'hashtags' => self::hashtags( $context['tags'] ),
			'source'   => trim( wp_strip_all_tags( (string) $context['source'] ) ),
		);

		$limit = max( 64, absint( $limit ) );
		$text  = self::fill( $template, $values );
		$over  = self::length( $text ) - $limit;

		if ( $over > 0 && '' !== $values['summary'] ) {
			$keep              = max( 0, self::length( $values['summary'] ) - $over - 1 );
			$values['summary'] = rtrim( self::cut( $values['summary'], $keep ) ) . '…';
			$text              = self::fill( $template, $values );
		}

		if ( self::length( $text ) > $limit ) {
			// Still too long, which only an enormous headline does. The words
			// are cut and the link is put back after them.
			$tail           = '' !== $values['link'] ? "\n\n" . $values['link'] : '';
			$values['link'] = '';
			$body           = self::fill( $template, $values );
			$text           = rtrim( self::cut( $body, $limit - self::length( $tail ) - 1 ) ) . '…' . $tail;
		}

		return '' === $text ? self::compose( $values['title'], $values['link'] ) : $text;
	}

	/**
	 * Substitute the placeholders and tidy what empty ones leave behind.
	 *
	 * @param string $template Template.
	 * @param array  $values   Placeholder values.
	 * @return string
	 */
	private static function fill( $template, $values ) {
		foreach ( $values as $key => $value ) {
			$template = str_replace( '{' . $key . '}', (string) $value, $template );
		}

		$lines = array_map( 'rtrim', preg_split( '/\r\n|\r|\n/', $template ) );
		$text  = preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) );

		return trim( (string) $text );
	}

	/**
	 * Length in characters, not bytes: a Persian letter is two bytes.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * The first characters of a text, without splitting a letter in two.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters to keep.
	 * @return string
	 */
	private static function cut( $text, $length ) {
		$length = max( 0, (int) $length );

		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length, 'UTF-8' ) : substr( (string) $text, 0, $length );
	}

	/**
	 * Build the URL for one bot API call.
	 *
	 * @param string $slug   Channel slug.
	 * @param string $method API method, e.g. sendMessage.
	 * @param string $token  Bot token.
	 * @return string Empty when the channel has no API.
	 */
	public static function api_url( $slug, $method, $token ) {
		$channel = WPNC_Channels::get( $slug );

		if ( empty( $channel ) || '' === $channel['api'] ) {
			return '';
		}

		return rtrim( $channel['api'], '/' ) . '/bot' . $token . '/' . $method;
	}

	/**
	 * Read the outcome of a bot API response.
	 *
	 * Both services answer 200 with ok:false for an application-level
	 * refusal, so the HTTP status alone is not the answer.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $data   Decoded body.
	 * @return true|string True on success, else the reason.
	 */
	public static function read_result( $status, $data ) {
		if ( is_array( $data ) && isset( $data['ok'] ) ) {
			if ( $data['ok'] ) {
				return true;
			}

			return isset( $data['description'] ) && '' !== $data['description']
				? (string) $data['description']
				: sprintf( 'HTTP %d', absint( $status ) );
		}

		$status = absint( $status );

		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		return sprintf( 'HTTP %d', $status );
	}

	/**
	 * The message body sent to a channel.
	 *
	 * @param string $title Headline.
	 * @param string $link  Link to include, already a URL or empty.
	 * @return string
	 */
	public static function compose( $title, $link ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		$link  = trim( (string) $link );

		if ( '' === $link ) {
			return $title;
		}

		return '' === $title ? $link : $title . "\n\n" . $link;
	}

	/**
	 * Send one message.
	 *
	 * @param string $slug  Channel slug.
	 * @param string $title Headline.
	 * @param string $link  Link to include.
	 * @return true|WP_Error
	 */
	public function send( $slug, $title, $link = '', $context = array() ) {
		$credentials = WPNC_Channels::credentials( $slug );

		if ( '' === $credentials['token'] || '' === $credentials['chat_id'] ) {
			return new WP_Error(
				'wpnc_channel_not_configured',
				sprintf(
					/* translators: %s: channel name */
					wpnc__( '%s has no bot token or chat id set.', 'برای %s توکن ربات یا شناسه گفتگو تنظیم نشده است.' ),
					self::label( $slug )
				)
			);
		}

		$context  = array_merge( (array) $context, array( 'title' => $title, 'link' => $link ) );
		$template = (string) get_option( WPNC_Channels::option( $slug, 'caption' ), '' );
		$image    = isset( $context['image'] ) ? trim( (string) $context['image'] ) : '';
		$photo_on = '0' !== (string) get_option( WPNC_Channels::option( $slug, 'photo' ), '1' );

		if ( $photo_on && '' !== $image ) {
			$sent = $this->call(
				$slug,
				'sendPhoto',
				array(
					'chat_id' => $credentials['chat_id'],
					'photo'   => $image,
					'caption' => self::render_caption( $template, $context, self::PHOTO_CAPTION_LIMIT ),
				)
			);

			if ( true === $sent ) {
				return true;
			}

			// The service fetches the picture itself, and a host it cannot
			// reach should not cost the reader the news: the same words go out
			// as a text message instead.
		}

		return $this->call(
			$slug,
			'sendMessage',
			array(
				'chat_id'                  => $credentials['chat_id'],
				'text'                     => self::render_caption( $template, $context, self::TEXT_LIMIT ),
				'disable_web_page_preview' => false,
			)
		);
	}

	/**
	 * Send plain text to a chat other than the channel's own.
	 *
	 * Used for alerts, which go to the administrator rather than to readers,
	 * through the same bot.
	 *
	 * @param string $slug    Channel slug.
	 * @param string $text    Message.
	 * @param string $chat_id Chat to send to.
	 * @return true|WP_Error
	 */
	public function send_text( $slug, $text, $chat_id ) {
		$credentials = WPNC_Channels::credentials( $slug );
		$chat_id     = trim( (string) $chat_id );

		if ( '' === $credentials['token'] || '' === $chat_id ) {
			return new WP_Error(
				'wpnc_channel_not_configured',
				sprintf(
					/* translators: %s: channel name */
					wpnc__( '%s has no bot token or chat id set.', 'برای %s توکن ربات یا شناسه گفتگو تنظیم نشده است.' ),
					self::label( $slug )
				)
			);
		}

		return $this->call(
			$slug,
			'sendMessage',
			array(
				'chat_id' => $chat_id,
				'text'    => self::cut( trim( (string) $text ), self::TEXT_LIMIT ),
			)
		);
	}

	/**
	 * Check that the credentials work, without sending anything to readers.
	 *
	 * getMe proves the token; it does not prove the chat id, so the chat is
	 * checked separately. A token that works with a chat id the bot cannot
	 * post to would otherwise pass a test and fail every real send.
	 *
	 * @param string $slug Channel slug.
	 * @return true|WP_Error
	 */
	public function verify( $slug ) {
		$credentials = WPNC_Channels::credentials( $slug );

		if ( '' === $credentials['token'] || '' === $credentials['chat_id'] ) {
			return new WP_Error(
				'wpnc_channel_not_configured',
				sprintf(
					/* translators: %s: channel name */
					wpnc__(
						'Enter both a bot token and a chat id for %s first.',
						'ابتدا توکن ربات و شناسه گفتگو را برای %s وارد کنید.'
					),
					self::label( $slug )
				)
			);
		}

		$token = $this->call( $slug, 'getMe', array() );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return $this->call( $slug, 'getChat', array( 'chat_id' => $credentials['chat_id'] ) );
	}

	/**
	 * One bot API call.
	 *
	 * @param string $slug   Channel slug.
	 * @param string $method API method.
	 * @param array  $body   Form fields.
	 * @return true|WP_Error
	 */
	private function call( $slug, $method, $body ) {
		$credentials = WPNC_Channels::credentials( $slug );
		$url         = self::api_url( $slug, $method, $credentials['token'] );

		if ( '' === $url ) {
			return new WP_Error(
				'wpnc_channel_no_api',
				sprintf(
					/* translators: %s: channel name */
					wpnc__( '%s cannot be sent to.', 'ارسال به %s ممکن نیست.' ),
					self::label( $slug )
				)
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => WPNC_Settings::get_timeout( 20 ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			// The token is in the URL, and WordPress puts the URL in transport
			// errors. Sending that to the screen or the log would leak it.
			return new WP_Error(
				'wpnc_channel_unreachable',
				sprintf(
					/* translators: 1: channel name, 2: error */
					wpnc__( 'Could not reach %1$s: %2$s', 'دسترسی به %1$s ممکن نشد: %2$s' ),
					self::label( $slug ),
					self::redact( $response->get_error_message(), $credentials['token'] )
				)
			);
		}

		$result = self::read_result(
			wp_remote_retrieve_response_code( $response ),
			json_decode( wp_remote_retrieve_body( $response ), true )
		);

		if ( true === $result ) {
			return true;
		}

		return new WP_Error(
			'wpnc_channel_refused',
			sprintf(
				/* translators: 1: channel name, 2: reason */
				wpnc__( '%1$s refused the request: %2$s', '%1$s درخواست را نپذیرفت: %2$s' ),
				self::label( $slug ),
				self::redact( $result, $credentials['token'] )
			)
		);
	}

	/**
	 * Remove a bot token from text that is about to be shown or logged.
	 *
	 * @param string $text  Text.
	 * @param string $token Token to remove.
	 * @return string
	 */
	public static function redact( $text, $token ) {
		$text = (string) $text;

		if ( '' === trim( (string) $token ) ) {
			return $text;
		}

		return str_replace( $token, '***', $text );
	}

	/**
	 * A channel's display name.
	 *
	 * @param string $slug Channel slug.
	 * @return string
	 */
	private static function label( $slug ) {
		$channel = WPNC_Channels::get( $slug );

		return isset( $channel['label'] ) ? $channel['label'] : (string) $slug;
	}
}
