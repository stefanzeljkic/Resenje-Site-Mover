<?php
/**
 * Encryption of stored remote storage passwords and keys.
 *
 * Secrets are encrypted with libsodium (bundled with WordPress) using a key derived from the
 * site's AUTH salts in wp-config.php, so a database dump alone does not reveal them.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secret box helper.
 */
class RSMover_Crypto {

	const PREFIX      = 'rsm1:';
	const NONCE_BYTES = 24;

	/**
	 * Makes sure the sodium functions exist (sodium_compat ships with WordPress).
	 *
	 * @return bool
	 */
	private static function ready() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) && is_file( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Encryption key.
	 *
	 * @return string 32 bytes.
	 */
	private static function key() {
		return hash( 'sha256', 'resenje-site-mover|' . wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain Secret.
	 * @return string Encrypted value ('' for an empty secret).
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! self::ready() ) {
			return '';
		}
		$nonce = random_bytes( self::NONCE_BYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
	}

	/**
	 * Decrypts a secret.
	 *
	 * @param string $stored Encrypted value.
	 * @return string Secret, or '' when it cannot be decrypted (for example after the salts changed).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) || ! self::ready() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage.
		if ( false === $raw || strlen( $raw ) <= self::NONCE_BYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, self::NONCE_BYTES ), substr( $raw, 0, self::NONCE_BYTES ), self::key() );
		} catch ( Throwable $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}
}
