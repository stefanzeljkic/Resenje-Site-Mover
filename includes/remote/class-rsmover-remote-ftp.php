<?php
/**
 * FTP / FTPS destination.
 *
 * Uses the PHP cURL extension: the WordPress HTTP API has no FTP support, and the PHP ftp extension
 * fails on many FTPS servers (TLS 1.3 session reuse) and is missing on many hosts.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- FTP transfers need cURL directly (no FTP in the WordPress HTTP API).

/**
 * FTP destination.
 */
class RSMover_Remote_FTP extends RSMover_Remote {

	const PART = 16777216;

	/**
	 * The cURL handle, reused so the control connection (and TLS session) stays open between operations.
	 *
	 * @var resource|CurlHandle|null
	 */
	private $ch = null;

	/**
	 * URL of a file in the backup folder, or of the folder itself (ends with a slash).
	 *
	 * @param string $file File name ('' for the folder).
	 * @return string
	 * @throws RuntimeException When the settings are incomplete.
	 */
	private function url( $file = '' ) {
		$host = isset( $this->cfg['host'] ) ? trim( (string) $this->cfg['host'] ) : '';
		if ( '' === $host ) {
			throw new RuntimeException( esc_html__( 'FTP: server address is missing.', 'resenje-site-mover' ) );
		}
		$port     = ! empty( $this->cfg['port'] ) ? (int) $this->cfg['port'] : 21;
		$dir      = $this->dir();
		$segments = array();
		if ( '.' !== $dir ) {
			foreach ( explode( '/', trim( $dir, '/' ) ) as $segment ) {
				if ( '' !== $segment ) {
					$segments[] = rawurlencode( $segment );
				}
			}
		}
		// A leading %2F makes the path absolute instead of relative to the login folder.
		$path = 0 === strpos( $dir, '/' ) ? '/%2F' : '';
		foreach ( $segments as $segment ) {
			$path .= '/' . $segment;
		}
		return 'ftp://' . $host . ':' . $port . $path . '/' . rawurlencode( $file );
	}

	/**
	 * Runs one cURL FTP operation.
	 *
	 * @param string $url  URL.
	 * @param array  $opts Extra cURL options.
	 * @return array body, info.
	 * @throws RuntimeException On failure.
	 */
	private function curl( $url, array $opts ) {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new RuntimeException( esc_html__( 'FTP: the PHP cURL extension is not installed on this server. Use SFTP or S3 instead.', 'resenje-site-mover' ) );
		}
		if ( ! $this->ch ) {
			$this->ch = curl_init();
		} else {
			curl_reset( $this->ch );
		}
		$ch   = $this->ch;
		$base = array(
			CURLOPT_URL                     => $url,
			CURLOPT_USERPWD                 => ( isset( $this->cfg['user'] ) ? (string) $this->cfg['user'] : '' ) . ':' . ( isset( $this->cfg['password'] ) ? (string) $this->cfg['password'] : '' ),
			CURLOPT_RETURNTRANSFER          => true,
			CURLOPT_CONNECTTIMEOUT          => 20,
			CURLOPT_TIMEOUT                 => 300,
			CURLOPT_FTP_CREATE_MISSING_DIRS => 1,
		);
		if ( ! empty( $this->cfg['tls'] ) ) {
			$base[ CURLOPT_USE_SSL ]        = CURLUSESSL_ALL;
			$base[ CURLOPT_SSL_VERIFYPEER ] = ! empty( $this->cfg['verify'] );
			$base[ CURLOPT_SSL_VERIFYHOST ] = empty( $this->cfg['verify'] ) ? 0 : 2;
		}
		if ( isset( $this->cfg['passive'] ) && empty( $this->cfg['passive'] ) ) {
			$base[ CURLOPT_FTPPORT ] = '-';
		}
		curl_setopt_array( $ch, $opts + $base );
		$body  = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		$info  = curl_getinfo( $ch );
		if ( $errno ) {
			if ( 67 === $errno ) {
				throw new RuntimeException( esc_html__( 'FTP: login failed. Check the user name and password.', 'resenje-site-mover' ) );
			}
			/* translators: %s: error message */
			throw new RuntimeException( esc_html( sprintf( __( 'FTP: %s', 'resenje-site-mover' ), $error ) ) );
		}
		return array(
			'body' => (string) $body,
			'info' => $info,
		);
	}

	/**
	 * Uploads data from memory.
	 *
	 * @param string $file   File name.
	 * @param string $data   Data.
	 * @param bool   $append Append instead of overwrite.
	 */
	private function put( $file, $data, $append ) {
		$mem = fopen( 'php://temp', 'w+b' );
		fwrite( $mem, $data );
		rewind( $mem );
		try {
			$this->curl(
				$this->url( $file ),
				array(
					CURLOPT_UPLOAD     => true,
					CURLOPT_INFILE     => $mem,
					CURLOPT_INFILESIZE => strlen( $data ),
					CURLOPT_APPEND     => (bool) $append,
				)
			);
		} finally {
			fclose( $mem );
		}
	}

	/**
	 * Sends FTP commands in the backup folder.
	 *
	 * @param string[] $commands Commands (prefix "*" to ignore a failure).
	 */
	private function commands( array $commands ) {
		$this->curl(
			$this->url(),
			array(
				CURLOPT_NOBODY    => true,
				CURLOPT_POSTQUOTE => array_values( $commands ),
			)
		);
	}

	/**
	 * Size of a remote file.
	 *
	 * @param string $file File name.
	 * @return int -1 when it does not exist.
	 */
	private function size( $file ) {
		try {
			$answer = $this->curl( $this->url( $file ), array( CURLOPT_NOBODY => true ) );
		} catch ( Throwable $e ) {
			return -1;
		}
		return isset( $answer['info']['download_content_length'] ) ? (int) $answer['info']['download_content_length'] : -1;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException On failure.
	 */
	public function test() {
		$file = '.rsmover-test-' . strtolower( wp_generate_password( 8, false ) );
		$this->put( $file, 'test', false );
		$this->commands( array( '*DELE ' . $file ) );
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
		$size = (int) filesize( $local );
		$part = $name . '.part';
		if ( $offset > 0 ) {
			// The local file never changes, so whatever already arrived is correct: continue after it.
			$remote = $this->size( $part );
			$offset = ( $remote < 0 || $remote > $size ) ? 0 : $remote;
		}
		while ( $offset < $size ) {
			$data = self::read_part( $local, $offset, self::PART );
			if ( '' === $data ) {
				throw new RuntimeException( 'Cannot read the backup file.' );
			}
			$this->put( $part, $data, $offset > 0 );
			$offset += strlen( $data );
			if ( $offset < $size && microtime( true ) > $deadline ) {
				return $offset;
			}
		}
		if ( $this->size( $part ) !== $size ) {
			throw new RuntimeException( esc_html__( 'FTP: the uploaded file has the wrong size.', 'resenje-site-mover' ) );
		}
		$this->commands( array( '*DELE ' . $name, 'RNFR ' . $part, 'RNTO ' . $name ) );
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
		$answer = $this->curl( $this->url(), array( CURLOPT_DIRLISTONLY => true ) );
		$names  = preg_split( '/\r?\n/', trim( $answer['body'] ) );
		$delete = self::names_to_delete( is_array( $names ) ? $names : array(), $prefix, $keep );
		if ( $delete ) {
			$this->commands(
				array_map(
					function ( $name ) {
						return '*DELE ' . $name;
					},
					$delete
				)
			);
		}
		return count( $delete );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name  Remote file name.
	 * @param array  $state Upload state.
	 */
	public function abort( $name, array $state ) {
		try {
			$this->commands( array( '*DELE ' . $name . '.part' ) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
