<?php
/**
 * Forminator detector.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Forminator_Detector {

    public function avcf_forminator_is_available() {
        return class_exists( 'Forminator_API' );
    }
}
