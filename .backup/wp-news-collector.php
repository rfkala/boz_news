<?php
/**
 * Plugin Name: WP News Collector
 * Plugin URI: https://example.com
 * Description: Fetch, moderate, rewrite, and publish news from RSS/Atom sources.
 * Version: 1.1.0
 * Author: Jules
 * Author URI: https://example.com
 * Text Domain: wp-news-collector
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPNC_VERSION', '1.1.0' );
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
	if ( 'toplevel_page_wpnc-news-collector' !== $hook ) {
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
			'i18n'     => array(
				'loading'          => __( 'Loading...', 'wp-news-collector' ),
				'error_loading'    => __( 'Error loading queue.', 'wp-news-collector' ),
				'no_pending'       => __( 'No pending news in the queue.', 'wp-news-collector' ),
				'select_all'       => __( 'Select All', 'wp-news-collector' ),
				'approve_selected' => __( 'Approve Selected', 'wp-news-collector' ),
				'reject_selected'  => __( 'Reject Selected', 'wp-news-collector' ),
				'no_image'         => __( 'No Image', 'wp-news-collector' ),
				'tags'             => __( 'Tags', 'wp-news-collector' ),
				'approve'          => __( 'Approve', 'wp-news-collector' ),
				'edit'             => __( 'Edit', 'wp-news-collector' ),
				'reject'           => __( 'Reject', 'wp-news-collector' ),
				'edit_item'        => __( 'Edit News Item', 'wp-news-collector' ),
				'save'             => __( 'Save', 'wp-news-collector' ),
				'cancel'           => __( 'Cancel', 'wp-news-collector' ),
				'error_approve'    => __( 'Error approving item.', 'wp-news-collector' ),
				'error_reject'     => __( 'Error rejecting item.', 'wp-news-collector' ),
				'error_save'       => __( 'Error saving changes.', 'wp-news-collector' ),
				'processing'       => __( 'Processing...', 'wp-news-collector' ),
				'run_fetch'        => __( 'Run fetch now', 'wp-news-collector' ),
				'fetch_done'       => __( 'Fetch completed.', 'wp-news-collector' ),
				'search'           => __( 'Search...', 'wp-news-collector' ),
				'previous'         => __( 'Previous', 'wp-news-collector' ),
				'next'             => __( 'Next', 'wp-news-collector' ),
				'confirm_reject'   => __( 'Reject selected item(s)?', 'wp-news-collector' ),
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
