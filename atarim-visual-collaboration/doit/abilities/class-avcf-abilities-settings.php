<?php
/**
 * WordPress settings and configuration MCP abilities.
 *
 * Read and write the settings exposed by WordPress's Settings → General /
 * Reading / Discussion / Permalinks / Media admin screens, plus read-only
 * access to the live robots.txt output and the site's .htaccess file.
 *
 * Each settings page gets a matched get / update pair. Reads return the
 * full settings group; writes accept any subset of the same fields and
 * leave omitted fields unchanged. This matches the pattern established by
 * get-content / update-content.
 *
 * Exposed abilities:
 *   atarim/get-general-settings      Site identity, admin email, timezone, locale, date/time format.
 *   atarim/update-general-settings   Update any subset of the above.
 *   atarim/get-reading-settings      Front page config, posts-per-page, search engine visibility.
 *   atarim/update-reading-settings   Update any subset, with page_on_front validation.
 *   atarim/get-discussion-settings   ~20 options grouped (permissions, threading, notifications, moderation, spam, avatars).
 *   atarim/update-discussion-settings  Update any subset.
 *   atarim/get-permalink-settings    Structure + category/tag base.
 *   atarim/update-permalink-settings Update + flush rewrite rules.
 *   atarim/get-media-settings        Thumbnail / medium / large sizes + yearmonth folder flag.
 *   atarim/update-media-settings     Update any subset.
 *   atarim/get-robots-txt            Current live robots.txt content (default + robots_txt filter applied).
 *   atarim/get-htaccess              Raw .htaccess file contents from ABSPATH, or empty if not present.
 *
 * Note: ability names registered here must also be added to the $tools
 * array in doit/class-avcf-mcp.php::avcf_mcp_setup_server() to be exposed
 * by the MCP server.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Settings extends AVCF_Abilities_Base {

    public function register() {

        // ---- get-general-settings ----
        wp_register_ability( 'atarim/get-general-settings', [
            'label'               => 'Get General Settings',
            'description'         => 'Returns the site identity settings shown on Settings → General: site title, tagline, admin email, site URL, home URL, locale, timezone, date format, time format, start of week.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'blogname'        => [ 'type' => 'string' ],
                    'blogdescription' => [ 'type' => 'string' ],
                    'admin_email'     => [ 'type' => 'string' ],
                    'siteurl'         => [ 'type' => 'string' ],
                    'home'            => [ 'type' => 'string' ],
                    'locale'          => [ 'type' => 'string' ],
                    'timezone_string' => [ 'type' => 'string' ],
                    'gmt_offset'      => [ 'type' => 'string' ],
                    'date_format'     => [ 'type' => 'string' ],
                    'time_format'     => [ 'type' => 'string' ],
                    'start_of_week'   => [ 'type' => 'integer' ],
                ],
                'required' => [ 'blogname', 'admin_email' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return [
                    'blogname'        => (string) get_option( 'blogname', '' ),
                    'blogdescription' => (string) get_option( 'blogdescription', '' ),
                    'admin_email'     => (string) get_option( 'admin_email', '' ),
                    'siteurl'         => (string) get_option( 'siteurl', '' ),
                    'home'            => (string) get_option( 'home', '' ),
                    'locale'          => (string) get_locale(),
                    'timezone_string' => (string) get_option( 'timezone_string', '' ),
                    'gmt_offset'      => (string) get_option( 'gmt_offset', '0' ),
                    'date_format'     => (string) get_option( 'date_format', '' ),
                    'time_format'     => (string) get_option( 'time_format', '' ),
                    'start_of_week'   => (int) get_option( 'start_of_week', 1 ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-general-settings ----
        wp_register_ability( 'atarim/update-general-settings', [
            'label'               => 'Update General Settings',
            'description'         => 'Updates any subset of the General settings. Pass only the fields you want to change; omitted fields are left unchanged. NOTE: admin_email is set DIRECTLY without WordPress\'s "verify the new email" double-opt-in flow, since AI workflows are admin-trusted and there is no human at the new address to click the confirmation link. Timezone accepts either a timezone_string (Region/City, recommended) OR a gmt_offset (-12 to 14, half-hour increments allowed) — not both. Setting one clears the other to match WordPress\'s own admin behaviour.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'blogname'        => [ 'type' => 'string', 'description' => 'Site title.' ],
                    'blogdescription' => [ 'type' => 'string', 'description' => 'Site tagline.' ],
                    'admin_email'     => [ 'type' => 'string', 'description' => 'Administrator email address. Validated for format; set directly without verification flow.' ],
                    'siteurl'         => [ 'type' => 'string', 'description' => 'WordPress address (URL). Be cautious — wrong values can lock the admin out.' ],
                    'home'            => [ 'type' => 'string', 'description' => 'Site URL the public sees. Often equal to siteurl.' ],
                    'timezone_string' => [ 'type' => 'string', 'description' => 'Region/City timezone (e.g. "America/New_York"). Setting this clears gmt_offset.' ],
                    'gmt_offset'      => [ 'type' => [ 'string', 'number' ], 'description' => 'Numeric offset from UTC (-12 to 14, half-hour increments). Setting this clears timezone_string.' ],
                    'date_format'     => [ 'type' => 'string', 'description' => 'PHP date format string (e.g. "F j, Y").' ],
                    'time_format'     => [ 'type' => 'string', 'description' => 'PHP date format string for time (e.g. "g:i a").' ],
                    'start_of_week'   => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'description' => '0=Sunday, 1=Monday, ... 6=Saturday.' ],
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

                if ( array_key_exists( 'blogname', $input ) ) {
                    update_option( 'blogname', sanitize_text_field( (string) $input['blogname'] ) );
                    $updated[] = 'blogname';
                }
                if ( array_key_exists( 'blogdescription', $input ) ) {
                    update_option( 'blogdescription', sanitize_text_field( (string) $input['blogdescription'] ) );
                    $updated[] = 'blogdescription';
                }
                if ( array_key_exists( 'admin_email', $input ) ) {
                    $email = sanitize_email( (string) $input['admin_email'] );
                    if ( ! is_email( $email ) ) {
                        return [ 'success' => false, 'updated' => $updated, 'message' => sprintf( 'admin_email "%s" is not a valid email address.', $input['admin_email'] ) ];
                    }
                    // Bypass WP's pending-verification flow — write directly.
                    update_option( 'admin_email', $email );
                    // Clear any pending verification state from a previous admin-UI change.
                    delete_option( 'adminhash' );
                    delete_option( 'new_admin_email' );
                    $updated[] = 'admin_email';
                }
                if ( array_key_exists( 'siteurl', $input ) ) {
                    update_option( 'siteurl', esc_url_raw( (string) $input['siteurl'] ) );
                    $updated[] = 'siteurl';
                }
                if ( array_key_exists( 'home', $input ) ) {
                    update_option( 'home', esc_url_raw( (string) $input['home'] ) );
                    $updated[] = 'home';
                }
                // Timezone: setting one clears the other (WP admin behaviour).
                if ( array_key_exists( 'timezone_string', $input ) && array_key_exists( 'gmt_offset', $input ) ) {
                    return [ 'success' => false, 'updated' => $updated, 'message' => 'Pass either timezone_string OR gmt_offset, not both.' ];
                }
                if ( array_key_exists( 'timezone_string', $input ) ) {
                    $tz = (string) $input['timezone_string'];
                    if ( $tz !== '' && ! in_array( $tz, timezone_identifiers_list(), true ) ) {
                        return [ 'success' => false, 'updated' => $updated, 'message' => sprintf( 'Unknown timezone "%s". Use a Region/City identifier (e.g. "America/New_York").', $tz ) ];
                    }
                    update_option( 'timezone_string', $tz );
                    update_option( 'gmt_offset', '0' );
                    $updated[] = 'timezone_string';
                }
                if ( array_key_exists( 'gmt_offset', $input ) ) {
                    $offset = (float) $input['gmt_offset'];
                    if ( $offset < -12 || $offset > 14 ) {
                        return [ 'success' => false, 'updated' => $updated, 'message' => 'gmt_offset must be between -12 and 14.' ];
                    }
                    update_option( 'gmt_offset', (string) $offset );
                    update_option( 'timezone_string', '' );
                    $updated[] = 'gmt_offset';
                }
                if ( array_key_exists( 'date_format', $input ) ) {
                    update_option( 'date_format', sanitize_text_field( (string) $input['date_format'] ) );
                    $updated[] = 'date_format';
                }
                if ( array_key_exists( 'time_format', $input ) ) {
                    update_option( 'time_format', sanitize_text_field( (string) $input['time_format'] ) );
                    $updated[] = 'time_format';
                }
                if ( array_key_exists( 'start_of_week', $input ) ) {
                    $sow = (int) $input['start_of_week'];
                    if ( $sow < 0 || $sow > 6 ) {
                        return [ 'success' => false, 'updated' => $updated, 'message' => 'start_of_week must be between 0 (Sunday) and 6 (Saturday).' ];
                    }
                    update_option( 'start_of_week', $sow );
                    $updated[] = 'start_of_week';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                return [
                    'success' => true,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-reading-settings ----
        wp_register_ability( 'atarim/get-reading-settings', [
            'label'               => 'Get Reading Settings',
            'description'         => 'Returns the Settings → Reading values: front page display (latest posts vs. static page), the static page IDs if applicable, posts per page, RSS post count, RSS excerpt mode, and search engine visibility ("discourage search engines" checkbox).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'show_on_front'              => [ 'type' => 'string' ],
                    'page_on_front'              => [ 'type' => 'integer' ],
                    'page_for_posts'             => [ 'type' => 'integer' ],
                    'posts_per_page'             => [ 'type' => 'integer' ],
                    'posts_per_rss'              => [ 'type' => 'integer' ],
                    'rss_use_excerpt'            => [ 'type' => 'boolean' ],
                    'search_engine_visible'      => [ 'type' => 'boolean' ],
                ],
                'required' => [ 'show_on_front', 'posts_per_page' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return [
                    'show_on_front'         => (string) get_option( 'show_on_front', 'posts' ),
                    'page_on_front'         => (int) get_option( 'page_on_front', 0 ),
                    'page_for_posts'        => (int) get_option( 'page_for_posts', 0 ),
                    'posts_per_page'        => (int) get_option( 'posts_per_page', 10 ),
                    'posts_per_rss'         => (int) get_option( 'posts_per_rss', 10 ),
                    'rss_use_excerpt'       => (bool) get_option( 'rss_use_excerpt', 0 ),
                    // blog_public stores 1=allow, 0=discourage. Surface as a clearer name.
                    'search_engine_visible' => (bool) get_option( 'blog_public', 1 ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-reading-settings ----
        wp_register_ability( 'atarim/update-reading-settings', [
            'label'               => 'Update Reading Settings',
            'description'         => 'Updates any subset of Settings → Reading. Pass show_on_front: "page" to use a static homepage — requires page_on_front to be set to an actual published page ID. To revert to latest-posts homepage, pass show_on_front: "posts" (page_on_front and page_for_posts are then ignored). search_engine_visible: false sets WordPress\'s "discourage search engines" flag.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'show_on_front'         => [ 'type' => 'string', 'enum' => [ 'posts', 'page' ] ],
                    'page_on_front'         => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Page ID for the static homepage. Only meaningful if show_on_front is "page".' ],
                    'page_for_posts'        => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Page ID used as the posts archive. Optional even when show_on_front is "page".' ],
                    'posts_per_page'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ],
                    'posts_per_rss'         => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ],
                    'rss_use_excerpt'       => [ 'type' => 'boolean' ],
                    'search_engine_visible' => [ 'type' => 'boolean', 'description' => 'false sets the "discourage search engines" flag (stored as blog_public=0).' ],
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

                // Resolve the effective show_on_front for validation purposes.
                $effective_show_on_front = array_key_exists( 'show_on_front', $input )
                    ? (string) $input['show_on_front']
                    : (string) get_option( 'show_on_front', 'posts' );

                if ( array_key_exists( 'show_on_front', $input ) ) {
                    $sof = (string) $input['show_on_front'];
                    if ( ! in_array( $sof, [ 'posts', 'page' ], true ) ) {
                        return [ 'success' => false, 'updated' => $updated, 'message' => 'show_on_front must be "posts" or "page".' ];
                    }
                    update_option( 'show_on_front', $sof );
                    $updated[] = 'show_on_front';
                }

                if ( array_key_exists( 'page_on_front', $input ) ) {
                    $pid = (int) $input['page_on_front'];
                    if ( $pid > 0 && $effective_show_on_front === 'page' ) {
                        $page = get_post( $pid );
                        if ( ! $page || $page->post_type !== 'page' || $page->post_status !== 'publish' ) {
                            return [ 'success' => false, 'updated' => $updated, 'message' => sprintf( 'page_on_front %d is not a published page.', $pid ) ];
                        }
                    }
                    update_option( 'page_on_front', $pid );
                    $updated[] = 'page_on_front';
                }

                if ( array_key_exists( 'page_for_posts', $input ) ) {
                    $pid = (int) $input['page_for_posts'];
                    if ( $pid > 0 ) {
                        $page = get_post( $pid );
                        if ( ! $page || $page->post_type !== 'page' || $page->post_status !== 'publish' ) {
                            return [ 'success' => false, 'updated' => $updated, 'message' => sprintf( 'page_for_posts %d is not a published page.', $pid ) ];
                        }
                    }
                    update_option( 'page_for_posts', $pid );
                    $updated[] = 'page_for_posts';
                }

                if ( array_key_exists( 'posts_per_page', $input ) ) {
                    update_option( 'posts_per_page', max( 1, (int) $input['posts_per_page'] ) );
                    $updated[] = 'posts_per_page';
                }
                if ( array_key_exists( 'posts_per_rss', $input ) ) {
                    update_option( 'posts_per_rss', max( 1, (int) $input['posts_per_rss'] ) );
                    $updated[] = 'posts_per_rss';
                }
                if ( array_key_exists( 'rss_use_excerpt', $input ) ) {
                    update_option( 'rss_use_excerpt', $input['rss_use_excerpt'] ? 1 : 0 );
                    $updated[] = 'rss_use_excerpt';
                }
                if ( array_key_exists( 'search_engine_visible', $input ) ) {
                    update_option( 'blog_public', $input['search_engine_visible'] ? 1 : 0 );
                    $updated[] = 'search_engine_visible';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                return [
                    'success' => true,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-discussion-settings ----
        wp_register_ability( 'atarim/get-discussion-settings', [
            'label'               => 'Get Discussion Settings',
            'description'         => 'Returns the Settings → Discussion values, grouped logically for clarity: permissions (default statuses, registration requirement, name/email requirement, old-post closing), threading (depth, pagination, order), notifications (admin email on new comment / pending moderation), moderation (require approval, previously-approved trust, link threshold), spam (moderation_keys = "review if contains" blocklist; disallowed_keys = "auto-trash if contains" disallowed list), avatars (visibility, max rating, default style).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'permissions'   => [ 'type' => 'object' ],
                    'threading'     => [ 'type' => 'object' ],
                    'notifications' => [ 'type' => 'object' ],
                    'moderation'    => [ 'type' => 'object' ],
                    'spam'          => [ 'type' => 'object' ],
                    'avatars'       => [ 'type' => 'object' ],
                ],
                'required' => [ 'permissions', 'threading', 'notifications', 'moderation', 'spam', 'avatars' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return [
                    'permissions' => [
                        'default_comment_status'        => (string) get_option( 'default_comment_status', 'open' ),
                        'default_ping_status'           => (string) get_option( 'default_ping_status', 'open' ),
                        'default_pingback_flag'         => (bool) get_option( 'default_pingback_flag', 1 ),
                        'comment_registration'          => (bool) get_option( 'comment_registration', 0 ),
                        'require_name_email'            => (bool) get_option( 'require_name_email', 1 ),
                        'close_comments_for_old_posts'  => (bool) get_option( 'close_comments_for_old_posts', 0 ),
                        'close_comments_days_old'       => (int) get_option( 'close_comments_days_old', 14 ),
                    ],
                    'threading' => [
                        'thread_comments'        => (bool) get_option( 'thread_comments', 1 ),
                        'thread_comments_depth'  => (int) get_option( 'thread_comments_depth', 5 ),
                        'page_comments'          => (bool) get_option( 'page_comments', 0 ),
                        'comments_per_page'      => (int) get_option( 'comments_per_page', 50 ),
                        'default_comments_page'  => (string) get_option( 'default_comments_page', 'newest' ),
                        'comment_order'          => (string) get_option( 'comment_order', 'asc' ),
                    ],
                    'notifications' => [
                        'comments_notify'   => (bool) get_option( 'comments_notify', 1 ),
                        'moderation_notify' => (bool) get_option( 'moderation_notify', 1 ),
                    ],
                    'moderation' => [
                        'comment_moderation'           => (bool) get_option( 'comment_moderation', 0 ),
                        'comment_previously_approved'  => (bool) get_option( 'comment_previously_approved', 1 ),
                        'comment_max_links'            => (int) get_option( 'comment_max_links', 2 ),
                    ],
                    'spam' => [
                        // moderation_keys = words that send a comment to the moderation queue.
                        // disallowed_keys = words that send the comment to trash immediately.
                        'moderation_keys' => (string) get_option( 'moderation_keys', '' ),
                        'disallowed_keys' => (string) get_option( 'disallowed_keys', '' ),
                    ],
                    'avatars' => [
                        'show_avatars'    => (bool) get_option( 'show_avatars', 1 ),
                        'avatar_rating'   => (string) get_option( 'avatar_rating', 'G' ),
                        'avatar_default'  => (string) get_option( 'avatar_default', 'mystery' ),
                    ],
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-discussion-settings ----
        wp_register_ability( 'atarim/update-discussion-settings', [
            'label'               => 'Update Discussion Settings',
            'description'         => 'Updates any subset of Settings → Discussion. Same grouped shape as get-discussion-settings; pass any of the six groups (permissions / threading / notifications / moderation / spam / avatars) with any subset of fields. Omitted fields and omitted groups are left unchanged.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'permissions' => [
                        'type'       => 'object',
                        'properties' => [
                            'default_comment_status'       => [ 'type' => 'string', 'enum' => [ 'open', 'closed' ] ],
                            'default_ping_status'          => [ 'type' => 'string', 'enum' => [ 'open', 'closed' ] ],
                            'default_pingback_flag'        => [ 'type' => 'boolean' ],
                            'comment_registration'         => [ 'type' => 'boolean' ],
                            'require_name_email'           => [ 'type' => 'boolean' ],
                            'close_comments_for_old_posts' => [ 'type' => 'boolean' ],
                            'close_comments_days_old'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'threading' => [
                        'type'       => 'object',
                        'properties' => [
                            'thread_comments'        => [ 'type' => 'boolean' ],
                            'thread_comments_depth'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ],
                            'page_comments'          => [ 'type' => 'boolean' ],
                            'comments_per_page'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ],
                            'default_comments_page'  => [ 'type' => 'string', 'enum' => [ 'newest', 'oldest' ] ],
                            'comment_order'          => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'notifications' => [
                        'type'       => 'object',
                        'properties' => [
                            'comments_notify'   => [ 'type' => 'boolean' ],
                            'moderation_notify' => [ 'type' => 'boolean' ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'moderation' => [
                        'type'       => 'object',
                        'properties' => [
                            'comment_moderation'           => [ 'type' => 'boolean' ],
                            'comment_previously_approved'  => [ 'type' => 'boolean' ],
                            'comment_max_links'            => [ 'type' => 'integer', 'minimum' => 0 ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'spam' => [
                        'type'       => 'object',
                        'properties' => [
                            'moderation_keys' => [ 'type' => 'string', 'description' => 'Newline-separated list of words/phrases. A comment containing any of these goes to the moderation queue.' ],
                            'disallowed_keys' => [ 'type' => 'string', 'description' => 'Newline-separated list of words/phrases. A comment containing any of these is sent to trash immediately.' ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'avatars' => [
                        'type'       => 'object',
                        'properties' => [
                            'show_avatars'   => [ 'type' => 'boolean' ],
                            'avatar_rating'  => [ 'type' => 'string', 'enum' => [ 'G', 'PG', 'R', 'X' ] ],
                            'avatar_default' => [ 'type' => 'string', 'description' => 'Avatar style: mystery, blank, gravatar_default, identicon, wavatar, monsterid, retro.' ],
                        ],
                        'additionalProperties' => false,
                    ],
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

                // Map each group's fields to (option_key, coercion) tuples and walk them.
                $bool_to_int = function( $v ) { return $v ? 1 : 0; };
                $as_string   = function( $v ) { return sanitize_text_field( (string) $v ); };
                $as_textarea = function( $v ) { return sanitize_textarea_field( (string) $v ); };
                $as_int      = function( $v ) { return (int) $v; };

                $groups = [
                    'permissions' => [
                        'default_comment_status'       => [ 'default_comment_status', $as_string ],
                        'default_ping_status'          => [ 'default_ping_status', $as_string ],
                        'default_pingback_flag'        => [ 'default_pingback_flag', $bool_to_int ],
                        'comment_registration'         => [ 'comment_registration', $bool_to_int ],
                        'require_name_email'           => [ 'require_name_email', $bool_to_int ],
                        'close_comments_for_old_posts' => [ 'close_comments_for_old_posts', $bool_to_int ],
                        'close_comments_days_old'      => [ 'close_comments_days_old', $as_int ],
                    ],
                    'threading' => [
                        'thread_comments'        => [ 'thread_comments', $bool_to_int ],
                        'thread_comments_depth'  => [ 'thread_comments_depth', $as_int ],
                        'page_comments'          => [ 'page_comments', $bool_to_int ],
                        'comments_per_page'      => [ 'comments_per_page', $as_int ],
                        'default_comments_page'  => [ 'default_comments_page', $as_string ],
                        'comment_order'          => [ 'comment_order', $as_string ],
                    ],
                    'notifications' => [
                        'comments_notify'   => [ 'comments_notify', $bool_to_int ],
                        'moderation_notify' => [ 'moderation_notify', $bool_to_int ],
                    ],
                    'moderation' => [
                        'comment_moderation'          => [ 'comment_moderation', $bool_to_int ],
                        'comment_previously_approved' => [ 'comment_previously_approved', $bool_to_int ],
                        'comment_max_links'           => [ 'comment_max_links', $as_int ],
                    ],
                    'spam' => [
                        'moderation_keys' => [ 'moderation_keys', $as_textarea ],
                        'disallowed_keys' => [ 'disallowed_keys', $as_textarea ],
                    ],
                    'avatars' => [
                        'show_avatars'   => [ 'show_avatars', $bool_to_int ],
                        'avatar_rating'  => [ 'avatar_rating', $as_string ],
                        'avatar_default' => [ 'avatar_default', $as_string ],
                    ],
                ];

                foreach ( $groups as $group_name => $fields ) {
                    if ( ! array_key_exists( $group_name, $input ) || ! is_array( $input[ $group_name ] ) ) {
                        continue;
                    }
                    foreach ( $fields as $field_name => $spec ) {
                        if ( ! array_key_exists( $field_name, $input[ $group_name ] ) ) {
                            continue;
                        }
                        list( $option_key, $coerce ) = $spec;
                        update_option( $option_key, $coerce( $input[ $group_name ][ $field_name ] ) );
                        $updated[] = $group_name . '.' . $field_name;
                    }
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                return [
                    'success' => true,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated %d field(s): %s.', count( $updated ), implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-permalink-settings ----
        wp_register_ability( 'atarim/get-permalink-settings', [
            'label'               => 'Get Permalink Settings',
            'description'         => 'Returns the permalink structure plus the category and tag URL bases. permalink_structure is the URL template (e.g. "/%postname%/" for "Post name", "" or null for the plain ?p=N default).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'permalink_structure' => [ 'type' => 'string' ],
                    'category_base'       => [ 'type' => 'string' ],
                    'tag_base'            => [ 'type' => 'string' ],
                ],
                'required' => [ 'permalink_structure' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return [
                    'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
                    'category_base'       => (string) get_option( 'category_base', '' ),
                    'tag_base'            => (string) get_option( 'tag_base', '' ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-permalink-settings ----
        wp_register_ability( 'atarim/update-permalink-settings', [
            'label'               => 'Update Permalink Settings',
            'description'         => 'Updates the permalink structure and optionally the category / tag URL bases. Flushes the rewrite rules cache so the new structure takes effect immediately. WARNING: changing permalink_structure on a public site without redirects can break all existing post URLs and tank SEO. Use carefully.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'permalink_structure' => [
                        'type'        => 'string',
                        'description' => 'Permalink template. Common values: "" (plain ?p=N), "/%postname%/" (post name), "/%category%/%postname%/", "/%year%/%monthnum%/%postname%/". Must start with /.',
                    ],
                    'category_base' => [
                        'type'        => 'string',
                        'description' => 'URL prefix for category archives. Empty string means "/category/" default.',
                    ],
                    'tag_base' => [
                        'type'        => 'string',
                        'description' => 'URL prefix for tag archives. Empty string means "/tag/" default.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'              => [ 'type' => 'boolean' ],
                    'updated'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'rewrite_rules_flushed' => [ 'type' => 'boolean' ],
                    'message'              => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $updated = [];

                if ( array_key_exists( 'permalink_structure', $input ) ) {
                    $struct = (string) $input['permalink_structure'];
                    if ( $struct !== '' && substr( $struct, 0, 1 ) !== '/' ) {
                        return [ 'success' => false, 'updated' => [], 'rewrite_rules_flushed' => false, 'message' => 'permalink_structure must start with "/" (or be an empty string for the plain default).' ];
                    }
                    update_option( 'permalink_structure', $struct );
                    $updated[] = 'permalink_structure';
                }
                if ( array_key_exists( 'category_base', $input ) ) {
                    update_option( 'category_base', sanitize_text_field( (string) $input['category_base'] ) );
                    $updated[] = 'category_base';
                }
                if ( array_key_exists( 'tag_base', $input ) ) {
                    update_option( 'tag_base', sanitize_text_field( (string) $input['tag_base'] ) );
                    $updated[] = 'tag_base';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'updated' => [], 'rewrite_rules_flushed' => false, 'message' => 'No fields provided to update.' ];
                }

                // Flush rewrite rules so the new structure takes effect.
                flush_rewrite_rules( false );

                return [
                    'success'              => true,
                    'updated'              => $updated,
                    'rewrite_rules_flushed' => true,
                    'message'              => sprintf( 'Updated: %s. Rewrite rules flushed.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-media-settings ----
        wp_register_ability( 'atarim/get-media-settings', [
            'label'               => 'Get Media Settings',
            'description'         => 'Returns the registered image sizes (thumbnail / medium / medium_large / large) plus the "organize uploads in month/year folders" flag. Image dimensions are no longer in the Settings → Media UI in newer WordPress but they still control how WordPress generates intermediate image sizes during media uploads.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'thumbnail_size_w'    => [ 'type' => 'integer' ],
                    'thumbnail_size_h'    => [ 'type' => 'integer' ],
                    'thumbnail_crop'      => [ 'type' => 'boolean' ],
                    'medium_size_w'       => [ 'type' => 'integer' ],
                    'medium_size_h'       => [ 'type' => 'integer' ],
                    'medium_large_size_w' => [ 'type' => 'integer' ],
                    'medium_large_size_h' => [ 'type' => 'integer' ],
                    'large_size_w'        => [ 'type' => 'integer' ],
                    'large_size_h'        => [ 'type' => 'integer' ],
                    'uploads_use_yearmonth_folders' => [ 'type' => 'boolean' ],
                ],
                'required' => [ 'thumbnail_size_w', 'medium_size_w', 'large_size_w' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return [
                    'thumbnail_size_w'    => (int) get_option( 'thumbnail_size_w', 150 ),
                    'thumbnail_size_h'    => (int) get_option( 'thumbnail_size_h', 150 ),
                    'thumbnail_crop'      => (bool) get_option( 'thumbnail_crop', 1 ),
                    'medium_size_w'       => (int) get_option( 'medium_size_w', 300 ),
                    'medium_size_h'       => (int) get_option( 'medium_size_h', 300 ),
                    'medium_large_size_w' => (int) get_option( 'medium_large_size_w', 768 ),
                    'medium_large_size_h' => (int) get_option( 'medium_large_size_h', 0 ),
                    'large_size_w'        => (int) get_option( 'large_size_w', 1024 ),
                    'large_size_h'        => (int) get_option( 'large_size_h', 1024 ),
                    'uploads_use_yearmonth_folders' => (bool) get_option( 'uploads_use_yearmonth_folders', 1 ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-media-settings ----
        wp_register_ability( 'atarim/update-media-settings', [
            'label'               => 'Update Media Settings',
            'description'         => 'Updates registered image sizes and the upload folder structure flag. Changes only affect NEW uploads going forward — existing media keeps its current intermediate sizes. Use a regenerate-thumbnails workflow separately if you need to recompute sizes for existing images.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'thumbnail_size_w'    => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'thumbnail_size_h'    => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'thumbnail_crop'      => [ 'type' => 'boolean' ],
                    'medium_size_w'       => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'medium_size_h'       => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'medium_large_size_w' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'medium_large_size_h' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'large_size_w'        => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'large_size_h'        => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
                    'uploads_use_yearmonth_folders' => [ 'type' => 'boolean' ],
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
                $int_fields  = [ 'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'medium_large_size_w', 'medium_large_size_h', 'large_size_w', 'large_size_h' ];
                $bool_fields = [ 'thumbnail_crop', 'uploads_use_yearmonth_folders' ];

                foreach ( $int_fields as $field ) {
                    if ( array_key_exists( $field, $input ) ) {
                        update_option( $field, max( 0, (int) $input[ $field ] ) );
                        $updated[] = $field;
                    }
                }
                foreach ( $bool_fields as $field ) {
                    if ( array_key_exists( $field, $input ) ) {
                        update_option( $field, $input[ $field ] ? 1 : 0 );
                        $updated[] = $field;
                    }
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                return [
                    'success' => true,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated: %s. Affects new uploads only; existing media retains its current intermediate sizes.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-robots-txt ----
        wp_register_ability( 'atarim/get-robots-txt', [
            'label'               => 'Get robots.txt',
            'description'         => 'Returns the live robots.txt content as WordPress generates it — starting from the default (Allow / Disallow depending on the search engine visibility setting) and running through any robots_txt filters added by SEO plugins. This is what search engines actually see when they request /robots.txt. Write support is not in this round; use an SEO plugin\'s robots.txt editor in the meantime.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'content'                 => [ 'type' => 'string' ],
                    'search_engine_visible'   => [ 'type' => 'boolean' ],
                    'byte_length'             => [ 'type' => 'integer' ],
                    'physical_file_exists'    => [ 'type' => 'boolean' ],
                ],
                'required' => [ 'content' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                // Build the default WordPress would emit, then run it through the robots_txt filter
                // so any contributions from SEO plugins (Yoast, Rank Math) are captured.
                $public = (bool) get_option( 'blog_public', 1 );

                $home_path  = parse_url( home_url(), PHP_URL_PATH );
                $site_url   = parse_url( site_url() );
                $site_path  = empty( $site_url['path'] ) ? '/' : trailingslashit( $site_url['path'] );

                if ( ! $public ) {
                    $output  = "User-agent: *\n";
                    $output .= "Disallow: /\n";
                } else {
                    $output  = "User-agent: *\n";
                    $output .= "Disallow: " . $site_path . "wp-admin/\n";
                    $output .= "Allow: " . $site_path . "wp-admin/admin-ajax.php\n";
                }

                // Run through the canonical robots_txt filter so SEO plugins contribute.
                $content = apply_filters( 'robots_txt', $output, $public );

                $physical_path = ABSPATH . 'robots.txt';

                return [
                    'content'                 => (string) $content,
                    'search_engine_visible'   => $public,
                    'byte_length'             => strlen( (string) $content ),
                    // If a physical robots.txt file is on disk, it OVERRIDES WordPress's dynamic version.
                    // Surfacing this so the AI can warn the user if expected dynamic content isn't showing up.
                    'physical_file_exists'    => file_exists( $physical_path ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-htaccess ----
        wp_register_ability( 'atarim/get-htaccess', [
            'label'               => 'Get .htaccess',
            'description'         => 'Reads the .htaccess file at the WordPress root, if present. Many sites (especially nginx-hosted ones) have no .htaccess at all — returns exists: false in that case. Write support is intentionally not exposed; .htaccess changes are a hosting-layer concern and the risk of breaking the site with a bad rule is too high for the AI to do unattended.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'exists'      => [ 'type' => 'boolean' ],
                    'readable'    => [ 'type' => 'boolean' ],
                    'path'        => [ 'type' => 'string' ],
                    'content'     => [ 'type' => 'string' ],
                    'byte_length' => [ 'type' => 'integer' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'exists', 'path' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $path = ABSPATH . '.htaccess';

                if ( ! file_exists( $path ) ) {
                    return [
                        'exists'      => false,
                        'readable'    => false,
                        'path'        => $path,
                        'content'     => '',
                        'byte_length' => 0,
                        'message'     => 'No .htaccess file found at the WordPress root. The site is likely running on nginx, or the host does not use .htaccess.',
                    ];
                }

                if ( ! is_readable( $path ) ) {
                    return [
                        'exists'      => true,
                        'readable'    => false,
                        'path'        => $path,
                        'content'     => '',
                        'byte_length' => (int) @filesize( $path ),
                        'message'     => '.htaccess exists but is not readable by the web server user. Check filesystem permissions.',
                    ];
                }

                $content = (string) file_get_contents( $path );
                return [
                    'exists'      => true,
                    'readable'    => true,
                    'path'        => $path,
                    'content'     => $content,
                    'byte_length' => strlen( $content ),
                    'message'     => sprintf( 'Read %d byte(s) from .htaccess.', strlen( $content ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }
}
