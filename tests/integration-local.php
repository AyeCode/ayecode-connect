<?php
// Run on a WordPress site whose APIs point at a local Worker (see mu-plugins/ayecode-local-api.php):
//   wp eval-file wp-content/plugins/ayecode-connect/tests/integration-local.php
// Requires: FX_VALID env (a licence key for item 65056), the local Worker with dev tokens.
$GLOBALS['W'] = $W = 'http://127.0.0.1:8787'; $SYNC = 'dev-store-sync-token'; $FS = 'dev-freescout-token';
$KEY = getenv( 'FX_VALID' ); if ( ! $KEY ) { WP_CLI::error( 'set FX_VALID' ); }
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function chk( $n, $ok, $info = '' ) { WP_CLI::line( ( $ok ? 'ok   ' : 'FAIL ' ) . $n . ( $info ? "  $info" : '' ) ); $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; }
function api( $m, $p, $b = null, $h = array() ) { $r = wp_remote_request( $GLOBALS['W'] . $p, array( 'method' => $m, 'headers' => array_merge( array( 'content-type' => 'application/json' ), $h ), 'body' => $b ? wp_json_encode( $b ) : null, 'timeout' => 20 ) ); return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) ); }

global $ayecode_connect; $c = $ayecode_connect; $prefix = $c->prefix;
// clean slate
$c->disconnect_site( false ); delete_option( 'exup_site_token' ); delete_option( 'exup_keys' ); delete_option( $prefix . '_blog_token' ); delete_option( 'ayecode_connect_seen_jti' );

// ---- 1. registration via the API (pull model, local site) ----
$secret = $c->get_activation_secret();
chk( 'local domain is flagged local, not blocked', $c->is_local_domain() === true );
list( $s, $r ) = api( 'POST', '/v1/sync', array( 'ops' => array( array( 'entity' => 'pending_connection', 'op' => 'upsert', 'data' => array( 'secret_hash' => hash( 'sha256', $secret ), 'user_id' => 3036, 'url' => home_url() ) ) ) ), array( 'authorization' => 'Bearer ' . $SYNC ) );
chk( 'store wrote pending connection', $s === 200 && ! empty( $r['ok'] ) );
chk( 'try_register succeeds', $c->api->try_register() === true );
chk( 'site id and token stored', $c->get_blog_id() > 0 && strlen( $c->api->get_site_token() ) >= 32, 'site ' . $c->get_blog_id() );
chk( 'is_registered / is_active', $c->is_registered() && $c->is_active() );
chk( 'connected user stored', get_option( $prefix . '_connected_username' ) === 'atest2' );
chk( 'uses 2.0 api (not legacy)', ! $c->use_legacy_api() && strpos( $c->get_api_url( '/me/' . $c->get_blog_id() ), '/v1/connect/me' ) !== false );

// ---- 1b. a site connected through the old server exchanges its legacy token for an API token ----
{
	$lid = 999998; $bt = 'legacy-' . wp_generate_password( 24, false ); $lurl = 'https://legacy-' . wp_parse_url( home_url(), PHP_URL_HOST ); // its own URL: a mirrored site supersedes other rows for the same URL
	list( $s, $r ) = api( 'POST', '/v1/sync', array( 'source' => 'edd', 'ops' => array( array( 'entity' => 'site', 'op' => 'upsert', 'data' => array( 'id' => $lid, 'url' => $lurl, 'user_id' => 3036, 'home_url' => $lurl, 'blog_token' => $bt ) ) ) ), array( 'authorization' => 'Bearer ' . $SYNC ) );
	chk( 'old server mirrored the legacy site', $s === 200 && ! empty( $r['ok'] ) );
	$saved = array( get_option( $prefix . '_blog_id' ), get_option( $prefix . '_blog_token' ), $c->api->get_site_token() );
	update_option( $prefix . '_blog_id', $lid ); update_option( $prefix . '_blog_token', $bt ); delete_option( $prefix . '_site_token' ); delete_transient( $prefix . '_token_exchange_tried' );
	chk( 'legacy-only site uses the legacy api', $c->use_legacy_api() );
	chk( 'exchange_legacy_token succeeds', $c->api->maybe_exchange_legacy_token() === true );
	chk( 'site token stored, 2.0 api now used', strlen( $c->api->get_site_token() ) >= 32 && ! $c->use_legacy_api() );
	chk( 'exchange is a no-op once a token exists', $c->api->maybe_exchange_legacy_token() === false );
	api( 'DELETE', '/v1/connect/site', null, array( 'authorization' => 'Bearer ' . $c->api->get_site_token() ) );
	update_option( $prefix . '_blog_id', $saved[0] ); update_option( $prefix . '_blog_token', $saved[1] ); update_option( $prefix . '_site_token', $saved[2] );
}

// ---- 2. licence sync through the 2.0 route ----
$r = $c->sync_licences();
$keys = get_option( 'exup_keys', array() );
chk( 'sync_licences stored a key for the installed add-on', ! empty( $keys['geodir_advance_search_filters/geodir_advance_search_filters.php']->key ), implode( ',', array_keys( (array) $keys ) ) );
chk( 'all licences option populated by domain', ! empty( get_option( $prefix . '_licences' )['wpgeodirectory.com'] ) );

// ---- 3. WPEU update check goes to the API with the site token ----
chk( 'WPEU sees the Connect site token', External_Updates_API::get_token() === $c->api->get_site_token() );
$admin = new External_Updates_Admin( 'external-updates', WP_EASY_UPDATES_VERSION );
$pkgs = $admin->get_packages_for_update_by_src( 'plugin' );
$ours = array(); foreach ( $pkgs as $src => $p ) { if ( External_Updates_API::is_ours( $src ) ) { $ours += $p; } }
$info = External_Updates_API::check_updates( $ours );
$asf = isset( $info->{'geodir_advance_search_filters/geodir_advance_search_filters.php'} ) ? $info->{'geodir_advance_search_filters/geodir_advance_search_filters.php'} : null;
chk( 'API update check returns the add-on', $asf && ! empty( $asf->new_version ), $asf ? 'v' . $asf->new_version . ' license=' . $asf->license : 'none' );
chk( 'package link is a signed Worker download', $asf && strpos( $asf->package, '/dl/65056/' ) !== false );
// the real WP update-check path (check_for_updates) must use the API and never the legacy domains
$GLOBALS['seen_hosts'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) { $GLOBALS['seen_hosts'][] = wp_parse_url( $url, PHP_URL_HOST ); return $pre; }, 10, 3 );
$admin2 = new External_Updates_Admin( 'external-updates', WP_EASY_UPDATES_VERSION );
$admin2->check_for_plugin_updates( new stdClass() ); // first call is skipped by the core double-run guard
$td = $admin2->check_for_plugin_updates( (object) array( 'checked' => array(), 'response' => array(), 'no_update' => array() ) );
$legacy_hit = array_intersect( $GLOBALS['seen_hosts'], array( 'wpgeodirectory.com', 'wpinvoicing.com', 'wpgetpaid.com', 'userswp.io' ) );
chk( 'check_for_updates went to the API only', in_array( '127.0.0.1', $GLOBALS['seen_hosts'], true ) && empty( $legacy_hit ), implode( ',', array_unique( $GLOBALS['seen_hosts'] ) ) );
$found = isset( $td->response['geodir_advance_search_filters/geodir_advance_search_filters.php'] ) || isset( $td->no_update['geodir_advance_search_filters/geodir_advance_search_filters.php'] );
chk( 'check_for_updates filled the update transient from the API', $found );
$t = $admin->check_for_plugin_updates( (object) array( 'checked' => array(), 'response' => array() ) ); $t = $admin->check_for_plugin_updates( $t );
chk( 'check_for_updates populates the transient from the API', isset( $t->response['geodir_advance_search_filters/geodir_advance_search_filters.php'] ) || isset( $t->no_update['geodir_advance_search_filters/geodir_advance_search_filters.php'] ) );

// ---- 4. WPEU deactivate/activate via API ----
$d = $admin->deactivate_licence( 'geodir_advance_search_filters/geodir_advance_search_filters.php', $KEY, 'plugin' );
chk( 'deactivate via API', isset( $d['success'] ), wp_json_encode( $d ) );
$a = $admin->activate_licence( 'geodir_advance_search_filters/geodir_advance_search_filters.php', $KEY, 'plugin' );
chk( 'activate via API', isset( $a['success'] ), wp_json_encode( $a ) );
$a2 = $admin->activate_licence( 'geodir_advance_search_filters/geodir_advance_search_filters.php', 'deadbeefdeadbeefdeadbeefdeadbeef', 'plugin' );
chk( 'invalid key gives the invalid message', isset( $a2['error'] ) && strpos( $a2['error'], 'invalid' ) !== false, wp_json_encode( $a2 ) );

// ---- 4b. extensions-screen install: core's plugins_api() asks WPEU for a package it has not got installed ----
$GLOBALS['seen_hosts'] = array();
$_REQUEST['update_url'] = 'https://wpgeodirectory.com'; $_REQUEST['item_id'] = 65056; $_REQUEST['license'] = $KEY; $_REQUEST['wpeu_activate'] = '1';
if ( ! function_exists( 'plugins_api' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; }
$api_info = plugins_api( 'plugin_information', (object) array( 'slug' => 'gd-install-test' ) );
unset( $_REQUEST['update_url'], $_REQUEST['item_id'], $_REQUEST['license'], $_REQUEST['wpeu_activate'] );
$legacy_hit = array_intersect( $GLOBALS['seen_hosts'], array( 'wpgeodirectory.com', 'wpinvoicing.com', 'wpgetpaid.com', 'userswp.io', 'api.wordpress.org' ) );
chk( 'install lookup went to the API only', in_array( '127.0.0.1', $GLOBALS['seen_hosts'], true ) && empty( $legacy_hit ), implode( ',', array_unique( $GLOBALS['seen_hosts'] ) ) );
chk( 'install lookup returns a signed API package with name and version', is_object( $api_info ) && strpos( (string) $api_info->download_link, '/dl/65056/' ) !== false && ! empty( $api_info->name ) && ! empty( $api_info->version ), is_object( $api_info ) ? 'v' . $api_info->version : ( is_wp_error( $api_info ) ? $api_info->get_error_message() : 'none' ) );
$keys = get_option( 'exup_keys', array() ); unset( $keys['gd-install-test'] ); update_option( 'exup_keys', $keys );

// ---- 4c. enabling updates queues a WPEU install from the API (not the legacy zip host) ----
{
	// the local dev database may not carry the WPEU product row the live one has; make it so (idempotent)
	api( 'POST', '/v1/sync', array( 'ops' => array( array( 'entity' => 'product', 'op' => 'upsert', 'data' => array( 'id' => 9000001, 'slug' => 'wp-easy-updates', 'brand' => 'geodirectory', 'name' => 'WP Easy Updates', 'status' => 'publish', 'current_version' => '2.0.0', 'r2_key' => 'packages/wp-easy-updates/wp-easy-updates-2.0.0.zip', 'free' => 1 ) ) ) ), array( 'authorization' => 'Bearer ' . $SYNC ) );
	$r = $c->request_updates();
	chk( 'enable-updates accepted by the API', is_object( $r ) && ! empty( $r->success ), wp_json_encode( $r ) );
	list( $s, $q ) = api( 'GET', '/v1/connect/commands', null, array( 'authorization' => 'Bearer ' . $c->api->get_site_token() ) );
	$env = is_array( $q ) && ! empty( $q['commands'] ) ? json_decode( $q['commands'][0]['envelope'], true ) : array();
	$link = isset( $env['payload']['download_link'] ) ? $env['payload']['download_link'] : '';
	chk( 'queued command installs wp-easy-updates from a signed API download', isset( $env['action'] ) && $env['action'] === 'install_plugin' && strpos( $link, '/dl/9000001/' ) !== false, $link ? $link : wp_json_encode( $q ) );
	if ( ! class_exists( 'AyeCode_Connect_Remote_Actions' ) ) { require_once WP_PLUGIN_DIR . '/ayecode-connect/includes/class-ayecode-connect-remote-actions.php'; }
	chk( 'that download passes the client allow-list', $link && AyeCode_Connect_Remote_Actions::validate_download_url( str_replace( 'http://127.0.0.1:8787', 'https://api.ayecode.io', $link ) ) );
	// the installer must hand WordPress the signed link byte for byte (esc_url turned & into &#038; and the API refused it)
	$signed = str_replace( 'http://127.0.0.1:8787', 'https://api.ayecode.io', $link );
	$seen   = null;
	$catch  = function ( $reply, $package ) use ( &$seen ) { $seen = $package; return new WP_Error( 'test_stop', 'stopped before download' ); };
	add_filter( 'upgrader_pre_download', $catch, 10, 2 );
	$c->api->execute( array( 'action' => 'install_plugin', 'payload' => array( 'name' => 'Download test', 'slug' => 'ac-download-test', 'file-slug' => 'ac-download-test', 'download_link' => $signed ) ) );
	remove_filter( 'upgrader_pre_download', $catch, 10 );
	chk( 'installer downloads the signed link unchanged', $signed && $seen === $signed, (string) $seen );
	chk( 'queued WPEU install carries min_version so a 1.x copy is replaced', isset( $env['payload']['min_version'] ) && $env['payload']['min_version'] === '2.0.0', wp_json_encode( $env['payload'] ?? null ) );
	// min_version: an installed copy older than it is replaced from the download link; a current one is left alone
	$udir = WP_PLUGIN_DIR . '/ac-upgrade-test';
	$mk = function ( $dir, $v ) { wp_mkdir_p( $dir ); file_put_contents( $dir . '/ac-upgrade-test.php', "<?php\n/*\nPlugin Name: AC upgrade test\nVersion: $v\n*/\n" ); };
	$mk( $udir, '1.0.0' );
	$zdir = get_temp_dir() . 'ac-upgrade-src-' . wp_generate_password( 6, false ); $mk( $zdir . '/ac-upgrade-test', '2.0.0' );
	$zip = $zdir . '.zip'; $za = new ZipArchive(); $za->open( $zip, ZipArchive::CREATE ); $za->addFile( $zdir . '/ac-upgrade-test/ac-upgrade-test.php', 'ac-upgrade-test/ac-upgrade-test.php' ); $za->close();
	$downloads = 0;
	$serve = function ( $reply, $package ) use ( $zip, &$downloads ) { $downloads++; $copy = wp_tempnam( 'ac-upgrade' ); copy( $zip, $copy ); return $copy; };
	add_filter( 'upgrader_pre_download', $serve, 10, 2 );
	$up = array( 'action' => 'install_plugin', 'payload' => array( 'name' => 'AC upgrade test', 'slug' => 'ac-upgrade-test', 'file-slug' => 'ac-upgrade-test', 'download_link' => 'https://api.ayecode.io/dl/1/x', 'min_version' => '2.0.0' ) );
	$c->api->execute( $up );
	wp_clean_plugins_cache(); $pl = get_plugins();
	chk( 'installer replaces a copy older than min_version', isset( $pl['ac-upgrade-test/ac-upgrade-test.php'] ) && $pl['ac-upgrade-test/ac-upgrade-test.php']['Version'] === '2.0.0' && $downloads === 1, $pl['ac-upgrade-test/ac-upgrade-test.php']['Version'] ?? 'missing' );
	$c->api->execute( $up );
	chk( 'installer leaves a current copy alone', $downloads === 1 );
	unset( $up['payload']['min_version'] ); $mk( $udir, '1.0.0' ); $c->api->execute( $up );
	chk( 'without min_version an installed copy is never replaced', $downloads === 1 );
	remove_filter( 'upgrader_pre_download', $serve, 10 );
	deactivate_plugins( 'ac-upgrade-test/ac-upgrade-test.php', true );
	$GLOBALS['wp_filesystem']->delete( $udir, true ); $GLOBALS['wp_filesystem']->delete( $zdir, true ); @unlink( $zip );
	if ( ! empty( $env['jti'] ) ) { api( 'POST', '/v1/connect/commands/' . rawurlencode( $env['jti'] ) . '/result', array( 'success' => true, 'note' => 'test: not installed' ), array( 'authorization' => 'Bearer ' . $c->api->get_site_token() ) ); }
	list( $s, $q2 ) = api( 'GET', '/v1/connect/commands', null, array( 'authorization' => 'Bearer ' . $c->api->get_site_token() ) );
	chk( 'queue empty after reporting the result', is_array( $q2 ) && empty( $q2['commands'] ) );
}

// ---- 5. command pull: FreeScout queues update_licences, the site pulls, verifies and applies it ----
delete_option( 'exup_keys' );
$installed = array( 'geodir_advance_search_filters/geodir_advance_search_filters.php' => array( 'license_key' => $KEY, 'status' => 'active', 'download_id' => 65056, 'expiration' => 0, 'parent' => 0, 'user_id' => 3036 ) );
list( $s, $cmd ) = api( 'POST', '/v1/commands', array( 'site_id' => $c->get_blog_id(), 'action' => 'update_licences', 'payload' => array( 'site_id' => $c->get_blog_id(), 'site_url' => site_url(), 'installed' => $installed, 'all' => array() ) ), array( 'authorization' => 'Bearer ' . $FS ) );
chk( 'command queued for pull', $s === 200 && $cmd['status'] === 'queued', $cmd['mode'] ?? '' );
$ran = $c->api->pull_commands();
chk( 'pull executed one command', $ran === 1 );
$keys = get_option( 'exup_keys', array() );
chk( 'pulled update_licences stored the key', ! empty( $keys['geodir_advance_search_filters/geodir_advance_search_filters.php']->key ) );
chk( 'nothing left to pull', $c->api->pull_commands() === 0 );
$log = get_option( $prefix . '_auth_paths', array() );
chk( 'auth log records envelope path', ! empty( $log['envelope'] ) );

// ---- 6. permission callback: envelope path and legacy path ----
list( $s, $pulled ) = api( 'POST', '/v1/commands', array( 'site_id' => $c->get_blog_id(), 'action' => 'test_connection', 'payload' => array() ), array( 'authorization' => 'Bearer ' . $FS ) );
$token = $c->api->get_site_token();
list( $s, $q ) = api( 'GET', '/v1/connect/commands', null, array( 'authorization' => 'Bearer ' . $token ) );
$env = $q['commands'][0];
$req = new WP_REST_Request( 'POST', '/ayecode-connect/v1/do_action' ); $req->set_param( 'envelope', $env['envelope'] ); $req->set_param( 'signature', $env['signature'] );
chk( 'has_permission accepts a signed envelope via REST', $c->has_permission( $req ) === true );
chk( 'has_permission rejects the same envelope replayed', is_wp_error( $c->has_permission( $req ) ) );
// legacy: HS256 JWT with a blog token, from a spoofed vs a real sender IP
update_option( $prefix . '_blog_token', 'legacy-test-token' );
$jwt = AyeCode_Connect::encode( array( 'blog_id' => $c->get_blog_id() ), 'legacy-test-token' );
$req2 = new WP_REST_Request( 'POST', '/ayecode-connect/v1/do_action' ); $req2->set_header( 'Authorization', 'X_AUTH ' . $jwt );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '173.208.153.114'; $_SERVER['HTTP_CLIENT_IP'] = '173.208.153.114';
chk( 'legacy JWT with spoofed X-Forwarded-For is rejected', is_wp_error( $c->has_permission( $req2 ) ) );
$_SERVER['REMOTE_ADDR'] = '173.208.153.114';
chk( 'legacy JWT from the old server IP is accepted', $c->has_permission( $req2 ) === true );
$req3 = new WP_REST_Request( 'POST', '/ayecode-connect/v1/do_action' ); $req3->set_header( 'Authorization', 'X_AUTH ' . AyeCode_Connect::encode( array( 'blog_id' => $c->get_blog_id() ), 'wrong-token' ) );
chk( 'legacy JWT with wrong secret is rejected even from the old IP', is_wp_error( $c->has_permission( $req3 ) ) );
delete_option( $prefix . '_blog_token' );

// ---- 7. download allow-list used by install_plugin ----
if ( ! class_exists( 'AyeCode_Connect_Remote_Actions' ) ) { require_once WP_PLUGIN_DIR . '/ayecode-connect/includes/class-ayecode-connect-remote-actions.php'; }
chk( 'install allow-list accepts the API host', AyeCode_Connect_Remote_Actions::validate_download_url( str_replace( 'http://127.0.0.1:8787', 'https://api.ayecode.io', $asf->package ) ) );

// ---- 7b. WP-CLI status (support tool): runs, shows the connection, never prints the token ----
{
	if ( ! class_exists( 'AyeCode_Connect_CLI' ) ) { require_once WP_PLUGIN_DIR . '/ayecode-connect/includes/class-ayecode-connect-cli.php'; }
	$cli = new AyeCode_Connect_CLI( $c ); $rows = $cli->status_rows();
	chk( 'cli status reports the connected site', $rows['connected'] === 'yes' && $rows['site_id'] === $c->get_blog_id() && $rows['site_token'] === 'present' && $rows['api'] === 'api.ayecode.io', wp_json_encode( array( $rows['connected'], $rows['site_id'], $rows['api'] ) ) );
	chk( 'cli status counts signed commands and records the last one', $rows['commands_signed'] >= 1 && strpos( $rows['last_command'], 'update_licences via pull' ) === 0, $rows['last_command'] );
	chk( 'cli status never contains the site token', strpos( wp_json_encode( $rows ), $c->api->get_site_token() ) === false );
	$out = WP_CLI::runcommand( 'ayecode-connect status --format=json', array( 'return' => true, 'launch' => false, 'exit_error' => false ) );
	$j = json_decode( $out, true );
	chk( 'wp ayecode-connect status runs as a command', is_array( $j ) && isset( $j['site_id'] ), is_array( $j ) ? '' : substr( (string) $out, 0, 120 ) );
}

// ---- 7c. routing: demo content, plugin lists and the connection test stay on ayecode.io; the rest go to the API ----
{
	$legacy_base = trailingslashit( $c->api_url . $c->api_namespace );
	chk( 'demos list stays on ayecode.io', $c->get_api_url( '/demos' ) === $legacy_base . 'demos', $c->get_api_url( '/demos' ) );
	chk( 'demo content request stays on ayecode.io', 0 === strpos( $c->get_api_url( '/request_demo_content/dir/theme' ), $legacy_base ) );
	chk( 'test-connection stays on ayecode.io', 0 === strpos( $c->get_api_url( '/test-connection' ), $legacy_base ) );
	chk( 'me goes to the API without the site id', $c->get_api_url( '/me/5' ) === $c->api_base . '/v1/connect/me', $c->get_api_url( '/me/5' ) );
	chk( 'fallback rebuilds the legacy route with the site id', $c->legacy_url_for( $c->api_base . '/v1/connect/me', 7 ) === $legacy_base . 'me/7' && $c->legacy_url_for( $c->api_base . '/v1/connect/site', 7 ) === $legacy_base . 'sites/7' );
	chk( 'fallback keeps the query string', $c->legacy_url_for( $c->api_base . '/v1/connect/licenses?a=1&b=2', 7 ) === $legacy_base . 'licenses?a=1&b=2' );
	$c->api->mark_api_down();
	chk( 'a site with no legacy token never falls back to ayecode.io', ! $c->use_legacy_api() );
	delete_option( $prefix . AyeCode_Connect_API::OPTION_LEGACY_UNTIL );
}

// ---- 7d. first admin load after connecting: WP Easy Updates active and licences synced, as 1.4 had it ----
{
	$wpeu = 'wp-easy-updates/external-updates.php';
	deactivate_plugins( $wpeu, true );
	update_option( $prefix . '_licence_sync', false );
	update_option( $prefix . '_post_connect_setup', 1, false );
	delete_transient( $prefix . '_post_connect_running' );
	$c->maybe_finish_connection_setup();
	chk( 'post-connect setup activates WP Easy Updates', is_plugin_active( $wpeu ) );
	chk( 'post-connect setup turns licence sync on and clears its flag', get_option( $prefix . '_licence_sync' ) && ! get_option( $prefix . '_post_connect_setup' ) );
	$c->maybe_finish_connection_setup();
	chk( 'post-connect setup runs once', ! get_option( $prefix . '_post_connect_setup' ) );
	chk( 'install_updates_plugin is true when already active', $c->install_updates_plugin() === true );
}

// ---- 7e. the API refuses the site token ----
{
	$tok = $c->api->get_site_token();
	update_option( $prefix . '_blog_token', 'legacy-test-token' );
	$c->api->handle_unauthorized();
	chk( 'refused token with a legacy token: token dropped, site stays connected for a re-exchange', ! $c->api->get_site_token() && $c->get_blog_id() && false === get_transient( $prefix . AyeCode_Connect_API::TRANSIENT_EXCHANGE ) );
	update_option( $prefix . AyeCode_Connect_API::OPTION_SITE_TOKEN, $tok );
	delete_option( $prefix . '_blog_token' );
}

// ---- 8. disconnect ----
$c->disconnect_site( true );
chk( 'disconnect clears token and keys', ! $c->api->get_site_token() && ! get_option( 'exup_keys' ) && ! $c->is_registered() );
WP_CLI::line( "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed" ); if ( $GLOBALS['fail'] ) { WP_CLI::halt( 1 ); }
