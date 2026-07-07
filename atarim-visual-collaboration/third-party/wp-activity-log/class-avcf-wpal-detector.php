<?php
/**
 * Detects the WP Activity Log plugin and its edition.
 *
 * This is the single point of contact for checking whether WPAL is installed,
 * which edition is active, and which features are available. If Melapress
 * renames or moves classes in a future WPAL version, this file is the only
 * place that needs to change.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

class AVCF_WPAL_Detector {

    /**
     * Fully-qualified WPAL class used to retrieve activity log data.
     */
    const WPAL_OCCURRENCES_CLASS = '\WSAL\Entities\Occurrences_Entity';

    /**
     * Method only present in the Premium edition.
     * Used as the sentinel for edition detection.
     */
    const WPAL_PREMIUM_METHOD = 'get_last24_links';

    public function __construct() {}

    /**
     * Whether WP Activity Log is installed and bootstrapped on this site.
     */
    public function avcf_wpal_is_available() {
        return class_exists( self::WPAL_OCCURRENCES_CLASS );
    }

    /**
     * Whether the Premium edition is active.
     * Premium ships an additional method on Occurrences_Entity that the free
     * edition does not, so method_exists is a reliable detection signal.
     */
    public function avcf_wpal_is_premium() {
        if ( ! $this->avcf_wpal_is_available() ) {
            return false;
        }
        return method_exists( self::WPAL_OCCURRENCES_CLASS, self::WPAL_PREMIUM_METHOD );
    }

    /**
     * Edition label suitable for display or API output.
     * Returns 'premium', 'free', or 'none'.
     */
    public function avcf_wpal_edition() {
        if ( ! $this->avcf_wpal_is_available() ) {
            return 'none';
        }
        return $this->avcf_wpal_is_premium() ? 'premium' : 'free';
    }
}
