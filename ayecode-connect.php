<?php

/**
 * Plugin Name: AyeCode Connect
 * Plugin URI: https://ayecode.io/
 * Description: A service plugin letting users connect AyeCode Services to their site.
 * Version: 1.1.7
 * Author: AyeCode
 * Author URI: https://ayecode.io
 * Requires at least: 4.7
 * Tested up to: 5.5
 *
 * Text Domain: ayecode-connect
 * Domain Path: /languages/
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !defined( 'AYECODE_CONNECT_VERSION' ) ) {
    define( 'AYECODE_CONNECT_VERSION', '1.1.7' );
}

add_action( 'plugins_loaded', 'ayecode_connect' );

/**
 * Sets up the client
 */
function ayecode_connect() {

    //Include the client connection class
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-ayecode-connect.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-ayecode-connect-settings.php';

    //Prepare client args
    $args   = ayecode_connect_args();

    $client = new AyeCode_Connect( $args );

    //Call the init method to register routes. This should be called exactly once per client (Preferably before the init hook).
    $client->init();

    // Load textdomain
    load_plugin_textdomain( 'ayecode-connect', false, basename( dirname( __FILE__ ) ) . '/languages/' );
}

/**
 * The AyeCode Connect arguments.
 *
 * @return array
 */
function ayecode_connect_args(){
    $base_url = 'https://ayecode.io';
    return array(
        'remote_url'            => $base_url, //URL to the WP site containing the WP_Service_Provider class
        'connection_url'        => $base_url.'/connect', //This should be a custom page the authinticates a user the calls the WP_Service_Provider::connect_site() method
        'api_url'               => $base_url.'/wp-json/', //Might be different for you
        'api_namespace'         => 'ayecode/v1',
        'local_api_namespace'   => 'ayecode-connect/v1', //Should be unique for each client implementation
        'prefix'                => 'ayecode_connect', //A unique prefix for things (accepts alphanumerics and underscores). Each client on a given site should have it's own unique prefix
        'textdomain'            => 'ayecode-connect',
    );
}

/**
 * Add settings link to plugins page.
 * 
 * @param $links
 *
 * @return mixed
 */
function ayecode_connect_settings_link( $links ) {
    $settings_link = '<a href="index.php?page=ayecode-connect">' . __( 'Settings','ayecode-connect' ) . '</a>';
    array_push( $links, $settings_link );
    return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_{$plugin}", 'ayecode_connect_settings_link' );

/**
 * Remove wp cron on deactivation if set.
 */
register_deactivation_hook( __FILE__, 'ayecode_connect_deactivation' );
function ayecode_connect_deactivation() {
    $args = ayecode_connect_args();
    $prefix = $args['prefix'];
    wp_clear_scheduled_hook( $prefix.'_callback' );

    // destroy support user
    $support_user = get_user_by( 'login', 'ayecode_connect_support_user' );
    if ( ! empty( $support_user ) && isset( $support_user->ID ) && ! empty( $support_user->ID ) ) {
        require_once(ABSPATH.'wp-admin/includes/user.php');
        $user_id = absint($support_user->ID);
        // get all sessions for user with ID $user_id
        $sessions = WP_Session_Tokens::get_instance($user_id);
        // we have got the sessions, destroy them all!
        $sessions->destroy_all();
        $reassign = user_can( 1, 'manage_options' ) ? 1 : null;
        wp_delete_user( $user_id, $reassign );
        if ( is_multisite() ) {
            require_once( ABSPATH . 'wp-admin/includes/ms.php' );
            revoke_super_admin( $user_id );
            wpmu_delete_user( $user_id );
        }
    }

    // Try to remove the must use plugin. This should fail silently even if file is missing.
    wp_delete_file( WPMU_PLUGIN_DIR."/ayecode-connect-filter-fix.php" );
}