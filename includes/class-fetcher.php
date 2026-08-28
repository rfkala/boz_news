<?php
/**
 * Fetching orchestration and WP-Cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Fetcher {

	/**
	 * Transient that serialises every fetch run, scheduled or manual.
	 */
	const LOCK_KEY = 'wpnc_fetch_lock';

	/**
	 * How long a lock survives without a heartbeat.
	 */
	const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Option holding per-source success/failure history.
	 */
	const HEALTH_OPTION = 'wpnc_source_health';

	/**
	 * Consecutive failures before a source starts being skipped.
	 */
	const FAIL_THRESHOLD = 3;

	/**
	 * @var WPNC_Feed_Reader
	 */
	private $feed_reader;

	/**
	 * @var WPNC_Queue_Repository
	 */
	private $queue;

	/**
	 * @var WPNC_Image_Service
	 */
	private $image_service;

	/**
	 * @var WPNC_AI_Rewriter
	 */
	private $ai_rewriter;

	/**
	 * @var WPNC_Publisher
	 */
	private $publisher;

	/**
	 * @var WPNC_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->feed_reader   = new WPNC_Feed_Reader();
		$this->queue         = new WPNC_Queue_Repository();
		$this->image_service = new WPNC_Image_Service();
		$this->ai_rewriter   = new WPNC_AI_Rewriter();
		$this->publisher     = new WPNC_Publisher();
		$this->logger        = new WPNC_Logger();

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule_cron_events' ) );
		add_action( 'wpnc_fetch_news_event', array( $this, 'fetch_news' ) );
		add_action( 'wpnc_cleanup_news_event', array( $this, 'cleanup_queue' ) );
		add_action( 'update_option_wpnc_interval', array( $this, 'reschedule_cron' ), 10, 3 );
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes', 'wp-news-collector' ),
		);
		$schedules['3hours'] = array(
			'interval' => 3 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 3 Hours', 'wp-news-collector' ),
		);

		return $schedules;
	}

	/**
	 * Schedule cron events.
	 */
	public function schedule_cron_events() {
		$interval = WPNC_Settings::sanitize_interval( get_option( 'wpnc_interval', 'hourly' ) );

		if ( ! wp_next_scheduled( 'wpnc_fetch_news_event' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, 'wpnc_fetch_news_event' );
		}

		if ( ! wp_next_scheduled( 'wpnc_cleanup_news_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpnc_cleanup_news_event' );
		}
	}

	/**
	 * Reschedule cron when interval setting changes.
	 *
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 */
	public function reschedule_cron( $old_value, $value, $option ) {
		$interval = WPNC_Settings::sanitize_interval( $value );

		wp_clear_scheduled_hook( 'wpnc_fetch_news_event' );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, 'wpnc_fetch_news_event' );
	}

	/**
	 * Fetch news from configured feeds.
	 *
	 * @param bool $manual Whether fetch was manually triggered.
	 * @return array
	 */
	public function fetch_news( $manual = false ) {
		$summary = array(
			'sources_total' => 0,
			'sources_ok'    => 0,
			'fetched'       => 0,
			'queued'        => 0,
			'published'     => 0,
			'skipped'       => 0,
			'errors'        => 0,
			'messages'      => array(),
		);

		if ( ! $this->acquire_lock( $manual ) ) {
			$message = __( 'A fetch job is already running.', 'wp-news-collector' );
			$this->logger->log( WPNC_Logger::LEVEL_WARNING, $message );
			$summary['errors']++;
			$summary['messages'][] = $message;
			return $summary;
		}

		try {
			$sources = $this->feed_reader->parse_sources( get_option( 'wpnc_rss_links', '' ) );
			$summary['sources_total'] = count( $sources );

			if ( empty( $sources ) ) {
				$message = __( 'No RSS feeds are configured.', 'wp-news-collector' );
				$this->logger->log( WPNC_Logger::LEVEL_WARNING, $message );
				$summary['messages'][] = $message;
				return $summary;
			}

			foreach ( $sources as $source ) {
				$this->process_source( $source, $summary );
			}

			update_option( 'wpnc_last_run', WPNC_Time::now() );
			update_option( 'wpnc_last_count', absint( $summary['fetched'] ) );
			update_option( 'wpnc_last_summary', $summary );

			$this->logger->log(
				WPNC_Logger::LEVEL_INFO,
				__( 'Fetch completed.', 'wp-news-collector' ),
				$summary
			);
		} finally {
			$this->release_lock();
		}

		return $summary;
	}

	/**
	 * Return parsed sources array.
	 *
	 * @return array
	 */
	public function get_sources() {
		return $this->feed_reader->parse_sources( get_option( 'wpnc_rss_links', '' ) );
	}

	/**
	 * Fetch a single source by its zero-based index in the sources list.
	 * Used by the per-source manual fetch UI so the browser can show progress.
	 *
	 * @param int $index Zero-based source index.
	 * @return array Partial summary for this source.
	 */
	public function fetch_single_source( $index ) {
		$sources = $this->get_sources();

		if ( ! isset( $sources[ $index ] ) ) {
			return array(
				'fetched'   => 0,
				'queued'    => 0,
				'published' => 0,
				'skipped'   => 0,
				'errors'    => 1,
				'messages'  => array( __( 'Source not found.', 'wp-news-collector' ) ),
			);
		}

		// Give each per-source request up to 2 minutes.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 120 );

		// The browser drives this one request at a time; keep the run-level
		// lock from expiring underneath a slow feed.
		$this->renew_lock();

		$summary = array(
			'fetched'   => 0,
			'queued'    => 0,
			'published' => 0,
			'skipped'   => 0,
			'errors'    => 0,
			'messages'  => array(),
		);

		$this->process_source( $sources[ $index ], $summary );

		return $summary;
	}

	/**
	 * Cleanup processed queue rows and old logs.
	 */
	public function cleanup_queue() {
		$this->queue->cleanup( 14 );
		$this->logger->cleanup( 30 );
	}

	/**
	 * Process one feed source.
	 *
	 * @param array $source  Source definition.
	 * @param array $summary Summary reference.
	 */
	private function process_source( $source, &$summary ) {
		$source_key = $source['source_key'] ?: wp_parse_url( $source['url'] ?? '', PHP_URL_HOST );
		$source_id  = WPNC_Feed_Reader::source_id( $source );

		if ( empty( $source['valid'] ) ) {
			$message = sprintf(
				/* translators: %s: feed URL */
				__( 'Skipped unsafe feed URL: %s', 'wp-news-collector' ),
				$source['url'] ?? ''
			);
			$this->logger->log( WPNC_Logger::LEVEL_ERROR, $message, array(), $source_key );
			$this->record_source_failure( $source_id, $source, $message );
			$summary['errors']++;
			$summary['messages'][] = $message;
			return;
		}

		if ( empty( $source['enabled'] ) ) {
			$summary['skipped']++;
			$summary['messages'][] = sprintf(
				/* translators: %s: feed URL */
				__( 'Source is disabled: %s', 'wp-news-collector' ),
				$source['url']
			);
			return;
		}

		$cooldown = $this->cooldown_remaining( $source_id );
		if ( $cooldown > 0 ) {
			$summary['skipped']++;
			$summary['messages'][] = sprintf(
				/* translators: 1: feed URL, 2: human readable duration */
				__( 'Source paused after repeated failures, retrying in %2$s: %1$s', 'wp-news-collector' ),
				$source['url'],
				human_time_diff( WPNC_Time::timestamp(), WPNC_Time::timestamp() + $cooldown )
			);
			return;
		}

		$result = $this->feed_reader->fetch( $source, get_option( 'wpnc_max_items_per_feed', 20 ) );
		if ( is_wp_error( $result ) ) {
			$message = sprintf(
				/* translators: 1: feed URL, 2: error message */
				__( 'Feed fetch failed for %1$s: %2$s', 'wp-news-collector' ),
				$source['url'],
				$result->get_error_message()
			);
			$this->logger->log( WPNC_Logger::LEVEL_ERROR, $message, array( 'url' => $source['url'] ), $source_key );
			$this->record_source_failure( $source_id, $source, $result->get_error_message() );
			$summary['errors']++;
			$summary['messages'][] = $message;
			return;
		}

		$this->record_source_success( $source_id, $source, count( $result['items'] ) );
		$summary['sources_ok']++;

		foreach ( $result['items'] as $item ) {
			$summary['fetched']++;
			$item_result = $this->process_item( $item );

			if ( 'queued' === $item_result ) {
				$summary['queued']++;
			} elseif ( 'published' === $item_result ) {
				$summary['published']++;
			} elseif ( 'error' === $item_result ) {
				$summary['errors']++;
			} else {
				$summary['skipped']++;
			}
		}
	}

	/**
	 * Process a normalized feed item.
	 *
	 * @param array $item Item.
	 * @return string queued|published|skipped|error
	 */
	private function process_item( $item ) {
		if ( empty( $item['main_link'] ) || empty( $item['title'] ) ) {
			return 'skipped';
		}

		if ( ! $this->passes_keyword_filters( $item ) ) {
			return 'skipped';
		}

		if ( $this->queue->exists( $item['main_link'], $item['guid'] ) ) {
			return 'skipped';
		}

		$item['image_url'] = $this->image_service->extract_image( $item['raw_item'], $item['main_link'] );

		if ( get_option( 'wpnc_extract_full_text', 0 ) ) {
			$full_text = $this->image_service->extract_full_text( $item['main_link'] );
			if ( ! empty( $full_text ) ) {
				$item['description'] = $full_text;
			}
		}

		unset( $item['raw_item'] );

		if ( get_option( 'wpnc_auto_rewrite', 0 ) ) {
			$rewrite = $this->ai_rewriter->rewrite( $item['title'], $item['description'] );
			if ( is_wp_error( $rewrite ) ) {
				$this->logger->log(
					WPNC_Logger::LEVEL_WARNING,
					__( 'AI rewrite failed; original item was kept.', 'wp-news-collector' ),
					array(
						'error' => $rewrite->get_error_message(),
						'url'   => $item['main_link'],
					),
					$item['source_key']
				);
			} else {
				$item = array_merge( $item, $rewrite );
			}
		}

		if ( get_option( 'wpnc_auto_publish', 0 ) ) {
			$post_id = $this->publisher->publish( $item );
			if ( is_wp_error( $post_id ) ) {
				$this->logger->log(
					WPNC_Logger::LEVEL_ERROR,
					__( 'Auto-publish failed.', 'wp-news-collector' ),
					array(
						'error' => $post_id->get_error_message(),
						'url'   => $item['main_link'],
					),
					$item['source_key']
				);
				return 'error';
			}

			return 'published';
		}

		$inserted = $this->queue->insert( $item );
		if ( ! $inserted ) {
			$this->logger->log(
				WPNC_Logger::LEVEL_ERROR,
				__( 'Failed to insert queue item.', 'wp-news-collector' ),
				array( 'url' => $item['main_link'] ),
				$item['source_key']
			);
			return 'error';
		}

		return 'queued';
	}

	/**
	 * Keyword include/exclude filtering.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	private function passes_keyword_filters( $item ) {
		$include_words = $this->parse_words( get_option( 'wpnc_include_words', '' ) );
		$exclude_words = $this->parse_words( get_option( 'wpnc_exclude_words', '' ) );
		$content       = $this->lower( $item['title'] . ' ' . wp_strip_all_tags( $item['description'] ) );

		foreach ( $exclude_words as $word ) {
			if ( '' !== $word && false !== strpos( $content, $this->lower( $word ) ) ) {
				return false;
			}
		}

		if ( empty( $include_words ) ) {
			return true;
		}

		foreach ( $include_words as $word ) {
			if ( '' !== $word && false !== strpos( $content, $this->lower( $word ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse comma-separated words.
	 *
	 * @param string $words Words.
	 * @return array
	 */
	private function parse_words( $words ) {
		return array_filter( array_map( 'trim', explode( ',', (string) $words ) ) );
	}

	/**
	 * Lowercase with multibyte support when available.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * Per-source success/failure history, keyed by stable source id.
	 *
	 * @return array
	 */
	public function get_source_health() {
		$health = get_option( self::HEALTH_OPTION, array() );

		return is_array( $health ) ? $health : array();
	}

	/**
	 * Seconds a source must still wait before it is tried again.
	 *
	 * Backoff doubles per failure past the threshold and is capped at a day,
	 * so a permanently dead feed stops being retried on every single run.
	 *
	 * @param string $source_id Stable source id.
	 * @return int Seconds remaining, 0 when the source is due.
	 */
	public function cooldown_remaining( $source_id ) {
		$health = $this->get_source_health();
		$record = isset( $health[ $source_id ] ) ? $health[ $source_id ] : null;

		if ( ! is_array( $record ) ) {
			return 0;
		}

		$fails = absint( isset( $record['fails'] ) ? $record['fails'] : 0 );
		if ( $fails < self::FAIL_THRESHOLD ) {
			return 0;
		}

		$wait = (int) min(
			DAY_IN_SECONDS,
			HOUR_IN_SECONDS * pow( 2, $fails - self::FAIL_THRESHOLD )
		);
		$due  = absint( isset( $record['last_try'] ) ? $record['last_try'] : 0 ) + $wait;

		return max( 0, $due - WPNC_Time::timestamp() );
	}

	/**
	 * Clear the failure history for one source, or for all of them.
	 *
	 * @param string $source_id Stable source id, or empty for all.
	 */
	public function reset_source_health( $source_id = '' ) {
		if ( '' === $source_id ) {
			delete_option( self::HEALTH_OPTION );
			return;
		}

		$health = $this->get_source_health();
		unset( $health[ $source_id ] );
		update_option( self::HEALTH_OPTION, $health, false );
	}

	/**
	 * Record a successful fetch for a source.
	 *
	 * @param string $source_id Stable source id.
	 * @param array  $source    Source definition.
	 * @param int    $items     Items returned.
	 */
	private function record_source_success( $source_id, $source, $items ) {
		$health = $this->get_source_health();
		$now    = WPNC_Time::timestamp();

		$health[ $source_id ] = array(
			'url'        => isset( $source['url'] ) ? $source['url'] : '',
			'fails'      => 0,
			'last_error' => '',
			'last_ok'    => $now,
			'last_try'   => $now,
			'last_items' => absint( $items ),
		);

		update_option( self::HEALTH_OPTION, $health, false );
	}

	/**
	 * Record a failed fetch for a source.
	 *
	 * @param string $source_id Stable source id.
	 * @param array  $source    Source definition.
	 * @param string $error     Error message.
	 */
	private function record_source_failure( $source_id, $source, $error ) {
		$health   = $this->get_source_health();
		$previous = ( isset( $health[ $source_id ] ) && is_array( $health[ $source_id ] ) ) ? $health[ $source_id ] : array();

		$health[ $source_id ] = array(
			'url'        => isset( $source['url'] ) ? $source['url'] : '',
			'fails'      => absint( isset( $previous['fails'] ) ? $previous['fails'] : 0 ) + 1,
			'last_error' => sanitize_text_field( $error ),
			'last_ok'    => absint( isset( $previous['last_ok'] ) ? $previous['last_ok'] : 0 ),
			'last_try'   => WPNC_Time::timestamp(),
			'last_items' => absint( isset( $previous['last_items'] ) ? $previous['last_items'] : 0 ),
		);

		update_option( self::HEALTH_OPTION, $health, false );
	}

	/**
	 * Acquire the fetch lock.
	 *
	 * Both the cron run and the browser-driven per-source run take this, so a
	 * manual fetch can no longer overlap a scheduled one and import an item
	 * twice (or pay OpenAI twice for it).
	 *
	 * @param bool $manual Whether the run was triggered by hand.
	 * @return bool True when the lock was taken.
	 */
	public function acquire_lock( $manual = false ) {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient(
			self::LOCK_KEY,
			array(
				'manual' => (bool) $manual,
				'time'   => WPNC_Time::timestamp(),
			),
			self::LOCK_TTL
		);

		return true;
	}

	/**
	 * Extend the current lock, or take it if it expired mid-run.
	 *
	 * @return bool True when this run holds the lock afterwards.
	 */
	public function renew_lock() {
		$lock = $this->get_lock();

		if ( false === $lock ) {
			return $this->acquire_lock( true );
		}

		if ( empty( $lock['manual'] ) ) {
			// A cron run owns it; do not steal it.
			return false;
		}

		$lock['time'] = WPNC_Time::timestamp();
		set_transient( self::LOCK_KEY, $lock, self::LOCK_TTL );

		return true;
	}

	/**
	 * Read the current lock.
	 *
	 * @return array|false
	 */
	public function get_lock() {
		$lock = get_transient( self::LOCK_KEY );

		return is_array( $lock ) ? $lock : false;
	}

	/**
	 * Release the fetch lock.
	 */
	public function release_lock() {
		delete_transient( self::LOCK_KEY );
	}
}

new WPNC_Fetcher();
