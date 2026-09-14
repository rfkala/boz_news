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
		add_action( 'wp_ajax_wpnc_delete_item', array( $this, 'delete_item' ) );
		add_action( 'wp_ajax_wpnc_bulk_delete', array( $this, 'bulk_delete' ) );
		add_action( 'wp_ajax_wpnc_unpublish_item', array( $this, 'unpublish_item' ) );
		add_action( 'wp_ajax_wpnc_test_source', array( $this, 'test_source' ) );
		add_action( 'wp_ajax_wpnc_toggle_source', array( $this, 'toggle_source' ) );
		add_action( 'wp_ajax_wpnc_reset_source_health', array( $this, 'reset_source_health' ) );
		add_action( 'wp_ajax_wpnc_save_source_policy', array( $this, 'save_source_policy' ) );
		add_action( 'wp_ajax_wpnc_fetch_full_text', array( $this, 'fetch_full_text' ) );
		add_action( 'wp_ajax_wpnc_detect_image', array( $this, 'detect_image' ) );
		add_action( 'wp_ajax_wpnc_ai_transform', array( $this, 'ai_transform' ) );
		add_action( 'wp_ajax_wpnc_preview_item', array( $this, 'preview_item' ) );
		add_action( 'wp_ajax_wpnc_test_channel', array( $this, 'test_channel' ) );
		add_action( 'wp_ajax_wpnc_test_alert', array( $this, 'test_alert' ) );
		add_action( 'wp_ajax_wpnc_get_dashboard', array( $this, 'get_dashboard' ) );
		add_action( 'wp_ajax_wpnc_get_stats', array( $this, 'get_stats' ) );
		add_action( 'wp_ajax_wpnc_get_logs', array( $this, 'get_logs' ) );
		add_action( 'wp_ajax_wpnc_get_sources_list', array( $this, 'get_sources_list' ) );
		add_action( 'wp_ajax_wpnc_fetch_one_source', array( $this, 'fetch_one_source' ) );
		add_action( 'wp_ajax_wpnc_clear_fetch_lock', array( $this, 'clear_fetch_lock' ) );
		add_action( 'wp_ajax_wpnc_diagnose_network', array( $this, 'diagnose_network' ) );
		add_action( 'wp_ajax_wpnc_probe_endpoints', array( $this, 'probe_endpoints' ) );
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
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		// Without this, a double click or a second moderator publishes the
		// same story twice.
		if ( ! $this->queue->is_actionable( $item ) ) {
			$this->fail(
				wpnc__( 'This item was already processed.', 'این آیتم قبلاً پردازش شده است.' ),
				'wpnc_already_processed',
				array( 'status' => (string) $item->status ),
				409
			);
		}

		$channels = $this->get_posted_channels();

		if ( empty( $channels ) ) {
			$this->fail(
				wpnc__(
					'Choose a destination that is set up and tested under Settings.',
					'مقصدی را انتخاب کنید که در تنظیمات پیکربندی و تست شده باشد.'
				),
				'wpnc_no_channel',
				array(),
				400
			);
		}

		// Reading the status and then acting on it is not the same as taking
		// it: two requests could both read "pending" and both publish. This
		// is the one operation that only one caller can win.
		if ( ! $this->queue->claim( $id ) ) {
			$this->fail(
				wpnc__( 'This item was already processed.', 'این آیتم قبلاً پردازش شده است.' ),
				'wpnc_already_processed',
				array( 'status' => (string) $item->status ),
				409
			);
		}

		$post_id = 0;

		if ( in_array( 'site', $channels, true ) ) {
			// Empty array, not null: the messengers are driven by the
			// selection here rather than by whatever happens to be set up.
			$post_id = $this->publisher->publish( $item, '', array() );

			if ( is_wp_error( $post_id ) ) {
				$this->queue->mark_error( $id, $post_id->get_error_message() );
				$this->fail( $post_id->get_error_message(), 'wpnc_publish_failed' );
			}
		}

		// Recorded before anything is sent. Delivery talks to two services
		// that may each take half a minute, and a request killed in there
		// used to leave the row pending with the post already live - so the
		// next click published the same story a second time.
		$this->queue->mark_approved( $id, $post_id );

		// Without a post there is no permalink, so readers get the original.
		// A scheduled post holds its messages until it is public.
		$sent     = $this->publisher->deliver_or_defer(
			$post_id,
			$channels,
			$item->title,
			$item->source_key,
			$item->main_link,
			WPNC_Publisher::message_context( $post_id, $item )
		);
		$deferred = null === $sent;
		$failed   = $deferred ? array() : array_keys( array_filter( $sent, 'is_string' ) );

		wp_send_json_success(
			array(
				'message'  => $this->describe_delivery( $channels, $failed, $post_id, $deferred ),
				'post_id'  => $post_id,
				'channels' => $channels,
				'failed'   => $failed,
			)
		);
	}

	/**
	 * The destinations this request asked for, reduced to the usable ones.
	 *
	 * Defaults to the site so that a caller which knows nothing about
	 * channels still behaves the way approve always has.
	 *
	 * @return array
	 */
	private function get_posted_channels() {
		if ( ! isset( $_POST['channels'] ) ) {
			return WPNC_Channels::sanitize_selection( array( 'site' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_POST['channels'] );

		if ( 'all' === $raw ) {
			return WPNC_Channels::sanitize_selection( 'all' );
		}

		return WPNC_Channels::sanitize_selection( is_array( $raw ) ? $raw : explode( ',', (string) $raw ) );
	}

	/**
	 * Say where an item actually went.
	 *
	 * A partial success is the common case worth wording carefully: the post
	 * is on the site and Bale refused it, and an editor who reads only
	 * "approved" would never go and look.
	 *
	 * @param array $channels Requested channels.
	 * @param array $failed   Channels that refused.
	 * @param int   $post_id  Post id, 0 when the site was not a destination.
	 * @return string
	 */
	private function describe_delivery( $channels, $failed, $post_id, $deferred = false ) {
		$post = $post_id ? get_post( $post_id ) : null;

		// A post set to go out later has not been sent anywhere yet, and
		// saying otherwise would have an editor looking for it on the site.
		if ( $post && 'future' === $post->post_status ) {
			$when = WPNC_Time::for_display( $post->post_date_gmt );

			if ( $deferred ) {
				return sprintf(
					/* translators: %s: publication date and time */
					wpnc__(
						'Approved and scheduled for %s. Telegram and Bale will be sent when it goes live.',
						'تأیید و برای %s زمان‌بندی شد. تلگرام و بله هنگام انتشار ارسال می‌شوند.'
					),
					$when
				);
			}

			return sprintf(
				/* translators: %s: publication date and time */
				wpnc__( 'Approved and scheduled for %s.', 'تأیید و برای %s زمان‌بندی شد.' ),
				$when
			);
		}

		$delivered = array_values( array_diff( $channels, $failed ) );
		$names     = array();

		foreach ( $delivered as $slug ) {
			$channel = WPNC_Channels::get( $slug );
			$names[] = isset( $channel['label'] ) ? $channel['label'] : $slug;
		}

		if ( empty( $names ) ) {
			return wpnc__( 'Approved, but nothing could be sent.', 'تأیید شد، اما ارسال به هیچ مقصدی انجام نشد.' );
		}

		$message = sprintf(
			/* translators: %s: comma separated destination names */
			wpnc__( 'Approved and sent to %s.', 'تأیید و به %s ارسال شد.' ),
			implode( wpnc__( ', ', '، ' ), $names )
		);

		if ( ! empty( $failed ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma separated destination names */
				wpnc__( 'Failed: %s. See Logs & Tools.', 'ناموفق: %s. به تب لاگ و ابزارها مراجعه کنید.' ),
				implode( wpnc__( ', ', '، ' ), $failed )
			);
		}

		return $message;
	}

	/**
	 * Check one channel's credentials against the live service.
	 */
	public function test_channel() {
		$this->check_admin_request();

		$slug = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';

		if ( ! WPNC_Channels::exists( $slug ) || 'site' === $slug ) {
			$this->fail(
				wpnc__( 'Unknown destination.', 'مقصد ناشناخته.' ),
				'wpnc_unknown_channel',
				array(),
				400
			);
		}

		$messenger = new WPNC_Messenger();
		$result    = $messenger->verify( $slug );

		if ( is_wp_error( $result ) ) {
			// A failed test must clear any earlier pass, or a channel that
			// has since broken keeps its button enabled.
			WPNC_Channels::clear_verified( $slug );
			$this->fail( $result->get_error_message(), 'wpnc_channel_failed' );
		}

		WPNC_Channels::mark_verified( $slug );

		wp_send_json_success(
			array(
				'message'  => wpnc__( 'Connected. This destination is ready to use.', 'اتصال برقرار شد. این مقصد آماده استفاده است.' ),
				'channels' => WPNC_Channels::status(),
			)
		);
	}

	/**
	 * Send a test alert to the administrator's chat.
	 *
	 * Sends a real message rather than only checking the chat exists: the
	 * question an administrator is asking is "will I see these", and only a
	 * message arriving answers that.
	 */
	public function test_alert() {
		$this->check_admin_request();

		$result = WPNC_Alerts::send(
			'test',
			wpnc__( 'Test alert from Boz News. Alerts will reach you here.', 'هشدار آزمایشی از بُز نیوز. هشدارها به همین‌جا می‌رسند.' ),
			true
		);

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 'wpnc_alert_failed' );
		}

		wp_send_json_success(
			array(
				'message' => wpnc__( 'Sent. Check the chat you entered.', 'ارسال شد. گفتگویی را که وارد کردید بررسی کنید.' ),
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
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		// Rejecting an already approved row used to flip its status while the
		// published post stayed live, which left the two out of sync.
		if ( ! $this->queue->is_actionable( $item ) ) {
			$this->fail(
				wpnc__( 'This item was already processed.', 'این آیتم قبلاً پردازش شده است.' ),
				'wpnc_already_processed',
				array( 'status' => (string) $item->status ),
				409
			);
		}

		$this->queue->update_status( $id, 'rejected' );

		wp_send_json_success( array( 'message' => wpnc__( 'Item rejected successfully.', 'آیتم رد شد.' ) ) );
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
			$this->fail(
				wpnc__( 'Title is required.', 'عنوان الزامی است.' ),
				'wpnc_title_required',
				array( 'field' => 'title' ),
				422
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_options = isset( $_POST['publish_options'] ) ? wp_unslash( $_POST['publish_options'] ) : array();

		$fields = array(
			'title'           => $title,
			'description'     => $description,
			'tags'            => $tags,
			'publish_options' => WPNC_Publish_Options::sanitize( $raw_options ),
		);

		// Only when sent, so an older copy of the editor still open in another
		// tab cannot wipe an item's picture by saving without the field.
		if ( isset( $_POST['image_url'] ) ) {
			$fields['image_url'] = $this->posted_image_url();
		}

		$saved = $this->queue->update_item( $id, $fields );

		// Reporting success for a write that did not happen is the worst of
		// both: the edit is gone and the editor has no reason to suspect it.
		if ( is_wp_error( $saved ) ) {
			$this->logger->log(
				WPNC_Logger::LEVEL_ERROR,
				wpnc__( 'Saving an edited queue item failed.', 'ذخیرهٔ ویرایش یک آیتم صف ناموفق بود.' ),
				array(
					'id'    => $id,
					'error' => $saved->get_error_message(),
				),
				'queue'
			);

			$this->fail(
				wpnc__(
					'The changes could not be saved. The database rejected the update - see Logs & Tools for the reason.',
					'تغییرات ذخیره نشد. پایگاه داده این به‌روزرسانی را نپذیرفت - دلیل آن در «لاگ‌ها و ابزارها» ثبت شده است.'
				),
				'wpnc_save_failed',
				array(),
				500
			);
		}

		wp_send_json_success( array( 'message' => wpnc__( 'Item updated successfully.', 'تغییرات ذخیره شد.' ) ) );
	}

	/**
	 * Bulk approve.
	 */
	public function bulk_approve() {
		$this->check_admin_request();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 120 );

		$ids           = $this->get_posted_ids();
		$channels      = $this->get_posted_channels();
		$success_count = 0;
		$error_count   = 0;
		$skipped_count = 0;
		$remaining     = 0;

		// Each item can mean an image download and two messenger calls, so a
		// bulk of twenty will not finish inside any shared host's limit. The
		// loop stops while it can still answer, and says what is left; being
		// killed here used to publish posts whose rows never got marked, and
		// pressing the button again republished them.
		$deadline = microtime( true ) + WPNC_Settings::time_budget();

		foreach ( $ids as $index => $id ) {
			if ( microtime( true ) >= $deadline ) {
				$remaining = count( $ids ) - $index;
				break;
			}

			$item = $this->queue->get( $id );

			if ( ! $this->queue->is_actionable( $item ) || ! $this->queue->claim( $id ) ) {
				$skipped_count++;
				continue;
			}

			$post_id = 0;

			if ( in_array( 'site', $channels, true ) ) {
				$post_id = $this->publisher->publish( $item, '', array() );

				if ( is_wp_error( $post_id ) ) {
					$this->queue->mark_error( $id, $post_id->get_error_message() );
					$error_count++;
					continue;
				}
			}

			// Before delivery, for the same reason as the single approve.
			$this->queue->mark_approved( $id, $post_id );
			$success_count++;

			$this->publisher->deliver_or_defer(
				$post_id,
				$channels,
				$item->title,
				$item->source_key,
				$item->main_link,
				WPNC_Publisher::message_context( $post_id, $item )
			);
		}

		$message = sprintf(
			/* translators: 1: success count, 2: error count, 3: skipped count */
			wpnc__( '%1$d items approved. %2$d failed. %3$d skipped.', '%1$d آیتم تأیید شد. %2$d ناموفق. %3$d رد شده.' ),
			$success_count,
			$error_count,
			$skipped_count
		);

		if ( $remaining > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: how many items were not reached */
				wpnc__(
					'%d were not reached before this request ran out of time - select them and approve again.',
					'به %d آیتم پیش از پایان زمان این درخواست نرسید - آن‌ها را انتخاب کنید و دوباره تأیید بزنید.'
				),
				$remaining
			);
		}

		wp_send_json_success(
			array(
				'message'   => $message,
				'remaining' => $remaining,
				'approved'  => $success_count,
				'failed'    => $error_count,
				'skipped'   => $skipped_count,
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
					wpnc__( '%1$d items rejected. %2$d skipped.', '%1$d آیتم رد شد. %2$d نادیده گرفته شد.' ),
					$success_count,
					$skipped_count
				),
				'rejected' => $success_count,
				'skipped'  => $skipped_count,
			)
		);
	}

	/**
	 * Permanently delete one queue row.
	 */
	public function delete_item() {
		$this->check_admin_request();

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		if ( ! $this->queue->delete( $id ) ) {
			$this->fail( wpnc__( 'Could not delete this item.', 'حذف این آیتم ممکن نبود.' ), 'wpnc_delete_failed' );
		}

		$this->logger->log(
			WPNC_Logger::LEVEL_INFO,
			wpnc__( 'Queue item deleted by an administrator.', 'یک آیتم صف توسط مدیر حذف شد.' ),
			array(
				'id'    => $id,
				'title' => $item->title,
			)
		);

		wp_send_json_success(
			array(
				'message' => wpnc__( 'Item deleted.', 'آیتم حذف شد.' ),
			)
		);
	}

	/**
	 * Permanently delete several queue rows.
	 */
	public function bulk_delete() {
		$this->check_admin_request();

		$ids     = $this->get_posted_ids();
		$deleted = $this->queue->delete_many( $ids );

		$this->logger->log(
			WPNC_Logger::LEVEL_INFO,
			wpnc__( 'Queue items deleted by an administrator.', 'چند آیتم صف توسط مدیر حذف شدند.' ),
			array( 'count' => $deleted )
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: deleted row count */
					wpnc__( '%d items deleted.', '%d آیتم حذف شد.' ),
					$deleted
				),
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * Undo an approval: trash the published post and reopen the queue row.
	 *
	 * Approving used to be irreversible, which made a misclick permanent.
	 * The post is trashed rather than deleted so it stays recoverable in
	 * WordPress itself.
	 */
	public function unpublish_item() {
		$this->check_admin_request();

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		if ( 'approved' !== (string) $item->status ) {
			$this->fail(
				wpnc__( 'Only an approved item can be sent back to the queue.', 'فقط آیتم تأییدشده را می‌توان به صف بازگرداند.' ),
				'wpnc_not_approved',
				array( 'status' => (string) $item->status ),
				409
			);
		}

		$post_id  = absint( $item->post_id );
		$trashed  = false;
		if ( $post_id && get_post( $post_id ) ) {
			$trashed = (bool) wp_trash_post( $post_id );
		}

		if ( ! $this->queue->reopen( $id ) ) {
			$this->fail( wpnc__( 'Could not reopen this item.', 'بازگرداندن این آیتم ممکن نبود.' ), 'wpnc_reopen_failed' );
		}

		$this->logger->log(
			WPNC_Logger::LEVEL_WARNING,
			wpnc__( 'Approval undone by an administrator.', 'یک تأیید توسط مدیر لغو شد.' ),
			array(
				'id'      => $id,
				'post_id' => $post_id,
				'trashed' => $trashed,
			)
		);

		wp_send_json_success(
			array(
				'message' => $trashed
					? wpnc__( 'Post moved to Trash and the item is back in the queue.', 'پست به زباله‌دان منتقل شد و آیتم به صف بازگشت.' )
					: wpnc__( 'The item is back in the queue. No published post was found to trash.', 'آیتم به صف بازگشت. پستی برای انتقال به زباله‌دان یافت نشد.' ),
				'trashed' => $trashed,
			)
		);
	}

	/**
	 * Read one source without importing anything, so a URL can be checked
	 * before it is trusted.
	 */
	public function test_source() {
		$this->check_admin_request();

		$index   = isset( $_POST['source_index'] ) ? absint( wp_unslash( $_POST['source_index'] ) ) : 0;
		$fetcher = new WPNC_Fetcher();
		$sources = $fetcher->get_sources();

		if ( ! isset( $sources[ $index ] ) ) {
			$this->fail( wpnc__( 'Source not found.', 'منبع یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		$source = $sources[ $index ];

		if ( empty( $source['valid'] ) ) {
			$this->fail(
				wpnc__( 'This URL points at a private or unreachable host.', 'این آدرس به میزبان خصوصی یا در دسترس نیست اشاره می‌کند.' ),
				'wpnc_unsafe_url'
			);
		}

		$reader = new WPNC_Feed_Reader();

		// A test that reports on a cached copy proves nothing about whether
		// the feed answers right now, which is the whole question.
		$result = $reader->fetch( $source, 5, true );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 'wpnc_feed_error' );
		}

		$titles = array();
		foreach ( $result['items'] as $item ) {
			$titles[] = $item['title'];
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: feed title, 2: item count */
					wpnc__( 'Read "%1$s" — %2$d items available.', 'فید «%1$s» خوانده شد — %2$d آیتم موجود است.' ),
					$result['title'],
					count( $titles )
				),
				'title'   => $result['title'],
				'titles'  => array_slice( $titles, 0, 5 ),
			)
		);
	}

	/**
	 * Pause or resume one source by rewriting its settings line.
	 */
	public function toggle_source() {
		$this->check_admin_request();

		$index   = isset( $_POST['source_index'] ) ? absint( wp_unslash( $_POST['source_index'] ) ) : 0;
		$reader  = new WPNC_Feed_Reader();
		$sources = $reader->parse_sources( get_option( 'wpnc_rss_links', '' ) );

		if ( ! isset( $sources[ $index ] ) ) {
			$this->fail( wpnc__( 'Source not found.', 'منبع یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		$sources[ $index ]['enabled'] = empty( $sources[ $index ]['enabled'] );

		$lines = array();
		foreach ( $sources as $source ) {
			$line = WPNC_Feed_Reader::to_line( $source );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		update_option( 'wpnc_rss_links', implode( "\n", $lines ) );

		wp_send_json_success(
			array(
				'message' => $sources[ $index ]['enabled']
					? wpnc__( 'Source resumed.', 'منبع دوباره فعال شد.' )
					: wpnc__( 'Source paused.', 'منبع متوقف شد.' ),
				'enabled' => (bool) $sources[ $index ]['enabled'],
			)
		);
	}

	/**
	 * Store one source's rules.
	 */
	public function save_source_policy() {
		$this->check_admin_request();

		$source_id = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$channels = isset( $_POST['channels'] ) ? (array) wp_unslash( $_POST['channels'] ) : array();

		$saved = WPNC_Source_Policy::save(
			$source_id,
			array(
				'mode'     => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'inherit',
				'rewrite'  => isset( $_POST['rewrite'] ) ? sanitize_key( wp_unslash( $_POST['rewrite'] ) ) : 'inherit',
				'channels' => array_map( 'sanitize_key', $channels ),
			)
		);

		if ( ! $saved ) {
			$this->fail( wpnc__( 'Source not found.', 'منبع یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		wp_send_json_success(
			array(
				'message' => wpnc__( 'Rules saved for this source.', 'قوانین این منبع ذخیره شد.' ),
				'summary' => WPNC_Source_Policy::describe( WPNC_Source_Policy::for_source( $source_id ) ),
			)
		);
	}

	/**
	 * Clear the failure history so a source is retried immediately.
	 */
	public function reset_source_health() {
		$this->check_admin_request();

		$source_id = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';
		$fetcher   = new WPNC_Fetcher();
		$fetcher->reset_source_health( $source_id );

		wp_send_json_success(
			array(
				'message' => wpnc__( 'Failure history cleared; this source will be tried on the next run.', 'تاریخچه خطا پاک شد؛ این منبع در اجرای بعدی دوباره تلاش می‌شود.' ),
			)
		);
	}

	/**
	 * Pull the full article body for one queue item, on demand.
	 *
	 * The fetch-time setting does this for every item; this is the button for
	 * when you want it for the one story in front of you. Nothing is saved
	 * here - the text goes back to the editor and the editor decides.
	 */
	public function fetch_full_text() {
		$this->check_admin_request();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 120 );

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		$service = new WPNC_Image_Service();
		$content = $service->extract_full_text( $item->main_link );

		if ( '' === $content ) {
			$reasons = array(
				'unsafe_url'             => wpnc__( 'That article URL is not safe to request.', 'آدرس این مقاله برای درخواست امن نیست.' ),
				'no_response'            => wpnc__( 'The article page did not respond.', 'صفحه مقاله پاسخ نداد.' ),
				'unparseable_html'       => wpnc__( 'The article page could not be parsed.', 'صفحه مقاله قابل تحلیل نبود.' ),
				'no_matching_paragraphs' => wpnc__( 'No article body was found on that page.', 'در آن صفحه بدنه مقاله پیدا نشد.' ),
				'paragraphs_too_short'   => wpnc__( 'The page had no text long enough to be an article.', 'متن آن صفحه برای یک مقاله بیش از حد کوتاه بود.' ),
			);

			$reason = $service->last_failure();

			$this->fail(
				isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : wpnc__( 'Nothing could be extracted.', 'چیزی قابل استخراج نبود.' ),
				'wpnc_no_full_text'
			);
		}

		wp_send_json_success(
			array(
				'content' => $content,
				'message' => sprintf(
					/* translators: %d: word count */
					wpnc__( 'Full text loaded, about %d words.', 'متن کامل بارگذاری شد، حدود %d کلمه.' ),
					// str_word_count() counts Latin letters only, so every
					// Persian article was "about 0 words".
					WPNC_Template::word_count( wp_strip_all_tags( $content ) )
				),
			)
		);
	}

	/**
	 * Look for a featured image for an item already in the queue.
	 *
	 * Only finds one. Keeping it is left to Save, like every other change
	 * made in the editor.
	 */
	public function detect_image() {
		$this->check_admin_request();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 60 );

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		$service = new WPNC_Image_Service();
		$url     = $service->find_image_for_text( (string) $item->description, (string) $item->main_link );
		$note    = $service->last_image_note();

		if ( '' === $url ) {
			$reasons = array(
				'unsafe_url'       => wpnc__( 'That article URL is not safe to request.', 'آدرس این مقاله برای درخواست امن نیست.' ),
				'page_no_response' => wpnc__( 'The article page did not respond.', 'صفحه مقاله پاسخ نداد.' ),
				'page_no_image'    => wpnc__(
					'Neither the item text nor its article page names a picture.',
					'نه متن خبر و نه صفحهٔ مقاله، تصویری معرفی نکرده‌اند.'
				),
			);

			$this->fail(
				isset( $reasons[ $note ] ) ? $reasons[ $note ] : wpnc__( 'No picture was found.', 'تصویری پیدا نشد.' ),
				'wpnc_no_image'
			);
		}

		wp_send_json_success(
			array(
				'image_url' => $url,
				'message'   => 'item_html' === $note
					? wpnc__( 'Found a picture in the item text. Save to keep it.', 'تصویری در متن خبر پیدا شد. برای نگه‌داشتن، ذخیره کنید.' )
					: wpnc__( 'Found the picture the article page declares. Save to keep it.', 'تصویری که صفحهٔ مقاله معرفی کرده پیدا شد. برای نگه‌داشتن، ذخیره کنید.' ),
			)
		);
	}

	/**
	 * The featured image address sent by the editor.
	 *
	 * Blank is allowed and means "none for this item".
	 *
	 * @param bool $strict Fail the request on an invalid address. The preview
	 *                     passes false: it runs while the address is still
	 *                     being typed, and should show no picture rather than
	 *                     an error on every keystroke.
	 * @return string
	 */
	private function posted_image_url( $strict = true ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and sanitised with esc_url_raw() below.
		$raw = isset( $_POST['image_url'] ) ? trim( (string) wp_unslash( $_POST['image_url'] ) ) : '';

		if ( '' === $raw ) {
			return '';
		}

		$url  = esc_url_raw( WPNC_Image_Picker::encode_spaces( $raw ), array( 'http', 'https' ) );
		$host = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';

		// esc_url_raw() turns "not a url" into "http://notaurl", so a host
		// without a dot is treated as the typo it almost certainly is.
		if ( '' !== $url && false !== strpos( $host, '.' ) ) {
			return $url;
		}

		if ( ! $strict ) {
			return '';
		}

		$this->fail(
			wpnc__( 'The featured image address is not a valid http or https URL.', 'آدرس تصویر شاخص یک نشانی http یا https معتبر نیست.' ),
			'wpnc_bad_image_url',
			array( 'field' => 'image_url' ),
			422
		);

		return '';
	}

	/**
	 * Run an AI action over the editor's current content.
	 */
	public function ai_transform() {
		$this->check_admin_request();

		if ( ! WPNC_AI_Rewriter::is_configured() ) {
			$this->fail(
				wpnc__(
					'Add an OpenAI API key under Settings to use the assistant.',
					'برای استفاده از دستیار، کلید API اوپن‌ای‌آی را در تنظیمات وارد کنید.'
				),
				'wpnc_ai_not_configured',
				array(),
				409
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 180 );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content     = isset( $_POST['content'] ) ? wp_kses( wp_unslash( $_POST['content'] ), WPNC_AI_Rewriter::allowed_html() ) : '';
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$action      = isset( $_POST['ai_action'] ) ? sanitize_key( wp_unslash( $_POST['ai_action'] ) ) : 'rewrite';
		$instruction = isset( $_POST['instruction'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instruction'] ) ) : '';

		$rewriter = new WPNC_AI_Rewriter();
		$result   = $rewriter->transform(
			array(
				'content'     => $content,
				'title'       => $title,
				'action'      => $action,
				'instruction' => $instruction,
				'language'    => sanitize_text_field( get_option( 'wpnc_target_language', '' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				WPNC_Logger::LEVEL_WARNING,
				wpnc__( 'AI assistant request failed.', 'درخواست دستیار هوش مصنوعی ناموفق بود.' ),
				array(
					'action' => $action,
					'error'  => $result->get_error_message(),
				)
			);

			$this->fail( $result->get_error_message(), 'wpnc_ai_failed' );
		}

		$kind = isset( $result['kind'] ) ? $result['kind'] : 'body';

		if ( 'titles' === $kind ) {
			wp_send_json_success(
				array(
					'kind'        => 'titles',
					'suggestions' => array_map( 'sanitize_text_field', $result['suggestions'] ),
					'message'     => wpnc__( 'Pick a headline below.', 'یکی از عنوان‌های زیر را انتخاب کنید.' ),
				)
			);
		}

		if ( 'seo' === $kind ) {
			wp_send_json_success(
				array(
					'kind'        => 'seo',
					'description' => WPNC_SEO::meta_description( isset( $result['description'] ) ? $result['description'] : '' ),
					'keyword'     => WPNC_SEO::clean_keyword( isset( $result['keyword'] ) ? $result['keyword'] : '' ),
					'message'     => wpnc__(
						'Description and keyword written under the caption. Read them before saving.',
						'توضیحات و کلمهٔ کلیدی زیر کپشن نوشته شد. پیش از ذخیره آن‌ها را بخوانید.'
					),
				)
			);
		}

		// Before the body fall-through below, which would read a content key a
		// caption does not have and hand the editor an empty article.
		if ( 'caption' === $kind ) {
			wp_send_json_success(
				array(
					'kind'    => 'caption',
					'caption' => WPNC_Publish_Options::clean_caption( isset( $result['caption'] ) ? $result['caption'] : '' ),
					'message' => wpnc__(
						'Caption written under the tags. Read it before sending - it goes out as written.',
						'کپشن زیر برچسب‌ها نوشته شد. پیش از ارسال آن را بخوانید؛ همان‌طور که نوشته شده ارسال می‌شود.'
					),
				)
			);
		}

		if ( 'tags' === $kind ) {
			wp_send_json_success(
				array(
					'kind'        => 'tags',
					'suggestions' => array_map( 'sanitize_text_field', $result['suggestions'] ),
					'message'     => wpnc__( 'Suggested tags are below.', 'برچسب‌های پیشنهادی در پایین آمده‌اند.' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'kind'    => 'body',
				'content' => $result['content'],
				'message' => wpnc__( 'The assistant returned a new version.', 'دستیار نسخه جدیدی برگرداند.' ),
			)
		);
	}

	/**
	 * Render what this item would look like once published.
	 *
	 * Goes through WPNC_Template exactly as the publisher does, so the
	 * template, the source line and the tidy-up of empty placeholders are all
	 * the real ones rather than an approximation built in the browser.
	 */
	public function preview_item() {
		$this->check_admin_request();

		$id   = $this->get_posted_id();
		$item = $this->queue->get( $id );

		if ( ! $item ) {
			$this->fail( wpnc__( 'Item not found.', 'آیتم یافت نشد.' ), 'wpnc_not_found', array(), 404 );
		}

		// The editor's current text, not what is stored: previewing the saved
		// copy would ignore everything done since the modal opened.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content = isset( $_POST['content'] ) ? wp_kses( wp_unslash( $_POST['content'] ), WPNC_AI_Rewriter::allowed_html() ) : '';
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$tags    = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';

		// The picture the editor currently shows, for the same reason as the text.
		$image_url = isset( $_POST['image_url'] ) ? $this->posted_image_url( false ) : (string) $item->image_url;

		$publisher = new WPNC_Publisher();
		$body      = $publisher->build_content(
			array(
				'content'     => $content,
				'title'       => $title,
				'source_name' => $item->source_name,
				'main_link'   => $item->main_link,
				'pub_date'    => $item->pub_date,
				'image_url'   => $image_url,
				'tags'        => $tags,
			)
		);

		$plain = wp_strip_all_tags( $content );

		wp_send_json_success(
			array(
				'title'    => $title,
				'html'     => $body,
				'featured' => $image_url,
				'stats'    => array(
					'words'   => WPNC_Template::word_count( $plain ),
					'minutes' => WPNC_Template::reading_minutes( $plain ),
				),
			)
		);
	}

	/**
	 * Everything the dashboard draws, in one request.
	 */
	public function get_dashboard() {
		$this->check_admin_request();

		$fetcher = new WPNC_Fetcher();
		$sources = $fetcher->get_sources();
		$health  = $fetcher->get_source_health();

		$ok      = 0;
		$failing = 0;
		$paused  = 0;
		$unsafe  = 0;

		foreach ( $sources as $source ) {
			if ( empty( $source['valid'] ) ) {
				$unsafe++;
				continue;
			}
			if ( empty( $source['enabled'] ) ) {
				$paused++;
				continue;
			}

			$record = isset( $health[ $source['id'] ] ) && is_array( $health[ $source['id'] ] )
				? $health[ $source['id'] ]
				: array();

			if ( absint( isset( $record['fails'] ) ? $record['fails'] : 0 ) > 0 ) {
				$failing++;
			} else {
				$ok++;
			}
		}

		$last_run = (string) get_option( 'wpnc_last_run', '' );
		$summary  = get_option( 'wpnc_last_summary', array() );

		wp_send_json_success(
			array(
				'totals'   => $this->queue->get_totals(),
				'activity' => $this->queue->get_daily_activity( 14 ),
				'sources'  => $this->queue->get_top_sources( 8 ),
				'health'   => array(
					'total'   => count( $sources ),
					'ok'      => $ok,
					'failing' => $failing,
					'paused'  => $paused,
					'unsafe'  => $unsafe,
				),
				'last_run' => array(
					'at'      => '' === $last_run ? '' : WPNC_Time::for_display( $last_run ),
					'summary' => is_array( $summary ) ? $summary : array(),
				),
				'next_run' => $this->next_run_label(),
			)
		);
	}

	/**
	 * Human description of the next scheduled fetch.
	 *
	 * @return string
	 */
	private function next_run_label() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return wpnc__( 'WP-Cron is disabled', 'WP-Cron غیرفعال است' );
		}

		$next = wp_next_scheduled( 'wpnc_fetch_news_event' );
		if ( ! $next ) {
			return wpnc__( 'Not scheduled yet', 'هنوز زمان‌بندی نشده' );
		}

		if ( $next <= time() ) {
			return wpnc__( 'Due now', 'زمانش رسیده' );
		}

		return sprintf(
			/* translators: %s: human readable duration */
			wpnc__( 'in %s', 'تا %s دیگر' ),
			human_time_diff( time(), $next )
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
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';

		wp_send_json_success(
			array(
				'logs'  => $this->logger->get_recent( $limit, $level ),
				'level' => $level,
			)
		);
	}

	/**
	 * Return list of configured RSS sources so the UI can fetch them one by one.
	 */
	public function get_sources_list() {
		$this->check_admin_request();

		$fetcher = new WPNC_Fetcher();
		$sources = $fetcher->get_sources();

		if ( empty( $sources ) ) {
			$this->fail( wpnc__( 'No RSS sources configured.', 'هیچ منبع RSS تنظیم نشده است.' ), 'wpnc_no_sources' );
		}

		// Take the run-level lock here, not per source, so a manual run cannot
		// interleave with the scheduled one. fetch_finalize releases it, and
		// the transient TTL covers a browser that walks away mid-run.
		if ( ! $fetcher->acquire_lock( true ) ) {
			$this->fail(
				wpnc__( 'A fetch job is already running. Wait for it to finish, or clear the lock from Logs & Tools.', 'یک دریافت در حال اجراست. تا پایان آن صبر کنید یا از تب لاگ و ابزارها قفل را پاک کنید.' ),
				'wpnc_locked',
				array( 'locked' => true ),
				409
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
	 * Test outbound requests from this server and report what happened.
	 *
	 * The plugin can tell an editor that something is cutting its requests
	 * short, but it has been saying so on the evidence of one failed request.
	 * This is the experiment behind the claim.
	 */
	public function diagnose_network() {
		$this->check_admin_request();

		// Three probes, each allowed thirty seconds.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 180 );

		$report = WPNC_Diagnostics::run();

		$this->logger->log(
			WPNC_Logger::LEVEL_INFO,
			sprintf(
				/* translators: %s: verdict code */
				wpnc__( 'Connection check ran: %s', 'بررسی اتصال اجرا شد: %s' ),
				$report['verdict']['code']
			),
			$report,
			'diagnostics'
		);

		wp_send_json_success( $report );
	}

	/**
	 * Ask every candidate AI address whether it answers from this server.
	 *
	 * "Choose another provider" is advice nobody can act on without knowing
	 * which ones work from where they are. This answers that.
	 */
	public function probe_endpoints() {
		$this->check_admin_request();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 180 );

		$extra = isset( $_POST['url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ) ) ) : '';

		if ( isset( $_POST['url'] ) && '' !== trim( (string) wp_unslash( $_POST['url'] ) ) && '' === $extra ) {
			$this->fail(
				wpnc__( 'That is not a valid address.', 'آن آدرس معتبر نیست.' ),
				'wpnc_bad_url',
				array( 'field' => 'url' ),
				422
			);
		}

		$report = WPNC_Diagnostics::sweep( $extra );

		$this->logger->log(
			WPNC_Logger::LEVEL_INFO,
			sprintf(
				/* translators: %d: how many addresses answered */
				wpnc__( 'Address check ran: %d answered.', 'بررسی آدرس‌ها اجرا شد: %d آدرس پاسخ داد.' ),
				count( $report['working'] )
			),
			$report,
			'diagnostics'
		);

		wp_send_json_success( $report );
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
			wpnc__( 'Fetch lock cleared by an administrator.', 'قفل دریافت توسط مدیر پاک شد.' ),
			is_array( $lock ) ? $lock : array()
		);

		wp_send_json_success( array( 'message' => wpnc__( 'Fetch lock cleared.', 'قفل دریافت پاک شد.' ) ) );
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
				wpnc__( 'Manual fetch completed.', 'دریافت دستی کامل شد.' ),
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
		// No nonce. This reads published posts and nothing else, so there is
		// no action for a forged request to perform - while a nonce on a page
		// that a cache serves to everyone expires with the cached copy and
		// takes the button down for every visitor a day later.
		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;

		// Read through the same rules as the shortcode and the block, so a page
		// loaded in later is laid out like the one it joins.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WPNC_Bulletin::args() sanitises each value.
		$args = WPNC_Bulletin::args(
			array(
				'limit'    => isset( $_POST['limit'] ) ? wp_unslash( $_POST['limit'] ) : 10,
				'category' => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '',
				'layout'   => isset( $_POST['layout'] ) ? wp_unslash( $_POST['layout'] ) : 'list',
				'image'    => isset( $_POST['image'] ) ? wp_unslash( $_POST['image'] ) : '1',
				'excerpt'  => isset( $_POST['excerpt'] ) ? wp_unslash( $_POST['excerpt'] ) : 30,
				'source'   => isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '1',
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$limit     = $args['limit'];
		$category  = $args['category'];
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
			wp_send_json_error(
				array(
					'message' => __( 'No more posts available.', 'wp-news-collector' ),
					'code'    => 'wpnc_no_more_posts',
				)
			);
		}

		ob_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			WPNC_Shortcode::render_news_item( $args );
		}
		wp_reset_postdata();

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Send a failure response.
	 *
	 * Every error this plugin returns is { message, code, ...extra }. The
	 * admin JS used to carry a messageFromResponse() shim purely because half
	 * these calls sent a bare string and half sent an array.
	 *
	 * @param string $message Human readable message.
	 * @param string $code    Machine readable code.
	 * @param array  $extra   Extra payload.
	 * @param int    $status  HTTP status.
	 */
	private function fail( $message, $code = 'wpnc_error', $extra = array(), $status = 200 ) {
		wp_send_json_error(
			array_merge(
				array(
					'message' => $message,
					'code'    => $code,
				),
				$extra
			),
			$status
		);
	}

	/**
	 * Check admin AJAX nonce and capability.
	 */
	private function check_admin_request() {
		check_ajax_referer( 'wpnc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->fail( wpnc__( 'Unauthorized access.', 'دسترسی غیرمجاز.' ), 'wpnc_forbidden', array(), 403 );
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
			$this->fail( wpnc__( 'Invalid ID provided.', 'شناسه نامعتبر است.' ), 'wpnc_invalid_id', array(), 422 );
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
			$this->fail( wpnc__( 'No valid IDs provided.', 'هیچ شناسه معتبری ارسال نشد.' ), 'wpnc_invalid_ids', array(), 422 );
		}

		return $ids;
	}
}

new WPNC_Ajax();
