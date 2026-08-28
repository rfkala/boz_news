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
	'wpnc_rss_links',
	'wpnc_source_rules',
	'wpnc_interval',
	'wpnc_target_post_type',
	'wpnc_default_category',
	'wpnc_auto_publish',
	'wpnc_default_image',
	'wpnc_extract_full_text',
	'wpnc_include_words',
	'wpnc_exclude_words',
	'wpnc_max_items_per_feed',
	'wpnc_request_timeout',
	'wpnc_openai_api_key',
	'wpnc_openai_model',
	'wpnc_auto_rewrite',
	'wpnc_target_language',
	'wpnc_telegram_token',
	'wpnc_telegram_chat_id',
	'wpnc_admin_notify',
	'wpnc_last_run',
	'wpnc_last_count',
	'wpnc_last_summary',
	'wpnc_schema_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}news_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}news_collector_logs" );
