<?php
/**
 * WooCommerce — Catalog helpers MCP abilities.
 *
 * Read-only store orientation plus the one product-category gap the generic
 * taxonomy abilities don't cover: WooCommerce-specific term meta (category
 * thumbnail image and display type). Category/tag create/update/delete itself
 * is handled by the generic create-term / update-term / delete-term abilities
 * (product_cat / product_tag are normal taxonomies).
 *
 * Exposed abilities:
 *   atarim/get-store-settings          Base location, currency, units, tax/decimals.
 *   atarim/list-product-types          Registered product types (simple/variable/...).
 *   atarim/list-product-statuses       Post statuses applicable to products.
 *   atarim/set-product-category-image  Set/clear a product category's image + display type.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Catalog extends AVCF_Abilities_Base {

    /** @var AVCF_WC_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WC_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wc_is_available() ) {
            return;
        }
        $this->register_store_settings();
        $this->register_product_types();
        $this->register_product_statuses();
        $this->register_category_image();
    }

    private function can() {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' );
    }
    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }

    private function register_store_settings() {
        $self = $this;
        wp_register_ability( 'atarim/get-store-settings', [
            'label'        => 'Get Store Settings',
            'description'  => 'Read core WooCommerce store settings (read-only): base country/state, selling currency and symbol position, weight and dimension units, price decimal count and separators, and whether tax calculation and prices-include-tax are enabled.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : [ 'country' => '', 'state' => '' ];
                return [ 'success' => true, 'settings' => [
                    'base_country'        => isset( $base['country'] ) ? $base['country'] : '',
                    'base_state'          => isset( $base['state'] ) ? $base['state'] : '',
                    'currency'            => get_woocommerce_currency(),
                    'currency_symbol'     => html_entity_decode( get_woocommerce_currency_symbol() ),
                    'currency_position'   => get_option( 'woocommerce_currency_pos' ),
                    'thousand_separator'  => wc_get_price_thousand_separator(),
                    'decimal_separator'   => wc_get_price_decimal_separator(),
                    'price_decimals'      => wc_get_price_decimals(),
                    'weight_unit'         => get_option( 'woocommerce_weight_unit' ),
                    'dimension_unit'      => get_option( 'woocommerce_dimension_unit' ),
                    'taxes_enabled'       => wc_tax_enabled(),
                    'prices_include_tax'  => wc_prices_include_tax(),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_product_types() {
        $self = $this;
        wp_register_ability( 'atarim/list-product-types', [
            'label'        => 'List Product Types',
            'description'  => 'List the registered WooCommerce product types (slug => label), e.g. simple, variable, grouped, external, plus any added by extensions (subscription, bundle, etc.). Use this to know which type values create-product / update-product accept on this site.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'types' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = function_exists( 'wc_get_product_types' ) ? wc_get_product_types() : [];
                return [ 'success' => true, 'types' => (array) $types, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_product_statuses() {
        $self = $this;
        wp_register_ability( 'atarim/list-product-statuses', [
            'label'        => 'List Product Statuses',
            'description'  => 'List the post statuses that apply to products (slug => label): publish, draft, pending, private, and trash, plus any custom statuses registered for products.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'statuses' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $statuses = function_exists( 'get_post_statuses' ) ? get_post_statuses() : [ 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private' ];
                if ( ! isset( $statuses['trash'] ) ) {
                    $statuses['trash'] = 'Trash';
                }
                return [ 'success' => true, 'statuses' => (array) $statuses, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_category_image() {
        $self = $this;
        wp_register_ability( 'atarim/set-product-category-image', [
            'label'        => 'Set Product Category Image',
            'description'  => 'Set or clear a product category\'s image and/or display type — the WooCommerce-specific term meta that the generic term abilities don\'t handle. Pass image_id (an attachment ID) to set the image, or null to clear it. display_type controls what the category archive shows.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'term_id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The product_cat term ID.' ],
                'image_id'     => [ 'type' => [ 'integer', 'null' ], 'description' => 'Attachment ID for the category image, or null to clear.' ],
                'display_type' => [ 'type' => 'string', 'enum' => [ 'default', 'products', 'subcategories', 'both' ], 'description' => 'What the category archive displays.' ],
            ], 'required' => [ 'term_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                if ( $term_id <= 0 ) { return [ 'success' => false, 'message' => 'term_id is required.' ]; }
                $term = get_term( $term_id, 'product_cat' );
                if ( ! $term || is_wp_error( $term ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Product category %d not found.', $term_id ) ];
                }
                $changed = [];
                if ( array_key_exists( 'image_id', $input ) ) {
                    if ( $input['image_id'] === null ) {
                        delete_term_meta( $term_id, 'thumbnail_id' );
                        $changed[] = 'image cleared';
                    } else {
                        $err = $self->avcf_validate_attachment_id( (int) $input['image_id'] );
                        if ( $err !== null ) {
                            return [ 'success' => false, 'message' => $err ];
                        }
                        update_term_meta( $term_id, 'thumbnail_id', (int) $input['image_id'] );
                        $changed[] = 'image set';
                    }
                }
                if ( isset( $input['display_type'] ) ) {
                    update_term_meta( $term_id, 'display_type', $input['display_type'] === 'default' ? '' : (string) $input['display_type'] );
                    $changed[] = 'display type set';
                }
                if ( empty( $changed ) ) {
                    return [ 'success' => true, 'message' => 'Nothing to update.' ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Updated category %d (%s).', $term_id, implode( ', ', $changed ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ] ],
        ] );
    }
}
