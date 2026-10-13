<?php
// php tests/test-remote-actions.php
require __DIR__ . '/bootstrap-stubs.php';
foreach ( array( 'add_action', 'add_filter', 'remove_action', 'do_action', 'wp_parse_url' ) as $fn ) {
	if ( ! function_exists( $fn ) ) { eval( "function $fn() { return func_num_args() > 1 ? func_get_arg(1) : null; }" ); }
}
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
if ( ! defined( 'AYECODE_CONNECT_VERSION' ) ) { define( 'AYECODE_CONNECT_VERSION', '2.0.0' ); }
require dirname( __DIR__ ) . '/includes/class-ayecode-connect-remote-actions.php';
$pass = 0; $fail = 0;
function check( $n, $ok ) { global $pass, $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $n . "\n"; $ok ? $pass++ : $fail++; }
$v = array( 'AyeCode_Connect_Remote_Actions', 'validate_download_url' );
check( 'api.ayecode.io allowed', $v( 'https://api.ayecode.io/dl/65056/2.3.35?exp=1&sig=x' ) );
check( 'downloads.wordpress.org allowed', $v( 'https://downloads.wordpress.org/plugin/ayecode-connect.zip' ) );
check( 'legacy wpgeodirectory.com allowed', $v( 'https://wpgeodirectory.com/edd-sl/package_download/abc' ) );
check( 'wpeasyupdates.com allowed', $v( 'https://wpeasyupdates.com/wp-easy-updates.zip' ) );
check( 'subdomain of allowed host allowed', $v( 'https://cdn.api.ayecode.io/x.zip' ) );
check( 'http rejected', ! $v( 'http://api.ayecode.io/dl/1/2' ) );
check( 'lookalike host rejected', ! $v( 'https://api.ayecode.io.evil.com/x.zip' ) );
check( 'other host rejected', ! $v( 'https://example.com/x.zip' ) );
check( 'empty rejected', ! $v( '' ) );
check( 'ip host rejected', ! $v( 'https://173.208.153.114/x.zip' ) );
echo "\n$pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );
