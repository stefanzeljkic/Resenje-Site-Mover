<?php
/**
 * Job runner shared by the admin AJAX handlers and WP-CLI.
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
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 20.0;
		}
		return (float) max( 3, min( 20, (int) floor( $max / 2 ) - 1 ) );
	}

	/**
	 * Starts a job.
	 *
	 * @param string $type    export or import.
	 * @param array  $options Options (exclude_media for export, archive for import).
	 * @return array Job.
	 * @throws RuntimeException When another job is running or the job cannot start.
	 */
	public static function start( $type, array $options ) {
		$current = RSMover_Storage::get_job();
		if ( $current && 'done' !== $current['phase'] ) {
			if ( time() - (int) $current['updated'] < 600 ) {
				throw new RuntimeException( esc_html__( 'Another backup or import is running. Wait for it or cancel it first.', 'resenje-site-mover' ) );
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
	 * @return array Job after the step.
	 * @throws RuntimeException On errors (the job is cancelled).
	 */
	public static function step( $budget = null ) {
		$job = RSMover_Storage::get_job();
		if ( ! $job ) {
			throw new RuntimeException( esc_html__( 'No backup or import is running.', 'resenje-site-mover' ) );
		}
		if ( 'done' === $job['phase'] ) {
			return $job;
		}
		$deadline = microtime( true ) + ( null === $budget ? self::budget() : (float) $budget );
		try {
			$job = 'export' === $job['type'] ? RSMover_Export::step( $job, $deadline ) : RSMover_Import::step( $job, $deadline );
		} catch ( Exception $e ) {
			self::cancel();
			throw new RuntimeException( esc_html( $e->getMessage() ) );
		}
		$job['updated'] = time();
		if ( 'done' === $job['phase'] ) {
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
			} else {
				RSMover_Import::cleanup( $job );
			}
		}
		RSMover_Storage::set_job( null );
	}
}
