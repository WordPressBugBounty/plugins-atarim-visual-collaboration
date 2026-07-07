<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AVCF_Functions {

    public function __construct() {}

    // function is used to get site settings data by key
    Public function avcf_get_setting_data($key, $default = '') {
        return get_option($key, $default);
    }

    Public function avcf_update_settings($key, $value) {
        update_option( $key, $value, false );
    }

    public function avcf_setting_screen() {
        if (is_admin()) {
            $wpf_current_screen = get_current_screen();
            if ($wpf_current_screen->id == 'settings_page_atarim-visual-collaboration') {
                return true;
            }
        }
        return false;
    }

    public function avcf_make_api_call($url, $data, $apikey = '', $token = '', $method = 'POST', $is_print = false) {
        $header = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'response-signature' => '',
        );

        if ($apikey != '') {
            $header['api-key'] = $apikey;
        }

        if ($token != '') {
            $header['Authorization'] = 'Bearer ' . $token;
        }

        $args = array(
            'headers'     => $header,
            'timeout'     => 100,
        );

        $response = null;

        switch ($method) {
            case 'POST':
                $args['body'] = is_array($data) ? wp_json_encode($data) : $data;
                $response = wp_remote_post($url, $args);
                break;
        
            case 'GET':
                if (is_array($data) && ! empty($data)) {
                    $url = add_query_arg($data, $url);
                    $response = wp_remote_get($url, $args);
                } else {
                    $response = wp_remote_get($url, $args);
                }
                break;
        
            default:
                return array(
                    'error' => true,
                    'message' => "Unsupported HTTP method: $method",
                );
        }
        

        if($is_print) {
            return $response;
        }
        
        if (is_wp_error($response)) {
            return array(
                'status_code' => 500,
                'message' => $response->get_error_message(),
            );
        }
    
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            $body = wp_remote_retrieve_body($response);
            $content_type = wp_remote_retrieve_header($response, 'content-type');

            if (strpos($content_type, 'application/json') !== false) {
                return array(
                    'status_code' => $status_code,
                    'data' => json_decode($body, true),
                );
            }

            return array(
                'status_code' => $status_code,
                'data' => $body,
            );
        } else {
            return array(
                'status_code' => $status_code,
                'error' => json_decode(wp_remote_retrieve_body($response), true),
            );
        }
    }

    public function avcf_validate_nonce() {
        if (! isset($_POST['avc_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['avc_nonce'])),
                'avc-script-nonce'
            )
        ) {
            return false;
        }

        return true;
    }

    public function avcf_user_consent_form() {
        $logo = $this->avcf_get_setting_data('avc_collab_logo');
        if ($logo == '') {
            $logo = AVCF_PLUGIN_URL . 'images/logo.svg';
        }
        
        $currentpageurl = $this->get_current_page_url();
        $screenshot = $this->avcf_image_exists_checker('https://api.urlbox.io/v1/N2okA12ymiQKeGym/png?url=' . $currentpageurl, AVCF_PLUGIN_URL . 'images/placeholderr.png');

        $consent_modal = "<div class='avc_user_consent_container'>
                            <div class='avc_user_consent_wrapper'>
                                <div class='avc_user_consent_modal'>
                                    <div class='avc_start_collab_modal'>
                                        <img src='" . $logo . "' class='avc_user_consent_logo'>
                                        <div class='avc_user_consent_title'>Dive Into Real-Time Collaboration</div>
                                        <div class='avc_user_consent_button'>
                                            <img src='" . AVCF_PLUGIN_URL . 'images/loader-2.svg'  . "' class='avc_consent_loader'>
                                            <img src='" . AVCF_PLUGIN_URL . 'images/wordpress-alt.svg'  . "'  class='avc_user_consent_button_img'>
                                            <span class='avc_user_consent_button_text'>Connect Your Account</span>
                                        </div>
                                        <div class='avc_user_consent_terms'>By clicking this button, you agree to our <a href='https://atarim.io/terms-and-conditions/' target='_blank'>terms & conditions</a></div>
                                    </div>
                                    <div class='avc_consent_meta_info'>
                                        <div class='avc_user_consent_close'>&times;</div>
                                        <div class='avc_consent_meta_title'>Ready To Give Your Feedback?</div>
                                        <div class='avc_consent_meta_desc'>Simply click any part of the page to leave a comment and instantly notify others who are doing the work, so they can get it done fast!</div>
                                        <div class='avc_consent_meta_img'>
                                            <img src='" . AVCF_PLUGIN_URL . 'images/comment-overlay.png'  . "' class='avc_comment_overlay'>
                                            <img src='" . $screenshot  . "' class='avc_current_screen'>
                                        </div>
                                    </div>
                                </div>
                            </div>
                          </div>";
        return $consent_modal;
    }

    public function avcf_user_consent_modal_trigger() {
        $favicon = $this->avcf_get_setting_data('avc_collab_favicon');
        if ($favicon == '') {
            $favicon = AVCF_PLUGIN_URL . 'images/atarim_icon.svg';
        }

        $trigger = '<div class="avc_consent_form_launcher">
                        <img src="'. $favicon .'" alt="poweredby">
                    </div>';
        return $trigger;
    }

    public function avcf_image_exists_checker( $imageUrl, $alterimg ) {
        $imageUrl = str_replace(' ', '%20', $imageUrl);
        // Check if the URL is reachable
        if ( $imageUrl != '' ) {
            if ( @getimagesize( $imageUrl ) ) {
                return $imageUrl;
            }
        }
        return $alterimg; 
    }

    public function avcf_get_user_detail($field) {
        $valid_fields = ['id', 'email', 'first_name', 'last_name', 'role'];
        if (! in_array($field, $valid_fields, true)) {
            return new WP_Error('invalid_key', 'The provided key is not valid. Allowed keys are: ' . implode(', ', $valid_fields) . '.');
        }
    
        if (! is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in to retrieve user details.');
        }
    
        $current_user = wp_get_current_user();
        switch ($field) {
            case 'id':
                return $current_user->ID;
            case 'email':
                return $current_user->user_email;
            case 'first_name':
                return get_user_meta($current_user->ID, 'first_name', true);
            case 'last_name':
                return get_user_meta($current_user->ID, 'last_name', true);
            case 'role':
                return !empty($current_user->roles) ? $current_user->roles[0] : null;
            default:
                return new WP_Error('unexpected_error', 'An unexpected error occurred.');
        }
    }
    public function avcf_allowed_user_role() {
        $selected_roles = (array)$this->avcf_get_setting_data('avc_selected_role', ['administrator', 'editor']);
        $role = $this->avcf_get_user_detail('role');
        if (is_wp_error($role)) {
            return false;
        }

        return in_array($role, $selected_roles, true);
    }

    public function remove_url_parameter($url, $removeparam) {
        // Parse the URL to get its components
        $urlParts = parse_url($url);
        $newUrl = '';
    
        // If there's no query string, no need to do anything
        if (! empty($urlParts['query'])) {
    
            // Parse the query string to get an associative array of the parameters
            parse_str($urlParts['query'], $queryParams);
    
            // Remove the parameter from the query array
            if(! empty($removeparam)) {
                foreach($removeparam as $param) {
                    unset($queryParams[$param]);
                }
            }
    
            // Rebuild the query string without the removed parameter
            $newQueryString = http_build_query($queryParams);
            // Rebuild the full URL without the removed parameter
            $newUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'];
            if (! empty($newQueryString)) {
                $newUrl .= '?' . $newQueryString;
            }
        } else {
            $newUrl = $url;
        }

        return $newUrl;
    }

    public function get_collab_js($site_id, $is_setting_screen = false) {

        $headless_attr = $is_setting_screen ? ' data-atarim-mode="headless"' : '';
        return '<script defer type="module"'
            . ' src="' . AVCF_SCRIPT_URL . '"'
            . ' data-siteid="' . esc_attr( $site_id ) . '"'
            . ' data-site-type="wordpress"'
            . $headless_attr
            . '></script>';
    }

    public function avcf_get_whitelabel() {
        $data = [
            'site_id' => $this->avcf_get_setting_data('avc_site_id'),
        ];

        $response = $this->avcf_make_api_call(
            AVCF_CRM_API . 'wp-api/site/whitelabel',
            $data,
            '',
            '',
            'GET'
        );

        if ($response['status_code'] === 200 && isset($response['data']['wpfeedback_logo'])) {
            $this->avcf_update_settings('avc_collab_logo', $response['data']['wpfeedback_logo']);
            $this->avcf_update_settings('avc_collab_favicon', $response['data']['wpfeedback_favicon']);
            $this->avcf_update_settings('avc_collab_color', $response['data']['wpfeedback_color']);
        }
    }

    public function get_current_page_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';

        $host = '';
        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            $host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
            $host = preg_replace( '/[^a-zA-Z0-9\.\-:]/', '', $host );
        }

        $request_uri = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
            $request_uri = preg_replace( '/[\x00-\x1F\x7F]/', '', $request_uri );
        }

        return esc_url_raw( $scheme . $host . $request_uri );
    }

    public function avcf_update_site_data() {
        $site_id = $this->avcf_get_setting_data('wpf_site_id');

        $response = $this->avcf_make_api_call(
            AVCF_CRM_API . 'encrypt/' . $site_id,
            '',
            '',
            '',
            'GET'
        );

        if ($response['status_code'] === 200 && ! empty($response['data'])) {
            $this->avcf_update_settings('avc_site_id', $response['data']);
            $this->avcf_update_settings('avc_initial_setup_complete', 'yes');
            $this->avcf_update_settings('avc_collab_active', 'yes');
            $this->avcf_update_settings('avc_license', 'valid');
            $this->avcf_get_whitelabel();
            $savedRoles = $this->avcf_get_setting_data('wpf_selcted_role', '');
            if (is_string($savedRoles) || $savedRoles != '') {
                $roles = array_filter(array_map('trim', explode(',', $savedRoles)));
                $this->avcf_update_settings('avc_selected_role', $roles);
            }
        }
    }

    public function avcf_get_site_visibility($site_id) {
        $site_id = trim((string) $site_id);
        if ($site_id === '') {
            return array(
                'status_code' => 400,
                'data' => array('site_visibility' => null),
            );
        }

        // Cache for 10 minutes to avoid hitting API on every page load
        $cache_key = 'avc_site_visibility_' . md5($site_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return array(
                'status_code' => 200,
                'data' => array('site_visibility' => $cached),
            );
        }

        $response = $this->avcf_make_api_call(
            AVCF_CRM_API . 'site/' . rawurlencode($site_id) . '/visibility',
            array(),
            '',
            '',
            'GET'
        );

        if (
            isset($response['status_code']) &&
            (int) $response['status_code'] === 200 &&
            isset($response['data']['site_visibility'])
        ) {
            $visibility = strtolower(trim((string) $response['data']['site_visibility']));
            if (! in_array($visibility, array('public', 'locked', 'private'), true)) {
                $visibility = null;
            }

            set_transient($cache_key, $visibility, 1 * MINUTE_IN_SECONDS);

            $response['data']['site_visibility'] = $visibility;
            return $response;
        }

        // Cache failures briefly too (prevents hammering if API is down)
        set_transient($cache_key, null, 1 * MINUTE_IN_SECONDS);

        return $response;
    }

    public function avcf_is_site_public($site_id) {
        $resp = $this->avcf_get_site_visibility($site_id);

        return (
            isset($resp['status_code'], $resp['data']['site_visibility']) &&
            (int) $resp['status_code'] === 200 &&
            $resp['data']['site_visibility'] === 'public'
        );
    }
}