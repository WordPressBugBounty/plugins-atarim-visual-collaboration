<?php
/**
 * Detects Yoast SEO (free) and Yoast SEO Premium.
 *
 * Single point of contact for whether Yoast is active, its version, and
 * whether Premium is present (the redirect manager is Premium-only). If Yoast
 * renames its sentinel classes between versions, only this file changes.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Yoast_Detector {

    public function __construct() {}

    /**
     * Whether Yoast SEO (free or premium) is active.
     */
    public function avcf_yoast_is_available() {
        return defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Meta' );
    }

    /**
     * Yoast version string, or ''.
     */
    public function avcf_yoast_version() {
        return defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : '';
    }

    /**
     * Whether Yoast SEO Premium is active (required for redirects).
     */
    public function avcf_yoast_is_premium() {
        return defined( 'WPSEO_PREMIUM_VERSION' ) && class_exists( 'WPSEO_Redirect_Manager' );
    }
}
