<?php
/**
 * Import (restore / migration) engine.
 *
 * Files are extracted into wp-content, the database is loaded into temporary tables (with URL and
 * path replacement), and only at the very end the temporary tables replace the live ones.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Large archives are streamed with native file functions; WP_Filesystem has no streaming API.
// The database is restored with direct queries into temporary tables; identifiers are passed with %i.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuerySchemaChange

/**
 * Import engine.
 */
class RSMover_Import {

	const TMP_PREFIX   = 'rsmt_';
	const OLD_PREFIX   = 'rsmo_';
	const INSERT_BYTES = 1048576;

	/**
	 * Validates an archive and starts an import job.
	 *
	 * @param string $name Archive file name in the backup directory.
	 * @return array Job state.
	 * @throws RuntimeException When the archive cannot be imported here.
	 */
	public static function start( $name ) {
		global $wpdb;

		if ( is_multisite() ) {
			throw new RuntimeException( esc_html__( 'Multisite networks are not supported.', 'resenje-site-mover' ) );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new RuntimeException( esc_html__( 'File changes are disabled on this site (DISALLOW_FILE_MODS), so an import is not possible.', 'resenje-site-mover' ) );
		}
		$archive = RSMover_Storage::archive_path( $name );
		if ( ! $archive ) {
			throw new RuntimeException( esc_html__( 'Backup file not found.', 'resenje-site-mover' ) );
		}
		$manifest = RSMover_Archive::read_manifest( $archive );
		if ( (int) $manifest['format'] > RSMover_Export::FORMAT ) {
			throw new RuntimeException( esc_html__( 'This backup was made with a newer version of Resenje Site Mover. Update the plugin first.', 'resenje-site-mover' ) );
		}
		if ( ! empty( $manifest['wp_version'] ) && version_compare( $manifest['wp_version'], get_bloginfo( 'version' ), '>' ) ) {
			/* translators: %s: WordPress version of the backup */
			throw new RuntimeException( esc_html( sprintf( __( 'This backup comes from WordPress %s. Update WordPress on this site to at least that version first.', 'resenje-site-mover' ), $manifest['wp_version'] ) ) );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		if ( false !== $free && $free < filesize( $archive ) * 1.2 ) {
			throw new RuntimeException( esc_html__( 'Not enough free disk space for this import.', 'resenje-site-mover' ) );
		}

		return array(
			'type'       => 'import',
			'archive'    => $name,
			'phase'      => 'files',
			'pos'        => strlen( RSMover_Archive::MAGIC ),
			'cur'        => null,
			'db_file'    => 'import-' . substr( $name, 0, -4 ) . '.db.tmp',
			'db_offset'  => 0,
			'tables'     => array(),
			'files'      => 0,
			'total'      => max( 1, (int) $manifest['files'] ),
			'old_prefix' => (string) $manifest['table_prefix'],
			'new_prefix' => $wpdb->prefix,
			'pairs'      => self::replacement_pairs( $manifest ),
			'source'     => (string) $manifest['home_url'],
			'started'    => time(),
		);
	}

	/**
	 * Builds the search => replace map from the source site to this site.
	 *
	 * @param array $manifest Archive manifest.
	 * @return array
	 */
	public static function replacement_pairs( array $manifest ) {
		$map   = array(
			untrailingslashit( (string) $manifest['home_url'] ) => untrailingslashit( home_url() ),
			untrailingslashit( (string) $manifest['site_url'] ) => untrailingslashit( site_url() ),
			untrailingslashit( (string) $manifest['content_url'] ) => untrailingslashit( content_url() ),
			(string) $manifest['content_dir'] => wp_normalize_path( WP_CONTENT_DIR ),
			(string) $manifest['abspath']     => wp_normalize_path( untrailingslashit( ABSPATH ) ),
		);
		$pairs = array();
		foreach ( $map as $from => $to ) {
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$pairs[ $from ] = $to;
			// JSON-escaped form (block editor, page builders).
			$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
			// URL-encoded form.
			$pairs[ rawurlencode( $from ) ] = rawurlencode( $to );
			// Scheme-less form (//example.com/...), so http/https variants are covered too.
			if ( preg_match( '#^https?:(//.+)$#', $from, $m ) && preg_match( '#^https?:(//.+)$#', $to, $n ) ) {
				$pairs[ $m[1] ] = $n[1];
			}
		}
		return $pairs;
	}

	/**
	 * Runs one step.
	 *
	 * @param array $job      Job.
	 * @param float $deadline Deadline.
	 * @return array
	 */
	public static function step( array $job, $deadline ) {
		switch ( $job['phase'] ) {
			case 'files':
				return self::step_files( $job, $deadline );
			case 'db':
				return self::step_db( $job, $deadline );
			case 'swap':
				return self::step_swap( $job );
		}
		return $job;
	}

	/**
	 * Progress for the UI.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( array $job ) {
		switch ( $job['phase'] ) {
			case 'files':
				return array(
					'percent' => (int) floor( 60 * min( 1, $job['files'] / $job['total'] ) ),
					/* translators: 1: files done, 2: total files */
					'message' => sprintf( __( 'Extracting files: %1$d of %2$d', 'resenje-site-mover' ), $job['files'], $job['total'] ),
				);
			case 'db':
				return array(
					'percent' => 60 + min( 35, count( $job['tables'] ) ),
					/* translators: %d: number of tables imported so far */
					'message' => sprintf( __( 'Importing database: %d tables', 'resenje-site-mover' ), count( $job['tables'] ) ),
				);
			case 'swap':
				return array(
					'percent' => 98,
					'message' => __( 'Activating the imported database…', 'resenje-site-mover' ),
				);
		}
		return array(
			'percent' => 100,
			'message' => __( 'Import complete.', 'resenje-site-mover' ),
		);
	}

	/**
	 * Validates a path from the archive and returns the wp-content relative path, or false.
	 *
	 * @param string $rel Relative path.
	 * @return string|false
	 */
	public static function safe_content_path( $rel ) {
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) || false !== strpos( $rel, ':' ) || '/' === $rel[0] ) {
			return false;
		}
		$parts = explode( '/', $rel );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		if ( RSMover_Storage::is_backup_dir_name( $parts[0] ) ) {
			return false;
		}
		// Keep the running copy of this plugin; it is not replaced from the archive.
		$own = 'plugins/' . dirname( RSMOVER_BASENAME ) . '/';
		if ( 0 === strpos( $rel . '/', $own ) ) {
			return false;
		}
		return $rel;
	}

	/**
	 * Extracts files.
	 *
	 * @param array $job      Job.
	 * @param float $deadline Deadline.
	 * @return array
	 * @throws RuntimeException On errors.
	 */
	private static function step_files( array $job, $deadline ) {
		$path = RSMover_Storage::archive_path( $job['archive'] );
		if ( ! $path ) {
			throw new RuntimeException( esc_html__( 'Backup file not found.', 'resenje-site-mover' ) );
		}
		$h = RSMover_Archive::open( $path );

		while ( microtime( true ) < $deadline ) {
			if ( null === $job['cur'] ) {
				fseek( $h, $job['pos'] );
				$entry = RSMover_Archive::read_header( $h );
				if ( null === $entry ) {
					$job['phase'] = 'db';
					break;
				}
				$target = null;
				if ( RSMover_Export::DATABASE === $entry['path'] ) {
					$target = RSMover_Storage::work_path( $job['db_file'] );
				} elseif ( 0 === strpos( $entry['path'], RSMover_Export::CONTENT ) ) {
					$rel = self::safe_content_path( substr( $entry['path'], strlen( RSMover_Export::CONTENT ) ) );
					if ( false !== $rel ) {
						$target = WP_CONTENT_DIR . '/' . $rel;
					}
					++$job['files'];
				}
				$job['cur'] = array(
					'target'  => $target,
					'size'    => $entry['size'],
					'mtime'   => $entry['mtime'],
					'data'    => $entry['data'],
					'written' => 0,
				);
			}

			$cur = $job['cur'];
			if ( null !== $cur['target'] ) {
				$cur['written'] = self::copy_out( $h, $cur, $deadline );
			} else {
				$cur['written'] = $cur['size'];
			}

			if ( $cur['written'] >= $cur['size'] ) {
				if ( null !== $cur['target'] && $cur['mtime'] > 0 ) {
					touch( $cur['target'], $cur['mtime'] );
				}
				$job['pos'] = $cur['data'] + $cur['size'];
				$job['cur'] = null;
			} else {
				$job['cur'] = $cur;
			}
		}
		fclose( $h );
		return $job;
	}

	/**
	 * Copies entry data from the archive to its target file.
	 *
	 * @param resource $h        Archive handle.
	 * @param array    $cur      Current entry state.
	 * @param float    $deadline Deadline.
	 * @return int Bytes written so far.
	 * @throws RuntimeException When the target cannot be written.
	 */
	private static function copy_out( $h, array $cur, $deadline ) {
		$written = $cur['written'];
		if ( 0 === $written ) {
			wp_mkdir_p( dirname( $cur['target'] ) );
			$out = fopen( $cur['target'], 'wb' );
		} else {
			$out = fopen( $cur['target'], 'cb' );
			if ( $out ) {
				ftruncate( $out, $written );
				fseek( $out, $written );
			}
		}
		if ( ! $out ) {
			/* translators: %s: file path */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot write %s. Check file permissions.', 'resenje-site-mover' ), $cur['target'] ) ) );
		}
		fseek( $h, $cur['data'] + $written );
		while ( $written < $cur['size'] ) {
			$buf = fread( $h, (int) min( RSMover_Archive::CHUNK, $cur['size'] - $written ) );
			if ( false === $buf || '' === $buf ) {
				fclose( $out );
				throw new RuntimeException( esc_html__( 'Damaged archive (unexpected end of file).', 'resenje-site-mover' ) );
			}
			fwrite( $out, $buf );
			$written += strlen( $buf );
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fclose( $out );
		return $written;
	}

	/**
	 * Temporary table name for a table.
	 *
	 * @param string $rel Table name without prefix.
	 * @return string
	 */
	private static function tmp_name( $rel ) {
		$name = self::TMP_PREFIX . $rel;
		return strlen( $name ) <= 64 ? $name : self::TMP_PREFIX . substr( md5( $rel ), 0, 20 );
	}

	/**
	 * Session settings for loading data.
	 */
	private static function prepare_session() {
		global $wpdb;
		$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
	}

	/**
	 * Makes a CREATE TABLE statement from the archive usable here.
	 *
	 * @param string $sql CREATE TABLE statement with the `{{TABLE}}` placeholder.
	 * @param string $tmp Temporary table name.
	 * @return string
	 * @throws RuntimeException On an unexpected statement.
	 */
	private static function create_sql( $sql, $tmp ) {
		global $wpdb;
		if ( 0 !== strpos( $sql, 'CREATE TABLE `{{TABLE}}`' ) ) {
			throw new RuntimeException( esc_html__( 'Damaged archive (unexpected SQL).', 'resenje-site-mover' ) );
		}
		$sql = 'CREATE TABLE `' . $tmp . '`' . substr( $sql, strlen( 'CREATE TABLE `{{TABLE}}`' ) );
		// Foreign keys reference the source table names and need unique names; they are dropped.
		$sql = preg_replace( '/,\s*CONSTRAINT `[^`]*` FOREIGN KEY [^\n]*/', '', $sql );
		// MySQL 8 collations are unknown to MariaDB and older MySQL.
		if ( false !== strpos( $sql, 'utf8mb4_0900_' ) && null === $wpdb->get_var( "SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'" ) ) {
			$sql = preg_replace( '/utf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_520_ci', $sql );
		}
		return $sql;
	}

	/**
	 * Loads the database into temporary tables.
	 *
	 * @param array $job      Job.
	 * @param float $deadline Deadline.
	 * @return array
	 * @throws RuntimeException On errors.
	 */
	private static function step_db( array $job, $deadline ) {
		global $wpdb;
		$file = RSMover_Storage::work_path( $job['db_file'] );
		if ( ! is_file( $file ) ) {
			throw new RuntimeException( esc_html__( 'The archive contains no database.', 'resenje-site-mover' ) );
		}
		self::prepare_session();
		$replacer = new RSMover_Replacer( $job['pairs'] );

		$h = fopen( $file, 'rb' );
		fseek( $h, $job['db_offset'] );
		while ( microtime( true ) < $deadline ) {
			$line = fgets( $h );
			if ( false === $line ) {
				$job['phase'] = 'swap';
				break;
			}
			$rec = json_decode( $line, true );
			if ( ! is_array( $rec ) || empty( $rec['name'] ) || ! is_string( $rec['name'] ) ) {
				fclose( $h );
				throw new RuntimeException( esc_html__( 'Damaged archive (database record).', 'resenje-site-mover' ) );
			}
			$rel = $rec['name'];
			$tmp = self::tmp_name( $rel );

			if ( 'table' === $rec['k'] ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tmp ) );
				if ( false === $wpdb->query( self::create_sql( (string) $rec['sql'], $tmp ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE from the archive, validated in create_sql().
					fclose( $h );
					/* translators: 1: table, 2: database error */
					throw new RuntimeException( esc_html( sprintf( __( 'Cannot create table %1$s: %2$s', 'resenje-site-mover' ), $rel, $wpdb->last_error ) ) );
				}
				$job['tables'][ $rel ] = array_values( array_map( 'strval', (array) $rec['columns'] ) );
			} elseif ( 'rows' === $rec['k'] && isset( $job['tables'][ $rel ] ) ) {
				self::insert_rows( $tmp, $rel, $job['tables'][ $rel ], (array) $rec['rows'], $replacer, $job );
			}
			$job['db_offset'] = ftell( $h );
		}
		fclose( $h );
		return $job;
	}

	/**
	 * Inserts a batch of rows with replacement applied.
	 *
	 * @param string           $tmp      Temporary table.
	 * @param string           $rel      Table name without prefix.
	 * @param string[]         $columns  Column names.
	 * @param array            $rows     Rows.
	 * @param RSMover_Replacer $replacer Replacer.
	 * @param array            $job      Job.
	 * @throws RuntimeException On a database error.
	 */
	private static function insert_rows( $tmp, $rel, array $columns, array $rows, RSMover_Replacer $replacer, array $job ) {
		global $wpdb;
		$count = count( $columns );
		if ( 0 === $count ) {
			return;
		}
		$rename_col = null;
		if ( $job['old_prefix'] !== $job['new_prefix'] ) {
			if ( 'options' === $rel ) {
				$rename_col = array_search( 'option_name', $columns, true );
			} elseif ( 'usermeta' === $rel ) {
				$rename_col = array_search( 'meta_key', $columns, true );
			}
		}

		$head   = 'INSERT IGNORE INTO %i (' . implode( ',', array_fill( 0, $count, '%i' ) ) . ') VALUES ';
		$groups = array();
		$args   = array_merge( array( $tmp ), $columns );
		$bytes  = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $count ) {
				continue;
			}
			$marks = array();
			foreach ( array_values( $row ) as $i => $value ) {
				if ( null === $value ) {
					$marks[] = 'NULL';
					continue;
				}
				if ( is_array( $value ) && isset( $value['b64'] ) ) {
					$value = (string) base64_decode( (string) $value['b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary column data from the archive.
				} else {
					$value = $replacer->replace( (string) $value );
					if ( false !== $rename_col && null !== $rename_col && $i === $rename_col && 0 === strpos( $value, $job['old_prefix'] ) ) {
						$value = $job['new_prefix'] . substr( $value, strlen( $job['old_prefix'] ) );
					}
				}
				$marks[] = '%s';
				$args[]  = $value;
				$bytes  += strlen( $value );
			}
			$groups[] = '(' . implode( ',', $marks ) . ')';
			if ( $bytes > self::INSERT_BYTES ) {
				self::run_insert( $head, $groups, $args, $rel );
				$groups = array();
				$args   = array_merge( array( $tmp ), $columns );
				$bytes  = 0;
			}
		}
		if ( ! empty( $groups ) ) {
			self::run_insert( $head, $groups, $args, $rel );
		}
	}

	/**
	 * Executes one multi-row insert.
	 *
	 * @param string $head   Statement head.
	 * @param array  $groups Value groups (placeholders).
	 * @param array  $args   Arguments.
	 * @param string $rel    Table (for the error message).
	 * @throws RuntimeException On a database error.
	 */
	private static function run_insert( $head, array $groups, array $args, $rel ) {
		global $wpdb;
		// The statement only contains %i / %s placeholders and NULL literals built in insert_rows().
		$sql = $wpdb->prepare( $head . implode( ',', $groups ), $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built in insert_rows().
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above.
			/* translators: 1: table, 2: database error */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot import rows into %1$s: %2$s', 'resenje-site-mover' ), $rel, $wpdb->last_error ) ) );
		}
	}

	/**
	 * Replaces the live tables with the imported ones.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function step_swap( array $job ) {
		global $wpdb;
		self::prepare_session();
		foreach ( array_keys( $job['tables'] ) as $rel ) {
			$tmp   = self::tmp_name( $rel );
			$final = $job['new_prefix'] . $rel;
			$old   = self::OLD_PREFIX . substr( md5( $rel ), 0, 20 );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $final ) ) ) ) {
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $final, $old, $tmp, $final ) );
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
			} else {
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $tmp, $final ) );
			}
		}

		wp_cache_flush();
		// Keep this plugin active and let WordPress rebuild the rewrite rules for the new address.
		$active = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( RSMOVER_BASENAME, $active, true ) ) {
			$active[] = RSMOVER_BASENAME;
			update_option( 'active_plugins', array_values( $active ) );
		}
		delete_option( 'rewrite_rules' );
		update_option( 'rsmover_last_success', time(), false );

		wp_delete_file( RSMover_Storage::work_path( $job['db_file'] ) );
		$job['phase'] = 'done';
		return $job;
	}

	/**
	 * Removes temporary data of an unfinished import.
	 *
	 * @param array $job Job.
	 */
	public static function cleanup( array $job ) {
		global $wpdb;
		if ( 'done' !== $job['phase'] ) {
			foreach ( array_keys( (array) $job['tables'] ) as $rel ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::tmp_name( $rel ) ) );
			}
		}
		if ( ! empty( $job['db_file'] ) ) {
			wp_delete_file( RSMover_Storage::work_path( $job['db_file'] ) );
		}
	}
}
