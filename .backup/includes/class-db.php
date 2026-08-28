<?php
/**
 * Database, activation, and lifecycle logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_DB {

	const SCHEMA_VERSION = '1.1.0';

	/**
	 * Constructor.
	 */
	public function __construct() {
		register_activation_hook( WPNC_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( WPNC_PLUGIN_FILE, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Plugin activation hook.
	 */
	public function activate() {
		$this->create_tables();
		update_option( 'wpnc_schema_version', self::SCHEMA_VERSION );

		if ( class_exists( 'WPNC_CPT' ) ) {
			$cpt = new WPNC_CPT();
			$cpt->register_cpt();
			flush_rewrite_rules();
		}
	}

	/**
	 * Plugin deactivation hook.
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'wpnc_fetch_news_event' );
		wp_clear_scheduled_hook( 'wpnc_cleanup_news_event' );
		delete_transient( 'wpnc_fetch_lock' );
		flush_rewrite_rules();
	}

	/**
	 * Upgrade schema when plugin files change.
	 */
	public function maybe_upgrade() {
		$version = get_option( 'wpnc_schema_version', '0' );
		if ( version_compare( $version, self::SCHEMA_VERSION, '<' ) ) {
			$this->create_tables();
			update_option( 'wpnc_schema_version', self::SCHEMA_VERSION );
		}
	}

	/**
	 * Create or upgrade custom tables.
	 */
	private function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$queue_table     = $wpdb->prefix . 'news_queue';
		$logs_table      = $wpdb->prefix . 'news_collector_logs';

		$queue_sql = "CREATE TABLE $queue_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_name varchar(255) NOT NULL,
			feed_url varchar(2083) DEFAULT '' NOT NULL,
			source_key varchar(100) DEFAULT '' NOT NULL,
			guid varchar(255) DEFAULT '' NOT NULL,
			title text NOT NULL,
			description mediumtext NOT NULL,
			main_link varchar(2083) NOT NULL,
			image_url varchar(2083) DEFAULT '' NOT NULL,
			pub_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			status varchar(50) DEFAULT 'pending' NOT NULL,
			category_id bigint(20) unsigned DEFAULT 0 NOT NULL,
			tags varchar(255) DEFAULT '' NOT NULL,
			post_id bigint(20) unsigned DEFAULT 0 NOT NULL,
			error_message text NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY main_link (main_link(191)),
			KEY guid (guid(191)),
			KEY status_pub_date (status, pub_date),
			KEY post_id (post_id),
			KEY source_key (source_key)
		) $charset_collate;";

		$logs_sql = "CREATE TABLE $logs_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) DEFAULT 'info' NOT NULL,
			source varchar(100) DEFAULT '' NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY source (source),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $queue_sql );
		dbDelta( $logs_sql );
	}
}

new WPNC_DB();
