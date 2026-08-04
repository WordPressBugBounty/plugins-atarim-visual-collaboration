<?php
/**
 * Deactivation feedback for the plugins.php screen.
 *
 * Loads a small React bundle on the Plugins list table that intercepts the
 * plugin's own "Deactivate" link and shows an Elementor-style feedback modal.
 * On submit we fire the feedback at the Atarim API (fire-and-forget) and
 * immediately let WordPress deactivate the plugin. "Skip" deactivates without
 * sending anything.
 *
 * Note: WordPress only exposes the "Delete" link for inactive plugins, whose
 * code does not run — so the delete-time data choice cannot be driven from
 * here. The destructive cleanup still lives in uninstall.php and stays dormant
 * (defaults to keeping data) until a preference is wired up in the future.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Offboarding {

    const NONCE_ACTION = 'avc_offboarding';

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_avcf_deactivation_feedback', array( $this, 'handle_deactivation_feedback' ) );
    }

    /**
     * Only load on the Plugins list table (plugins.php), never the network one.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'plugins.php' !== $hook ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Reuse the bundled wp-components styles for parity with the settings UI.
        wp_enqueue_style( 'wp-components', includes_url( 'css/dist/components/style.min.css' ), array(), AVCF_VERSION );
        wp_enqueue_style(
            'avc-offboarding-style',
            AVCF_PLUGIN_URL . 'assets/css/offboarding.css',
            array(),
            AVCF_VERSION
        );

        wp_enqueue_script(
            'avc-offboarding',
            AVCF_PLUGIN_URL . 'assets/build/offboarding.js',
            array(),
            AVCF_VERSION,
            true
        );

        $reasons = array(
            array(
                'value' => 'no_longer_need',
                'label' => __( 'I no longer need the plugin', 'atarim-visual-collaboration' ),
            ),
            array(
                'value' => 'found_better',
                'label' => __( 'I found a better plugin', 'atarim-visual-collaboration' ),
            ),
            array(
                'value' => 'couldnt_work',
                'label' => __( "I couldn't get the plugin to work", 'atarim-visual-collaboration' ),
            ),
            array(
                'value' => 'temporary',
                'label' => __( "It's a temporary deactivation", 'atarim-visual-collaboration' ),
            ),
            array(
                'value' => 'other',
                'label' => __( 'Other', 'atarim-visual-collaboration' ),
            ),
        );

        wp_localize_script(
            'avc-offboarding',
            'avcOffboarding',
            array(
                'ajaxurl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
                'pluginBasename' => AVCF_PLUGIN_BASE,
                'logoUrl'        => AVCF_PLUGIN_URL . 'images/logo.svg',
                'reasons'        => $reasons,
                'otherValue'     => 'other',
                'i18n'           => array(
                    'deactivateTitle'    => __( 'Quick Feedback', 'atarim-visual-collaboration' ),
                    'deactivatePrompt'   => __( 'If you have a moment, please share why you are deactivating Atarim:', 'atarim-visual-collaboration' ),
                    'otherPlaceholder'   => __( 'Please tell us more...', 'atarim-visual-collaboration' ),
                    'submitDeactivate'   => __( 'Submit & Deactivate', 'atarim-visual-collaboration' ),
                    'skipDeactivate'     => __( 'Skip & Deactivate', 'atarim-visual-collaboration' ),
                    'otherRequired'      => __( 'Please add a few words so we can improve.', 'atarim-visual-collaboration' ),
                    'cancel'             => __( 'Cancel', 'atarim-visual-collaboration' ),
                ),
            )
        );
    }

    /**
     * Verify the modal nonce sent via header or POST body.
     *
     * @return bool
     */
    private function verify_request() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $nonce = '';
        if ( isset( $_SERVER['HTTP_X_AVC_NONCE'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AVC_NONCE'] ) );
        } elseif ( isset( $_POST['nonce'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
        }

        return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
    }

    /**
     * Proxy the deactivation feedback to the Atarim API.
     *
     * The client does not wait for this; it fires the request (keepalive) and
     * then lets WordPress deactivate. We still respond fast so keepalive can
     * settle. The outbound call is made server-side so no token/CORS leaks to
     * the browser.
     */
    public function handle_deactivation_feedback() {
        if ( ! $this->verify_request() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
        }

        $function = new AVCF_Functions();

        $reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        $comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

        $payload = array(
            'reason'         => $reason,
            'comment'        => $comment,
            'site_url'       => AVCF_HOME_URL,
            'site_id'        => $function->avcf_get_setting_data( 'avc_site_id' ),
            'plugin_version' => AVCF_VERSION,
            'source'         => 'wordpress',
        );

        // Endpoint does not exist yet; that is expected. We do not block on it.
        $function->avcf_make_api_call(
            AVCF_CRM_API . 'plugin-deactivation-reason',
            $payload,
            '',
            $function->avcf_get_setting_data( 'avc_atarim_secret_token' ),
            'POST',
            true
        );

        wp_send_json_success( array( 'message' => 'Feedback received.' ) );
    }
}

new AVCF_Offboarding();
