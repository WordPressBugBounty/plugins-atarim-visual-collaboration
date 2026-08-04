<?php
/**
 * WooCommerce — Customers MCP abilities.
 *
 * Read access to registered customers (users with the customer role) and
 * guest customers (unique billing emails across orders). For mutation,
 * exposes default billing and shipping address writes — the most common
 * customer-level edit. General user CRUD is in the users cluster
 * (atarim/create-user / update-user / delete-user / change-user-role);
 * a "customer" in WooCommerce is just a user with the customer role.
 *
 * Exposed abilities:
 *   atarim/list-customers              Registered customers with stats; optionally include guest emails from orders.
 *   atarim/get-customer                Single customer by ID or email.
 *   atarim/update-customer-billing     Update default billing address fields.
 *   atarim/update-customer-shipping    Update default shipping address fields.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Customers extends AVCF_Abilities_Base {

    /**
     * @var AVCF_WC_Detector
     */
    private $detector;

    /**
     * Writeable billing fields (WooCommerce convention).
     */
    private $billing_fields = [
        'first_name', 'last_name', 'company',
        'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
        'email', 'phone',
    ];

    /**
     * Writeable shipping fields (no email — that's customer-level).
     */
    private $shipping_fields = [
        'first_name', 'last_name', 'company',
        'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
        'phone',
    ];

    public function __construct() {
        $this->detector = new AVCF_WC_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wc_is_available() ) {
            return;
        }

        // ---- list-customers ----
        wp_register_ability( 'atarim/list-customers', [
            'label'               => 'List Customers',
            'description'         => 'Returns registered customers (users with the customer role) with per-customer stats: order count, total spent, last order date. Use include_guests: true to also surface unique billing emails from orders that have no associated user account (guest checkouts). Filters: search across email/name, registered date range, total-spent range, has-orders flag.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'search' => [ 'type' => 'string', 'description' => 'Match login / email / display name.' ],
                    'registered_after' => [ 'type' => 'string', 'description' => 'Only customers registered on or after this date.' ],
                    'registered_before' => [ 'type' => 'string' ],
                    'min_total_spent' => [ 'type' => 'number', 'description' => 'Minimum lifetime spend.' ],
                    'has_orders' => [ 'type' => 'boolean', 'description' => 'Only customers with at least one order.' ],
                    'orderby' => [ 'type' => 'string', 'enum' => [ 'ID', 'registered', 'total_spent', 'order_count' ], 'default' => 'registered' ],
                    'order' => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
                    'limit' => [ 'type' => 'integer', 'minimum' => -1, 'default' => 50 ],
                    'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                    'include_guests' => [
                        'type'        => 'boolean',
                        'description' => 'In addition to registered customers, return unique billing emails from orders that don\'t belong to a registered user (guest checkouts). Default false. Guests are returned with id=0 and a "guest" flag.',
                        'default'     => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'        => [ 'type' => 'integer' ],
                    'returned'     => [ 'type' => 'integer' ],
                    'customers'    => [ 'type' => 'array' ],
                    'guest_count'  => [ 'type' => 'integer' ],
                ],
                'required' => [ 'total', 'returned', 'customers' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $orderby_in = isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'registered';
                $order      = ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

                // For ID/registered we can use WP_User_Query directly. For total_spent/order_count
                // we have to post-process because those are computed from order data.
                $needs_post_sort = in_array( $orderby_in, [ 'total_spent', 'order_count' ], true );

                $args = [
                    'role'    => 'customer',
                    'number'  => $needs_post_sort ? -1 : ( $limit > 0 ? $limit : -1 ),
                    'offset'  => $needs_post_sort ? 0 : $offset,
                    'orderby' => $needs_post_sort ? 'registered' : ( $orderby_in === 'registered' ? 'user_registered' : $orderby_in ),
                    'order'   => $order,
                ];
                if ( ! empty( $input['search'] ) ) {
                    $args['search']         = '*' . esc_attr( (string) $input['search'] ) . '*';
                    $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
                }
                if ( ! empty( $input['registered_after'] ) || ! empty( $input['registered_before'] ) ) {
                    $dq = [ 'inclusive' => true, 'column' => 'user_registered' ];
                    if ( ! empty( $input['registered_after'] ) ) {
                        list( $after, , $err ) = $this->avcf_normalize_post_date( (string) $input['registered_after'] );
                        if ( $err === null ) $dq['after'] = $after;
                    }
                    if ( ! empty( $input['registered_before'] ) ) {
                        list( $before, , $err ) = $this->avcf_normalize_post_date( (string) $input['registered_before'] );
                        if ( $err === null ) $dq['before'] = $before;
                    }
                    if ( count( $dq ) > 2 ) {
                        $args['date_query'] = [ $dq ];
                    }
                }

                $query = new \WP_User_Query( $args );
                $users = $query->get_results();

                $rows = [];
                foreach ( $users as $user ) {
                    $oc = (int) wc_get_customer_order_count( $user->ID );
                    $ts = (float) wc_get_customer_total_spent( $user->ID );

                    if ( ! empty( $input['has_orders'] ) && $oc === 0 ) continue;
                    if ( isset( $input['min_total_spent'] ) && $ts < (float) $input['min_total_spent'] ) continue;

                    $last_order_id = (int) wc_get_customer_last_order( $user->ID ) ?: 0;
                    $last_order_date = '';
                    if ( $last_order_id > 0 ) {
                        $lo = wc_get_order( $last_order_id );
                        if ( $lo && $lo->get_date_created() ) {
                            $last_order_date = $lo->get_date_created()->date( 'Y-m-d H:i:s' );
                        }
                    }

                    $rows[] = [
                        'id'             => (int) $user->ID,
                        'email'          => (string) $user->user_email,
                        'login'          => (string) $user->user_login,
                        'display_name'   => (string) $user->display_name,
                        'first_name'     => (string) get_user_meta( $user->ID, 'first_name', true ),
                        'last_name'      => (string) get_user_meta( $user->ID, 'last_name', true ),
                        'registered'     => $user->user_registered,
                        'order_count'    => $oc,
                        'total_spent'    => round( $ts, 2 ),
                        'last_order_id'  => $last_order_id,
                        'last_order_date'=> $last_order_date,
                        'guest'          => false,
                    ];
                }

                // Sort post-fetch if needed.
                if ( $needs_post_sort ) {
                    usort( $rows, function( $a, $b ) use ( $orderby_in, $order ) {
                        $av = $orderby_in === 'total_spent' ? $a['total_spent'] : $a['order_count'];
                        $bv = $orderby_in === 'total_spent' ? $b['total_spent'] : $b['order_count'];
                        $cmp = ( $av <=> $bv );
                        return $order === 'ASC' ? $cmp : -$cmp;
                    } );
                    $total_count = count( $rows );
                    if ( $offset > 0 ) {
                        $rows = array_slice( $rows, $offset );
                    }
                    if ( $limit > 0 ) {
                        $rows = array_slice( $rows, 0, $limit );
                    }
                } else {
                    $total_count = (int) $query->get_total();
                }

                $guest_count = 0;
                if ( ! empty( $input['include_guests'] ) ) {
                    global $wpdb;
                    $hpos = $this->detector->avcf_wc_is_hpos_enabled();
                    if ( $hpos ) {
                        // HPOS path: query wc_orders for distinct billing_email where customer_id = 0
                        $table_orders = $wpdb->prefix . 'wc_orders';
                        $emails = $wpdb->get_col( $wpdb->prepare(
                            "SELECT DISTINCT billing_email FROM {$table_orders} WHERE customer_id = 0 AND billing_email != '' LIMIT %d",
                            500
                        ) );
                    } else {
                        // Legacy path: pull billing_email post_meta for shop_order posts with author = 0
                        $emails = $wpdb->get_col( $wpdb->prepare(
                            "SELECT DISTINCT pm.meta_value
                             FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                             WHERE p.post_type = 'shop_order'
                               AND p.post_author = 0
                               AND pm.meta_key = '_billing_email'
                               AND pm.meta_value != ''
                             LIMIT %d",
                            500
                        ) );
                    }
                    $emails = is_array( $emails ) ? array_values( array_unique( $emails ) ) : [];
                    foreach ( $emails as $email ) {
                        if ( $email === '' ) continue;
                        // Skip if a registered user has this email (already in the list).
                        if ( get_user_by( 'email', $email ) ) continue;
                        $rows[] = [
                            'id'             => 0,
                            'email'          => (string) $email,
                            'login'          => '',
                            'display_name'   => '',
                            'first_name'     => '',
                            'last_name'      => '',
                            'registered'     => '',
                            'order_count'    => 0,
                            'total_spent'    => 0.0,
                            'last_order_id'  => 0,
                            'last_order_date'=> '',
                            'guest'          => true,
                        ];
                        $guest_count++;
                    }
                }

                return [
                    'total'       => $total_count + $guest_count,
                    'returned'    => count( $rows ),
                    'customers'   => $rows,
                    'guest_count' => $guest_count,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'list_users' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-customer ----
        wp_register_ability( 'atarim/get-customer', [
            'label'               => 'Get Customer',
            'description'         => 'Full detail for one customer by user ID or email. Includes profile info, lifetime stats, default billing and shipping addresses. Works for both registered customers (full detail) and guests (limited detail — only what\'s on their orders).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'email' => [ 'type' => 'string', 'description' => 'Customer email. Works for both registered users and guests.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'customer' => [ 'type' => 'object' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
                $email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';

                if ( $id <= 0 && $email === '' ) {
                    return [ 'success' => false, 'message' => 'Pass id or email.' ];
                }
                if ( $id <= 0 && $email !== '' ) {
                    $u = get_user_by( 'email', $email );
                    if ( $u ) $id = (int) $u->ID;
                }

                if ( $id <= 0 ) {
                    // Guest path — query orders by billing_email and return aggregate-only detail.
                    if ( $email === '' ) {
                        return [ 'success' => false, 'message' => 'Customer not found.' ];
                    }
                    $orders = wc_get_orders( [
                        'limit'         => -1,
                        'billing_email' => $email,
                        'return'        => 'objects',
                    ] );
                    if ( empty( $orders ) ) {
                        return [ 'success' => false, 'message' => sprintf( 'No customer or orders found for email "%s".', $email ) ];
                    }
                    $first_order = $orders[ count( $orders ) - 1 ];
                    $last_order  = $orders[0];
                    $total       = 0.0;
                    foreach ( $orders as $o ) {
                        if ( in_array( $o->get_status(), [ 'completed', 'processing', 'on-hold' ], true ) ) {
                            $total += (float) $o->get_total();
                        }
                    }
                    return [
                        'success'  => true,
                        'customer' => [
                            'id'             => 0,
                            'guest'          => true,
                            'email'          => $email,
                            'first_name'     => (string) $first_order->get_billing_first_name(),
                            'last_name'      => (string) $first_order->get_billing_last_name(),
                            'order_count'    => count( $orders ),
                            'total_spent'    => round( $total, 2 ),
                            'last_order_id'  => (int) $last_order->get_id(),
                            'last_order_date'=> $last_order->get_date_created() ? $last_order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                            'billing'        => $first_order->get_address( 'billing' ),
                            'shipping'       => $first_order->get_address( 'shipping' ),
                        ],
                        'message'  => sprintf( 'Guest customer with %d order(s).', count( $orders ) ),
                    ];
                }

                $user = get_userdata( $id );
                if ( ! $user ) {
                    return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $id ) ];
                }

                $oc = (int) wc_get_customer_order_count( $id );
                $ts = (float) wc_get_customer_total_spent( $id );
                $last_order_id = (int) wc_get_customer_last_order( $id ) ?: 0;
                $last_order_date = '';
                if ( $last_order_id > 0 ) {
                    $lo = wc_get_order( $last_order_id );
                    if ( $lo && $lo->get_date_created() ) {
                        $last_order_date = $lo->get_date_created()->date( 'Y-m-d H:i:s' );
                    }
                }

                $billing  = [];
                $shipping = [];
                foreach ( $this->billing_fields as $f ) {
                    $billing[ $f ] = (string) get_user_meta( $id, 'billing_' . $f, true );
                }
                foreach ( $this->shipping_fields as $f ) {
                    $shipping[ $f ] = (string) get_user_meta( $id, 'shipping_' . $f, true );
                }

                return [
                    'success'  => true,
                    'customer' => [
                        'id'             => $id,
                        'guest'          => false,
                        'email'          => (string) $user->user_email,
                        'login'          => (string) $user->user_login,
                        'display_name'   => (string) $user->display_name,
                        'first_name'     => (string) get_user_meta( $id, 'first_name', true ),
                        'last_name'      => (string) get_user_meta( $id, 'last_name', true ),
                        'registered'     => $user->user_registered,
                        'order_count'    => $oc,
                        'total_spent'    => round( $ts, 2 ),
                        'last_order_id'  => $last_order_id,
                        'last_order_date'=> $last_order_date,
                        'billing'        => $billing,
                        'shipping'       => $shipping,
                    ],
                    'message'  => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'list_users' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-customer-billing ----
        wp_register_ability( 'atarim/update-customer-billing', [
            'label'               => 'Update Customer Billing Address',
            'description'         => 'Update a registered customer\'s default billing address. Partial — pass only fields you want to change. country is validated against WooCommerce\'s known country codes; state is validated when the country has a known state list (US, CA, IN, etc.) and free-form otherwise. Does NOT apply to past orders — those have their own billing snapshots. Affects future checkouts where the customer doesn\'t override the address.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'first_name' => [ 'type' => 'string' ],
                    'last_name' => [ 'type' => 'string' ],
                    'company' => [ 'type' => 'string' ],
                    'address_1' => [ 'type' => 'string' ],
                    'address_2' => [ 'type' => 'string' ],
                    'city' => [ 'type' => 'string' ],
                    'state' => [ 'type' => 'string', 'description' => 'State / region code (e.g. "CA"). Free-form if the country has no known state list.' ],
                    'postcode' => [ 'type' => 'string' ],
                    'country' => [ 'type' => 'string', 'description' => 'ISO-3166 alpha-2 country code (e.g. "US", "GB").' ],
                    'email' => [ 'type' => 'string', 'description' => 'Billing-specific email. May differ from the customer\'s account email.' ],
                    'phone' => [ 'type' => 'string' ],
                ],
                'required' => [ 'customer_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'customer_id' => [ 'type' => 'integer' ],
                    'updated'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return $this->avcf_update_address( $input, 'billing', $this->billing_fields );
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_users' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- update-customer-shipping ----
        wp_register_ability( 'atarim/update-customer-shipping', [
            'label'               => 'Update Customer Shipping Address',
            'description'         => 'Update a registered customer\'s default shipping address. Partial — pass only fields you want to change. Same country/state validation as billing. No email field (shipping addresses don\'t have one — billing carries the customer email).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'first_name' => [ 'type' => 'string' ],
                    'last_name' => [ 'type' => 'string' ],
                    'company' => [ 'type' => 'string' ],
                    'address_1' => [ 'type' => 'string' ],
                    'address_2' => [ 'type' => 'string' ],
                    'city' => [ 'type' => 'string' ],
                    'state' => [ 'type' => 'string' ],
                    'postcode' => [ 'type' => 'string' ],
                    'country' => [ 'type' => 'string' ],
                    'phone' => [ 'type' => 'string' ],
                ],
                'required' => [ 'customer_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'customer_id' => [ 'type' => 'integer' ],
                    'updated'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return $this->avcf_update_address( $input, 'shipping', $this->shipping_fields );
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_users' ) || current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );
    }

    /**
     * Shared helper for billing and shipping address updates.
     *
     * @param array  $input            Input arguments from the ability call.
     * @param string $prefix           'billing' or 'shipping'.
     * @param array  $allowed_fields   Whitelist of field names for this prefix.
     */
    private function avcf_update_address( $input, $prefix, $allowed_fields ) {
        $customer_id = isset( $input['customer_id'] ) ? (int) $input['customer_id'] : 0;
        if ( $customer_id <= 0 ) {
            return [ 'success' => false, 'message' => 'customer_id is required.' ];
        }
        $user = get_userdata( $customer_id );
        if ( ! $user ) {
            return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $customer_id ) ];
        }

        // Country/state validation.
        if ( array_key_exists( 'country', $input ) && $input['country'] !== '' ) {
            $country = strtoupper( wc_clean( (string) $input['country'] ) );
            $countries = WC()->countries->get_countries();
            if ( ! isset( $countries[ $country ] ) ) {
                return [ 'success' => false, 'customer_id' => $customer_id, 'message' => sprintf( 'Country code "%s" is not recognised by WooCommerce.', $country ) ];
            }
            $input['country'] = $country;

            if ( array_key_exists( 'state', $input ) && $input['state'] !== '' ) {
                $states = WC()->countries->get_states( $country );
                if ( is_array( $states ) && ! empty( $states ) ) {
                    $state = strtoupper( wc_clean( (string) $input['state'] ) );
                    if ( ! isset( $states[ $state ] ) ) {
                        return [ 'success' => false, 'customer_id' => $customer_id, 'message' => sprintf( 'State code "%s" is not valid for country "%s".', $state, $country ) ];
                    }
                    $input['state'] = $state;
                }
                // If country has no known states list, accept free-form.
            }
        }

        if ( array_key_exists( 'email', $input ) && $input['email'] !== '' ) {
            $email = sanitize_email( (string) $input['email'] );
            if ( ! is_email( $email ) ) {
                return [ 'success' => false, 'customer_id' => $customer_id, 'message' => sprintf( 'Email "%s" is not a valid address.', $input['email'] ) ];
            }
            $input['email'] = $email;
        }

        $updated = [];
        foreach ( $allowed_fields as $field ) {
            if ( ! array_key_exists( $field, $input ) ) continue;
            $value = (string) $input[ $field ];
            $meta_key = $prefix . '_' . $field;
            update_user_meta( $customer_id, $meta_key, sanitize_text_field( $value ) );
            $updated[] = $field;
        }

        if ( empty( $updated ) ) {
            return [ 'success' => false, 'customer_id' => $customer_id, 'message' => 'No fields provided to update.' ];
        }

        return [
            'success'     => true,
            'customer_id' => $customer_id,
            'updated'     => $updated,
            'message'     => sprintf( '%s address updated: %s.', ucfirst( $prefix ), implode( ', ', $updated ) ),
        ];
    }
}
