<?php
/**
 * Detects Advanced Custom Fields (ACF / ACF Pro) and its capabilities.
 *
 * Single point of contact for: whether ACF is active, free vs Pro edition,
 * version string, and whether the "internal post type" management APIs
 * (ACF's own custom post types, taxonomies, and UI options pages) are
 * available. Those APIs ship with ACF Pro 6.1+ (CPT/taxonomy) and 6.2+
 * (UI options pages); the management abilities require ACF Pro 6.5+, which
 * is the version where acf_get_internal_post_type_posts() and friends are
 * stable. If ACF moves these functions, this file is the only place that
 * needs to change.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ACF_Detector {

    /**
     * Sentinel present in any ACF installation (free or pro).
     */
    const ACF_SENTINEL_FUNCTION = 'acf_get_field_groups';

    public function __construct() {}

    /**
     * Whether ACF (free or pro) is installed and bootstrapped.
     */
    public function avcf_acf_is_available() {
        return function_exists( self::ACF_SENTINEL_FUNCTION ) && class_exists( 'ACF' );
    }

    /**
     * Edition: 'pro', 'free', or '' when ACF is absent.
     */
    public function avcf_acf_edition() {
        if ( ! $this->avcf_acf_is_available() ) {
            return '';
        }
        if ( ( defined( 'ACF_PRO' ) && ACF_PRO ) || class_exists( 'acf_pro' ) ) {
            return 'pro';
        }
        // Newer ACF exposes the 'pro' setting directly.
        if ( function_exists( 'acf_get_setting' ) && acf_get_setting( 'pro' ) ) {
            return 'pro';
        }
        return 'free';
    }

    /**
     * Whether ACF Pro is the active edition.
     */
    public function avcf_acf_is_pro() {
        return $this->avcf_acf_edition() === 'pro';
    }

    /**
     * ACF version string, or '' if not installed.
     */
    public function avcf_acf_version() {
        if ( ! $this->avcf_acf_is_available() ) {
            return '';
        }
        if ( function_exists( 'acf_get_setting' ) ) {
            $v = acf_get_setting( 'version' );
            if ( is_string( $v ) && $v !== '' ) {
                return $v;
            }
        }
        return defined( 'ACF_VERSION' ) ? ACF_VERSION : '';
    }

    /**
     * Whether the internal-post-type management APIs are available.
     *
     * These back the ACF CPT / taxonomy / options-page management abilities.
     * They are Pro-only and require a recent ACF (6.5+ in practice). We probe
     * for the function rather than parsing the version so a future rename is
     * caught here rather than fataling deep inside an ability.
     */
    public function avcf_acf_supports_internal_post_types() {
        return $this->avcf_acf_is_pro()
            && function_exists( 'acf_get_internal_post_type_posts' )
            && function_exists( 'acf_get_internal_post_type' );
    }
}
