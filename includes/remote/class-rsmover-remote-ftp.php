<?php
/**
 * FTP / FTPS destination (PHP ftp extension).
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Parts are sent from a php://temp stream with ftp_fput(); WP_Filesystem has no resumable upload.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * FTP destination.
 */
class RSMover_Remote_FTP extends RSMover_Remote {

	const PART = 16777216;

	/**
	 * Open connection.
	 *
	 * @var resource|\FTP\Connection|null
	 */
	private $conn = null;

	/**
	 * Connects and logs in.
	 *
	 * @return resource|\FTP\Connection
	 * @throws RuntimeException On failure.
	 */
	private function connect() {
		if ( $this->conn ) {
			return $this->conn;
		}
		if ( ! function_exists( 'ftp_connect' ) ) {
			throw new RuntimeException( esc_html__( 'FTP: the PHP ftp extension is not installed on this server. Use SFTP or S3 instead.', 'resenje-site-mover' ) );
		}
		$host = isset( $this->cfg['host'] ) ? trim( (string) $this->cfg['host'] ) : '';
		$port = ! empty( $this->cfg['port'] ) ? (int) $this->cfg['port'] : 21;
		$tls  = ! empty( $this->cfg['tls'] );
		if ( '' === $host ) {
			throw new RuntimeException( esc_html__( 'FTP: server address is missing.', 'resenje-site-mover' ) );
		}
		if ( $tls && ! function_exists( 'ftp_ssl_connect' ) ) {
			throw new RuntimeException( esc_html__( 'FTP: this PHP build has no FTPS (TLS) support. Use SFTP or S3 instead.', 'resenje-site-mover' ) );
		}
		$conn = $tls ? @ftp_ssl_connect( $host, $port, 20 ) : @ftp_connect( $host, $port, 20 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is reported below.
		if ( ! $conn ) {
			throw new RuntimeException( esc_html__( 'FTP: cannot connect to the server.', 'resenje-site-mover' ) );
		}
		$user = isset( $this->cfg['user'] ) ? (string) $this->cfg['user'] : '';
		$pass = isset( $this->cfg['password'] ) ? (string) $this->cfg['password'] : '';
		if ( ! @ftp_login( $conn, $user, $pass ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is reported below.
			ftp_close( $conn );
			throw new RuntimeException( esc_html__( 'FTP: login failed. Check the user name and password.', 'resenje-site-mover' ) );
		}
		ftp_pasv( $conn, ! isset( $this->cfg['passive'] ) || ! empty( $this->cfg['passive'] ) );
		$this->conn = $conn;
		return $conn;
	}

	/**
	 * Closes the connection.
	 */
	public function __destruct() {
		if ( $this->conn ) {
			@ftp_close( $this->conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
		}
	}

	/**
	 * Path of a file in the backup folder.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	private function path( $file ) {
		$dir = $this->dir();
		return '.' === $dir ? $file : $dir . '/' . $file;
	}

	/**
	 * Creates the remote folder (and its parents) when needed.
	 *
	 * @param resource|\FTP\Connection $conn Connection.
	 */
	private function ensure_dir( $conn ) {
		$dir = $this->dir();
		if ( '.' === $dir ) {
			return;
		}
		$path = 0 === strpos( $dir, '/' ) ? '' : '.';
		foreach ( explode( '/', trim( $dir, '/' ) ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$path .= '/' . $segment;
			@ftp_mkdir( $conn, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the folder may exist already.
		}
	}

	/**
	 * Sends data from memory, starting at an offset of the remote file.
	 *
	 * @param resource|\FTP\Connection $conn   Connection.
	 * @param string                   $remote Remote path.
	 * @param string                   $data   Data.
	 * @param int                      $offset Start position.
	 * @throws RuntimeException On failure.
	 */
	private function put( $conn, $remote, $data, $offset ) {
		if ( 0 === $offset ) {
			$mem = fopen( 'php://temp', 'w+b' );
			fwrite( $mem, $data );
			rewind( $mem );
			$ok = @ftp_fput( $conn, $remote, $mem, FTP_BINARY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is reported below.
			fclose( $mem );
		} else {
			// Continue with APPE: many servers ignore REST before STOR. ftp_append() needs a local file.
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$tmp = wp_tempnam( 'rsmover-ftp' );
			file_put_contents( $tmp, $data );
			$ok = @ftp_append( $conn, $remote, $tmp, FTP_BINARY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is reported below.
			wp_delete_file( $tmp );
		}
		if ( ! $ok ) {
			$message = empty( $this->cfg['tls'] )
				? __( 'FTP: writing the file failed (no permission, disk full, or try switching passive mode).', 'resenje-site-mover' )
				: __( 'FTP: writing the file failed. Some FTPS servers refuse the PHP ftp extension (TLS session reuse); if the connection works but writing fails, use SFTP or S3 instead.', 'resenje-site-mover' );
			throw new RuntimeException( esc_html( $message ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException On failure.
	 */
	public function test() {
		$conn = $this->connect();
		$this->ensure_dir( $conn );
		$file = $this->path( '.rsmover-test-' . strtolower( wp_generate_password( 8, false ) ) );
		$this->put( $conn, $file, 'test', 0 );
		@ftp_delete( $conn, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
		return __( 'Connected and wrote a test file.', 'resenje-site-mover' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $local    Local file.
	 * @param string $name     Remote file name.
	 * @param int    $offset   Bytes already uploaded.
	 * @param array  $state    Upload state.
	 * @param float  $deadline Deadline.
	 * @return int
	 * @throws RuntimeException On failure.
	 */
	public function upload( $local, $name, $offset, array &$state, $deadline ) {
		$conn = $this->connect();
		$size = (int) filesize( $local );
		$part = $this->path( $name . '.part' );
		if ( 0 === $offset ) {
			$this->ensure_dir( $conn );
			@ftp_delete( $conn, $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may not exist.
		} else {
			// The local file never changes, so whatever already arrived is correct: continue after it.
			$remote = ftp_size( $conn, $part );
			$offset = ( $remote < 0 || $remote > $size ) ? 0 : (int) $remote;
		}
		while ( $offset < $size ) {
			$data = self::read_part( $local, $offset, self::PART );
			if ( '' === $data ) {
				throw new RuntimeException( esc_html__( 'Cannot read the backup file.', 'resenje-site-mover' ) );
			}
			$this->put( $conn, $part, $data, $offset );
			$offset += strlen( $data );
			if ( $offset < $size && microtime( true ) > $deadline ) {
				return $offset;
			}
		}
		if ( ftp_size( $conn, $part ) !== $size ) {
			throw new RuntimeException( esc_html__( 'FTP: the uploaded file has the wrong size.', 'resenje-site-mover' ) );
		}
		$final = $this->path( $name );
		@ftp_delete( $conn, $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may not exist.
		if ( ! @ftp_rename( $conn, $part, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is reported below.
			throw new RuntimeException( esc_html__( 'FTP: cannot rename the uploaded file.', 'resenje-site-mover' ) );
		}
		return $size;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $prefix Prefix.
	 * @param int    $keep   Files to keep.
	 * @return int
	 */
	public function prune( $prefix, $keep ) {
		$conn  = $this->connect();
		$names = @ftp_nlist( $conn, $this->dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an empty folder may return false.
		$count = 0;
		foreach ( self::names_to_delete( is_array( $names ) ? $names : array(), $prefix, $keep ) as $name ) {
			if ( @ftp_delete( $conn, $this->path( $name ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- counted below.
				++$count;
			}
		}
		return $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name  Remote file name.
	 * @param array  $state Upload state.
	 */
	public function abort( $name, array $state ) {
		if ( ! function_exists( 'ftp_delete' ) ) {
			return;
		}
		try {
			@ftp_delete( $this->connect(), $this->path( $name . '.part' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
