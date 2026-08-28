<?php
/**
 * Lightweight operational logging for WP News Collector.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Log an event to the custom log table.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @param string $source  Optional feed/source key.
	 * @return bool
	 */
	public function log( $level, $message, $context = array(), $source = '' ) {
		global $wpdb;

		$level = in_array( $level, array( self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR ), true ) ? $level : self::LEVEL_INFO;

		$inserted = $wpdb->insert(
			$this->table_name(),
			array(
				'level'      => $level,
				'source'     => sanitize_text_field( $source ),
				'message'    => sanitize_text_field( $message ),
				'context'    => wp_json_encode( $context ),
				'created_at' => WPNC_Time::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * Get recent logs.
	 *
	 * @param int $limit Number of rows.
	 * @return array
	 */
	public function get_recent( $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 200, absint( $limit ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, level, source, message, context, created_at FROM {$this->table_name()} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['created_at_display'] = WPNC_Time::for_display( $row['created_at'] );
		}

		return $rows;
	}

	/**
	 * Delete old logs.
	 *
	 * @param int $days Retention days.
	 * @return int|false
	 */
	public function cleanup( $days = 30 ) {
		global $wpdb;

		$threshold  = WPNC_Time::days_ago( $days );
		$table_name = $this->table_name();

		return $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE created_at < %s", $threshold ) );
	}

	/**
	 * Clear all logs.
	 *
	 * @return int|false
	 */
	public function clear() {
		global $wpdb;

		return $wpdb->query( "TRUNCATE TABLE {$this->table_name()}" );
	}

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'news_collector_logs';
	}
}
