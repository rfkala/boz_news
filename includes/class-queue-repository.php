<?php
/**
 * Queue persistence layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Queue_Repository {

	/**
	 * Queue table name.
	 *
	 * @return string
	 */
	public function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'news_queue';
	}

	/**
	 * Get queue items with pagination.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function get_items( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status' => 'pending',
			'page'   => 1,
			'limit'  => 20,
			'search' => '',
		);
		$args     = wp_parse_args( $args, $defaults );
		$page     = max( 1, absint( $args['page'] ) );
		$limit    = max( 1, min( 100, absint( $args['limit'] ) ) );
		$offset   = ( $page - 1 ) * $limit;
		$status   = $this->normalize_status( $args['status'] );
		$search   = sanitize_text_field( $args['search'] );
		$where    = array( 'status = %s' );
		$params   = array( $status );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(title LIKE %s OR source_name LIKE %s OR main_link LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		$table     = $this->table_name();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM $table WHERE $where_sql",
				$params
			)
		);

		$params[] = $limit;
		$params[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE $where_sql ORDER BY pub_date DESC, id DESC LIMIT %d OFFSET %d",
				$params
			)
		);

		return array(
			'items'       => array_map( array( $this, 'format_for_response' ), $items ),
			'total'       => $total,
			'page'        => $page,
			'limit'       => $limit,
			'total_pages' => max( 1, (int) ceil( $total / $limit ) ),
		);
	}

	/**
	 * Get a queue item by ID.
	 *
	 * @param int $id Queue item ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE id = %d", absint( $id ) )
		);
	}

	/**
	 * Insert a pending queue item.
	 *
	 * @param array $item Item data.
	 * @return int|false
	 */
	public function insert( $item ) {
		global $wpdb;

		$now  = WPNC_Time::now();
		$data = array(
			'source_name'   => sanitize_text_field( $item['source_name'] ?? '' ),
			'feed_url'      => esc_url_raw( $item['feed_url'] ?? '' ),
			'source_key'    => sanitize_key( $item['source_key'] ?? '' ),
			'guid'          => sanitize_text_field( $item['guid'] ?? '' ),
			'title'         => sanitize_text_field( $item['title'] ?? '' ),
			'description'   => wp_kses_post( $item['description'] ?? '' ),
			'main_link'     => esc_url_raw( $item['main_link'] ?? '' ),
			'image_url'     => esc_url_raw( $item['image_url'] ?? '' ),
			'pub_date'      => WPNC_Time::to_utc( $item['pub_date'] ?? '' ),
			'status'        => $this->normalize_status( $item['status'] ?? 'pending' ),
			'category_id'   => absint( $item['category_id'] ?? 0 ),
			'tags'          => sanitize_text_field( $item['tags'] ?? '' ),
			'error_message' => sanitize_text_field( $item['error_message'] ?? '' ),
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$inserted = $wpdb->insert(
			$this->table_name(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update queue item text fields.
	 *
	 * @param int   $id   Item ID.
	 * @param array $data Item data.
	 * @return bool
	 */
	public function update_item( $id, $data ) {
		global $wpdb;

		$fields = array(
			'title'       => sanitize_text_field( $data['title'] ?? '' ),
			'description' => wp_kses_post( $data['description'] ?? '' ),
			'tags'        => sanitize_text_field( $data['tags'] ?? '' ),
			'updated_at'  => WPNC_Time::now(),
		);
		$format = array( '%s', '%s', '%s', '%s' );

		// Only written when the caller actually passed it, so a caller that
		// knows nothing about publishing options cannot blank an item's
		// overrides as a side effect of saving a title.
		if ( array_key_exists( 'publish_options', $data ) ) {
			$options = WPNC_Publish_Options::sanitize( $data['publish_options'] );

			$fields['publish_options'] = WPNC_Publish_Options::encode( $options );
			$format[]                  = '%s';

			// The category has had its own column since before overrides
			// existed; keeping it there means the existing queries and the
			// CSV export go on seeing it.
			$fields['category_id'] = isset( $options['category_id'] ) ? absint( $options['category_id'] ) : 0;
			$format[]              = '%d';
		}

		$updated = $wpdb->update(
			$this->table_name(),
			$fields,
			array( 'id' => absint( $id ) ),
			$format,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Mark a queue item as approved.
	 *
	 * @param int $id      Item ID.
	 * @param int $post_id Published post ID.
	 * @return bool
	 */
	public function mark_approved( $id, $post_id ) {
		return $this->update_status( $id, 'approved', array( 'post_id' => absint( $post_id ) ) );
	}

	/**
	 * Update status.
	 *
	 * @param int    $id     Item ID.
	 * @param string $status Status.
	 * @param array  $extra  Extra fields.
	 * @return bool
	 */
	public function update_status( $id, $status, $extra = array() ) {
		global $wpdb;

		$data = array_merge(
			array(
				'status'       => $this->normalize_status( $status ),
				'processed_at' => WPNC_Time::now(),
				'updated_at'   => WPNC_Time::now(),
			),
			$extra
		);

		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, array( 'post_id' ), true ) ? '%d' : '%s';
		}

		$updated = $wpdb->update(
			$this->table_name(),
			$data,
			array( 'id' => absint( $id ) ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Record processing error.
	 *
	 * @param int    $id      Item ID.
	 * @param string $message Error message.
	 * @return bool
	 */
	public function mark_error( $id, $message ) {
		return $this->update_status(
			$id,
			'error',
			array( 'error_message' => sanitize_text_field( $message ) )
		);
	}

	/**
	 * Statuses a moderator may still act on.
	 *
	 * 'error' is included on purpose: approving an errored row retries the
	 * publish. 'approved' and 'rejected' are terminal, which is what stops a
	 * double click from publishing the same story twice.
	 *
	 * @return array
	 */
	public static function actionable_statuses() {
		return array( 'pending', 'error' );
	}

	/**
	 * Whether a queue row can still be approved or rejected.
	 *
	 * @param object|null $item Queue row.
	 * @return bool
	 */
	public function is_actionable( $item ) {
		return $item && in_array( (string) $item->status, self::actionable_statuses(), true );
	}

	/**
	 * Permanently delete one queue row.
	 *
	 * Only the queue row: a post this item already published is deliberately
	 * left alone, because deleting a moderation record should never remove
	 * live content from the site.
	 *
	 * @param int $id Item ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( $this->table_name(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * Permanently delete several queue rows.
	 *
	 * @param array $ids Item IDs.
	 * @return int Rows deleted.
	 */
	public function delete_many( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ( $placeholders )", $ids ) );

		return (int) $deleted;
	}

	/**
	 * Return an approved row to the queue so it can be reviewed again.
	 *
	 * @param int $id Item ID.
	 * @return bool
	 */
	public function reopen( $id ) {
		global $wpdb;

		$table = $this->table_name();

		// Written as a direct query because $wpdb->update() casts a null
		// value through its %s format into an empty string, which a datetime
		// column rejects under strict mode. processed_at has to become a real
		// SQL NULL or the whole undo fails silently.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET
					status = 'pending',
					post_id = 0,
					error_message = '',
					processed_at = NULL,
					updated_at = %s
				WHERE id = %d",
				WPNC_Time::now(),
				absint( $id )
			)
		);

		return false !== $updated;
	}

	/**
	 * Rows for a CSV export, in the same order the list shows them.
	 *
	 * Reads in chunks so a large queue does not have to fit in memory at once.
	 *
	 * @param array    $args     status and search, as in get_items().
	 * @param callable $callback Receives each row as an array.
	 */
	public function each_for_export( $args, $callback ) {
		global $wpdb;

		$status = $this->normalize_status( isset( $args['status'] ) ? $args['status'] : 'pending' );
		$search = sanitize_text_field( isset( $args['search'] ) ? $args['search'] : '' );
		$table  = $this->table_name();
		$where  = array( 'status = %s' );
		$params = array( $status );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(title LIKE %s OR source_name LIKE %s OR main_link LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		$chunk     = 200;
		$offset    = 0;

		do {
			$batch = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT * FROM $table WHERE $where_sql ORDER BY pub_date DESC, id DESC LIMIT %d OFFSET %d",
					array_merge( $params, array( $chunk, $offset ) )
				)
			);

			foreach ( (array) $batch as $row ) {
				call_user_func( $callback, $this->format_for_response( $row ) );
			}

			$offset += $chunk;
		} while ( count( (array) $batch ) === $chunk );
	}

	/**
	 * Per-day counts for the activity chart.
	 *
	 * Rows are stored in UTC but a dashboard is read in local time, so the
	 * grouping is shifted by the site offset. Otherwise everything imported
	 * after 20:30 in Tehran would land on the following day's bar.
	 *
	 * @param int $days How many days back, including today.
	 * @return array Ordered oldest first: date => counts.
	 */
	public function get_daily_activity( $days = 14 ) {
		global $wpdb;

		$days   = max( 1, min( 90, absint( $days ) ) );
		$offset = WPNC_Time::offset_seconds();
		$table  = $this->table_name();
		$since  = WPNC_Time::days_ago( $days );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE( DATE_ADD( created_at, INTERVAL %d SECOND ) ) AS day,
					COUNT(*) AS total,
					SUM( CASE WHEN status = 'approved' THEN 1 ELSE 0 END ) AS approved,
					SUM( CASE WHEN status = 'rejected' THEN 1 ELSE 0 END ) AS rejected,
					SUM( CASE WHEN status = 'error' THEN 1 ELSE 0 END ) AS errors
				FROM $table
				WHERE created_at >= %s
				GROUP BY day
				ORDER BY day ASC",
				$offset,
				$since
			),
			ARRAY_A
		);

		$byday = array();
		foreach ( (array) $rows as $row ) {
			$byday[ $row['day'] ] = $row;
		}

		// Fill the gaps so the chart has one column per day rather than
		// silently compressing quiet days out of existence.
		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day  = gmdate( 'Y-m-d', WPNC_Time::timestamp() + $offset - ( $i * DAY_IN_SECONDS ) );
			$have = isset( $byday[ $day ] ) ? $byday[ $day ] : array();

			$series[] = array(
				'day'      => $day,
				'label'    => WPNC_Time::day_label( $day ),
				'total'    => absint( isset( $have['total'] ) ? $have['total'] : 0 ),
				'approved' => absint( isset( $have['approved'] ) ? $have['approved'] : 0 ),
				'rejected' => absint( isset( $have['rejected'] ) ? $have['rejected'] : 0 ),
				'errors'   => absint( isset( $have['errors'] ) ? $have['errors'] : 0 ),
			);
		}

		return $series;
	}

	/**
	 * Which sources are actually producing, and how much of it survives
	 * moderation.
	 *
	 * @param int $limit Maximum sources returned.
	 * @return array
	 */
	public function get_top_sources( $limit = 8 ) {
		global $wpdb;

		$limit = max( 1, min( 50, absint( $limit ) ) );
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_name,
					COUNT(*) AS total,
					SUM( CASE WHEN status = 'approved' THEN 1 ELSE 0 END ) AS approved,
					SUM( CASE WHEN status = 'pending' THEN 1 ELSE 0 END ) AS pending
				FROM $table
				WHERE source_name <> ''
				GROUP BY source_name
				ORDER BY total DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'     => (string) $row['source_name'],
				'total'    => absint( $row['total'] ),
				'approved' => absint( $row['approved'] ),
				'pending'  => absint( $row['pending'] ),
			);
		}

		return $out;
	}

	/**
	 * Totals used by the headline cards, including all-time throughput.
	 *
	 * @return array
	 */
	public function get_totals() {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM( CASE WHEN status = 'pending' THEN 1 ELSE 0 END ) AS pending,
				SUM( CASE WHEN status = 'approved' THEN 1 ELSE 0 END ) AS approved,
				SUM( CASE WHEN status = 'rejected' THEN 1 ELSE 0 END ) AS rejected,
				SUM( CASE WHEN status = 'error' THEN 1 ELSE 0 END ) AS errors
			FROM $table",
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'total'    => absint( isset( $row['total'] ) ? $row['total'] : 0 ),
			'pending'  => absint( isset( $row['pending'] ) ? $row['pending'] : 0 ),
			'approved' => absint( isset( $row['approved'] ) ? $row['approved'] : 0 ),
			'rejected' => absint( isset( $row['rejected'] ) ? $row['rejected'] : 0 ),
			'errors'   => absint( isset( $row['errors'] ) ? $row['errors'] : 0 ),
		);
	}

	/**
	 * Neutralise a CSV cell that a spreadsheet would treat as a formula.
	 *
	 * Feed titles come from other people's servers, and Excel and Sheets both
	 * execute a cell beginning with = + - or @, so an exported queue is a
	 * delivery mechanism unless the leading character is defused.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		return false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Check duplicate in queue or posts.
	 *
	 * @param string $main_link Link.
	 * @param string $guid      GUID.
	 * @return bool
	 */
	public function exists( $main_link, $guid = '' ) {
		global $wpdb;

		$table = $this->table_name();

		if ( $main_link ) {
			$queue_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE main_link = %s LIMIT 1", $main_link ) );
			if ( $queue_id ) {
				return true;
			}

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
					'_wpnc_source_url',
					$main_link
				)
			);
			if ( $post_id ) {
				return true;
			}
		}

		if ( $guid ) {
			$queue_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE guid = %s LIMIT 1", $guid ) );
			if ( $queue_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get counts by status.
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$table = $this->table_name();

		return array(
			'approved' => (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table WHERE status = 'approved'" ),
			'pending'  => (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table WHERE status = 'pending'" ),
			'rejected' => (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table WHERE status = 'rejected'" ),
			'error'    => (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table WHERE status = 'error'" ),
		);
	}

	/**
	 * Cleanup processed queue rows.
	 *
	 * @param int $days Retention days.
	 * @return int|false
	 */
	public function cleanup( $days = 14 ) {
		global $wpdb;

		$threshold = WPNC_Time::days_ago( $days );
		$table     = $this->table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE status IN ('approved', 'rejected') AND updated_at < %s",
				$threshold
			)
		);
	}

	/**
	 * Format DB row for AJAX response.
	 *
	 * @param object $item DB row.
	 * @return array
	 */
	public function format_for_response( $item ) {
		return array(
			'id'               => (int) $item->id,
			'source_name'      => (string) $item->source_name,
			'feed_url'         => isset( $item->feed_url ) ? (string) $item->feed_url : '',
			'source_key'       => isset( $item->source_key ) ? (string) $item->source_key : '',
			'guid'             => isset( $item->guid ) ? (string) $item->guid : '',
			'title'            => (string) $item->title,
			'description'      => (string) $item->description,
			'main_link'        => (string) $item->main_link,
			'image_url'        => (string) $item->image_url,
			'pub_date'         => (string) $item->pub_date,
			'pub_date_display' => WPNC_Time::for_display( $item->pub_date ),
			'status'           => (string) $item->status,
			'category_id'      => (int) $item->category_id,
			'tags'             => (string) $item->tags,
			'publish_options'  => WPNC_Publish_Options::decode( isset( $item->publish_options ) ? $item->publish_options : '' ),
			'post_id'          => isset( $item->post_id ) ? (int) $item->post_id : 0,
			'error_message'    => isset( $item->error_message ) ? (string) $item->error_message : '',
		);
	}

	/**
	 * Normalize status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'pending', 'approved', 'rejected', 'error' ), true ) ? $status : 'pending';
	}
}
