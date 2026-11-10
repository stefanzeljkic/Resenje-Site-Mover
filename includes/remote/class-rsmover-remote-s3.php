<?php
/**
 * Amazon S3 and S3-compatible destination (own AWS Signature V4 client over the WordPress HTTP API).
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * S3 destination.
 */
class RSMover_Remote_S3 extends RSMover_Remote {

	const MIN_PART  = 8388608;
	const MAX_PARTS = 9000;

	/**
	 * Setting value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function opt( $key ) {
		return isset( $this->cfg[ $key ] ) ? trim( (string) $this->cfg[ $key ] ) : '';
	}

	/**
	 * Region (default us-east-1).
	 *
	 * @return string
	 */
	private function region() {
		$region = $this->opt( 'region' );
		return '' === $region ? 'us-east-1' : $region;
	}

	/**
	 * Object key for a file name.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function key( $name ) {
		$prefix = trim( str_replace( '\\', '/', $this->opt( 'prefix' ) ), '/' );
		return '' === $prefix ? $name : $prefix . '/' . $name;
	}

	/**
	 * Scheme, host and path for a request.
	 *
	 * @param string $key Object key ('' for the bucket).
	 * @return array scheme, host, path.
	 * @throws RuntimeException When the settings are incomplete.
	 */
	private function target( $key ) {
		$bucket = $this->opt( 'bucket' );
		if ( '' === $bucket || '' === $this->opt( 'access_key' ) || '' === $this->opt( 'secret_key' ) ) {
			throw new RuntimeException( esc_html__( 'S3: bucket, access key and secret key are required.', 'resenje-site-mover' ) );
		}
		$endpoint = $this->opt( 'endpoint' );
		$scheme   = 'https';
		if ( '' === $endpoint ) {
			$host       = $bucket . '.s3.' . $this->region() . '.amazonaws.com'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- user-configured backup storage endpoint, no site assets are loaded from it.
			$path_style = false;
		} else {
			if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
				$endpoint = 'https://' . $endpoint;
			}
			$parts = wp_parse_url( $endpoint );
			if ( empty( $parts['host'] ) ) {
				throw new RuntimeException( esc_html__( 'S3: the endpoint address is not valid.', 'resenje-site-mover' ) );
			}
			$scheme     = strtolower( $parts['scheme'] );
			$host       = $parts['host'] . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
			$path_style = ! empty( $this->cfg['path_style'] );
			if ( ! $path_style ) {
				$host = $bucket . '.' . $host;
			}
		}
		$segments = array();
		if ( ! empty( $path_style ) ) {
			$segments[] = $bucket;
		}
		if ( '' !== $key ) {
			$segments = array_merge( $segments, explode( '/', $key ) );
		}
		$path = '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
		return array( $scheme, $host, $path );
	}

	/**
	 * Sends a signed request.
	 *
	 * @param string $method HTTP method.
	 * @param string $key    Object key ('' for the bucket).
	 * @param array  $query  Query parameters.
	 * @param string $body   Request body.
	 * @return array code, body, headers.
	 * @throws RuntimeException On HTTP or S3 errors.
	 */
	private function request( $method, $key, array $query = array(), $body = '' ) {
		list( $scheme, $host, $path ) = $this->target( $key );

		$now     = time();
		$amzdate = gmdate( 'Ymd\THis\Z', $now );
		$day     = gmdate( 'Ymd', $now );
		$hash    = hash( 'sha256', $body );
		$headers = array(
			'host'                 => $host,
			'x-amz-content-sha256' => $hash,
			'x-amz-date'           => $amzdate,
		);
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed = implode( ';', array_keys( $headers ) );

		ksort( $query );
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( (string) $value );
		}
		$query_string = implode( '&', $pairs );

		$canonical = $method . "\n" . $path . "\n" . $query_string . "\n" . $canonical_headers . "\n" . $signed . "\n" . $hash;
		$scope     = $day . '/' . $this->region() . '/s3/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$k         = hash_hmac( 'sha256', $day, 'AWS4' . $this->opt( 'secret_key' ), true );
		$k         = hash_hmac( 'sha256', $this->region(), $k, true );
		$k         = hash_hmac( 'sha256', 's3', $k, true );
		$k         = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$signature = hash_hmac( 'sha256', $to_sign, $k );

		$send = array(
			'x-amz-content-sha256' => $hash,
			'x-amz-date'           => $amzdate,
			'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $this->opt( 'access_key' ) . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature,
		);
		if ( '' !== $body ) {
			$send['Content-Type'] = 'application/octet-stream';
		}
		$url      = $scheme . '://' . $host . $path . ( '' === $query_string ? '' : '?' . $query_string );
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $send,
				'body'        => $body,
				'timeout'     => 300,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			throw new RuntimeException( esc_html( sprintf( __( 'S3: request failed (%s).', 'resenje-site-mover' ), $response->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$text = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$detail = self::xml_value( $text, 'Code' );
			$msg    = self::xml_value( $text, 'Message' );
			/* translators: 1: HTTP status, 2: S3 error code and message */
			throw new RuntimeException( esc_html( sprintf( __( 'S3: the server answered %1$d %2$s', 'resenje-site-mover' ), $code, trim( $detail . ' ' . $msg ) ) ) );
		}
		return array(
			'code'    => $code,
			'body'    => $text,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * First value of an XML element (S3 answers are flat enough for this; no XML parser needed).
	 *
	 * @param string $xml XML.
	 * @param string $tag Element name.
	 * @return string
	 */
	private static function xml_value( $xml, $tag ) {
		return preg_match( '#<' . $tag . '>([^<]*)</' . $tag . '>#', (string) $xml, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException On failure.
	 */
	public function test() {
		$key = $this->key( '.rsmover-test-' . strtolower( wp_generate_password( 8, false ) ) );
		$this->request( 'PUT', $key, array(), 'test' );
		$this->request( 'DELETE', $key );
		return __( 'Connected and wrote a test object into the bucket.', 'resenje-site-mover' );
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
		$key  = $this->key( $name );

		if ( $size <= self::MIN_PART ) {
			$this->request( 'PUT', $key, array(), self::read_part( $local, 0, $size ) );
			return $size;
		}

		if ( empty( $state['upload_id'] ) ) {
			$part_size = (int) max( self::MIN_PART, ceil( $size / self::MAX_PARTS / 1048576 ) * 1048576 );
			$answer    = $this->request( 'POST', $key, array( 'uploads' => '' ) );
			$upload_id = self::xml_value( $answer['body'], 'UploadId' );
			if ( '' === $upload_id ) {
				throw new RuntimeException( esc_html__( 'S3: the server did not start the upload.', 'resenje-site-mover' ) );
			}
			$state  = array(
				'upload_id' => $upload_id,
				'part_size' => $part_size,
				'etags'     => array(),
			);
			$offset = 0;
		}
		$part_size = (int) $state['part_size'];
		// Always continue at a part boundary.
		$offset = (int) ( floor( $offset / $part_size ) * $part_size );

		while ( $offset < $size ) {
			$number = (int) ( $offset / $part_size ) + 1;
			$data   = self::read_part( $local, $offset, $part_size );
			$answer = $this->request(
				'PUT',
				$key,
				array(
					'partNumber' => $number,
					'uploadId'   => $state['upload_id'],
				),
				$data
			);
			$etag   = isset( $answer['headers']['etag'] ) ? (string) $answer['headers']['etag'] : '';
			if ( '' === $etag ) {
				throw new RuntimeException( esc_html__( 'S3: the server did not confirm an uploaded part.', 'resenje-site-mover' ) );
			}
			$state['etags'][ (string) $number ] = $etag;
			$offset                            += strlen( $data );
			if ( $offset < $size && microtime( true ) > $deadline ) {
				return $offset;
			}
		}

		$xml = '<CompleteMultipartUpload>';
		$max = (int) ceil( $size / $part_size );
		for ( $i = 1; $i <= $max; $i++ ) {
			if ( empty( $state['etags'][ (string) $i ] ) ) {
				throw new RuntimeException( 'S3: a part of the upload is missing.' );
			}
			$xml .= '<Part><PartNumber>' . $i . '</PartNumber><ETag>' . esc_html( $state['etags'][ (string) $i ] ) . '</ETag></Part>';
		}
		$xml   .= '</CompleteMultipartUpload>';
		$answer = $this->request( 'POST', $key, array( 'uploadId' => $state['upload_id'] ), $xml );
		// S3 may answer 200 with an error document.
		if ( false !== strpos( $answer['body'], '<Error>' ) ) {
			/* translators: %s: S3 error message */
			throw new RuntimeException( esc_html( sprintf( __( 'S3: completing the upload failed (%s).', 'resenje-site-mover' ), self::xml_value( $answer['body'], 'Message' ) ) ) );
		}
		$state = array();
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
		$names = array();
		$token = '';
		do {
			$query = array(
				'list-type' => '2',
				'prefix'    => $this->key( $prefix ),
			);
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}
			$answer = $this->request( 'GET', '', $query );
			if ( preg_match_all( '#<Key>([^<]*)</Key>#', $answer['body'], $m ) ) {
				foreach ( $m[1] as $key ) {
					$names[] = html_entity_decode( $key, ENT_QUOTES | ENT_XML1, 'UTF-8' );
				}
			}
			$token = 'true' === self::xml_value( $answer['body'], 'IsTruncated' ) ? self::xml_value( $answer['body'], 'NextContinuationToken' ) : '';
		} while ( '' !== $token );

		$count = 0;
		foreach ( self::names_to_delete( $names, $prefix, $keep ) as $name ) {
			$this->request( 'DELETE', $this->key( $name ) );
			++$count;
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
		if ( empty( $state['upload_id'] ) ) {
			return;
		}
		try {
			$this->request( 'DELETE', $this->key( $name ), array( 'uploadId' => $state['upload_id'] ) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
