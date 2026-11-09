<?php
/**
 * Search and replace that keeps PHP serialized data valid.
 *
 * Serialized values are walked with a small parser (no unserialize(), so no object injection);
 * every string inside is replaced and its length prefix recalculated.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replacer.
 */
class RSMover_Replacer {

	const MAX_DEPTH = 64;

	/**
	 * Search strings.
	 *
	 * @var string[]
	 */
	private $from = array();

	/**
	 * Replacement strings.
	 *
	 * @var string[]
	 */
	private $to = array();

	/**
	 * Constructor.
	 *
	 * @param array $pairs Map of search => replace. Longer keys are applied first.
	 */
	public function __construct( array $pairs ) {
		$pairs = array_filter(
			$pairs,
			function ( $to, $from ) {
				return '' !== (string) $from && (string) $from !== (string) $to;
			},
			ARRAY_FILTER_USE_BOTH
		);
		uksort(
			$pairs,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->from = array_map( 'strval', array_keys( $pairs ) );
		$this->to   = array_map( 'strval', array_values( $pairs ) );
	}

	/**
	 * Whether there is anything to replace.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->from );
	}

	/**
	 * Replaces in one value.
	 *
	 * @param string $value Value.
	 * @param int    $depth Recursion depth.
	 * @return string
	 */
	public function replace( $value, $depth = 0 ) {
		$value = (string) $value;
		if ( $this->is_empty() || '' === $value ) {
			return $value;
		}
		if ( $depth < self::MAX_DEPTH && $this->looks_serialized( $value ) ) {
			try {
				$pos = 0;
				$out = $this->walk( $value, $pos, $depth );
				if ( strlen( $value ) === $pos ) {
					return $out;
				}
			} catch ( RuntimeException $e ) {
				// Not valid serialized data: treat it as plain text below.
				unset( $e );
			}
		}
		$has = false;
		foreach ( $this->from as $from ) {
			if ( false !== strpos( $value, $from ) ) {
				$has = true;
				break;
			}
		}
		return $has ? str_replace( $this->from, $this->to, $value ) : $value;
	}

	/**
	 * Cheap check whether a string may be serialized.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function looks_serialized( $value ) {
		return (bool) preg_match( '/^(?:N;|[bidsaOCE]:)/', $value ) && in_array( substr( $value, -1 ), array( ';', '}' ), true );
	}

	/**
	 * Reads up to (not including) a delimiter.
	 *
	 * @param string $s     Input.
	 * @param int    $pos   Position (advanced past the delimiter).
	 * @param string $delim Delimiter.
	 * @return string
	 * @throws RuntimeException On malformed data.
	 */
	private function read_until( $s, &$pos, $delim ) {
		$end = strpos( $s, $delim, $pos );
		if ( false === $end ) {
			throw new RuntimeException( 'bad' );
		}
		$out = substr( $s, $pos, $end - $pos );
		$pos = $end + strlen( $delim );
		return $out;
	}

	/**
	 * Expects a literal.
	 *
	 * @param string $s   Input.
	 * @param int    $pos Position.
	 * @param string $lit Literal.
	 * @throws RuntimeException On malformed data.
	 */
	private function expect( $s, &$pos, $lit ) {
		if ( substr( $s, $pos, strlen( $lit ) ) !== $lit ) {
			throw new RuntimeException( 'bad' );
		}
		$pos += strlen( $lit );
	}

	/**
	 * Reads a non-negative integer length.
	 *
	 * @param string $s     Input.
	 * @param int    $pos   Position.
	 * @param string $delim Delimiter after the number.
	 * @return int
	 * @throws RuntimeException On malformed data.
	 */
	private function read_len( $s, &$pos, $delim ) {
		$num = $this->read_until( $s, $pos, $delim );
		if ( ! ctype_digit( $num ) || strlen( $num ) > 10 ) {
			throw new RuntimeException( 'bad' );
		}
		return (int) $num;
	}

	/**
	 * Walks one serialized value and returns it rebuilt with replaced strings.
	 *
	 * @param string $s     Input.
	 * @param int    $pos   Position.
	 * @param int    $depth Depth.
	 * @return string
	 * @throws RuntimeException On malformed data.
	 */
	private function walk( $s, &$pos, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new RuntimeException( 'deep' );
		}
		$type = substr( $s, $pos, 1 );
		switch ( $type ) {
			case 'N':
				$this->expect( $s, $pos, 'N;' );
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$this->expect( $s, $pos, $type . ':' );
				$val = $this->read_until( $s, $pos, ';' );
				if ( ! preg_match( '/^[0-9eE.+\-INFA]*$/', $val ) ) {
					throw new RuntimeException( 'bad' );
				}
				return $type . ':' . $val . ';';
			case 's':
				$this->expect( $s, $pos, 's:' );
				$len = $this->read_len( $s, $pos, ':"' );
				if ( $pos + $len + 2 > strlen( $s ) ) {
					throw new RuntimeException( 'bad' );
				}
				$str  = substr( $s, $pos, $len );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				$new = $this->replace( $str, $depth + 1 );
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'E':
				// Enum: copied unchanged.
				$this->expect( $s, $pos, 'E:' );
				$len  = $this->read_len( $s, $pos, ':"' );
				$str  = substr( $s, $pos, $len );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				return 'E:' . $len . ':"' . $str . '";';
			case 'a':
				$this->expect( $s, $pos, 'a:' );
				$count = $this->read_len( $s, $pos, ':{' );
				$out   = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos, $depth + 1 );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';
			case 'O':
				$this->expect( $s, $pos, 'O:' );
				$len   = $this->read_len( $s, $pos, ':"' );
				$class = substr( $s, $pos, $len );
				$pos  += $len;
				$this->expect( $s, $pos, '":' );
				$count = $this->read_len( $s, $pos, ':{' );
				$out   = 'O:' . $len . ':"' . $class . '":' . $count . ':{';
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos, $depth + 1 );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';
			case 'C':
				// Custom serialization: the payload format is class specific, copied unchanged.
				$this->expect( $s, $pos, 'C:' );
				$len   = $this->read_len( $s, $pos, ':"' );
				$class = substr( $s, $pos, $len );
				$pos  += $len;
				$this->expect( $s, $pos, '":' );
				$dlen = $this->read_len( $s, $pos, ':{' );
				$data = substr( $s, $pos, $dlen );
				$pos += $dlen;
				$this->expect( $s, $pos, '}' );
				return 'C:' . $len . ':"' . $class . '":' . $dlen . ':{' . $data . '}';
		}
		throw new RuntimeException( 'bad' );
	}
}
