<?php
/**
 * Detects Elementor and Elementor Pro.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Elementor_Detector {

    public function __construct() {}

    /**
     * Whether Elementor (free or pro) is active.
     */
    public function avcf_elementor_is_available() {
        return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
    }

    /**
     * Elementor version, or ''.
     */
    public function avcf_elementor_version() {
        return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
    }

    /**
     * Whether Elementor Pro is active.
     */
    public function avcf_elementor_is_pro() {
        return defined( 'ELEMENTOR_PRO_VERSION' );
    }
}
