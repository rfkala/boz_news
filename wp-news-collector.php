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
 * Register Elementor widget only when Elementor is available.
 *
 * @param object $widgets_manager Elementor widgets manager.
 */
function wpnc_register_elementor_widget( $widgets_manager ) {
	if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
		return;
	}

	require_once WPNC_PLUGIN_DIR . 'includes/class-elementor-widget.php';
	$widgets_manager->register( new \WPNC_Elementor_Widget() );
}
add_action( 'elementor/widgets/register', 'wpnc_register_elementor_widget' );

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
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wpnc_admin_nonce' ),
			'lang'     => get_option( 'wpnc_admin_lang', 'fa' ),
			'i18n'     => array(
				'loading'          => 'Loading...',
				'error_loading'    => 'Error loading queue.',
				'no_pending'       => 'No pending news in the queue.',
				'select_all'       => 'Select All',
				'approve_selected' => 'Approve Selected',
				'reject_selected'  => 'Reject Selected',
				'no_image'         => 'No Image',
				'tags'             => 'Tags',
				'approve'          => 'Approve',
				'edit'             => 'Edit',
				'reject'           => 'Reject',
				'edit_item'        => 'Edit News Item',
				'save'             => 'Save',
				'cancel'           => 'Cancel',
				'error_approve'    => 'Error approving item.',
				'error_reject'     => 'Error rejecting item.',
				'error_save'       => 'Error saving changes.',
				'processing'       => 'Processing...',
				'run_fetch'        => 'Fetch Now',
				'fetch_done'       => 'Fetch completed.',
				'search'           => 'Search...',
				'previous'         => 'Previous',
				'next'             => 'Next',
				'confirm_reject'   => 'Reject selected item(s)?',
				'no_sources'       => 'No RSS sources configured.',
				'fetching_source'  => 'Fetching source',
				'of'               => 'of',
				'fetch_failed'     => 'Fetch failed.',
				'lock_cleared'     => 'Lock cleared.',
				'fetched'          => 'Fetched',
				'queued_lc'        => 'queued',
				'published_lc'     => 'published',
				'errors_lc'        => 'errors',
			),
			'i18n_fa'  => array(
				'loading'          => 'در حال بارگذاری...',
				'error_loading'    => 'خطا در بارگذاری صف.',
				'no_pending'       => 'خبری در صف وجود ندارد.',
				'select_all'       => 'انتخاب همه',
				'approve_selected' => 'تأیید انتخاب‌شده‌ها',
				'reject_selected'  => 'رد انتخاب‌شده‌ها',
				'no_image'         => 'بدون تصویر',
				'tags'             => 'برچسب‌ها',
				'approve'          => 'تأیید',
				'edit'             => 'ویرایش',
				'reject'           => 'رد',
				'edit_item'        => 'ویرایش خبر',
				'save'             => 'ذخیره',
				'cancel'           => 'انصراف',
				'error_approve'    => 'خطا در تأیید.',
				'error_reject'     => 'خطا در رد.',
				'error_save'       => 'خطا در ذخیره تغییرات.',
				'processing'       => 'در حال پردازش...',
				'run_fetch'        => 'دریافت فوری',
				'fetch_done'       => 'دریافت کامل شد.',
				'search'           => 'جستجو...',
				'previous'         => 'قبلی',
				'next'             => 'بعدی',
				'confirm_reject'   => 'آیتم(ها) رد شوند؟',
				'no_sources'       => 'هیچ منبع RSS تنظیم نشده.',
				'fetching_source'  => 'دریافت منبع',
				'of'               => 'از',
				'fetch_failed'     => 'دریافت ناموفق بود.',
				'lock_cleared'     => 'قفل پاک شد.',
				'fetched'          => 'دریافت‌شده',
				'queued_lc'        => 'در صف',
				'published_lc'     => 'منتشرشده',
				'errors_lc'        => 'خطا',
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
