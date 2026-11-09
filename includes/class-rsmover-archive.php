<?php
/**
 * Simple appendable archive format (.rsm).
 *
 * Layout: "RSMOVER1" file magic, then entries. Each entry:
 * "RSME" | uint32 LE path length | path (UTF-8, "/" separated) | uint64 LE size | uint32 LE mtime | data.
 * Entries are appended with plain file appends, so large sites can be written over many short requests.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Large archives are streamed with native file functions; WP_Filesystem has no streaming API.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Archive reader/writer.
 */
class RSMover_Archive {

	const MAGIC       = 'RSMOVER1';
	const ENTRY_MAGIC = 'RSME';
	const CHUNK       = 1048576;
	const MAX_PATH    = 4096;

	/**
	 * Creates an empty archive.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function create( $path ) {
		return false !== file_put_contents( $path, self::MAGIC );
	}

	/**
	 * Checks the file magic.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function is_archive( $path ) {
		$h = fopen( $path, 'rb' );
		if ( ! $h ) {
			return false;
		}
		$magic = fread( $h, strlen( self::MAGIC ) );
		fclose( $h );
		return self::MAGIC === $magic;
	}

	/**
	 * Cuts the archive back to a known good length (undoes a partially written step).
	 *
	 * @param string $path   Archive path.
	 * @param int    $length Length in bytes.
	 */
	public static function truncate( $path, $length ) {
		clearstatcache( true, $path );
		if ( filesize( $path ) > $length ) {
			$h = fopen( $path, 'r+b' );
			if ( $h ) {
				ftruncate( $h, $length );
				fclose( $h );
			}
		}
	}

	/**
	 * Builds an entry header.
	 *
	 * @param string $rel   Path inside the archive.
	 * @param int    $size  Data size.
	 * @param int    $mtime Modification time.
	 * @return string
	 */
	private static function header( $rel, $size, $mtime ) {
		return self::ENTRY_MAGIC . pack( 'V', strlen( $rel ) ) . $rel . pack( 'P', $size ) . pack( 'V', $mtime );
	}

	/**
	 * Appends a small entry from memory.
	 *
	 * @param string $archive Archive path.
	 * @param string $rel     Path inside the archive.
	 * @param string $data    Data.
	 * @return bool
	 */
	public static function add_string( $archive, $rel, $data ) {
		$h = fopen( $archive, 'ab' );
		if ( ! $h ) {
			return false;
		}
		fwrite( $h, self::header( $rel, strlen( $data ), time() ) . $data );
		fclose( $h );
		return true;
	}

	/**
	 * Appends (part of) a file. Call again with the returned offset until it returns true.
	 *
	 * @param string $archive  Archive path.
	 * @param string $src      Source file.
	 * @param string $rel      Path inside the archive.
	 * @param int    $size     Size written in the header (fixed when the entry starts).
	 * @param int    $offset   Bytes of this file already written.
	 * @param float  $deadline microtime(true) value after which to stop.
	 * @return int|true New offset, or true when the file is complete.
	 * @throws RuntimeException On errors.
	 */
	public static function add_file( $archive, $src, $rel, $size, $offset, $deadline ) {
		$out = fopen( $archive, 'ab' );
		if ( ! $out ) {
			throw new RuntimeException( 'Cannot write archive.' );
		}
		if ( 0 === $offset ) {
			fwrite( $out, self::header( $rel, $size, (int) @filemtime( $src ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable mtime is not an error.
		}
		$in = fopen( $src, 'rb' );
		if ( $in ) {
			fseek( $in, $offset );
			while ( $offset < $size ) {
				$buf = fread( $in, (int) min( self::CHUNK, $size - $offset ) );
				if ( false === $buf || '' === $buf ) {
					break;
				}
				fwrite( $out, $buf );
				$offset += strlen( $buf );
				if ( $offset < $size && microtime( true ) > $deadline ) {
					fclose( $in );
					fclose( $out );
					return $offset;
				}
			}
			fclose( $in );
		}
		// The file shrank or became unreadable: pad so the archive stays consistent.
		while ( $offset < $size ) {
			$pad = (int) min( self::CHUNK, $size - $offset );
			fwrite( $out, str_repeat( "\0", $pad ) );
			$offset += $pad;
		}
		fclose( $out );
		return true;
	}

	/**
	 * Reads the entry header at the current position.
	 *
	 * @param resource $h Open archive handle.
	 * @return array|null Entry (path, size, mtime, data) or null at the end of the archive.
	 * @throws RuntimeException On a damaged archive.
	 */
	public static function read_header( $h ) {
		$magic = fread( $h, 4 );
		if ( false === $magic || '' === $magic ) {
			return null;
		}
		if ( self::ENTRY_MAGIC !== $magic ) {
			throw new RuntimeException( 'Damaged archive (bad entry header).' );
		}
		$len = unpack( 'V', (string) fread( $h, 4 ) );
		$len = is_array( $len ) ? (int) $len[1] : 0;
		if ( $len < 1 || $len > self::MAX_PATH ) {
			throw new RuntimeException( 'Damaged archive (bad path length).' );
		}
		$path  = (string) fread( $h, $len );
		$size  = unpack( 'P', (string) fread( $h, 8 ) );
		$mtime = unpack( 'V', (string) fread( $h, 4 ) );
		if ( strlen( $path ) !== $len || ! is_array( $size ) || ! is_array( $mtime ) ) {
			throw new RuntimeException( 'Damaged archive (truncated header).' );
		}
		return array(
			'path'  => $path,
			'size'  => (int) $size[1],
			'mtime' => (int) $mtime[1],
			'data'  => ftell( $h ),
		);
	}

	/**
	 * Opens an archive for reading, positioned after the file magic.
	 *
	 * @param string $path Archive path.
	 * @return resource
	 * @throws RuntimeException When the file is not an archive.
	 */
	public static function open( $path ) {
		$h = fopen( $path, 'rb' );
		if ( ! $h || self::MAGIC !== fread( $h, strlen( self::MAGIC ) ) ) {
			throw new RuntimeException( 'Not a Site Mover archive.' );
		}
		return $h;
	}

	/**
	 * Reads the manifest and checks that all entries are complete.
	 *
	 * @param string $path Archive path.
	 * @return array Manifest.
	 * @throws RuntimeException When the archive is damaged or has no manifest.
	 */
	public static function read_manifest( $path ) {
		$h        = self::open( $path );
		$total    = filesize( $path );
		$manifest = null;
		$entry    = self::read_header( $h );
		while ( null !== $entry ) {
			if ( $entry['data'] + $entry['size'] > $total ) {
				fclose( $h );
				throw new RuntimeException( 'Damaged archive (incomplete file data).' );
			}
			if ( RSMover_Export::MANIFEST === $entry['path'] && $entry['size'] < 1048576 ) {
				$manifest = json_decode( (string) fread( $h, $entry['size'] ), true );
			}
			fseek( $h, $entry['data'] + $entry['size'] );
			$entry = self::read_header( $h );
		}
		fclose( $h );
		if ( ! is_array( $manifest ) || empty( $manifest['format'] ) ) {
			throw new RuntimeException( 'Archive has no manifest (incomplete backup?).' );
		}
		return $manifest;
	}
}
