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

		$updated = $wpdb->update(
			$this->table_name(),
			array(
				'title'       => sanitize_text_field( $data['title'] ?? '' ),
				'description' => wp_kses_post( $data['description'] ?? '' ),
				'tags'        => sanitize_text_field( $data['tags'] ?? '' ),
				'updated_at'  => WPNC_Time::now(),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%s' ),
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
			'id'            => (int) $item->id,
			'source_name'   => (string) $item->source_name,
			'feed_url'      => isset( $item->feed_url ) ? (string) $item->feed_url : '',
			'source_key'    => isset( $item->source_key ) ? (string) $item->source_key : '',
			'guid'          => isset( $item->guid ) ? (string) $item->guid : '',
			'title'         => (string) $item->title,
			'description'   => (string) $item->description,
			'main_link'     => (string) $item->main_link,
			'image_url'     => (string) $item->image_url,
			'pub_date'      => (string) $item->pub_date,
			'status'        => (string) $item->status,
			'category_id'   => (int) $item->category_id,
			'tags'          => (string) $item->tags,
			'post_id'       => isset( $item->post_id ) ? (int) $item->post_id : 0,
			'error_message' => isset( $item->error_message ) ? (string) $item->error_message : '',
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
