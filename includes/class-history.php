<?php
/**
 * What happened to each queue item, and who did it.
 *
 * Once more than one person moderates, "why is this story on the site" and
 * "who rejected the other one" had no answer: the log recorded runs and
 * errors, not decisions, and nothing tied an entry to an item.
 *
 * describe() and clean_detail() are pure, so they are testable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_History {

	/**
	 * History table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'news_history';
	}

	/**
	 * Record one thing that happened to an item.
	 *
	 * @param int    $item_id Queue item id.
	 * @param string $action  imported, edited, ai, approved, rejected,
	 *                        unpublished or failed.
	 * @param array  $detail  Scalar facts about it.
	 * @return bool
	 */
	public static function record( $item_id, $action, $detail = array() ) {
		global $wpdb;

		$item_id = absint( $item_id );

		if ( ! $item_id ) {
			return false;
		}

		return (bool) $wpdb->insert(
			self::table(),
			array(
				'item_id'    => $item_id,
				'action'     => sanitize_key( $action ),
				'user_id'    => get_current_user_id(),
				'detail'     => wp_json_encode( self::clean_detail( $detail ) ),
				'created_at' => WPNC_Time::now(),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * An item's history, newest first, in words.
	 *
	 * @param int $item_id Queue item id.
	 * @param int $limit   Most entries returned.
	 * @return array of { when, user, text }
	 */
	public static function for_item( $item_id, $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, user_id, detail, created_at FROM $table WHERE item_id = %d ORDER BY id DESC LIMIT %d",
				absint( $item_id ),
				max( 1, min( 200, absint( $limit ) ) )
			)
		);

		$names = array();
		$out   = array();

		foreach ( (array) $rows as $row ) {
			$user_id = absint( $row->user_id );

			if ( $user_id && ! isset( $names[ $user_id ] ) ) {
				$user               = get_userdata( $user_id );
				$names[ $user_id ] = $user ? $user->display_name : '';
			}

			$detail = json_decode( (string) $row->detail, true );

			$out[] = array(
				'when' => WPNC_Time::for_display( $row->created_at ),
				'user' => $user_id ? $names[ $user_id ] : wpnc__( 'Automatic', 'خودکار' ),
				'text' => self::describe( (string) $row->action, is_array( $detail ) ? $detail : array() ),
			);
		}

		return $out;
	}

	/**
	 * Remove entries older than the retention period.
	 *
	 * @param int $days Days to keep.
	 * @return int|false
	 */
	public static function cleanup( $days ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", WPNC_Time::days_ago( $days ) ) );
	}

	/**
	 * One entry as a sentence.
	 *
	 * @param string $action Action key.
	 * @param array  $detail Stored facts.
	 * @return string
	 */
	public static function describe( $action, $detail ) {
		$detail = is_array( $detail ) ? $detail : array();

		switch ( $action ) {
			case 'imported':
				return ! empty( $detail['source'] )
					? sprintf(
						/* translators: %s: source name */
						wpnc__( 'Imported from %s', 'از %s وارد شد' ),
						$detail['source']
					)
					: wpnc__( 'Imported', 'وارد شد' );

			case 'edited':
				return wpnc__( 'Edited', 'ویرایش شد' );

			case 'ai':
				$actions = WPNC_AI_Rewriter::actions();
				$name    = ( isset( $detail['ai_action'] ) && isset( $actions[ $detail['ai_action'] ] ) )
					? $actions[ $detail['ai_action'] ]
					: wpnc__( 'a custom instruction', 'یک دستور دلخواه' );

				return sprintf(
					/* translators: %s: assistant action name */
					wpnc__( 'Assistant: %s', 'دستیار: %s' ),
					$name
				);

			case 'approved':
				if ( ! empty( $detail['scheduled'] ) ) {
					return sprintf(
						/* translators: %s: publication date and time */
						wpnc__( 'Approved and scheduled for %s', 'تأیید و برای %s زمان‌بندی شد' ),
						$detail['scheduled']
					);
				}

				$names = array();
				foreach ( (array) ( isset( $detail['channels'] ) ? $detail['channels'] : array() ) as $slug ) {
					$channel = WPNC_Channels::get( $slug );
					if ( ! empty( $channel ) ) {
						$names[] = $channel['label'];
					}
				}

				return empty( $names )
					? wpnc__( 'Approved', 'تأیید شد' )
					: sprintf(
						/* translators: %s: comma separated destination names */
						wpnc__( 'Approved and sent to %s', 'تأیید و به %s ارسال شد' ),
						implode( wpnc__( ', ', '، ' ), $names )
					);

			case 'rejected':
				return wpnc__( 'Rejected', 'رد شد' );

			case 'unpublished':
				return wpnc__( 'Approval undone and the post moved to Trash', 'تأیید لغو شد و پست به زباله‌دان رفت' );

			case 'failed':
				return ! empty( $detail['error'] )
					? sprintf(
						/* translators: %s: error message */
						wpnc__( 'Publishing failed: %s', 'انتشار ناموفق بود: %s' ),
						$detail['error']
					)
					: wpnc__( 'Publishing failed', 'انتشار ناموفق بود' );
		}

		return (string) $action;
	}

	/**
	 * Facts reduced to short scalars.
	 *
	 * The detail column is read back into the panel, so nothing that is not a
	 * short piece of text goes into it: no objects, no nested structures, no
	 * error message long enough to be a page of HTML.
	 *
	 * @param mixed $detail Raw facts.
	 * @return array
	 */
	public static function clean_detail( $detail ) {
		$out = array();

		foreach ( (array) $detail as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$list = array();
				foreach ( $value as $entry ) {
					if ( is_scalar( $entry ) ) {
						$list[] = self::short( (string) $entry );
					}
				}
				$out[ $key ] = $list;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = self::short( (string) $value );
			}
		}

		return $out;
	}

	/**
	 * A value short enough to show in a line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( $text ) {
		$text = sanitize_text_field( $text );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300, 'UTF-8' ) : substr( $text, 0, 300 );
	}
}
