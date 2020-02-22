<?php

/**
 * This class enables two-way communication between a Client's WordPress website and a remote WordPress website
 *
 * @link https://github.com/AyeCode/wp-service-provider - The server class
 *
 */


if ( ! class_exists( 'AyeCode_Connect' ) ) :

	/**
	 * The  Connection class that is used as a single gateway between remote host and this plugin
	 */
	class AyeCode_Connect {

		/**
		 * Remote URL base
		 *
		 * @var string
		 */
		public $remote_url = '';

		/**
		 * Remote connection URL
		 *
		 * @var string
		 */
		public $connection_url = '';

		/**
		 * Remote api URL
		 *
		 * @var string
		 */
		public $api_url = '';

		/**
		 * Remote api URL namespace
		 *
		 * @var string
		 */
		public $api_namespace = 'ayecode/v1';

		/**
		 * Local api URL namespace
		 *
		 * @var string
		 */
		public $local_api_namespace = 'ayecode-connect/v1';

		/**
		 * Prefix
		 *
		 * @var string
		 */
		public $prefix = 'ayecode_connect';

		/**
		 * Prefix
		 *
		 * @var string
		 */
		public $textdomain = 'ayecode-connect';

		/**
		 * Class constructor
		 *
		 */
		public function __construct( array $args = array() ) {

			foreach ( $args as $key => $value ) {
				$this->{$key} = $value;
			}

			$this->api_url       = trailingslashit( $this->api_url );
			$this->api_namespace = ltrim( $this->api_namespace, '/' );

		}

		/**
		 * Initializes required listeners.
		 *
		 */
		public function init() {

			if ( $this->is_active() ) {

				//Connected
				do_action( $this->prefix . '_connected_to_remote' );
				add_action( 'rest_api_init', array( $this, 'register_connected_routes' ) );
				add_action( 'edd_api_button_args', array( $this, 'edd_api_button_args' ),8 );


			} else {

				//Not Connected
				add_action( 'rest_api_init', array( $this, 'register_connection_routes' ) );
				add_action( 'init', array( $this, 'maybe_redirect_to_connection_page' ) );
				do_action( $this->prefix . '_not_connected_to_remote' );

			}

		}

		/**
		 * Maybe add licence keys to the install buttons if we have them.
		 *
		 * @param $button_args
		 *
		 * @return mixed
		 */
		public function edd_api_button_args($button_args){

			if(defined( 'WP_EASY_UPDATES_ACTIVE' )){

				if(!empty($button_args['licensing']) && !empty($button_args['update_url']) && !empty($button_args['id']) && empty($button_args['license'])){
					$update_url = esc_url_raw($button_args['update_url']);
					$item_id = absint($button_args['id']);
					$domain = '';
					if( trailingslashit($update_url) == "https://wpgeodirectory.com/" || trailingslashit($update_url) == "http://wpgeodirectory.com/" ){
						$domain = 'wpgeodirectory.com';
					}elseif(trailingslashit($update_url) == "https://userswp.io/" || trailingslashit($update_url) == "http://userswp.io/" ){
						$domain = 'userswp.io';
					}elseif(trailingslashit($update_url) == "https://wpinvoicing.com/" || trailingslashit($update_url) == "http://wpinvoicing.com/" ){
						$domain = 'wwpinvoicing.com';
					}

					if($domain){
						$licences = get_option($this->prefix."_licences");
						if(isset($licences[$domain][$item_id])){
							$licence = $licences[$domain][$item_id];
							if(!empty($licence->key)){
								$button_args['license'] = $licence->key;
							}
						}
					}

				}
			}

			return $button_args;
		}


		/**
		 * Returns true if the current site is connected to remote
		 *
		 * @return Boolean is the site connected?
		 */
		public function is_active() {
			return (bool) $this->get_access_token();
		}

		/**
		 * Returns true if the site has both a token and a blog id, which indicates a site has been registered.
		 *
		 * @access public
		 *
		 * @return bool
		 */
		public function is_registered() {

			$blog_id   = (bool) $this->get_blog_id();
			$has_token = $this->is_active();

			return $blog_id && $has_token;

		}

		/**
		 * Returns the requested remote API URL.
		 *
		 * @param String $relative_url the relative API path.
		 *
		 * @return String API URL.
		 */
		public function get_api_url( $relative_url) {

			$api_url = $this->api_url;

			$api_base     = trailingslashit( $api_url . $this->api_namespace );
			$relative_url = ltrim( $relative_url, '/' );

			return $api_base . $relative_url;
		}

		/**
		 * Builds the timeout limit for queries talking with the remote servers.
		 *
		 * Based on local php max_execution_time in php.ini
		 *
		 * @since 1.0.0
		 * @return int
		 **/
		public function get_max_execution_time() {

			$timeout = (int) ini_get( 'max_execution_time' );

			// Ensure exec time set in php.ini.
			if ( ! $timeout ) {
				$timeout = 30;
			}

			return $timeout;

		}

		/**
		 * Sets a minimum request timeout, and returns the current timeout
		 *
		 * @since 1.0.0
		 *
		 * @param Integer $min_timeout the minimum timeout value.
		 *
		 * @return int The timeout value.
		 **/
		public function set_min_time_limit( $min_timeout ) {

			$timeout = $this->get_max_execution_time();
			if ( $timeout < $min_timeout ) {
				$timeout = $min_timeout;
				set_time_limit( $timeout );
			}

			return $timeout;

		}

		/**
		 * Get our assumed site creation date.
		 * Calculated based on the earlier date of either:
		 * - Earliest admin user registration date.
		 * - Earliest date of post of any post type.
		 *
		 * @since 1.0.0
		 *
		 * @return string Assumed site creation date and time.
		 */
		public function get_assumed_site_creation_date() {
			$earliest_registered_users  = get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'user_registered',
					'order'   => 'ASC',
					'fields'  => array( 'user_registered' ),
					'number'  => 1,
				)
			);
			$earliest_registration_date = $earliest_registered_users[0]->user_registered;

			$earliest_posts = get_posts(
				array(
					'posts_per_page' => 1,
					'post_type'      => 'any',
					'post_status'    => 'any',
					'orderby'        => 'date',
					'order'          => 'ASC',
				)
			);

			// If there are no posts at all, we'll count only on user registration date.
			if ( $earliest_posts ) {
				$earliest_post_date = $earliest_posts[0]->post_date;
			} else {
				$earliest_post_date = PHP_INT_MAX;
			}

			return min( $earliest_registration_date, $earliest_post_date );
		}

		/**
		 * Deletes secret tokens in case they, for example, have expired.
		 */
		public function delete_secrets() {

			delete_option( $this->prefix . '_blog_id' );
			delete_option( $this->prefix . '_blog_token' );
			delete_option( $this->prefix . '_connected_username' );
			delete_option( $this->prefix . '_licence_sync' );
			delete_option( $this->prefix . '_licences' );
			delete_transient( $this->prefix . '_activation_secret' );

		}

		/**
		 * Responds to the remote's call to register the current site.
		 *
		 * @param array $registration_data Array of [ activation_secret, blog_id, access_token ].
		 *
		 * @return mixed|WP_Error|WP_REST_Response
		 */
		public function handle_registration( array $registration_data ) {
			list( $activation_secret, $blog_id, $access_token, $username ) = $registration_data;

			if ( empty( $activation_secret ) || empty( $access_token ) || empty( $blog_id ) ) {
				return new WP_Error( 'registration_state_invalid', __( 'Invalid Registration Data', 'ayecode-connect' ), 400 );
			}

			if ( $this->get_activation_secret() != $activation_secret ) {
				return new WP_Error( 'invalid_secret', __( 'Invalid Secret', 'ayecode-connect' ), 401 );
			}

			update_option( $this->prefix . '_connected_username', $username );
			update_option( $this->prefix . '_blog_id', $blog_id );
			update_option( $this->prefix . '_blog_token', $access_token );
			update_option( $this->prefix . '_licence_sync', true );

			// just in case it was disabled, add it back here
			if ( ! wp_next_scheduled( $this->prefix . "_callback" ) ) {
				wp_schedule_event( time(), 'daily', $this->prefix . "_callback" );
			}

			return rest_ensure_response( true );

		}

		/**
		 * Returns the activation secret which expires after an hour.
		 *
		 * @return mixed|string
		 */
		public function get_activation_secret() {

			//Prepare transient name
			$transient = $this->prefix . '_activation_secret';

			//Fetch its value
			$secret = get_transient( $transient );

			//If set, return
			if ( ! empty( $secret ) ) {
				return $secret;
			}

			//Else, create a new activation secret...
			$secret = wp_generate_password( 24, false );

			//Then cache it as a transient
			set_transient( $transient, $secret, 3 * HOUR_IN_SECONDS );

			//Return the new activation secret
			return $secret;

		}

		/**
		 * Builds a URL to the remote connection auth page.
		 *
		 * @param bool $raw If true, URL will not be escaped.
		 * @param bool|string $redirect If true, will redirect back to wp-admin page after connection.
		 *                              If string, will be a custom redirect.
		 *
		 * @return string Connect URL
		 */
		public function build_connect_url( $redirect = true ) {

			$user       = wp_get_current_user();
			$admin_page = esc_url_raw( admin_url( "index.php?page=ayecode-connect" ) );

			//Setup a redirect url after successful connection
			$redirect = $redirect
				? wp_validate_redirect( esc_url_raw( $redirect ), $admin_page )
				: $admin_page;

			//Build the connection URL
			$args = urlencode_deep(
				array(
					'redirect_uri'      => urlencode( $redirect ),
					'remote_user_id'    => $user->ID,
					'user_email'        => $user->user_email,
					'user_login'        => $user->user_login,
					'activation_secret' => $this->get_activation_secret(),
					'secret_key'        => wp_generate_password( 40, true, true ),
					'blogname'          => get_option( 'blogname' ),
					'site_url'          => site_url(),
					'home_url'          => home_url(),
					'api_url'           => get_rest_url( null, $this->local_api_namespace ),
					'site_icon'         => get_site_icon_url(),
					'site_lang'         => get_locale(),
					'site_created'      => $this->get_assumed_site_creation_date(),
				)
			);

			return add_query_arg( $args, $this->connection_url );

		}

		/**
		 * Disconnects from the remote servers.
		 * Forgets all connection details and tells the remote servers to do the same.
		 */
		public function disconnect_site() {

			$site_id = $this->get_blog_id();

			//Abort early if it is not connected
			if ( ! $site_id ) {
				return false;
			}

			//Disconnect from remote...
			$args     = array(
				'url'    => $this->get_api_url( sprintf( '/sites/%d', $site_id ) ),
				'method' => 'DELETE'
			);
			$response = self::remote_request( $args );


			//Then delete local secrets
			$this->delete_secrets();

			return $response;

		}

		/**
		 * Request to enable update notifications.
		 *
		 * @return array|mixed|void|WP_Error
		 */
		public function request_licences( $site = '' ) {

			$site_id = $this->get_blog_id();

			//Abort early if it is not connected
			if ( ! $site_id ) {
				return;
			}

			//Disconnect from remote...
			$args = array(
				'url'    => $this->get_api_url( '/licenses'),
				'method' => 'GET'
			);

			$response = self::remote_request( $args );

			//in case the request failed...
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			return $body;
		}

		/**
		 * Request that licences for any of our plugins are auto synced.
		 *
		 * @return array|bool|mixed|WP_Error
		 */
		public function sync_licences() {

			// only run if WPEU is active
			if ( ! defined( 'WP_EASY_UPDATES_ACTIVE' ) ) {
				return false;
			}

			$site_id = $this->get_blog_id();

			//Abort early if it is not connected
			if ( ! $site_id ) {
				return false;
			}

			//Disconnect from remote...
			$args = array(
				'url'    => $this->get_api_url( '/activate_licenses' ),
				'method' => 'POST'
			);

			$plugins = get_plugins();

			// remove any non AyeCode plugins.
			foreach ( $plugins as $slug => $plugin ) {
				if ( empty( $plugin['Update URL'] ) ) {
					unset( $plugins[ $slug ] );
				}
			}

			// maybe add current licence keys
			$keys = get_option( 'exup_keys', array() );
			if ( ! empty( $keys ) ) {
				foreach ( $keys as $plugin_slug => $key_info ) {
					if ( isset( $plugins[ $plugin_slug ] ) && isset( $key_info->key ) ) {
						$plugins[ $plugin_slug ]['key'] = $key_info->key;
					}
				}
			}

			$body = array(
				'plugins' => $plugins
			);

			$response = self::remote_request( $args, $body );

			
			//in case the request failed...
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );


			return $body;

		}

		/**
		 * Request to enable update notifications.
		 *
		 * @return array|mixed|void|WP_Error
		 */
		public function request_updates() {

			$site_id = $this->get_blog_id();

			//Abort early if it is not connected
			if ( ! $site_id ) {
				return;
			}

			//Disconnect from remote...
			$args = array(
				'url'    => $this->get_api_url( sprintf( '/enable-updates/%d', $site_id ) ),
				'method' => 'POST'
			);

			$response = self::remote_request( $args );

			//in case the request failed...
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			return $body;
		}

		/**
		 * Checks if the current domain can run the plugin
		 *
		 * @param string $domain The domain to check.
		 *
		 * @return bool|WP_Error
		 */
		public function is_usable_domain( $domain ) {

			// If it's empty, just fail out.
			if ( ! $domain ) {
				return new WP_Error(
					'fail_domain_empty',
					/* translators: %1$s is a domain name. */
					sprintf( __( 'Domain `%1$s` just failed is_usable_domain check as it is empty.', 'ayecode-connect' ), $domain )
				);
			}

			/**
			 * Skips the usuable domain check when connecting a site.
			 *
			 * Allows site administrators with domains that fail gethostname-based checks to pass the request to remote
			 *
			 * @since 1.0.0
			 *
			 * @param bool If the check should be skipped. Default false.
			 */
			if ( apply_filters( $this->prefix . '_skip_usuable_domain_check', false ) ) {
				return true;
			}

			// None of the explicit localhosts.
			$forbidden_domains = array(
				'localhost',
				'localhost.localdomain',
				'127.0.0.1'
			);

			if ( in_array( $domain, $forbidden_domains, true ) ) {
				return new WP_Error(
					'fail_domain_forbidden',
					sprintf(
					/* translators: %1$s is a domain name. */
						__(
							'Domain `%1$s` just failed is_usable_domain check as it is in the forbidden array.',
							'ayecode-connect'
						),
						$domain
					)
				);
			}

			// No .test or .local domains.
			if ( preg_match( '#\.(test|local)$#i', $domain ) ) {
				return new WP_Error(
					'fail_domain_tld',
					sprintf(
					/* translators: %1$s is a domain name. */
						__(
							'Domain `%1$s` just failed is_usable_domain check as it uses an invalid top level domain.',
							'ayecode-connect'
						),
						$domain
					)
				);
			}

			return true;
		}

		/**
		 * Gets the remote's access token.
		 *
		 *
		 * @return string|false
		 */
		public function get_access_token() {

			$option_name = $this->prefix . '_blog_token';

			return get_option( $option_name, false );

		}

		/**
		 * Get the connected users username.
		 *
		 * @return mixed|void
		 */
		public function get_connected_username() {

			$option_name = $this->prefix . '_connected_username';

			return get_option( $option_name, false );
		}

		/**
		 * Gets the remote's blog id.
		 *
		 * @return string|false
		 */
		public function get_blog_id() {

			$option_name = $this->prefix . '_blog_id';

			return get_option( $option_name, false );

		}

		/**
		 * Redirects a user to the remote's connection page
		 *
		 * @return void
		 */
		public function maybe_redirect_to_connection_page() {

			//Only admins have the capability to connect
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			//Ensure that this is an admin page
			if ( ! is_admin() ) {
				return;
			}

			//And that the user wants to be redirected
			$action = $this->prefix . '_redirect_to_activation_url';
			if ( empty( $_GET['action'] ) || $action != $_GET['action'] ) {
				return;
			}

			//Prepare the connect URL
			$url = $this->build_connect_url();

			//Then redirect the user to the URL
			wp_redirect( esc_url( $url ) );
			exit;
		}

		/**
		 * Checks if the request actually came from the connected remote
		 *
		 * Use this as the permission callback when registering new REST Routes
		 *
		 * @since    1.0.0
		 */
		public function is_api_request_authenticated( $request ) {

			$headers = $request->get_header_as_array( 'Authorization' );
			if ( empty( $headers ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Missing Authorization Header.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			$jwt = '';
			foreach ( $headers as $header ) {

				$header = trim( $header );
				if ( strpos( $header, 'X_AUTH' ) === 0 ) {
					$jwt = trim( substr( $header, 7 ) );
					break;
				}

			}

			//Ensure the jwt auth is set...
			if ( empty( $jwt ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Missing Authorization Header.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//And is valid
			$tokens = explode( '.', $jwt );
			if ( count( $tokens ) != 3 ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Invalid Authorization Header.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//The key used to authenticate the request
			$key = $this->get_access_token();

			if ( empty( $key ) ) {
				return new WP_Error( 'missing_token', esc_html__( 'Missing blog token.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//Use it to decode the jwt token
			if ( false === self::decode( $jwt, $key ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'You are not authorized to do that.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//This request is authentic
			return true;

		}

		/**
		 * Decodes a JWT string into a PHP object.
		 *
		 * @param string $jwt The JWT
		 * @param string|array $key The key. If the algorithm used is asymmetric, this is the public key
		 *
		 * @return false|object The JWT's payload as a PHP object
		 */
		public static function decode( $jwt, $key ) {

			$tokens = explode( '.', $jwt );
			if ( count( $tokens ) != 3 ) {
				return false;
			}

			list( $header_64, $body_64, $hash_64 ) = $tokens;

			//Header contains the algorithym used to encode the jwt
			if ( null === ( $header = json_decode( self::url_safe_base64_decode( $header_64 ) ) ) ) {
				return false;
			}

			//Payload contains the blog id etc
			if ( null === $payload = json_decode( self::url_safe_base64_decode( $body_64 ) ) ) {
				return false;
			}

			//Signature is a hs256 encoding of the header and the payload
			if ( false === ( $signature = self::url_safe_base64_decode( $hash_64 ) ) ) {
				return false;
			}

			//Only HS256 is supported
			if ( empty( $header->alg ) || 'HS256' != $header->alg ) {
				return false;
			}

			// Check the signature
			$hash = hash_hmac( 'sha256', $header_64 . "." . $body_64, $key, true ); //(HS256)
			if ( ! hash_equals( $signature, $hash ) ) {
				return false;
			}

			return $payload;

		}

		/**
		 * Converts and signs a PHP object or array into a JWT string.
		 *
		 * @param object|array $payload PHP object or array
		 * @param string $key The secret key.
		 *                                  If the algorithm used is asymmetric, this is the private key
		 *
		 * @return string A signed JWT
		 *
		 */
		public static function encode( $payload, $key ) {

			$header = array( 'typ' => 'JWT', 'alg' => 'HS256' );

			$segments      = array();
			$segments[]    = self::url_safe_base64_encode( wp_json_encode( $header ) );
			$segments[]    = self::url_safe_base64_encode( wp_json_encode( $payload ) );
			$signing_input = implode( '.', $segments );
			$signature     = hash_hmac( 'sha256', $signing_input, $key, true ); //(HS256)
			$segments[]    = self::url_safe_base64_encode( $signature );

			return implode( '.', $segments );

		}

		/**
		 * Decode a string with URL-safe Base64.
		 *
		 * @param string $input A Base64 encoded string
		 *
		 * @return string A decoded string
		 */
		public static function url_safe_base64_decode( $input ) {

			$remainder = strlen( $input ) % 4;
			if ( $remainder ) {
				$padlen = 4 - $remainder;
				$input .= str_repeat( '=', $padlen );
			}

			return base64_decode( strtr( $input, '-_', '+/' ) );

		}

		/**
		 * Encode a string with URL-safe Base64.
		 *
		 * @param string $input The string you want encoded
		 *
		 * @return string The base64 encode of what you passed in
		 */
		public static function url_safe_base64_encode( $input ) {
			return str_replace( '=', '', strtr( base64_encode( $input ), '+/', '-_' ) );
		}

		/**
		 * Makes an authorized remote request to the remote website
		 *
		 * @param array $args the arguments for the remote request.
		 * @param array|String $body the request body.
		 *
		 * @return array|WP_Error WP HTTP response on success
		 */
		public function remote_request( $args, $body = null ) {

			$defaults = array(
				'url'         => '',
				'blog_id'     => $this->get_blog_id(),
				'method'      => 'POST',
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(),
				'stream'      => false,
				'filename'    => null,
				'sslverify'   => true,
			);

			$args = wp_parse_args( $args, $defaults );

			$args['blog_id'] = (int) $args['blog_id'];

			$token = $this->get_access_token();

			if ( ! $token ) {
				return new WP_Error( 'missing_token' );
			}

			$method = strtoupper( $args['method'] );

			$timeout = intval( $args['timeout'] );

			$redirection = $args['redirection'];
			$stream      = $args['stream'];
			$filename    = $args['filename'];
			$sslverify   = $args['sslverify'];

			$request = compact( 'method', 'body', 'timeout', 'redirection', 'stream', 'filename', 'sslverify' );


			$url = esc_url( $args['url'] );

			$signature = self::encode( array( 'blog_id' => $args['blog_id'] ), $token );

			$request['headers'] = array_merge(
				$args['headers'],
				array(
					'Authorization'          => 'X_AUTH ' . $signature,
					'X-HTTP-Method-Override' => $method
				)
			);

			return wp_remote_request( $url, $request );

		}

		/**
		 * Registers REST routes only when the current website is connected to the remote website
		 *
		 */
		public function register_connected_routes() {

			// Initiates a given action
			register_rest_route(
				$this->local_api_namespace,
				'/do_action',
				array(
					'methods'             => WP_REST_Server::ALLMETHODS,
					'callback'            => array( $this, 'do_action' ),
					'permission_callback' => array( $this, 'has_permission' ),
				)
			);

		}

		/**
		 * Checks for permission
		 *
		 * @since    1.0.0
		 */
		public function has_permission( $request ) {

			//Retrieve the jwt for the request
			$jwt = $this->get_jwt( $request );

			//Ensure the jwt auth is set...
			if ( empty( $jwt ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Missing Authorization Header.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//And is valid
			$tokens = explode( '.', $jwt );
			if ( count( $tokens ) != 3 ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Invalid Authorization Header.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//Ensure the body is not empty
			$body = json_decode( self::url_safe_base64_decode( $tokens[1] ) );
			if ( empty( $body ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Invalid Authorization Header.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			//Retrieve the secret key associated with the blog id
			$key = $this->get_access_token();

			//... then use it to decrypt the jwt
			if ( empty( $key ) || empty( self::decode( $jwt, $key ) ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'You are not authorized to do that.', 'ayecode-connect' ), array( 'status' => 401 ) );
			}

			return true;

		}

		/**
		 * Retrieves the JWT for the request
		 *
		 * @since    1.0.0
		 */
		public function get_jwt( $request ) {

			//Prepare authorization headers
			$auth_headers = $request->get_header_as_array( 'Authorization' );

			// If empty this might be because of the server removes the auth header https://github.com/WP-API/WP-API/issues/2512
			if ( empty( $auth_headers ) ) {
				$auth_headers = $request->get_header_as_array( 'X-AYE-Authorization' );
			}

			//The provided json web token
			$jwt = '';

			//Loop through them and retrieve our auth header
			if ( ! empty( $auth_headers ) ) {
				foreach ( $auth_headers as $header ) {

					$header = trim( $header );
					if ( strpos( $header, 'X_AUTH' ) === 0 ) {
						$jwt = trim( substr( $header, 7 ) );
						break;
					}

				}
			}

			return $jwt;
		}

		/**
		 * Init's a remote action
		 *
		 */
		public function do_action( $request ) {

			$prefix = $this->prefix;
			$action = sanitize_title_with_dashes( $request->get_param( 'action' ) );

			if ( empty( $action ) ) {
				return new WP_Error( 'missing_action', __( 'Specify an action', 'ayecode-connect' ) );
			}

			/**
			 * Run the remote actions class.
			 *
			 * This is only loaded if authenticated.
			 */
			require_once plugin_dir_path( __FILE__ ) . 'class-ayecode-connect-remote-actions.php';
			AyeCode_Connect_Remote_Actions::instance($prefix);

			$response = apply_filters( "{$prefix}_remote_action_{$action}", array( "success" => false ) );

			return rest_ensure_response( $response );

		}

		/**
		 * Registers REST routes only when the current website is not connected to the remote website
		 *
		 */
		public function register_connection_routes() {

			// Verifies registration
			register_rest_route(
				$this->local_api_namespace,
				'/verify_registration',
				array(
					'methods'  => WP_REST_Server::EDITABLE,
					'callback' => array( $this, 'verify_registration' ),
				)
			);

			// Returns a url to the connection page.
			register_rest_route(
				$this->local_api_namespace,
				'/connection_page',
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'connection_page' ),
				)
			);

		}

		/**
		 * Handles verification that a site is registered.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request The request sent to the WP REST API.
		 *
		 * @return string|WP_Error
		 */
		public function verify_registration( WP_REST_Request $request ) {

			//Prepare the registration data
			$registration_data = array(
				$request['activation_secret'],
				$request['blog_id'],
				$request['access_token'],
				$request['username']
			);

			//Then (maybe) save it
			return $this->handle_registration( $registration_data );

		}

		/**
		 * Returns a URL to the connection page.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request The request sent to the WP REST API.
		 *
		 * @return string|WP_Error
		 */
		public function connection_page( WP_REST_Request $request ) {

			$action = $this->prefix . '_redirect_to_activation_url';
			$url    = add_query_arg( 'action', $action, get_admin_url() );

			return rest_ensure_response( $url );

		}


	}
endif;