<?php
/**
 * WPForms detector.
 *
 * Detects WPForms (Lite or Pro). The Pro check is used internally by the
 * adapter to gate entry-related methods, since WPForms Lite stores no entries.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WPForms_Detector {

    public function avcf_wpforms_is_available() {
        return function_exists( 'wpforms' );
    }

    public function avcf_wpforms_is_pro() {
        if ( ! $this->avcf_wpforms_is_available() ) {
            return false;
        }
        // Pro registers \WPForms\Pro\Pro; Lite does not. WPFORMS_VERSION_DB
        // is also a Pro-only constant in older versions.
        return class_exists( '\\WPForms\\Pro\\Pro' ) || defined( 'WPFORMS_VERSION_DB' );
    }
}
