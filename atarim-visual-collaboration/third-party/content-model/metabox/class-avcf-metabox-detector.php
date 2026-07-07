<?php
/**
 * Detects Meta Box and its relevant extensions.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_MetaBox_Detector {

    public function __construct() {}

    /** Core Meta Box plugin. */
    public function avcf_mb_is_available() {
        return class_exists( 'RW_Meta_Box' ) || defined( 'RWMB_VER' );
    }

    public function avcf_mb_version() {
        return defined( 'RWMB_VER' ) ? RWMB_VER : '';
    }

    /** MB Custom Post Types / Custom Taxonomies (stores builder CPTs). */
    public function avcf_mb_has_cpt() {
        return post_type_exists( 'mb-post-type' );
    }
    public function avcf_mb_has_taxonomy_builder() {
        return post_type_exists( 'mb-taxonomy' );
    }

    /** MB Settings Page. */
    public function avcf_mb_has_settings_page() {
        return post_type_exists( 'mb-settings-page' );
    }

    /** MB Builder (field groups stored as the "meta-box" CPT). */
    public function avcf_mb_has_builder() {
        return post_type_exists( 'meta-box' );
    }

    /** MB Relationships. */
    public function avcf_mb_has_relationships() {
        return class_exists( 'MB_Relationships_API' );
    }
}
