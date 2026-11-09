<?php
/**
 * Backup storage: protected directory, archive names, job state.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Large archives are streamed with native file functions; WP_Filesystem has no streaming API.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Storage helper.
 */
class RSMover_Storage {

	const DIR_PREFIX = 'rsmover-backups-';

	/**
	 * Cached directory path.
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * Returns (and creates) the protected backup directory inside wp-content.
	 *
	 * The name has a random suffix so the files cannot be guessed on servers that ignore .htaccess.
	 * It is found again by pattern, so it survives a database import.
	 *
	 * @return string
	 */
	public static function dir() {
		if ( null !== self::$dir ) {
			return self::$dir;
		}
		$found = glob( WP_CONTENT_DIR . '/' . self::DIR_PREFIX . '*', GLOB_ONLYDIR );
		if ( ! empty( $found ) ) {
			self::$dir = $found[0];
		} else {
			self::$dir = WP_CONTENT_DIR . '/' . self::DIR_PREFIX . strtolower( wp_generate_password( 20, false ) );
			wp_mkdir_p( self::$dir );
		}
		self::protect( self::$dir );
		return self::$dir;
	}

	/**
	 * Writes files that block web access to the directory.
	 *
	 * @param string $dir Directory.
	 */
	private static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content );
			}
		}
	}

	/**
	 * Whether a wp-content relative top-level name is a backup directory of this plugin.
	 *
	 * @param string $name Top-level directory name.
	 * @return bool
	 */
	public static function is_backup_dir_name( $name ) {
		return 0 === strpos( $name, self::DIR_PREFIX );
	}

	/**
	 * Validates an archive file name and returns its full path, or false.
	 *
	 * @param string $name        File name (no directories).
	 * @param bool   $must_exist  Whether the file must exist.
	 * @return string|false
	 */
	public static function archive_path( $name, $must_exist = true ) {
		$name = (string) $name;
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.rsm$/', $name ) || false !== strpos( $name, '..' ) ) {
			return false;
		}
		$path = self::dir() . '/' . $name;
		if ( $must_exist && ! is_file( $path ) ) {
			return false;
		}
		return $path;
	}

	/**
	 * Generates a new, unique archive file name.
	 *
	 * @param string $kind Prefix (backup or upload).
	 * @return string
	 */
	public static function new_archive_name( $kind = 'backup' ) {
		$host = sanitize_key( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = '' === $host ? 'site' : substr( $host, 0, 40 );
		return sanitize_key( $kind ) . '-' . $host . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false ) ) . '.rsm';
	}

	/**
	 * Lists archives, newest first.
	 *
	 * @return array[] Each item: name, size, time.
	 */
	public static function list_archives() {
		$items = array();
		foreach ( (array) glob( self::dir() . '/*.rsm' ) as $path ) {
			$name = basename( $path );
			if ( ! self::archive_path( $name ) ) {
				continue;
			}
			$items[] = array(
				'name' => $name,
				'size' => (int) filesize( $path ),
				'time' => (int) filemtime( $path ),
			);
		}
		usort(
			$items,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $items;
	}

	/**
	 * Path of a temporary work file.
	 *
	 * @param string $name Work file name.
	 * @return string
	 */
	public static function work_path( $name ) {
		return self::dir() . '/' . sanitize_file_name( $name );
	}

	/**
	 * Reads the current job state (stored in a file so it survives the database swap of an import).
	 *
	 * @return array|null
	 */
	public static function get_job() {
		$path = self::dir() . '/job-state.php';
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw = (string) file_get_contents( $path );
		// The first line is a PHP exit guard, the second line holds the JSON state.
		$parts = explode( "\n", $raw, 2 );
		$job   = isset( $parts[1] ) ? json_decode( $parts[1], true ) : null;
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Saves the job state.
	 *
	 * @param array|null $job State, or null to clear it.
	 */
	public static function set_job( $job ) {
		$path = self::dir() . '/job-state.php';
		if ( null === $job ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			return;
		}
		file_put_contents( $path, "<?php exit; ?>\n" . wp_json_encode( $job ), LOCK_EX );
	}

	/**
	 * Deletes an archive.
	 *
	 * @param string $name Archive name.
	 * @return bool
	 */
	public static function delete_archive( $name ) {
		$path = self::archive_path( $name );
		if ( ! $path ) {
			return false;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Removes the whole backup directory (used on uninstall).
	 */
	public static function remove_all() {
		$dir = self::dir();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_file( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
		rmdir( $dir );
	}
}
