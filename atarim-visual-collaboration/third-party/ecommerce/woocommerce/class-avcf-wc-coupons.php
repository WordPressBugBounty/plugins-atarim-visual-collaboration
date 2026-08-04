<?php
/**
 * WooCommerce — Coupons MCP abilities.
 *
 * Standard CRUD for WooCommerce coupons. Coupons are a CPT (shop_coupon)
 * with a thin object wrapper (WC_Coupon) that exposes setters/getters for
 * the various discount-type, usage-limit, and restriction settings.
 *
 * Exposed abilities:
 *   atarim/list-coupons    Filtered list with pagination.
 *   atarim/get-coupon      Full detail for one coupon by ID or code.
 *   atarim/create-coupon   Create a new coupon.
 *   atarim/update-coupon   Partial update of an existing coupon.
 *   atarim/delete-coupon   Trash or permanently delete.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Coupons extends AVCF_Abilities_Base {

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

        // ---- list-coupons ----
        wp_register_ability( 'atarim/list-coupons', [
            'label'               => 'List Coupons',
            'description'         => 'Query coupons with filters: status, search across code/description, expiry date range, discount type, pagination. Returns a summary per coupon (code, amount, type, usage count, expiry). Use get-coupon for full restrictions and meta.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'status' => [ 'type' => [ 'string', 'array' ], 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash' ] ],
                    'search' => [ 'type' => 'string', 'description' => 'Match coupon code or description.' ],
                    'discount_type' => [
                        'type'        => 'string',
                        'description' => 'Filter by discount_type. Common: percent, fixed_cart, fixed_product.',
                    ],
                    'expires_after' => [ 'type' => 'string', 'description' => 'Coupons expiring on or after this date (omit to ignore).' ],
                    'expires_before' => [ 'type' => 'string' ],
                    'orderby' => [ 'type' => 'string', 'enum' => [ 'date', 'title', 'ID', 'menu_order' ], 'default' => 'date' ],
                    'order' => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
                    'limit' => [ 'type' => 'integer', 'minimum' => -1, 'default' => 20 ],
                    'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'    => [ 'type' => 'integer' ],
                    'returned' => [ 'type' => 'integer' ],
                    'coupons'  => [ 'type' => 'array' ],
                ],
                'required' => [ 'total', 'returned', 'coupons' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

                $args = [
                    'post_type'      => 'shop_coupon',
                    'posts_per_page' => $limit,
                    'offset'         => $offset,
                    'orderby'        => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'date',
                    'order'          => ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
                ];
                if ( ! empty( $input['status'] ) ) {
                    $args['post_status'] = $input['status'];
                } else {
                    $args['post_status'] = [ 'publish', 'draft', 'pending', 'private' ];
                }
                if ( ! empty( $input['search'] ) ) {
                    $args['s'] = (string) $input['search'];
                }

                $meta_query = [];
                if ( ! empty( $input['discount_type'] ) ) {
                    $meta_query[] = [
                        'key'   => 'discount_type',
                        'value' => (string) $input['discount_type'],
                    ];
                }
                if ( ! empty( $input['expires_after'] ) || ! empty( $input['expires_before'] ) ) {
                    $exp = [ 'key' => 'date_expires', 'type' => 'NUMERIC' ];
                    if ( ! empty( $input['expires_after'] ) && ! empty( $input['expires_before'] ) ) {
                        $exp['value']   = [ strtotime( (string) $input['expires_after'] ), strtotime( (string) $input['expires_before'] ) ];
                        $exp['compare'] = 'BETWEEN';
                    } elseif ( ! empty( $input['expires_after'] ) ) {
                        $exp['value']   = strtotime( (string) $input['expires_after'] );
                        $exp['compare'] = '>=';
                    } else {
                        $exp['value']   = strtotime( (string) $input['expires_before'] );
                        $exp['compare'] = '<=';
                    }
                    $meta_query[] = $exp;
                }
                if ( ! empty( $meta_query ) ) {
                    $args['meta_query'] = $meta_query;
                }

                $query = new \WP_Query( $args );
                $coupons = [];
                foreach ( $query->posts as $post ) {
                    $coupon = new \WC_Coupon( $post->ID );
                    $expires = $coupon->get_date_expires();
                    $coupons[] = [
                        'id'             => (int) $coupon->get_id(),
                        'code'           => (string) $coupon->get_code(),
                        'status'         => (string) $post->post_status,
                        'discount_type'  => (string) $coupon->get_discount_type(),
                        'amount'         => (string) $coupon->get_amount(),
                        'usage_count'    => (int) $coupon->get_usage_count(),
                        'usage_limit'    => $coupon->get_usage_limit(),
                        'date_expires'   => $expires ? $expires->date( 'Y-m-d' ) : '',
                        'date_created'   => $coupon->get_date_created() ? $coupon->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                    ];
                }

                return [
                    'total'    => (int) $query->found_posts,
                    'returned' => count( $coupons ),
                    'coupons'  => $coupons,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_shop_coupons' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-coupon ----
        wp_register_ability( 'atarim/get-coupon', [
            'label'               => 'Get Coupon',
            'description'         => 'Full detail for a coupon by ID or code. Includes restrictions (product/category include/exclude, email restrictions), usage limits per coupon / per user / per items, minimum/maximum spend, free shipping flag, exclude-sale-items flag.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'code' => [ 'type' => 'string', 'description' => 'Coupon code. Pass id OR code.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'coupon'  => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
                $code = isset( $input['code'] ) ? (string) $input['code'] : '';

                if ( $id <= 0 && $code === '' ) {
                    return [ 'success' => false, 'message' => 'Pass id or code.' ];
                }
                if ( $id <= 0 ) {
                    $id = (int) wc_get_coupon_id_by_code( $code );
                }
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'Coupon not found.' ];
                }

                $coupon = new \WC_Coupon( $id );
                if ( ! $coupon->get_id() ) {
                    return [ 'success' => false, 'message' => 'Coupon not found.' ];
                }

                $expires = $coupon->get_date_expires();

                $detail = [
                    'id'                        => (int) $coupon->get_id(),
                    'code'                      => (string) $coupon->get_code(),
                    'description'               => (string) $coupon->get_description(),
                    'discount_type'             => (string) $coupon->get_discount_type(),
                    'amount'                    => (string) $coupon->get_amount(),
                    'date_expires'              => $expires ? $expires->date( 'Y-m-d' ) : '',
                    'usage_count'               => (int) $coupon->get_usage_count(),
                    'individual_use'            => (bool) $coupon->get_individual_use(),
                    'product_ids'               => array_map( 'intval', (array) $coupon->get_product_ids() ),
                    'excluded_product_ids'      => array_map( 'intval', (array) $coupon->get_excluded_product_ids() ),
                    'usage_limit'               => $coupon->get_usage_limit(),
                    'usage_limit_per_user'      => $coupon->get_usage_limit_per_user(),
                    'limit_usage_to_x_items'    => $coupon->get_limit_usage_to_x_items(),
                    'free_shipping'             => (bool) $coupon->get_free_shipping(),
                    'product_categories'        => array_map( 'intval', (array) $coupon->get_product_categories() ),
                    'excluded_product_categories' => array_map( 'intval', (array) $coupon->get_excluded_product_categories() ),
                    'exclude_sale_items'        => (bool) $coupon->get_exclude_sale_items(),
                    'minimum_amount'            => (string) $coupon->get_minimum_amount(),
                    'maximum_amount'            => (string) $coupon->get_maximum_amount(),
                    'email_restrictions'        => array_values( (array) $coupon->get_email_restrictions() ),
                ];

                return [ 'success' => true, 'coupon' => $detail, 'message' => 'OK.' ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_shop_coupons' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- create-coupon ----
        wp_register_ability( 'atarim/create-coupon', [
            'label'               => 'Create Coupon',
            'description'         => 'Create a new coupon. Required: code, discount_type, amount. Common: date_expires, usage_limit, individual_use, free_shipping. Code uniqueness enforced — duplicate codes hard-fail.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'code' => [ 'type' => 'string', 'minLength' => 1 ],
                    'discount_type' => [ 'type' => 'string', 'description' => 'percent, fixed_cart, fixed_product, or any custom type registered by plugins.' ],
                    'amount' => [ 'type' => [ 'string', 'number' ] ],
                    'description' => [ 'type' => 'string' ],
                    'date_expires' => [ 'type' => 'string', 'description' => 'Expiry date. ISO 8601 or strtotime-parseable. Empty/omit for no expiry.' ],
                    'usage_limit' => [ 'type' => [ 'integer', 'null' ] ],
                    'usage_limit_per_user' => [ 'type' => [ 'integer', 'null' ] ],
                    'limit_usage_to_x_items' => [ 'type' => [ 'integer', 'null' ] ],
                    'individual_use' => [ 'type' => 'boolean' ],
                    'free_shipping' => [ 'type' => 'boolean' ],
                    'product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'exclude_sale_items' => [ 'type' => 'boolean' ],
                    'minimum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'maximum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'email_restrictions' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
                'required' => [ 'code', 'discount_type', 'amount' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'code'    => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $code = isset( $input['code'] ) ? wc_format_coupon_code( (string) $input['code'] ) : '';
                if ( $code === '' ) {
                    return [ 'success' => false, 'message' => 'code is required.' ];
                }
                if ( (int) wc_get_coupon_id_by_code( $code ) > 0 ) {
                    return [ 'success' => false, 'message' => sprintf( 'Coupon code "%s" already exists.', $code ) ];
                }
                if ( ! isset( $input['discount_type'] ) || $input['discount_type'] === '' ) {
                    return [ 'success' => false, 'message' => 'discount_type is required.' ];
                }
                if ( ! isset( $input['amount'] ) ) {
                    return [ 'success' => false, 'message' => 'amount is required.' ];
                }

                $coupon = new \WC_Coupon();
                $coupon->set_code( $code );
                $this->avcf_apply_coupon_setters( $coupon, $input );

                try {
                    $id = $coupon->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $e->getMessage() ];
                }
                if ( ! $id ) {
                    return [ 'success' => false, 'message' => 'Create failed: no ID returned.' ];
                }

                return [
                    'success' => true,
                    'id'      => (int) $id,
                    'code'    => (string) $coupon->get_code(),
                    'message' => sprintf( 'Coupon %d created (%s).', $id, $code ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'publish_shop_coupons' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- update-coupon ----
        wp_register_ability( 'atarim/update-coupon', [
            'label'               => 'Update Coupon',
            'description'         => 'Partial update of an existing coupon by ID. Pass any subset of writeable fields. Cannot change the code — to rename a coupon, delete and recreate.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'discount_type' => [ 'type' => 'string' ],
                    'amount' => [ 'type' => [ 'string', 'number' ] ],
                    'description' => [ 'type' => 'string' ],
                    'date_expires' => [ 'type' => 'string', 'description' => 'Empty string to clear expiry.' ],
                    'usage_limit' => [ 'type' => [ 'integer', 'null' ] ],
                    'usage_limit_per_user' => [ 'type' => [ 'integer', 'null' ] ],
                    'limit_usage_to_x_items' => [ 'type' => [ 'integer', 'null' ] ],
                    'individual_use' => [ 'type' => 'boolean' ],
                    'free_shipping' => [ 'type' => 'boolean' ],
                    'product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'exclude_sale_items' => [ 'type' => 'boolean' ],
                    'minimum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'maximum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'email_restrictions' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
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
                $coupon = new \WC_Coupon( $id );
                if ( ! $coupon->get_id() ) {
                    return [ 'success' => false, 'message' => sprintf( 'Coupon %d not found.', $id ) ];
                }

                $updated = $this->avcf_apply_coupon_setters( $coupon, $input );
                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                try {
                    $coupon->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => $updated, 'message' => 'Save failed: ' . $e->getMessage() ];
                }

                return [
                    'success' => true,
                    'id'      => $id,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_shop_coupons' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- delete-coupon ----
        wp_register_ability( 'atarim/delete-coupon', [
            'label'               => 'Delete Coupon',
            'description'         => 'Delete a coupon by ID. Defaults to trash (recoverable). Pass force: true for permanent delete.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'force' => [ 'type' => 'boolean', 'default' => false ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
                $force = ! empty( $input['force'] );
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                $coupon = new \WC_Coupon( $id );
                if ( ! $coupon->get_id() ) {
                    return [ 'success' => false, 'id' => $id, 'message' => sprintf( 'Coupon %d not found.', $id ) ];
                }
                $result = $coupon->delete( $force );
                if ( ! $result ) {
                    return [ 'success' => false, 'id' => $id, 'message' => 'Delete failed.' ];
                }
                return [
                    'success' => true,
                    'id'      => $id,
                    'message' => $force ? sprintf( 'Coupon %d permanently deleted.', $id ) : sprintf( 'Coupon %d trashed.', $id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'delete_shop_coupons' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }

    /**
     * Apply input fields to a WC_Coupon via setters. Returns the list of
     * field names that were touched.
     *
     * @param \WC_Coupon $coupon
     * @param array      $input
     * @return string[]
     */
    private function avcf_apply_coupon_setters( $coupon, $input ) {
        $touched = [];
        $map = [
            'discount_type'               => 'set_discount_type',
            'amount'                      => 'set_amount',
            'description'                 => 'set_description',
            'usage_limit'                 => 'set_usage_limit',
            'usage_limit_per_user'        => 'set_usage_limit_per_user',
            'limit_usage_to_x_items'      => 'set_limit_usage_to_x_items',
            'individual_use'              => 'set_individual_use',
            'free_shipping'               => 'set_free_shipping',
            'product_ids'                 => 'set_product_ids',
            'excluded_product_ids'        => 'set_excluded_product_ids',
            'product_categories'          => 'set_product_categories',
            'excluded_product_categories' => 'set_excluded_product_categories',
            'exclude_sale_items'          => 'set_exclude_sale_items',
            'minimum_amount'              => 'set_minimum_amount',
            'maximum_amount'              => 'set_maximum_amount',
            'email_restrictions'          => 'set_email_restrictions',
        ];
        foreach ( $map as $field => $setter ) {
            if ( ! array_key_exists( $field, $input ) ) continue;
            $value = $input[ $field ];
            if ( in_array( $field, [ 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories' ], true ) ) {
                $value = array_map( 'intval', (array) $value );
            } elseif ( $field === 'email_restrictions' ) {
                $value = array_map( 'sanitize_email', (array) $value );
            }
            $coupon->$setter( $value );
            $touched[] = $field;
        }
        if ( array_key_exists( 'date_expires', $input ) ) {
            $val = (string) $input['date_expires'];
            $ts  = $val === '' ? null : strtotime( $val );
            $coupon->set_date_expires( $ts );
            $touched[] = 'date_expires';
        }
        return $touched;
    }
}
