<?php
/**
 * Export (backup) engine. Runs in short steps so it works on shared hosting time limits.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Large archives are streamed with native file functions; WP_Filesystem has no streaming API.
// The database is read table by table with direct queries; table names are passed with %i.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery

/**
 * Export engine.
 */
class RSMover_Export {

	const MANIFEST   = '__rsmover__/manifest.json';
	const DATABASE   = '__rsmover__/database.jsonl';
	const CONTENT    = 'content/';
	const FORMAT     = 1;
	const ROW_BATCH  = 500;
	const BYTE_BATCH = 2097152;

	/**
	 * Top-level wp-content folders that are never exported (caches, other backup tools).
	 *
	 * @return string[]
	 */
	public static function excluded_dirs() {
		$dirs = array( 'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'backups-dup-lite', 'backups-dup-pro', 'backup-db', 'backupwordpress', 'wflogs', 'et-cache', 'wpo-cache', 'litespeed' );
		/**
		 * Filters the top-level wp-content folders excluded from backups.
		 *
		 * @param string[] $dirs Folder names.
		 */
		return (array) apply_filters( 'rsmover_excluded_dirs', $dirs );
	}

	/**
	 * Starts a new export job.
	 *
	 * @param array $options Options: exclude_media (bool).
	 * @return array Job state.
	 * @throws RuntimeException On errors.
	 */
	public static function start( array $options ) {
		global $wpdb;

		$name    = RSMover_Storage::new_archive_name( isset( $options['kind'] ) && 'auto' === $options['kind'] ? 'auto' : 'backup' );
		$archive = RSMover_Storage::archive_path( $name, false );
		if ( ! $archive || ! RSMover_Archive::create( $archive ) ) {
			throw new RuntimeException( esc_html__( 'Cannot create the backup file. Check that wp-content is writable.', 'resenje-site-mover' ) );
		}

		$tables = array();
		foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			if ( isset( $row[1] ) && 'BASE TABLE' === $row[1] && 0 === strpos( $row[0], $wpdb->prefix ) ) {
				$tables[] = $row[0];
			}
		}

		$base = substr( $name, 0, -4 );
		$job  = array(
			'type'        => 'export',
			'archive'     => $name,
			'phase'       => 'db',
			'options'     => array( 'exclude_media' => ! empty( $options['exclude_media'] ) ),
			'tables'      => $tables,
			'table_idx'   => 0,
			'row_offset'  => 0,
			'table_open'  => false,
			'db_file'     => $base . '.db.tmp',
			'list_file'   => $base . '.list.tmp',
			'list_offset' => 0,
			'cur'         => null,
			'archive_len' => strlen( RSMover_Archive::MAGIC ),
			'db_len'      => 0,
			'files'       => 0,
			'bytes'       => 0,
			'total_files' => 0,
			'started'     => time(),
		);
		file_put_contents( RSMover_Storage::work_path( $job['db_file'] ), '' );
		return $job;
	}

	/**
	 * Runs one step.
	 *
	 * @param array $job      Job state.
	 * @param float $deadline microtime(true) value to stop at.
	 * @return array Updated job state.
	 */
	public static function step( array $job, $deadline ) {
		switch ( $job['phase'] ) {
			case 'db':
				return self::step_db( $job, $deadline );
			case 'scan':
				return self::step_scan( $job );
			case 'files':
				return self::step_files( $job, $deadline );
			case 'finalize':
				return self::step_finalize( $job );
		}
		return $job;
	}

	/**
	 * Progress information for the UI.
	 *
	 * @param array $job Job state.
	 * @return array percent, message.
	 */
	public static function progress( array $job ) {
		switch ( $job['phase'] ) {
			case 'db':
				$count = max( 1, count( $job['tables'] ) );
				return array(
					'percent' => (int) floor( 30 * $job['table_idx'] / $count ),
					/* translators: 1: current table number, 2: number of tables */
					'message' => sprintf( __( 'Exporting database: table %1$d of %2$d', 'resenje-site-mover' ), min( $job['table_idx'] + 1, $count ), $count ),
				);
			case 'scan':
				return array(
					'percent' => 30,
					'message' => __( 'Listing files…', 'resenje-site-mover' ),
				);
			case 'files':
				$total = max( 1, $job['total_files'] );
				return array(
					'percent' => 30 + (int) floor( 68 * $job['files'] / $total ),
					/* translators: 1: files done, 2: total files */
					'message' => sprintf( __( 'Archiving files: %1$d of %2$d', 'resenje-site-mover' ), $job['files'], $total ),
				);
			case 'finalize':
				return array(
					'percent' => 99,
					'message' => __( 'Finishing…', 'resenje-site-mover' ),
				);
		}
		return array(
			'percent' => 100,
			'message' => __( 'Backup complete.', 'resenje-site-mover' ),
		);
	}

	/**
	 * Encodes a database value for JSON (binary data as base64).
	 *
	 * @param string|null $value Value.
	 * @return mixed
	 */
	private static function encode_value( $value ) {
		if ( null === $value ) {
			return null;
		}
		$value = (string) $value;
		if ( '' === $value || preg_match( '//u', $value ) ) {
			return $value;
		}
		return array( 'b64' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary column data in JSON.
	}

	/**
	 * Exports database rows.
	 *
	 * @param array $job      Job.
	 * @param float $deadline Deadline.
	 * @return array
	 * @throws RuntimeException On errors.
	 */
	private static function step_db( array $job, $deadline ) {
		global $wpdb;

		// Drop anything a previous, interrupted request may have written after the last saved state.
		RSMover_Archive::truncate( RSMover_Storage::work_path( $job['db_file'] ), $job['db_len'] );
		$out = fopen( RSMover_Storage::work_path( $job['db_file'] ), 'ab' );
		if ( ! $out ) {
			throw new RuntimeException( esc_html__( 'Cannot write the database export file.', 'resenje-site-mover' ) );
		}

		$table_count = count( $job['tables'] );
		while ( $job['table_idx'] < $table_count && microtime( true ) < $deadline ) {
			$table = $job['tables'][ $job['table_idx'] ];
			$rel   = substr( $table, strlen( $wpdb->prefix ) );

			if ( ! $job['table_open'] ) {
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				if ( empty( $create[1] ) ) {
					++$job['table_idx'];
					continue;
				}
				$sql     = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `{{TABLE}}`', $create[1], 1 );
				$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
				fwrite(
					$out,
					wp_json_encode(
						array(
							'k'       => 'table',
							'name'    => $rel,
							'sql'     => $sql,
							'columns' => $columns,
						)
					) . "\n"
				);
				$job['table_open'] = true;
				$job['row_offset'] = 0;
			}

			$keys  = $wpdb->get_col( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), 4 );
			$order = '';
			if ( ! empty( $keys ) ) {
				$order = ' ORDER BY ' . implode( ', ', array_fill( 0, count( $keys ), '%i' ) );
			}
			$args = array_merge( array( $table ), $keys, array( $job['row_offset'], self::ROW_BATCH ) );
			// The query string only contains %i / %d placeholders built above.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $order . ' LIMIT %d, %d', $args ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only %i placeholders for the primary key columns are added.

			if ( empty( $rows ) ) {
				$job['table_open'] = false;
				++$job['table_idx'];
				continue;
			}

			$batch = array();
			$bytes = 0;
			foreach ( $rows as $row ) {
				$encoded = array_map( array( __CLASS__, 'encode_value' ), $row );
				$batch[] = $encoded;
				foreach ( $row as $v ) {
					$bytes += null === $v ? 0 : strlen( (string) $v );
				}
				if ( $bytes > self::BYTE_BATCH ) {
					fwrite(
						$out,
						wp_json_encode(
							array(
								'k'    => 'rows',
								'name' => $rel,
								'rows' => $batch,
							)
						) . "\n"
					);
					$batch = array();
					$bytes = 0;
				}
			}
			if ( ! empty( $batch ) ) {
				fwrite(
					$out,
					wp_json_encode(
						array(
							'k'    => 'rows',
							'name' => $rel,
							'rows' => $batch,
						)
					) . "\n"
				);
			}
			$job['row_offset'] += count( $rows );
		}
		fclose( $out );
		clearstatcache();
		$job['db_len'] = (int) filesize( RSMover_Storage::work_path( $job['db_file'] ) );

		if ( $job['table_idx'] >= count( $job['tables'] ) ) {
			$job['phase'] = 'scan';
		}
		return $job;
	}

	/**
	 * Writes the list of files to archive.
	 *
	 * @param array $job Job.
	 * @return array
	 * @throws RuntimeException On errors.
	 */
	private static function step_scan( array $job ) {
		$list = fopen( RSMover_Storage::work_path( $job['list_file'] ), 'wb' );
		if ( ! $list ) {
			throw new RuntimeException( esc_html__( 'Cannot write the file list.', 'resenje-site-mover' ) );
		}
		fwrite( $list, "\t" . self::DATABASE . "\n" );
		$count    = 1;
		$excluded = self::excluded_dirs();
		if ( $job['options']['exclude_media'] ) {
			$excluded[] = 'uploads';
		}
		$root = wp_normalize_path( WP_CONTENT_DIR );
		$len  = strlen( $root ) + 1;

		$filter = function ( $current ) use ( $excluded, $len ) {
			$rel = substr( wp_normalize_path( $current->getPathname() ), $len );
			if ( false === strpos( $rel, '/' ) && $current->isDir() ) {
				if ( in_array( $rel, $excluded, true ) || RSMover_Storage::is_backup_dir_name( $rel ) ) {
					return false;
				}
			}
			if ( $current->isLink() && $current->isDir() ) {
				return false;
			}
			return true;
		};

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ),
				$filter
			),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || ! $file->isReadable() ) {
				continue;
			}
			$rel = substr( wp_normalize_path( $file->getPathname() ), $len );
			if ( '' === $rel || preg_match( '/[\t\r\n]/', $rel ) || strlen( $rel ) > 3000 ) {
				continue;
			}
			fwrite( $list, $rel . "\n" );
			++$count;
		}
		fclose( $list );

		$job['total_files'] = $count;
		$job['phase']       = 'files';
		return $job;
	}

	/**
	 * Copies files into the archive.
	 *
	 * @param array $job      Job.
	 * @param float $deadline Deadline.
	 * @return array
	 * @throws RuntimeException On errors.
	 */
	private static function step_files( array $job, $deadline ) {
		$archive = RSMover_Storage::archive_path( $job['archive'] );
		if ( ! $archive ) {
			throw new RuntimeException( esc_html__( 'The backup file disappeared.', 'resenje-site-mover' ) );
		}
		RSMover_Archive::truncate( $archive, $job['archive_len'] );

		$list = fopen( RSMover_Storage::work_path( $job['list_file'] ), 'rb' );
		fseek( $list, $job['list_offset'] );

		while ( microtime( true ) < $deadline ) {
			if ( null === $job['cur'] ) {
				$line = fgets( $list );
				if ( false === $line ) {
					$job['phase'] = 'finalize';
					break;
				}
				$job['list_offset'] = ftell( $list );
				$line               = rtrim( $line, "\n" );
				if ( "\t" . self::DATABASE === $line ) {
					$src = RSMover_Storage::work_path( $job['db_file'] );
					$rel = self::DATABASE;
				} else {
					$src = WP_CONTENT_DIR . '/' . $line;
					$rel = self::CONTENT . $line;
				}
				clearstatcache( true, $src );
				if ( ! is_file( $src ) ) {
					++$job['files'];
					continue;
				}
				$job['cur'] = array(
					'src'    => $src,
					'rel'    => $rel,
					'size'   => (int) filesize( $src ),
					'offset' => 0,
				);
			}
			$cur    = $job['cur'];
			$result = RSMover_Archive::add_file( $archive, $cur['src'], $cur['rel'], $cur['size'], $cur['offset'], $deadline );
			if ( true === $result ) {
				$job['bytes'] += $cur['size'];
				++$job['files'];
				$job['cur'] = null;
			} else {
				$job['cur']['offset'] = $result;
			}
			clearstatcache( true, $archive );
			$job['archive_len'] = (int) filesize( $archive );
		}
		fclose( $list );
		return $job;
	}

	/**
	 * Writes the manifest and removes work files.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function step_finalize( array $job ) {
		global $wpdb;
		$archive = RSMover_Storage::archive_path( $job['archive'] );
		RSMover_Archive::truncate( $archive, $job['archive_len'] );

		$tables = array();
		foreach ( $job['tables'] as $table ) {
			$tables[] = substr( $table, strlen( $wpdb->prefix ) );
		}
		$manifest = array(
			'format'         => self::FORMAT,
			'plugin_version' => RSMOVER_VERSION,
			'created'        => gmdate( 'c' ),
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'abspath'        => wp_normalize_path( untrailingslashit( ABSPATH ) ),
			'content_dir'    => wp_normalize_path( WP_CONTENT_DIR ),
			'content_url'    => content_url(),
			'table_prefix'   => $wpdb->prefix,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'db_charset'     => $wpdb->charset,
			'tables'         => $tables,
			'files'          => $job['files'],
			'bytes'          => $job['bytes'],
			'exclude_media'  => $job['options']['exclude_media'],
		);
		RSMover_Archive::add_string( $archive, self::MANIFEST, wp_json_encode( $manifest ) );

		wp_delete_file( RSMover_Storage::work_path( $job['db_file'] ) );
		wp_delete_file( RSMover_Storage::work_path( $job['list_file'] ) );

		clearstatcache( true, $archive );
		$job['phase'] = 'done';
		$job['size']  = (int) filesize( $archive );
		return $job;
	}

	/**
	 * Removes the files of an unfinished job.
	 *
	 * @param array $job Job.
	 */
	public static function cleanup( array $job ) {
		foreach ( array( 'db_file', 'list_file' ) as $key ) {
			if ( ! empty( $job[ $key ] ) ) {
				wp_delete_file( RSMover_Storage::work_path( $job[ $key ] ) );
			}
		}
		if ( 'done' !== $job['phase'] && ! empty( $job['archive'] ) ) {
			RSMover_Storage::delete_archive( $job['archive'] );
		}
	}
}
