<?php
/**
 * Formidable Forms detector.
 *
 * Detects core (Lite) and Pro. Lite has no entry storage; Pro stores entries.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Formidable_Detector {

    public function avcf_formidable_is_available() {
        return class_exists( 'FrmForm' );
    }

    public function avcf_formidable_is_pro() {
        return $this->avcf_formidable_is_available() && class_exists( 'FrmProDb' );
    }
}
