<?php
/**
 * WooCommerce — Product relationships (upsells, cross-sells, gallery).
 *
 * Dedicated abilities for the relational and visual aspects of a product
 * that are independent of its primary fields. Keeps the products file
 * focused on core attributes while still exposing rich management.
 *
 * Upsells appear on the product page as "you might also like". Cross-sells
 * appear in the cart as "frequently bought together". Both are stored as
 * arrays of product IDs in WC_Product's data.
 *
 * Exposed abilities:
 *   atarim/update-product-upsells       Set upsell product IDs.
 *   atarim/update-product-cross-sells   Set cross-sell product IDs.
 *   atarim/update-product-gallery       Set product gallery image IDs (separate from featured image).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Product_Relations extends AVCF_Abilities_Base {

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

        // ---- update-product-upsells ----
        wp_register_ability( 'atarim/update-product-upsells', [
            'label'               => 'Update Product Upsells',
            'description'         => 'Sets the upsell product IDs on a product (the "you might also like" suggestions shown on the product page). REPLACE semantics — the new array becomes the complete set. Pass empty array to clear all upsells. Each ID must reference an existing product.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'upsell_ids' => [
                        'type'        => 'array',
                        'description' => 'Array of product IDs to use as upsells. Empty array clears all upsells.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'required' => [ 'product_id', 'upsell_ids' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'product_id' => [ 'type' => 'integer' ],
                    'count'      => [ 'type' => 'integer' ],
                    'upsell_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'skipped'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'message'    => [ 'type' => 'string' ],
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
                $ids_in = isset( $input['upsell_ids'] ) && is_array( $input['upsell_ids'] ) ? $input['upsell_ids'] : [];
                $ids    = array_values( array_unique( array_map( 'intval', $ids_in ) ) );

                $valid_ids = [];
                $skipped   = [];
                foreach ( $ids as $pid ) {
                    if ( $pid <= 0 ) continue;
                    if ( $pid === $product_id ) { $skipped[] = $pid; continue; }
                    if ( ! wc_get_product( $pid ) ) { $skipped[] = $pid; continue; }
                    $valid_ids[] = $pid;
                }

                $product->set_upsell_ids( $valid_ids );
                try {
                    $product->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Save failed: ' . $e->getMessage() ];
                }

                return [
                    'success'    => true,
                    'product_id' => $product_id,
                    'count'      => count( $valid_ids ),
                    'upsell_ids' => $valid_ids,
                    'skipped'    => $skipped,
                    'message'    => sprintf( 'Upsells set: %d valid ID(s), %d skipped (self-reference or missing product).', count( $valid_ids ), count( $skipped ) ),
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

        // ---- update-product-cross-sells ----
        wp_register_ability( 'atarim/update-product-cross-sells', [
            'label'               => 'Update Product Cross-Sells',
            'description'         => 'Sets the cross-sell product IDs on a product (the "frequently bought together" suggestions shown in the cart). REPLACE semantics — the new array becomes the complete set. Pass empty array to clear all cross-sells. Each ID must reference an existing product.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'cross_sell_ids' => [
                        'type'        => 'array',
                        'description' => 'Array of product IDs to use as cross-sells. Empty array clears all.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'required' => [ 'product_id', 'cross_sell_ids' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'product_id'     => [ 'type' => 'integer' ],
                    'count'          => [ 'type' => 'integer' ],
                    'cross_sell_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'skipped'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'message'        => [ 'type' => 'string' ],
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
                $ids_in = isset( $input['cross_sell_ids'] ) && is_array( $input['cross_sell_ids'] ) ? $input['cross_sell_ids'] : [];
                $ids    = array_values( array_unique( array_map( 'intval', $ids_in ) ) );

                $valid_ids = [];
                $skipped   = [];
                foreach ( $ids as $pid ) {
                    if ( $pid <= 0 ) continue;
                    if ( $pid === $product_id ) { $skipped[] = $pid; continue; }
                    if ( ! wc_get_product( $pid ) ) { $skipped[] = $pid; continue; }
                    $valid_ids[] = $pid;
                }

                $product->set_cross_sell_ids( $valid_ids );
                try {
                    $product->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Save failed: ' . $e->getMessage() ];
                }

                return [
                    'success'        => true,
                    'product_id'     => $product_id,
                    'count'          => count( $valid_ids ),
                    'cross_sell_ids' => $valid_ids,
                    'skipped'        => $skipped,
                    'message'        => sprintf( 'Cross-sells set: %d valid ID(s), %d skipped.', count( $valid_ids ), count( $skipped ) ),
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

        // ---- update-product-gallery ----
        wp_register_ability( 'atarim/update-product-gallery', [
            'label'               => 'Update Product Gallery',
            'description'         => 'Sets the product gallery image IDs (the additional images shown on the product page, separate from the featured image). REPLACE semantics — the new array becomes the complete gallery. Pass empty array to clear. To change the featured image use atarim/update-product with featured_image_id. Each ID must reference an existing attachment.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'image_ids' => [
                        'type'        => 'array',
                        'description' => 'Array of attachment IDs for the gallery. Empty array clears the gallery.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'required' => [ 'product_id', 'image_ids' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'product_id' => [ 'type' => 'integer' ],
                    'count'      => [ 'type' => 'integer' ],
                    'image_ids'  => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'skipped'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'message'    => [ 'type' => 'string' ],
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
                $ids_in = isset( $input['image_ids'] ) && is_array( $input['image_ids'] ) ? $input['image_ids'] : [];
                $ids    = array_values( array_unique( array_map( 'intval', $ids_in ) ) );

                $valid_ids = [];
                $skipped   = [];
                foreach ( $ids as $aid ) {
                    if ( $aid <= 0 ) { $skipped[] = $aid; continue; }
                    $att = get_post( $aid );
                    if ( ! $att || $att->post_type !== 'attachment' ) {
                        $skipped[] = $aid;
                        continue;
                    }
                    $valid_ids[] = $aid;
                }

                $product->set_gallery_image_ids( $valid_ids );
                try {
                    $product->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Save failed: ' . $e->getMessage() ];
                }

                return [
                    'success'    => true,
                    'product_id' => $product_id,
                    'count'      => count( $valid_ids ),
                    'image_ids'  => $valid_ids,
                    'skipped'    => $skipped,
                    'message'    => sprintf( 'Gallery set: %d image(s), %d skipped (not attachments).', count( $valid_ids ), count( $skipped ) ),
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
