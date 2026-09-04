<?php
/**
 * Uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

wp_clear_scheduled_hook( 'wpnc_fetch_news_event' );
wp_clear_scheduled_hook( 'wpnc_cleanup_news_event' );

delete_transient( 'wpnc_fetch_lock' );
delete_transient( 'wpnc_admin_notify_lock' );

$options = array(
	// Settings.
	'wpnc_admin_lang',
	'wpnc_rss_links',
	'wpnc_interval',
	'wpnc_target_post_type',
	'wpnc_default_category',
	'wpnc_post_author',
	'wpnc_post_status',
	'wpnc_auto_publish',
	'wpnc_default_image',
	'wpnc_extract_full_text',
	'wpnc_include_words',
	'wpnc_exclude_words',
	'wpnc_max_items_per_feed',
	'wpnc_request_timeout',
	'wpnc_queue_retention_days',
	'wpnc_log_retention_days',
	'wpnc_ai_provider',
	'wpnc_ai_models',
	'wpnc_ai_keys',
	'wpnc_ai_key_state',
	'wpnc_auto_rewrite',
	'wpnc_target_language',
	'wpnc_ai_base_urls',
	'wpnc_telegram_token',
	'wpnc_telegram_chat_id',
	'wpnc_bale_token',
	'wpnc_bale_chat_id',
	'wpnc_channel_verified',

	// Runtime state.
	'wpnc_last_run',
	'wpnc_last_count',
	'wpnc_last_summary',
	'wpnc_source_health',
	'wpnc_schema_version',
	'wpnc_schema_error',

	// Removed in earlier versions; deleted so upgraded sites leave no rows.
	'wpnc_admin_notify',
	'wpnc_source_rules',
	'wpnc_openai_api_key',
	'wpnc_openai_model',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Post meta written by the publisher.
foreach ( array( '_wpnc_source_url', '_wpnc_source_name', '_wpnc_source_guid', '_wpnc_source_image', 'wpnc_source_image' ) as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}news_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}news_collector_logs" );
