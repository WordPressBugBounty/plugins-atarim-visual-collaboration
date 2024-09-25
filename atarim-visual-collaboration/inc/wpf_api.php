<?php
/*
 * wpf_api.php
 * This file contains all the code related to the APIs. APIs are used to communicate the data between the plugin and the dashboard app.
 */

 /*
 * This function is called by the APP to get the website information when website is synced.
 * URL: DOMAIN/wp-admin/admin-ajax.php?action=wpf_website_details
 *
 * @input NULL
 * @return JSON
 */
function wpf_website_details() {
    $valid = wpf_api_request_verification();
    if ( $valid == 1 ) {
        update_option( 'wpf_initial_sync', 1, 'no' );
        $response_array                    = array();
        $response_array['name']            = get_option( 'blogname' );
        $response_array['url']             = WPF_HOME_URL;
	    $wpf_license_key_enc               = get_option( 'wpf_license_key' );
        $wpf_license_key                   = wpf_crypt_key( $wpf_license_key_enc, 'd' );
        $response_array['wpf_license_key'] = $wpf_license_key;
        $settings                          = [];
        $val                               = get_option( "wpfeedback_color" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpfeedback_color', 'value' => $val] );
        }
        $val = get_option( "wpf_selcted_role" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_selcted_role', 'value' => $val] );
        }
        $val = get_option( "wpf_website_developer" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_website_developer', 'value' => $val] );
        }
        $val = get_option( "wpf_show_front_stikers" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_show_front_stikers', 'value' => $val] );
        }
        $val = get_option( "wpf_customisations_client" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_customisations_client', 'value' => $val] );
        }
        $val = get_option( "wpf_customisations_webmaster" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_customisations_webmaster', 'value' => $val] );
        }
        $val = get_option( "wpf_customisations_others" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_customisations_others', 'value' => $val] );
        }
        $val = get_option( "wpf_from_email" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_from_email', 'value' => $val] );
        }
        $val = get_option( "wpf_allow_guest" );
        if( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_allow_guest', 'value' =>$val] );
        }
        $val = get_option( "wpf_disable_for_admin" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_disable_for_admin', 'value' => $val] );
        }
        $val = get_option( "wpf_website_client" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_website_client', 'value' => $val] );
        }
        $val = get_option( "wpf_license" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_license', 'value' => $val] );
        }
        $val = get_option( "wpf_license_expires" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_license_expires', 'value' => $val] );
        }
        $val = get_option( "wpf_decr_key" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_decr_key', 'value' => $val] );
        }
        $val = get_option( "wpf_decr_checksum" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpf_decr_checksum', 'value' => $val] );
        }
        $val = get_option( "enabled_wpfeedback" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'enabled_wpfeedback', 'value' => $val] );
        }
        $val = get_option( "wpfeedback_font_awesome_script" );
        if ( $val != FALSE ) {
            array_push( $settings, ['name' => 'wpfeedback_font_awesome_script', 'value' => $val] );
        }
        $val = get_option( "wpf_allow_backend_commenting" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_allow_backend_commenting', 'value' => $val] );
        }
        $val = get_option( "wpf_more_emails" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_more_emails', 'value' => $val] );
        }
        $val = get_option( "wpfeedback_powered_by" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpfeedback_powered_by', 'value' => $val] );
        }
        $val = get_option( "wpf_every_new_task" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_every_new_task', 'value' => $val] );
        }
        $val = get_option( "wpf_every_new_comment" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_every_new_comment', 'value' => $val] );
        }
        $val = get_option( "wpf_every_new_complete" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_every_new_complete', 'value' => $val] );
        }
        $val = get_option( "wpf_every_status_change" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_every_status_change', 'value' => $val] );
        }
        $val = get_option( "wpf_daily_report" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_daily_report', 'value' => $val] );
        }
        $val = get_option( "wpf_weekly_report" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_weekly_report', 'value' => $val] );
        }
        $val = get_option( "wpf_auto_daily_report" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_auto_daily_report', 'value' => $val] );
        }
        $val = get_option( "wpf_auto_weekly_report" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_auto_weekly_report', 'value' => $val] );
        }
        $val = get_option( "wpf_initial_setup" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpf_initial_setup', 'value' => $val] );
        }
        $val = get_option( "wpf_logo" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpfeedback_logo', 'value' => $val] );
        }
        $val = get_option( "wpf_favicon" );
        if ( $val != FALSE ){
            array_push( $settings, ['name' => 'wpfeedback_favicon', 'value' => $val] );
        }

        $response_array['settings'] = $settings;
        $response                   = wp_json_encode( $response_array );
        $response_signature         = hash_hmac( 'sha256', $response, $wpf_license_key );
        header( "response-signature: " . $response_signature );
    } else {
        $response = 'invalid request';
    }
    echo $response;
    exit;
}
add_action( 'wp_ajax_wpf_website_details', 'wpf_website_details' );
add_action( 'wp_ajax_nopriv_wpf_website_details', 'wpf_website_details' );

/*
 * This function is called by the APP to get the users of the website.
 * URL: DOMAIN/wp-admin/admin-ajax.php?action=wpf_website_users
 *
 * @input NULL
 * @return JSON
 */
function wpf_website_users() {
    $valid = wpf_api_request_verification();
    if ( $valid == 1 ) {
        $response = wpf_api_func_get_users();
        // fixed sync of users when user click the "sync" button on the app
        get_notif_sitedata_filterdata();
        $response_signature = wpf_generate_response_signature( $response );
        header( "response-signature: " . $response_signature );
    } else {
        $response = 'invalid request';
    }
    echo $response;
    exit;
}
add_action( 'wp_ajax_wpf_website_users', 'wpf_website_users' );
add_action( 'wp_ajax_nopriv_wpf_website_users', 'wpf_website_users' );

/*
 * This function is called from APP when website is requested to resync. This function is also called from the website when the button "Resync the Central Dashboard" button is clicked.
 * URL: DOMAIN/wp-admin/admin-ajax.php?action=wpf_website_resync
 *
 * @input NULL
 * @return JSON || invalid request
 */
function wpf_website_resync() {
    $valid = wpf_api_request_verification();
    if ( $valid == 1 ) {
        $wpf_license_key_enc = get_option( 'wpf_license_key' );
        $wpf_license_key     = wpf_crypt_key( $wpf_license_key_enc, 'd' );
        $response            = wpf_initial_sync( $wpf_license_key );
        $response_signature  = wpf_generate_response_signature( $response );
        header( "response-signature: " . $response_signature );
    } else {
        $response = 'invalid request';
    }
    echo $response;
    exit;
}
add_action( 'wp_ajax_wpf_website_resync', 'wpf_website_resync' );
add_action( 'wp_ajax_nopriv_wpf_website_resync', 'wpf_website_resync' );

/*
 * Support functions start here
 */

/*
 * This function is called by all the functions for the verification of authentication.
 *
 * @input Array ( $_SERVER )
 * @return Boolean
 */
function wpf_api_request_verification() {
    $response            = 0;
    $request_reference   = $_SERVER['HTTP_REQUEST_REFERENCE'];
    $request_signature   = $_SERVER['HTTP_REQUEST_SIGNATURE'];
    $wpf_site_id         = get_option( 'wpf_site_id' );
    if ( $request_signature == hash_hmac( 'sha256', $request_reference, $wpf_site_id ) ) {
        $response = 1;
    }
    return $response;
}

/*
 * This function is called by function to get all the users of website.
 *
 * @input NULL
 * @return JSON
 */
function wpf_api_func_get_users() {
    $response              = array();
    $wpf_website_developer = get_option( 'wpf_website_developer' );
    $selected_roles        = get_site_data_by_key( 'wpf_selcted_role' );
    $selected_roles        = explode( ',', $selected_roles );
    $wpfb_users            = get_users( array( 'role__in' => $selected_roles ) );
    // Get invited user using Share version 2 by Pratap.
    $args = array(
        'meta_key' => 'avc_user_token',
        'meta_value' => '',
        'meta_compare' => '!='
    );
    $user_query = new WP_User_Query( $args );
    $guest_user_list = $user_query->get_results();
    if ( ! empty( $guest_user_list ) ) {
        foreach ( $guest_user_list as $user ) {
            $wpfb_users[] = $user;
        }
    }
    $wpf_temp_count        = 0;
    foreach ( $wpfb_users as $user ) {
        if ( $user->ID == $wpf_website_developer ) {
            $response[$wpf_temp_count]['is_admin'] = 1;
        } else {
            $response[$wpf_temp_count]['is_admin'] = 0;
        }
        $response[$wpf_temp_count]['wpf_id']            = $user->ID;
        $response[$wpf_temp_count]['wpf_display_name']  = htmlspecialchars( $user->display_name, ENT_QUOTES, 'UTF-8' );
        $response[$wpf_temp_count]['wpf_name']          = htmlspecialchars( $user->display_name, ENT_QUOTES, 'UTF-8' );
        $response[$wpf_temp_count]['wpf_email']         = $user->user_email;
        $response[$wpf_temp_count]['first_name']        = get_user_meta( $user->ID, 'first_name', true );
        $response[$wpf_temp_count]['last_name']         = get_user_meta( $user->ID, 'last_name', true );
        $response[$wpf_temp_count]['role']              = implode( ',', $user->roles );
        $wpf_temp_count++;
    }
    return wp_json_encode( $response );
}

/*
 * Functions to update CRM
 */


/*
 * This function is used to register a new API route for the auto login feature.
 *
 * @input NULL
 * @return NULL
 */
function wpf_autologin_api_hook() {
    register_rest_route(
        'wpf_api', '/wpf_autologin/',
        array(
            'methods'  => 'GET',
            'callback' => 'wpf_autologin',
            'permission_callback' => '__return_true'
        )
    );
}
add_action( 'rest_api_init', 'wpf_autologin_api_hook' );

/*
 * This function is used to auto login the user to website based on the token.
 *
 * @input NULL
 * @return NULL
 */
add_action( 'init', 'wpf_autologin_init_hook' );
function wpf_autologin_init_hook() {
    $isautologin = get_site_data_by_key( 'wpf_enable_autologin' );
    if ( isset( $_GET['wpf_token'] ) ) {
        if ( $isautologin != 'no' ) {
            $wpf_token_request = array();
            $wpf_token_request = array_merge( $_GET, $_SERVER );
            wpf_autologin( $wpf_token_request );
        } else {
            wp_safe_redirect( WPF_HOME_URL );
            exit();
        }
    }
}

/*
 * This function is called by wpf_autologin_init_hook to auto login the user to website.
 *
 * @input Array
 * @return NULL
 */
function wpf_autologin( $request ) {
	global $wpdb;
    $url                     = WPF_CRM_API . 'wp-api/user/verify-access';
    $response                = array();
    $response['wpf_site_id'] = get_option( 'wpf_site_id' );
    $response                = wp_json_encode( $response );
    $res                     = wpf_send_remote_post( $url, $response, $request['wpf_token'] );
    $removeparam             = array( 'wpf_token', 'wpf_username', 'wpf_login');
    if ( $res['status'] == 1 ) {
        if ( ! is_user_logged_in() ) {
            $user = get_user_by( 'email', $request['wpf_username'] );
            if ( ! is_wp_error( $user ) ) {
                wp_clear_auth_cookie();
                wp_set_current_user ( $user->ID );
                wp_set_auth_cookie  ( $user->ID );
                setcookie('_wordpress_test_cookie', 'wpf_test', 900, '/' );
            }
        }
    }
    remove_url_parameter_and_redirect( $removeparam );
}

function remove_url_parameter_and_redirect( $removeparam ) {
    // Get the current URL
    $currentUrl = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    // Parse the URL to get its components
    $urlParts = parse_url( $currentUrl );

    $newUrl = '';

    // If there's no query string, no need to do anything
    if ( ! empty( $urlParts['query'] ) ) {

        // Parse the query string to get an associative array of the parameters
        parse_str( $urlParts['query'], $queryParams );

        // Remove the parameter from the query array
        if( ! empty( $removeparam ) ) {
            foreach( $removeparam as $param ) {
                unset( $queryParams[$param] );
            }
        }

        // Rebuild the query string without the removed parameter
        $newQueryString = http_build_query( $queryParams );

        // Rebuild the full URL without the removed parameter
        $newUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'];
        if ( ! empty( $newQueryString ) ) {
            $newUrl .= '?' . $newQueryString;
        }
    } else {
        $newUrl = $currentUrl;
    }

    // Redirect to the new URL
    wp_safe_redirect($newUrl, 301);
    exit;
}

/*
 * General Functions
 */

/*
 * This function is called from APP when website is requested to resync. This function is also called from the website when the button "Resync the Central Dashboard" button is clicked.
 * URL: DOMAIN/wp-admin/admin-ajax.php?action=wpf_initial_sync
 *
 * @input String
 * @return Boolean
 */
function wpf_initial_sync( $wpf_license_key ) { 
    $url                     = WPF_CRM_API . 'sitedata/sync';
    $response                = array();
    $wpf_site_id             = get_option( 'wpf_site_id' );
    $response['license_key'] = $wpf_license_key;
    $response['wpf_site_id'] = $wpf_site_id;
    $response['url']         = WPF_HOME_URL;
    $response['name']        = get_option( 'blogname' );
    $response['from_plugin'] = 1;
    $response['users']       = json_decode( wpf_api_func_get_users() );
    $settings[]              = ['name' => 'wpf_website_developer', 'value' => get_option( "wpf_website_developer" )];
    $response['settings']    = $settings;
    $body                    = wp_json_encode( $response );  
    $res                     = wpf_send_remote_post( $url, $body );
    
    if ( isset( $res['status'] ) && $res['status'] == 1 ) {
	    update_default_site_data();
        $res=0;
    } else {
        $res = 1;
    }
    return $res;
}
add_action( 'wpf_initial_sync', 'wpf_initial_sync', 1 );

/*
 * This function is used to generate the response signature based on the input.
 *
 * @input String
 * @return String
 */
function wpf_generate_response_signature( $response ) {
    $wpf_license_key_enc = get_option( 'wpf_license_key' );
    $wpf_license_key     = wpf_crypt_key( $wpf_license_key_enc, 'd' );    
    $response_signature  = hash_hmac( 'sha256', $response, $wpf_license_key );
    return $response_signature;
}

/*
 * This function is used to communicate between the website and the APP.
 *
 * @input String, String, String
 * @return JSON
 */
function wpf_send_remote_post( $url, $response, $wpf_token = '', $is_print = false ) {
    if ( get_option( 'atarim_server_down' ) == 'true' && ( get_option( 'atarim_server_check_count' ) == '2' ) ) {
        return;
    }
    $response_signature = wpf_generate_response_signature( $response );
    if ( $wpf_token == '' ) {
        $header = array( 'Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json', 'response-signature' => $response_signature );
    } else {
        $header = array( 'Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json', 'response-signature' => $response_signature, 'Authorization' => 'Bearer ' . $wpf_token );
    }

    $args = array(
        'headers'     => $header,
        'body'        => $response,
        'method'      => 'POST',
        'data_format' => 'body',
        'timeout'     => 100,
    );

    // bypass SSL error
    add_filter( 'https_ssl_verify', '__return_false' );

    $response = wp_remote_post( $url, $args );
    if( $is_print ) {
        return $response;
    }
    //echo '<pre>';
    //print_r($response);
    /*$ip = "49.43.32.130";
	if (filter_var($ip, FILTER_VALIDATE_IP)) {
		echo '<pre>';
		print_r($response);
	}*/

    if ( ! is_wp_error( $response ) ) {
        if ( $response['response']['code'] == 504 ) {
            return wp_json_encode( $response['response'], true );
        } elseif ( $response['response']['code'] == 403 ) {
           update_option( 'wpf_license', 'invalid' );
        }
    }

    if ( is_wp_error( $response ) ) {
        $unix_time_now  = time();
        $unix_time_now += 1800;
        update_option( 'atarim_server_down', 'true', 'no' );
        $atarim_server_check_count = get_option( 'atarim_server_check_count' );
        if ( $atarim_server_check_count == '1' ) {
            update_option( 'atarim_server_check_count', '2', 'no' );
        } else {
            update_option( 'atarim_server_check_count', '1', 'no' );
        }

        update_option( 'atarim_server_down_check', $unix_time_now, 'no' );
        $error_message = $response->get_error_message();
        echo "Something went wrong: $error_message";
    } else {
        $real_response = json_decode( wp_remote_retrieve_body( $response ), true );        
        update_option( 'atarim_server_check_count', '0', 'no' );
        if ( ! empty( $real_response['wpf_license'] ) ) {
            //update_option( 'wpf_license', base64_decode( $real_response['wpf_license'] ) );
        }
        return $real_response;
    }
}

/**
 * Grab data from database and send it to be used inside Visual Composer plugin.
 *
 * @param array $data returns the values.
 */
function wpf_visual_composer_api() {
    $wpfb_users    = do_shortcode( '[wpf_user_list_front]' );
    $wpf_license   = get_option( 'wpf_license' );
    $wpf_user_plan = get_option( 'wpf_user_plan', false );
    if ( $wpf_user_plan ) {
        $wpf_user_plan = unserialize( $wpf_user_plan );
    }
    $data = array(
        'wpf_site_id'   => get_option( 'wpf_site_id' ),
        'wpf_license'   => $wpf_license,
        'url'           => WPF_HOME_URL,
        'task_types'    => ['general', 'email', 'page'],
        'sort'          => ['task_title', 'created_at'],
        'sort_by'       => 'asc',
        'notify_user'   => $wpfb_users,
        'wpf_user_plan' => $wpf_user_plan
    );
    return $data;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'atarim/v1', '/db/vc', array(
        'methods' => 'GET',
        'callback' => 'wpf_visual_composer_api',
        'permission_callback' => '__return_true'
    ) );
} );

// Add a custom REST API endpoint for Chrome to check if plugin is installed or not.
function chrome_ext_flag_endpoint() {
    register_rest_route( 'atarim/v1', '/is-installed', array(
        'methods' => 'GET',
        'callback' => 'check_if_plugin_installed',
    ) );
}
add_action( 'rest_api_init', 'chrome_ext_flag_endpoint' );

function check_if_plugin_installed() {
    return array( 'installed' => true );
}