<?php
/**
 * WP-CLI commands for AyeCode Connect 2.0.
 *
 *   wp ayecode-connect status          connection, token and command state (for support)
 *   wp ayecode-connect pull            fetch and run queued commands now
 *   wp ayecode-connect sync-licences   sync licences from the account now
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyeCode_Connect_CLI {

	/** @var AyeCode_Connect */
	protected $client;

	public function __construct( $client ) {
		$this->client = $client;
	}

	/**
	 * Show the connection state. Never prints secrets, only whether they exist.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$rows = $this->status_rows();
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
			return;
		}
		$items = array();
		foreach ( $rows as $k => $v ) {
			$items[] = array( 'field' => $k, 'value' => is_scalar( $v ) || null === $v ? (string) $v : wp_json_encode( $v ) );
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Pull and run queued commands from api.ayecode.io now.
	 *
	 * @when after_wp_load
	 */
	public function pull( $args, $assoc_args ) {
		if ( ! $this->client->is_registered() ) {
			WP_CLI::error( 'Site is not connected.' );
		}
		$ran = $this->client->api->pull_commands();
		WP_CLI::success( sprintf( '%d command(s) run.', (int) $ran ) );
	}

	/**
	 * Sync licences from the connected account now.
	 *
	 * @subcommand sync-licences
	 * @when after_wp_load
	 */
	public function sync_licences( $args, $assoc_args ) {
		if ( ! $this->client->is_registered() ) {
			WP_CLI::error( 'Site is not connected.' );
		}
		if ( ! get_option( $this->client->prefix . '_licence_sync' ) ) {
			WP_CLI::error( 'Licence sync is switched off on the AyeCode Connect settings page.' );
		}
		$this->client->sync_licences();
		$keys = get_option( 'exup_keys', array() );
		WP_CLI::success( sprintf( '%d licence key(s) stored.', is_array( $keys ) ? count( $keys ) : 0 ) );
	}

	/** The fields `status` prints. Public so the settings debug panel and tests can reuse them. */
	public function status_rows() {
		$c      = $this->client;
		$prefix = $c->prefix;
		$log    = get_option( $prefix . '_auth_paths', array() );
		$last   = get_option( $prefix . '_last_command', array() );
		$keys   = get_option( 'exup_keys', array() );
		$fmt    = function ( $ts ) { return $ts ? gmdate( 'Y-m-d H:i:s', (int) $ts ) . ' UTC (' . human_time_diff( (int) $ts ) . ' ago)' : 'never'; };

		return array(
			'version'             => defined( 'AYECODE_CONNECT_VERSION' ) ? AYECODE_CONNECT_VERSION : '',
			'connected'           => $c->is_registered() ? 'yes' : 'no',
			'site_id'             => (int) $c->get_blog_id(),
			'connected_user'      => (string) get_option( $prefix . '_connected_username', '' ),
			'site_url'            => $c->get_site_url(),
			'local_site'          => $c->api->is_local() ? 'yes (commands are pulled)' : 'no',
			'api'                 => $c->use_legacy_api() ? 'legacy (ayecode.io)' : 'api.ayecode.io',
			'api_base'            => apply_filters( 'ayecode_connect_api_base', 'https://api.ayecode.io' ),
			'site_token'          => $c->api->get_site_token() ? 'present' : 'none',
			'legacy_blog_token'   => get_option( $prefix . '_blog_token' ) ? 'present' : 'none',
			'updates_plugin'      => defined( 'WP_EASY_UPDATES_ACTIVE' ) ? 'active' : 'not active',
			'licence_sync'        => get_option( $prefix . '_licence_sync' ) ? 'on' : 'off',
			'licence_keys_stored' => is_array( $keys ) ? count( $keys ) : 0,
			'last_command'        => ! empty( $last['action'] ) ? $last['action'] . ' via ' . $last['path'] . ' at ' . $fmt( $last['time'] ) : 'none',
			'commands_signed'     => isset( $log['envelope'] ) ? (int) $log['envelope'] : 0,
			'commands_legacy_jwt' => isset( $log['legacy_jwt'] ) ? (int) $log['legacy_jwt'] : 0,
			'commands_rejected'   => ( isset( $log['pull_rejected'] ) ? (int) $log['pull_rejected'] : 0 ) + ( isset( $log['envelope_rejected'] ) ? (int) $log['envelope_rejected'] : 0 ) + ( isset( $log['legacy_rejected_ip'] ) ? (int) $log['legacy_rejected_ip'] : 0 ),
			'last_signed'         => $fmt( isset( $log['last_envelope'] ) ? $log['last_envelope'] : 0 ),
			'last_legacy_jwt'     => $fmt( isset( $log['last_legacy_jwt'] ) ? $log['last_legacy_jwt'] : 0 ),
			'support_user'        => get_option( $prefix . '_support_user', 0 ) ? 'active' : 'none',
		);
	}
}
