<?php
/**
 * Detects Rank Math SEO.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_RankMath_Detector {

    public function __construct() {}

    /**
     * Whether Rank Math is active.
     */
    public function avcf_rankmath_is_available() {
        return class_exists( 'RankMath' ) || function_exists( 'rank_math' );
    }

    /**
     * Rank Math version, or ''.
     */
    public function avcf_rankmath_version() {
        return defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '';
    }
}
