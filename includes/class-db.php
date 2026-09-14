<?php
/**
 * Database, activation, and lifecycle logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_DB {

	const SCHEMA_VERSION = '1.6.0';

	/**
	 * Columns the queue table must have for the plugin to write to it.
	 *
	 * Listed so a column dbDelta silently failed to add is caught here rather
	 * than as an edit that reports success and disappears.
	 */
	const QUEUE_COLUMNS = array(
		'id',
		'source_name',
		'feed_url',
		'source_key',
		'guid',
		'title',
		'description',
		'main_link',
		'image_url',
		'pub_date',
		'status',
		'category_id',
		'tags',
		'publish_options',
		'link_hash',
		'post_id',
		'error_message',
		'created_at',
		'updated_at',
		'processed_at',
	);

	/**
	 * Option that records a failed table creation so the admin can be told.
	 */
	const HEALTH_OPTION = 'wpnc_schema_error';

	/**
	 * Mutex so two concurrent requests cannot both run the upgrade.
	 */
	const UPGRADE_LOCK = 'wpnc_upgrading';

	/**
	 * Option recording that the link_hash migration has finished.
	 */
	const LINK_HASH_READY = 'wpnc_link_hash_ready';

	/**
	 * Event that continues that migration between requests.
	 */
	const LINK_HASH_EVENT = 'wpnc_migrate_link_hash_event';

	/**
	 * Constructor.
	 */
	public function __construct() {
		register_activation_hook( WPNC_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( WPNC_PLUGIN_FILE, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
		add_action( self::LINK_HASH_EVENT, array( $this, 'migrate_link_hash' ) );
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
		wp_clear_scheduled_hook( self::LINK_HASH_EVENT );

		// The lock is an option now, so that taking it is atomic. Deactivating
		// mid-run must clear both it and the transient older builds used, or
		// the next activation starts out locked.
		delete_option( WPNC_Fetcher::LOCK_KEY );
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

			// Started here and continued in the background. An install with a
			// large queue cannot have every row rewritten inside whichever
			// request happened to load the plugin after an update.
			$this->migrate_link_hash();

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
	 * Give every queue row a key derived from its whole address.
	 *
	 * Uniqueness used to rest on the first 191 characters of main_link, which
	 * is all a MySQL index can cover of a column that size. One Persian letter
	 * costs six of those characters once percent-encoded, so two genuinely
	 * different articles from the same section could share a prefix - and the
	 * second was rejected as a duplicate and lost, with nothing to show for it
	 * but "Failed to insert queue item" in the log.
	 *
	 * Runs in bounded passes and reschedules itself, because rewriting every
	 * row of a long queue does not belong in whichever page load happened to
	 * follow the update.
	 *
	 * @return bool True when the migration is complete.
	 */
	public function migrate_link_hash() {
		global $wpdb;

		if ( get_option( self::LINK_HASH_READY, 0 ) ) {
			return true;
		}

		$table = $wpdb->prefix . 'news_queue';

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$this->add_missing_queue_columns( $table );

		if ( in_array( 'link_hash', $this->missing_columns( $table, array( 'link_hash' ) ), true ) ) {
			// Without the column there is nothing to fill; the schema error
			// option already records why.
			return false;
		}

		$deadline = microtime( true ) + WPNC_Settings::time_budget( 20 );

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT id, main_link FROM `$table` WHERE link_hash = '' LIMIT 200" );
			$rows = (array) $rows;

			foreach ( $rows as $row ) {
				$wpdb->update(
					$table,
					array( 'link_hash' => WPNC_Link::storage_hash( $row->main_link ) ),
					array( 'id' => absint( $row->id ) ),
					array( '%s' ),
					array( '%d' )
				);
			}

			if ( microtime( true ) >= $deadline && count( $rows ) === 200 ) {
				// More to do than this request can afford.
				$this->schedule_link_hash_pass();
				return false;
			}
		} while ( count( $rows ) === 200 );

		$this->finish_link_hash( $table );

		return true;
	}

	/**
	 * Ask for another pass at the backfill shortly.
	 */
	private function schedule_link_hash_pass() {
		if ( ! wp_next_scheduled( self::LINK_HASH_EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::LINK_HASH_EVENT );
		}
	}

	/**
	 * Swap the truncated unique index for one over the whole address.
	 *
	 * @param string $table Queue table name.
	 */
	private function finish_link_hash( $table ) {
		global $wpdb;

		// Normalising addresses can map two rows that were distinct as strings
		// onto one key - the same article under http and https, say. Those
		// rows are kept and given a key of their own rather than deleted:
		// removing somebody's queue rows to add an index would be a poor
		// trade. The oldest keeps the real key, so duplicate detection still
		// recognises the story.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `$table` AS q
			INNER JOIN (
				SELECT link_hash, MIN(id) AS keep_id
				FROM `$table`
				WHERE link_hash <> ''
				GROUP BY link_hash
				HAVING COUNT(*) > 1
			) AS dupes ON dupes.link_hash = q.link_hash AND q.id <> dupes.keep_id
			SET q.link_hash = MD5( CONCAT( 'dup:', q.id ) )"
		);

		if ( $this->index_exists( $table, 'main_link' ) && ! $this->index_is_unique( $table, 'main_link' ) ) {
			// Already the plain lookup index the new schema wants.
			$this->add_link_hash_index( $table );
			return;
		}

		if ( $this->index_exists( $table, 'main_link' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE `$table` DROP INDEX `main_link`" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE `$table` ADD KEY `main_link` (main_link(191))" );
		}

		$this->add_link_hash_index( $table );
	}

	/**
	 * Add the unique index, once nothing collides on it.
	 *
	 * @param string $table Queue table name.
	 */
	private function add_link_hash_index( $table ) {
		global $wpdb;

		if ( ! $this->index_exists( $table, 'link_hash' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE `$table` ADD UNIQUE KEY `link_hash` (link_hash)" );
		}

		if ( $this->index_exists( $table, 'link_hash' ) ) {
			update_option( self::LINK_HASH_READY, 1, false );
			return;
		}

		// The index did not take, so the old guarantee is all there is. Record
		// it rather than leaving the table half-migrated in silence.
		update_option( self::HEALTH_OPTION, $table . ': could not add the link_hash index - ' . (string) $wpdb->last_error );
	}

	/**
	 * Whether a named index exists on a table.
	 *
	 * @param string $table Table name.
	 * @param string $name  Index name.
	 * @return bool
	 */
	private function index_exists( $table, $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `$table` WHERE Key_name = %s", $name ) );

		return ! empty( $found );
	}

	/**
	 * Whether a named index enforces uniqueness.
	 *
	 * @param string $table Table name.
	 * @param string $name  Index name.
	 * @return bool
	 */
	private function index_is_unique( $table, $name ) {
		global $wpdb;

		// Non_unique is 0 for a unique index and 1 otherwise.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `$table` WHERE Key_name = %s", $name ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Non_unique'] ) ) {
				return 0 === (int) $row['Non_unique'];
			}
		}

		return false;
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
		$seen_table      = $wpdb->prefix . 'news_seen';

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
			publish_options text NULL,
			link_hash char(32) DEFAULT '' NOT NULL,
			post_id bigint(20) unsigned DEFAULT 0 NOT NULL,
			error_message text NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY link_hash (link_hash),
			KEY main_link (main_link(191)),
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

		// What the plugin has already imported, kept after the queue row is
		// gone. Retention deletes processed rows, and until this table existed
		// that also deleted the only record that a rejected story had ever
		// been seen - so a feed still carrying it delivered it again as new.
		//
		// Fixed-width hashes rather than the addresses themselves: a URL only
		// ever fitted a truncated prefix index, and one Persian letter costs
		// six characters of it once encoded.
		$seen_sql = "CREATE TABLE $seen_table (
			link_hash char(32) NOT NULL,
			guid_hash char(32) DEFAULT '' NOT NULL,
			source_key varchar(100) DEFAULT '' NOT NULL,
			outcome varchar(20) DEFAULT '' NOT NULL,
			seen_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (link_hash),
			KEY guid_hash (guid_hash),
			KEY seen_at (seen_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $queue_sql );
		dbDelta( $logs_sql );
		dbDelta( $seen_sql );

		// dbDelta declines to add a column often enough - a table it cannot
		// parse, a collation mismatch, an ALTER it decides against - and it
		// says nothing when it does. Retrying it would only fail the same
		// way, so add what is missing directly.
		$this->add_missing_queue_columns( $queue_table );

		// dbDelta never reports failure, so verify instead of assuming.
		$missing = array();
		foreach ( array( $queue_table, $logs_table, $seen_table ) as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		// Checking the tables exist was not enough. dbDelta adds columns to
		// an existing table just as quietly as it creates one, so a column it
		// failed to add left the schema looking healthy while every write
		// touching that column was rejected - which is how an edit could be
		// reported as saved and not be there.
		if ( empty( $missing ) ) {
			foreach ( $this->missing_columns( $queue_table, self::QUEUE_COLUMNS ) as $column ) {
				$missing[] = $queue_table . '.' . $column;
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
	/**
	 * Add any column a migration was supposed to introduce and did not.
	 *
	 * Only columns added after the original schema are listed: the rest come
	 * with CREATE TABLE, and a table missing those is not repairable one
	 * column at a time.
	 *
	 * @param string $table Queue table name.
	 * @return void
	 */
	private function add_missing_queue_columns( $table ) {
		global $wpdb;

		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$added_later = array(
			'publish_options' => 'text NULL',
			'link_hash'       => "char(32) DEFAULT '' NOT NULL",
		);

		$missing = $this->missing_columns( $table, array_keys( $added_later ) );

		foreach ( $missing as $column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` " . $added_later[ $column ] );

			if ( '' !== (string) $wpdb->last_error ) {
				update_option( self::HEALTH_OPTION, $table . '.' . $column . ': ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Which of the given columns the table does not have.
	 *
	 * @param string $table   Table name.
	 * @param array  $columns Column names that must be present.
	 * @return array Missing column names.
	 */
	private function missing_columns( $table, $columns ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" );

		if ( ! is_array( $found ) || empty( $found ) ) {
			// Unreadable rather than incomplete; table_exists() has already
			// spoken for whether it is there at all.
			return array();
		}

		return array_values( array_diff( $columns, $found ) );
	}

	private function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $found === (string) $table;
	}
}

new WPNC_DB();
