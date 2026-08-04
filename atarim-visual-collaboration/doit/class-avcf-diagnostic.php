<?php
/**
 * Diagnostic REST endpoint.
 *
 * Exposes GET /wp-json/atarim/v1/diagnostic for the Atarim backend to check
 * whether this site meets MCP requirements (WordPress version, PHP version).
 * Always returns HTTP 200 — the response body's "mcp_available" flag is
 * how the dashboard knows the actual health state.
 *
 * Auth is the same X-Atarim-Token check used by the MCP endpoint, so the
 * version info isn't world-readable. Unauthenticated requests get 401.
 *
 * Lives outside the MCP adapter conditional in the bootstrap so it works
 * even on sites where MCP itself doesn't (which is the point — when MCP
 * doesn't work, the diagnostic endpoint is how the dashboard finds out
 * why).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Diagnostic {

    /**
     * Minimum required WordPress version. WP 6.9 ships the Abilities API
     * which the MCP adapter requires.
     */
    const REQUIRED_WP = '6.9';

    /**
     * Minimum required PHP version. WordPress core's floor is 7.4 but the
     * MCP adapter uses PHP 8.0 syntax (typed properties, constructor promotion),
     * so 8.0 is the effective floor for MCP-enabled sites.
     */
    const REQUIRED_PHP = '8.0';

    /**
     * @var AVCF_MCP_Auth
     */
    private $auth;

    /**
     * @var AVCF_Functions
     */
    private $function;

    public function __construct() {
        $this->auth     = new AVCF_MCP_Auth();
        $this->function = new AVCF_Functions();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            'atarim/v1',
            '/diagnostic',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_diagnostic' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        );
    }

    /**
     * Gate the diagnostic endpoint behind the same X-Atarim-Token check
     * as the MCP endpoint. Returning detailed environment info without auth
     * would help attackers (knowing WP 6.3.1 narrows their CVE search).
     */
    public function check_permission() {
        if ( ! $this->auth->avcf_mcp_validate_request() ) {
            return new WP_Error(
                'avcf_diagnostic_unauthorized',
                'Your token is invalid. Reactivate the site to set a valid token.',
                [ 'status' => 401 ]
            );
        }
        return true;
    }

    /**
     * Return the diagnostic payload.
     *
     * Always HTTP 200 — the request itself succeeded. The "mcp_available"
     * boolean in the body indicates whether MCP can actually be used. Same
     * pattern as a typical /health endpoint.
     */
    public function handle_diagnostic() {
        $wp_actual  = (string) get_bloginfo( 'version' );
        $php_actual = PHP_VERSION;

        $wp_met  = version_compare( $wp_actual, self::REQUIRED_WP, '>=' );
        $php_met = version_compare( $php_actual, self::REQUIRED_PHP, '>=' );

        $plugin_version = '';
        if ( defined( 'AVCF_PLUGIN_BASE' ) ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin_file = WP_PLUGIN_DIR . '/' . AVCF_PLUGIN_BASE;
            if ( file_exists( $plugin_file ) ) {
                $data = get_plugin_data( $plugin_file, false, false );
                $plugin_version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
            }
        }

        $response = [
            'mcp_available'  => ( $wp_met && $php_met ),
            'requirements'   => [
                'wordpress' => [
                    'required' => self::REQUIRED_WP,
                    'actual'   => $wp_actual,
                    'met'      => $wp_met,
                ],
                'php' => [
                    'required' => self::REQUIRED_PHP,
                    'actual'   => $php_actual,
                    'met'      => $php_met,
                ],
            ],
            'plugin_version' => $plugin_version,
            'site_url'       => get_site_url(),
        ];

        return new WP_REST_Response( $response, 200 );
    }
}
