<?php
/**
 * Remote storage destinations for scheduled backups.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class and factory of remote destinations.
 */
abstract class RSMover_Remote {

	/**
	 * Destination settings (secrets already decrypted).
	 *
	 * @var array
	 */
	protected $cfg;

	/**
	 * Constructor.
	 *
	 * @param array $cfg Destination settings.
	 */
	public function __construct( array $cfg ) {
		$this->cfg = $cfg;
	}

	/**
	 * Labels of the supported destinations.
	 *
	 * @return array key => label.
	 */
	public static function types() {
		return array(
			'none' => __( 'Only on this server', 'resenje-site-mover' ),
			'sftp' => __( 'SFTP / SSH server', 'resenje-site-mover' ),
			's3'   => __( 'Amazon S3 or S3-compatible storage (Backblaze B2, Wasabi, Cloudflare R2, MinIO, DigitalOcean Spaces…)', 'resenje-site-mover' ),
			'ftp'  => __( 'FTP / FTPS server', 'resenje-site-mover' ),
		);
	}

	/**
	 * Short name of a destination type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function short_label( $type ) {
		$labels = array(
			'sftp' => 'SFTP',
			's3'   => 'S3',
			'ftp'  => 'FTP',
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Creates the destination configured in the schedule settings.
	 *
	 * @param array $settings Schedule settings (as stored).
	 * @return RSMover_Remote|null Null when backups stay on this server.
	 * @throws RuntimeException When the destination is unknown.
	 */
	public static function from_settings( array $settings ) {
		$type = isset( $settings['destination'] ) ? $settings['destination'] : 'none';
		if ( 'none' === $type ) {
			return null;
		}
		$cfg = isset( $settings[ $type ] ) && is_array( $settings[ $type ] ) ? $settings[ $type ] : array();
		foreach ( RSMover_Schedule::secret_fields() as $field ) {
			if ( isset( $cfg[ $field ] ) ) {
				$cfg[ $field ] = RSMover_Crypto::decrypt( $cfg[ $field ] );
			}
		}
		switch ( $type ) {
			case 'sftp':
				require_once __DIR__ . '/remote/class-rsmover-remote-sftp.php';
				return new RSMover_Remote_SFTP( $cfg );
			case 's3':
				require_once __DIR__ . '/remote/class-rsmover-remote-s3.php';
				return new RSMover_Remote_S3( $cfg );
			case 'ftp':
				require_once __DIR__ . '/remote/class-rsmover-remote-ftp.php';
				return new RSMover_Remote_FTP( $cfg );
		}
		throw new RuntimeException( 'Unknown remote storage type.' );
	}

	/**
	 * Checks the connection and write access.
	 *
	 * @return string Human readable result.
	 * @throws RuntimeException On failure.
	 */
	abstract public function test();

	/**
	 * Uploads (part of) a file. Called repeatedly until the returned offset equals the file size.
	 *
	 * @param string $local    Local file.
	 * @param string $name     Remote file name (no directories).
	 * @param int    $offset   Bytes already uploaded.
	 * @param array  $state    Destination specific state, kept between calls.
	 * @param float  $deadline microtime(true) value to stop at.
	 * @return int New offset; equal to the file size once the remote file is complete.
	 * @throws RuntimeException On failure.
	 */
	abstract public function upload( $local, $name, $offset, array &$state, $deadline );

	/**
	 * Removes old backups so only the newest $keep files with the prefix remain.
	 *
	 * @param string $prefix File name prefix of this site's scheduled backups.
	 * @param int    $keep   Number of files to keep.
	 * @return int Deleted files.
	 * @throws RuntimeException On failure.
	 */
	abstract public function prune( $prefix, $keep );

	/**
	 * Cleans up an unfinished upload.
	 *
	 * @param string $name  Remote file name.
	 * @param array  $state Upload state.
	 */
	abstract public function abort( $name, array $state );

	/**
	 * Chooses the names that prune() removes.
	 *
	 * @param string[] $names  File names found on the destination.
	 * @param string   $prefix Prefix of this site's scheduled backups.
	 * @param int      $keep   Number of files to keep.
	 * @return string[]
	 */
	protected static function names_to_delete( array $names, $prefix, $keep ) {
		$ours = array();
		foreach ( $names as $name ) {
			$name = basename( (string) $name );
			if ( RSMover_Schedule::is_own( $name, $prefix ) ) {
				$ours[] = $name;
			}
		}
		// Names contain the date (YYYYMMDD-HHMMSS), so sorting by name sorts by age.
		rsort( $ours, SORT_STRING );
		return array_slice( $ours, max( 1, (int) $keep ) );
	}

	/**
	 * Remote directory from the settings, without a trailing slash.
	 *
	 * @return string
	 */
	protected function dir() {
		$path = isset( $this->cfg['path'] ) ? trim( str_replace( '\\', '/', (string) $this->cfg['path'] ) ) : '';
		$path = rtrim( $path, '/' );
		return '' === $path ? '.' : $path;
	}

	/**
	 * Reads part of a local file.
	 *
	 * @param string $local  Local file.
	 * @param int    $offset Start.
	 * @param int    $length Length.
	 * @return string
	 * @throws RuntimeException When the file cannot be read.
	 */
	protected static function read_part( $local, $offset, $length ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- partial reads of a large local file.
		$h = fopen( $local, 'rb' );
		if ( ! $h ) {
			throw new RuntimeException( 'Cannot read the backup file.' );
		}
		fseek( $h, $offset );
		$data = '';
		$got  = 0;
		while ( $got < $length && ! feof( $h ) ) {
			$buf = fread( $h, $length - $got );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$data .= $buf;
			$got  += strlen( $buf );
		}
		fclose( $h );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return $data;
	}
}
