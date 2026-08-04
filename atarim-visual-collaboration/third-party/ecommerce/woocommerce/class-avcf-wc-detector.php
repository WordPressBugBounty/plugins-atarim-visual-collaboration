<?php
/**
 * Detects the WooCommerce plugin and surrounding extensions.
 *
 * Single point of contact for checking whether WooCommerce is installed,
 * whether HPOS (custom order tables) is enabled, and which extension
 * plugins are active. If WooCommerce renames or moves functions between
 * major versions, this file is the only place that needs to change.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Detector {

    /**
     * Sentinel function present in any WooCommerce installation.
     */
    const WC_SENTINEL_FUNCTION = 'wc_get_products';

    public function __construct() {}

    /**
     * Whether WooCommerce is installed and bootstrapped on this site.
     */
    public function avcf_wc_is_available() {
        return function_exists( self::WC_SENTINEL_FUNCTION );
    }

    /**
     * WooCommerce version string, or empty if not installed.
     */
    public function avcf_wc_version() {
        if ( ! $this->avcf_wc_is_available() ) {
            return '';
        }
        return defined( 'WC_VERSION' ) ? WC_VERSION : '';
    }

    /**
     * Whether HPOS (High-Performance Order Storage / custom order tables)
     * is enabled. Order-related queries must route differently depending
     * on whether HPOS or the legacy post-table storage is active.
     */
    public function avcf_wc_is_hpos_enabled() {
        if ( ! $this->avcf_wc_is_available() ) {
            return false;
        }
        if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return false;
        }
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Whether WooCommerce Subscriptions is active.
     */
    public function avcf_wc_is_subscriptions_active() {
        return class_exists( 'WC_Subscriptions' );
    }

    /**
     * Whether WooCommerce Bookings is active.
     */
    public function avcf_wc_is_bookings_active() {
        return class_exists( 'WC_Bookings' );
    }

    /**
     * Whether WooCommerce Memberships is active.
     */
    public function avcf_wc_is_memberships_active() {
        return class_exists( 'WC_Memberships' );
    }
}
