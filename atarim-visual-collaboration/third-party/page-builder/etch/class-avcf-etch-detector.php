<?php
/**
 * Detects Etch (builder).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Etch_Detector {

    public function __construct() {}

    public function avcf_etch_is_available() {
        return class_exists( '\Etch\Plugin' )
            || defined( 'ETCH_VERSION' )
            || defined( 'ETCH_PLUGIN_FILE' )
            || defined( 'ETCH_PLUGIN_DIR' );
    }

    public function avcf_etch_version() {
        if ( class_exists( '\Etch\Plugin' ) && method_exists( '\Etch\Plugin', 'get_plugin_version' ) ) {
            try { return (string) call_user_func( [ '\Etch\Plugin', 'get_plugin_version' ] ); } catch ( \Throwable $e ) {}
        }
        return defined( 'ETCH_VERSION' ) ? ETCH_VERSION : '';
    }
}
