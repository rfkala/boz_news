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
	public function send( $slug, $title, $link = '' ) {
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

		return $this->call(
			$slug,
			'sendMessage',
			array(
				'chat_id'                  => $credentials['chat_id'],
				'text'                     => self::compose( $title, $link ),
				'disable_web_page_preview' => false,
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
