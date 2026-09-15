<?php
/**
 * Boz News from the command line.
 *
 * WP-Cron only runs when somebody visits the site, so on a quiet site a
 * fifteen-minute schedule can mean hours between fetches. A system cron job
 * running "wp boz-news fetch" keeps to the clock instead; the readme shows
 * the crontab line and the wp-config.php setting that goes with it.
 *
 * Output is plain English, as WP-CLI output is by convention.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_CLI {

	/**
	 * Fetch every source now, as the schedule would.
	 *
	 * ## OPTIONS
	 *
	 * [--budget=<seconds>]
	 * : Stop starting new sources after this many seconds; the rest go first
	 * on the next run. Default 300.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boz-news fetch
	 *     wp boz-news fetch --budget=900
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function fetch( $args, $assoc_args ) {
		$budget = isset( $assoc_args['budget'] ) ? absint( $assoc_args['budget'] ) : 0;

		if ( $budget > 0 ) {
			add_filter(
				'wpnc_time_budget_unlimited',
				function () use ( $budget ) {
					return $budget;
				}
			);
		}

		$fetcher = new WPNC_Fetcher();
		$summary = $fetcher->fetch_news( false );

		foreach ( (array) $summary['messages'] as $message ) {
			WP_CLI::log( wp_strip_all_tags( (string) $message ) );
		}

		$line = sprintf(
			'Sources %d of %d answered. Fetched %d, queued %d, published %d, skipped %d, errors %d.',
			absint( $summary['sources_ok'] ),
			absint( $summary['sources_total'] ),
			absint( $summary['fetched'] ),
			absint( $summary['queued'] ),
			absint( $summary['published'] ),
			absint( $summary['skipped'] ),
			absint( $summary['errors'] )
		);

		if ( absint( $summary['errors'] ) > 0 ) {
			WP_CLI::warning( $line );
			return;
		}

		WP_CLI::success( $line );
	}

	/**
	 * Show the last run, the lock, the queue and each source's health.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boz-news status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$fetcher = new WPNC_Fetcher();
		$queue   = new WPNC_Queue_Repository();
		$stats   = $queue->get_stats();
		$lock    = $fetcher->get_lock();
		$last    = (string) get_option( 'wpnc_last_run', '' );
		$next    = wp_next_scheduled( 'wpnc_fetch_news_event' );

		WP_CLI::log( 'Last run:  ' . ( '' === $last ? 'never' : $last . ' UTC' ) );
		WP_CLI::log( 'Next run:  ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ) . ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? ' (WP-Cron is disabled)' : '' ) );
		WP_CLI::log( 'Lock:      ' . ( $lock ? 'held since ' . gmdate( 'Y-m-d H:i:s', absint( $lock['time'] ) ) . ' UTC' : 'free' ) );
		WP_CLI::log(
			sprintf(
				'Queue:     %d pending, %d approved, %d rejected, %d with errors',
				absint( $stats['pending'] ),
				absint( $stats['approved'] ),
				absint( $stats['rejected'] ),
				absint( $stats['error'] )
			)
		);

		$health = $fetcher->get_source_health();
		$rows   = array();

		foreach ( $fetcher->get_sources() as $index => $source ) {
			$record = isset( $health[ $source['id'] ] ) && is_array( $health[ $source['id'] ] ) ? $health[ $source['id'] ] : array();

			$rows[] = array(
				'index'      => $index,
				'source'     => '' !== $source['source_key'] ? $source['source_key'] : $source['url'],
				'enabled'    => empty( $source['enabled'] ) ? 'no' : 'yes',
				'fails'      => absint( isset( $record['fails'] ) ? $record['fails'] : 0 ),
				'last_ok'    => ! empty( $record['last_ok'] ) ? gmdate( 'Y-m-d H:i', absint( $record['last_ok'] ) ) : '-',
				'last_error' => isset( $record['last_error'] ) ? (string) $record['last_error'] : '',
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No sources are configured.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Release a fetch lock left behind by a run that died.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boz-news unlock
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function unlock( $args, $assoc_args ) {
		$fetcher = new WPNC_Fetcher();

		if ( false === $fetcher->get_lock() ) {
			WP_CLI::log( 'No fetch lock was held.' );
			return;
		}

		$fetcher->release_lock();
		WP_CLI::success( 'Fetch lock released.' );
	}

	/**
	 * Run retention now: old processed queue rows, logs and history.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boz-news cleanup
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		$fetcher = new WPNC_Fetcher();
		$fetcher->cleanup_queue();

		WP_CLI::success( 'Retention cleanup ran.' );
	}
}
