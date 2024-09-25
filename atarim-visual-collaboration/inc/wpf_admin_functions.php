<?php
/*
 * This function is to handle the request and response to the main set global settings in wp_api.
 *
 * @input NULL
 * @return Int
 */
if ( ! function_exists( 'wpf_global_settings' ) ) {
    function wpf_global_settings() {
        wpf_security_check();
        if ( $_POST['wpf_global_settings'] == 'yes' ) {
            $url                        = WPF_CRM_API . 'update-global-settings';
            $wpf_license_key            = get_option( 'wpf_license_key' );
            $wpf_license_key            = wpf_crypt_key( $wpf_license_key, 'd' );
            $sendarr                    = array();
            $sendarr["wpf_site_id"]     = get_option( 'wpf_site_id' );
            $sendarr["wpf_license_key"] = $wpf_license_key;
            $sendtocloud                = wp_json_encode( $sendarr );
            $response                   = wpf_send_remote_post( $url, $sendtocloud );
            echo ( isset( $response['status'] ) == 200 ) ? 1 : 3;
            update_option( 'wpf_global_settings', 'yes' );
            get_notif_sitedata_filterdata();
        } else if ( $_POST['wpf_global_settings'] == 'no' ) {
            $parms1 = [];
            array_push( $parms1, ['name' => 'wpf_global_settings', 'value' => 'no'] );
            update_site_data( $parms1 );
            echo 2;
        } else {
            get_notif_sitedata_filterdata();
            echo 0;
        }
        exit;
    }
}
add_action( 'wp_ajax_wpf_global_settings', 'wpf_global_settings' );

/*
 * This function is to sync the global settings once a day.
 *
 * @input NULL
 * @return Int
 */
if ( ! function_exists( 'sync_global_settings' ) ) {
    function sync_global_settings() {
        $wpf_active = wpf_check_if_enable();
        if ( $wpf_active == 1 ) {
            $unix_time_now       = time();
            $unix_time_last_sync = get_option( 'wpf_global_settings_resync_time' );
            if ( $unix_time_now > $unix_time_last_sync ) {
                $global_settings = get_option( "wpf_global_settings" );
                if ( $global_settings == 'yes' ) {
                    $url                        = WPF_CRM_API . 'update-global-settings';
                    $wpf_license_key            = get_option( 'wpf_license_key' );
                    $wpf_license_key            = wpf_crypt_key( $wpf_license_key, 'd' );
                    $sendarr                    = array();
                    $sendarr["wpf_site_id"]     = get_option( 'wpf_site_id' );
                    $sendarr["wpf_license_key"] = $wpf_license_key;
                    $sendtocloud                = wp_json_encode( $sendarr );
                    $response                   = wpf_send_remote_post( $url, $sendtocloud );
                    if ( isset( $response['status'] ) == 200 ) {
                        get_notif_sitedata_filterdata();
                    } else {
                        get_notif_sitedata_filterdata();
                    }
                } elseif ( $global_settings == 'no' ) {
                    $parms1 = [];
                    array_push( $parms1, ['name' => 'wpf_global_settings', 'value' => 'no'] );
                    update_site_data( $parms1 );
                } else {
                    get_notif_sitedata_filterdata();
                }
                $unix_time  = time();
                $unix_time += 86400;
                update_option( "wpf_global_settings_resync_time", $unix_time, 'no' );
            }
        }
    }
}
add_action( 'init', 'sync_global_settings' );