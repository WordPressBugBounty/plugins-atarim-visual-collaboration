<?php
/**
 * Detects Pods.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Pods_Detector {

    public function __construct() {}

    public function avcf_pods_is_available() {
        return function_exists( 'pods_api' ) && function_exists( 'pods' ) && ( defined( 'PODS_VERSION' ) || class_exists( 'PodsInit' ) );
    }

    public function avcf_pods_version() {
        return defined( 'PODS_VERSION' ) ? PODS_VERSION : '';
    }
}
