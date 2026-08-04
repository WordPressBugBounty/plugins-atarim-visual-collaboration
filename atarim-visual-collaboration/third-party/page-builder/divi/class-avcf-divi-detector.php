<?php
/**
 * Detects Divi and whether the Divi 5 (block-based) builder is enabled.
 *
 * The Divi ability cluster supports the Divi 5 module model ONLY (content stored
 * as `divi/*` WordPress blocks in post_content). Divi 4 `[et_pb_*]` shortcodes
 * are NOT supported — on a Divi 4 site avcf_divi_d5_enabled() returns false and
 * the abilities refuse.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Divi_Detector {

    public function __construct() {}

    /** Divi (theme or builder plugin) present at all. */
    public function avcf_divi_is_available() {
        return defined( 'ET_CORE_VERSION' )
            || defined( 'ET_BUILDER_VERSION' )
            || function_exists( 'et_builder_d5_enabled' )
            || class_exists( '\ET\Builder\Packages\ModuleLibrary\ModuleRegistration' );
    }

    /** Divi 5 block-based builder enabled (required for this cluster). */
    public function avcf_divi_d5_enabled() {
        return function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled();
    }

    public function avcf_divi_version() {
        if ( defined( 'ET_BUILDER_VERSION' ) ) { return ET_BUILDER_VERSION; }
        if ( defined( 'ET_CORE_VERSION' ) ) { return ET_CORE_VERSION; }
        return '';
    }

    /** Whether the Divi 5 module registry is queryable. */
    public function avcf_divi_registry_available() {
        return class_exists( '\ET\Builder\Packages\ModuleLibrary\ModuleRegistration' );
    }
}
