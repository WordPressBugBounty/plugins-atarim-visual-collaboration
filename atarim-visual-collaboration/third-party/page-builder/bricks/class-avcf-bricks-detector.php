<?php
/**
 * Detects Bricks (builder theme).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Bricks_Detector {

    public function __construct() {}

    public function avcf_bricks_is_available() {
        return defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Database' );
    }

    public function avcf_bricks_version() {
        return defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '';
    }
}
