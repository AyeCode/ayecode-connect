<?php
/**
 * AyeCode Connect 2.0: talks to api.ayecode.io.
 *
 * Registration without an inbound call (works on localhost), site tokens, the command pull queue and the
 * daily ping. The legacy ayecode.io/wp-json/ayecode/v1 path stays available as a fallback for one release.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyeCode_Connect_API {

	const OPTION_SITE_TOKEN   = '_site_token';
	const OPTION_LOCAL        = '_local_site';
	const OPTION_LEGACY_UNTIL = '_legacy_api_until';
	const OPTION_AUTH_LOG     = '_auth_paths';
	const TRANSIENT_PULL      = '_commands_pulled';
	const CRON_PULL           = '_pull_commands';
	const CRON_REGISTER       = '_pending_registration';
	const TRANSIENT_EXCHANGE  = '_token_exchange_tried';

	/** @var AyeCode_Connect */
	protected $client;
	/** @var string */
	protected $prefix;
	/** @var string */
	protected $api_base;
	/** @var AyeCode_Connect_Envelope */
	protected $envelope;
	/** @var array prefixes whose hooks are registered: the plugin builds several clients per request, hooks run once */
	protected static $hooked = array();

	public function __construct( $client, $api_base ) {
		$this->client   = $client;
		$this->prefix   = $client->prefix;
		$this->api_base = untrailingslashit( $api_base );
		$this->envelope = new AyeCode_Connect_Envelope( $this->api_base );

		if ( ! empty( self::$hooked[ $this->prefix ] ) ) {
			return;
		}
		self::$hooked[ $this->prefix ] = true;
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( $this->prefix . self::CRON_PULL, array( $this, 'pull_commands' ) );
		add_action( $this->prefix . self::CRON_REGISTER, array( $this, 'try_register' ) );
		// before cron_callback (10) syncs licences, so an upgraded 1.4 site uses the API from its first daily run
		add_action( $this->prefix . '_callback', array( $this, 'maybe_exchange_legacy_token' ), 5 );
		add_action( $this->prefix . '_callback', array( $this, 'daily' ), 20 );
		add_action( 'admin_init', array( $this, 'admin_init' ), 5 );
	}

	public function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['ayecode_five_minutes'] ) ) {
			$schedules['ayecode_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes' );
		}
		return $schedules;
	}

	/* ---------- api base ---------- */

	public function api_base() {
		return $this->api_base;
	}

	public function envelope() {
		return $this->envelope;
	}

	/** True while the new API is marked down (5xx/unreachable in the last hour) and the legacy path should be used. */
	public function use_legacy() {
		return (int) get_option( $this->prefix . self::OPTION_LEGACY_UNTIL, 0 ) > time();
	}

	public function mark_api_down() {
		update_option( $this->prefix . self::OPTION_LEGACY_UNTIL, time() + HOUR_IN_SECONDS, false );
	}

	public function get_site_token() {
		return get_option( $this->prefix . self::OPTION_SITE_TOKEN, '' );
	}

	public function is_local() {
		return (bool) get_option( $this->prefix . self::OPTION_LOCAL, false );
	}

	/**
	 * Authenticated JSON request to the new API with the site token.
	 *
	 * @return array|WP_Error decoded body
	 */
	public function request( $method, $path, $body = null, $timeout = 30 ) {
		$token = $this->get_site_token();
		if ( ! $token ) {
			return new WP_Error( 'no_site_token', __( 'This site has no API token yet.', 'ayecode-connect' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'AyeCode Connect/' . AYECODE_CONNECT_VERSION . '; ' . home_url(),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $this->api_base . '/v1/connect' . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->mark_api_down();
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			$this->mark_api_down();
			return new WP_Error( 'api_down', sprintf( 'API returned %d', $code ) );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code ) {
			$this->handle_unauthorized();
			return new WP_Error( 'api_unauthorized', 'The site token was rejected.' );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'api_error', isset( $decoded['message'] ) ? $decoded['message'] : 'API error ' . $code, $decoded );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The API refused our site token: it was replaced or the site was removed from the account. Drop it. A site that
	 * still has its legacy token gets a new one on the next admin load; one without is disconnected and told to
	 * reconnect, as 1.4 did when the old server refused it.
	 */
	public function handle_unauthorized() {
		if ( ! $this->get_site_token() ) {
			return;
		}
		delete_option( $this->prefix . self::OPTION_SITE_TOKEN );
		wp_clear_scheduled_hook( $this->prefix . self::CRON_PULL );
		if ( $this->client->get_access_token() ) {
			delete_transient( $this->prefix . self::TRANSIENT_EXCHANGE );
			return;
		}
		$this->client->disconnect_site( false );
		set_transient( $this->prefix . '_site_moved', true, MONTH_IN_SECONDS );
	}

	/* ---------- registration (pull model) ---------- */

	/**
	 * Called when the user lands back on the Connect admin page and on a 5-minute cron while an activation
	 * secret exists. The store wrote a pending activation for our secret; the API hands us a site id and token.
	 */
	public function try_register() {
		if ( $this->client->is_registered() ) {
			wp_clear_scheduled_hook( $this->prefix . self::CRON_REGISTER );
			return true;
		}
		$secret = $this->client->get_activation_secret( false );
		if ( ! $secret ) {
			wp_clear_scheduled_hook( $this->prefix . self::CRON_REGISTER );
			return false;
		}
		$response = wp_remote_post( $this->api_base . '/v1/connect/register', array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'activation_secret' => $secret,
				'url'               => home_url(),
				'site_url'          => site_url(),
				'api_url'           => get_rest_url( null, $this->client->local_api_namespace ),
				'local'             => $this->client->is_local_domain(),
				'blogname'          => get_option( 'blogname' ),
				'site_lang'         => get_locale(),
				'multisite'         => is_multisite() ? 1 : 0,
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false; // 403 = store has not authorised yet; keep polling until the secret expires
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['site_id'] ) || empty( $data['site_token'] ) ) {
			return false;
		}
		$user = isset( $data['user'] ) && is_array( $data['user'] ) ? $data['user'] : array();
		update_option( $this->prefix . '_connected_username', isset( $user['user_login'] ) ? sanitize_user( $user['user_login'] ) : '' );
		update_option( $this->prefix . '_connected_email', isset( $user['user_email'] ) ? sanitize_email( $user['user_email'] ) : '' );
		update_option( $this->prefix . '_connected_name', isset( $user['display_name'] ) ? sanitize_text_field( $user['display_name'] ) : '' );
		update_option( $this->prefix . '_connected_user_id', isset( $user['ID'] ) ? absint( $user['ID'] ) : 0 );
		update_option( $this->prefix . '_blog_id', absint( $data['site_id'] ) );
		update_option( $this->prefix . '_blog_token', '' ); // 2.x sites have no legacy HS256 token; commands are signed
		update_option( $this->prefix . self::OPTION_SITE_TOKEN, sanitize_text_field( $data['site_token'] ) );
		update_option( $this->prefix . self::OPTION_LOCAL, ! empty( $data['local'] ) ? 1 : 0 );
		update_option( $this->prefix . '_licence_sync', true );
		update_option( $this->prefix . '_post_connect_setup', 1, false ); // install WP Easy Updates and sync licences on the next admin load
		delete_transient( $this->prefix . '_site_moved' );
		delete_transient( $this->prefix . '_activation_secret' );
		delete_option( $this->prefix . '_activation_secret' );
		wp_clear_scheduled_hook( $this->prefix . self::CRON_REGISTER );
		wp_clear_scheduled_hook( $this->prefix . '_callback' );
		wp_schedule_event( time(), 'daily', $this->prefix . '_callback' );
		$this->schedule_pull();
		do_action( $this->prefix . '_registered_via_api', $data );

		return true;
	}

	/** Exchange at most once an hour while it keeps failing (API down, token unknown yet). */
	public function maybe_exchange_legacy_token() {
		if ( $this->get_site_token() || ! $this->client->get_blog_id() || ! $this->client->get_access_token() ) {
			return false;
		}
		if ( false !== get_transient( $this->prefix . self::TRANSIENT_EXCHANGE ) ) {
			return false;
		}
		set_transient( $this->prefix . self::TRANSIENT_EXCHANGE, 1, HOUR_IN_SECONDS );

		return $this->exchange_legacy_token();
	}

	/**
	 * A site with a legacy HS256 blog token and no site token proves the legacy token to the API and gets a
	 * site token. From then on the 2.0 routes are used and the old server never needs to push to this site,
	 * which also fixes sites behind a proxy where the legacy IP check cannot see the real caller.
	 *
	 * @return bool
	 */
	public function exchange_legacy_token() {
		$blog_id = (int) $this->client->get_blog_id();
		$token   = $this->client->get_access_token();
		if ( $this->get_site_token() || ! $blog_id || ! $token ) {
			return false;
		}
		$jwt      = AyeCode_Connect::encode( array( 'blog_id' => $blog_id ), $token );
		$response = wp_remote_post( $this->api_base . '/v1/connect/token', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'X_AUTH ' . $jwt,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'AyeCode Connect/' . AYECODE_CONNECT_VERSION . '; ' . home_url(),
			),
			'body'    => wp_json_encode( array( 'url' => home_url(), 'local' => $this->client->is_local_domain() ) ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['site_token'] ) || (int) $data['site_id'] !== $blog_id ) {
			return false;
		}
		update_option( $this->prefix . self::OPTION_SITE_TOKEN, sanitize_text_field( $data['site_token'] ) );
		update_option( $this->prefix . self::OPTION_LOCAL, ! empty( $data['local'] ) ? 1 : 0 );
		// keep the site's own licence sync setting: a 1.4 site may have turned it off
		delete_transient( $this->prefix . self::TRANSIENT_EXCHANGE );
		$this->schedule_pull();
		do_action( $this->prefix . '_registered_via_api', $data );

		return true;
	}

	/** Start polling for registration after the user has been sent to the connect page. */
	public function schedule_registration_polling() {
		if ( ! wp_next_scheduled( $this->prefix . self::CRON_REGISTER ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ayecode_five_minutes', $this->prefix . self::CRON_REGISTER );
		}
	}

	/* ---------- commands (pull queue) ---------- */

	public function schedule_pull() {
		$hook = $this->prefix . self::CRON_PULL;
		wp_clear_scheduled_hook( $hook );
		if ( ! $this->get_site_token() ) {
			return;
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $this->is_local() ? 'ayecode_five_minutes' : 'daily', $hook );
	}

	public function admin_init() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Landing back on our admin page after the connect flow: try to complete registration.
		if ( ! $this->client->is_registered() && isset( $_GET['page'] ) && 'ayecode-connect' === $_GET['page'] ) {
			$this->try_register();
		}
		// Connected through the old server (1.4.x upgrade, or the ayecode.io/connect page still finishing the
		// handshake): swap the legacy token for an API token so licences and commands come from the API.
		$this->maybe_exchange_legacy_token();
		// First admin load after connecting: install WP Easy Updates and sync licences, as the old server did for 1.4.
		$this->client->maybe_finish_connection_setup();
		// Local sites cannot be reached inbound: pull commands on admin page loads, at most once a minute. Every site
		// pulls when its Connect page is opened, so anything queued while pushes are off arrives without the daily wait.
		$on_connect_page = isset( $_GET['page'] ) && 'ayecode-connect' === $_GET['page'];
		if ( ( $this->is_local() || $on_connect_page ) && $this->get_site_token() && false === get_transient( $this->prefix . self::TRANSIENT_PULL ) ) {
			set_transient( $this->prefix . self::TRANSIENT_PULL, 1, MINUTE_IN_SECONDS );
			$this->pull_commands();
		}
	}

	/**
	 * Fetch queued commands, verify each envelope, run it through the same handlers push uses, report results.
	 *
	 * @return int number of commands executed
	 */
	public function pull_commands() {
		$data = $this->request( 'GET', '/commands' );
		if ( is_wp_error( $data ) || empty( $data['commands'] ) || ! is_array( $data['commands'] ) ) {
			return 0;
		}
		$ran = 0;
		foreach ( $data['commands'] as $item ) {
			if ( empty( $item['envelope'] ) || empty( $item['signature'] ) ) {
				continue;
			}
			$verified = $this->envelope->verify( $item['envelope'], $item['signature'], $this->client->get_blog_id(), home_url() );
			$envelope = is_string( $item['envelope'] ) ? json_decode( $item['envelope'], true ) : $item['envelope'];
			$id       = is_array( $envelope ) && isset( $envelope['jti'] ) ? sanitize_text_field( $envelope['jti'] ) : '';
			if ( is_wp_error( $verified ) ) {
				$this->log_auth( 'pull_rejected' );
				if ( $id ) {
					$this->request( 'POST', '/commands/' . rawurlencode( $id ) . '/result', array( 'success' => false, 'error' => $verified->get_error_code() ) );
				}
				continue;
			}
			$this->log_auth( 'envelope' );
			$result = $this->execute( $envelope );
			$ran ++;
			if ( $id ) {
				$this->request( 'POST', '/commands/' . rawurlencode( $id ) . '/result', is_array( $result ) ? $result : array( 'success' => (bool) $result ) );
			}
		}

		return $ran;
	}

	/**
	 * Run a verified envelope through the remote-action handlers. They read their arguments from $_REQUEST,
	 * exactly as a pushed request would present them, so the payload is exposed the same way.
	 */
	public function execute( array $envelope ) {
		$action  = sanitize_title_with_dashes( $envelope['action'] );
		update_option( $this->prefix . '_last_command', array( 'action' => $action, 'path' => 'pull', 'time' => time() ), false );
		$payload = isset( $envelope['payload'] ) && is_array( $envelope['payload'] ) ? $envelope['payload'] : array();
		if ( ! class_exists( 'AyeCode_Connect_Remote_Actions' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-ayecode-connect-remote-actions.php';
			AyeCode_Connect_Remote_Actions::instance( $this->prefix, $this->client );
		}
		$saved = $_REQUEST;
		foreach ( $payload as $k => $v ) {
			$_REQUEST[ $k ] = is_array( $v ) ? wp_json_encode( $v ) : $v;
		}
		$_REQUEST['action'] = $action;
		$_REQUEST['ayecode_connect_verified_envelope'] = 1;
		$request = new WP_REST_Request( 'POST', '/' . $this->client->local_api_namespace . '/do_action' );
		foreach ( $_REQUEST as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$response = apply_filters( "{$this->prefix}_remote_action_{$action}", array( 'success' => false ), $request );
		$_REQUEST = $saved;

		return $response;
	}

	/**
	 * Store licences returned by the 2.0 activate_licenses route, through the same handler a pushed
	 * update_licences command uses (sanitisation and WPEU key storage live there).
	 */
	public function apply_licences( array $installed, array $all ) {
		return $this->execute( array(
			'action'  => 'update_licences',
			'payload' => array(
				'site_id'   => $this->client->get_blog_id(),
				'site_url'  => site_url(),
				'installed' => $installed,
				'all'       => $all,
			),
		) );
	}

	/* ---------- daily ping ---------- */

	public function daily() {
		if ( ! $this->get_site_token() ) {
			return;
		}
		$versions = array();
		if ( function_exists( 'get_plugins' ) || ( defined( 'ABSPATH' ) && @include_once ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			foreach ( get_plugins() as $file => $plugin ) {
				if ( ! empty( $plugin['Update ID'] ) || ( isset( $plugin['TextDomain'] ) && in_array( $plugin['TextDomain'], array( 'geodirectory', 'userswp', 'invoicing', 'ayecode-connect', 'external-updates' ), true ) ) ) {
					$versions[ $file ] = $plugin['Version'];
				}
			}
		}
		$this->request( 'POST', '/ping', array( 'versions' => $versions, 'local' => $this->is_local(), 'auth_paths' => get_option( $this->prefix . self::OPTION_AUTH_LOG, array() ) ) );
		// Safety net for every site: pull once a day in case a push was missed.
		$this->pull_commands();
	}

	/** Count which permission path accepted commands, so support and the daily ping can see 1.4.x vs 2.0 traffic. */
	public function log_auth( $path ) {
		$log = get_option( $this->prefix . self::OPTION_AUTH_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[ $path ] = isset( $log[ $path ] ) ? (int) $log[ $path ] + 1 : 1;
		$log['last_' . $path ] = time();
		update_option( $this->prefix . self::OPTION_AUTH_LOG, $log, false );
	}
}
