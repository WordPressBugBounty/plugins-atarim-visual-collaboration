<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Connects this site to Atarim from the server.
 *
 * The browser only opens Atarim's consent page. The read key that redeems the
 * consent, the account token it yields and the licence Atarim returns all stay
 * on this server, so the site connects without a second trip through the
 * browser (and a second consent screen) to deliver the licence.
 */
class AVCF_Connect {
    const READ_KEY_TRANSIENT = 'avcf_connect_read_key_';
    const OFFER_TRANSIENT = 'avcf_connect_offer_';
    // Atarim keeps the key pair for 10 minutes.
    const TTL = 600;
    // An offered key is already approved, so it waits only for the click.
    const OFFER_TTL = 120;

    private $function;

    // Signs the admin's browser in to Atarim on this site (mint_login_key).
    private $login_key = '';

    public function __construct() {
        $this->function = new AVCF_Functions();
        add_action('wp_ajax_avcf_connect_start', [$this, 'start']);
        add_action('wp_ajax_avcf_connect_poll', [$this, 'poll']);
        add_action('wp_ajax_avcf_connect_offer', [$this, 'offer']);
        add_action('wp_ajax_avcf_connect_confirm', [$this, 'confirm']);
        add_action('wp_ajax_avcf_connect_cancel', [$this, 'cancel']);
    }

    /**
     * Mint a key pair and return the consent link. The read key is kept here.
     */
    public function start() {
        $this->authorise();

        $response = wp_remote_get(AVCF_CRM_API . 'wp/auth', [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (429 === wp_remote_retrieve_response_code($response)) {
            wp_send_json_error(['message' => __('Too many connection attempts from this server. Wait a minute and try again.', 'atarim-visual-collaboration')], 429);
        }

        $body = $this->json_body($response);

        if (empty($body['read_key']) || empty($body['write_key']) || empty($body['state'])) {
            wp_send_json_error(['message' => __('Could not prepare the connection. Please try again.', 'atarim-visual-collaboration')], 502);
        }

        // Lowercase on purpose: transient names are case-sensitive in a
        // persistent object cache, and the id comes back through a POST field.
        $attempt = strtolower(wp_generate_password(20, false, false));
        set_transient($this->transient_key($attempt), sanitize_text_field($body['read_key']), self::TTL);

        $connect_url = AVCF_APP_SITE_URL . '/fetching/?_from=wp_plugin&from_wp=true'
            . '&write_key=' . rawurlencode($body['write_key'])
            . '&state=' . rawurlencode($body['state']);

        wp_send_json_success(['connect_url' => $connect_url, 'attempt' => $attempt]);
    }

    /**
     * Once the user has accepted in Atarim, redeem the key and activate the site.
     */
    public function poll() {
        $this->authorise();

        $attempt = $this->posted_attempt();
        $read_key = $attempt ? get_transient($this->transient_key($attempt)) : '';
        if (empty($read_key)) {
            wp_send_json_error(['message' => __('This connection expired. Please start it again.', 'atarim-visual-collaboration')], 410);
        }

        $this->allow_time_to_finish();
        $result = $this->redeem($read_key);

        // Not accepted yet, or a blip on the way: the browser asks again.
        if ('pending' === $result) {
            wp_send_json_success(['status' => 'pending']);
        }

        // The key is single use from here on, whatever happened.
        delete_transient($this->transient_key($attempt));

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['status' => 'connected', 'login_key' => $this->login_key]);
    }

    /**
     * A key Atarim already approved, handed over by the onboarding wizard in
     * the window that opened this page. Nothing connects yet: this only names
     * the Atarim account and workspace so the admin can confirm them.
     */
    public function offer() {
        $this->authorise();

        $key = isset($_POST['connect_key']) ? sanitize_text_field(wp_unslash($_POST['connect_key'])) : '';
        if (! preg_match('/^wp_read_[A-Za-z0-9]{20,100}$/', $key)) {
            wp_send_json_error(['message' => __('This connection link is not valid.', 'atarim-visual-collaboration')], 400);
        }

        $response = wp_remote_get(AVCF_CRM_API . 'wp/connect-sessions/describe?connect_key=' . rawurlencode($key), [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $body = $this->json_body($response);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response) || empty($body['account'])) {
            wp_send_json_error(['message' => __('This connection has expired. Start it again from Atarim.', 'atarim-visual-collaboration')], 400);
        }

        set_transient($this->offer_key(), $key, self::OFFER_TTL);

        wp_send_json_success([
            'account' => [
                'name' => sanitize_text_field($body['account']['name'] ?? ''),
                'email' => sanitize_email($body['account']['email'] ?? ''),
            ],
            'workspace' => ! empty($body['workspace']['name'])
                ? ['name' => sanitize_text_field($body['workspace']['name'])]
                : null,
        ]);
    }

    /**
     * The admin confirmed the offered workspace and account: connect now.
     */
    public function confirm() {
        $this->authorise();

        $key = get_transient($this->offer_key());

        if (empty($key)) {
            wp_send_json_error(['message' => __('This connection has expired. Start it again from Atarim.', 'atarim-visual-collaboration')], 410);
        }

        $this->allow_time_to_finish();
        $result = $this->redeem($key);

        // Atarim approved this key before it was offered, so no answer now is a
        // blip on the way: keep the key for another click.
        if ('pending' === $result) {
            wp_send_json_error([
                'message' => __('Atarim did not answer. Click Connect site to try again.', 'atarim-visual-collaboration'),
                'retry' => true,
            ], 503);
        }

        // The key is single use from here on, whatever happened.
        delete_transient($this->offer_key());

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['status' => 'connected', 'login_key' => $this->login_key]);
    }

    /**
     * "Not you? Cancel" on the confirm step: drop the offered key now.
     */
    public function cancel() {
        $this->authorise();
        delete_transient($this->offer_key());
        wp_send_json_success(['status' => 'cancelled']);
    }

    /**
     * Trade an approved read key for a token and activate the site with it.
     *
     * @return true|'pending'|WP_Error
     */
    private function redeem($read_key) {
        $response = wp_remote_get(AVCF_CRM_API . 'wp/pollForAccessToken?read_key=' . rawurlencode($read_key), [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $code = (int) wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || 202 === $code || 429 === $code || $code >= 500) {
            return 'pending';
        }

        $body = $this->json_body($response);
        if (200 !== $code || empty($body['access_token'])) {
            return new WP_Error(
                'avcf_connect',
                ! empty($body['error'])
                    ? sanitize_text_field($body['error'])
                    : __('Atarim did not approve the connection. Please try again.', 'atarim-visual-collaboration')
            );
        }

        $workspace_id = isset($body['workspace_id']) ? absint($body['workspace_id']) : 0;

        $activated = $this->activate($body['access_token'], $workspace_id);
        if (true === $activated) {
            $this->login_key = $this->mint_login_key($body['access_token'], $workspace_id);
        }

        return $activated;
    }

    /**
     * A single-use key that signs the admin's browser in to Atarim on this site.
     *
     * Atarim's session on a site is a cookie the browser keeps for that site
     * alone, set when a page on the site trades an approved key for a token.
     * The consent happens on Atarim's domain and the connect on this server, so
     * neither sets it, and the first visit after connecting would be signed out.
     * The token that just connected the site approves one more key, and the
     * settings page hands it to the collaboration layer on the site, which signs
     * in with it. The token itself never reaches the browser.
     *
     * Best effort: the site is connected either way, so a failure is logged and
     * the admin signs in from the collaboration layer instead.
     */
    private function mint_login_key($access_token, $workspace_id) {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
        ];

        $keys = $this->json_body(wp_remote_get(AVCF_CRM_API . 'wp/auth', [
            'timeout' => 15,
            'headers' => $headers,
        ]));
        if (empty($keys['read_key']) || empty($keys['write_key']) || empty($keys['state'])) {
            error_log('[atarim] Connected, but no sign-in key could be prepared: wp/auth returned no key pair.');
            return '';
        }

        $approval = ['write_key' => $keys['write_key'], 'state' => $keys['state'], 'confirmed' => true];
        if ($workspace_id) {
            $approval['workspace_id'] = $workspace_id;
        }

        $response = wp_remote_post(AVCF_CRM_API . 'wp/processAuth', [
            'timeout' => 15,
            'headers' => $headers + ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($approval),
        ]);
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            error_log('[atarim] Connected, but the sign-in key was not approved: ' . (is_wp_error($response)
                ? $response->get_error_message()
                : 'HTTP ' . wp_remote_retrieve_response_code($response)));
            return '';
        }

        return sanitize_text_field($keys['read_key']);
    }

    /**
     * @return true|WP_Error
     */
    private function activate($access_token, $workspace_id) {
        $url = AVCF_CRM_API . 'site/activate' . ($workspace_id ? '?workspace_id=' . $workspace_id : '');

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'body' => wp_json_encode([
                // Atarim registers and dedupes on the base64 home URL.
                'url' => base64_encode(AVCF_HOME_URL),
                'name' => get_bloginfo('name'),
            ]),
        ]);

        if (is_wp_error($response)) {
            // Atarim may have recorded the site before the answer was lost. A
            // fresh connect merges into that site and delivers the licence.
            return new WP_Error('avcf_connect', __('Atarim did not answer while finishing the connection. Click Connect again to finish.', 'atarim-visual-collaboration'));
        }

        $body = $this->json_body($response);
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        if (200 !== wp_remote_retrieve_response_code($response)
            || empty($data['wpf_license'])
            || empty($data['site_id'])
            || empty($data['mcp_token'])
        ) {
            return new WP_Error(
                'avcf_connect',
                ! empty($body['message'])
                    ? sanitize_text_field($body['message'])
                    : __('Atarim could not connect this site.', 'atarim-visual-collaboration')
            );
        }

        $license = base64_decode(sanitize_text_field($data['wpf_license']));
        if ('valid' !== $license) {
            return new WP_Error(
                'avcf_connect',
                __('This Atarim account cannot connect this site. Ask the owner of its Atarim workspace to connect it.', 'atarim-visual-collaboration')
            );
        }

        $this->function->avcf_store_connection(
            $license,
            sanitize_text_field($data['site_id']),
            sanitize_text_field($data['mcp_token'])
        );

        return true;
    }

    /**
     * Redeeming the key and activating the site run back to back in one request,
     * and the activation call may take up to its 60 second timeout.
     */
    private function allow_time_to_finish() {
        if (function_exists('set_time_limit') && false === strpos((string) ini_get('disable_functions'), 'set_time_limit')) {
            @set_time_limit(120);
        }
    }

    private function authorise() {
        if (! $this->function->avcf_validate_nonce() || ! is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized access.'], 403);
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
    }

    private function transient_key($attempt) {
        return self::READ_KEY_TRANSIENT . get_current_user_id() . '_' . $attempt;
    }

    private function posted_attempt() {
        $attempt = isset($_POST['attempt']) ? (string) wp_unslash($_POST['attempt']) : '';

        // Validated as is, never normalised: a changed case would name a
        // different transient.
        return preg_match('/^[a-z0-9]{20}$/', $attempt) ? $attempt : '';
    }

    private function offer_key() {
        return self::OFFER_TRANSIENT . get_current_user_id();
    }

    private function json_body($response) {
        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : [];
    }
}

new AVCF_Connect();
