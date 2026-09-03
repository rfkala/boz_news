<?php
/**
 * Database, activation, and lifecycle logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_DB {

	const SCHEMA_VERSION = '1.3.0';

	/**
	 * Option that records a failed table creation so the admin can be told.
	 */
	const HEALTH_OPTION = 'wpnc_schema_error';

	/**
	 * Mutex so two concurrent requests cannot both run the upgrade.
	 */
	const UPGRADE_LOCK = 'wpnc_upgrading';

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
		delete_transient( WPNC_Fetcher::LOCK_KEY );
		flush_rewrite_rules();
	}

	/**
	 * Upgrade schema when plugin files change.
	 */
	public function maybe_upgrade() {
		$version = (string) get_option( 'wpnc_schema_version', '0' );

		if ( version_compare( $version, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		// This hook fires on every request. Without a mutex, two concurrent
		// hits on an un-migrated site would both run the UTC shift below and
		// move every timestamp by twice the GMT offset.
		if ( false !== get_transient( self::UPGRADE_LOCK ) ) {
			return;
		}
		set_transient( self::UPGRADE_LOCK, WPNC_Time::timestamp(), 5 * MINUTE_IN_SECONDS );

		try {
			$tables_ready = $this->create_tables();

			// 1.2.0 moved every stored datetime to UTC. Rows written by
			// earlier versions hold site-local time, so shift them once or
			// retention will delete them off by the site's GMT offset.
			//
			// Skipped when the tables are not there: rewriting timestamps in
			// a table that failed to create would only produce SQL errors,
			// and the version must not advance past a migration that never
			// ran.
			if ( ! $tables_ready ) {
				return;
			}

			if ( '0' !== $version && version_compare( $version, '1.2.0', '<' ) ) {
				$this->migrate_datetimes_to_utc( $version );
			}

			if ( version_compare( $version, '1.3.0', '<' ) ) {
				$this->migrate_ai_key_to_pool();
			}

			update_option( 'wpnc_schema_version', self::SCHEMA_VERSION );
		} finally {
			delete_transient( self::UPGRADE_LOCK );
		}
	}

	/**
	 * Shift legacy local-time columns to UTC.
	 *
	 * pub_date is deliberately excluded: it was already written through
	 * gmdate() before 1.2.0 and is therefore already UTC.
	 *
	 * @param string $from_version Version being upgraded from, for the record.
	 */
	private function migrate_datetimes_to_utc( $from_version ) {
		global $wpdb;

		$offset = WPNC_Time::offset_seconds();
		if ( 0 === $offset ) {
			return;
		}

		$queue = $wpdb->prefix . 'news_queue';
		$logs  = $wpdb->prefix . 'news_collector_logs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queue_rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $queue SET
					created_at   = DATE_SUB( created_at, INTERVAL %d SECOND ),
					updated_at   = DATE_SUB( updated_at, INTERVAL %d SECOND ),
					processed_at = IF( processed_at IS NULL, NULL, DATE_SUB( processed_at, INTERVAL %d SECOND ) )",
				$offset,
				$offset,
				$offset
			)
		);

		$log_rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $logs SET created_at = DATE_SUB( created_at, INTERVAL %d SECOND )",
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// A one-way rewrite of every stored timestamp should leave a record
		// saying it happened and by how much.
		$logger = new WPNC_Logger();
		$logger->log(
			WPNC_Logger::LEVEL_WARNING,
			sprintf(
				/* translators: 1: queue row count, 2: log row count, 3: offset in hours */
				wpnc__(
					'Upgrade migrated stored timestamps to UTC: %1$d queue rows and %2$d log rows shifted by %3$s hours.',
					'ارتقا زمان‌های ذخیره‌شده را به UTC منتقل کرد: %1$d ردیف صف و %2$d ردیف لاگ به اندازه %3$s ساعت جابه‌جا شدند.'
				),
				$queue_rows,
				$log_rows,
				number_format_i18n( $offset / HOUR_IN_SECONDS, 1 )
			),
			array(
				'from_version'   => $from_version,
				'offset_seconds' => $offset,
				'queue_rows'     => $queue_rows,
				'log_rows'       => $log_rows,
			)
		);
	}

	/**
	 * Move the single OpenAI key into the multi-provider pool.
	 *
	 * Runs for fresh installs too: activate() stamps the current schema
	 * version, but a site upgrading from any earlier build has a key in the
	 * old option that must not be lost when the settings screen stops
	 * rendering that field.
	 */
	private function migrate_ai_key_to_pool() {
		$legacy = trim( (string) get_option( 'wpnc_openai_api_key', '' ) );
		if ( '' === $legacy ) {
			return;
		}

		$keys = WPNC_AI_Keys::all();
		if ( ! empty( $keys['openai'] ) ) {
			// Already migrated, or keys added by hand since.
			return;
		}

		$keys['openai'][ WPNC_AI_Keys::new_id() ] = $legacy;
		WPNC_AI_Keys::save( $keys );

		$model = trim( (string) get_option( 'wpnc_openai_model', '' ) );
		if ( '' !== $model ) {
			$models = get_option( 'wpnc_ai_models', array() );
			$models = is_array( $models ) ? $models : array();
			if ( empty( $models['openai'] ) ) {
				$models['openai'] = $model;
				update_option( 'wpnc_ai_models', $models, false );
			}
		}

		update_option( 'wpnc_ai_provider', 'openai' );
	}

	/**
	 * Create or upgrade custom tables.
	 *
	 * @return bool True when both tables exist afterwards.
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
			KEY source_key (source_key),
			KEY status_updated (status, updated_at),
			KEY title_search (title(64))
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
			KEY created_at (created_at),
			KEY level_created (level, created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $queue_sql );
		dbDelta( $logs_sql );

		// dbDelta never reports failure, so verify instead of assuming.
		$missing = array();
		foreach ( array( $queue_table, $logs_table ) as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		if ( empty( $missing ) ) {
			delete_option( self::HEALTH_OPTION );
			return true;
		}

		// The log table may be one of the missing ones, so do not log to it.
		update_option( self::HEALTH_OPTION, implode( ', ', $missing ) );

		return false;
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $found === (string) $table;
	}
}

new WPNC_DB();
