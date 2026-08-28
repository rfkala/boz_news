<?php
/**
 * Fetching orchestration and WP-Cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Fetcher {

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

			$this->send_admin_notification( $summary['queued'] );

			update_option( 'wpnc_last_run', current_time( 'mysql' ) );
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
	 * Backward-compatible publish wrapper.
	 *
	 * @return int|WP_Error
	 */
	public function publish_post( $title, $description, $main_link, $source_name, $image_url, $pub_date, $category_id = 0, $tags = '', $post_type = 'post' ) {
		return $this->publisher->publish(
			array(
				'title'       => $title,
				'description' => $description,
				'main_link'   => $main_link,
				'source_name' => $source_name,
				'image_url'   => $image_url,
				'pub_date'    => $pub_date,
				'category_id' => $category_id,
				'tags'        => $tags,
			),
			$post_type
		);
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

		if ( empty( $source['valid'] ) ) {
			$message = sprintf( __( 'Skipped unsafe feed URL: %s', 'wp-news-collector' ), $source['url'] ?? '' );
			$this->logger->log( WPNC_Logger::LEVEL_ERROR, $message, array(), $source_key );
			$summary['errors']++;
			$summary['messages'][] = $message;
			return;
		}

		$result = $this->feed_reader->fetch( $source, get_option( 'wpnc_max_items_per_feed', 20 ) );
		if ( is_wp_error( $result ) ) {
			$message = sprintf( __( 'Feed fetch failed for %1$s: %2$s', 'wp-news-collector' ), $source['url'], $result->get_error_message() );
			$this->logger->log( WPNC_Logger::LEVEL_ERROR, $message, array( 'url' => $source['url'] ), $source_key );
			$summary['errors']++;
			$summary['messages'][] = $message;
			return;
		}

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

		$item['image_url'] = $this->image_service->extract_image( $item['raw_item'], $item['main_link'], $item['source_key'] );

		if ( get_option( 'wpnc_extract_full_text', 0 ) ) {
			$full_text = $this->image_service->extract_full_text( $item['main_link'], $item['source_key'] );
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
	 * Send moderation notification.
	 *
	 * @param int $new_items New queued items.
	 */
	private function send_admin_notification( $new_items ) {
		if ( $new_items <= 0 || ! get_option( 'wpnc_admin_notify', 0 ) ) {
			return;
		}

		if ( false !== get_transient( 'wpnc_admin_notify_lock' ) ) {
			return;
		}

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( __( '[%s] New News Items for Moderation', 'wp-news-collector' ), get_option( 'blogname' ) ),
			sprintf( __( 'You have %d new news items pending in the Moderation Queue. Please log in to review them.', 'wp-news-collector' ), $new_items )
		);

		set_transient( 'wpnc_admin_notify_lock', true, DAY_IN_SECONDS );
	}

	/**
	 * Acquire fetch lock.
	 *
	 * @param bool $manual Whether manual.
	 * @return bool
	 */
	private function acquire_lock( $manual ) {
		if ( false !== get_transient( 'wpnc_fetch_lock' ) ) {
			return false;
		}

		set_transient(
			'wpnc_fetch_lock',
			array(
				'manual' => (bool) $manual,
				'time'   => time(),
			),
			15 * MINUTE_IN_SECONDS
		);

		return true;
	}

	/**
	 * Release fetch lock.
	 */
	private function release_lock() {
		delete_transient( 'wpnc_fetch_lock' );
	}
}

new WPNC_Fetcher();
