<?php
/**
 * User and permissions management MCP abilities.
 *
 * Registers Atarim/* abilities for working with WordPress user accounts —
 * full CRUD, role changes, and detection of two-factor authentication
 * status across the three most common 2FA plugins.
 *
 * Uses standard WordPress capability checks (list_users / create_users /
 * edit_users / delete_users / promote_users). Token validation happens
 * upstream in AVCF_MCP::avcf_mcp_authenticate_request, which maps the
 * incoming token to a real WP user — these abilities then check that
 * mapped user's caps via current_user_can(). This is the same model used
 * by every other ability cluster in DoIt.
 *
 * Exposed abilities:
 *   atarim/list-users                List users with role / email / registered date filters.
 *   atarim/get-user                  Single user by ID with full detail, optional meta keys.
 *   atarim/create-user               Create a user account.
 *   atarim/update-user               Update profile fields (not password — separate concern).
 *   atarim/delete-user               Delete a user; refuses to leave content orphaned without explicit reassign target.
 *   atarim/change-user-role          Dedicated role-change ability (replace, add, remove).
 *   atarim/get-2fa-status            Read 2FA enrollment status across Two Factor, WP 2FA, Wordfence.
 *
 * Note: ability names registered here must also be added to the $tools array
 * in doit/class-avcf-mcp.php::avcf_mcp_setup_server() to be exposed by the
 * MCP server.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Users extends AVCF_Abilities_Base {

    /**
     * Register all user management abilities.
     */
    public function register() {

        // ---- list-users ----
        wp_register_ability( 'atarim/list-users', [
            'label'               => 'List Users',
            'description'         => 'Returns user accounts on the site with role, email, display name, and registered date. Supports filtering by role and by registration date range, free-text search across login / email / display_name, and pagination. Does NOT include the last_login field — WordPress does not track this natively; if you need login history, query the activity log plugin if one is installed.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'role' => [
                        'type'        => 'string',
                        'description' => 'Filter by role slug (e.g. "administrator", "editor", "subscriber"). Omit for all roles.',
                        'minLength'   => 1,
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Match login, email, or display name containing this string.',
                        'minLength'   => 1,
                    ],
                    'registered_after' => [
                        'type'        => 'string',
                        'description' => 'Only users registered on or after this date. ISO 8601 or strtotime()-parseable.',
                    ],
                    'registered_before' => [
                        'type'        => 'string',
                        'description' => 'Only users registered on or before this date.',
                    ],
                    'orderby' => [
                        'type'        => 'string',
                        'enum'        => [ 'ID', 'login', 'email', 'display_name', 'registered' ],
                        'default'     => 'registered',
                    ],
                    'order' => [
                        'type'        => 'string',
                        'enum'        => [ 'ASC', 'DESC' ],
                        'default'     => 'DESC',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max users per page. -1 returns all (use carefully on large sites). Defaults to 50.',
                        'default'     => 50,
                        'minimum'     => -1,
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Skip this many users (for pagination).',
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
                    'users'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'             => [ 'type' => 'integer' ],
                                'login'          => [ 'type' => 'string' ],
                                'email'          => [ 'type' => 'string' ],
                                'display_name'   => [ 'type' => 'string' ],
                                'first_name'     => [ 'type' => 'string' ],
                                'last_name'      => [ 'type' => 'string' ],
                                'roles'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                                'registered'     => [ 'type' => 'string' ],
                                'post_count'     => [ 'type' => 'integer' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'returned', 'users' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

                $args = [
                    'orderby' => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'registered',
                    'order'   => ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
                    'number'  => ( $limit > 0 ) ? $limit : -1,
                    'offset'  => $offset,
                ];

                if ( ! empty( $input['role'] ) ) {
                    $args['role'] = sanitize_key( (string) $input['role'] );
                }
                if ( ! empty( $input['search'] ) ) {
                    $args['search']         = '*' . esc_attr( (string) $input['search'] ) . '*';
                    $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
                }

                // Date range filter — WP_User_Query supports date_query.
                if ( ! empty( $input['registered_after'] ) || ! empty( $input['registered_before'] ) ) {
                    $date_query = [];
                    if ( ! empty( $input['registered_after'] ) ) {
                        list( $after, , $err ) = $this->avcf_normalize_post_date( (string) $input['registered_after'] );
                        if ( $err !== null ) {
                            return [ 'total' => 0, 'returned' => 0, 'users' => [], 'message' => 'registered_after: ' . $err ];
                        }
                        $date_query['after'] = $after;
                    }
                    if ( ! empty( $input['registered_before'] ) ) {
                        list( $before, , $err ) = $this->avcf_normalize_post_date( (string) $input['registered_before'] );
                        if ( $err !== null ) {
                            return [ 'total' => 0, 'returned' => 0, 'users' => [], 'message' => 'registered_before: ' . $err ];
                        }
                        $date_query['before'] = $before;
                    }
                    $date_query['inclusive'] = true;
                    $date_query['column']    = 'user_registered';
                    $args['date_query']      = [ $date_query ];
                }

                $query = new \WP_User_Query( $args );
                $items = [];
                foreach ( $query->get_results() as $user ) {
                    $items[] = [
                        'id'           => (int) $user->ID,
                        'login'        => $user->user_login,
                        'email'        => $user->user_email,
                        'display_name' => $user->display_name,
                        'first_name'   => (string) get_user_meta( $user->ID, 'first_name', true ),
                        'last_name'    => (string) get_user_meta( $user->ID, 'last_name', true ),
                        'roles'        => array_values( (array) $user->roles ),
                        'registered'   => $user->user_registered,
                        'post_count'   => (int) count_user_posts( $user->ID ),
                    ];
                }

                return [
                    'total'    => (int) $query->get_total(),
                    'returned' => count( $items ),
                    'users'    => $items,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'list_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // ---- get-user ----
        wp_register_ability( 'atarim/get-user', [
            'label'               => 'Get User',
            'description'         => 'Returns full detail for a single user by ID, login, or email. Optional include_meta_keys reads specific user meta fields (keys starting with "_" are excluded for safety even if requested). Useful before update-user or delete-user.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID. Pass id, login, or email — not multiple.',
                        'minimum'     => 1,
                    ],
                    'login' => [
                        'type'        => 'string',
                        'description' => 'User login. Pass id, login, or email — not multiple.',
                        'minLength'   => 1,
                    ],
                    'email' => [
                        'type'        => 'string',
                        'description' => 'User email. Pass id, login, or email — not multiple.',
                        'minLength'   => 1,
                    ],
                    'include_meta_keys' => [
                        'type'        => 'array',
                        'description' => 'List of user meta keys to read. Keys starting with "_" (private/internal) are excluded even if requested.',
                        'items'       => [ 'type' => 'string' ],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'      => [ 'type' => 'boolean' ],
                    'id'           => [ 'type' => 'integer' ],
                    'login'        => [ 'type' => 'string' ],
                    'email'        => [ 'type' => 'string' ],
                    'display_name' => [ 'type' => 'string' ],
                    'first_name'   => [ 'type' => 'string' ],
                    'last_name'    => [ 'type' => 'string' ],
                    'nickname'     => [ 'type' => 'string' ],
                    'description'  => [ 'type' => 'string' ],
                    'url'          => [ 'type' => 'string' ],
                    'roles'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'registered'   => [ 'type' => 'string' ],
                    'post_count'   => [ 'type' => 'integer' ],
                    'meta'         => [ 'type' => 'object' ],
                    'message'      => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id    = isset( $input['id'] )    ? (int) $input['id'] : 0;
                $login = isset( $input['login'] ) ? sanitize_user( (string) $input['login'] ) : '';
                $email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';

                $provided = (int) ( $id > 0 ) + (int) ( $login !== '' ) + (int) ( $email !== '' );
                if ( $provided === 0 ) {
                    return [ 'success' => false, 'message' => 'Pass one of: id, login, email.' ];
                }
                if ( $provided > 1 ) {
                    return [ 'success' => false, 'message' => 'Pass exactly one of: id, login, email.' ];
                }

                $user = false;
                if ( $id > 0 ) {
                    $user = get_user_by( 'id', $id );
                } elseif ( $login !== '' ) {
                    $user = get_user_by( 'login', $login );
                } else {
                    $user = get_user_by( 'email', $email );
                }

                if ( ! $user ) {
                    return [ 'success' => false, 'message' => 'User not found.' ];
                }

                $result = [
                    'success'      => true,
                    'id'           => (int) $user->ID,
                    'login'        => $user->user_login,
                    'email'        => $user->user_email,
                    'display_name' => $user->display_name,
                    'first_name'   => (string) get_user_meta( $user->ID, 'first_name', true ),
                    'last_name'    => (string) get_user_meta( $user->ID, 'last_name', true ),
                    'nickname'     => (string) get_user_meta( $user->ID, 'nickname', true ),
                    'description' => (string) get_user_meta( $user->ID, 'description', true ),
                    'url'          => $user->user_url,
                    'roles'        => array_values( (array) $user->roles ),
                    'registered'   => $user->user_registered,
                    'post_count'   => (int) count_user_posts( $user->ID ),
                    'message'      => 'OK.',
                ];

                if ( ! empty( $input['include_meta_keys'] ) && is_array( $input['include_meta_keys'] ) ) {
                    $meta = [];
                    foreach ( $input['include_meta_keys'] as $key ) {
                        $key = (string) $key;
                        if ( $key === '' || strpos( $key, '_' ) === 0 ) {
                            continue;
                        }
                        $meta[ $key ] = get_user_meta( $user->ID, $key, true );
                    }
                    $result['meta'] = $meta;
                }

                return $result;
            },
            'permission_callback' => function() {
                return current_user_can( 'list_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // ---- create-user ----
        wp_register_ability( 'atarim/create-user', [
            'label'               => 'Create User',
            'description'         => 'Creates a new user account. Required: login, email, password, role. Optional: first_name, last_name, display_name, url. WordPress validates email format and uniqueness, login uniqueness, password strength is the caller\'s responsibility. The password is never echoed back in the response.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'login' => [
                        'type'        => 'string',
                        'description' => 'Username. Must be unique site-wide; WordPress enforces this.',
                        'minLength'   => 1,
                    ],
                    'email' => [
                        'type'        => 'string',
                        'description' => 'Email address. Must be unique site-wide; WordPress enforces this.',
                        'minLength'   => 3,
                    ],
                    'password' => [
                        'type'        => 'string',
                        'description' => 'Password. The caller is responsible for strength. The password is hashed before storage and never echoed back.',
                        'minLength'   => 1,
                    ],
                    'role' => [
                        'type'        => 'string',
                        'description' => 'Initial role slug (e.g. "subscriber", "editor", "administrator"). Defaults to the site default (usually "subscriber"). Pass an explicit role to be safe.',
                        'minLength'   => 1,
                    ],
                    'first_name' => [
                        'type'        => 'string',
                    ],
                    'last_name' => [
                        'type'        => 'string',
                    ],
                    'display_name' => [
                        'type'        => 'string',
                        'description' => 'Public display name. Defaults to the login if omitted.',
                    ],
                    'url' => [
                        'type'        => 'string',
                        'description' => 'User\'s personal/profile URL.',
                    ],
                ],
                'required'             => [ 'login', 'email', 'password' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'      => [ 'type' => 'boolean' ],
                    'id'           => [ 'type' => 'integer' ],
                    'login'        => [ 'type' => 'string' ],
                    'email'        => [ 'type' => 'string' ],
                    'display_name' => [ 'type' => 'string' ],
                    'roles'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'      => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $login    = isset( $input['login'] )    ? sanitize_user( (string) $input['login'], true ) : '';
                $email    = isset( $input['email'] )    ? sanitize_email( (string) $input['email'] )      : '';
                $password = isset( $input['password'] ) ? (string) $input['password']                     : '';

                if ( $login === '' || $email === '' || $password === '' ) {
                    return [ 'success' => false, 'message' => 'login, email, and password are required.' ];
                }
                if ( ! is_email( $email ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Email "%s" is not a valid email address.', $email ) ];
                }
                if ( username_exists( $login ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'A user with login "%s" already exists.', $login ) ];
                }
                if ( email_exists( $email ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'A user with email "%s" already exists.', $email ) ];
                }

                $role = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';
                if ( $role !== '' && ! wp_roles()->is_role( $role ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Role "%s" does not exist on this site.', $role ) ];
                }

                $userdata = [
                    'user_login'    => $login,
                    'user_email'    => $email,
                    'user_pass'     => $password,
                    'first_name'    => isset( $input['first_name'] )    ? sanitize_text_field( (string) $input['first_name'] )    : '',
                    'last_name'     => isset( $input['last_name'] )     ? sanitize_text_field( (string) $input['last_name'] )     : '',
                    'display_name'  => isset( $input['display_name'] )  ? sanitize_text_field( (string) $input['display_name'] )  : $login,
                    'user_url'      => isset( $input['url'] )           ? esc_url_raw( (string) $input['url'] )                   : '',
                ];
                if ( $role !== '' ) {
                    $userdata['role'] = $role;
                }

                $user_id = wp_insert_user( $userdata );
                if ( is_wp_error( $user_id ) ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $user_id->get_error_message() ];
                }

                $created = get_userdata( $user_id );

                return [
                    'success'      => true,
                    'id'           => (int) $user_id,
                    'login'        => $created->user_login,
                    'email'        => $created->user_email,
                    'display_name' => $created->display_name,
                    'roles'        => array_values( (array) $created->roles ),
                    'message'      => 'User created.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'create_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // ---- update-user ----
        wp_register_ability( 'atarim/update-user', [
            'label'               => 'Update User',
            'description'         => 'Updates profile fields on an existing user. Only id is required; pass any subset of email, display_name, first_name, last_name, nickname, description, url, password. Omitted fields are left unchanged. To change role, use the dedicated change-user-role ability — it has tighter validation. The password, if changed, is hashed and never echoed back.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID.',
                        'minimum'     => 1,
                    ],
                    'email' => [
                        'type'        => 'string',
                        'description' => 'New email. WordPress enforces uniqueness.',
                        'minLength'   => 3,
                    ],
                    'display_name' => [
                        'type'        => 'string',
                    ],
                    'first_name' => [
                        'type'        => 'string',
                    ],
                    'last_name' => [
                        'type'        => 'string',
                    ],
                    'nickname' => [
                        'type'        => 'string',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Bio / about field.',
                    ],
                    'url' => [
                        'type'        => 'string',
                    ],
                    'password' => [
                        'type'        => 'string',
                        'description' => 'New password. Hashed before storage; never echoed back. Caller is responsible for strength.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'      => [ 'type' => 'boolean' ],
                    'id'           => [ 'type' => 'integer' ],
                    'login'        => [ 'type' => 'string' ],
                    'email'        => [ 'type' => 'string' ],
                    'display_name' => [ 'type' => 'string' ],
                    'updated'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'      => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }

                $user = get_userdata( $id );
                if ( ! $user ) {
                    return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $id ) ];
                }

                $userdata = [ 'ID' => $id ];
                $updated  = [];

                if ( array_key_exists( 'email', $input ) ) {
                    $email = sanitize_email( (string) $input['email'] );
                    if ( ! is_email( $email ) ) {
                        return [ 'success' => false, 'message' => sprintf( 'Email "%s" is not a valid email address.', $email ) ];
                    }
                    // Check uniqueness — only error if a DIFFERENT user already has this email.
                    $existing = email_exists( $email );
                    if ( $existing && (int) $existing !== $id ) {
                        return [ 'success' => false, 'message' => sprintf( 'Another user with email "%s" already exists.', $email ) ];
                    }
                    $userdata['user_email'] = $email;
                    $updated[] = 'email';
                }
                if ( array_key_exists( 'display_name', $input ) ) {
                    $userdata['display_name'] = sanitize_text_field( (string) $input['display_name'] );
                    $updated[] = 'display_name';
                }
                if ( array_key_exists( 'first_name', $input ) ) {
                    $userdata['first_name'] = sanitize_text_field( (string) $input['first_name'] );
                    $updated[] = 'first_name';
                }
                if ( array_key_exists( 'last_name', $input ) ) {
                    $userdata['last_name'] = sanitize_text_field( (string) $input['last_name'] );
                    $updated[] = 'last_name';
                }
                if ( array_key_exists( 'nickname', $input ) ) {
                    $userdata['nickname'] = sanitize_text_field( (string) $input['nickname'] );
                    $updated[] = 'nickname';
                }
                if ( array_key_exists( 'description', $input ) ) {
                    $userdata['description'] = wp_kses_post( (string) $input['description'] );
                    $updated[] = 'description';
                }
                if ( array_key_exists( 'url', $input ) ) {
                    $userdata['user_url'] = esc_url_raw( (string) $input['url'] );
                    $updated[] = 'url';
                }
                if ( array_key_exists( 'password', $input ) ) {
                    $pw = (string) $input['password'];
                    if ( $pw === '' ) {
                        return [ 'success' => false, 'message' => 'password cannot be empty.' ];
                    }
                    $userdata['user_pass'] = $pw;
                    $updated[] = 'password';
                }

                if ( count( $userdata ) === 1 ) {
                    return [ 'success' => false, 'message' => 'No fields provided to update.' ];
                }

                $result = wp_update_user( $userdata );
                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $result->get_error_message() ];
                }

                $fresh = get_userdata( $id );

                return [
                    'success'      => true,
                    'id'           => $id,
                    'login'        => $fresh->user_login,
                    'email'        => $fresh->user_email,
                    'display_name' => $fresh->display_name,
                    'updated'      => $updated,
                    'message'      => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // ---- delete-user ----
        wp_register_ability( 'atarim/delete-user', [
            'label'               => 'Delete User',
            'description'         => 'Deletes a user account. WordPress requires deciding what happens to posts owned by the deleted user. By default this ability HARD-FAILS if the user owns any posts and reassign_to is not provided — the response includes the post count so the AI can choose. Pass reassign_to: <user_id> to reassign content to another user, or reassign_to: 0 explicitly to delete posts along with the user. On multisite this removes the user from the current site only (not the network).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID to delete.',
                        'minimum'     => 1,
                    ],
                    'reassign_to' => [
                        'type'        => 'integer',
                        'description' => 'Reassign owned posts to this user ID. Pass 0 to delete posts along with the user. Omit to hard-fail when posts exist (forces the AI to make an explicit choice).',
                        'minimum'     => 0,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'         => [ 'type' => 'boolean' ],
                    'id'              => [ 'type' => 'integer' ],
                    'login'           => [ 'type' => 'string' ],
                    'post_count'      => [ 'type' => 'integer' ],
                    'reassigned_to'   => [ 'type' => 'integer' ],
                    'message'         => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }

                $user = get_userdata( $id );
                if ( ! $user ) {
                    return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $id ) ];
                }

                // Refuse to delete the current user (mapped from token) — same as wp-admin behaviour.
                if ( $id === get_current_user_id() ) {
                    return [ 'success' => false, 'id' => $id, 'login' => $user->user_login, 'message' => 'Cannot delete the user currently authenticated for this request.' ];
                }

                // Ensure plugin functions are available — wp_delete_user lives in wp-admin/includes/user.php.
                if ( ! function_exists( 'wp_delete_user' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }

                $post_count = (int) count_user_posts( $id );

                $reassign_provided = array_key_exists( 'reassign_to', $input );
                $reassign_to       = $reassign_provided ? (int) $input['reassign_to'] : null;

                // Hard-fail when posts exist and reassign target wasn't explicitly given.
                if ( $post_count > 0 && ! $reassign_provided ) {
                    return [
                        'success'    => false,
                        'id'         => $id,
                        'login'      => $user->user_login,
                        'post_count' => $post_count,
                        'message'    => sprintf(
                            'User "%s" owns %d post(s). Pass reassign_to: <user_id> to reassign them, or reassign_to: 0 to delete the posts along with the user.',
                            $user->user_login,
                            $post_count
                        ),
                    ];
                }

                if ( $reassign_provided && $reassign_to > 0 ) {
                    if ( $reassign_to === $id ) {
                        return [ 'success' => false, 'message' => 'reassign_to cannot be the same as the user being deleted.' ];
                    }
                    $target = get_userdata( $reassign_to );
                    if ( ! $target ) {
                        return [ 'success' => false, 'message' => sprintf( 'reassign_to target user %d does not exist.', $reassign_to ) ];
                    }
                }

                // Effective reassign value for wp_delete_user: 0 / positive int, or null which WP treats as "delete posts".
                $effective_reassign = ( $reassign_provided && $reassign_to > 0 ) ? $reassign_to : null;

                if ( is_multisite() ) {
                    if ( ! function_exists( 'remove_user_from_blog' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/ms.php';
                    }
                    $result = remove_user_from_blog( $id, get_current_blog_id(), $effective_reassign );
                } else {
                    $result = wp_delete_user( $id, $effective_reassign );
                }

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'message' => 'Delete failed: ' . $result->get_error_message() ];
                }
                if ( $result === false ) {
                    return [ 'success' => false, 'message' => 'Delete failed: WordPress reported the operation did not complete.' ];
                }

                return [
                    'success'       => true,
                    'id'            => $id,
                    'login'         => $user->user_login,
                    'post_count'    => $post_count,
                    'reassigned_to' => $effective_reassign === null ? 0 : (int) $effective_reassign,
                    'message'       => $effective_reassign === null
                        ? sprintf( 'User "%s" deleted; %d post(s) deleted along with the user.', $user->user_login, $post_count )
                        : sprintf( 'User "%s" deleted; %d post(s) reassigned to user %d.', $user->user_login, $post_count, $effective_reassign ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'delete_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // ---- change-user-role ----
        wp_register_ability( 'atarim/change-user-role', [
            'label'               => 'Change User Role',
            'description'         => 'Changes a user\'s role(s). Three modes: "replace" (default) — set the user\'s roles to exactly the listed role(s), removing all others; "add" — add the listed role(s) without removing existing ones; "remove" — remove the listed role(s) while keeping any others. Most sites use a single role per user — pass mode: replace with a single role for the common case. Refuses to remove the last administrator on the site, and refuses to change the role of the user currently authenticated for this request.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID.',
                        'minimum'     => 1,
                    ],
                    'roles' => [
                        'type'        => 'array',
                        'description' => 'Role slugs to apply.',
                        'items'       => [ 'type' => 'string', 'minLength' => 1 ],
                        'minItems'    => 1,
                    ],
                    'mode' => [
                        'type'        => 'string',
                        'description' => '"replace" (default): roles becomes exactly the listed roles, all others removed. "add": listed roles are added. "remove": listed roles are removed.',
                        'enum'        => [ 'replace', 'add', 'remove' ],
                        'default'     => 'replace',
                    ],
                ],
                'required'             => [ 'id', 'roles' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'id'             => [ 'type' => 'integer' ],
                    'login'          => [ 'type' => 'string' ],
                    'previous_roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'current_roles'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'mode'           => [ 'type' => 'string' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }

                $user = get_userdata( $id );
                if ( ! $user ) {
                    return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $id ) ];
                }
                if ( $id === get_current_user_id() ) {
                    return [ 'success' => false, 'message' => 'Cannot change the role of the user currently authenticated for this request.' ];
                }

                $roles_in = isset( $input['roles'] ) && is_array( $input['roles'] ) ? $input['roles'] : [];
                $roles    = array_values( array_filter( array_map( 'sanitize_key', $roles_in ) ) );
                if ( empty( $roles ) ) {
                    return [ 'success' => false, 'message' => 'roles is required and must be a non-empty array.' ];
                }

                $wp_roles = wp_roles();
                foreach ( $roles as $r ) {
                    if ( ! $wp_roles->is_role( $r ) ) {
                        return [ 'success' => false, 'message' => sprintf( 'Role "%s" does not exist on this site.', $r ) ];
                    }
                }

                $mode = ( isset( $input['mode'] ) && in_array( $input['mode'], [ 'replace', 'add', 'remove' ], true ) )
                    ? $input['mode']
                    : 'replace';

                $previous_roles = array_values( (array) $user->roles );

                // Last-administrator guard. Trigger if the operation would REMOVE administrator from this user
                // AND there are no other administrators on the site.
                $would_lose_admin = false;
                if ( in_array( 'administrator', $previous_roles, true ) ) {
                    if ( $mode === 'replace' && ! in_array( 'administrator', $roles, true ) ) {
                        $would_lose_admin = true;
                    } elseif ( $mode === 'remove' && in_array( 'administrator', $roles, true ) ) {
                        $would_lose_admin = true;
                    }
                }
                if ( $would_lose_admin ) {
                    $other_admins = get_users( [
                        'role'    => 'administrator',
                        'exclude' => [ $id ],
                        'number'  => 1,
                        'fields'  => 'ID',
                    ] );
                    if ( empty( $other_admins ) ) {
                        return [
                            'success' => false,
                            'message' => 'Cannot remove the administrator role from the last admin on the site.',
                        ];
                    }
                }

                // Apply the change.
                $u = new \WP_User( $id );
                if ( $mode === 'replace' ) {
                    // set_role only takes one role. For multi-role replace we wipe and add.
                    if ( count( $roles ) === 1 ) {
                        $u->set_role( $roles[0] );
                    } else {
                        foreach ( $previous_roles as $r ) {
                            $u->remove_role( $r );
                        }
                        foreach ( $roles as $r ) {
                            $u->add_role( $r );
                        }
                    }
                } elseif ( $mode === 'add' ) {
                    foreach ( $roles as $r ) {
                        $u->add_role( $r );
                    }
                } else { // remove
                    foreach ( $roles as $r ) {
                        $u->remove_role( $r );
                    }
                }

                // Reload to confirm.
                $fresh = get_userdata( $id );
                $current_roles = array_values( (array) $fresh->roles );

                return [
                    'success'        => true,
                    'id'             => $id,
                    'login'          => $fresh->user_login,
                    'previous_roles' => $previous_roles,
                    'current_roles'  => $current_roles,
                    'mode'           => $mode,
                    'message'        => sprintf( 'Roles updated (mode: %s). Was: [%s]. Now: [%s].', $mode, implode( ', ', $previous_roles ), implode( ', ', $current_roles ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'promote_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // ---- get-2fa-status ----
        wp_register_ability( 'atarim/get-2fa-status', [
            'label'               => 'Get 2FA Status',
            'description'         => 'Returns the two-factor authentication enrollment status for a user, detecting across the three most common 2FA plugins: Two Factor (the WP.org plugin), WP 2FA (Melapress), and Wordfence. The response includes which provider plugin is in use on the site and whether the specified user has 2FA enabled. If no supported 2FA plugin is detected, provider is "none" and enabled is null (unknown — not false).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID to check.',
                        'minimum'     => 1,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'id'       => [ 'type' => 'integer' ],
                    'provider' => [ 'type' => 'string' ],
                    'enabled'  => [ 'type' => [ 'boolean', 'null' ] ],
                    'methods'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'provider' => 'none', 'enabled' => null, 'message' => 'id is required and must be a positive integer.' ];
                }
                if ( ! get_userdata( $id ) ) {
                    return [ 'success' => false, 'id' => $id, 'provider' => 'none', 'enabled' => null, 'message' => sprintf( 'User %d not found.', $id ) ];
                }

                $status = $this->avcf_detect_2fa_for_user( $id );

                return [
                    'success'  => true,
                    'id'       => $id,
                    'provider' => $status['provider'],
                    'enabled'  => $status['enabled'],
                    'methods'  => $status['methods'],
                    'message'  => $status['provider'] === 'none'
                        ? 'No supported 2FA plugin detected on this site.'
                        : sprintf( '%s plugin detected; user 2FA enabled: %s.',
                            $status['provider'],
                            $status['enabled'] === null ? 'unknown' : ( $status['enabled'] ? 'yes' : 'no' )
                        ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'list_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );
    }

    /**
     * Detect 2FA enrollment for a user across the supported plugins.
     *
     * Returns an array of the form:
     *   [ 'provider' => string, 'enabled' => bool|null, 'methods' => string[] ]
     *
     * Providers are checked in order; the first plugin detected wins. If no
     * supported 2FA plugin is active, returns provider: "none", enabled: null.
     *
     * @param int $user_id
     * @return array
     */
    private function avcf_detect_2fa_for_user( $user_id ) {
        // --- Two Factor (https://wordpress.org/plugins/two-factor/) ---
        if ( class_exists( '\Two_Factor_Core' ) ) {
            // Two_Factor_Core::get_enabled_providers_for_user returns array of provider keys
            // (e.g. 'Two_Factor_Totp', 'Two_Factor_Email'). Empty array means not enrolled.
            $providers = [];
            if ( is_callable( [ '\Two_Factor_Core', 'get_enabled_providers_for_user' ] ) ) {
                $providers = (array) \Two_Factor_Core::get_enabled_providers_for_user( $user_id );
            }
            // Normalize method names for the response.
            $methods = array_map(
                function( $p ) {
                    $p = str_replace( 'Two_Factor_', '', (string) $p );
                    return strtolower( $p );
                },
                $providers
            );
            return [
                'provider' => 'two-factor',
                'enabled'  => ! empty( $providers ),
                'methods'  => array_values( $methods ),
            ];
        }

        // --- WP 2FA (Melapress) ---
        // Sentinel: WP2FA\WP2FA class, or wp2fa_freemius() function (loaded via Freemius).
        if ( class_exists( '\WP2FA\WP2FA' ) || function_exists( 'wp2fa_freemius' ) ) {
            // WP 2FA stores enabled methods in user meta keyed by method.
            // The strongest signal: wp_2fa_totp_key set (TOTP enrolled),
            // or wp_2fa_email_token_key set (email 2FA enrolled),
            // or wp_2fa_backup_methods_enabled non-empty.
            $methods = [];
            if ( get_user_meta( $user_id, 'wp_2fa_totp_key', true ) ) {
                $methods[] = 'totp';
            }
            if ( get_user_meta( $user_id, 'wp_2fa_email_token_key', true ) ) {
                $methods[] = 'email';
            }
            $backup = get_user_meta( $user_id, 'wp_2fa_backup_methods_enabled', true );
            if ( ! empty( $backup ) ) {
                $methods[] = 'backup_codes';
            }
            // Fallback: WP 2FA also sets wp_2fa_user_setup_complete on enrollment.
            $setup_complete = get_user_meta( $user_id, 'wp_2fa_user_setup_complete', true );
            $enabled = ! empty( $methods ) || ! empty( $setup_complete );

            return [
                'provider' => 'wp-2fa',
                'enabled'  => $enabled,
                'methods'  => $methods,
            ];
        }

        // --- Wordfence ---
        // Sentinel: WORDFENCE_VERSION constant or wordfence class.
        if ( defined( 'WORDFENCE_VERSION' ) || class_exists( '\wordfence' ) ) {
            global $wpdb;
            $methods = [];
            $enabled = null; // we may not be able to read Wordfence's table; default to unknown.
            if ( $wpdb ) {
                $table = $wpdb->base_prefix . 'wfconfig';
                // Wordfence Premium stores 2FA secrets in wfTwoFactor for Premium / wfconfig key for free.
                // The cleanest signal we can rely on without poking premium-only internals: a user-meta key.
                $wf_meta = get_user_meta( $user_id, '_wf_twoFactorEnabled', true );
                if ( $wf_meta ) {
                    $enabled = true;
                    $methods[] = 'wordfence';
                }
                // Fallback: check wfTwoFactor secrets table when present.
                if ( $enabled === null ) {
                    $secrets_table = $wpdb->base_prefix . 'wfTwoFactor';
                    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $secrets_table ) );
                    if ( $exists === $secrets_table ) {
                        $row = $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$secrets_table}` WHERE userID = %d",
                            $user_id
                        ) );
                        if ( $row !== null ) {
                            $enabled = ( (int) $row > 0 );
                            if ( $enabled ) {
                                $methods[] = 'wordfence';
                            }
                        }
                    }
                }
                if ( $enabled === null ) {
                    $enabled = false; // Wordfence is active but we found no enrollment signal.
                }
            }
            return [
                'provider' => 'wordfence',
                'enabled'  => $enabled,
                'methods'  => $methods,
            ];
        }

        return [
            'provider' => 'none',
            'enabled'  => null,
            'methods'  => [],
        ];
    }
}
