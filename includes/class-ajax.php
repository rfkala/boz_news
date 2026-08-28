<?php
/**
 * AJAX endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Ajax {

	/**
	 * @var WPNC_Queue_Repository
	 */
	private $queue;

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
		$this->queue     = new WPNC_Queue_Repository();
		$this->publisher = new WPNC_Publisher();
		$this->logger    = new WPNC_Logger();

		add_action( 'wp_ajax_wpnc_get_queue', array( $this, 'get_queue' ) );
		add_action( 'wp_ajax_wpnc_approve_item', array( $this, 'approve_item' ) );
		add_action( 'wp_ajax_wpnc_reject_item', array( $this, 'reject_item' ) );
		add_action( 'wp_ajax_wpnc_edit_item', array( $this, 'edit_item' ) );
		add_action( 'wp_ajax_wpnc_bulk_approve', array( $this, 'bulk_approve' ) );
		add_action( 'wp_ajax_wpnc_bulk_reject', array( $this, 'bulk_reject' ) );
		add_action( 'wp_ajax_wpnc_get_stats', array( $this, 'get_stats' ) );
		add_action( 'wp_ajax_wpnc_get_logs', array( $this, 'get_logs' ) );
		add_action( 'wp_ajax_wpnc_run_fetch', array( $this, 'run_fetch' ) );
		add_action( 'wp_ajax_wpnc_get_sources_list', array( $this, 'get_sources_list' ) );
		add_action( 'wp_ajax_wpnc_fetch_one_source', array( $this, 'fetch_one_source' ) );
		add_action( 'wp_ajax_wpnc_clear_fetch_lock', array( $this, 'clear_fetch_lock' ) );
		add_action( 'wp_ajax_wpnc_fetch_finalize', array( $this, 'fetch_finalize' ) );
		add_action( 'wp_ajax_wpnc_load_more_news', array( $this, 'load_more_news' ) );
		add_action( 'wp_ajax_nopriv_wpnc_load_more_news', array( $this, 'load_more_news' ) );
	}

	/**
	 * Get queue items.
	 */
	public function get_queue() {
		$this->check_admin_request();

		$page   = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
		$limit  = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 20;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';

		wp_send_json_success(
			$this->queue->get_items(
				array(
					'page'   => $page,
					'limit'  => $limit,
					'search' => $search,
					'status' => $status,
				)
			)
		);
	}

	/**
	 * Approve one queue item.
	 */
	public function approve_item() {
		$this->check_admin_request();

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Item not found.', 'wp-news-collector' ) ) );
		}

		// Without this, a double click or a second moderator publishes the
		// same story twice.
		if ( ! $this->queue->is_actionable( $item ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This item was already processed.', 'wp-news-collector' ),
					'status'  => (string) $item->status,
				)
			);
		}

		$post_id = $this->publisher->publish( $item );
		if ( is_wp_error( $post_id ) ) {
			$this->queue->mark_error( $id, $post_id->get_error_message() );
			wp_send_json_error( $post_id->get_error_message() );
		}

		$this->queue->mark_approved( $id, $post_id );
		wp_send_json_success(
			array(
				'message' => __( 'Item approved and published successfully.', 'wp-news-collector' ),
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Reject one queue item.
	 */
	public function reject_item() {
		$this->check_admin_request();

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Item not found.', 'wp-news-collector' ) ) );
		}

		// Rejecting an already approved row used to flip its status while the
		// published post stayed live, which left the two out of sync.
		if ( ! $this->queue->is_actionable( $item ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This item was already processed.', 'wp-news-collector' ),
					'status'  => (string) $item->status,
				)
			);
		}

		$this->queue->update_status( $id, 'rejected' );

		wp_send_json_success( array( 'message' => __( 'Item rejected successfully.', 'wp-news-collector' ) ) );
	}

	/**
	 * Edit queue item.
	 */
	public function edit_item() {
		$this->check_admin_request();

		$id          = $this->get_posted_id();
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$tags        = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Title is required.', 'wp-news-collector' ) );
		}

		$this->queue->update_item(
			$id,
			array(
				'title'       => $title,
				'description' => $description,
				'tags'        => $tags,
			)
		);

		wp_send_json_success( array( 'message' => __( 'Item updated successfully.', 'wp-news-collector' ) ) );
	}

	/**
	 * Bulk approve.
	 */
	public function bulk_approve() {
		$this->check_admin_request();

		$ids           = $this->get_posted_ids();
		$success_count = 0;
		$error_count   = 0;
		$skipped_count = 0;

		foreach ( $ids as $id ) {
			$item = $this->queue->get( $id );
			if ( ! $this->queue->is_actionable( $item ) ) {
				$skipped_count++;
				continue;
			}

			$post_id = $this->publisher->publish( $item );
			if ( is_wp_error( $post_id ) ) {
				$this->queue->mark_error( $id, $post_id->get_error_message() );
				$error_count++;
				continue;
			}

			$this->queue->mark_approved( $id, $post_id );
			$success_count++;
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: success count, 2: error count, 3: skipped count */
					__( '%1$d items approved. %2$d failed. %3$d skipped.', 'wp-news-collector' ),
					$success_count,
					$error_count,
					$skipped_count
				),
				'approved' => $success_count,
				'failed'   => $error_count,
				'skipped'  => $skipped_count,
			)
		);
	}

	/**
	 * Bulk reject.
	 */
	public function bulk_reject() {
		$this->check_admin_request();

		$ids           = $this->get_posted_ids();
		$success_count = 0;
		$skipped_count = 0;

		foreach ( $ids as $id ) {
			$item = $this->queue->get( $id );
			if ( ! $this->queue->is_actionable( $item ) ) {
				$skipped_count++;
				continue;
			}

			$this->queue->update_status( $id, 'rejected' );
			$success_count++;
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: rejected count, 2: skipped count */
					__( '%1$d items rejected. %2$d skipped.', 'wp-news-collector' ),
					$success_count,
					$skipped_count
				),
				'rejected' => $success_count,
				'skipped'  => $skipped_count,
			)
		);
	}

	/**
	 * Get queue stats.
	 */
	public function get_stats() {
		$this->check_admin_request();

		wp_send_json_success( $this->queue->get_stats() );
	}

	/**
	 * Get recent logs.
	 */
	public function get_logs() {
		$this->check_admin_request();

		$limit = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 50;
		wp_send_json_success( array( 'logs' => $this->logger->get_recent( $limit ) ) );
	}

	/**
	 * Run fetch manually (single blocking call — kept for backward compat / cron).
	 */
	public function run_fetch() {
		$this->check_admin_request();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		$fetcher = new WPNC_Fetcher();
		wp_send_json_success( $fetcher->fetch_news( true ) );
	}

	/**
	 * Return list of configured RSS sources so the UI can fetch them one by one.
	 */
	public function get_sources_list() {
		$this->check_admin_request();

		$fetcher = new WPNC_Fetcher();
		$sources = $fetcher->get_sources();

		if ( empty( $sources ) ) {
			wp_send_json_error( array( 'message' => __( 'No RSS sources configured.', 'wp-news-collector' ) ) );
		}

		// Take the run-level lock here, not per source, so a manual run cannot
		// interleave with the scheduled one. fetch_finalize releases it, and
		// the transient TTL covers a browser that walks away mid-run.
		if ( ! $fetcher->acquire_lock( true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'A fetch job is already running. Wait for it to finish, or clear the lock from Logs & Tools.', 'wp-news-collector' ),
					'locked'  => true,
				)
			);
		}

		$list = array();
		foreach ( $sources as $i => $source ) {
			$list[] = array(
				'index'   => $i,
				'url'     => isset( $source['url'] ) ? $source['url'] : '',
				'key'     => isset( $source['source_key'] ) ? $source['source_key'] : '',
				'enabled' => ! empty( $source['enabled'] ),
			);
		}

		wp_send_json_success(
			array(
				'sources' => $list,
				'total'   => count( $list ),
			)
		);
	}

	/**
	 * Fetch one source by its index — called once per source by the progress UI.
	 */
	public function fetch_one_source() {
		$this->check_admin_request();

		$index   = isset( $_POST['source_index'] ) ? absint( wp_unslash( $_POST['source_index'] ) ) : 0;
		$fetcher = new WPNC_Fetcher();

		wp_send_json_success( $fetcher->fetch_single_source( $index ) );
	}

	/**
	 * Clear a stuck fetch lock transient.
	 */
	public function clear_fetch_lock() {
		$this->check_admin_request();

		$fetcher = new WPNC_Fetcher();
		$lock    = $fetcher->get_lock();
		$fetcher->release_lock();

		$this->logger->log(
			WPNC_Logger::LEVEL_WARNING,
			__( 'Fetch lock cleared by an administrator.', 'wp-news-collector' ),
			is_array( $lock ) ? $lock : array()
		);

		wp_send_json_success( array( 'message' => __( 'Fetch lock cleared.', 'wp-news-collector' ) ) );
	}

	/**
	 * Save accumulated summary after a per-source manual fetch completes.
	 */
	public function fetch_finalize() {
		$this->check_admin_request();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw     = isset( $_POST['summary'] ) ? wp_unslash( $_POST['summary'] ) : '{}';
		$summary = json_decode( $raw, true );

		if ( is_array( $summary ) ) {
			update_option( 'wpnc_last_run', WPNC_Time::now() );
			update_option( 'wpnc_last_count', absint( $summary['fetched'] ?? 0 ) );

			$safe_keys = array( 'sources_total', 'sources_ok', 'fetched', 'queued', 'published', 'skipped', 'errors' );
			$safe      = array();
			foreach ( $safe_keys as $k ) {
				$safe[ $k ] = absint( $summary[ $k ] ?? 0 );
			}
			update_option( 'wpnc_last_summary', $safe );

			$this->logger->log(
				WPNC_Logger::LEVEL_INFO,
				__( 'Manual fetch completed.', 'wp-news-collector' ),
				$safe
			);
		}

		$fetcher = new WPNC_Fetcher();
		$fetcher->release_lock();

		wp_send_json_success();
	}

	/**
	 * Load more frontend news.
	 */
	public function load_more_news() {
		check_ajax_referer( 'wpnc_frontend_nonce', 'nonce' );

		$page     = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$limit    = isset( $_POST['limit'] ) ? max( 1, min( 50, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 10;
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$post_type = WPNC_Settings::get_target_post_type();

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'paged'          => $page,
			'no_found_rows'  => false,
		);

		if ( ! empty( $category ) ) {
			$args['category_name'] = $category;
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			wp_send_json_error( __( 'No more posts available.', 'wp-news-collector' ) );
		}

		ob_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			WPNC_Shortcode::render_news_item();
		}
		wp_reset_postdata();

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Check admin AJAX nonce and capability.
	 */
	private function check_admin_request() {
		check_ajax_referer( 'wpnc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized access.', 'wp-news-collector' ), 403 );
		}
	}

	/**
	 * Get posted ID.
	 *
	 * @return int
	 */
	private function get_posted_id() {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid ID provided.', 'wp-news-collector' ) );
		}

		return $id;
	}

	/**
	 * Get posted IDs.
	 *
	 * @return array
	 */
	private function get_posted_ids() {
		$posted_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$ids        = array_filter( array_map( 'absint', $posted_ids ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No valid IDs provided.', 'wp-news-collector' ) );
		}

		return $ids;
	}
}

new WPNC_Ajax();
