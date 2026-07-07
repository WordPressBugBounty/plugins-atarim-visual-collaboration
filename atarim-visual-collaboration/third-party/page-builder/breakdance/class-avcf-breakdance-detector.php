<?php
/**
 * Detects Breakdance (builder).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Breakdance_Detector {

    public function __construct() {}

    public function avcf_breakdance_is_available() {
        return defined( '__BREAKDANCE_VERSION' )
            || defined( 'BREAKDANCE_VERSION' )
            || function_exists( '\Breakdance\Data\get_meta' )
            || class_exists( '\Breakdance\Elements\Element' );
    }

    public function avcf_breakdance_version() {
        if ( defined( '__BREAKDANCE_VERSION' ) ) { return constant( '__BREAKDANCE_VERSION' ); }
        if ( defined( 'BREAKDANCE_VERSION' ) ) { return constant( 'BREAKDANCE_VERSION' ); }
        return '';
    }
}
