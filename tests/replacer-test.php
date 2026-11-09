<?php
/**
 * Standalone tests for RSMover_Replacer (run: php tests/replacer-test.php).
 *
 * @package ResenjeSiteMover
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-rsmover-replacer.php';

$fail = 0;
/**
 * Assertion helper.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 */
function check( $name, $expected, $actual ) {
	global $fail;
	if ( $expected === $actual ) {
		echo "ok   $name\n";
	} else {
		++$fail;
		echo "FAIL $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

$r = new RSMover_Replacer(
	array(
		'http://old.test'   => 'https://new.example.com',
		'http:\/\/old.test' => 'https:\/\/new.example.com',
		'//old.test'        => '//new.example.com',
		'/var/www/old'      => '/home/u/public_html',
	)
);

check( 'plain', 'see https://new.example.com/a', $r->replace( 'see http://old.test/a' ) );
check( 'json escaped', '{"u":"https:\/\/new.example.com\/x"}', $r->replace( '{"u":"http:\/\/old.test\/x"}' ) );
check( 'https variant via scheme-less', 'https://new.example.com/p', $r->replace( 'https://old.test/p' ) );

$data = array(
	'url'    => 'http://old.test/img.png',
	'n'      => 5,
	'f'      => 1.5,
	'b'      => true,
	'null'   => null,
	'nested' => array( 'path' => '/var/www/old/wp-content' ),
);
$out  = $r->replace( serialize( $data ) );
check(
	'serialized array',
	array(
		'url'    => 'https://new.example.com/img.png',
		'n'      => 5,
		'f'      => 1.5,
		'b'      => true,
		'null'   => null,
		'nested' => array( 'path' => '/home/u/public_html/wp-content' ),
	),
	unserialize( $out )
);

$double = serialize( serialize( array( 'x' => 'http://old.test' ) ) );
check( 'double serialized', array( 'x' => 'https://new.example.com' ), unserialize( unserialize( $r->replace( $double ) ) ) );

$obj      = new stdClass();
$obj->url = 'http://old.test/o';
$obj->arr = array( 'http://old.test' => 'key url' );
$res      = unserialize( $r->replace( serialize( $obj ) ) );
check( 'object prop', 'https://new.example.com/o', $res->url );
check( 'array key', array( 'https://new.example.com' => 'key url' ), $res->arr );

check( 'utf8 lengths', array( 'š' => 'čćž https://new.example.com ђ' ), unserialize( $r->replace( serialize( array( 'š' => 'čćž http://old.test ђ' ) ) ) ) );

$broken = 'a:1:{s:3:"url";s:99:"http://old.test";}';
check( 'broken serialized falls back to text', 'a:1:{s:3:"url";s:99:"https://new.example.com";}', $r->replace( $broken ) );

check( 'string with "; inside', array( 'a' => 'x";y https://new.example.com' ), unserialize( $r->replace( serialize( array( 'a' => 'x";y http://old.test' ) ) ) ) );
check( 'not serialized looks like', 'b: https://new.example.com;', $r->replace( 'b: http://old.test;' ) );
check( 'no match unchanged', 'a:1:{i:0;s:1:"x";}', $r->replace( 'a:1:{i:0;s:1:"x";}' ) );
check( 'percent kept', '100% https://new.example.com', $r->replace( '100% http://old.test' ) );
$deep = 'http://old.test';
for ( $i = 0; $i < 100; $i++ ) {
	$deep = array( $deep );
}
check( 'very deep does not crash', true, is_string( $r->replace( serialize( $deep ) ) ) );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
