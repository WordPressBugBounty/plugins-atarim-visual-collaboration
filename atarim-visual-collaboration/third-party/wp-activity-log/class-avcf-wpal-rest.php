<?php
/**
 * REST endpoints for WP Activity Log statistics.
 *
 * Exposes WPAL yesterday counts and (premium) drill-down links to Atarim's
 * dashboard backend over the existing atarim/v1 REST namespace. The MCP
 * surface registers its own abilities in doit/class-avcf-mcp.php and does
 * not depend on these endpoints.
 *
 * Authentication is server-to-server via the X-Atarim-Token header, validated
 * against the secret token saved during site connection. WordPress user
 * sessions are not used because the Atarim app calls these endpoints from
 * its own backend.
 *
 * Endpoints:
 *   GET  /wp-json/atarim/v1/wpal/stats   Yesterday's counts plus availability.
 *   GET  /wp-json/atarim/v1/wpal/links   Premium drill-down links (or fallback).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

class AVCF_WPAL_REST {

    /**
     * @var AVCF_WPAL_Stats
     */
    private $stats;

    /**
     * @var AVCF_MCP_Auth
     */
    private $auth;

    public function __construct() {
        $this->stats = new AVCF_WPAL_Stats();
        $this->auth  = new AVCF_MCP_Auth();
        add_action( 'rest_api_init', array( $this, 'avcf_wpal_register_routes' ) );
    }

    public function avcf_wpal_register_routes() {
        $permission_callback = array( $this, 'avcf_wpal_permission_check' );

        register_rest_route( 'atarim/v1', '/wpal/stats', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'avcf_wpal_handle_stats' ),
            'permission_callback' => $permission_callback,
        ) );

        register_rest_route( 'atarim/v1', '/wpal/links', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'avcf_wpal_handle_links' ),
            'permission_callback' => $permission_callback,
        ) );
    }

    /**
     * Authenticate the request against the Atarim secret token.
     *
     * These endpoints are called server-to-server by Atarim's backend, so
     * no WordPress user session is available. Auth is by shared secret in
     * the X-Atarim-Token header, validated with hash_equals against the
     * token saved during site connection (avc_atarim_secret_token).
     */
    public function avcf_wpal_permission_check( WP_REST_Request $request ) {
        if ( $this->auth->avcf_mcp_validate_request() ) {
            return true;
        }

        return new WP_Error(
            'avcf_wpal_invalid_token',
            __( 'Invalid or missing Atarim authentication token.', 'atarim-visual-collaboration' ),
            array( 'status' => 401 )
        );
    }

    public function avcf_wpal_handle_stats( WP_REST_Request $request ) {
        return new WP_REST_Response( $this->stats->avcf_wpal_get_yesterday_counts(), 200 );
    }

    public function avcf_wpal_handle_links( WP_REST_Request $request ) {
        return new WP_REST_Response( $this->stats->avcf_wpal_get_yesterday_links(), 200 );
    }
}

new AVCF_WPAL_REST();
