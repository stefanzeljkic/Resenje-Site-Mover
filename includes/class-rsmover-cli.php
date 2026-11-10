<?php
/**
 * WP-CLI commands.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Back up and migrate the site from the command line.
 */
class RSMover_CLI {

	/**
	 * Runs the current job to the end.
	 */
	private function run() {
		$last = '';
		do {
			try {
				$job = RSMover_Jobs::step( 30 );
			} catch ( Throwable $e ) {
				WP_CLI::error( html_entity_decode( $e->getMessage(), ENT_QUOTES ) );
				return array();
			}
			if ( ! empty( $job['busy'] ) ) {
				sleep( 5 );
			}
			$progress = RSMover_Jobs::progress( $job );
			if ( $progress['message'] !== $last ) {
				WP_CLI::log( sprintf( '%3d%%  %s', $progress['percent'], $progress['message'] ) );
				$last = $progress['message'];
			}
		} while ( 'done' !== $job['phase'] );
		return $job;
	}

	/**
	 * Creates a backup of the whole site.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-media]
	 * : Leave out the uploads folder.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-mover export
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		try {
			RSMover_Jobs::start( 'export', array( 'exclude_media' => ! empty( $assoc_args['exclude-media'] ) ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( html_entity_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		$job = $this->run();
		WP_CLI::success( RSMover_Storage::dir() . '/' . $job['archive'] );
	}

	/**
	 * Imports a backup into this site (replaces content, users and settings).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .rsm file, or the name of a file in the backup folder.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		$file = (string) $args[0];
		$name = basename( $file );
		if ( ! RSMover_Storage::archive_path( $name ) ) {
			if ( ! is_file( $file ) || '.rsm' !== substr( $name, -4 ) ) {
				WP_CLI::error( 'File not found or not a .rsm file.' );
			}
			$name = RSMover_Storage::new_archive_name( 'upload' );
			if ( ! copy( $file, RSMover_Storage::archive_path( $name, false ) ) ) {
				WP_CLI::error( 'Cannot copy the file into the backup folder.' );
			}
		}
		WP_CLI::confirm( 'This replaces all content, users and settings of this site. Continue?', $assoc_args );
		try {
			RSMover_Jobs::start( 'import', array( 'archive' => $name ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( html_entity_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		$this->run();
		WP_CLI::success( 'Import complete. Log in with a user of the source site.' );
	}

	/**
	 * Runs the scheduled backup now (with upload to remote storage and retention), e.g. from a server cron job.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-mover scheduled-backup
	 *
	 * @subcommand scheduled-backup
	 */
	public function scheduled_backup() {
		try {
			RSMover_Schedule::start();
		} catch ( Throwable $e ) {
			WP_CLI::error( html_entity_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		$this->run();
		$log = get_option( RSMover_Schedule::LOG, array() );
		WP_CLI::success( isset( $log[0]['message'] ) ? $log[0]['message'] : 'Done.' );
	}

	/**
	 * Tests the remote storage configured for scheduled backups.
	 *
	 * @subcommand test-remote
	 */
	public function test_remote() {
		try {
			$remote = RSMover_Remote::from_settings( RSMover_Schedule::get() );
			if ( ! $remote ) {
				WP_CLI::error( 'No remote storage is selected.' );
			}
			WP_CLI::success( html_entity_decode( $remote->test(), ENT_QUOTES ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( html_entity_decode( $e->getMessage(), ENT_QUOTES ) );
		}
	}

	/**
	 * Lists backups.
	 *
	 * @subcommand list
	 */
	public function list_() {
		$items = array();
		foreach ( RSMover_Storage::list_archives() as $item ) {
			$items[] = array(
				'name' => $item['name'],
				'size' => size_format( $item['size'], 1 ),
				'date' => gmdate( 'Y-m-d H:i', $item['time'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'name', 'size', 'date' ) );
	}
}
