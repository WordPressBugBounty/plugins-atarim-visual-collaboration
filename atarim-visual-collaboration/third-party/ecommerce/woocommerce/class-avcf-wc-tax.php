<?php
/**
 * WooCommerce — Tax MCP abilities.
 *
 * Tax rate CRUD is deliberately out of scope this round — managing
 * individual tax rates is its own sub-cluster (per-class rates, location
 * overrides, label management, priority handling). This file exposes
 * read-only rate listings plus full read/write of site-wide tax flags.
 *
 * Exposed abilities:
 *   atarim/list-tax-rates     Read tax rates from the woocommerce_tax_rates table.
 *   atarim/get-tax-settings   Read all site-wide tax flags.
 *   atarim/update-tax-settings Write site-wide tax flags. Rate CRUD deferred.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Tax extends AVCF_Abilities_Base {

    /**
     * @var AVCF_WC_Detector
     */
    private $detector;

    /**
     * Site-wide tax flags handled by this cluster.
     * Each entry is option_name => [ enum_or_null, default_value, description ].
     */
    private $tax_options = [
        'woocommerce_calc_taxes'           => [ [ 'yes', 'no' ], 'no', 'Enable tax calculations on the store.' ],
        'woocommerce_prices_include_tax'   => [ [ 'yes', 'no' ], 'no', 'Whether catalog prices are entered inclusive of tax.' ],
        'woocommerce_tax_based_on'         => [ [ 'shipping', 'billing', 'base' ], 'shipping', 'Which address the tax calculation uses.' ],
        'woocommerce_shipping_tax_class'   => [ null, 'inherit', 'Tax class to apply to shipping. "inherit" matches cart contents.' ],
        'woocommerce_tax_round_at_subtotal'=> [ [ 'yes', 'no' ], 'no', 'Round tax at the subtotal level (instead of per-line).' ],
        'woocommerce_tax_display_shop'     => [ [ 'incl', 'excl' ], 'excl', 'Catalog price display: inclusive or exclusive of tax.' ],
        'woocommerce_tax_display_cart'     => [ [ 'incl', 'excl' ], 'excl', 'Cart/checkout price display.' ],
        'woocommerce_tax_total_display'    => [ [ 'single', 'itemized' ], 'itemized', 'Tax total display: single line or itemized by class.' ],
        'woocommerce_price_display_suffix' => [ null, '', 'Display suffix shown after prices (e.g. "incl. VAT").' ],
    ];

    public function __construct() {
        $this->detector = new AVCF_WC_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wc_is_available() ) {
            return;
        }

        // ---- list-tax-rates ----
        wp_register_ability( 'atarim/list-tax-rates', [
            'label'               => 'List Tax Rates',
            'description'         => 'Returns tax rates from the woocommerce_tax_rates table, optionally filtered by tax class. Tax rates are: country code + state code + postcode + city + rate + priority + name. The "standard" tax class is referenced as an empty string in the database; other classes are referenced by slug (e.g. "reduced-rate"). Rate CRUD (create / update / delete individual rates) is out of scope for this round.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'tax_class' => [
                        'type'        => 'string',
                        'description' => 'Filter to one tax class. "standard" (the default) is stored as an empty string in the DB; pass "standard" here for convenience.',
                    ],
                    'country' => [
                        'type'        => 'string',
                        'description' => 'ISO country code (e.g. "US").',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => 500,
                        'default'     => 100,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'total'   => [ 'type' => 'integer' ],
                    'rates'   => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wpdb;
                $limit = isset( $input['limit'] ) ? max( 1, min( 500, (int) $input['limit'] ) ) : 100;

                $where  = [];
                $params = [];

                if ( isset( $input['tax_class'] ) ) {
                    $tc = (string) $input['tax_class'];
                    if ( $tc === 'standard' ) $tc = '';
                    $where[]  = 'tax_rate_class = %s';
                    $params[] = $tc;
                }
                if ( ! empty( $input['country'] ) ) {
                    $where[]  = 'tax_rate_country = %s';
                    $params[] = strtoupper( (string) $input['country'] );
                }

                $sql = "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates";
                if ( ! empty( $where ) ) {
                    $sql .= ' WHERE ' . implode( ' AND ', $where );
                }
                $sql .= ' ORDER BY tax_rate_country ASC, tax_rate_state ASC, tax_rate_priority ASC';
                $sql .= ' LIMIT ' . (int) $limit;

                $rows = $params
                    ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
                    : $wpdb->get_results( $sql, ARRAY_A );

                if ( ! is_array( $rows ) ) {
                    return [ 'success' => false, 'message' => 'Query failed.' ];
                }

                $rates = array_map( function( $r ) {
                    return [
                        'id'        => (int) $r['tax_rate_id'],
                        'country'   => (string) $r['tax_rate_country'],
                        'state'     => (string) $r['tax_rate_state'],
                        'rate'      => (string) $r['tax_rate'],
                        'name'      => (string) $r['tax_rate_name'],
                        'priority'  => (int) $r['tax_rate_priority'],
                        'compound'  => (bool) (int) $r['tax_rate_compound'],
                        'shipping'  => (bool) (int) $r['tax_rate_shipping'],
                        'class'     => ( $r['tax_rate_class'] === '' ) ? 'standard' : (string) $r['tax_rate_class'],
                        'order'     => (int) $r['tax_rate_order'],
                    ];
                }, $rows );

                return [
                    'success' => true,
                    'total'   => count( $rates ),
                    'rates'   => $rates,
                    'message' => sprintf( '%d tax rate(s) returned.', count( $rates ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-tax-settings ----
        wp_register_ability( 'atarim/get-tax-settings', [
            'label'               => 'Get Tax Settings',
            'description'         => 'Returns site-wide WooCommerce tax flags: whether tax calc is enabled, prices include tax, tax basis (shipping/billing/base), display formats for shop and cart, total display mode (single/itemized), tax classes available, and the price display suffix. Per-rate tax data is in list-tax-rates.',
            'category'            => 'atarim',
            'input_schema'        => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'settings' => [ 'type' => 'object' ],
                    'classes'  => [ 'type' => 'array' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $settings = [];
                foreach ( $this->tax_options as $opt => $spec ) {
                    $settings[ $opt ] = get_option( $opt, $spec[1] );
                }
                $classes_raw = \WC_Tax::get_tax_classes();
                $classes = [ 'standard' ]; // standard is always present, stored as ''
                foreach ( $classes_raw as $c ) {
                    $classes[] = sanitize_title( $c );
                }

                return [
                    'success'  => true,
                    'settings' => $settings,
                    'classes'  => array_values( array_unique( $classes ) ),
                    'message'  => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-tax-settings ----
        wp_register_ability( 'atarim/update-tax-settings', [
            'label'               => 'Update Tax Settings',
            'description'         => 'Update site-wide WooCommerce tax flags. Partial — pass only what you want to change. Each flag validated against its enum where applicable. Does NOT manage tax rates themselves (per-country/state rate CRUD is deferred to a future PR).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'woocommerce_calc_taxes'           => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
                    'woocommerce_prices_include_tax'   => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
                    'woocommerce_tax_based_on'         => [ 'type' => 'string', 'enum' => [ 'shipping', 'billing', 'base' ] ],
                    'woocommerce_shipping_tax_class'   => [ 'type' => 'string', 'description' => 'Tax class slug. "inherit" to match cart contents.' ],
                    'woocommerce_tax_round_at_subtotal'=> [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
                    'woocommerce_tax_display_shop'     => [ 'type' => 'string', 'enum' => [ 'incl', 'excl' ] ],
                    'woocommerce_tax_display_cart'     => [ 'type' => 'string', 'enum' => [ 'incl', 'excl' ] ],
                    'woocommerce_tax_total_display'    => [ 'type' => 'string', 'enum' => [ 'single', 'itemized' ] ],
                    'woocommerce_price_display_suffix' => [ 'type' => 'string' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $updated = [];
                foreach ( $this->tax_options as $opt => $spec ) {
                    if ( ! array_key_exists( $opt, $input ) ) continue;
                    $value = (string) $input[ $opt ];
                    $enum  = $spec[0];
                    if ( $enum !== null && ! in_array( $value, $enum, true ) ) {
                        return [ 'success' => false, 'updated' => $updated, 'message' => sprintf( '"%s" is not a valid value for %s. Allowed: %s.', $value, $opt, implode( ', ', $enum ) ) ];
                    }
                    update_option( $opt, $value );
                    $updated[] = $opt;
                }
                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'updated' => [], 'message' => 'No tax flags provided to update.' ];
                }
                return [
                    'success' => true,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated tax settings: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }
}
