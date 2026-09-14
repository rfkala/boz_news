<?php
/**
 * Telling the administrator when something needs them.
 *
 * A source that dies, or an AI pool that runs dry, used to be discoverable
 * only by opening Logs & Tools and happening to look. These go to the
 * administrator's own chat through a bot that is already set up - never to
 * the channel readers see, which the settings refuse outright.
 *
 * The throttle decision is pure, so it is testable; sending is not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Alerts {

	/**
	 * Option holding when each alert was last sent: { key: timestamp }.
	 */
	const STATE = 'wpnc_alert_state';

	/**
	 * The same alert is not repeated more often than this.
	 *
	 * A feed that is down stays down for hours, and an alert every fetch run
	 * would be an alert every fifteen minutes - which is how alerts get muted.
	 */
	const COOLDOWN = 6 * HOUR_IN_SECONDS;

	/**
	 * Where alerts go, if they are on.
	 *
	 * @return array|null { slug, chat_id }, or null when alerts are off.
	 */
	public static function destination() {
		$slug = sanitize_key( (string) get_option( 'wpnc_alert_channel', '' ) );
		$chat = trim( (string) get_option( 'wpnc_alert_chat_id', '' ) );

		if ( '' === $slug || '' === $chat ) {
			return null;
		}

		$channel = WPNC_Channels::get( $slug );

		if ( empty( $channel ) || 'bot' !== $channel['kind'] ) {
			return null;
		}

		return array(
			'slug'    => $slug,
			'chat_id' => $chat,
		);
	}

	/**
	 * Whether an alert may go out now.
	 *
	 * @param array  $state    Last-sent times.
	 * @param string $key      Alert key.
	 * @param int    $now      Current timestamp.
	 * @param int    $cooldown Seconds between repeats.
	 * @return bool
	 */
	public static function due( $state, $key, $now, $cooldown = self::COOLDOWN ) {
		$last = ( is_array( $state ) && isset( $state[ $key ] ) ) ? absint( $state[ $key ] ) : 0;

		return 0 === $last || ( absint( $now ) - $last ) >= absint( $cooldown );
	}

	/**
	 * Send an alert, unless the same one went out recently.
	 *
	 * @param string $key     What the alert is about, for the throttle.
	 * @param string $message Text.
	 * @param bool   $force   Ignore the throttle, for a test.
	 * @return true|false|WP_Error False when throttled.
	 */
	public static function send( $key, $message, $force = false ) {
		$to = self::destination();

		if ( null === $to ) {
			return new WP_Error(
				'wpnc_alerts_off',
				wpnc__(
					'Alerts are off. Choose a service and your own chat ID under Settings, then save.',
					'هشدارها خاموش‌اند. در تنظیمات، سرویس و شناسهٔ گفتگوی خودتان را انتخاب و ذخیره کنید.'
				)
			);
		}

		$state = self::state();
		$now   = WPNC_Time::timestamp();

		if ( ! $force && ! self::due( $state, $key, $now ) ) {
			return false;
		}

		$messenger = new WPNC_Messenger();
		$result    = $messenger->send_text( $to['slug'], self::compose( $message ), $to['chat_id'] );

		if ( true === $result ) {
			$state[ $key ] = $now;
			update_option( self::STATE, $state, false );
		}

		return $result;
	}

	/**
	 * Clear an alert's throttle, so the next occurrence is reported at once.
	 *
	 * @param string $key Alert key.
	 */
	public static function forget( $key ) {
		$state = self::state();

		if ( isset( $state[ $key ] ) ) {
			unset( $state[ $key ] );
			update_option( self::STATE, $state, false );
		}
	}

	/**
	 * A source has just been paused after repeated failures.
	 *
	 * @param string $source_id Stable source id.
	 * @param string $url       Feed URL.
	 * @param string $error     Last error.
	 */
	public static function source_down( $source_id, $url, $error ) {
		self::send(
			'source_down:' . $source_id,
			sprintf(
				/* translators: 1: feed URL, 2: error message */
				wpnc__(
					'A source stopped responding and has been paused: %1$s - %2$s',
					'منبعی پاسخ نداد و موقتاً متوقف شد: %1$s - %2$s'
				),
				$url,
				$error
			)
		);
	}

	/**
	 * A paused source has answered again.
	 *
	 * @param string $source_id Stable source id.
	 * @param string $url       Feed URL.
	 */
	public static function source_up( $source_id, $url ) {
		// Cleared, so that if it fails again tonight the alert is not held
		// back by the one sent this morning.
		self::forget( 'source_down:' . $source_id );

		self::send(
			'source_up:' . $source_id,
			sprintf(
				/* translators: %s: feed URL */
				wpnc__( 'A paused source is responding again: %s', 'منبع متوقف‌شده دوباره پاسخ می‌دهد: %s' ),
				$url
			)
		);
	}

	/**
	 * Every key for a provider is resting.
	 *
	 * @param string $slug Provider slug.
	 */
	public static function pool_exhausted( $slug ) {
		$provider = WPNC_AI_Providers::get( $slug );

		self::send(
			'ai_exhausted:' . sanitize_key( $slug ),
			sprintf(
				/* translators: %s: provider name */
				wpnc__(
					'Every API key for %s is resting after failures, so the assistant will not work until one recovers or a new key is added under Settings.',
					'همهٔ کلیدهای API برای %s پس از خطا کنار گذاشته شده‌اند؛ تا یکی بازنگردد یا کلید تازه‌ای در تنظیمات اضافه نشود، دستیار کار نمی‌کند.'
				),
				$provider['label']
			)
		);
	}

	/**
	 * Prefix a message with the site it is about.
	 *
	 * Someone running more than one site reads these in one chat.
	 *
	 * @param string $message Text.
	 * @return string
	 */
	public static function compose( $message ) {
		$site = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		return ( '' !== $site ? '[' . $site . '] ' : '' ) . trim( (string) $message );
	}

	/**
	 * Last-sent times.
	 *
	 * @return array
	 */
	private static function state() {
		$state = get_option( self::STATE, array() );

		return is_array( $state ) ? $state : array();
	}
}
