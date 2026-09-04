<?php
/**
 * Shared settings helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return string in the configured admin language (English or Persian).
 *
 * @param string $en English text.
 * @param string $fa Persian text.
 * @return string
 */
function wpnc__( $en, $fa = '' ) {
	if ( $fa && 'en' !== get_option( 'wpnc_admin_lang', 'fa' ) ) {
		return $fa;
	}
	return $en;
}

/**
 * Echo HTML-escaped string in the configured admin language.
 *
 * @param string $en English text.
 * @param string $fa Persian text.
 */
function wpnc_e( $en, $fa = '' ) {
	echo esc_html( wpnc__( $en, $fa ) );
}

class WPNC_Settings {

	/**
	 * Slug every settings notice is registered under.
	 */
	const NOTICE_SLUG = 'wpnc_settings';

	/**
	 * Register a bilingual settings notice.
	 *
	 * Before 1.3.0 nothing in this plugin called add_settings_error, so every
	 * rejected or clamped value was applied in silence.
	 *
	 * @param string $code Machine code.
	 * @param string $en   English message.
	 * @param string $fa   Persian message.
	 * @param string $type error|warning|success|info.
	 */
	public static function notify( $code, $en, $fa = '', $type = 'error' ) {
		add_settings_error( self::NOTICE_SLUG, $code, wpnc__( $en, $fa ), $type );
	}

	/**
	 * Allowed admin interface languages.
	 *
	 * @return array
	 */
	public static function languages() {
		return array( 'fa', 'en' );
	}

	/**
	 * Sanitize the admin language.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_language( $value ) {
		$value = sanitize_key( $value );

		if ( in_array( $value, self::languages(), true ) ) {
			return $value;
		}

		self::notify(
			'wpnc_bad_lang',
			'Unknown interface language; kept Persian.',
			'زبان رابط ناشناخته بود؛ فارسی نگه داشته شد.',
			'warning'
		);

		return 'fa';
	}

	/**
	 * Allowed post statuses for imported items.
	 *
	 * @return array
	 */
	public static function post_statuses() {
		return array( 'publish', 'draft', 'pending', 'private' );
	}

	/**
	 * Sanitize the post status setting.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_status( $value ) {
		$value = sanitize_key( $value );

		return in_array( $value, self::post_statuses(), true ) ? $value : 'publish';
	}

	/**
	 * Sanitize the post author setting.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_post_author( $value ) {
		$value = absint( $value );

		if ( $value && ! get_userdata( $value ) ) {
			self::notify(
				'wpnc_bad_author',
				'That author no longer exists; cleared the setting.',
				'آن نویسنده دیگر وجود ندارد؛ تنظیم پاک شد.',
				'warning'
			);

			return 0;
		}

		return $value;
	}

	/**
	 * Sanitize the default category, checking that it still exists.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_category( $value ) {
		$value = absint( $value );

		if ( $value && ! term_exists( $value, 'category' ) ) {
			self::notify(
				'wpnc_bad_category',
				'That category no longer exists; cleared the default category.',
				'آن دسته‌بندی دیگر وجود ندارد؛ دسته‌بندی پیش‌فرض پاک شد.',
				'warning'
			);

			return 0;
		}

		return $value;
	}

	/**
	 * Sanitize the fallback image URL.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_image_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value );
		if ( '' === $url ) {
			self::notify(
				'wpnc_bad_image',
				'The fallback image URL was not a valid URL and was discarded.',
				'آدرس تصویر پیش‌فرض معتبر نبود و ذخیره نشد.'
			);

			return '';
		}

		return $url;
	}

	/**
	 * Sanitize the pacing interval in minutes.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_stagger_minutes( $value ) {
		$value   = absint( $value );
		$clamped = max( 1, min( 1440, $value ) );

		if ( $value !== $clamped ) {
			self::notify(
				'wpnc_stagger_clamped',
				'Pacing must be between 1 minute and 24 hours; the value was adjusted.',
				'فاصله انتشار باید بین ۱ دقیقه تا ۲۴ ساعت باشد؛ مقدار اصلاح شد.',
				'warning'
			);
		}

		return $clamped;
	}

	/**
	 * Sanitize retention in days.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_retention( $value ) {
		$value   = absint( $value );
		$clamped = max( 1, min( 365, $value ) );

		if ( $value !== $clamped ) {
			self::notify(
				'wpnc_retention_clamped',
				'Retention must be between 1 and 365 days; the value was adjusted.',
				'بازه نگهداری باید بین ۱ تا ۳۶۵ روز باشد؛ مقدار اصلاح شد.',
				'warning'
			);
		}

		return $clamped;
	}

	/**
	 * Sanitize the chosen AI provider.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_ai_provider( $value ) {
		$value = sanitize_key( $value );

		if ( WPNC_AI_Providers::exists( $value ) ) {
			return $value;
		}

		self::notify(
			'wpnc_bad_provider',
			'Unknown AI provider; kept OpenAI.',
			'ارائه‌دهنده هوش مصنوعی ناشناخته بود؛ OpenAI نگه داشته شد.',
			'warning'
		);

		return 'openai';
	}

	/**
	 * Sanitize the per-provider model names.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	/**
	 * Per-provider Base URL overrides.
	 *
	 * http is allowed only for a loopback address, which is how a model
	 * running on the same machine is reached; anything leaving the server is
	 * required to be https, since the API key travels with it.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize_ai_base_urls( $value ) {
		$clean = array();

		if ( ! is_array( $value ) ) {
			return $clean;
		}

		foreach ( WPNC_AI_Providers::slugs() as $slug ) {
			$url = isset( $value[ $slug ] ) ? trim( (string) $value[ $slug ] ) : '';

			if ( '' === $url ) {
				continue;
			}

			$url  = esc_url_raw( $url, array( 'http', 'https' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( '' === $url || '' === $host ) {
				self::notify(
					'wpnc_ai_base_url_' . $slug,
					'That Base URL is not a valid address, so it was not saved.',
					'آن Base URL آدرس معتبری نیست و ذخیره نشد.'
				);
				continue;
			}

			$loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );

			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && ! $loopback ) {
				self::notify(
					'wpnc_ai_base_url_' . $slug,
					'A Base URL that leaves this server must use https - your API key travels with every request. It was not saved.',
					'آدرسی که از این سرور خارج می‌شود باید https باشد؛ کلید API با هر درخواست ارسال می‌گردد. ذخیره نشد.'
				);
				continue;
			}

			// Trailing slashes are the commonest way to end up with a double
			// slash in the path, and they carry no meaning here.
			$clean[ $slug ] = untrailingslashit( $url );
		}

		return $clean;
	}

	public static function sanitize_ai_models( $value ) {
		$clean = array();

		if ( ! is_array( $value ) ) {
			return $clean;
		}

		foreach ( WPNC_AI_Providers::slugs() as $slug ) {
			$model = isset( $value[ $slug ] ) ? sanitize_text_field( $value[ $slug ] ) : '';
			if ( '' !== $model ) {
				$clean[ $slug ] = $model;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize the API key pool.
	 *
	 * Rows are keyed by a stable id, not by position. An empty value means
	 * "keep the key already stored under this id", which is what lets the
	 * form show a mask instead of the secret. A row the browser did not
	 * submit was removed, so it is dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize_ai_keys( $value ) {
		$existing = WPNC_AI_Keys::all();
		$clean    = array();
		$added    = 0;

		if ( ! is_array( $value ) ) {
			return $existing;
		}

		foreach ( WPNC_AI_Providers::slugs() as $slug ) {
			$clean[ $slug ] = array();

			if ( ! isset( $value[ $slug ] ) || ! is_array( $value[ $slug ] ) ) {
				continue;
			}

			foreach ( $value[ $slug ] as $id => $submitted ) {
				$id        = sanitize_key( $id );
				$submitted = trim( (string) $submitted );

				if ( '' === $submitted ) {
					// Blank means unchanged, so carry the stored key over.
					if ( isset( $existing[ $slug ][ $id ] ) ) {
						$clean[ $slug ][ $id ] = $existing[ $slug ][ $id ];
					}
					continue;
				}

				// A key the browser rendered as a mask must never be saved
				// back as the literal mask.
				if ( false !== strpos( $submitted, '******' ) ) {
					if ( isset( $existing[ $slug ][ $id ] ) ) {
						$clean[ $slug ][ $id ] = $existing[ $slug ][ $id ];
					}
					continue;
				}

				$key = sanitize_text_field( $submitted );
				if ( '' === $key ) {
					continue;
				}

				// New rows arrive with a placeholder id from the browser.
				$store_id             = ( '' === $id || 0 === strpos( $id, 'new' ) ) ? WPNC_AI_Keys::new_id() : $id;
				$clean[ $slug ][ $store_id ] = $key;
				$added++;
			}
		}

		if ( $added > 0 ) {
			// A key that was replaced because it ran out should get another
			// chance immediately rather than waiting out its rest period.
			WPNC_AI_Keys::wake();
		}

		return $clean;
	}

	/**
	 * Allowed cron intervals.
	 *
	 * @return array
	 */
	public static function intervals() {
		return array( '15min', 'hourly', '3hours', 'twicedaily', 'daily' );
	}

	/**
	 * Sanitize interval setting.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_interval( $value ) {
		$value = sanitize_key( $value );

		if ( in_array( $value, self::intervals(), true ) ) {
			return $value;
		}

		self::notify(
			'wpnc_bad_interval',
			'Unknown update interval; fell back to hourly.',
			'بازه بروزرسانی نامعتبر بود؛ به «۱ ساعت» بازگردانده شد.',
			'warning'
		);

		return 'hourly';
	}

	/**
	 * Get configured target post type.
	 *
	 * @return string
	 */
	public static function get_target_post_type() {
		return self::sanitize_post_type( get_option( 'wpnc_target_post_type', 'post' ) );
	}

	/**
	 * Sanitize target post type.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_type( $value ) {
		$value   = sanitize_key( $value );
		$allowed = array( 'post', 'wpnc_news' );

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		self::notify(
			'wpnc_bad_post_type',
			'Unknown target post type; fell back to Standard Post.',
			'نوع پست هدف نامعتبر بود؛ به «پست معمولی» بازگردانده شد.',
			'warning'
		);

		return 'post';
	}

	/**
	 * Sanitize a checkbox.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Cap number of items fetched per source.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_max_items( $value ) {
		$value   = absint( $value );
		$clamped = max( 1, min( 100, $value ) );

		if ( $value !== $clamped ) {
			self::notify(
				'wpnc_max_items_clamped',
				'Items per feed must be between 1 and 100; the value was adjusted.',
				'حداکثر آیتم هر فید باید بین ۱ تا ۱۰۰ باشد؛ مقدار اصلاح شد.',
				'warning'
			);
		}

		return $clamped;
	}

	/**
	 * Default HTTP timeout in seconds.
	 */
	const DEFAULT_TIMEOUT = 8;

	/**
	 * Days a processed queue row is kept before it is deleted.
	 */
	const DEFAULT_QUEUE_RETENTION = 14;

	/**
	 * Days a log row is kept before it is deleted.
	 */
	const DEFAULT_LOG_RETENTION = 30;

	/**
	 * Configured queue retention in days.
	 *
	 * @return int
	 */
	public static function get_queue_retention() {
		return max( 1, min( 365, absint( get_option( 'wpnc_queue_retention_days', self::DEFAULT_QUEUE_RETENTION ) ) ) );
	}

	/**
	 * Configured log retention in days.
	 *
	 * @return int
	 */
	public static function get_log_retention() {
		return max( 1, min( 365, absint( get_option( 'wpnc_log_retention_days', self::DEFAULT_LOG_RETENTION ) ) ) );
	}

	/**
	 * Cap HTTP timeout.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_timeout( $value ) {
		$value   = absint( $value );
		$clamped = max( 3, min( 30, $value ) );

		if ( $value !== $clamped ) {
			self::notify(
				'wpnc_timeout_clamped',
				'HTTP timeout must be between 3 and 30 seconds; the value was adjusted.',
				'زمان‌انتظار HTTP باید بین ۳ تا ۳۰ ثانیه باشد؛ مقدار اصلاح شد.',
				'warning'
			);
		}

		return $clamped;
	}

	/**
	 * Configured HTTP timeout, optionally with extra headroom.
	 *
	 * One clamp for the whole plugin; the three call sites used to disagree
	 * about both the default and the allowed range.
	 *
	 * @param int $extra Seconds to add for slower endpoints such as OpenAI.
	 * @return int
	 */
	public static function get_timeout( $extra = 0 ) {
		$timeout = self::sanitize_timeout( get_option( 'wpnc_request_timeout', self::DEFAULT_TIMEOUT ) );

		return $timeout + max( 0, absint( $extra ) );
	}

	/**
	 * Preserve existing secrets when admin field is left empty.
	 *
	 * @param string $value Raw value.
	 * @param string $option Option name.
	 * @return string
	 */
	public static function sanitize_secret( $value, $option ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return (string) get_option( $option, '' );
		}

		if ( '__delete__' === $value ) {
			return '';
		}

		return sanitize_text_field( $value );
	}
}

/**
 * Time helpers.
 *
 * Every datetime this plugin writes to its own tables is UTC. Local time is
 * produced only at render time. Mixing the two is what made retention delete
 * rows off by the site's GMT offset before 1.3.0.
 */
class WPNC_Time {

	/**
	 * Current time as a UTC MySQL datetime.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Current UTC timestamp.
	 *
	 * @return int
	 */
	public static function timestamp() {
		return time();
	}

	/**
	 * Normalize an arbitrary datetime string to a UTC MySQL datetime.
	 *
	 * Falls back to now when the value is unparseable, which keeps rows
	 * insertable instead of failing on a malformed feed date.
	 *
	 * @param string $datetime Datetime string.
	 * @return string
	 */
	public static function to_utc( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime ) {
			return self::now();
		}

		$timestamp = strtotime( $datetime );

		return false === $timestamp ? self::now() : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * UTC MySQL datetime for a point N days in the past.
	 *
	 * @param int $days Days back.
	 * @return string
	 */
	public static function days_ago( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Render a stored UTC datetime in the site's timezone for display.
	 *
	 * @param string $datetime UTC MySQL datetime.
	 * @param string $format   Optional date format; site format when empty.
	 * @return string
	 */
	public static function for_display( $datetime, $format = '' ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		}

		$timestamp = strtotime( $datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date( $format, $timestamp );
	}

	/**
	 * Short axis label for a Y-m-d day, in the site's own calendar.
	 *
	 * wp_date() honours a Jalali locale, so Persian sites get Persian dates
	 * on the chart instead of Gregorian ones.
	 *
	 * @param string $day Y-m-d.
	 * @return string
	 */
	public static function day_label( $day ) {
		$timestamp = strtotime( $day . ' 12:00:00 UTC' );

		return false === $timestamp ? (string) $day : wp_date( 'j M', $timestamp );
	}

	/**
	 * Site GMT offset in seconds.
	 *
	 * @return int
	 */
	public static function offset_seconds() {
		return (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}
}
