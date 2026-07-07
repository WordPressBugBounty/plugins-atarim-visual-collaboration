<?php
/**
 * Ninja Forms detector.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Ninja_Detector {

    public function avcf_ninja_is_available() {
        return function_exists( 'Ninja_Forms' ) || class_exists( 'NF_Database_Models_Form' );
    }
}
