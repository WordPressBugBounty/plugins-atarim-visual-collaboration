<?php
if (!defined('ABSPATH')) {
    exit;
}

class AVCF_Settings {
    private $function;

    public function __construct() {
        $this->function = new AVCF_Functions(); // Initialize AVCF_Functions

        // Register activation hook
        register_activation_hook(AVCF_PLUGIN_DIR . 'atarim-visual-collaboration.php', [$this, 'avcf_set_activation_redirect']);

        // Setting button on the plugin page
        add_filter( 'plugin_action_links_' . AVCF_PLUGIN_BASE, [$this, 'avcf_setting_action_link']);

        // Redirect on activation
        add_action('admin_init', [$this, 'avcf_redirect_to_settings_page']);

        add_action('admin_menu', [$this, 'avcf_add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'avcf_admin_assets']);

        // First-run connect overlay, printed at body level so it covers the
        // whole admin and escapes any transformed ancestor in the content area.
        add_action('admin_footer', [$this, 'avcf_render_connect_overlay']);

        // Activate license
        add_action('init', [$this, 'avcf_license_activation']);

        // Temporary hook to fire action for script version of the plugin
        add_action('init', [$this, 'avcf_auto_update_settings']);
    }

    public function avcf_set_activation_redirect() {
        add_option('avc_plugin_activation_redirect', true);

        // Enable "Do it" by default on fresh activation only.
        add_option('avc_enable_doit', '1');
    }

    public function avcf_redirect_to_settings_page() {
        if ($this->function->avcf_get_setting_data('avc_plugin_activation_redirect', false)) {
            delete_option('avc_plugin_activation_redirect');
            // Already on the settings page (an Atarim-started connect sends the
            // window here with ?atarim_connect=1): redirecting would drop that.
            if (isset($_GET['page']) && 'atarim-visual-collaboration' === $_GET['page']) {
                return;
            }
            wp_safe_redirect(admin_url('options-general.php?page=atarim-visual-collaboration'));
            exit();
        }
    }

    public function avcf_setting_action_link($links) {
        $links[] = '<a href="' . esc_url(admin_url('options-general.php?page=atarim-visual-collaboration')) . '">' . __('Settings', 'atarim-visual-collaboration') . '</a>';
        return $links;
    }

    public function avcf_license_activation() {
        if (! isset($_GET['atarim_response'])) {
            return;
        }

        if (
            ! is_admin() ||
            ! is_user_logged_in() ||
            ! $this->function->avcf_allowed_user_role() ||
            ! isset($_GET['page'])
        ) {
            wp_safe_redirect(AVCF_HOME_URL);
            exit;
        }

        $page_raw = sanitize_text_field(wp_unslash($_GET['page']));
        $page_decoded = base64_decode($page_raw, true);

        if (false === $page_decoded) {
            wp_safe_redirect(AVCF_HOME_URL);
            exit;
        }

        $parsed = [];
        parse_str('page=' . $page_decoded, $parsed);

        $page_slug = isset($parsed['page']) ? $parsed['page'] : '';
        $atarim_state = isset($parsed['atarim_state']) ? $parsed['atarim_state'] : '';

        if ('atarim-visual-collaboration' !== $page_slug) {
            wp_safe_redirect(AVCF_HOME_URL);
            exit;
        }

        // Verify nonce / state.
        if (empty( $atarim_state ) || ! wp_verify_nonce($atarim_state, 'avc_new_license_activation')) {
            wp_safe_redirect(AVCF_HOME_URL);
            exit;
        }

        $atarim_response_raw = sanitize_text_field(wp_unslash($_GET['atarim_response']));
        if (strpos($atarim_response_raw, '%3D') !== false) {
            $atarim_response = substr($atarim_response_raw, -1, 3);
        } else {
            $atarim_response = $atarim_response_raw;
        }

        $this->function->avcf_store_connection(
            base64_decode(sanitize_text_field($atarim_response)),
            sanitize_text_field(wp_unslash($_GET['site_id'])),
            sanitize_text_field(wp_unslash($_GET['mcp_token']))
        );
        wp_safe_redirect(AVCF_HOME_URL);
        exit();
    }

    public function avcf_auto_update_settings() {
        $isOldPluginActivated = $this->function->avcf_get_setting_data('wpf_initial_setup_complete');
        $isNewPluginActivated = $this->function->avcf_get_setting_data('avc_initial_setup_complete');
        if($isOldPluginActivated === 'yes' && $isNewPluginActivated === 'yes') {
            return;
        }

        $this->function->avcf_update_site_data();
    }
    public function avcf_add_settings_page() {
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

    public function avcf_render_connect_overlay() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'settings_page_atarim-visual-collaboration' ) {
            return;
        }

        // First-run only: avc_initial_setup_complete is set to 'yes' at connect
        // success and survives disconnect, so the overlay shows until the first
        // successful connection and never again (existing connected sites never
        // see it).
        // An Atarim-started connect (?atarim_connect=1) needs the overlay's
        // confirm step even on a site that was connected before.
        if ( 'yes' === $this->function->avcf_get_setting_data('avc_initial_setup_complete') && ! isset( $_GET['atarim_connect'] ) ) {
            return;
        }

        require_once AVCF_PLUGIN_DIR . 'admin/atarim-connect-screen.php';
        if ( function_exists('atarim_connect_screen_render') ) {
            atarim_connect_screen_render();
        }
    }

    public function avcf_admin_assets() {
        $screen = get_current_screen();
        if ($screen->id === 'settings_page_atarim-visual-collaboration') {
            wp_enqueue_style('wp-components', includes_url('css/dist/components/style.min.css'), [], '1.0.0');
            wp_enqueue_style('acv-setting-style', AVCF_PLUGIN_URL . 'assets/css/settings.css', false, AVCF_VERSION);

            // Connect-overlay styles: only load for first-time (never-connected)
            // users, matching when the overlay itself is rendered.
            if ('yes' !== $this->function->avcf_get_setting_data('avc_initial_setup_complete') || isset($_GET['atarim_connect'])) {
                wp_enqueue_style('avc-connect-screen-style', AVCF_PLUGIN_URL . 'assets/css/atarim-connect-screen.css', [], AVCF_VERSION);
            }
            wp_enqueue_script('avc-settings-script', AVCF_PLUGIN_URL . 'assets/build/index.js', [], '1.0.0', true);

            wp_register_script('acv-setup-script', AVCF_PLUGIN_URL . 'assets/js/admin.js', false, AVCF_VERSION);
            wp_enqueue_script('acv-setup-script');

            $selected_roles = $this->function->avcf_get_setting_data('avc_selected_role', ['administrator', 'editor']);
            $users = get_users(['role__in' => $selected_roles]);
            $isCollabActive = $this->function->avcf_get_setting_data('avc_collab_active', 'no');
            $atarim_state = wp_create_nonce( 'avc_new_license_activation' );
            $page_redirect = 'atarim-visual-collaboration&atarim_state=' . $atarim_state;
            $activationUrl = AVCF_HOME_URL . '/?activation_callback=' . base64_encode(AVCF_SITE_URL)
                . '&page_redirect=' . base64_encode($page_redirect)
                . '&site_url=' . base64_encode(AVCF_HOME_URL)
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

            $app_url = AVCF_APP_SITE_URL;
            $app_parts = wp_parse_url($app_url);
            $app_origin = isset($app_parts['scheme'], $app_parts['host'])
                ? $app_parts['scheme'] . '://' . $app_parts['host'] . (isset($app_parts['port']) ? ':' . $app_parts['port'] : '')
                : '';
            $registerUrl = $app_url . '/register';
            $pluginVersion = AVCF_VERSION;

            wp_localize_script('avc-settings-script', 'avcSettings', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'avc_nonce' => wp_create_nonce('avc-script-nonce'),
                'isCollabActive' => $isCollabActive,
                'activationUrl' => $activationUrl,
                // The only origin allowed to hand this page a connect key.
                'appOrigin' => $app_origin,
                'avc_collab_link' => AVCF_HOME_URL . '/?collab=true',
                'pluginVersion' => $pluginVersion,
                'notice'      => [
                    'enabled'          => true,
                    'showForVersions'  => ['4.3'],
                    'title'            => 'New: AI Collaboration Is Now Built In',
                    'descriptionHtml'  => '<p>We’ve rebuilt the plugin for speed and smarter collaboration - now with your own AI teammates. Get clearer feedback, fewer revisions, and real-time support from agents trained on millions of creative reviews.</p><p>Whether you’re solo or scaling, it’s like having a full QA, UX, and content team in your back pocket.</p> <a href="https://atarim.io/help/atarim-ai/atarim-ai-workflow/" target="_blank" rel="noopener noreferrer"><button class="components-button is-primary"><span class="dashicons dashicons-video-alt3" style="margin-right: 4px;"></span>See It In Action</button></a>',
                ],
                'settings' => [
                    'avc_selected_role' => $selected_roles,
                    'avc_website_developer' => $this->function->avcf_get_setting_data('avc_website_developer'),
                    'avc_enable_doit' => (bool) $this->function->avcf_get_setting_data('avc_enable_doit'),
                ],
                'users' => array_map(function ($user) {
                    return [
                        'display_name' => $user->display_name,
                        'user_email' => $user->user_email,
                    ];
                }, $users),
                'availableRoles' => $available_roles,
                'logoUrl' => AVCF_PLUGIN_URL . '/images/logo.svg',
                'i18n' => [
                    'connect' => __('Connect with Atarim', 'atarim-visual-collaboration'),
                    'disconnect' => __('Disconnect from Dashboard', 'atarim-visual-collaboration'),
                    'settings' => __('Project Settings', 'atarim-visual-collaboration'),
                    'connectHeading' => __('Connect this Site to your Atarim Workspace', 'atarim-visual-collaboration'),
                    'connectDescription' => __('Speed up feedback, reduce back & fourth and get AI suggestions right inside your site - No more guesswork or endless revisions.', 'atarim-visual-collaboration'),
                    'connectCta' => sprintf(
                        __('Don’t have an account? <a href="%s" target="_blank">Create one for free</a>', 'atarim-visual-collaboration'),
                        esc_url($registerUrl)
                    ),
                    'connected' => __('This website is connected to your dashboard.', 'atarim-visual-collaboration'),
                    'subheader' => sprintf(
                        __('Manage QA, feedback and tasks directly in your <a href="%s" target="_blank">Atarim dashboard</a>', 'atarim-visual-collaboration'),
                        esc_url($app_url)
                    ),
                    'whoCan' => __('Who can collaborate', 'atarim-visual-collaboration'),
                    'whoCanTooltip' => __('Only these user roles are going to see the colalboration interface, unless Guest Mode is switched on.', 'atarim-visual-collaboration'),
                    'howTo' => __('How to collaborate', 'atarim-visual-collaboration'),
                    'howToTooltip' => __('Click the “Share” button when inside the collaboration interface or click the button below to explore all options.', 'atarim-visual-collaboration'),
                    'howToText1' => __('<b>Copy and share a quick link</b> with your clients or colleague. OR..', 'atarim-visual-collaboration'),
                    'howToText2' => __('<b>Add <code>?collab=true</code> to any link</b> on the website. OR..', 'atarim-visual-collaboration'),
                    'howToText3' => __('<b>Turn on Guest mode to show to all</b>, via "Manage Access" tab.', 'atarim-visual-collaboration'),
                    'sharingOptions' => __('Explore Sharing Options', 'atarim-visual-collaboration'),
                    'autoLoginAs' => __('1 click login from Atarim as', 'atarim-visual-collaboration'),
                    'autoLoginAsTooltip' => __('No more sharing passwords within the team. 1 click to login AND take you to any task across your fleet of websites.', 'atarim-visual-collaboration'),
                    'enableAutoLogin' => __('Enable Auto Login', 'atarim-visual-collaboration'),
                    'saveButton' => __('Save & Apply', 'atarim-visual-collaboration'),
                    'selectUserPlaceholder' => __('-- Select a user --', 'atarim-visual-collaboration'),
                    'enableDoit' => __('Enable "<b>Do it</b>" via Atarim AI', 'atarim-visual-collaboration'),
                    'enableDoitTooltip' => __('Click to execute the feedback with 1 click. “Do it” is not visible to clients & guests. We save a backup in case the AI makes mistakes.', 'atarim-visual-collaboration'),
                ]
            ]);

            // The key exchange and the activation run on this server (AVCF_Connect):
            // the page only opens Atarim's consent and asks whether it is done.
            wp_add_inline_script('avc-settings-script', <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    const btns = document.querySelectorAll('.avc-trigger-activate');
    if (!btns.length) return;

    const settings = window.avcSettings || {};
    let polling = false;
    // The attempt this page's consent link belongs to (AVCF_Connect::start).
    let attempt = '';

    // Some buttons (the first-run modal CTA) keep their own styled
    // label/icon; only change text on the rest.
    function setLabel(el, text) {
        if (!el.hasAttribute('data-keep-label')) { el.innerText = text; }
    }

    function setButtons(text, enabled) {
        btns.forEach(function (b) {
            setLabel(b, text);
            b.style.pointerEvents = enabled ? 'auto' : 'none';
        });
    }

    function ajax(action, fields) {
        const body = new FormData();
        body.append('action', action);
        body.append('avc_nonce', settings.avc_nonce);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(settings.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json(); });
    }

    // With a sign-in key (AVCF_Connect::mint_login_key), the site itself, where
    // the collaboration layer signs in with it: the connect never gives this
    // browser an Atarim session on the site otherwise. Without one, the plain
    // settings page, not a reload: that would keep ?atarim_connect=1 and show
    // the connect screen again on a site that is now connected.
    function showConnected(loginKey) {
        if (loginKey && settings.avc_collab_link) {
            window.location.replace(settings.avc_collab_link + '&read_key=' + encodeURIComponent(loginKey));
            return;
        }
        console.warn('Atarim connect: connected, but no sign-in key was issued, so the site opens signed out of Atarim.');
        const url = new URL(window.location.href);
        url.searchParams.delete('atarim_connect');
        window.location.replace(url.toString());
    }

    function prepare() {
        setButtons('Preparing...', false);
        return ajax('avcf_connect_start')
            .then(function (res) {
                if (!res.success || !res.data || !res.data.connect_url) {
                    throw new Error((res.data && res.data.message) || 'Failed to prepare activation.');
                }
                attempt = res.data.attempt || '';
                btns.forEach(function (b) { b.href = res.data.connect_url; });
                setButtons('Connect with Atarim', true);
            })
            .catch(function (err) {
                console.error('Atarim connect: prepare failed', err);
                setButtons('Connect with Atarim', true);
                alert(err.message || 'Could not prepare activation.');
            });
    }

    function fail(message) {
        polling = false;
        if (window.AtarimConnect) { window.AtarimConnect.setState('idle'); }
        alert(message || 'The connection did not finish. Please try again.');
        prepare();
    }

    function poll() {
        ajax('avcf_connect_poll', { attempt: attempt })
            .then(function (res) {
                if (!res.success) { fail(res.data && res.data.message); return; }
                if (res.data.status === 'connected') {
                    polling = false;
                    if (window.AtarimConnect) { window.AtarimConnect.setState('connected'); }
                    setTimeout(function () { showConnected(res.data.login_key); }, 2200);
                    return;
                }
                setTimeout(poll, 3000);
            })
            .catch(function () { setTimeout(poll, 3000); });
    }

    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            if (polling || !b.href || b.href.indexOf('/fetching/') === -1) return;
            polling = true;
            // Play the connecting animation in the first-run overlay (if present).
            if (window.AtarimConnect) { window.AtarimConnect.setState('connecting'); }
            setButtons('Authenticating...', false);
            setTimeout(poll, 3000);
        });
    });

    // Started in Atarim (V5 onboarding): the key comes from the wizard, so no
    // key pair is minted unless that hand-off fails or the admin cancels it.
    const handoffExpected = new URLSearchParams(window.location.search).get('atarim_connect') === '1'
        && !!window.opener && !!settings.appOrigin && !!document.getElementById('atc-confirm-btn') && !!window.AtarimConnect;
    let handoffReceived = false;
    if (handoffExpected) {
        setButtons('Connect with Atarim', false);
        // No answer from the wizard (another Atarim origin, a closed tab):
        // fall back to the manual connect.
        setTimeout(function () { if (!handoffReceived) prepare(); }, 8000);
    } else {
        prepare();
    }

    // Started in Atarim (V5 onboarding). The wizard hands over an approved key
    // by postMessage, never in a URL, and only Atarim's own origin, from the
    // window that opened this one, can do that. The admin still confirms the
    // workspace and account before anything connects.
    const confirmBtn = document.getElementById('atc-confirm-btn');
    const cancelBtn = document.getElementById('atc-confirm-cancel');
    const fromAtarim = new URLSearchParams(window.location.search).get('atarim_connect') === '1';

    if (fromAtarim && window.opener && settings.appOrigin && confirmBtn && window.AtarimConnect) {
        let offered = false;

        // Atarim's key was not used, so the connect goes on through this page's
        // own Connect, where the workspace is chosen in Atarim instead.
        function declineHandoff() {
            try {
                window.opener.postMessage({ type: 'atarim:connect-declined' }, settings.appOrigin);
            } catch (e) { /* the opener is gone */ }
        }

        window.addEventListener('message', function (event) {
            if (offered || event.origin !== settings.appOrigin || event.source !== window.opener) return;
            const data = event.data || {};
            if (data.type !== 'atarim:connect-key' || typeof data.key !== 'string') return;
            offered = true;
            handoffReceived = true;

            ajax('avcf_connect_offer', { connect_key: data.key })
                .then(function (res) {
                    if (!res.success) {
                        offered = false;
                        alert((res.data && res.data.message) || 'This connection could not be started.');
                        declineHandoff();
                        prepare();
                        return;
                    }
                    const workspace = res.data.workspace && res.data.workspace.name;
                    const account = res.data.account || {};
                    document.getElementById('atc-confirm-workspace').textContent = workspace || '';
                    document.getElementById('atc-confirm-account').textContent =
                        account.name ? account.name + ' (' + account.email + ')' : (account.email || '');
                    window.AtarimConnect.setState('confirm');
                    const workspaceRow = document.getElementById('atc-confirm-workspace-row');
                    if (workspaceRow && !workspace) workspaceRow.hidden = true;
                })
                .catch(function () {
                    offered = false;
                    alert('This connection could not be started.');
                    declineHandoff();
                    prepare();
                });
        });

        confirmBtn.addEventListener('click', function () {
            window.AtarimConnect.setState('connecting');
            ajax('avcf_connect_confirm')
                .then(function (res) {
                    if (!res.success) {
                        const error = new Error(res.data && res.data.message);
                        // Atarim did not answer: the key is kept for another click.
                        error.retry = !!(res.data && res.data.retry);
                        throw error;
                    }
                    window.AtarimConnect.setState('connected');
                    setTimeout(function () { showConnected(res.data && res.data.login_key); }, 2200);
                })
                .catch(function (err) {
                    alert((err && err.message) || 'The connection did not finish. Please try again.');
                    if (err && err.retry) {
                        window.AtarimConnect.setState('confirm');
                        return;
                    }
                    window.AtarimConnect.setState('idle');
                    declineHandoff();
                    prepare();
                });
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                ajax('avcf_connect_cancel').catch(function () {});
                window.AtarimConnect.setState('idle');
                declineHandoff();
                prepare();
            });
        }

        window.opener.postMessage({ type: 'atarim:connect-ready' }, settings.appOrigin);
    }
});
JS
            , 'after');
        }
    }
}

new AVCF_Settings();