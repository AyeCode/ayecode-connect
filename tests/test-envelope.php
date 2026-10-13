<?php
// php tests/test-envelope.php   (needs ED25519 test key: set AYECODE_TEST_PRIV to the base64 PKCS#8 body, or a throwaway keypair is generated)
require __DIR__ . '/bootstrap-stubs.php';
require dirname( __DIR__ ) . '/includes/class-ayecode-connect-envelope.php';

$pass = 0; $fail = 0;
function check( $name, $ok, $info = '' ) { global $pass, $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $info ? "  $info" : '' ) . "\n"; $ok ? $pass++ : $fail++; }

// Key: use the real 2026-10 private key if provided (verifies against the shipped public key), else a throwaway pair registered via keys.json stub.
$priv_b64 = getenv( 'AYECODE_TEST_PRIV' );
if ( $priv_b64 ) {
	$der  = base64_decode( $priv_b64 ); $seed = substr( $der, -32 ); $kid = '2026-10';
	$kp   = sodium_crypto_sign_seed_keypair( $seed );
} else {
	$kp = sodium_crypto_sign_keypair(); $kid = 'test-kid';
	$GLOBALS['__http']['https://api.ayecode.io/v1/keys.json'] = http_response( 200, array( 'keys' => array( $kid => base64_encode( "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" . sodium_crypto_sign_publickey( $kp ) ) ) ) );
}
$secret = sodium_crypto_sign_secretkey( $kp );
$sign = function ( array $env ) use ( $secret ) {
	return rtrim( strtr( base64_encode( sodium_crypto_sign_detached( AyeCode_Connect_Envelope::canonical_json( $env ), $secret ) ), '+/', '-_' ), '=' );
};
$now = time();
$base = array( 'kid' => $kid, 'site_id' => 4839, 'url' => 'example.com', 'action' => 'install_plugin', 'payload' => array( 'slug' => 'wp-easy-updates', 'activate' => true, 'n' => 1, 'nested' => array( 'b' => 2, 'a' => 1 ) ), 'iat' => $now, 'exp' => $now + 300, 'jti' => 'J1' );
$v = new AyeCode_Connect_Envelope();

check( 'canonical json sorts keys recursively', AyeCode_Connect_Envelope::canonical_json( array( 'b' => 1, 'a' => array( 'y' => '/x', 'x' => 'é' ) ) ) === '{"a":{"x":"é","y":"/x"},"b":1}' );
check( 'good envelope verifies', true === $v->verify( $base, $sign( $base ), 4839, 'https://www.Example.com/' ) );
check( 'replayed jti rejected', is_wp_error( $r = $v->verify( $base, $sign( $base ), 4839, 'https://example.com' ) ) && $r->get_error_code() === 'envelope_replayed' );
$e = $base; $e['jti'] = 'J2'; check( 'wrong site id rejected', is_wp_error( $r = $v->verify( $e, $sign( $e ), 4840, 'https://example.com' ) ) && $r->get_error_code() === 'envelope_wrong_site' );
$e['jti'] = 'J3'; check( 'wrong url rejected', is_wp_error( $r = $v->verify( $e, $sign( $e ), 4839, 'https://other.com' ) ) && $r->get_error_code() === 'envelope_wrong_url' );
$e['jti'] = 'J4'; $e['exp'] = $now - 1; check( 'expired rejected', is_wp_error( $r = $v->verify( $e, $sign( $e ), 4839, 'https://example.com' ) ) && $r->get_error_code() === 'envelope_expired' );
$e = $base; $e['jti'] = 'J5'; $e['iat'] = $now + 600; check( 'future iat rejected', is_wp_error( $r = $v->verify( $e, $sign( $e ), 4839, 'https://example.com' ) ) && $r->get_error_code() === 'envelope_not_yet_valid' );
$e = $base; $e['jti'] = 'J6'; $sig = $sign( $e ); $e['payload']['slug'] = 'evil'; check( 'tampered payload rejected', is_wp_error( $r = $v->verify( $e, $sig, 4839, 'https://example.com' ) ) && $r->get_error_code() === 'envelope_bad_signature' );
$e = $base; $e['jti'] = 'J7'; $e['kid'] = 'nope'; check( 'unknown kid rejected', is_wp_error( $r = $v->verify( $e, $sign( $e ), 4839, 'https://example.com' ) ) && $r->get_error_code() === 'envelope_unknown_kid' );
$e = $base; $e['jti'] = 'J8'; check( 'canonical string envelope accepted', true === $v->verify( AyeCode_Connect_Envelope::canonical_json( $e ), $sign( $e ), 4839, 'https://example.com' ) );
check( 'spki to raw', strlen( AyeCode_Connect_Envelope::spki_to_raw( 'MCowBQYDK2VwAyEAQQRiDPwvQ17LA8m3GZp2ZaDMBDw0FitywpgdkjRA7Po=' ) ) === 32 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '173.208.153.114';
check( 'remote_addr ignores X-Forwarded-For', AyeCode_Connect_Envelope::remote_addr() === '203.0.113.9' );
check( 'legacy ip list has old server', in_array( '173.208.153.114', $v->get_allowed_legacy_ips(), true ) );
echo "\n$pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );
