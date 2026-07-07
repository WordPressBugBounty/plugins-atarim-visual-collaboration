<?php
/**
 * Detects Mosaic (builder).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Mosaic_Detector {

    public function __construct() {}

    public function avcf_mosaic_is_available() {
        return class_exists( '\Mosaic\Database\MosaicDB' )
            || defined( 'MOSAIC_VERSION' )
            || defined( 'MOSAIC_PLUGIN_FILE' );
    }

    public function avcf_mosaic_version() {
        return defined( 'MOSAIC_VERSION' ) ? (string) constant( 'MOSAIC_VERSION' ) : '';
    }

    /** Fractional ordering requires Mosaic's own class — writes can't be placed without it. */
    public function avcf_mosaic_ordering_available() {
        return class_exists( '\Mosaic\Common\FractionalIndex' );
    }
}
