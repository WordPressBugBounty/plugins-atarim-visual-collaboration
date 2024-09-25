<?php
/*
 * wpf_email_notifications.php
 * This file contains all the code related to sending the email notifications to the users for task related activities like Creating a new task, creating a new comment on the task, change the status of the task, mark task as complete, daily reports and weekly reports.
 */

/*
 * This function is used to send the daily and weekly reports to the users from Backend Tasks Center, Frontend Sidebar and Graphics Sidebar.
 *
 * @input NULL
 * @return NULL
 */
function wpf_send_email_report() {
    if ( $_SERVER['REMOTE_ADDR'] != '35.246.48.203' ) {
        wpf_security_check();
    }
    $type                = $_REQUEST['type'];
    $site_id             = get_option( "wpf_site_id" );
    $response            = [];
    $args                = [];
    $args['wpf_site_id'] = $site_id;
    $sendtocloud         = wp_json_encode( $args );
    if ( $type == 'daily_report' ) {
        $url      = WPF_CRM_API . 'wp-api/task/daily-reports';
        $response = wpf_send_remote_post( $url, $sendtocloud );
    } else {
        $url      = WPF_CRM_API . 'wp-api/task/weekly-reports';
        $response = wpf_send_remote_post( $url, $sendtocloud );
    }
    exit();
}
add_action( 'wp_ajax_wpf_send_email_report', 'wpf_send_email_report' );
add_action( 'wp_ajax_nopriv_wpf_send_email_report', 'wpf_send_email_report' );

/*
 * This function is used to send the daily and weekly reports to the users from Auto reports cron running on wpfeedback.co.
 *
 * @input String, String
 * @return NULL
 */
function wpf_send_email_report_cron( $type, $forced ) {
    $site_id             = get_option( "wpf_site_id" );
    $response            = [];
    $args                = [];
    $args['wpf_site_id'] = $site_id;
    $sendtocloud         = wp_json_encode( $args );
    if ( $type == 'daily_report' ) {
        $url      = WPF_CRM_API . 'wp-api/task/daily-reports';
        $response = wpf_send_remote_post( $url, $sendtocloud );
    } else {
        $url      = WPF_CRM_API . 'wp-api/task/weekly-reports';
        $response = wpf_send_remote_post( $url, $sendtocloud );
    }
    exit();
}

/*
 * This function is used to send the auto reports from the cron on wpfeedback.co.
 *
 * @input Array
 * @return JSON
 */
function wpf_auto_send_email_report( $request ) {
    $send_report     = false;
    $wpf_license     = $request['wpf_license'];
    $wpf_license_key = trim( get_option( 'wpf_license_key' ) );
    $wpf_decry_key   = wpf_crypt_key( $wpf_license_key, 'd' );
    if ( $wpf_license == $wpf_license_key || $wpf_license == $wpf_decry_key ) {
        $send_report = true;
    }
    if ( $send_report == true ) {
        $type                = array();
        $type['report_type'] = $request["report_type"];
        if ( $request["report_type"] == 'daily_report' || $request['report_type'] == 'weekly_report' ) {
            wpf_send_email_report_cron( $type['report_type'], 'no' );
            echo wp_json_encode( $type );
        }
    }
    exit;
}

/*
 * This function is used to register the API wpf-send-email-report for sending the auto reports.
 *
 * @input NULL
 * @return NULL
 */
// Remove later.
function wpf_send_email_report_register_api_hooks() {
    register_rest_route(
    'wpf-send-email-report',
    '/wpf-send-email-report/',
        array(
            'methods'             => 'GET',
            'callback'            => 'wpf_auto_send_email_report',
            'permission_callback' => '__return_true',
        )
    );
}
add_action( 'rest_api_init', 'wpf_send_email_report_register_api_hooks' );