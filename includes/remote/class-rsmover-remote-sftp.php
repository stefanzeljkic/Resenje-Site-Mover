<?php
/**
 * SFTP destination (uses the bundled phpseclib library, MIT license).
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SFTP destination.
 */
class RSMover_Remote_SFTP extends RSMover_Remote {

	const PART = 4194304;

	/**
	 * Open connection.
	 *
	 * @var \phpseclib3\Net\SFTP|null
	 */
	private $sftp = null;

	/**
	 * Registers an autoloader for the bundled phpseclib (only if no other copy is loaded already).
	 */
	public static function load_library() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( class_exists( '\phpseclib3\Net\SFTP' ) ) {
			return;
		}
		$base = dirname( __DIR__, 2 ) . '/vendor/';
		$map  = array(
			'phpseclib3\\'              => $base . 'phpseclib/',
			'ParagonIE\\ConstantTime\\' => $base . 'constant_time_encoding/src/',
		);
		spl_autoload_register(
			function ( $class_name ) use ( $map ) {
				foreach ( $map as $prefix => $dir ) {
					if ( 0 === strpos( $class_name, $prefix ) ) {
						$file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
						if ( is_file( $file ) ) {
							require_once $file;
						}
						return;
					}
				}
			}
		);
	}

	/**
	 * SHA-256 fingerprint of a public host key in OpenSSH notation.
	 *
	 * @param string $key Key as returned by phpseclib ("type base64").
	 * @return string
	 */
	public static function fingerprint( $key ) {
		$parts = explode( ' ', (string) $key );
		$blob  = isset( $parts[1] ) ? base64_decode( $parts[1], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SSH public key blob.
		if ( false === $blob ) {
			return '';
		}
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OpenSSH fingerprint format.
	}

	/**
	 * Connects and logs in. Verifies the server host key (remembered on first use).
	 *
	 * @return \phpseclib3\Net\SFTP
	 * @throws RuntimeException On failure.
	 */
	private function connect() {
		if ( $this->sftp ) {
			return $this->sftp;
		}
		self::load_library();
		$host = isset( $this->cfg['host'] ) ? (string) $this->cfg['host'] : '';
		$port = ! empty( $this->cfg['port'] ) ? (int) $this->cfg['port'] : 22;
		if ( '' === $host ) {
			throw new RuntimeException( esc_html__( 'SFTP: server address is missing.', 'resenje-site-mover' ) );
		}
		try {
			$sftp = new \phpseclib3\Net\SFTP( $host, $port, 20 );
			$key  = $sftp->getServerPublicHostKey();
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			throw new RuntimeException( esc_html( sprintf( __( 'SFTP: cannot connect (%s).', 'resenje-site-mover' ), $e->getMessage() ) ) );
		}
		if ( ! $key ) {
			throw new RuntimeException( esc_html__( 'SFTP: cannot connect to the server.', 'resenje-site-mover' ) );
		}
		$fp    = self::fingerprint( $key );
		$known = isset( $this->cfg['fingerprint'] ) ? (string) $this->cfg['fingerprint'] : '';
		if ( '' !== $known && ! hash_equals( $known, $fp ) ) {
			/* translators: 1: remembered fingerprint, 2: fingerprint sent by the server */
			throw new RuntimeException( esc_html( sprintf( __( 'SFTP: the server host key changed (expected %1$s, got %2$s). If the server was reinstalled, clear the remembered host key in the settings.', 'resenje-site-mover' ), $known, $fp ) ) );
		}
		if ( '' === $known ) {
			RSMover_Schedule::remember_fingerprint( $fp );
			$this->cfg['fingerprint'] = $fp;
		}

		$user = isset( $this->cfg['user'] ) ? (string) $this->cfg['user'] : '';
		try {
			if ( isset( $this->cfg['auth'] ) && 'key' === $this->cfg['auth'] ) {
				$pass = isset( $this->cfg['passphrase'] ) && '' !== $this->cfg['passphrase'] ? $this->cfg['passphrase'] : false;
				$cred = \phpseclib3\Crypt\PublicKeyLoader::load( isset( $this->cfg['private_key'] ) ? (string) $this->cfg['private_key'] : '', $pass );
			} else {
				$cred = isset( $this->cfg['password'] ) ? (string) $this->cfg['password'] : '';
			}
			$ok = $sftp->login( $user, $cred );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			throw new RuntimeException( esc_html( sprintf( __( 'SFTP: login failed (%s).', 'resenje-site-mover' ), $e->getMessage() ) ) );
		}
		if ( ! $ok ) {
			throw new RuntimeException( esc_html__( 'SFTP: login failed. Check the user name, password or private key.', 'resenje-site-mover' ) );
		}
		$this->sftp = $sftp;
		return $sftp;
	}

	/**
	 * Creates the remote directory when needed.
	 *
	 * @param \phpseclib3\Net\SFTP $sftp Connection.
	 * @throws RuntimeException When the directory cannot be created.
	 */
	private function ensure_dir( $sftp ) {
		$dir = $this->dir();
		if ( '.' !== $dir && ! $sftp->is_dir( $dir ) && ! $sftp->mkdir( $dir, -1, true ) ) {
			/* translators: %s: directory */
			throw new RuntimeException( esc_html( sprintf( __( 'SFTP: cannot create the folder %s.', 'resenje-site-mover' ), $dir ) ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException On failure.
	 */
	public function test() {
		$sftp = $this->connect();
		$this->ensure_dir( $sftp );
		$file = $this->dir() . '/.rsmover-test-' . wp_generate_password( 8, false );
		if ( ! $sftp->put( $file, 'test' ) ) {
			throw new RuntimeException( esc_html__( 'SFTP: connected, but cannot write into the folder.', 'resenje-site-mover' ) );
		}
		$sftp->delete( $file );
		/* translators: 1: host key fingerprint */
		return sprintf( __( 'Connected and wrote a test file. Server host key: %1$s', 'resenje-site-mover' ), $this->cfg['fingerprint'] );
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
		$sftp = $this->connect();
		$size = (int) filesize( $local );
		$part = $this->dir() . '/' . $name . '.part';
		if ( 0 === $offset ) {
			$this->ensure_dir( $sftp );
			$sftp->delete( $part );
		} else {
			// Continue from what really arrived on the server.
			$remote = $sftp->filesize( $part );
			$offset = false === $remote ? 0 : (int) min( $offset, $remote );
		}
		while ( $offset < $size ) {
			$data = self::read_part( $local, $offset, self::PART );
			if ( '' === $data ) {
				throw new RuntimeException( 'Cannot read the backup file.' );
			}
			if ( ! $sftp->put( $part, $data, \phpseclib3\Net\SFTP::SOURCE_STRING, $offset ) ) {
				throw new RuntimeException( esc_html__( 'SFTP: writing the backup failed (disk full or no permission?).', 'resenje-site-mover' ) );
			}
			$offset += strlen( $data );
			if ( $offset < $size && microtime( true ) > $deadline ) {
				return $offset;
			}
		}
		$final = $this->dir() . '/' . $name;
		$sftp->delete( $final );
		if ( ! $sftp->rename( $part, $final ) ) {
			throw new RuntimeException( esc_html__( 'SFTP: cannot rename the uploaded file.', 'resenje-site-mover' ) );
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
		$sftp  = $this->connect();
		$names = $sftp->nlist( $this->dir() );
		$count = 0;
		foreach ( self::names_to_delete( is_array( $names ) ? $names : array(), $prefix, $keep ) as $name ) {
			if ( $sftp->delete( $this->dir() . '/' . $name ) ) {
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
		try {
			$this->connect()->delete( $this->dir() . '/' . $name . '.part' );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
