<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AVC_Inject_Script {
    private $function;
    private $license;
    private $is_collab_active;
    private $inisetup;

    public function __construct() {
        $this->function = new AVC_Functions();
        $this->license = $this->function->avc_get_setting_data('avc_license');
        $this->is_collab_active = $this->function->avc_get_setting_data('avc_collab_active');
        $this->inisetup = $this->function->avc_get_setting_data('avc_initial_setup_complete');

        // Initialize hooks
        $this->init_hooks();
    }

    private function init_hooks() {
        // Load collaboration script
        add_action('wp_head', [$this, 'load_collaboration_script'], 11);
        add_action('admin_head', [$this, 'load_collaboration_script'], 11);


        // Load global styles and scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_global_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_global_assets']);

        // Load footer scripts
        add_action('wp_footer', [$this, 'load_footer_script']);
        add_action('admin_footer', [$this, 'load_footer_script']);

        // Auto login
        add_action('init', array($this, 'avc_autologin'));
        //add_action('init', array($this, 'avc_accept_invitation'));
    }

    public function load_collaboration_script() {
        if(isset($_GET['site_id']) && ! empty($_GET['site_id'])) {
            return;
        }

        $site_id = $this->function->avc_get_setting_data('avc_site_id');
        if(isset($_GET['activation_callback']) && ! empty($_GET['activation_callback'])) {
            $site_id = '';
        }
        
        $user_id = $this->function->avc_get_user_detail('id');
        $is_webmaster = get_user_meta($user_id, 'avc_user_type', true) === 'webmaster';
        $allow_collab = isset($_GET['collab']) && $_GET['collab'] == 'true';
        $has_consented = get_user_meta($user_id, 'avc_consent_status', true);

        if (
            ! $allow_collab && (
                $this->function->avc_restricted_screen() ||
                $this->license !== 'valid' ||
                $this->is_collab_active !== 'yes' ||
                $this->inisetup !== 'yes' ||
                ! is_user_logged_in() ||
                ! $this->function->avc_allowed_user_role() ||
                (! $is_webmaster && ! $has_consented) ||
                $site_id == ''
            )
        ) {
            return;
        }

        echo $this->function->get_collab_css();
        echo $this->function->get_collab_js($site_id);
    }

    public function enqueue_global_assets() {
        wp_register_style(
            'avc-global-style',
            AVC_PLUGIN_URL . 'assets/css/global.css',
            [],
            filemtime(AVC_PLUGIN_DIR . 'assets/css/global.css')
        );
        wp_enqueue_style('avc-global-style');

        wp_enqueue_script('jquery');

        wp_register_script(
            'avc-global-script',
            AVC_PLUGIN_URL . 'assets/js/global.js',
            ['jquery'],
            AVC_VERSION,
            true
        );
        wp_enqueue_script('avc-global-script');

        wp_localize_script('avc-global-script', 'ajax', [
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);

        $avc_nonce = wp_create_nonce('avc-script-nonce');
        wp_localize_script('avc-global-script', 'site_data', [
            'site_url' => AVC_HOME_URL,
            'avc_nonce' => $avc_nonce
        ]);
    }

    public function load_footer_script() {
        $user_id = $this->function->avc_get_user_detail('id');
        $is_webmaster = get_user_meta($user_id, 'avc_user_type', true) === 'webmaster';
        $site_id = $this->function->avc_get_setting_data('avc_site_id');
        $has_consented = get_user_meta($user_id, 'avc_consent_status', true);

        if (
            $this->function->avc_restricted_screen() || 
            $this->license !== 'valid' || 
            $this->is_collab_active !== 'yes' || 
            $this->inisetup !== 'yes' ||
            ! is_user_logged_in() || 
            ! $this->function->avc_allowed_user_role() ||
            $is_webmaster ||
            $has_consented ||
            $site_id == ''
        ) {
            return;
        }

        echo $this->function->avc_user_consent_modal_trigger();
        echo $this->function->avc_user_consent_form();
    }

    public function avc_autologin() {
        if (! isset($_GET['wpf_token'])) {
            return;
        }

        if (isset($_GET['wpf_token']) && is_user_logged_in()) {
            return;
        }

        $webmaster = $this->function->avc_get_setting_data('avc_website_developer');
        if ($webmaster == '') {
            return;
        }

        $payload = [
            'site_id' => $this->function->avc_get_setting_data('avc_site_id'),
        ];

        $response = $this->function->avc_make_api_call(
            AVC_CRM_API . 'wp-api/user/verify-access',
            wp_json_encode($payload),
            '',
            $_GET['wpf_token']
        );

        if (
            $response['status_code'] === 200 &&
            isset($response['data']['status']) &&
            $response['data']['status'] == 1
        ) {
            $user = get_user_by('email', $webmaster);
            if (! is_wp_error($user)) {
                wp_clear_auth_cookie();
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                setcookie('_wordpress_test_cookie', 'wpf_test', 900, '/' );
            }
        }

        $removeparam = array('wpf_token', 'wpf_username', 'wpf_login');
        // Get the current URL
        $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $newurl = $this->function->remove_url_parameter($currentUrl, $removeparam);
        // Redirect to the new URL
        wp_safe_redirect($newurl, 301);
        exit;
    }

    public function avc_accept_invitation() {
        if (
            is_user_logged_in() ||
            ! isset($_GET['token']) ||
            empty($_GET['token'])
        ) {
            return;
        }

        $data = [
            'token' => sanitize_text_field($_GET['token'])
        ];

        $response = $this->function->avc_make_api_call(
            AVC_CRM_API . 'collaborate/site/accept-invitation',
            $data,
            '',
            '',
            'GET'
        );

        if ($response['status_code'] === 200) {
            if (
                isset($response['data']['status']) &&
                $response['data']['status'] == 1 &&
                isset($response['data']['result']['access_token'])
            ) {
                $token = $response['data']['result']['access_token'];
                setcookie('avc_token', $token, time() + (86400 * 30), '/');
            } else if (
                isset($response['data']['status']) &&
                $response['data']['status'] == '' &&
                isset($response['data']['data']['error'])
            ) {
                echo $response['data']['data']['message'];
                die;
            }
        }

        $removeparam = array('token', 'role');
        // Get the current URL
        $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $newurl = $this->function->remove_url_parameter($currentUrl, $removeparam);
        wp_safe_redirect($newurl . '?collab=true', 301);
        exit;
    }
}

new AVC_Inject_Script();
