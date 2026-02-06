<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AVC_Functions {

    public function __construct() {}

    // function is used to get site settings data by key
    Public function avc_get_setting_data($key, $default = '') {
        return get_option($key, $default);
    }

    Public function avc_update_settings($key, $value) {
        update_option( $key, $value, false );
    }

    public function avc_restricted_screen() {
        if (is_admin()) {
            $wpf_current_screen = get_current_screen();
            if ($wpf_current_screen->id == 'settings_page_atarim-visual-collaboration') {
                return true;
            }
        }
        return false;
    }

    public function avc_make_api_call($url, $data, $apikey = '', $token = '', $method = 'POST', $is_print = false) {
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

    public function avc_validate_nonce() {
        if (! isset($_POST['avc_nonce']) || ! wp_verify_nonce($_POST['avc_nonce'], 'avc-script-nonce')) {
            return false;
        }

        return true;
    }

    public function avc_user_consent_form() {
        $logo = $this->avc_get_setting_data('avc_collab_logo');
        if ($logo == '') {
            $logo = AVC_PLUGIN_URL . 'images/logo.svg';
        }
        
        $currentpageurl = $this->get_current_page_url();
        $screenshot = $this->avc_image_exists_checker('https://api.urlbox.io/v1/N2okA12ymiQKeGym/png?url=' . $currentpageurl, AVC_PLUGIN_URL . 'images/placeholderr.png');

        $consent_modal = "<div class='avc_user_consent_container'>
                            <div class='avc_user_consent_wrapper'>
                                <div class='avc_user_consent_modal'>
                                    <div class='avc_start_collab_modal'>
                                        <img src='" . $logo . "' class='avc_user_consent_logo'>
                                        <div class='avc_user_consent_title'>Dive Into Real-Time Collaboration</div>
                                        <div class='avc_user_consent_button'>
                                            <img src='" . AVC_PLUGIN_URL . 'images/loader-2.svg'  . "' class='avc_consent_loader'>
                                            <img src='" . AVC_PLUGIN_URL . 'images/wordpress-alt.svg'  . "'  class='avc_user_consent_button_img'>
                                            <span class='avc_user_consent_button_text'>Connect Your WordPress Account</span>
                                        </div>
                                        <div class='avc_user_consent_terms'>By clicking this button, you agree to our <a href='www.atarim.io'>terms & conditions</a></div>
                                    </div>
                                    <div class='avc_consent_meta_info'>
                                        <div class='avc_user_consent_close'>&times;</div>
                                        <div class='avc_consent_meta_title'>Ready To Give Your Feedback?</div>
                                        <div class='avc_consent_meta_desc'>Simply click any part of the page to leave a comment and instantly notify others who are doing the work, so they can get it done fast!</div>
                                        <div class='avc_consent_meta_img'>
                                            <img src='" . AVC_PLUGIN_URL . 'images/comment-overlay.png'  . "' class='avc_comment_overlay'>
                                            <img src='" . $screenshot  . "' class='avc_current_screen'>
                                        </div>
                                    </div>
                                </div>
                            </div>
                          </div>";
        return $consent_modal;
    }

    public function avc_user_consent_modal_trigger() {
        $favicon = $this->avc_get_setting_data('avc_collab_favicon');
        if ($favicon == '') {
            $favicon = AVC_PLUGIN_URL . 'images/atarim_icon.svg';
        }

        $trigger = '<div class="avc_consent_form_launcher">
                        <img src="'. $favicon .'" alt="poweredby">
                    </div>';
        return $trigger;
    }

    public function avc_image_exists_checker( $imageUrl, $alterimg ) {
        $imageUrl = str_replace(' ', '%20', $imageUrl);
        // Check if the URL is reachable
        if ( $imageUrl != '' ) {
            if ( @getimagesize( $imageUrl ) ) {
                return $imageUrl;
            }
        }
        return $alterimg; 
    }

    public function avc_get_user_detail($field) {
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
    public function avc_allowed_user_role() {
        $selected_roles = (array)$this->avc_get_setting_data('avc_selected_role', ['administrator', 'editor']);
        $role = $this->avc_get_user_detail('role');
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

    public function get_collab_css() {
        return '<link rel="stylesheet" crossorigin="" href="https://ij-script.pages.dev/atarim.css">';
    }

    public function get_collab_js($site_id) {

        return '<script defer type="module"'
            . ' src="https://ij-script.pages.dev/atarim.js"'
            . ' data-siteid="' . esc_attr( $site_id ) . '"'
            . ' data-site-type="wordpress"'
            . '></script>';
    }

    public function avc_get_whitelabel() {
        $data = [
            'site_id' => $this->avc_get_setting_data('avc_site_id'),
        ];

        $response = $this->avc_make_api_call(
            AVC_CRM_API . 'wp-api/site/whitelabel',
            $data,
            '',
            '',
            'GET'
        );

        if ($response['status_code'] === 200 && isset($response['data']['wpfeedback_logo'])) {
            $this->avc_update_settings('avc_collab_logo', $response['data']['wpfeedback_logo']);
            $this->avc_update_settings('avc_collab_favicon', $response['data']['wpfeedback_favicon']);
            $this->avc_update_settings('avc_collab_color', $response['data']['wpfeedback_color']);
        }
    }

    public function get_current_page_url() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $requestUri = $_SERVER['REQUEST_URI'];
        return $protocol . $host . $requestUri;
    }

    public function avc_update_site_data() {
        $site_id = $this->avc_get_setting_data('wpf_site_id');

        $response = $this->avc_make_api_call(
            AVC_CRM_API . 'encrypt/' . $site_id,
            '',
            '',
            '',
            'GET'
        );

        if ($response['status_code'] === 200 && ! empty($response['data'])) {
            $this->avc_update_settings('avc_site_id', $response['data']);
            $this->avc_update_settings('avc_initial_setup_complete', 'yes');
            $this->avc_update_settings('avc_collab_active', 'yes');
            $this->avc_update_settings('avc_license', 'valid');
            $this->avc_get_whitelabel();
            $savedRoles = $this->avc_get_setting_data('wpf_selcted_role', '');
            if (is_string($savedRoles) || $savedRoles != '') {
                $roles = array_filter(array_map('trim', explode(',', $savedRoles)));
                $this->avc_update_settings('avc_selected_role', $roles);
            }
        }
    }
}