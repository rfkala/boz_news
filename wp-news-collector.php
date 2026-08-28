<?php
/**
 * Plugin Name: Boz News
 * Plugin URI: https://example.com
 * Description: Fetch, moderate, rewrite, and publish news from RSS/Atom sources.
 * Version: 1.2.0
 * Author: Arash
 * Text Domain: wp-news-collector
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPNC_VERSION', '1.2.0' );
define( 'WPNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPNC_PLUGIN_FILE', __FILE__ );

require_once WPNC_PLUGIN_DIR . 'includes/class-settings.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-db.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-logger.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-queue-repository.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-feed-reader.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-image-service.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-telegram.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-publisher.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-cpt.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-fetcher.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ajax.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-shortcode.php';

if ( is_admin() ) {
	require_once WPNC_PLUGIN_DIR . 'includes/class-admin.php';
}

/**
 * Load plugin translations.
 */
function wpnc_load_textdomain() {
	load_plugin_textdomain( 'wp-news-collector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wpnc_load_textdomain' );

/**
 * Register shared frontend assets.
 */
function wpnc_register_frontend_assets() {
	wp_register_style( 'wpnc-frontend-style', WPNC_PLUGIN_URL . 'assets/frontend.css', array(), WPNC_VERSION );
	wp_register_script( 'wpnc-frontend-script', WPNC_PLUGIN_URL . 'assets/frontend.js', array( 'jquery' ), WPNC_VERSION, true );
	wp_localize_script(
		'wpnc-frontend-script',
		'wpnc_frontend_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wpnc_frontend_nonce' ),
			'i18n'     => array(
				'loading'   => __( 'Loading...', 'wp-news-collector' ),
				'load_more' => __( 'Load More News', 'wp-news-collector' ),
				'no_more'   => __( 'No more news', 'wp-news-collector' ),
				'error'     => __( 'Could not load more news. Please try again.', 'wp-news-collector' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'wpnc_register_frontend_assets' );

/**
 * Enqueue admin assets on plugin pages.
 *
 * @param string $hook Admin hook.
 */
function wpnc_enqueue_admin_assets( $hook ) {
	if ( 'toplevel_page_boz-news' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'wpnc-admin-style', WPNC_PLUGIN_URL . 'assets/admin.css', array(), WPNC_VERSION );
	wp_enqueue_script( 'wpnc-admin-script', WPNC_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), WPNC_VERSION, true );
	wp_localize_script(
		'wpnc-admin-script',
		'wpnc_ajax',
		array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'wpnc_admin_nonce' ),
			'lang'           => get_option( 'wpnc_admin_lang', 'fa' ),
			'post_edit_base' => admin_url( 'post.php?action=edit&post=' ),
			'i18n'           => array(
				'loading'             => 'Loading...',
				'processing'          => 'Processing...',
				'done'                => 'Done.',
				'saved'               => 'Saved.',
				'retry'               => 'Try again',
				'search'              => 'Search...',
				'previous'            => 'Previous',
				'next'                => 'Next',
				'of'                  => 'of',
				'pending_opt'         => 'Pending',
				'error_opt'           => 'Error',
				'approved_opt'        => 'Approved',
				'rejected_opt'        => 'Rejected',
				'filter_status'       => 'Filter by status',
				'select_all'          => 'Select All',
				'select_item'         => 'Select this item',
				'select_something'    => 'Select at least one item first.',
				'approve'             => 'Approve',
				'edit'                => 'Edit',
				'reject'              => 'Reject',
				'approve_selected'    => 'Approve Selected',
				'reject_selected'     => 'Reject Selected',
				'confirm_reject'      => 'Reject selected item(s)?',
				'view_post'           => 'View post',
				'no_image'            => 'No Image',
				'tags'                => 'Tags',
				'edit_item'           => 'Edit News Item',
				'field_title'         => 'Title',
				'field_description'   => 'Description',
				'field_tags'          => 'Tags (comma separated)',
				'title_required'      => 'Title is required.',
				'save'                => 'Save',
				'cancel'              => 'Cancel',
				'no_pending'          => 'No pending news in the queue.',
				'empty_pending_hint'  => 'Add RSS sources under Settings, then run Fetch Now from Logs & Tools.',
				'empty_search_hint'   => 'No item matches this search. Clear the search box to see the whole queue.',
				'empty_status_hint'   => 'Nothing has reached this status yet.',
				'no_logs'             => 'No logs yet.',
				'empty_logs_hint'     => 'Run Fetch Now above and the result will appear here.',
				'empty_stats_hint'    => 'The queue is empty. Add sources under Settings, then run Fetch Now.',
				'delete'              => 'Delete',
				'delete_selected'     => 'Delete Selected',
				'undo_approve'        => 'Undo approve',
				'confirm_delete'      => 'Permanently delete this item from the queue? Any post it already published stays on the site.',
				'confirm_delete_bulk' => 'Permanently delete the selected items from the queue? This cannot be undone.',
				'confirm_unpublish'   => 'Move the published post to Trash and return this item to the queue?',
				'pause_source'        => 'Pause',
				'resume_source'       => 'Resume',
				'empty_level_hint'    => 'Nothing was logged at this level. Choose All to see every entry.',
				'error_network'       => 'Could not reach the server. Check your connection and try again.',
				'error_server'        => 'The server returned an error. Check Logs & Tools for details.',
				'error_forbidden'     => 'Your session expired or you lack permission. Reload the page and sign in again.',
				'error_timeout'       => 'The request timed out. Try again.',
				'error_parse'         => 'The server sent an unreadable response. Check Logs & Tools.',
				'col_time'            => 'Time',
				'col_level'           => 'Level',
				'col_source'          => 'Source',
				'col_message'         => 'Message',
				'fetch_done'          => 'Fetch completed.',
				'fetching_source'     => 'Fetching source',
				'no_sources'          => 'No RSS sources configured.',
				'lock_cleared'        => 'Lock cleared.',
				'confirm_clear_lock'  => 'Clear the fetch lock? Only do this if a previous run is stuck.',
				'fetched'             => 'Fetched',
				'queued_lc'           => 'queued',
				'published_lc'        => 'published',
				'skipped_lc'          => 'skipped',
				'errors_lc'           => 'errors',
			),
			'i18n_fa'        => array(
				'loading'             => 'در حال بارگذاری...',
				'processing'          => 'در حال پردازش...',
				'done'                => 'انجام شد.',
				'saved'               => 'ذخیره شد.',
				'retry'               => 'تلاش دوباره',
				'search'              => 'جستجو...',
				'previous'            => 'قبلی',
				'next'                => 'بعدی',
				'of'                  => 'از',
				'pending_opt'         => 'در انتظار',
				'error_opt'           => 'خطا',
				'approved_opt'        => 'تأییدشده',
				'rejected_opt'        => 'ردشده',
				'filter_status'       => 'فیلتر بر اساس وضعیت',
				'select_all'          => 'انتخاب همه',
				'select_item'         => 'انتخاب این آیتم',
				'select_something'    => 'ابتدا حداقل یک آیتم را انتخاب کنید.',
				'approve'             => 'تأیید',
				'edit'                => 'ویرایش',
				'reject'              => 'رد',
				'approve_selected'    => 'تأیید انتخاب‌شده‌ها',
				'reject_selected'     => 'رد انتخاب‌شده‌ها',
				'confirm_reject'      => 'آیتم(های) انتخاب‌شده رد شوند؟',
				'view_post'           => 'مشاهده پست',
				'no_image'            => 'بدون تصویر',
				'tags'                => 'برچسب‌ها',
				'edit_item'           => 'ویرایش خبر',
				'field_title'         => 'عنوان',
				'field_description'   => 'توضیحات',
				'field_tags'          => 'برچسب‌ها (با کاما جدا کنید)',
				'title_required'      => 'عنوان الزامی است.',
				'save'                => 'ذخیره',
				'cancel'              => 'انصراف',
				'no_pending'          => 'خبری در صف وجود ندارد.',
				'empty_pending_hint'  => 'ابتدا در تب تنظیمات منابع RSS را اضافه کنید، سپس از تب لاگ و ابزارها «دریافت فوری» را بزنید.',
				'empty_search_hint'   => 'هیچ آیتمی با این جستجو مطابقت ندارد. کادر جستجو را خالی کنید تا کل صف نمایش داده شود.',
				'empty_status_hint'   => 'هنوز چیزی به این وضعیت نرسیده است.',
				'no_logs'             => 'هنوز لاگی ثبت نشده است.',
				'empty_logs_hint'     => 'دکمه «دریافت فوری» بالا را بزنید تا نتیجه اینجا نمایش داده شود.',
				'empty_stats_hint'    => 'صف خالی است. در تنظیمات منبع اضافه کنید و سپس «دریافت فوری» را بزنید.',
				'delete'              => 'حذف',
				'delete_selected'     => 'حذف انتخاب‌شده‌ها',
				'undo_approve'        => 'لغو تأیید',
				'confirm_delete'      => 'این آیتم برای همیشه از صف حذف شود؟ پستی که قبلاً منتشر شده روی سایت باقی می‌ماند.',
				'confirm_delete_bulk' => 'آیتم‌های انتخاب‌شده برای همیشه از صف حذف شوند؟ این کار قابل بازگشت نیست.',
				'confirm_unpublish'   => 'پست منتشرشده به زباله‌دان منتقل و این آیتم به صف بازگردانده شود؟',
				'pause_source'        => 'توقف',
				'resume_source'       => 'فعال‌سازی',
				'empty_level_hint'    => 'در این سطح چیزی ثبت نشده است. برای دیدن همه موارد گزینه «همه» را انتخاب کنید.',
				'error_network'       => 'ارتباط با سرور برقرار نشد. اتصال خود را بررسی و دوباره تلاش کنید.',
				'error_server'        => 'سرور خطا برگرداند. برای جزئیات به تب لاگ و ابزارها مراجعه کنید.',
				'error_forbidden'     => 'نشست شما منقضی شده یا دسترسی ندارید. صفحه را تازه کنید و دوباره وارد شوید.',
				'error_timeout'       => 'زمان درخواست به پایان رسید. دوباره تلاش کنید.',
				'error_parse'         => 'پاسخ سرور قابل خواندن نبود. به تب لاگ و ابزارها مراجعه کنید.',
				'col_time'            => 'زمان',
				'col_level'           => 'سطح',
				'col_source'          => 'منبع',
				'col_message'         => 'پیام',
				'fetch_done'          => 'دریافت کامل شد.',
				'fetching_source'     => 'دریافت منبع',
				'no_sources'          => 'هیچ منبع RSS تنظیم نشده است.',
				'lock_cleared'        => 'قفل پاک شد.',
				'confirm_clear_lock'  => 'قفل دریافت پاک شود؟ فقط زمانی این کار را بکنید که اجرای قبلی گیر کرده باشد.',
				'fetched'             => 'دریافت‌شده',
				'queued_lc'           => 'در صف',
				'published_lc'        => 'منتشرشده',
				'skipped_lc'          => 'رد شده',
				'errors_lc'           => 'خطا',
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'wpnc_enqueue_admin_assets' );

/**
 * Add privacy policy content.
 */
function wpnc_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	wp_add_privacy_policy_content(
		__( 'WP News Collector', 'wp-news-collector' ),
		wp_kses_post(
			__( 'WP News Collector stores RSS feed items in a moderation queue and may send article text to OpenAI for rewriting and published post links to Telegram when those integrations are enabled. Review your configured feeds and API keys to ensure they match your site privacy policy.', 'wp-news-collector' )
		)
	);
}
add_action( 'admin_init', 'wpnc_add_privacy_policy_content' );
