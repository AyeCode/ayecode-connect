<?php
// Minimal WordPress stubs so the 2.0 classes can be unit tested without a WordPress install.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
$GLOBALS['__options'] = array(); $GLOBALS['__transients'] = array(); $GLOBALS['__http'] = array();
class WP_Error { public $code; public $message; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { return update_option( $k, $v ); }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function wp_remote_get( $url, $args = array() ) { return isset( $GLOBALS['__http'][ $url ] ) ? $GLOBALS['__http'][ $url ] : new WP_Error( 'http', 'no stub for ' . $url ); }
function wp_remote_post( $url, $args = array() ) { return isset( $GLOBALS['__http'][ $url ] ) ? $GLOBALS['__http'][ $url ] : new WP_Error( 'http', 'no stub for ' . $url ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url_raw( $u ) { return $u; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function http_response( $code, $body ) { return array( 'response' => array( 'code' => $code ), 'body' => is_string( $body ) ? $body : json_encode( $body ) ); }
