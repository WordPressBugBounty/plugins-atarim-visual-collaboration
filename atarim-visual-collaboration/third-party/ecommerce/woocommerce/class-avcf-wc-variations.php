<?php
/**
 * WooCommerce — Variations and sale-price scheduling abilities.
 *
 * Variable products are parents with no price of their own — each variation
 * is a child product with its own attributes, price, stock, and SKU. The
 * AI needs to address them as their own entities. This cluster covers
 * variation CRUD plus a convenience ability for scheduling sale prices
 * atomically across the four sale-date meta keys WooCommerce uses.
 *
 * Schedule-sale-price lives in this file (rather than products.php) because
 * sales naturally apply across all variations of a variable product, and the
 * implementation reuses variation-aware code paths.
 *
 * Exposed abilities:
 *   atarim/list-product-variations         All variations of a variable parent.
 *   atarim/create-product-variation        Add a new variation to a variable parent.
 *   atarim/update-product-variation        Update a single variation.
 *   atarim/bulk-update-product-variations  Update one field across many variations.
 *   atarim/schedule-sale-price             Atomic sale-price + start/end-date scheduling.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Variations extends AVCF_Abilities_Base {

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

        // ---- list-product-variations ----
        wp_register_ability( 'atarim/list-product-variations', [
            'label'               => 'List Product Variations',
            'description'         => 'Returns all variations for a variable product, with attribute combinations, prices, stock, and SKU per variation. The parent must be a variable product. Returns empty array if no variations are configured yet.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'parent_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                ],
                'required' => [ 'parent_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'parent_id'  => [ 'type' => 'integer' ],
                    'total'      => [ 'type' => 'integer' ],
                    'variations' => [ 'type' => 'array' ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $parent_id = isset( $input['parent_id'] ) ? (int) $input['parent_id'] : 0;
                if ( $parent_id <= 0 ) {
                    return [ 'success' => false, 'message' => 'parent_id is required.' ];
                }
                $parent = wc_get_product( $parent_id );
                if ( ! $parent ) {
                    return [ 'success' => false, 'message' => sprintf( 'Product %d not found.', $parent_id ) ];
                }
                if ( ! $parent->is_type( 'variable' ) ) {
                    return [ 'success' => false, 'parent_id' => $parent_id, 'message' => sprintf( 'Product %d is type "%s", not "variable". Only variable products have variations.', $parent_id, $parent->get_type() ) ];
                }

                $variation_ids = $parent->get_children();
                $variations = [];
                foreach ( $variation_ids as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;
                    $variations[] = [
                        'id'             => (int) $v->get_id(),
                        'sku'            => (string) $v->get_sku(),
                        'status'         => (string) $v->get_status(),
                        'attributes'     => (array) $v->get_attributes(),
                        'regular_price'  => (string) $v->get_regular_price(),
                        'sale_price'     => (string) $v->get_sale_price(),
                        'price'          => (string) $v->get_price(),
                        'on_sale'        => (bool) $v->is_on_sale(),
                        'stock_status'   => (string) $v->get_stock_status(),
                        'stock_quantity' => $v->get_stock_quantity(),
                        'manage_stock'   => (bool) $v->get_manage_stock(),
                        'weight'         => (string) $v->get_weight(),
                        'image_id'       => (int) $v->get_image_id(),
                    ];
                }

                return [
                    'success'    => true,
                    'parent_id'  => $parent_id,
                    'total'      => count( $variations ),
                    'variations' => $variations,
                    'message'    => sprintf( '%d variation(s) on product %d.', count( $variations ), $parent_id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- create-product-variation ----
        wp_register_ability( 'atarim/create-product-variation', [
            'label'               => 'Create Product Variation',
            'description'         => 'Add a variation to a variable product. The parent must already be type "variable" with attributes configured. attributes is a map of attribute_name => option_value matching the parent\'s variation attributes. Returns the new variation ID. After creating all needed variations, WC stores them as children of the parent automatically.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'parent_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'attributes' => [
                        'type'        => 'object',
                        'description' => 'Map of attribute_name => option_value for this variation. Attribute names should match those configured on the parent product as variation attributes.',
                        'additionalProperties' => [ 'type' => 'string' ],
                    ],
                    'sku' => [ 'type' => 'string' ],
                    'regular_price' => [ 'type' => [ 'string', 'number' ] ],
                    'sale_price' => [ 'type' => [ 'string', 'number' ] ],
                    'manage_stock' => [ 'type' => 'boolean' ],
                    'stock_quantity' => [ 'type' => 'integer' ],
                    'stock_status' => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
                    'weight' => [ 'type' => [ 'string', 'number' ] ],
                    'image_id' => [ 'type' => 'integer' ],
                    'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'private' ], 'default' => 'publish' ],
                ],
                'required' => [ 'parent_id', 'attributes' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'parent_id' => [ 'type' => 'integer' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $parent_id = isset( $input['parent_id'] ) ? (int) $input['parent_id'] : 0;
                if ( $parent_id <= 0 ) {
                    return [ 'success' => false, 'message' => 'parent_id is required.' ];
                }
                $parent = wc_get_product( $parent_id );
                if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'parent_id %d is not a variable product.', $parent_id ) ];
                }
                $attributes = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : [];
                if ( empty( $attributes ) ) {
                    return [ 'success' => false, 'message' => 'attributes is required and must contain at least one attribute_name => option_value pair.' ];
                }

                if ( ! class_exists( 'WC_Product_Variation' ) ) {
                    return [ 'success' => false, 'message' => 'WC_Product_Variation class not available.' ];
                }

                $variation = new \WC_Product_Variation();
                $variation->set_parent_id( $parent_id );
                $variation->set_status( isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish' );

                // Attribute keys may need the 'attribute_' prefix or pa_taxonomy slug; let WC handle.
                $clean_attrs = [];
                foreach ( $attributes as $name => $value ) {
                    $clean_attrs[ (string) $name ] = (string) $value;
                }
                $variation->set_attributes( $clean_attrs );

                if ( isset( $input['sku'] ) && $input['sku'] !== '' ) {
                    $sku_clean = wc_clean( (string) $input['sku'] );
                    if ( wc_get_product_id_by_sku( $sku_clean ) > 0 ) {
                        return [ 'success' => false, 'message' => sprintf( 'SKU "%s" is already in use.', $sku_clean ) ];
                    }
                    $variation->set_sku( $sku_clean );
                }
                if ( isset( $input['regular_price'] ) ) {
                    $variation->set_regular_price( (string) $input['regular_price'] );
                }
                if ( isset( $input['sale_price'] ) ) {
                    $variation->set_sale_price( (string) $input['sale_price'] );
                }
                if ( isset( $input['manage_stock'] ) ) {
                    $variation->set_manage_stock( (bool) $input['manage_stock'] );
                }
                if ( array_key_exists( 'stock_quantity', $input ) && $input['stock_quantity'] !== null ) {
                    $variation->set_stock_quantity( (int) $input['stock_quantity'] );
                }
                if ( isset( $input['stock_status'] ) ) {
                    $variation->set_stock_status( sanitize_key( $input['stock_status'] ) );
                }
                if ( isset( $input['weight'] ) ) {
                    $variation->set_weight( (string) $input['weight'] );
                }
                if ( ! empty( $input['image_id'] ) ) {
                    $variation->set_image_id( (int) $input['image_id'] );
                }

                try {
                    $id = $variation->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $e->getMessage() ];
                }
                if ( ! $id ) {
                    return [ 'success' => false, 'message' => 'Create failed: no ID returned.' ];
                }

                // Refresh parent so it picks up the new child.
                if ( class_exists( '\WC_Product_Variable' ) ) {
                    \WC_Product_Variable::sync( $parent_id );
                }

                return [
                    'success'   => true,
                    'id'        => (int) $id,
                    'parent_id' => $parent_id,
                    'message'   => sprintf( 'Variation %d created for parent %d.', $id, $parent_id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- update-product-variation ----
        wp_register_ability( 'atarim/update-product-variation', [
            'label'               => 'Update Product Variation',
            'description'         => 'Update a single variation by ID. Same partial-update model as update-product: pass only what you want to change. Cannot change parent_id (variations are bound to their parent at creation).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'sku' => [ 'type' => 'string' ],
                    'regular_price' => [ 'type' => [ 'string', 'number' ] ],
                    'sale_price' => [ 'type' => [ 'string', 'number' ] ],
                    'sale_price_dates_from' => [ 'type' => 'string' ],
                    'sale_price_dates_to' => [ 'type' => 'string' ],
                    'manage_stock' => [ 'type' => 'boolean' ],
                    'stock_quantity' => [ 'type' => 'integer' ],
                    'stock_status' => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
                    'weight' => [ 'type' => [ 'string', 'number' ] ],
                    'image_id' => [ 'type' => 'integer' ],
                    'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'private' ] ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                $v = wc_get_product( $id );
                if ( ! $v || ! $v->is_type( 'variation' ) ) {
                    return [ 'success' => false, 'message' => sprintf( '%d is not a product variation.', $id ) ];
                }

                $updated = [];

                if ( array_key_exists( 'sku', $input ) ) {
                    $new_sku = wc_clean( (string) $input['sku'] );
                    if ( $new_sku !== '' ) {
                        $existing = (int) wc_get_product_id_by_sku( $new_sku );
                        if ( $existing > 0 && $existing !== $id ) {
                            return [ 'success' => false, 'id' => $id, 'updated' => $updated, 'message' => sprintf( 'SKU "%s" is already in use by product %d.', $new_sku, $existing ) ];
                        }
                    }
                    $v->set_sku( $new_sku );
                    $updated[] = 'sku';
                }
                if ( array_key_exists( 'regular_price', $input ) ) {
                    $v->set_regular_price( (string) $input['regular_price'] );
                    $updated[] = 'regular_price';
                }
                if ( array_key_exists( 'sale_price', $input ) ) {
                    $v->set_sale_price( (string) $input['sale_price'] );
                    $updated[] = 'sale_price';
                }
                if ( array_key_exists( 'sale_price_dates_from', $input ) ) {
                    $ts = $input['sale_price_dates_from'] === '' ? null : strtotime( (string) $input['sale_price_dates_from'] );
                    $v->set_date_on_sale_from( $ts );
                    $updated[] = 'sale_price_dates_from';
                }
                if ( array_key_exists( 'sale_price_dates_to', $input ) ) {
                    $ts = $input['sale_price_dates_to'] === '' ? null : strtotime( (string) $input['sale_price_dates_to'] );
                    $v->set_date_on_sale_to( $ts );
                    $updated[] = 'sale_price_dates_to';
                }
                if ( array_key_exists( 'manage_stock', $input ) ) {
                    $v->set_manage_stock( (bool) $input['manage_stock'] );
                    $updated[] = 'manage_stock';
                }
                if ( array_key_exists( 'stock_quantity', $input ) ) {
                    $v->set_stock_quantity( $input['stock_quantity'] === null ? null : (int) $input['stock_quantity'] );
                    $updated[] = 'stock_quantity';
                }
                if ( array_key_exists( 'stock_status', $input ) ) {
                    $v->set_stock_status( sanitize_key( $input['stock_status'] ) );
                    $updated[] = 'stock_status';
                }
                if ( array_key_exists( 'weight', $input ) ) {
                    $v->set_weight( (string) $input['weight'] );
                    $updated[] = 'weight';
                }
                if ( array_key_exists( 'image_id', $input ) ) {
                    $v->set_image_id( (int) $input['image_id'] );
                    $updated[] = 'image_id';
                }
                if ( array_key_exists( 'status', $input ) ) {
                    $v->set_status( sanitize_key( $input['status'] ) );
                    $updated[] = 'status';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                try {
                    $v->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => $updated, 'message' => 'Save failed: ' . $e->getMessage() ];
                }

                // Re-sync the parent so aggregate price/stock recalculates.
                $parent_id = (int) $v->get_parent_id();
                if ( $parent_id > 0 && class_exists( '\WC_Product_Variable' ) ) {
                    \WC_Product_Variable::sync( $parent_id );
                }

                return [
                    'success' => true,
                    'id'      => $id,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- bulk-update-product-variations ----
        wp_register_ability( 'atarim/bulk-update-product-variations', [
            'label'               => 'Bulk Update Product Variations',
            'description'         => 'Apply the same field update across many variations. Two modes: (1) "parent_id" + "value" applies to ALL variations of the parent; (2) "variation_ids" + "value" applies to the listed variations. Allowed fields: regular_price, sale_price, sale_price_dates_from, sale_price_dates_to, stock_status, stock_quantity, manage_stock, status. Per-id success tracking. Re-syncs the parent product once at the end.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'parent_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Apply to all variations of this parent. Mutually exclusive with variation_ids.' ],
                    'variation_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'maxItems' => 500, 'description' => 'Specific variation IDs to update. Mutually exclusive with parent_id.' ],
                    'field' => [
                        'type'        => 'string',
                        'description' => 'Field to set on every targeted variation.',
                        'enum'        => [ 'regular_price', 'sale_price', 'sale_price_dates_from', 'sale_price_dates_to', 'stock_status', 'stock_quantity', 'manage_stock', 'status' ],
                    ],
                    'value' => [ 'description' => 'New value. Type depends on the field.' ],
                ],
                'required' => [ 'field', 'value' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'mode'      => [ 'type' => 'string' ],
                    'attempted' => [ 'type' => 'integer' ],
                    'updated'   => [ 'type' => 'integer' ],
                    'failed'    => [ 'type' => 'integer' ],
                    'results'   => [ 'type' => 'array' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'attempted', 'updated', 'failed', 'results', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $field = isset( $input['field'] ) ? sanitize_key( $input['field'] ) : '';
                if ( $field === '' || ! array_key_exists( 'value', $input ) ) {
                    return [ 'success' => false, 'mode' => 'unknown', 'attempted' => 0, 'updated' => 0, 'failed' => 0, 'results' => [], 'message' => 'field and value are required.' ];
                }

                $has_parent = isset( $input['parent_id'] ) && (int) $input['parent_id'] > 0;
                $has_ids    = isset( $input['variation_ids'] ) && is_array( $input['variation_ids'] ) && ! empty( $input['variation_ids'] );
                if ( $has_parent && $has_ids ) {
                    return [ 'success' => false, 'mode' => 'unknown', 'attempted' => 0, 'updated' => 0, 'failed' => 0, 'results' => [], 'message' => 'Pass either parent_id or variation_ids, not both.' ];
                }
                if ( ! $has_parent && ! $has_ids ) {
                    return [ 'success' => false, 'mode' => 'unknown', 'attempted' => 0, 'updated' => 0, 'failed' => 0, 'results' => [], 'message' => 'Either parent_id or variation_ids is required.' ];
                }

                $variation_ids = [];
                $mode = '';
                if ( $has_parent ) {
                    $mode = 'parent';
                    $parent_id = (int) $input['parent_id'];
                    $parent    = wc_get_product( $parent_id );
                    if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
                        return [ 'success' => false, 'mode' => $mode, 'attempted' => 0, 'updated' => 0, 'failed' => 0, 'results' => [], 'message' => sprintf( 'parent_id %d is not a variable product.', $parent_id ) ];
                    }
                    $variation_ids = array_map( 'intval', (array) $parent->get_children() );
                } else {
                    $mode = 'ids';
                    $variation_ids = array_values( array_unique( array_map( 'intval', $input['variation_ids'] ) ) );
                }

                if ( empty( $variation_ids ) ) {
                    return [ 'success' => true, 'mode' => $mode, 'attempted' => 0, 'updated' => 0, 'failed' => 0, 'results' => [], 'message' => 'No variations to process.' ];
                }

                $value   = $input['value'];
                $results = [];
                $updated = 0;
                $failed  = 0;
                $touched_parents = [];

                foreach ( $variation_ids as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v || ! $v->is_type( 'variation' ) ) {
                        $results[] = [ 'id' => $vid, 'success' => false, 'message' => 'Not a variation.' ];
                        $failed++;
                        continue;
                    }

                    try {
                        switch ( $field ) {
                            case 'regular_price':
                                $v->set_regular_price( (string) $value );
                                break;
                            case 'sale_price':
                                $v->set_sale_price( (string) $value );
                                break;
                            case 'sale_price_dates_from':
                                $ts = ( $value === '' || $value === null ) ? null : strtotime( (string) $value );
                                $v->set_date_on_sale_from( $ts );
                                break;
                            case 'sale_price_dates_to':
                                $ts = ( $value === '' || $value === null ) ? null : strtotime( (string) $value );
                                $v->set_date_on_sale_to( $ts );
                                break;
                            case 'stock_status':
                                $v->set_stock_status( sanitize_key( (string) $value ) );
                                break;
                            case 'stock_quantity':
                                $v->set_stock_quantity( $value === null ? null : (int) $value );
                                break;
                            case 'manage_stock':
                                $v->set_manage_stock( (bool) $value );
                                break;
                            case 'status':
                                $v->set_status( sanitize_key( (string) $value ) );
                                break;
                        }
                        $v->save();
                        $touched_parents[ (int) $v->get_parent_id() ] = true;
                        $results[] = [ 'id' => $vid, 'success' => true, 'message' => 'OK.' ];
                        $updated++;
                    } catch ( \Exception $e ) {
                        $results[] = [ 'id' => $vid, 'success' => false, 'message' => $e->getMessage() ];
                        $failed++;
                    }
                }

                // Re-sync touched parents once.
                if ( class_exists( '\WC_Product_Variable' ) ) {
                    foreach ( array_keys( $touched_parents ) as $pid ) {
                        if ( $pid > 0 ) {
                            \WC_Product_Variable::sync( $pid );
                        }
                    }
                }

                return [
                    'success'   => ( $failed === 0 ),
                    'mode'      => $mode,
                    'attempted' => count( $variation_ids ),
                    'updated'   => $updated,
                    'failed'    => $failed,
                    'results'   => $results,
                    'message'   => sprintf( '%d of %d updated, %d failed (field: %s).', $updated, count( $variation_ids ), $failed, $field ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- schedule-sale-price ----
        wp_register_ability( 'atarim/schedule-sale-price', [
            'label'               => 'Schedule Sale Price',
            'description'         => 'Atomic sale-price scheduling: sets sale_price + sale_price_dates_from + sale_price_dates_to in one call. For variable products, optionally apply_to_variations: true cascades the same sale price + dates to ALL the parent\'s variations. Set sale_price to empty string and pass both dates as empty strings to clear an existing sale. Common use: "put product X on sale for $19.99 from Friday through Monday".',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'sale_price' => [ 'type' => [ 'string', 'number' ], 'description' => 'New sale price. Empty string to clear the sale.' ],
                    'start_date' => [ 'type' => 'string', 'description' => 'When the sale starts. ISO 8601 or strtotime-parseable. Empty string to clear.' ],
                    'end_date'   => [ 'type' => 'string', 'description' => 'When the sale ends. ISO 8601 or strtotime-parseable. Empty string to clear.' ],
                    'apply_to_variations' => [
                        'type'        => 'boolean',
                        'description' => 'For variable products: also apply this sale price + dates to ALL variations. Defaults to false (parent-only). Ignored for non-variable types.',
                        'default'     => false,
                    ],
                ],
                'required' => [ 'product_id', 'sale_price' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'product_id' => [ 'type' => 'integer' ],
                    'sale_price' => [ 'type' => 'string' ],
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                    'variations_updated' => [ 'type' => 'integer' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
                if ( $product_id <= 0 ) {
                    return [ 'success' => false, 'message' => 'product_id is required.' ];
                }
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    return [ 'success' => false, 'message' => sprintf( 'Product %d not found.', $product_id ) ];
                }

                $sale_price = (string) $input['sale_price'];
                $start_in   = isset( $input['start_date'] ) ? (string) $input['start_date'] : '';
                $end_in     = isset( $input['end_date'] ) ? (string) $input['end_date'] : '';
                $start_ts   = $start_in === '' ? null : strtotime( $start_in );
                $end_ts     = $end_in === '' ? null : strtotime( $end_in );

                if ( $start_in !== '' && $start_ts === false ) {
                    return [ 'success' => false, 'message' => 'start_date could not be parsed.' ];
                }
                if ( $end_in !== '' && $end_ts === false ) {
                    return [ 'success' => false, 'message' => 'end_date could not be parsed.' ];
                }
                if ( $start_ts !== null && $end_ts !== null && $end_ts < $start_ts ) {
                    return [ 'success' => false, 'message' => 'end_date is before start_date.' ];
                }

                $product->set_sale_price( $sale_price );
                $product->set_date_on_sale_from( $start_ts );
                $product->set_date_on_sale_to( $end_ts );

                try {
                    $product->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Save failed: ' . $e->getMessage() ];
                }

                $variations_updated = 0;
                if ( ! empty( $input['apply_to_variations'] ) && $product->is_type( 'variable' ) ) {
                    foreach ( $product->get_children() as $vid ) {
                        $v = wc_get_product( $vid );
                        if ( ! $v ) continue;
                        $v->set_sale_price( $sale_price );
                        $v->set_date_on_sale_from( $start_ts );
                        $v->set_date_on_sale_to( $end_ts );
                        try {
                            $v->save();
                            $variations_updated++;
                        } catch ( \Exception $e ) {
                            // Continue, count as not updated.
                        }
                    }
                    if ( class_exists( '\WC_Product_Variable' ) ) {
                        \WC_Product_Variable::sync( $product_id );
                    }
                }

                $msg = $variations_updated > 0
                    ? sprintf( 'Sale scheduled on product %d and %d variation(s).', $product_id, $variations_updated )
                    : sprintf( 'Sale scheduled on product %d.', $product_id );

                return [
                    'success'    => true,
                    'product_id' => $product_id,
                    'sale_price' => $sale_price,
                    'start_date' => $start_in,
                    'end_date'   => $end_in,
                    'variations_updated' => $variations_updated,
                    'message'    => $msg,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }
}
