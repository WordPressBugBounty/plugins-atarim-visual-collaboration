<?php
/**
 * Detects ACPT (Advanced Custom Post Types).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ACPT_Detector {

    public function __construct() {}

    public function avcf_acpt_is_available() {
        return class_exists( '\ACPT\Includes\ACPT_Plugin' ) || defined( 'ACPT_PLUGIN_VERSION' );
    }

    public function avcf_acpt_version() {
        return defined( 'ACPT_PLUGIN_VERSION' ) ? ACPT_PLUGIN_VERSION : '';
    }

    /** Whether the repository layer is present (needed by all abilities). */
    public function avcf_acpt_has_repositories() {
        return class_exists( '\ACPT\Core\Repository\CustomPostTypeRepository' );
    }
}
