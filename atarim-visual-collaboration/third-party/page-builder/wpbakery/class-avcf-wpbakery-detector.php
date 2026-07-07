<?php
/**
 * Detects WPBakery Page Builder (js_composer).
 *
 * NOTE: This is the classic shortcode-based WPBakery (WPBMap / [vc_*] shortcodes
 * in post_content), NOT the separate "Visual Composer Website Builder" product.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WPBakery_Detector {

    public function __construct() {}

    public function avcf_wpbakery_is_available() {
        return class_exists( '\WPBMap' ) || defined( 'WPB_VC_VERSION' ) || function_exists( 'vc_map' );
    }

    public function avcf_wpbakery_version() {
        return defined( 'WPB_VC_VERSION' ) ? WPB_VC_VERSION : '';
    }

    /** Whether the WPBMap shortcode registry is queryable. */
    public function avcf_wpbakery_registry_available() {
        return class_exists( '\WPBMap' ) && method_exists( '\WPBMap', 'getAllShortCodes' );
    }
}
