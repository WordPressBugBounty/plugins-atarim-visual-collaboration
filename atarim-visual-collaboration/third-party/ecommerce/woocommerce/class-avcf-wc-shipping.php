<?php
/**
 * WooCommerce — Shipping MCP abilities (read-only).
 *
 * Read access to shipping zones, methods, and per-method instance settings.
 * Writes are deliberately deferred — shipping configuration is its own
 * sub-cluster (per-method config, zone CRUD, shipping classes) and deserves
 * a focused PR.
 *
 * Exposed abilities:
 *   atarim/list-shipping-zones    All zones with their methods + costs summary.
 *   atarim/get-shipping-zone      Single zone with full method instance detail.
 *   atarim/list-shipping-methods  All shipping method TYPES available on the site
 *                                 (flat_rate, free_shipping, local_pickup, plus any
 *                                 registered by plugins).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Shipping extends AVCF_Abilities_Base {

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

        // ---- list-shipping-zones ----
        wp_register_ability( 'atarim/list-shipping-zones', [
            'label'               => 'List Shipping Zones',
            'description'         => 'Returns all configured shipping zones, including the implicit "Rest of the World" zone (zone_id = 0). Each zone includes: regions covered (countries / states / postcodes), and the methods attached to it with their type, title, and enabled flag. For per-method instance settings (cost formulas, class rates) use get-shipping-zone.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'total'   => [ 'type' => 'integer' ],
                    'zones'   => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
                    return [ 'success' => false, 'message' => 'WC_Shipping_Zones class not available.' ];
                }

                $zones_raw = \WC_Shipping_Zones::get_zones();
                $zones = [];

                // Configured zones.
                foreach ( $zones_raw as $z ) {
                    $zone_obj = \WC_Shipping_Zones::get_zone( (int) $z['id'] );
                    $zones[]  = $this->avcf_format_zone_summary( $zone_obj );
                }

                // Implicit "Rest of the World" — zone_id 0. Always present.
                $row = \WC_Shipping_Zones::get_zone( 0 );
                if ( $row ) {
                    $zones[] = $this->avcf_format_zone_summary( $row );
                }

                return [
                    'success' => true,
                    'total'   => count( $zones ),
                    'zones'   => $zones,
                    'message' => sprintf( '%d zone(s) configured.', count( $zones ) ),
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

        // ---- get-shipping-zone ----
        wp_register_ability( 'atarim/get-shipping-zone', [
            'label'               => 'Get Shipping Zone',
            'description'         => 'Full detail for a single shipping zone by ID, including per-method instance settings (cost, fee, class rates, conditional logic where the method exposes it). Pass zone_id = 0 for the "Rest of the World" zone.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'zone_id' => [
                        'type'        => 'integer',
                        'description' => 'Zone ID. 0 for "Rest of the World".',
                        'minimum'     => 0,
                    ],
                ],
                'required' => [ 'zone_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'zone'    => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
                    return [ 'success' => false, 'message' => 'WC_Shipping_Zones class not available.' ];
                }

                $zone_id = isset( $input['zone_id'] ) ? (int) $input['zone_id'] : -1;
                if ( $zone_id < 0 ) {
                    return [ 'success' => false, 'message' => 'zone_id is required.' ];
                }

                $zone = \WC_Shipping_Zones::get_zone( $zone_id );
                if ( ! $zone ) {
                    return [ 'success' => false, 'message' => sprintf( 'Shipping zone %d not found.', $zone_id ) ];
                }

                $methods = [];
                foreach ( $zone->get_shipping_methods( false ) as $method ) {
                    $settings = [];
                    if ( method_exists( $method, 'instance_settings' ) ) {
                        // instance_settings is the per-instance settings array, exposed on most methods.
                        $settings = (array) $method->instance_settings;
                    } elseif ( property_exists( $method, 'instance_settings' ) ) {
                        $settings = (array) $method->instance_settings;
                    }
                    $methods[] = [
                        'instance_id'  => (int) $method->instance_id,
                        'method_id'    => (string) $method->id,
                        'method_title' => (string) $method->method_title,
                        'title'        => (string) $method->title,
                        'enabled'      => ( $method->is_enabled() ),
                        'settings'     => $settings,
                    ];
                }

                $zone_data = [
                    'id'        => (int) $zone->get_id(),
                    'name'      => (string) $zone->get_zone_name(),
                    'order'     => (int) $zone->get_zone_order(),
                    'locations' => array_map( function( $loc ) {
                        return [ 'code' => (string) $loc->code, 'type' => (string) $loc->type ];
                    }, (array) $zone->get_zone_locations() ),
                    'methods'   => $methods,
                ];

                return [ 'success' => true, 'zone' => $zone_data, 'message' => 'OK.' ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- list-shipping-methods ----
        wp_register_ability( 'atarim/list-shipping-methods', [
            'label'               => 'List Shipping Method Types',
            'description'         => 'Returns the catalogue of shipping method TYPES registered on the site (e.g. flat_rate, free_shipping, local_pickup, plus any added by plugins like Table Rate Shipping). This is the type registry, not the per-zone configured instances — use list-shipping-zones for the instances actually attached to zones.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'total'   => [ 'type' => 'integer' ],
                    'methods' => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $shipping = WC()->shipping();
                if ( ! $shipping ) {
                    return [ 'success' => false, 'message' => 'WC shipping not initialized.' ];
                }

                $methods = $shipping->get_shipping_methods();
                $rows = [];
                foreach ( $methods as $method_id => $method ) {
                    $rows[] = [
                        'method_id'       => (string) $method_id,
                        'method_title'    => (string) ( $method->method_title ?? '' ),
                        'method_description' => (string) ( $method->method_description ?? '' ),
                        'supports_instances' => (bool) ( $method->supports( 'shipping-zones' ) ?? false ),
                    ];
                }

                return [
                    'success' => true,
                    'total'   => count( $rows ),
                    'methods' => $rows,
                    'message' => sprintf( '%d shipping method type(s) registered.', count( $rows ) ),
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
    }

    /**
     * Format a zone summary (no per-method instance settings).
     *
     * @param \WC_Shipping_Zone $zone
     * @return array
     */
    private function avcf_format_zone_summary( $zone ) {
        $methods = [];
        foreach ( $zone->get_shipping_methods( false ) as $method ) {
            $methods[] = [
                'instance_id'  => (int) $method->instance_id,
                'method_id'    => (string) $method->id,
                'method_title' => (string) $method->method_title,
                'title'        => (string) $method->title,
                'enabled'      => ( $method->is_enabled() ),
            ];
        }
        return [
            'id'             => (int) $zone->get_id(),
            'name'           => (string) $zone->get_zone_name(),
            'order'          => (int) $zone->get_zone_order(),
            'location_count' => count( (array) $zone->get_zone_locations() ),
            'method_count'   => count( $methods ),
            'methods'        => $methods,
        ];
    }
}
