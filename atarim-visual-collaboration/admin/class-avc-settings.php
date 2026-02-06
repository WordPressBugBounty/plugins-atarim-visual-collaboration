<?php
if (!defined('ABSPATH')) {
    exit;
}

class AVC_Settings {
    private $function;

    public function __construct() {
        $this->function = new AVC_Functions(); // Initialize AVC_Functions

        // Register activation hook
        register_activation_hook(AVC_PLUGIN_DIR . 'atarim-visual-collaboration.php', [$this, 'avc_set_activation_redirect']);

        // Setting button on the plugin page
        add_filter( 'plugin_action_links_' . AVC_PLUGIN_BASE, [$this, 'avc_setting_action_link']);

        // Redirect on activation
        add_action('admin_init', [$this, 'avc_redirect_to_settings_page']);

        add_action('admin_menu', [$this, 'avc_add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'avc_admin_assets']);

        // Activate license
        add_action('init', [$this, 'avc_license_activation']);

        // Temporary hook to fire action for script version of the plugin
        add_action('init', [$this, 'avc_auto_update_settings']);
    }

    public function avc_set_activation_redirect() {
        add_option('avc_plugin_activation_redirect', true);
    }

    public function avc_redirect_to_settings_page() {
        if ($this->function->avc_get_setting_data('avc_plugin_activation_redirect', false)) {
            delete_option('avc_plugin_activation_redirect');
            wp_safe_redirect(admin_url('options-general.php?page=atarim-visual-collaboration'));
            exit();
        }
    }

    public function avc_setting_action_link($links) {
        $links[] = '<a href="' . esc_url(admin_url('options-general.php?page=atarim-visual-collaboration')) . '">' . __('Settings', 'atarim-visual-collaboration') . '</a>';
        return $links;
    }

    public function avc_license_activation() {
        if (! isset($_GET['atarim_response'])) {
            return;
        }

        if (
            ! is_admin() ||
            ! is_user_logged_in() ||
            ! $this->function->avc_allowed_user_role() ||
            ! isset($_GET['page'])
        ) {
            wp_safe_redirect(AVC_HOME_URL);
            exit;
        }

        $page_raw = sanitize_text_field(wp_unslash($_GET['page']));
        $page_decoded = base64_decode($page_raw, true);

        if (false === $page_decoded) {
            wp_safe_redirect(AVC_HOME_URL);
            exit;
        }

        $parsed = [];
        parse_str('page=' . $page_decoded, $parsed);

        $page_slug = isset($parsed['page']) ? $parsed['page'] : '';
        $atarim_state = isset($parsed['atarim_state']) ? $parsed['atarim_state'] : '';

        if ('atarim-visual-collaboration' !== $page_slug) {
            wp_safe_redirect(AVC_HOME_URL);
            exit;
        }

        // Verify nonce / state.
        if ( empty( $atarim_state ) || ! wp_verify_nonce( $atarim_state, 'avc_new_license_activation' ) ) {
            wp_safe_redirect(AVC_HOME_URL);
            exit;
        }

        if (strpos($_GET['atarim_response'], '%3D') !== false) {
            $atarim_response = substr($_GET['atarim_response'], -1, 3);
        } else {
            $atarim_response = $_GET['atarim_response'];
        }

        $user_id = $this->function->avc_get_user_detail('id');
        $this->function->avc_update_settings('avc_license', base64_decode(sanitize_text_field($atarim_response)));
        $avc_site_id = sanitize_text_field($_GET['site_id']);
        $this->function->avc_update_settings('avc_site_id', $avc_site_id);
        $this->function->avc_update_settings('avc_initial_setup_complete', 'yes');
        $this->function->avc_update_settings('avc_collab_active', 'yes');
        update_user_meta($user_id, 'avc_user_type', 'webmaster', false);
        $this->function->avc_get_whitelabel();
        wp_safe_redirect(AVC_HOME_URL);
        exit();
    }

    public function avc_auto_update_settings() {
        $isOldPluginActivated = $this->function->avc_get_setting_data('wpf_initial_setup_complete');
        $isNewPluginActivated = $this->function->avc_get_setting_data('avc_initial_setup_complete');
        if($isOldPluginActivated === 'yes' && $isNewPluginActivated === 'yes') {
            return;
        }

        $this->function->avc_update_site_data();
    }
    public function avc_add_settings_page() {
        add_options_page(
            __('Atarim Visual Collaboration', 'atarim-visual-collaboration'),
            __('Collaborate', 'atarim-visual-collaboration'),
            'manage_options',
            'atarim-visual-collaboration',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        echo '<div id="avc-settings-root"></div>'; // React app will render here
    }

    public function avc_admin_assets() {
        $screen = get_current_screen();
        if ($screen->id === 'settings_page_atarim-visual-collaboration') {
            wp_enqueue_style('wp-components', includes_url('css/dist/components/style.min.css'), [], '1.0.0');
            wp_enqueue_style('acv-setting-style', AVC_PLUGIN_URL . 'assets/css/settings.css', false, AVC_VERSION);
            wp_enqueue_script('avc-settings-script', AVC_PLUGIN_URL . 'assets/build/index.js', [], '1.0.0', true);

            wp_register_script('acv-setup-script', AVC_PLUGIN_URL . 'assets/js/admin.js', false, AVC_VERSION);
            wp_enqueue_script('acv-setup-script');

            $selected_roles = $this->function->avc_get_setting_data('avc_selected_role', ['administrator', 'editor']);
            $users = get_users(['role__in' => $selected_roles]);
            $isCollabActive = $this->function->avc_get_setting_data('avc_collab_active', 'no');
            $atarim_state = wp_create_nonce( 'avc_new_license_activation' );
            $page_redirect = 'atarim-visual-collaboration&atarim_state=' . $atarim_state;
            $activationUrl = AVC_HOME_URL . '/?activation_callback=' . base64_encode(AVC_SITE_URL)
                . '&page_redirect=' . base64_encode($page_redirect)
                . '&site_url=' . base64_encode(AVC_HOME_URL)
                . '&collab=true';

            global $wp_roles;
            $available_roles = [];
            if (!empty($wp_roles->roles)) {
                foreach ($wp_roles->roles as $role_key => $role) {
                    $available_roles[] = [
                        'label' => $role['name'],
                        'value' => $role_key,
                    ];
                }
            }

            $crm_api_url = AVC_CRM_API;
            $app_url = AVC_APP_SITE_URL;
            $registerUrl = $app_url . '/register';
            $pluginVersion = AVC_VERSION;

            wp_localize_script('avc-settings-script', 'avcSettings', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'avc_nonce' => wp_create_nonce('avc-script-nonce'),
                'isCollabActive' => $isCollabActive,
                'activationUrl' => $activationUrl,
                'avc_collab_link' => AVC_HOME_URL . '/?collab=true',
                'pluginVersion' => $pluginVersion,
                'notice'      => [
                    'enabled'          => true,
                    'showForVersions'  => ['4.3'],
                    'title'            => 'New: AI Collaboration Is Now Built In',
                    'descriptionHtml'  => '<p>We’ve rebuilt the plugin for speed and smarter collaboration - now with your own AI teammates. Get clearer feedback, fewer revisions, and real-time support from agents trained on millions of creative reviews.</p><p>Whether you’re solo or scaling, it’s like having a full QA, UX, and content team in your back pocket.</p> <a href="https://atarim.io/help/atarim-ai/atarim-ai-workflow/" target="_blank" rel="noopener noreferrer"><button class="components-button is-primary"><span class="dashicons dashicons-video-alt3" style="margin-right: 4px;"></span>See It In Action</button></a>',
                ],
                'settings' => [
                    'avc_selected_role' => $selected_roles,
                    'avc_website_developer' => $this->function->avc_get_setting_data('avc_website_developer'),
                ],
                'users' => array_map(function ($user) {
                    return [
                        'display_name' => $user->display_name,
                        'user_email' => $user->user_email,
                    ];
                }, $users),
                'availableRoles' => $available_roles,
                'logoUrl' => AVC_PLUGIN_URL . '/images/logo.svg',
                'i18n' => [
                    'connect' => __('Connect with Atarim', 'atarim-visual-collaboration'),
                    'disconnect' => __('Disconnect from Atarim', 'atarim-visual-collaboration'),
                    'connectHeading' => __('Connect this Site to your Atarim Workspace', 'atarim-visual-collaboration'),
                    'connectDescription' => __('Speed up feedback, reduce back & fourth and get AI suggestions right inside your site - No more guesswork or endless revisions.', 'atarim-visual-collaboration'),
                    'connectCta' => sprintf(
                        __('Don’t have an account? <a href="%s" target="_blank">Create one for free</a>', 'atarim-visual-collaboration'),
                        esc_url($registerUrl)
                    ),
                    'connected' => __('This website is connected to your dashboard.', 'atarim-visual-collaboration'),
                    'subheader' => sprintf(
                        __('Manage AI and human feedback, projects, tasks, integrations and your team directly in your <a href="%s" target="_blank">Atarim dashboard</a>', 'atarim-visual-collaboration'),
                        esc_url($app_url)
                    ),
                    'whoCan' => __('Who can collaborate', 'atarim-visual-collaboration'),
                    'guestMode' => __('Guest mode', 'atarim-visual-collaboration'),
                    'guestLinkText1' => __('Share this with the guests and clients to get fast feedback - with no WordPress login needed.', 'atarim-visual-collaboration'),
                    'guestLinkText2' => __('OR add "?collab=true" to any link on the site', 'atarim-visual-collaboration'),
                    'autoLoginAs' => __('1 click login from Atarim as', 'atarim-visual-collaboration'),
                    'enableAutoLogin' => __('Enable Auto Login', 'atarim-visual-collaboration'),
                    'copyLink' => __('Copy Link', 'atarim-visual-collaboration'),
                    'copied' => __('Copied!', 'atarim-visual-collaboration'),
                    'saveButton' => __('Save & Apply', 'atarim-visual-collaboration'),
                    'selectUserPlaceholder' => __('-- Select a user --', 'atarim-visual-collaboration'),
                ]
            ]);

            wp_add_inline_script('avc-settings-script', "
                document.addEventListener('DOMContentLoaded', function () {
                    const btn = document.querySelector('.avc-trigger-activate');
                    if (!btn) return;
            
                    btn.innerText = 'Preparing...';
                    btn.style.pointerEvents = 'none';
            
                    fetch('{$crm_api_url}wp/auth')
                        .then(res => res.json())
                        .then(data => {
                            const { read_key, write_key } = data;
            
                            if (!read_key || !write_key) {
                                btn.innerText = 'Connect with Atarim';
                                btn.style.pointerEvents = 'auto';
                                alert('Failed to prepare activation. Please try again.');
                                return;
                            }
            
                            const activationUrl = '{$app_url}/fetching/?_from=wp_plugin&from_wp=true&write_key=' + write_key;
            
                            btn.href = activationUrl;
                            btn.innerText = 'Connect with Atarim';
                            btn.style.pointerEvents = 'auto';
            
                            // Save read_key globally
                            window.avcReadKey = read_key;
                        })
                        .catch(err => {
                            console.error('Auth fetch failed:', err);
                            btn.innerText = 'Connect with Atarim';
                            btn.style.pointerEvents = 'auto';
                            alert('Could not prepare activation.');
                        });
            
                    // Attach polling on click
                    btn.addEventListener('click', function () {
                        if (!window.avcReadKey) return;
                        
                        btn.innerText = 'Authenticating...';
                        btn.style.pointerEvents = 'none';
            
                        const pollUrl = '{$crm_api_url}wp/pollForAccessToken?read_key=' + window.avcReadKey;
                        const redirectUrl = '{$activationUrl}';
            
                        const interval = setInterval(() => {
                            fetch(pollUrl, { credentials: 'include' })
                                .then(res => res.json())
                                .then(data => {
                                    if (data.access_token) {
                                        clearInterval(interval);
                                        window.location.href = redirectUrl;
                                    }
                                });
                        }, 3000);
                    }, { once: true }); // Only start polling once
                });
            ", 'after');
        }
    }
}

new AVC_Settings();