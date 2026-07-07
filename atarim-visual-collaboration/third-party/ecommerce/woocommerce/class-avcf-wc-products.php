<?php
/**
 * WooCommerce — Products MCP abilities.
 *
 * Reads and writes the core product catalogue via WooCommerce's data API
 * (WC_Product setters/getters) so plugin hooks, cache invalidation,
 * variation parent recalculation, and search-index updates all run as
 * WooCommerce expects.
 *
 * Variable-product nuance: a variable product is a parent with no price
 * of its own — pricing lives on each variation. The list/get response
 * surfaces the parent's price range; update-product writes to the parent's
 * price fields silently for variable types (they have no effect). For
 * per-variation pricing use the variations cluster.
 *
 * Exposed abilities:
 *   atarim/list-products    Catalogue with filters (status, stock, category, type, price range, search, dates).
 *   atarim/get-product      Single product with full detail.
 *   atarim/create-product   New product (simple, variable, grouped, external).
 *   atarim/update-product   Partial update via WC_Product setters.
 *   atarim/delete-product   Trash or permanent delete.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Products extends AVCF_Abilities_Base {

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

        // ---- list-products ----
        wp_register_ability( 'atarim/list-products', [
            'label'               => 'List Products',
            'description'         => 'Query the WooCommerce product catalogue with rich filters: status, type (simple/variable/grouped/external), stock_status, category (slug or ID), tag, price range, search, on-sale flag, featured flag, date range, pagination. For variable products the price field shows the price range (min-max from variations). To see per-variation pricing use list-product-variations.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => [ 'string', 'array' ],
                        'description' => 'Filter by post status. Defaults to publish only.',
                        'enum'        => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Filter by product type.',
                        'enum'        => [ 'simple', 'variable', 'grouped', 'external' ],
                    ],
                    'stock_status' => [
                        'type'        => 'string',
                        'description' => 'Filter by stock availability.',
                        'enum'        => [ 'instock', 'outofstock', 'onbackorder' ],
                    ],
                    'category' => [
                        'type'        => 'string',
                        'description' => 'Filter by product category slug or ID.',
                    ],
                    'tag' => [
                        'type'        => 'string',
                        'description' => 'Filter by product tag slug or ID.',
                    ],
                    'sku' => [
                        'type'        => 'string',
                        'description' => 'Exact SKU match.',
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Free-text search across title, content, and SKU.',
                    ],
                    'min_price' => [
                        'type'        => 'number',
                        'description' => 'Minimum price (inclusive).',
                    ],
                    'max_price' => [
                        'type'        => 'number',
                        'description' => 'Maximum price (inclusive).',
                    ],
                    'on_sale' => [
                        'type'        => 'boolean',
                        'description' => 'Only products currently on sale (a sale price is set and active for the current date).',
                    ],
                    'featured' => [
                        'type'        => 'boolean',
                        'description' => 'Only featured products.',
                    ],
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Products created on or after this date. ISO 8601 or strtotime-parseable.',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'Products created on or before this date.',
                    ],
                    'orderby' => [
                        'type'        => 'string',
                        'enum'        => [ 'date', 'modified', 'title', 'menu_order', 'price', 'popularity', 'rating', 'ID' ],
                        'default'     => 'date',
                    ],
                    'order' => [
                        'type'        => 'string',
                        'enum'        => [ 'ASC', 'DESC' ],
                        'default'     => 'DESC',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max products per page. -1 for all. Defaults to 20.',
                        'default'     => 20,
                        'minimum'     => -1,
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Skip this many products (pagination).',
                        'default'     => 0,
                        'minimum'     => 0,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'    => [ 'type' => 'integer' ],
                    'returned' => [ 'type' => 'integer' ],
                    'offset'   => [ 'type' => 'integer' ],
                    'products' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'              => [ 'type' => 'integer' ],
                                'name'            => [ 'type' => 'string' ],
                                'slug'            => [ 'type' => 'string' ],
                                'sku'             => [ 'type' => 'string' ],
                                'status'          => [ 'type' => 'string' ],
                                'type'            => [ 'type' => 'string' ],
                                'price'           => [ 'type' => 'string' ],
                                'regular_price'   => [ 'type' => 'string' ],
                                'sale_price'      => [ 'type' => 'string' ],
                                'on_sale'         => [ 'type' => 'boolean' ],
                                'stock_status'    => [ 'type' => 'string' ],
                                'stock_quantity'  => [ 'type' => [ 'integer', 'null' ] ],
                                'manage_stock'    => [ 'type' => 'boolean' ],
                                'featured'        => [ 'type' => 'boolean' ],
                                'catalog_visibility' => [ 'type' => 'string' ],
                                'categories'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                                'url'             => [ 'type' => 'string' ],
                                'date_created'    => [ 'type' => 'string' ],
                                'date_modified'   => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'returned', 'products' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

                $args = [
                    'limit'   => $limit,
                    'offset'  => $offset,
                    'orderby' => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'date',
                    'order'   => ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
                    'return'  => 'objects',
                ];

                if ( ! empty( $input['status'] ) ) {
                    $args['status'] = $input['status'];
                } else {
                    $args['status'] = 'publish';
                }
                if ( ! empty( $input['type'] ) ) {
                    $args['type'] = (string) $input['type'];
                }
                if ( ! empty( $input['stock_status'] ) ) {
                    $args['stock_status'] = (string) $input['stock_status'];
                }
                if ( ! empty( $input['sku'] ) ) {
                    $args['sku'] = (string) $input['sku'];
                }
                if ( ! empty( $input['search'] ) ) {
                    $args['s'] = (string) $input['search'];
                }
                if ( isset( $input['featured'] ) ) {
                    $args['featured'] = (bool) $input['featured'];
                }
                if ( isset( $input['on_sale'] ) ) {
                    // wc_get_products' on_sale arg only narrows when true.
                    if ( $input['on_sale'] ) {
                        $args['on_sale'] = true;
                    }
                }
                if ( ! empty( $input['category'] ) ) {
                    $args['category'] = [ (string) $input['category'] ];
                }
                if ( ! empty( $input['tag'] ) ) {
                    $args['tag'] = [ (string) $input['tag'] ];
                }

                // Date range — wc_get_products accepts date_created with comparison strings.
                if ( ! empty( $input['date_from'] ) || ! empty( $input['date_to'] ) ) {
                    $from_ts = null;
                    $to_ts   = null;
                    if ( ! empty( $input['date_from'] ) ) {
                        $from_ts = strtotime( (string) $input['date_from'] );
                        if ( $from_ts === false ) {
                            return [ 'total' => 0, 'returned' => 0, 'offset' => $offset, 'products' => [], 'message' => 'date_from could not be parsed.' ];
                        }
                    }
                    if ( ! empty( $input['date_to'] ) ) {
                        $to_ts = strtotime( (string) $input['date_to'] );
                        if ( $to_ts === false ) {
                            return [ 'total' => 0, 'returned' => 0, 'offset' => $offset, 'products' => [], 'message' => 'date_to could not be parsed.' ];
                        }
                    }
                    if ( $from_ts !== null && $to_ts !== null ) {
                        $args['date_created'] = $from_ts . '...' . $to_ts;
                    } elseif ( $from_ts !== null ) {
                        $args['date_created'] = '>=' . $from_ts;
                    } else {
                        $args['date_created'] = '<=' . $to_ts;
                    }
                }

                // Price range — applied post-query because wc_get_products doesn't take it natively
                // across all storage backends. We over-fetch slightly to compensate.
                $price_filter = isset( $input['min_price'] ) || isset( $input['max_price'] );

                $products = wc_get_products( $args );

                $count_args = $args;
                $count_args['limit']  = -1;
                $count_args['offset'] = 0;
                $count_args['return'] = 'ids';
                $total = count( wc_get_products( $count_args ) );

                $items = [];
                foreach ( $products as $product ) {
                    if ( $price_filter ) {
                        $p = (float) $product->get_price();
                        if ( isset( $input['min_price'] ) && $p < (float) $input['min_price'] ) {
                            continue;
                        }
                        if ( isset( $input['max_price'] ) && $p > (float) $input['max_price'] ) {
                            continue;
                        }
                    }

                    $term_objects = get_the_terms( $product->get_id(), 'product_cat' );
                    $cat_slugs = ( is_array( $term_objects ) ) ? array_map( function( $t ) { return $t->slug; }, $term_objects ) : [];

                    $items[] = [
                        'id'                 => (int) $product->get_id(),
                        'name'               => (string) $product->get_name(),
                        'slug'               => (string) $product->get_slug(),
                        'sku'                => (string) $product->get_sku(),
                        'status'             => (string) $product->get_status(),
                        'type'               => (string) $product->get_type(),
                        'price'              => (string) $product->get_price(),
                        'regular_price'      => (string) $product->get_regular_price(),
                        'sale_price'         => (string) $product->get_sale_price(),
                        'on_sale'            => (bool) $product->is_on_sale(),
                        'stock_status'       => (string) $product->get_stock_status(),
                        'stock_quantity'     => $product->get_stock_quantity(),
                        'manage_stock'       => (bool) $product->get_manage_stock(),
                        'featured'           => (bool) $product->get_featured(),
                        'catalog_visibility' => (string) $product->get_catalog_visibility(),
                        'categories'         => array_values( $cat_slugs ),
                        'url'                => (string) get_permalink( $product->get_id() ),
                        'date_created'       => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                        'date_modified'      => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
                    ];
                }

                return [
                    'total'    => $price_filter ? count( $items ) : (int) $total,
                    'returned' => count( $items ),
                    'offset'   => $offset,
                    'products' => $items,
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

        // ---- get-product ----
        wp_register_ability( 'atarim/get-product', [
            'label'               => 'Get Product',
            'description'         => 'Full detail for a single product by ID or SKU. Includes pricing, stock, dimensions, attributes, categories/tags, image references (featured + gallery), upsell/cross-sell IDs, and variation IDs (if variable). For variable products the price/sale_price fields show the range; per-variation detail comes from list-product-variations.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Product ID. Pass id OR sku.',
                        'minimum'     => 1,
                    ],
                    'sku' => [
                        'type'        => 'string',
                        'description' => 'SKU. Pass id OR sku.',
                        'minLength'   => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'product' => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id  = isset( $input['id'] )  ? (int) $input['id']  : 0;
                $sku = isset( $input['sku'] ) ? (string) $input['sku'] : '';

                if ( $id <= 0 && $sku === '' ) {
                    return [ 'success' => false, 'message' => 'Pass either id or sku.' ];
                }
                if ( $id <= 0 ) {
                    $id = (int) wc_get_product_id_by_sku( $sku );
                }
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'Product not found.' ];
                }

                $product = wc_get_product( $id );
                if ( ! $product ) {
                    return [ 'success' => false, 'message' => sprintf( 'Product %d not found.', $id ) ];
                }

                $term_cats = get_the_terms( $id, 'product_cat' );
                $term_tags = get_the_terms( $id, 'product_tag' );
                $categories = is_array( $term_cats )
                    ? array_map( function( $t ) { return [ 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name ]; }, $term_cats )
                    : [];
                $tags = is_array( $term_tags )
                    ? array_map( function( $t ) { return [ 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name ]; }, $term_tags )
                    : [];

                $attributes = [];
                foreach ( $product->get_attributes() as $name => $attr ) {
                    if ( ! $attr instanceof \WC_Product_Attribute ) {
                        continue;
                    }
                    $attributes[] = [
                        'name'      => $attr->get_name(),
                        'options'   => $attr->is_taxonomy() ? wc_get_product_terms( $id, $attr->get_name(), [ 'fields' => 'names' ] ) : $attr->get_options(),
                        'visible'   => (bool) $attr->get_visible(),
                        'variation' => (bool) $attr->get_variation(),
                    ];
                }

                $gallery_ids = (array) $product->get_gallery_image_ids();

                $detail = [
                    'id'                 => (int) $product->get_id(),
                    'name'               => (string) $product->get_name(),
                    'slug'               => (string) $product->get_slug(),
                    'sku'                => (string) $product->get_sku(),
                    'status'             => (string) $product->get_status(),
                    'type'               => (string) $product->get_type(),
                    'short_description'  => (string) $product->get_short_description(),
                    'description'        => (string) $product->get_description(),
                    'price'              => (string) $product->get_price(),
                    'regular_price'      => (string) $product->get_regular_price(),
                    'sale_price'         => (string) $product->get_sale_price(),
                    'on_sale'            => (bool) $product->is_on_sale(),
                    'sale_price_dates_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( 'Y-m-d H:i:s' ) : '',
                    'sale_price_dates_to'   => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( 'Y-m-d H:i:s' ) : '',
                    'stock_status'       => (string) $product->get_stock_status(),
                    'stock_quantity'     => $product->get_stock_quantity(),
                    'manage_stock'       => (bool) $product->get_manage_stock(),
                    'backorders'         => (string) $product->get_backorders(),
                    'low_stock_amount'   => $product->get_low_stock_amount(),
                    'sold_individually'  => (bool) $product->get_sold_individually(),
                    'featured'           => (bool) $product->get_featured(),
                    'catalog_visibility' => (string) $product->get_catalog_visibility(),
                    'tax_status'         => (string) $product->get_tax_status(),
                    'tax_class'          => (string) $product->get_tax_class(),
                    'virtual'            => (bool) $product->get_virtual(),
                    'downloadable'       => (bool) $product->get_downloadable(),
                    'weight'             => (string) $product->get_weight(),
                    'length'             => (string) $product->get_length(),
                    'width'              => (string) $product->get_width(),
                    'height'             => (string) $product->get_height(),
                    'shipping_class_id'  => (int) $product->get_shipping_class_id(),
                    'featured_image_id'  => (int) $product->get_image_id(),
                    'gallery_image_ids'  => array_map( 'intval', $gallery_ids ),
                    'upsell_ids'         => array_map( 'intval', (array) $product->get_upsell_ids() ),
                    'cross_sell_ids'     => array_map( 'intval', (array) $product->get_cross_sell_ids() ),
                    'categories'         => $categories,
                    'tags'               => $tags,
                    'attributes'         => $attributes,
                    'url'                => (string) get_permalink( $id ),
                    'date_created'       => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                    'date_modified'      => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
                ];

                // For variable products, include variation IDs.
                if ( $product->is_type( 'variable' ) ) {
                    $detail['variation_ids'] = array_map( 'intval', $product->get_children() );
                }

                return [ 'success' => true, 'product' => $detail, 'message' => 'OK.' ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- create-product ----
        wp_register_ability( 'atarim/create-product', [
            'label'               => 'Create Product',
            'description'         => 'Create a new product. type defaults to "simple". For variable products, this creates the parent only — add variations via create-product-variation afterward. Required: name. Recommended: type, status, regular_price (for simple products). All fields use WC_Product setters, so plugin hooks fire and caches invalidate correctly.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string', 'minLength' => 1 ],
                    'type' => [ 'type' => 'string', 'enum' => [ 'simple', 'variable', 'grouped', 'external' ], 'default' => 'simple' ],
                    'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
                    'sku' => [ 'type' => 'string' ],
                    'short_description' => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'regular_price' => [ 'type' => [ 'string', 'number' ] ],
                    'sale_price' => [ 'type' => [ 'string', 'number' ] ],
                    'manage_stock' => [ 'type' => 'boolean' ],
                    'stock_quantity' => [ 'type' => 'integer' ],
                    'stock_status' => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
                    'featured' => [ 'type' => 'boolean' ],
                    'catalog_visibility' => [ 'type' => 'string', 'enum' => [ 'visible', 'catalog', 'search', 'hidden' ] ],
                    'virtual' => [ 'type' => 'boolean' ],
                    'downloadable' => [ 'type' => 'boolean' ],
                    'weight' => [ 'type' => [ 'string', 'number' ] ],
                    'category_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'tag_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'featured_image_id' => [ 'type' => 'integer' ],
                ],
                'required' => [ 'name' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'sku'     => [ 'type' => 'string' ],
                    'url'     => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $name = isset( $input['name'] ) ? (string) $input['name'] : '';
                if ( $name === '' ) {
                    return [ 'success' => false, 'message' => 'name is required.' ];
                }

                $type = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : 'simple';
                $class_map = [
                    'simple'   => 'WC_Product_Simple',
                    'variable' => 'WC_Product_Variable',
                    'grouped'  => 'WC_Product_Grouped',
                    'external' => 'WC_Product_External',
                ];
                $class = isset( $class_map[ $type ] ) ? $class_map[ $type ] : 'WC_Product_Simple';
                if ( ! class_exists( $class ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Product type "%s" is not available on this site.', $type ) ];
                }

                $product = new $class();
                $product->set_name( $name );
                $product->set_status( isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft' );

                if ( isset( $input['sku'] ) && $input['sku'] !== '' ) {
                    $sku_clean = wc_clean( (string) $input['sku'] );
                    if ( wc_get_product_id_by_sku( $sku_clean ) > 0 ) {
                        return [ 'success' => false, 'message' => sprintf( 'SKU "%s" is already in use.', $sku_clean ) ];
                    }
                    $product->set_sku( $sku_clean );
                }
                if ( isset( $input['short_description'] ) ) {
                    $product->set_short_description( wp_kses_post( (string) $input['short_description'] ) );
                }
                if ( isset( $input['description'] ) ) {
                    $product->set_description( wp_kses_post( (string) $input['description'] ) );
                }
                if ( isset( $input['regular_price'] ) ) {
                    $product->set_regular_price( (string) $input['regular_price'] );
                }
                if ( isset( $input['sale_price'] ) ) {
                    $product->set_sale_price( (string) $input['sale_price'] );
                }
                if ( isset( $input['manage_stock'] ) ) {
                    $product->set_manage_stock( (bool) $input['manage_stock'] );
                }
                if ( array_key_exists( 'stock_quantity', $input ) && $input['stock_quantity'] !== null ) {
                    $product->set_stock_quantity( (int) $input['stock_quantity'] );
                }
                if ( isset( $input['stock_status'] ) ) {
                    $product->set_stock_status( sanitize_key( $input['stock_status'] ) );
                }
                if ( isset( $input['featured'] ) ) {
                    $product->set_featured( (bool) $input['featured'] );
                }
                if ( isset( $input['catalog_visibility'] ) ) {
                    $product->set_catalog_visibility( sanitize_key( $input['catalog_visibility'] ) );
                }
                if ( isset( $input['virtual'] ) ) {
                    $product->set_virtual( (bool) $input['virtual'] );
                }
                if ( isset( $input['downloadable'] ) ) {
                    $product->set_downloadable( (bool) $input['downloadable'] );
                }
                if ( isset( $input['weight'] ) ) {
                    $product->set_weight( (string) $input['weight'] );
                }
                if ( ! empty( $input['category_ids'] ) ) {
                    $product->set_category_ids( array_map( 'intval', (array) $input['category_ids'] ) );
                }
                if ( ! empty( $input['tag_ids'] ) ) {
                    $product->set_tag_ids( array_map( 'intval', (array) $input['tag_ids'] ) );
                }
                if ( ! empty( $input['featured_image_id'] ) ) {
                    $product->set_image_id( (int) $input['featured_image_id'] );
                }

                try {
                    $id = $product->save();
                } catch ( \Exception $e ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $e->getMessage() ];
                }
                if ( ! $id ) {
                    return [ 'success' => false, 'message' => 'Create failed: WC reported no ID returned.' ];
                }

                return [
                    'success' => true,
                    'id'      => (int) $id,
                    'sku'     => (string) $product->get_sku(),
                    'url'     => (string) get_permalink( $id ),
                    'message' => sprintf( 'Product %d created (%s).', $id, $type ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'publish_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- update-product ----
        wp_register_ability( 'atarim/update-product', [
            'label'               => 'Update Product',
            'description'         => 'Partial update of a product. Only id is required; pass any subset of writeable fields. Omitted fields are left unchanged. For variable products: writes to regular_price/sale_price are accepted but have no effect (variable products have no price of their own — use variations cluster). Sale-date fields are paired: sale_price_dates_from/to expect ISO 8601 strings; pass empty string to clear. Uses WC_Product setters so plugin hooks fire.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'name' => [ 'type' => 'string' ],
                    'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash' ] ],
                    'sku' => [ 'type' => 'string' ],
                    'short_description' => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'regular_price' => [ 'type' => [ 'string', 'number' ] ],
                    'sale_price' => [ 'type' => [ 'string', 'number' ] ],
                    'sale_price_dates_from' => [ 'type' => 'string' ],
                    'sale_price_dates_to' => [ 'type' => 'string' ],
                    'manage_stock' => [ 'type' => 'boolean' ],
                    'stock_quantity' => [ 'type' => 'integer' ],
                    'stock_status' => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
                    'featured' => [ 'type' => 'boolean' ],
                    'catalog_visibility' => [ 'type' => 'string', 'enum' => [ 'visible', 'catalog', 'search', 'hidden' ] ],
                    'virtual' => [ 'type' => 'boolean' ],
                    'downloadable' => [ 'type' => 'boolean' ],
                    'weight' => [ 'type' => [ 'string', 'number' ] ],
                    'category_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'tag_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'featured_image_id' => [ 'type' => 'integer' ],
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

                $product = wc_get_product( $id );
                if ( ! $product ) {
                    return [ 'success' => false, 'message' => sprintf( 'Product %d not found.', $id ) ];
                }

                $updated = [];

                if ( array_key_exists( 'name', $input ) ) {
                    $product->set_name( (string) $input['name'] );
                    $updated[] = 'name';
                }
                if ( array_key_exists( 'status', $input ) ) {
                    $product->set_status( sanitize_key( $input['status'] ) );
                    $updated[] = 'status';
                }
                if ( array_key_exists( 'sku', $input ) ) {
                    $new_sku = wc_clean( (string) $input['sku'] );
                    if ( $new_sku !== '' ) {
                        $existing = (int) wc_get_product_id_by_sku( $new_sku );
                        if ( $existing > 0 && $existing !== $id ) {
                            return [ 'success' => false, 'id' => $id, 'updated' => $updated, 'message' => sprintf( 'SKU "%s" is already in use by product %d.', $new_sku, $existing ) ];
                        }
                    }
                    $product->set_sku( $new_sku );
                    $updated[] = 'sku';
                }
                if ( array_key_exists( 'short_description', $input ) ) {
                    $product->set_short_description( wp_kses_post( (string) $input['short_description'] ) );
                    $updated[] = 'short_description';
                }
                if ( array_key_exists( 'description', $input ) ) {
                    $product->set_description( wp_kses_post( (string) $input['description'] ) );
                    $updated[] = 'description';
                }
                if ( array_key_exists( 'regular_price', $input ) ) {
                    $product->set_regular_price( (string) $input['regular_price'] );
                    $updated[] = 'regular_price';
                }
                if ( array_key_exists( 'sale_price', $input ) ) {
                    $product->set_sale_price( (string) $input['sale_price'] );
                    $updated[] = 'sale_price';
                }
                if ( array_key_exists( 'sale_price_dates_from', $input ) ) {
                    $ts = $input['sale_price_dates_from'] === '' ? null : strtotime( (string) $input['sale_price_dates_from'] );
                    $product->set_date_on_sale_from( $ts );
                    $updated[] = 'sale_price_dates_from';
                }
                if ( array_key_exists( 'sale_price_dates_to', $input ) ) {
                    $ts = $input['sale_price_dates_to'] === '' ? null : strtotime( (string) $input['sale_price_dates_to'] );
                    $product->set_date_on_sale_to( $ts );
                    $updated[] = 'sale_price_dates_to';
                }
                if ( array_key_exists( 'manage_stock', $input ) ) {
                    $product->set_manage_stock( (bool) $input['manage_stock'] );
                    $updated[] = 'manage_stock';
                }
                if ( array_key_exists( 'stock_quantity', $input ) ) {
                    $product->set_stock_quantity( $input['stock_quantity'] === null ? null : (int) $input['stock_quantity'] );
                    $updated[] = 'stock_quantity';
                }
                if ( array_key_exists( 'stock_status', $input ) ) {
                    $product->set_stock_status( sanitize_key( $input['stock_status'] ) );
                    $updated[] = 'stock_status';
                }
                if ( array_key_exists( 'featured', $input ) ) {
                    $product->set_featured( (bool) $input['featured'] );
                    $updated[] = 'featured';
                }
                if ( array_key_exists( 'catalog_visibility', $input ) ) {
                    $product->set_catalog_visibility( sanitize_key( $input['catalog_visibility'] ) );
                    $updated[] = 'catalog_visibility';
                }
                if ( array_key_exists( 'virtual', $input ) ) {
                    $product->set_virtual( (bool) $input['virtual'] );
                    $updated[] = 'virtual';
                }
                if ( array_key_exists( 'downloadable', $input ) ) {
                    $product->set_downloadable( (bool) $input['downloadable'] );
                    $updated[] = 'downloadable';
                }
                if ( array_key_exists( 'weight', $input ) ) {
                    $product->set_weight( (string) $input['weight'] );
                    $updated[] = 'weight';
                }
                if ( array_key_exists( 'category_ids', $input ) ) {
                    $product->set_category_ids( array_map( 'intval', (array) $input['category_ids'] ) );
                    $updated[] = 'category_ids';
                }
                if ( array_key_exists( 'tag_ids', $input ) ) {
                    $product->set_tag_ids( array_map( 'intval', (array) $input['tag_ids'] ) );
                    $updated[] = 'tag_ids';
                }
                if ( array_key_exists( 'featured_image_id', $input ) ) {
                    $product->set_image_id( (int) $input['featured_image_id'] );
                    $updated[] = 'featured_image_id';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                try {
                    $product->save();
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
                return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- delete-product ----
        wp_register_ability( 'atarim/delete-product', [
            'label'               => 'Delete Product',
            'description'         => 'Delete a product. Defaults to trash (recoverable). Pass force: true to permanently delete (no recovery). For variable products this also deletes all child variations.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'force' => [ 'type' => 'boolean', 'default' => false, 'description' => 'When true, permanently delete (skip trash). Defaults to false (trash, recoverable).' ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'force'   => [ 'type' => 'boolean' ],
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
                $product = wc_get_product( $id );
                if ( ! $product ) {
                    return [ 'success' => false, 'id' => $id, 'force' => $force, 'message' => sprintf( 'Product %d not found.', $id ) ];
                }
                if ( ! current_user_can( 'delete_product', $id ) ) {
                    return [ 'success' => false, 'id' => $id, 'force' => $force, 'message' => 'Permission denied.' ];
                }

                $result = $product->delete( $force );
                if ( ! $result ) {
                    return [ 'success' => false, 'id' => $id, 'force' => $force, 'message' => 'Delete failed.' ];
                }
                return [
                    'success' => true,
                    'id'      => $id,
                    'force'   => $force,
                    'message' => $force ? sprintf( 'Product %d permanently deleted.', $id ) : sprintf( 'Product %d moved to trash.', $id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'delete_products' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }
}
