<?php
/**
 * Gravity Forms detector.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Gravity_Detector {

    public function avcf_gravity_is_available() {
        return class_exists( 'GFAPI' ) && class_exists( 'GFForms' );
    }
}
