<?php
/**
 * Standalone tests for RSMover_Replacer (run: php tests/replacer-test.php).
 *
 * This is a command-line script and is not part of the plugin that is released, so its output is plain
 * text for a terminal and never HTML.
 *
 * @package ResenjeSiteMover
 */

// Run from the command line only; the file must never do anything when requested through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

// The plugin classes stop when ABSPATH is missing, so a stand-in value is defined for this standalone run.
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-rsmover-replacer.php';

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- terminal output, not HTML.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the replacer exists to rewrite serialized data, so the checks must build it, and unserialize only trusted test fixtures to compare results.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- shows the expected and actual values of a failed check.

/**
 * Assertion helper. Counts failures and prints one line per check.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return bool Whether the check passed.
 */
function rsmover_test_check( $name, $expected, $actual ) {
	if ( $expected === $actual ) {
		echo "ok   $name\n";
		return true;
	}
	echo "FAIL $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	return false;
}

/**
 * Runs all replacer checks.
 *
 * @return int Number of failed checks.
 */
function rsmover_test_run() {
	$failed = 0;
	$check  = function ( $name, $expected, $actual ) use ( &$failed ) {
		if ( ! rsmover_test_check( $name, $expected, $actual ) ) {
			++$failed;
		}
	};

	$r = new RSMover_Replacer(
		array(
			'http://old.test'   => 'https://new.example.com',
			'http:\/\/old.test' => 'https:\/\/new.example.com',
			'//old.test'        => '//new.example.com',
			'/var/www/old'      => '/home/u/public_html',
		)
	);

	$check( 'plain', 'see https://new.example.com/a', $r->replace( 'see http://old.test/a' ) );
	$check( 'json escaped', '{"u":"https:\/\/new.example.com\/x"}', $r->replace( '{"u":"http:\/\/old.test\/x"}' ) );
	$check( 'https variant via scheme-less', 'https://new.example.com/p', $r->replace( 'https://old.test/p' ) );

	$data = array(
		'url'    => 'http://old.test/img.png',
		'n'      => 5,
		'f'      => 1.5,
		'b'      => true,
		'null'   => null,
		'nested' => array( 'path' => '/var/www/old/wp-content' ),
	);
	$out  = $r->replace( serialize( $data ) );
	$check(
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
	$check( 'double serialized', array( 'x' => 'https://new.example.com' ), unserialize( unserialize( $r->replace( $double ) ) ) );

	$obj      = new stdClass();
	$obj->url = 'http://old.test/o';
	$obj->arr = array( 'http://old.test' => 'key url' );
	$res      = unserialize( $r->replace( serialize( $obj ) ) );
	$check( 'object prop', 'https://new.example.com/o', $res->url );
	$check( 'array key', array( 'https://new.example.com' => 'key url' ), $res->arr );

	$check( 'utf8 lengths', array( 'š' => 'čćž https://new.example.com ђ' ), unserialize( $r->replace( serialize( array( 'š' => 'čćž http://old.test ђ' ) ) ) ) );

	$broken = 'a:1:{s:3:"url";s:99:"http://old.test";}';
	$check( 'broken serialized falls back to text', 'a:1:{s:3:"url";s:99:"https://new.example.com";}', $r->replace( $broken ) );

	$check( 'string with "; inside', array( 'a' => 'x";y https://new.example.com' ), unserialize( $r->replace( serialize( array( 'a' => 'x";y http://old.test' ) ) ) ) );
	$check( 'not serialized looks like', 'b: https://new.example.com;', $r->replace( 'b: http://old.test;' ) );
	$check( 'no match unchanged', 'a:1:{i:0;s:1:"x";}', $r->replace( 'a:1:{i:0;s:1:"x";}' ) );
	$check( 'percent kept', '100% https://new.example.com', $r->replace( '100% http://old.test' ) );
	$deep = 'http://old.test';
	for ( $i = 0; $i < 100; $i++ ) {
		$deep = array( $deep );
	}
	$check( 'very deep does not crash', true, is_string( $r->replace( serialize( $deep ) ) ) );

	return $failed;
}

$rsmover_failed = rsmover_test_run();
echo $rsmover_failed ? "\n$rsmover_failed FAILED\n" : "\nALL PASSED\n";
exit( $rsmover_failed ? 1 : 0 );
