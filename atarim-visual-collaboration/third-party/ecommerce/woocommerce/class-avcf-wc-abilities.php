<?php
/**
 * WooCommerce — Abilities orchestrator.
 *
 * Single entry point that instantiates all the WooCommerce ability classes
 * and triggers their register() methods. Called from doit/class-avcf-mcp.php
 * inside the WC-available check, so this class itself doesn't need to
 * sentinel-check WooCommerce — but each ability class still does as
 * defence-in-depth in case ordering changes.
 *
 * Files dispatched:
 *   class-avcf-wc-products.php          5 abilities
 *   class-avcf-wc-variations.php        5 abilities
 *   class-avcf-wc-product-relations.php 3 abilities
 *   class-avcf-wc-orders.php            3 abilities
 *   class-avcf-wc-customers.php         4 abilities
 *   class-avcf-wc-coupons.php           5 abilities
 *   class-avcf-wc-shipping.php          3 abilities
 *   class-avcf-wc-tax.php               3 abilities
 *   class-avcf-wc-checkout-email.php    4 abilities
 *   class-avcf-wc-attributes.php        6 abilities
 *   class-avcf-wc-catalog.php           4 abilities
 *                                       ─────────────
 *                                       45 abilities total
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_WooCommerce extends AVCF_Abilities_Base {

    /**
     * @var AVCF_WC_Detector
     */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WC_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wc_is_available() ) {
            return;
        }

        ( new AVCF_WC_Products() )->register();
        ( new AVCF_WC_Variations() )->register();
        ( new AVCF_WC_Product_Relations() )->register();
        ( new AVCF_WC_Orders() )->register();
        ( new AVCF_WC_Customers() )->register();
        ( new AVCF_WC_Coupons() )->register();
        ( new AVCF_WC_Shipping() )->register();
        ( new AVCF_WC_Tax() )->register();
        ( new AVCF_WC_Checkout_Email() )->register();
        ( new AVCF_WC_Attributes() )->register();
        ( new AVCF_WC_Catalog() )->register();
    }
}
