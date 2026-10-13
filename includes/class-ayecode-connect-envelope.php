<?php
/**
 * Signed command envelopes (AyeCode Connect 2.0).
 *
 * Every remote command the API delivers to this site, pushed or pulled, is an Ed25519-signed envelope:
 * { kid, site_id, url, action, payload, iat, exp, jti } + detached signature. This class verifies it.
 * Public keys ship hardcoded and are refreshed daily from the API; the union of both is trusted.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyeCode_Connect_Envelope {

	/** Public keys by key id, raw 32-byte Ed25519 keys, base64. New kids are added here on rotation. */
	const KEYS = array(
		'2026-10' => 'QQRiDPwvQ17LA8m3GZp2ZaDMBDw0FitywpgdkjRA7Po=',
	);

	/** Old server IP, allowed only for legacy (unsigned) commands until 2.1. */
	const LEGACY_IPS = array( '173.208.153.114' );

	const CLOCK_SKEW = 60;
	const OPTION_SEEN = 'ayecode_connect_seen_jti';
	const TRANSIENT_KEYS = 'ayecode_connect_api_keys';
	const TRANSIENT_IPS = 'ayecode_connect_api_ips';

	/** @var string */
	protected $api_base;

	public function __construct( $api_base = 'https://api.ayecode.io' ) {
		$this->api_base = untrailingslashit( $api_base );
	}

	/**
	 * Verify an envelope for this site.
	 *
	 * @param array|string $envelope  Decoded envelope array, or its JSON string.
	 * @param string       $signature base64url detached signature.
	 * @param int          $site_id   This site's blog id as stored at connect time.
	 * @param string       $home_url  This site's home_url().
	 * @param int|null     $now       Unix time, for tests.
	 *
	 * @return true|WP_Error
	 */
	public function verify( $envelope, $signature, $site_id, $home_url, $now = null ) {
		$now = $now ? (int) $now : time();
		// The API delivers the envelope as the exact canonical string it signed; verify those bytes. An array
		// (e.g. from a JSON REST body) is canonicalised here instead.
		if ( is_string( $envelope ) ) {
			$signed_bytes = $envelope;
			$envelope     = json_decode( $envelope, true );
		} else {
			$signed_bytes = is_array( $envelope ) ? self::canonical_json( $envelope ) : '';
		}
		if ( ! is_array( $envelope ) ) {
			return new WP_Error( 'envelope_invalid', 'Envelope is not valid JSON.' );
		}
		foreach ( array( 'kid', 'site_id', 'url', 'action', 'payload', 'iat', 'exp', 'jti' ) as $field ) {
			if ( ! array_key_exists( $field, $envelope ) ) {
				return new WP_Error( 'envelope_invalid', 'Envelope is missing ' . $field . '.' );
			}
		}
		$keys = $this->get_keys();
		if ( empty( $keys[ $envelope['kid'] ] ) ) {
			return new WP_Error( 'envelope_unknown_kid', 'Unknown signing key ' . sanitize_text_field( $envelope['kid'] ) . '.' );
		}
		$public_key = base64_decode( $keys[ $envelope['kid'] ], true );
		$sig        = self::base64url_decode( (string) $signature );
		if ( strlen( $public_key ) !== 32 || strlen( $sig ) !== 64 ) {
			return new WP_Error( 'envelope_bad_signature', 'Signature has the wrong length.' );
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, $signed_bytes, $public_key ) ) {
			return new WP_Error( 'envelope_bad_signature', 'Signature does not verify.' );
		}
		if ( (int) $envelope['site_id'] !== (int) $site_id ) {
			return new WP_Error( 'envelope_wrong_site', 'Envelope is for another site id.' );
		}
		if ( self::normalise_url( $envelope['url'] ) !== self::normalise_url( $home_url ) ) {
			return new WP_Error( 'envelope_wrong_url', 'Envelope is for another site URL.' );
		}
		if ( (int) $envelope['iat'] - self::CLOCK_SKEW > $now ) {
			return new WP_Error( 'envelope_not_yet_valid', 'Envelope issued in the future.' );
		}
		if ( (int) $envelope['exp'] < $now ) {
			return new WP_Error( 'envelope_expired', 'Envelope has expired.' );
		}
		if ( ! $this->claim_jti( (string) $envelope['jti'], (int) $envelope['exp'], $now ) ) {
			return new WP_Error( 'envelope_replayed', 'Envelope has already been used.' );
		}

		return true;
	}

	/**
	 * Record a jti as used. Returns false if it was already used. Prunes expired entries on every call.
	 */
	protected function claim_jti( $jti, $exp, $now ) {
		$seen = get_option( self::OPTION_SEEN, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		foreach ( $seen as $id => $expires ) {
			if ( (int) $expires < $now ) {
				unset( $seen[ $id ] );
			}
		}
		if ( isset( $seen[ $jti ] ) ) {
			return false;
		}
		$seen[ $jti ] = $exp;
		update_option( self::OPTION_SEEN, $seen, false );

		return true;
	}

	/**
	 * Trusted public keys: hardcoded union daily-refreshed from the API.
	 *
	 * @return array kid => base64 raw key
	 */
	public function get_keys() {
		$keys   = self::KEYS;
		$remote = get_transient( self::TRANSIENT_KEYS );
		if ( false === $remote ) {
			$remote = array();
			$body   = $this->fetch_json( '/v1/keys.json' );
			if ( ! empty( $body['keys'] ) && is_array( $body['keys'] ) ) {
				foreach ( $body['keys'] as $kid => $spki ) {
					$raw = self::spki_to_raw( $spki );
					if ( $raw ) {
						$remote[ sanitize_text_field( $kid ) ] = base64_encode( $raw );
					}
				}
			}
			set_transient( self::TRANSIENT_KEYS, $remote, DAY_IN_SECONDS );
		}

		return array_merge( $keys, is_array( $remote ) ? $remote : array() );
	}

	/**
	 * IPs allowed to send legacy (JWT only, unsigned) commands. Hardcoded old server union the API's list.
	 * Transitional; removed in 2.1.
	 */
	public function get_allowed_legacy_ips() {
		$ips    = self::LEGACY_IPS;
		$remote = get_transient( self::TRANSIENT_IPS );
		if ( false === $remote ) {
			$remote = array();
			$body   = $this->fetch_json( '/v1/ips.json' );
			if ( ! empty( $body['ips'] ) && is_array( $body['ips'] ) ) {
				$remote = array_values( array_filter( array_map( 'sanitize_text_field', $body['ips'] ), function ( $ip ) {
					return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
				} ) );
			}
			set_transient( self::TRANSIENT_IPS, $remote, DAY_IN_SECONDS );
		}

		return array_values( array_unique( array_merge( $ips, is_array( $remote ) ? $remote : array() ) ) );
	}

	/**
	 * The request IP for the legacy path. REMOTE_ADDR only: forwarded-for headers are attacker controlled
	 * on any host that is not behind a trusted proxy.
	 */
	public static function remote_addr() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
	}

	protected function fetch_json( $path ) {
		$response = wp_remote_get( $this->api_base . $path, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}

	/* ---------- helpers ---------- */

	/**
	 * Canonical JSON: keys sorted recursively, no whitespace, slashes and unicode unescaped.
	 * Must produce byte-identical output to the API's canonicalJson().
	 */
	public static function canonical_json( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list && ! empty( $value ) ) {
				return '[' . implode( ',', array_map( array( __CLASS__, 'canonical_json' ), $value ) ) . ']';
			}
			if ( empty( $value ) ) {
				return '{}';
			}
			ksort( $value, SORT_STRING );
			$parts = array();
			foreach ( $value as $k => $v ) {
				$parts[] = json_encode( (string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ':' . self::canonical_json( $v );
			}

			return '{' . implode( ',', $parts ) . '}';
		}

		return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** Lowercase, no scheme, no www, no port, no trailing slash. Same as the API. */
	public static function normalise_url( $url ) {
		$url = strtolower( trim( (string) $url ) );
		$url = preg_replace( '#^https?://?#', '', $url );
		$url = preg_replace( '#^www\.#', '', $url );
		$url = preg_replace( '#^([^/:]+):\d+#', '$1', $url );

		return rtrim( $url, '/' );
	}

	public static function base64url_decode( $s ) {
		$s = strtr( $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		$out = base64_decode( $s, true );

		return false === $out ? '' : $out;
	}

	/** Ed25519 SPKI DER (44 bytes) to the raw 32-byte key; already-raw keys pass through. */
	public static function spki_to_raw( $b64 ) {
		$der = base64_decode( (string) $b64, true );
		if ( false === $der ) {
			return '';
		}
		if ( strlen( $der ) === 32 ) {
			return $der;
		}
		if ( strlen( $der ) === 44 && substr( $der, 0, 12 ) === "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" ) {
			return substr( $der, 12 );
		}

		return '';
	}
}
