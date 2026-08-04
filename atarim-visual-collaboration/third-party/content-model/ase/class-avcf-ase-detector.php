<?php
/**
 * Detects ASE (Admin and Site Enhancements — custom fields / CPT / taxonomy
 * module). Definitions are stored as the asenha_cfgroup / asenha_cpt /
 * asenha_ctax post types.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ASE_Detector {

    public function __construct() {}

    public function avcf_ase_is_available() {
        return post_type_exists( 'asenha_cfgroup' ) || post_type_exists( 'asenha_cpt' ) || class_exists( 'cfgroup_init' );
    }

    public function avcf_ase_version() {
        return defined( 'ASENHA_VERSION' ) ? ASENHA_VERSION : '';
    }

    public function avcf_ase_has_field_groups() {
        return post_type_exists( 'asenha_cfgroup' );
    }
    public function avcf_ase_has_post_types() {
        return post_type_exists( 'asenha_cpt' );
    }
    public function avcf_ase_has_taxonomies() {
        return post_type_exists( 'asenha_ctax' );
    }
}
