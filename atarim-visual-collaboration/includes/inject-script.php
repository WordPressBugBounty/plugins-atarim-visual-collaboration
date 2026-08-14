<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AVCF_Inject_Script {
    private $function;
    private $license;
    private $is_collab_active;
    private $inisetup;

    public function __construct() {
        $this->function = new AVCF_Functions();
        $this->license = $this->function->avcf_get_setting_data('avc_license');
        $this->is_collab_active = $this->function->avcf_get_setting_data('avc_collab_active');
        $this->inisetup = $this->function->avcf_get_setting_data('avc_initial_setup_complete');

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
        add_action('init', array($this, 'avcf_autologin'));
        //add_action('init', array($this, 'avcf_accept_invitation'));
    }

    public function load_collaboration_script() {
        if (isset($_GET['site_id']) && ! empty($_GET['site_id'])) {
            return;
        }

        $site_id = $this->function->avcf_get_setting_data('avc_site_id');
        if (isset($_GET['activation_callback']) && ! empty($_GET['activation_callback'])) {
            $site_id = '';
        }
        
        $user_id = $this->function->avcf_get_user_detail('id');
        if (is_wp_error($user_id)) {
            $user_id = 0;
        }

        $is_webmaster = $user_id ? (get_user_meta($user_id, 'avc_user_type', true) === 'webmaster') : false;
        $has_consented = $user_id ? (bool) get_user_meta($user_id, 'avc_consent_status', true) : false;

        $allow_collab = false;
        if (! is_user_logged_in()) {
            $allow_collab = $this->function->avcf_is_site_public($site_id);
        }

        if (isset($_GET['collab'])) {
            $allow_collab = filter_var($_GET['collab'], FILTER_VALIDATE_BOOLEAN);
        }

        $is_setting_screen =  $this->function->avcf_setting_screen();

        if (
            ! $allow_collab && (
                $this->license !== 'valid' ||
                $this->is_collab_active !== 'yes' ||
                $this->inisetup !== 'yes' ||
                ! is_user_logged_in() ||
                ! $this->function->avcf_allowed_user_role() ||
                (! $is_webmaster && ! $has_consented) ||
                $site_id == ''
            )
        ) {
            return;
        }

        echo $this->function->get_collab_js($site_id, $is_setting_screen);
    }

    public function enqueue_global_assets() {
        wp_register_style(
            'avc-global-style',
            AVCF_PLUGIN_URL . 'assets/css/global.css',
            [],
            filemtime(AVCF_PLUGIN_DIR . 'assets/css/global.css')
        );
        wp_enqueue_style('avc-global-style');

        wp_enqueue_script('jquery');

        wp_register_script(
            'avc-global-script',
            AVCF_PLUGIN_URL . 'assets/js/global.js',
            ['jquery'],
            AVCF_VERSION,
            true
        );
        wp_enqueue_script('avc-global-script');

        wp_localize_script('avc-global-script', 'avcajax', [
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);

        $avc_nonce = wp_create_nonce('avc-script-nonce');
        wp_localize_script('avc-global-script', 'avc_site_data', [
            'site_url' => AVCF_HOME_URL,
            'avc_nonce' => $avc_nonce
        ]);
    }

    public function load_footer_script() {
        $user_id = $this->function->avcf_get_user_detail('id');
        if ( is_wp_error( $user_id ) ) {
            $user_id = 0;
        }

        $is_webmaster = $user_id ? ( get_user_meta( $user_id, 'avc_user_type', true ) === 'webmaster' ) : false;
        $has_consented = $user_id ? (bool) get_user_meta( $user_id, 'avc_consent_status', true ) : false;
        $site_id = $this->function->avcf_get_setting_data('avc_site_id');

        if (
            $this->function->avcf_setting_screen() ||
            $this->license !== 'valid' || 
            $this->is_collab_active !== 'yes' || 
            $this->inisetup !== 'yes' ||
            ! is_user_logged_in() || 
            ! $this->function->avcf_allowed_user_role() ||
            $is_webmaster ||
            $has_consented ||
            $site_id == ''
        ) {
            return;
        }

        echo $this->function->avcf_user_consent_modal_trigger();
        echo $this->function->avcf_user_consent_form();
    }

    public function avcf_autologin() {
        if (! isset($_GET['wpf_token'])) {
            return;
        }

        if (isset($_GET['wpf_token']) && is_user_logged_in()) {
            return;
        }

        $webmaster = $this->function->avcf_get_setting_data('avc_website_developer');
        if ($webmaster == '') {
            return;
        }

        $payload = [
            'site_id' => $this->function->avcf_get_setting_data('avc_site_id'),
        ];

        $wpf_token = sanitize_text_field(wp_unslash($_GET['wpf_token']));

        $response = $this->function->avcf_make_api_call(
            AVCF_CRM_API . 'wp-api/user/verify-access',
            wp_json_encode($payload),
            '',
            $wpf_token
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
                $this->avcf_make_auth_cookies_embeddable();
            }
        }

        $removeparam = array('wpf_token', 'wpf_username', 'wpf_login');
        // Remove the params from the current request URL.
        $newurl = remove_query_arg($removeparam);
        // Redirect safely (same-host only).
        wp_safe_redirect(esc_url_raw($newurl), 302);
        exit;
    }

    /**
     * Re-send the auth cookies WordPress just emitted with attributes that
     * survive inside a cross-site iframe: the Atarim stage embeds the site
     * on the app's origin, and WordPress emits its cookies without SameSite,
     * which browsers treat as Lax and drop on embedded requests. Partitioned
     * keeps them accepted once third-party cookies are fully phased out.
     */
    private function avcf_make_auth_cookies_embeddable() {
        if (headers_sent()) {
            return;
        }

        $cookies = array();
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookies[] = trim(substr($header, strlen('Set-Cookie:')));
        }
        if (empty($cookies)) {
            return;
        }

        header_remove('Set-Cookie');
        foreach ($cookies as $cookie) {
            if (preg_match('/^wordpress_/i', $cookie)) {
                $cookie = preg_replace('/;\s*samesite=[^;]*/i', '', $cookie);
                if (! preg_match('/;\s*secure(;|$)/i', $cookie)) {
                    $cookie .= '; Secure';
                }
                $cookie .= '; SameSite=None; Partitioned';
            }
            header('Set-Cookie: ' . $cookie, false);
        }
    }

    public function avcf_accept_invitation() {
        if (
            is_user_logged_in() ||
            ! isset($_GET['token']) ||
            empty($_GET['token'])
        ) {
            return;
        }

        $data = [
            'token' => sanitize_text_field(wp_unslash($_GET['token']))
        ];

        $response = $this->function->avcf_make_api_call(
            AVCF_CRM_API . 'collaborate/site/accept-invitation',
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
        // Remove the params from the current request URL.
        $newurl = remove_query_arg($removeparam);
        // Redirect safely (same-host only).
        wp_safe_redirect(esc_url_raw($newurl), 302);
        exit;
    }
}

new AVCF_Inject_Script();
