<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_MCP_Auth {

    private $function;

    public function __construct() {
        $this->function = new AVCF_Functions();
    }

    /**
     * Get the MCP token saved by Atarim backend during site connection.
     */
    public function avcf_mcp_get_token() {
        return $this->function->avcf_get_setting_data( 'avc_atarim_secret_token' );
    }

    /**
     * Validate the incoming MCP request.
     * Checks X-Atarim-Token header against token saved during site connection.
     */
    public function avcf_mcp_validate_request() {
        $incoming_token = '';
        if ( isset( $_SERVER['HTTP_X_ATARIM_TOKEN'] ) ) {
            $incoming_token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ATARIM_TOKEN'] ) );
        }

        $stored_token = $this->avcf_mcp_get_token();

        if ( empty( $incoming_token ) || empty( $stored_token ) ) {
            return false;
        }

        return hash_equals( $stored_token, $incoming_token );
    }

}