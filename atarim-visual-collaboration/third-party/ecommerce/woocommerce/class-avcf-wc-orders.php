<?php
/**
 * WooCommerce — Orders MCP abilities.
 *
 * Read-only by design — payment gateway reconciliation is out of scope.
 * All order queries route through wc_get_orders() / wc_get_order() so
 * HPOS (custom order tables) is handled transparently; the AI doesn't
 * need to know which storage backend the site uses.
 *
 * Exposed abilities:
 *   atarim/list-orders      Orders with filters (status, date range, customer, payment method, totals).
 *   atarim/get-order        Full detail including line items, addresses, fees, taxes, refunds.
 *   atarim/get-order-stats  Aggregated metrics for a date range (status counts, revenue trends,
 *                           on-sale products, low-stock products, top sellers).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Orders extends AVCF_Abilities_Base {

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

        // ---- list-orders ----
        wp_register_ability( 'atarim/list-orders', [
            'label'               => 'List Orders',
            'description'         => 'Query WooCommerce orders. Filters: status (single or array), date range (created/modified/paid/completed), customer ID, customer email, payment method, total range, search across order numbers and customer fields, pagination. HPOS-compatible — uses wc_get_orders so works regardless of storage backend.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => [ 'string', 'array' ],
                        'description' => 'Filter by order status. Common: pending, processing, on-hold, completed, cancelled, refunded, failed. Status names should NOT include the "wc-" prefix.',
                    ],
                    'customer_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Filter by registered customer user ID.' ],
                    'customer_email' => [ 'type' => 'string', 'description' => 'Filter by billing email (works for both guest and registered).' ],
                    'payment_method' => [ 'type' => 'string', 'description' => 'Payment method ID (e.g. "stripe", "paypal", "cheque").' ],
                    'date_from' => [ 'type' => 'string', 'description' => 'Orders created on or after this date.' ],
                    'date_to' => [ 'type' => 'string', 'description' => 'Orders created on or before this date.' ],
                    'date_field' => [
                        'type'        => 'string',
                        'description' => 'Which date to filter by. Defaults to created.',
                        'enum'        => [ 'created', 'modified', 'paid', 'completed' ],
                        'default'     => 'created',
                    ],
                    'min_total' => [ 'type' => 'number', 'description' => 'Minimum order total (inclusive).' ],
                    'max_total' => [ 'type' => 'number', 'description' => 'Maximum order total (inclusive).' ],
                    'search' => [ 'type' => 'string', 'description' => 'Search across order numbers and customer fields.' ],
                    'orderby' => [ 'type' => 'string', 'enum' => [ 'date', 'modified', 'total', 'ID' ], 'default' => 'date' ],
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
                    'offset'   => [ 'type' => 'integer' ],
                    'orders'   => [ 'type' => 'array' ],
                ],
                'required' => [ 'total', 'returned', 'orders' ],
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
                    // Normalize: accept "completed" or "wc-completed".
                    $statuses = (array) $input['status'];
                    $normalized = [];
                    foreach ( $statuses as $s ) {
                        $s = (string) $s;
                        if ( strpos( $s, 'wc-' ) === 0 ) {
                            $normalized[] = substr( $s, 3 );
                        } else {
                            $normalized[] = $s;
                        }
                    }
                    $args['status'] = $normalized;
                }
                if ( isset( $input['customer_id'] ) ) {
                    $args['customer_id'] = (int) $input['customer_id'];
                }
                if ( ! empty( $input['customer_email'] ) ) {
                    $args['billing_email'] = (string) $input['customer_email'];
                }
                if ( ! empty( $input['payment_method'] ) ) {
                    $args['payment_method'] = (string) $input['payment_method'];
                }
                if ( ! empty( $input['search'] ) ) {
                    $args['s'] = (string) $input['search'];
                }

                // Date range. wc_get_orders accepts date_created / date_modified / date_paid / date_completed.
                $date_field = isset( $input['date_field'] ) ? sanitize_key( $input['date_field'] ) : 'created';
                $date_arg_map = [
                    'created'   => 'date_created',
                    'modified'  => 'date_modified',
                    'paid'      => 'date_paid',
                    'completed' => 'date_completed',
                ];
                $date_key = isset( $date_arg_map[ $date_field ] ) ? $date_arg_map[ $date_field ] : 'date_created';

                if ( ! empty( $input['date_from'] ) || ! empty( $input['date_to'] ) ) {
                    $from_ts = ! empty( $input['date_from'] ) ? strtotime( (string) $input['date_from'] ) : null;
                    $to_ts   = ! empty( $input['date_to'] ) ? strtotime( (string) $input['date_to'] ) : null;
                    if ( $from_ts !== null && $from_ts !== false && $to_ts !== null && $to_ts !== false ) {
                        $args[ $date_key ] = $from_ts . '...' . $to_ts;
                    } elseif ( $from_ts !== null && $from_ts !== false ) {
                        $args[ $date_key ] = '>=' . $from_ts;
                    } elseif ( $to_ts !== null && $to_ts !== false ) {
                        $args[ $date_key ] = '<=' . $to_ts;
                    }
                }

                $total_filter = isset( $input['min_total'] ) || isset( $input['max_total'] );

                $orders = wc_get_orders( $args );
                $count_args = $args;
                $count_args['limit']  = -1;
                $count_args['offset'] = 0;
                $count_args['return'] = 'ids';
                $total = count( wc_get_orders( $count_args ) );

                $items = [];
                foreach ( $orders as $order ) {
                    if ( $total_filter ) {
                        $t = (float) $order->get_total();
                        if ( isset( $input['min_total'] ) && $t < (float) $input['min_total'] ) continue;
                        if ( isset( $input['max_total'] ) && $t > (float) $input['max_total'] ) continue;
                    }
                    $items[] = [
                        'id'             => (int) $order->get_id(),
                        'number'         => (string) $order->get_order_number(),
                        'status'         => (string) $order->get_status(),
                        'total'          => (string) $order->get_total(),
                        'currency'       => (string) $order->get_currency(),
                        'customer_id'    => (int) $order->get_customer_id(),
                        'billing_email'  => (string) $order->get_billing_email(),
                        'billing_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'payment_method' => (string) $order->get_payment_method(),
                        'item_count'     => (int) $order->get_item_count(),
                        'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                        'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
                        'date_paid'      => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : '',
                        'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : '',
                    ];
                }

                return [
                    'total'    => $total_filter ? count( $items ) : (int) $total,
                    'returned' => count( $items ),
                    'offset'   => $offset,
                    'orders'   => $items,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-order ----
        wp_register_ability( 'atarim/get-order', [
            'label'               => 'Get Order',
            'description'         => 'Full detail for one order: header (status, totals, currency), customer info, billing and shipping addresses, line items (product, qty, price, taxes), fees, shipping lines, refunds, notes (if requested), payment method. HPOS-compatible.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'include_notes' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Include order notes (admin notes + customer-facing notes). Defaults to false.' ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'order'   => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                $order = wc_get_order( $id );
                if ( ! $order ) {
                    return [ 'success' => false, 'message' => sprintf( 'Order %d not found.', $id ) ];
                }

                $items = [];
                foreach ( $order->get_items() as $item_id => $item ) {
                    $items[] = [
                        'item_id'      => (int) $item_id,
                        'name'         => $item->get_name(),
                        'product_id'   => (int) $item->get_product_id(),
                        'variation_id' => (int) $item->get_variation_id(),
                        'quantity'     => (int) $item->get_quantity(),
                        'subtotal'     => (string) $item->get_subtotal(),
                        'total'        => (string) $item->get_total(),
                        'total_tax'    => (string) $item->get_total_tax(),
                    ];
                }

                $fees = [];
                foreach ( $order->get_fees() as $fee ) {
                    $fees[] = [
                        'name'  => $fee->get_name(),
                        'total' => (string) $fee->get_total(),
                    ];
                }

                $shipping_lines = [];
                foreach ( $order->get_shipping_methods() as $sm ) {
                    $shipping_lines[] = [
                        'method_title' => $sm->get_method_title(),
                        'method_id'    => $sm->get_method_id(),
                        'total'        => (string) $sm->get_total(),
                    ];
                }

                $refunds = [];
                foreach ( $order->get_refunds() as $refund ) {
                    $refunds[] = [
                        'id'     => (int) $refund->get_id(),
                        'amount' => (string) $refund->get_amount(),
                        'reason' => (string) $refund->get_reason(),
                        'date'   => $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                    ];
                }

                $detail = [
                    'id'             => (int) $order->get_id(),
                    'number'         => (string) $order->get_order_number(),
                    'status'         => (string) $order->get_status(),
                    'currency'       => (string) $order->get_currency(),
                    'subtotal'       => (string) $order->get_subtotal(),
                    'total_tax'      => (string) $order->get_total_tax(),
                    'shipping_total' => (string) $order->get_shipping_total(),
                    'total'          => (string) $order->get_total(),
                    'discount_total' => (string) $order->get_discount_total(),
                    'customer_id'    => (int) $order->get_customer_id(),
                    'customer_note'  => (string) $order->get_customer_note(),
                    'billing'        => $order->get_address( 'billing' ),
                    'shipping'       => $order->get_address( 'shipping' ),
                    'payment_method' => (string) $order->get_payment_method(),
                    'payment_method_title' => (string) $order->get_payment_method_title(),
                    'transaction_id' => (string) $order->get_transaction_id(),
                    'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                    'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
                    'date_paid'      => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : '',
                    'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : '',
                    'items'          => $items,
                    'fees'           => $fees,
                    'shipping_lines' => $shipping_lines,
                    'refunds'        => $refunds,
                    'coupon_codes'   => array_values( (array) $order->get_coupon_codes() ),
                ];

                if ( ! empty( $input['include_notes'] ) ) {
                    $notes = wc_get_order_notes( [ 'order_id' => $id ] );
                    $detail['notes'] = array_map( function( $n ) {
                        return [
                            'id'             => (int) $n->id,
                            'date_created'   => is_object( $n->date_created ) ? $n->date_created->date( 'Y-m-d H:i:s' ) : '',
                            'content'        => (string) $n->content,
                            'customer_note'  => (bool) $n->customer_note,
                            'added_by'       => (string) $n->added_by,
                        ];
                    }, $notes );
                }

                return [ 'success' => true, 'order' => $detail, 'message' => 'OK.' ];
            },
            'permission_callback' => function() {
                return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-order-stats ----
        wp_register_ability( 'atarim/get-order-stats', [
            'label'               => 'Get Order Stats',
            'description'         => 'Aggregated WooCommerce metrics for a date range: per-status order counts, revenue total + daily breakdown, refund rate, top sellers by units and revenue, products currently on sale, products with low stock. Requires a date range (max 1 year) to keep queries bounded. Honest perf note: on sites with > 50k orders, top-sellers computation is slower — consider narrower date windows. Skips test orders (status: failed, cancelled) from revenue calculations.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'date_from' => [ 'type' => 'string', 'description' => 'Start of date range (inclusive). ISO 8601 or strtotime.' ],
                    'date_to'   => [ 'type' => 'string', 'description' => 'End of date range (inclusive). Max 1 year after date_from.' ],
                    'top_sellers_limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Number of top sellers to return (per metric — units and revenue lists). Default 20.' ],
                    'low_stock_threshold' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 5, 'description' => 'Stock quantity at or below which a product is "low stock". Defaults to 5; ignored for products with manage_stock=false.' ],
                ],
                'required' => [ 'date_from', 'date_to' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'period'   => [ 'type' => 'object' ],
                    'orders'   => [ 'type' => 'object' ],
                    'revenue'  => [ 'type' => 'object' ],
                    'refunds'  => [ 'type' => 'object' ],
                    'top_sellers_by_units'    => [ 'type' => 'array' ],
                    'top_sellers_by_revenue'  => [ 'type' => 'array' ],
                    'on_sale'  => [ 'type' => 'object' ],
                    'low_stock' => [ 'type' => 'object' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $from_str = isset( $input['date_from'] ) ? (string) $input['date_from'] : '';
                $to_str   = isset( $input['date_to'] ) ? (string) $input['date_to'] : '';
                if ( $from_str === '' || $to_str === '' ) {
                    return [ 'success' => false, 'message' => 'date_from and date_to are both required.' ];
                }
                $from_ts = strtotime( $from_str );
                $to_ts   = strtotime( $to_str );
                if ( $from_ts === false || $to_ts === false ) {
                    return [ 'success' => false, 'message' => 'Could not parse one or both dates.' ];
                }
                if ( $to_ts < $from_ts ) {
                    return [ 'success' => false, 'message' => 'date_to is before date_from.' ];
                }
                $max_span = 366 * DAY_IN_SECONDS;
                if ( ( $to_ts - $from_ts ) > $max_span ) {
                    return [ 'success' => false, 'message' => 'Date range exceeds maximum (1 year). Narrow the window and try again.' ];
                }

                $top_limit   = isset( $input['top_sellers_limit'] ) ? max( 1, min( 100, (int) $input['top_sellers_limit'] ) ) : 20;
                $low_thresh  = isset( $input['low_stock_threshold'] ) ? max( 0, (int) $input['low_stock_threshold'] ) : 5;

                global $wpdb;

                // Per-status counts within window — use wc_get_orders().
                $known_statuses = array_keys( wc_get_order_statuses() );
                $status_counts = [];
                foreach ( $known_statuses as $status ) {
                    $short = strpos( $status, 'wc-' ) === 0 ? substr( $status, 3 ) : $status;
                    $count = count( wc_get_orders( [
                        'limit'  => -1,
                        'status' => $short,
                        'date_created' => $from_ts . '...' . $to_ts,
                        'return' => 'ids',
                    ] ) );
                    $status_counts[ $short ] = $count;
                }

                $total_orders = array_sum( $status_counts );

                // Revenue + refund — sum totals over "real" orders (exclude failed/cancelled).
                $revenue_statuses = [ 'completed', 'processing', 'on-hold' ];
                $revenue_orders   = wc_get_orders( [
                    'limit'        => -1,
                    'status'       => $revenue_statuses,
                    'date_created' => $from_ts . '...' . $to_ts,
                    'return'       => 'objects',
                ] );

                $revenue_total   = 0.0;
                $refund_total    = 0.0;
                $revenue_by_day  = [];
                $unit_aggregate  = [];
                $revenue_aggregate = [];

                foreach ( $revenue_orders as $order ) {
                    $revenue_total += (float) $order->get_total();
                    $refund_total  += (float) $order->get_total_refunded();

                    $day = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
                    if ( $day !== '' ) {
                        if ( ! isset( $revenue_by_day[ $day ] ) ) {
                            $revenue_by_day[ $day ] = 0.0;
                        }
                        $revenue_by_day[ $day ] += (float) $order->get_total();
                    }

                    foreach ( $order->get_items() as $item ) {
                        $pid = $item->get_variation_id() ?: $item->get_product_id();
                        if ( $pid <= 0 ) continue;
                        $qty = (int) $item->get_quantity();
                        $rev = (float) $item->get_total();
                        if ( ! isset( $unit_aggregate[ $pid ] ) ) {
                            $unit_aggregate[ $pid ]    = 0;
                            $revenue_aggregate[ $pid ] = 0.0;
                        }
                        $unit_aggregate[ $pid ]    += $qty;
                        $revenue_aggregate[ $pid ] += $rev;
                    }
                }

                ksort( $revenue_by_day );

                arsort( $unit_aggregate );
                arsort( $revenue_aggregate );

                $top_by_units = [];
                $i = 0;
                foreach ( $unit_aggregate as $pid => $qty ) {
                    if ( $i >= $top_limit ) break;
                    $p = wc_get_product( $pid );
                    $top_by_units[] = [
                        'product_id' => (int) $pid,
                        'name'       => $p ? $p->get_name() : '',
                        'sku'        => $p ? $p->get_sku() : '',
                        'units'      => (int) $qty,
                    ];
                    $i++;
                }

                $top_by_revenue = [];
                $i = 0;
                foreach ( $revenue_aggregate as $pid => $rev ) {
                    if ( $i >= $top_limit ) break;
                    $p = wc_get_product( $pid );
                    $top_by_revenue[] = [
                        'product_id' => (int) $pid,
                        'name'       => $p ? $p->get_name() : '',
                        'sku'        => $p ? $p->get_sku() : '',
                        'revenue'    => round( (float) $rev, 2 ),
                    ];
                    $i++;
                }

                // Products currently on sale.
                $on_sale_ids   = (array) wc_get_product_ids_on_sale();
                $on_sale_count = count( $on_sale_ids );
                $on_sale_sample = [];
                $i = 0;
                foreach ( $on_sale_ids as $pid ) {
                    if ( $i >= 20 ) break;
                    $p = wc_get_product( $pid );
                    if ( ! $p ) continue;
                    $on_sale_sample[] = [
                        'id'   => (int) $pid,
                        'name' => $p->get_name(),
                        'regular_price' => $p->get_regular_price(),
                        'sale_price'    => $p->get_sale_price(),
                    ];
                    $i++;
                }

                // Low-stock products — managed stock only.
                $low_stock_args = [
                    'limit'  => 50,
                    'status' => 'publish',
                    'return' => 'objects',
                    'meta_query' => [
                        [ 'key' => '_manage_stock', 'value' => 'yes' ],
                        [ 'key' => '_stock', 'value' => $low_thresh, 'compare' => '<=', 'type' => 'NUMERIC' ],
                    ],
                ];
                $low_stock_products = wc_get_products( $low_stock_args );
                $low_stock_sample = [];
                foreach ( $low_stock_products as $p ) {
                    if ( ! $p->managing_stock() ) continue;
                    $low_stock_sample[] = [
                        'id'     => (int) $p->get_id(),
                        'name'   => $p->get_name(),
                        'sku'    => $p->get_sku(),
                        'stock'  => $p->get_stock_quantity(),
                    ];
                }

                $refund_rate = $revenue_total > 0 ? round( ( $refund_total / $revenue_total ) * 100, 2 ) : 0.0;

                return [
                    'success' => true,
                    'period'  => [
                        'date_from' => date( 'Y-m-d', $from_ts ),
                        'date_to'   => date( 'Y-m-d', $to_ts ),
                        'days'      => (int) ceil( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ),
                    ],
                    'orders'  => [
                        'total'        => $total_orders,
                        'status_counts' => $status_counts,
                    ],
                    'revenue' => [
                        'total'        => round( $revenue_total, 2 ),
                        'by_day'       => array_map( function( $v ) { return round( $v, 2 ); }, $revenue_by_day ),
                        'note'         => 'Revenue includes orders in completed / processing / on-hold; excludes cancelled / failed.',
                    ],
                    'refunds' => [
                        'total'        => round( $refund_total, 2 ),
                        'rate_percent' => $refund_rate,
                    ],
                    'top_sellers_by_units'   => $top_by_units,
                    'top_sellers_by_revenue' => $top_by_revenue,
                    'on_sale' => [
                        'total'  => $on_sale_count,
                        'sample' => $on_sale_sample,
                        'note'   => 'sample shows up to 20; on_sale.total is the full count.',
                    ],
                    'low_stock' => [
                        'threshold' => $low_thresh,
                        'total'     => count( $low_stock_sample ),
                        'sample'    => $low_stock_sample,
                        'note'      => 'Sample limited to 50 products. Only products with manage_stock=yes are checked.',
                    ],
                    'message' => sprintf( '%d orders in window; revenue $%.2f; refund rate %.2f%%.', $total_orders, $revenue_total, $refund_rate ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }
}
