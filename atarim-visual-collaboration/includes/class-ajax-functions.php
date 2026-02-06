<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
function avc_deactivate_collab() {
    $function = new AVC_Functions();
    if (! $function->avc_validate_nonce() || ! is_user_logged_in()) {
        wp_send_json_error(['message' => 'Unauthorized access.'], 403);
        exit;
    }

    $function->avc_update_settings('avc_collab_active', 'no');
    exit;
}
add_action('wp_ajax_avc_deactivate_collab', 'avc_deactivate_collab');
add_action('wp_ajax_nopriv_avc_deactivate_collab', 'avc_deactivate_collab');

function avc_user_consent() {
    $function = new AVC_Functions();
    if (! $function->avc_validate_nonce() || ! is_user_logged_in()) {
        wp_send_json_error(['message' => 'Unauthorized access.'], 403);
        exit;
    }

    $site_id = $function->avc_get_setting_data('avc_site_id');
    $email = $function->avc_get_user_detail('email');
    $fname = $function->avc_get_user_detail('first_name');
    $lname = $function->avc_get_user_detail('last_name');

    if (is_wp_error($email)) {
        wp_send_json_error(['message' => $email->get_error_message()], 400);
        exit;
    }

    $payload =  [
        'site_id' => $site_id, 
        'email' => $email,
        'name' => $fname . ' ' . $lname,
        'source' => 'wordpress',
        'apikey' => 'ab497511-9293-4e36-8e8b-fe3fdf0c4086',
        'apiurl' => AVC_CRM_API . 'wp-api/user/auth',
    ];

    wp_send_json_success($payload);
    exit;
}
add_action('wp_ajax_avc_user_consent', 'avc_user_consent');
add_action('wp_ajax_nopriv_avc_user_consent', 'avc_user_consent');

function avc_set_user_consent_status() {
    $function = new AVC_Functions();
    if (! $function->avc_validate_nonce() || ! is_user_logged_in()) {
        wp_send_json_error(['message' => 'Unauthorized access.'], 403);
        exit;
    }

    $user_id = $function->avc_get_user_detail('id');
    update_user_meta($user_id, 'avc_consent_status', true);

    wp_send_json_success(['message' => 'Consent status updated.']);
}
add_action('wp_ajax_avc_set_user_consent_status', 'avc_set_user_consent_status');
add_action('wp_ajax_nopriv_avc_set_user_consent_status', 'avc_set_user_consent_status');


function avc_save_avc_settings() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Authentication required.' ], 401 );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    $nonce = '';
    if ( isset($_SERVER['HTTP_X_AVC_NONCE']) ) {
        $nonce = sanitize_text_field( wp_unslash($_SERVER['HTTP_X_AVC_NONCE']) );
    }

    if ( ! wp_verify_nonce( $nonce, 'avc-script-nonce' ) ) {
        wp_send_json_error(['message' => 'Invalid nonce.'], 403);
    }

    $function = new AVC_Functions();
    $data = json_decode(file_get_contents('php://input'), true);

    $allowed_fields = ['avc_selected_role', 'avc_website_developer'];

    foreach ($data as $key => $value) {
        if (! in_array($key, $allowed_fields, true)) {
            wp_send_json_error(['message' => 'Invalid setting field: ' . esc_html($key)]);
        }
        
        $function->avc_update_settings($key, $value);
    }

    wp_send_json_success(['message' => 'Settings saved']);
}
add_action('wp_ajax_avc_save_settings', 'avc_save_avc_settings');