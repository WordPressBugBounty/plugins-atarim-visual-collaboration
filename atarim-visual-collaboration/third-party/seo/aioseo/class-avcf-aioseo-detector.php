<?php
/**
 * Detects All in One SEO (AIOSEO) and AIOSEO Pro.
 *
 * AIOSEO stores per-post/term SEO in its own database tables, accessed via
 * its ORM models. Redirects are a Pro feature. This detector centralises the
 * presence/edition checks; the ability class probes for the specific model
 * classes before using them.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_AIOSEO_Detector {

    public function __construct() {}

    /**
     * Whether AIOSEO (free or pro) is active.
     */
    public function avcf_aioseo_is_available() {
        return function_exists( 'aioseo' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' );
    }

    /**
     * AIOSEO version, or ''.
     */
    public function avcf_aioseo_version() {
        return defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : '';
    }

    /**
     * Whether AIOSEO Pro with the Redirects feature is available.
     */
    public function avcf_aioseo_has_redirects() {
        return class_exists( '\AIOSEO\Plugin\Pro\Models\Redirect' )
            || class_exists( '\AIOSEO\Plugin\Pro\Redirects\Models\Redirect' );
    }

    /**
     * The available redirect model class name, or '' if none.
     */
    public function avcf_aioseo_redirect_model() {
        if ( class_exists( '\AIOSEO\Plugin\Pro\Redirects\Models\Redirect' ) ) {
            return '\AIOSEO\Plugin\Pro\Redirects\Models\Redirect';
        }
        if ( class_exists( '\AIOSEO\Plugin\Pro\Models\Redirect' ) ) {
            return '\AIOSEO\Plugin\Pro\Models\Redirect';
        }
        return '';
    }
}
