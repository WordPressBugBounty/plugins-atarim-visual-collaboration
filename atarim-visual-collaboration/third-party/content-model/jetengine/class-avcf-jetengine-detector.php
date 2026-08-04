<?php
/**
 * Detects JetEngine and its relevant data stores.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_JetEngine_Detector {

    public function __construct() {}

    public function avcf_je_is_available() {
        return class_exists( 'Jet_Engine' ) && function_exists( 'jet_engine' );
    }

    public function avcf_je_version() {
        if ( function_exists( 'jet_engine' ) && is_object( jet_engine() ) && method_exists( jet_engine(), 'get_version' ) ) {
            return (string) jet_engine()->get_version();
        }
        return '';
    }

    /** CCT (Custom Content Types) module. */
    public function avcf_je_has_cct() {
        if ( ! $this->avcf_je_is_available() ) {
            return false;
        }
        $je = jet_engine();
        if ( ! isset( $je->modules ) || ! is_object( $je->modules ) || ! method_exists( $je->modules, 'get_module' ) ) {
            return false;
        }
        return (bool) $je->modules->get_module( 'custom-content-types' );
    }

    public function avcf_je_has_meta_boxes() {
        return $this->avcf_je_is_available() && isset( jet_engine()->meta_boxes ) && is_object( jet_engine()->meta_boxes );
    }

    public function avcf_je_has_options_pages() {
        return $this->avcf_je_is_available() && isset( jet_engine()->options_pages ) && is_object( jet_engine()->options_pages );
    }
}
