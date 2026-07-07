<?php
/**
 * Fluent Forms detector.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Fluent_Detector {

    public function avcf_fluent_is_available() {
        return defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' );
    }
}
