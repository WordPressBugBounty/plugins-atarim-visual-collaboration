<?php
/**
 * Contact Form 7 detector.
 *
 * CF7 is detected by the WPCF7 class / wpcf7_contact_form function. CF7 stores
 * no submissions itself; the entry side is owned by the separate Flamingo
 * cluster (third-party/forms/flamingo/), which detects and reads Flamingo
 * independently.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_CF7_Detector {

    public function avcf_cf7_is_available() {
        return class_exists( 'WPCF7' ) || function_exists( 'wpcf7_contact_form' );
    }
}
