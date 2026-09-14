<?php
/**
 * Proving what is wrong with outbound requests, from the server itself.
 *
 * "Something on this server is cutting requests short" is a claim, and the
 * plugin was in no position to make it: it saw one failed request and guessed.
 * This runs the experiment instead - the same request, with a timeout long
 * enough that being cut short is unambiguous, against the address that fails
 * and two that should not - and reports what actually happened.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Diagnostics {

	/**
	 * How long each probe is allowed.
	 *
	 * Long enough that a cut-off well below it proves a limit that is not
	 * ours, short enough that three of them do not outlast the page.
	 */
	const PROBE_TIMEOUT = 30;

	/**
	 * A probe that failed short of this fraction of its timeout was stopped
	 * by something other than the timeout it was given.
	 */
	const CAP_RATIO = 0.8;

	/**
	 * Run every probe and interpret the results.
	 *
	 * @return array
	 */
	public static function run() {
		$probes = array();

		$slug     = WPNC_AI_Rewriter::provider();
		$provider = WPNC_AI_Providers::get( $slug );
		$endpoint = WPNC_AI_Providers::endpoint( $slug, WPNC_AI_Rewriter::base_url( $slug ), WPNC_AI_Rewriter::model( $slug ) );

		if ( '' !== $endpoint ) {
			$probes[] = self::probe(
				'ai',
				sprintf(
					/* translators: %s: provider name */
					wpnc__( 'AI endpoint (%s)', 'درگاه هوش مصنوعی (%s)' ),
					$provider['label']
				),
				$endpoint
			);
		}

		// A host WordPress itself talks to on every install, so a failure here
		// is about this server rather than about one provider.
		$probes[] = self::probe(
			'control',
			wpnc__( 'WordPress.org', 'WordPress.org' ),
			'https://api.wordpress.org/core/version-check/1.7/'
		);

		// The site calling itself. It proves an outbound request can leave at
		// all, without depending on anything beyond the host.
		$probes[] = self::probe(
			'self',
			wpnc__( 'This site', 'همین سایت' ),
			home_url( '/' )
		);

		return array(
			'asked'      => self::PROBE_TIMEOUT,
			'ai_timeout' => WPNC_AI_Rewriter::timeout(),
			'php_limit'  => (int) ini_get( 'max_execution_time' ),
			'probes'     => $probes,
			'verdict'    => self::verdict( $probes, self::PROBE_TIMEOUT ),
		);
	}

	/**
	 * One request, timed.
	 *
	 * A GET with no credentials is enough: any HTTP status at all proves the
	 * address answered, and what it answered is beside the point here.
	 *
	 * @param string $key     Probe key.
	 * @param string $label   Human label.
	 * @param string $url     URL to request.
	 * @param int    $timeout Seconds to allow.
	 * @return array
	 */
	private static function probe( $key, $label, $url, $timeout = self::PROBE_TIMEOUT ) {
		$started = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => max( 1, absint( $timeout ) ),
				'redirection' => 2,
				'sslverify'   => true,
			)
		);

		$elapsed = round( microtime( true ) - $started, 2 );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();

			return array(
				'key'       => $key,
				'label'     => $label,
				'host'      => (string) wp_parse_url( $url, PHP_URL_HOST ),
				'ok'        => false,
				'status'    => 0,
				'timed_out' => WPNC_AI_Providers::timeout_seconds( $message ) !== 0.0,
				'error'     => $message,
				'elapsed'   => $elapsed,
			);
		}

		return array(
			'key'       => $key,
			'label'     => $label,
			'host'      => (string) wp_parse_url( $url, PHP_URL_HOST ),
			'ok'        => true,
			'status'    => (int) wp_remote_retrieve_response_code( $response ),
			'timed_out' => false,
			'error'     => '',
			'elapsed'   => $elapsed,
		);
	}

	/**
	 * How long each address in a sweep is given.
	 *
	 * Shorter than a single check on purpose: an address that works answers a
	 * bare GET in well under a second, so this only has to separate "answers"
	 * from "does not", and a dozen of them still has to finish inside the
	 * page.
	 */
	const SWEEP_TIMEOUT = 8;

	/**
	 * Addresses worth trying when the configured one does not answer.
	 *
	 * Every provider the plugin knows, plus the hosts that are documented
	 * alternates of one another - a service reachable under one address and
	 * not the other is the situation this exists for.
	 *
	 * @param string $extra An address the admin wants tested too.
	 * @return array of { label, url }
	 */
	public static function candidates( $extra = '' ) {
		$out  = array();
		$seen = array();

		$add = function ( $label, $url ) use ( &$out, &$seen ) {
			$url = trim( (string) $url );

			if ( '' === $url || isset( $seen[ $url ] ) ) {
				return;
			}

			$seen[ $url ] = true;
			$out[]        = array(
				'label' => $label,
				'url'   => $url,
			);
		};

		$extra = trim( (string) $extra );

		if ( '' !== $extra ) {
			$add(
				wpnc__( 'The address you entered', 'آدرسی که وارد کردید' ),
				WPNC_AI_Providers::endpoint( 'custom', $extra, 'test-model' )
			);
		}

		foreach ( WPNC_AI_Providers::all() as $slug => $provider ) {
			$base = WPNC_AI_Rewriter::base_url( $slug );
			$url  = WPNC_AI_Providers::endpoint( $slug, $base, 'test-model' );

			if ( '' !== $url ) {
				$add( $provider['label'], $url );
			}

			// The provider's own address as well, when a Base URL is standing
			// in front of it: knowing which of the two answers is the point.
			if ( '' !== $base ) {
				$direct = WPNC_AI_Providers::endpoint( $slug, '', 'test-model' );

				if ( '' !== $direct ) {
					$add(
						sprintf(
							/* translators: %s: provider name */
							wpnc__( '%s, direct', '%s، مستقیم' ),
							$provider['label']
						),
						$direct
					);
				}
			}
		}

		// GapGPT documents api.gapgpt.app and serves the same API from
		// api.gapapi.com. Which of them answers depends on where the server
		// is, so both are offered rather than assumed.
		$add( 'GapGPT (api.gapgpt.app)', 'https://api.gapgpt.app/v1/chat/completions' );
		$add( 'GapGPT (api.gapapi.com)', 'https://api.gapapi.com/v1/chat/completions' );

		return $out;
	}

	/**
	 * Ask each candidate whether it answers from this server.
	 *
	 * @param string $extra An address the admin wants tested too.
	 * @return array
	 */
	public static function sweep( $extra = '' ) {
		$rows    = array();
		$working = array();

		foreach ( self::candidates( $extra ) as $index => $candidate ) {
			$probe = self::probe( 'sweep-' . $index, $candidate['label'], $candidate['url'], self::SWEEP_TIMEOUT );

			// Any HTTP status at all means the address answered. A bare GET
			// with no key is meant to be refused; being refused is the proof.
			if ( $probe['ok'] ) {
				$working[] = $candidate['label'];
			}

			$rows[] = $probe;
		}

		return array(
			'asked'   => self::SWEEP_TIMEOUT,
			'probes'  => $rows,
			'working' => $working,
			'verdict' => array(
				'code'    => empty( $working ) ? 'none_reachable' : 'some_reachable',
				'message' => empty( $working )
					? wpnc__(
						'None of these answered from this server. If Connection check showed the internet working otherwise, an address that answers from here is what is missing - a gateway, a reseller, or a model running on the server itself.',
						'هیچ‌کدام از این آدرس‌ها از این سرور پاسخ ندادند. اگر «بررسی اتصال» نشان داده که اینترنت سرور سالم است، چیزی که کم است آدرسی است که از اینجا جواب بدهد - یک درگاه واسط، یک فروشندهٔ داخلی، یا مدلی که روی خود سرور اجرا می‌شود.'
					)
					: sprintf(
						/* translators: %s: comma separated list of addresses that answered */
						wpnc__(
							'These answered from this server: %s. Put one of them in Base URL under Settings, with a key that works there.',
							'این‌ها از این سرور پاسخ دادند: %s. یکی از آن‌ها را در تنظیمات در Base URL بگذارید، با کلیدی که روی همان کار می‌کند.'
						),
						implode( wpnc__( ', ', '، ' ), $working )
					),
			),
		);
	}

	/**
	 * What the probes taken together mean.
	 *
	 * Pure, so the reasoning can be tested without a network - which is the
	 * only way to test the case this exists for, since reproducing a host's
	 * outbound cap on demand is not something a test can do.
	 *
	 * @param array $probes Probe results.
	 * @param int   $asked  Timeout each probe was given.
	 * @return array { code, message }
	 */
	public static function verdict( $probes, $asked ) {
		$probes = array_values( (array) $probes );

		if ( empty( $probes ) ) {
			return array(
				'code'    => 'unknown',
				'message' => wpnc__( 'Nothing could be tested.', 'چیزی برای آزمایش نبود.' ),
			);
		}

		$asked   = max( 1, (int) $asked );
		$cut     = array();
		$failed  = 0;
		$ai      = null;
		$control = null;

		foreach ( $probes as $probe ) {
			if ( empty( $probe['ok'] ) ) {
				$failed++;
			}

			if ( ! empty( $probe['timed_out'] ) && (float) $probe['elapsed'] < ( $asked * self::CAP_RATIO ) ) {
				$cut[] = (float) $probe['elapsed'];
			}

			if ( 'ai' === $probe['key'] ) {
				$ai = $probe;
			}

			if ( 'control' === $probe['key'] ) {
				$control = $probe;
			}
		}

		// Cut short well before the time allowed, more than once: that is a
		// limit imposed on this server, not a slow correspondent.
		if ( count( $cut ) > 1 ) {
			return array(
				'code'    => 'capped',
				'message' => sprintf(
					/* translators: 1: seconds at which requests were cut, 2: seconds allowed */
					wpnc__(
						'Confirmed: outbound requests are being cut at about %1$ss even though %2$ss was allowed, and it happens to more than one address - so it is this server, not the provider. Ask the host what limits outbound HTTP requests.',
						'تأیید شد: درخواست‌های خروجی حدود %1$s ثانیه‌ای قطع می‌شوند در حالی که %2$s ثانیه مجاز بوده، و این برای بیش از یک آدرس رخ می‌دهد - پس مشکل از این سرور است نه از ارائه‌دهنده. از هاست بپرسید چه چیزی درخواست‌های خروجی HTTP را محدود می‌کند.'
					),
					round( min( $cut ), 1 ),
					$asked
				),
			);
		}

		// Only the provider was cut short, and a neutral address was fine.
		if ( $ai && empty( $ai['ok'] ) && $control && ! empty( $control['ok'] ) ) {
			return array(
				'code'    => 'endpoint_blocked',
				'message' => sprintf(
					/* translators: %s: endpoint host */
					wpnc__(
						'This server reaches the internet, but not %s. The address is the problem, not the server and not your keys: set a Base URL that answers from here, or choose another provider.',
						'این سرور به اینترنت دسترسی دارد اما به %s نه. مشکل از آدرس است، نه از سرور و نه از کلیدها: در تنظیمات Base URL آدرسی بگذارید که از اینجا پاسخ می‌دهد، یا ارائه‌دهندهٔ دیگری انتخاب کنید.'
					),
					$ai['host']
				),
			);
		}

		if ( $failed === count( $probes ) ) {
			return array(
				'code'    => 'no_outbound',
				'message' => wpnc__(
					'No address answered at all, including WordPress.org. This server is not making outbound requests; that is a hosting question before it is a plugin one.',
					'هیچ آدرسی پاسخ نداد، حتی WordPress.org. این سرور اصلاً درخواست خروجی نمی‌فرستد؛ این پیش از آنکه مسئلهٔ افزونه باشد، مسئلهٔ هاست است.'
				),
			);
		}

		if ( 0 === $failed ) {
			return array(
				'code'    => 'healthy',
				'message' => wpnc__(
					'Every address answered, so outbound requests work and nothing is cutting them short. An assistant action that still times out is one whose answer genuinely takes longer than the limit - the ones that rebuild the whole article are the slowest.',
					'همهٔ آدرس‌ها پاسخ دادند، پس درخواست‌های خروجی کار می‌کنند و چیزی آن‌ها را قطع نمی‌کند. اگر کاری از دستیار باز هم به زمان‌انتظار بخورد، یعنی واقعاً تولید پاسخش طولانی‌تر از حد مجاز است - کارهایی که کل متن را بازسازی می‌کنند کندترین‌اند.'
				),
			);
		}

		return array(
			'code'    => 'mixed',
			'message' => wpnc__(
				'Some addresses answered and some did not. The detail for each is below.',
				'بعضی آدرس‌ها پاسخ دادند و بعضی نه. جزئیات هرکدام در زیر آمده است.'
			),
		);
	}
}
