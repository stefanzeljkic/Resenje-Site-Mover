<?php
/**
 * Job runner shared by the admin AJAX handlers, scheduled backups and WP-CLI.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job runner.
 */
class RSMover_Jobs {

	/**
	 * Seconds one web request may work.
	 *
	 * @return float
	 */
	public static function budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = $max <= 0 ? 20.0 : (float) max( 3, min( 20, (int) floor( $max / 2 ) - 1 ) );
		/**
		 * Filters the seconds one web request works on a backup or import.
		 *
		 * @param float $budget Seconds.
		 */
		return (float) apply_filters( 'rsmover_step_budget', $budget );
	}

	/**
	 * Name of the database lock that serializes steps (browser tab, cron and loopback may overlap).
	 *
	 * @return string
	 */
	private static function lock_name() {
		global $wpdb;
		return 'rsmover_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 );
	}

	/**
	 * Takes the step lock without waiting.
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Releases the step lock.
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Starts a job.
	 *
	 * @param string $type    export or import.
	 * @param array  $options Options (exclude_media and kind for export, archive for import).
	 * @return array Job.
	 * @throws RuntimeException When another job is running or the job cannot start.
	 */
	public static function start( $type, array $options ) {
		$current = RSMover_Storage::get_job();
		if ( $current && 'done' !== $current['phase'] ) {
			if ( time() - (int) $current['updated'] < 600 ) {
				throw new RuntimeException( esc_html__( 'Another backup or import is running. Wait for it or cancel it first.', 'resenje-site-mover' ) );
			}
			if ( ! empty( $current['auto'] ) ) {
				RSMover_Schedule::failed( $current, __( 'The scheduled backup stopped working (no progress for 10 minutes) and was cancelled.', 'resenje-site-mover' ) );
			}
			self::cancel();
		}
		if ( 'export' === $type ) {
			$job = RSMover_Export::start( $options );
		} elseif ( 'import' === $type ) {
			$job = RSMover_Import::start( isset( $options['archive'] ) ? (string) $options['archive'] : '' );
		} else {
			throw new RuntimeException( 'Unknown job type.' );
		}
		$job['updated'] = time();
		RSMover_Storage::set_job( $job );
		return $job;
	}

	/**
	 * Runs one step of the current job.
	 *
	 * @param float|null $budget Seconds to work (null = web default).
	 * @return array Job after the step ('busy' is set when another request holds the lock).
	 * @throws RuntimeException On errors (the job is cancelled).
	 */
	public static function step( $budget = null ) {
		if ( ! self::lock() ) {
			$job = RSMover_Storage::get_job();
			if ( ! $job ) {
				throw new RuntimeException( esc_html__( 'No backup or import is running.', 'resenje-site-mover' ) );
			}
			$job['busy'] = true;
			return $job;
		}
		try {
			return self::locked_step( $budget );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Runs one step while holding the lock.
	 *
	 * @param float|null $budget Seconds to work.
	 * @return array
	 * @throws RuntimeException On errors.
	 */
	private static function locked_step( $budget ) {
		$job = RSMover_Storage::get_job();
		if ( ! $job ) {
			throw new RuntimeException( esc_html__( 'No backup or import is running.', 'resenje-site-mover' ) );
		}
		if ( 'done' === $job['phase'] ) {
			return $job;
		}
		unset( $job['busy'] );
		$deadline = microtime( true ) + ( null === $budget ? self::budget() : (float) $budget );
		try {
			if ( 'export' === $job['type'] ) {
				$job = RSMover_Export::step( $job, $deadline );
			} elseif ( 'upload' === $job['type'] ) {
				$job = RSMover_Schedule::upload_step( $job, $deadline );
			} else {
				$job = RSMover_Import::step( $job, $deadline );
			}
		} catch ( Throwable $e ) {
			if ( ! empty( $job['auto'] ) ) {
				RSMover_Schedule::failed( $job, $e->getMessage() );
			}
			self::cancel();
			throw new RuntimeException( esc_html( $e->getMessage() ) );
		}
		$job['updated'] = time();
		if ( 'done' === $job['phase'] ) {
			$next = null;
			if ( ! empty( $job['auto'] ) && 'export' === $job['type'] ) {
				$next = RSMover_Schedule::export_finished( $job );
			}
			if ( $next ) {
				$next['updated'] = time();
				RSMover_Storage::set_job( $next );
				return $next;
			}
			update_option( 'rsmover_last_success', time(), false );
			RSMover_Storage::set_job( null );
		} else {
			RSMover_Storage::set_job( $job );
		}
		return $job;
	}

	/**
	 * Progress of a job.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( array $job ) {
		if ( 'upload' === $job['type'] ) {
			return RSMover_Schedule::upload_progress( $job );
		}
		return 'export' === $job['type'] ? RSMover_Export::progress( $job ) : RSMover_Import::progress( $job );
	}

	/**
	 * Cancels the current job and removes its temporary data.
	 */
	public static function cancel() {
		$job = RSMover_Storage::get_job();
		if ( $job ) {
			if ( 'export' === $job['type'] ) {
				RSMover_Export::cleanup( $job );
			} elseif ( 'upload' === $job['type'] ) {
				RSMover_Schedule::upload_cleanup( $job );
			} else {
				RSMover_Import::cleanup( $job );
			}
		}
		RSMover_Storage::set_job( null );
	}
}
