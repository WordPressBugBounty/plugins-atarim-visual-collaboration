<?php
/**
 * Content (posts, pages, custom post types) MCP abilities.
 *
 * Registers Atarim/* abilities for reading and managing post objects of any
 * post type — built-in (post, page) or custom. One ability per verb; the
 * post_type parameter selects which type to operate on.
 *
 * Exposed abilities:
 *   atarim/list-post-types           Discover available post type slugs.
 *   atarim/list-content              Query posts with rich filters + pagination.
 *   atarim/get-content               Read a single post with full body, taxonomies, meta.
 *   atarim/create-content            Create a post/page/CPT item.
 *   atarim/update-content            Update an existing post/page/CPT item.
 *   atarim/bulk-update-content       Update one field across many posts in one call.
 *   atarim/delete-content            Trash or permanently delete an item.
 *   atarim/list-revisions            List revision history for a post.
 *   atarim/restore-revision          Restore a post to a prior revision.
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

class AVCF_Abilities_Content extends AVCF_Abilities_Base {

    /**
     * Post types that must never be created or updated through the generic
     * content abilities. These are internal / structural types WordPress
     * stores as posts but which own dedicated write pipelines (Customizer,
     * Site Editor, block/nav editors) that apply sanitisation and side
     * effects the generic post write does not. Writing raw block markup into
     * e.g. custom_css would store invalid CSS verbatim.
     *
     * @return string[]
     */
    private function avcf_write_protected_post_types() {
        return [
            'attachment', 'revision', 'nav_menu_item', 'custom_css',
            'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
            'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        ];
    }

    /**
     * Redirect hint for a write-protected post type, so the refusal points the
     * agent at the correct dedicated ability where one exists.
     *
     * @param string $post_type
     * @return string
     */
    private function avcf_write_protected_hint( $post_type ) {
        $map = [
            'custom_css' => ' Use atarim/set-additional-css to change the theme Additional CSS.',
        ];
        return isset( $map[ $post_type ] ) ? $map[ $post_type ] : '';
    }

    /**
     * Register all content abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {

        // ---- list-post-types ----
        wp_register_ability( 'atarim/list-post-types', [
            'label'               => 'List Post Types',
            'description'         => 'Returns all available post types on the site, including built-in (post, page) and custom post types. Use the returned slugs as the post_type parameter for list-content and create-content.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'public_only' => [
                        'type'        => 'boolean',
                        'description' => 'If true, return only public post types. Defaults to true.',
                        'default'     => true,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'      => [ 'type' => 'integer' ],
                    'post_types' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'         => [ 'type' => 'string' ],
                                'label'        => [ 'type' => 'string' ],
                                'singular'     => [ 'type' => 'string' ],
                                'description'  => [ 'type' => 'string' ],
                                'public'       => [ 'type' => 'boolean' ],
                                'hierarchical' => [ 'type' => 'boolean' ],
                                'built_in'     => [ 'type' => 'boolean' ],
                                'supports'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'post_types' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $public_only = isset( $input['public_only'] ) ? (bool) $input['public_only'] : true;

                $args  = $public_only ? [ 'public' => true ] : [];
                $types = get_post_types( $args, 'objects' );

                // Exclude attachment by default — it's "public" but rarely what a caller means.
                unset( $types['attachment'] );

                $result = [];
                foreach ( $types as $slug => $obj ) {
                    $result[] = [
                        'slug'         => $slug,
                        'label'        => isset( $obj->labels->name ) ? $obj->labels->name : ( isset( $obj->label ) ? $obj->label : $slug ),
                        'singular'     => isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : '',
                        'description'  => isset( $obj->description ) ? $obj->description : '',
                        'public'       => (bool) $obj->public,
                        'hierarchical' => (bool) $obj->hierarchical,
                        'built_in'     => (bool) $obj->_builtin,
                        'supports'     => array_keys( get_all_post_type_supports( $slug ) ),
                    ];
                }

                return [
                    'total'      => count( $result ),
                    'post_types' => $result,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- list-content ----
        wp_register_ability( 'atarim/list-content', [
            'label'               => 'List Content',
            'description'         => 'Query posts, pages, or custom post types with rich filters: status, author, date range, taxonomy, custom field (meta) key/value, free-text search, plus ordering and pagination. Each returned item includes title, slug, status, dates, excerpt, and a content_preview (first ~200 chars of body, HTML/blocks stripped). Pass include_content: true to also return full post bodies — use sparingly, response size grows. Use list-post-types to discover available post_type values.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type slug (e.g. "post", "page", "product"). Defaults to "post".',
                        'default'     => 'post',
                        'minLength'   => 1,
                    ],
                    'status' => [
                        'type'        => [ 'string', 'array' ],
                        'description' => 'Filter by post status. Single value or array. Omit for all non-trashed.',
                        'enum'        => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
                    ],
                    'author' => [
                        'type'        => 'integer',
                        'description' => 'Filter by author user ID.',
                        'minimum'     => 1,
                    ],
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Only items with publish date on or after this date. ISO 8601 or strtotime()-parseable.',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'Only items with publish date on or before this date. ISO 8601 or strtotime()-parseable.',
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Free-text keyword search across title and content (WordPress default search behaviour).',
                        'minLength'   => 1,
                    ],
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug to filter by (e.g. "category", "post_tag", or a custom taxonomy). Use with "terms" parameter. The taxonomy must apply to the post_type.',
                        'minLength'   => 1,
                    ],
                    'terms' => [
                        'type'        => 'array',
                        'description' => 'Array of term slugs to match (OR semantics — items with any of these terms). Requires "taxonomy".',
                        'items'       => [ 'type' => 'string' ],
                        'minItems'    => 1,
                    ],
                    'meta_key' => [
                        'type'        => 'string',
                        'description' => 'Custom field key to filter by. Pair with meta_value and optionally meta_compare.',
                        'minLength'   => 1,
                    ],
                    'meta_value' => [
                        'type'        => 'string',
                        'description' => 'Custom field value to match.',
                    ],
                    'meta_compare' => [
                        'type'        => 'string',
                        'description' => 'How to compare meta_value. Defaults to "=". Supports the safe subset of WP_Query meta_compare operators.',
                        'enum'        => [ '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'EXISTS', 'NOT EXISTS' ],
                        'default'     => '=',
                    ],
                    'orderby' => [
                        'type'        => 'string',
                        'description' => 'Field to sort by.',
                        'enum'        => [ 'date', 'modified', 'title', 'menu_order', 'ID', 'author', 'rand' ],
                        'default'     => 'date',
                    ],
                    'order' => [
                        'type'        => 'string',
                        'description' => 'Sort direction.',
                        'enum'        => [ 'ASC', 'DESC' ],
                        'default'     => 'DESC',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max items per page. -1 returns all (use carefully). Defaults to 20.',
                        'default'     => 20,
                        'minimum'     => -1,
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Skip this many items before returning results. For pagination.',
                        'default'     => 0,
                        'minimum'     => 0,
                    ],
                    'include_content' => [
                        'type'        => 'boolean',
                        'description' => 'Include the full post body in each item under "content". Defaults to false — only a short content_preview is returned. Set true when the caller actually needs full bodies; responses can grow large.',
                        'default'     => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'         => [ 'type' => 'integer' ],
                    'returned'      => [ 'type' => 'integer' ],
                    'offset'        => [ 'type' => 'integer' ],
                    'post_type'     => [ 'type' => 'string' ],
                    'items'         => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'              => [ 'type' => 'integer' ],
                                'title'           => [ 'type' => 'string' ],
                                'slug'            => [ 'type' => 'string' ],
                                'status'          => [ 'type' => 'string' ],
                                'post_type'       => [ 'type' => 'string' ],
                                'url'             => [ 'type' => 'string' ],
                                'author'          => [ 'type' => 'integer' ],
                                'parent'          => [ 'type' => 'integer' ],
                                'date'            => [ 'type' => 'string' ],
                                'created'         => [ 'type' => 'string' ],
                                'modified'        => [ 'type' => 'string' ],
                                'excerpt'         => [ 'type' => 'string' ],
                                'content_preview' => [ 'type' => 'string' ],
                                'content'         => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'returned', 'post_type', 'items' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';

                if ( ! post_type_exists( $post_type ) ) {
                    return [
                        'total'     => 0,
                        'returned'  => 0,
                        'offset'    => 0,
                        'post_type' => $post_type,
                        'items'     => [],
                    ];
                }

                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

                $args = [
                    'post_type'      => $post_type,
                    'posts_per_page' => $limit,
                    'offset'         => $offset,
                    'orderby'        => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'date',
                    'order'          => ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
                    // suppress_filters off so caching/translation plugins still apply.
                ];

                if ( ! empty( $input['status'] ) ) {
                    $args['post_status'] = $input['status'];
                } else {
                    $args['post_status'] = [ 'publish', 'draft', 'pending', 'private', 'future' ];
                }

                if ( isset( $input['author'] ) ) {
                    $args['author'] = (int) $input['author'];
                }

                if ( isset( $input['search'] ) && $input['search'] !== '' ) {
                    $args['s'] = (string) $input['search'];
                }

                // Date range — WP_Query accepts a date_query array.
                if ( isset( $input['date_from'] ) || isset( $input['date_to'] ) ) {
                    $date_query = [];
                    if ( isset( $input['date_from'] ) && $input['date_from'] !== '' ) {
                        list( $local_from, , $err_from ) = $this->avcf_normalize_post_date( (string) $input['date_from'] );
                        if ( $err_from !== null ) {
                            return [
                                'total'     => 0,
                                'returned'  => 0,
                                'offset'    => $offset,
                                'post_type' => $post_type,
                                'items'     => [],
                                'message'   => 'date_from invalid: ' . $err_from,
                            ];
                        }
                        $date_query['after']     = $local_from;
                    }
                    if ( isset( $input['date_to'] ) && $input['date_to'] !== '' ) {
                        list( $local_to, , $err_to ) = $this->avcf_normalize_post_date( (string) $input['date_to'] );
                        if ( $err_to !== null ) {
                            return [
                                'total'     => 0,
                                'returned'  => 0,
                                'offset'    => $offset,
                                'post_type' => $post_type,
                                'items'     => [],
                                'message'   => 'date_to invalid: ' . $err_to,
                            ];
                        }
                        $date_query['before']     = $local_to;
                    }
                    $date_query['inclusive'] = true;
                    $args['date_query']      = [ $date_query ];
                }

                // Taxonomy filter — single taxonomy + array of term slugs, OR semantics.
                if ( ! empty( $input['taxonomy'] ) && ! empty( $input['terms'] ) ) {
                    $tax = sanitize_key( (string) $input['taxonomy'] );
                    if ( ! taxonomy_exists( $tax ) ) {
                        return [
                            'total'     => 0,
                            'returned'  => 0,
                            'offset'    => $offset,
                            'post_type' => $post_type,
                            'items'     => [],
                            'message'   => sprintf( 'Taxonomy "%s" does not exist on this site.', $tax ),
                        ];
                    }
                    $terms = array_map( 'sanitize_title', (array) $input['terms'] );
                    $args['tax_query'] = [
                        [
                            'taxonomy' => $tax,
                            'field'    => 'slug',
                            'terms'    => $terms,
                            'operator' => 'IN',
                        ],
                    ];
                }

                // Meta filter — single key/value/compare. EXISTS / NOT EXISTS don't need a value.
                if ( ! empty( $input['meta_key'] ) ) {
                    $compare = isset( $input['meta_compare'] ) ? (string) $input['meta_compare'] : '=';
                    $allowed_compare = [ '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'EXISTS', 'NOT EXISTS' ];
                    if ( ! in_array( $compare, $allowed_compare, true ) ) {
                        $compare = '=';
                    }
                    $meta_clause = [
                        'key'     => (string) $input['meta_key'],
                        'compare' => $compare,
                    ];
                    if ( $compare !== 'EXISTS' && $compare !== 'NOT EXISTS' ) {
                        $meta_clause['value'] = isset( $input['meta_value'] ) ? (string) $input['meta_value'] : '';
                    }
                    $args['meta_query'] = [ $meta_clause ];
                }

                $include_content = ! empty( $input['include_content'] );
                $query           = new \WP_Query( $args );

                $items = [];
                foreach ( $query->posts as $post ) {
                    $excerpt = $post->post_excerpt;
                    if ( $excerpt === '' && $post->post_content !== '' ) {
                        // Generate a short excerpt from content when one isn't authored.
                        $excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '…' );
                    }

                    // content_preview: ~200 chars of plain text from the body, ellipsis if truncated.
                    $stripped        = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
                    $stripped        = preg_replace( '/\s+/', ' ', $stripped );
                    $content_preview = ( strlen( $stripped ) > 200 )
                        ? substr( $stripped, 0, 200 ) . '…'
                        : $stripped;

                    $item = [
                        'id'              => $post->ID,
                        'title'           => $post->post_title,
                        'slug'            => $post->post_name,
                        'status'          => $post->post_status,
                        'post_type'       => $post->post_type,
                        'url'             => get_permalink( $post->ID ),
                        'author'          => (int) $post->post_author,
                        'parent'          => (int) $post->post_parent,
                        'date'            => $post->post_date,
                        'created'         => $post->post_date_gmt,
                        'modified'        => $post->post_modified_gmt,
                        'excerpt'         => $excerpt,
                        'content_preview' => $content_preview,
                    ];

                    if ( $include_content ) {
                        $item['content'] = $post->post_content;
                    }

                    $items[] = $item;
                }

                return [
                    'total'     => (int) $query->found_posts,
                    'returned'  => count( $items ),
                    'offset'    => $offset,
                    'post_type' => $post_type,
                    'items'     => $items,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- get-content ----
        wp_register_ability( 'atarim/get-content', [
            'label'               => 'Get Content',
            'description'         => 'Returns full detail for a single post, page, or custom post type item — including the post body, excerpt, dates, author, parent, featured image, comment/ping status. Optional include flags add taxonomies (categories, tags, custom taxonomies) and selected meta fields. Use list-content to discover IDs.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID.',
                        'minimum'     => 1,
                    ],
                    'include_taxonomies' => [
                        'type'        => 'boolean',
                        'description' => 'Include all assigned taxonomy terms (categories, tags, custom taxonomies) for the post.',
                        'default'     => false,
                    ],
                    'include_meta_keys' => [
                        'type'        => 'array',
                        'description' => 'List of specific meta keys to read. Omit or empty array to skip meta. Keys beginning with "_" (private/internal meta) are excluded for safety even if requested.',
                        'items'       => [ 'type' => 'string' ],
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'id'             => [ 'type' => 'integer' ],
                    'title'          => [ 'type' => 'string' ],
                    'slug'           => [ 'type' => 'string' ],
                    'status'         => [ 'type' => 'string' ],
                    'post_type'      => [ 'type' => 'string' ],
                    'url'            => [ 'type' => 'string' ],
                    'content'        => [ 'type' => 'string' ],
                    'excerpt'        => [ 'type' => 'string' ],
                    'date'           => [ 'type' => 'string' ],
                    'created'        => [ 'type' => 'string' ],
                    'modified'       => [ 'type' => 'string' ],
                    'author'         => [ 'type' => 'integer' ],
                    'parent'         => [ 'type' => 'integer' ],
                    'featured_media' => [ 'type' => 'integer' ],
                    'comment_status' => [ 'type' => 'string' ],
                    'ping_status'    => [ 'type' => 'string' ],
                    'taxonomies'     => [ 'type' => 'object' ],
                    'meta'           => [ 'type' => 'object' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }

                $post = get_post( $id );
                if ( ! $post ) {
                    return [ 'success' => false, 'message' => sprintf( 'Post %d not found.', $id ) ];
                }

                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->read_post, $id ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'You do not have permission to read this %s.', $post->post_type ) ];
                }

                $result = [
                    'success'        => true,
                    'id'             => $post->ID,
                    'title'          => $post->post_title,
                    'slug'           => $post->post_name,
                    'status'         => $post->post_status,
                    'post_type'      => $post->post_type,
                    'url'            => get_permalink( $post->ID ),
                    'content'        => $post->post_content,
                    'excerpt'        => $post->post_excerpt,
                    'date'           => $post->post_date,
                    'created'        => $post->post_date_gmt,
                    'modified'       => $post->post_modified_gmt,
                    'author'         => (int) $post->post_author,
                    'parent'         => (int) $post->post_parent,
                    'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
                    'comment_status' => $post->comment_status,
                    'ping_status'    => $post->ping_status,
                    'message'        => 'OK.',
                ];

                if ( ! empty( $input['include_taxonomies'] ) ) {
                    $taxonomies     = get_object_taxonomies( $post->post_type, 'names' );
                    $tax_assignments = [];
                    foreach ( $taxonomies as $tax ) {
                        $terms = get_the_terms( $post->ID, $tax );
                        if ( is_wp_error( $terms ) || empty( $terms ) ) {
                            $tax_assignments[ $tax ] = [];
                            continue;
                        }
                        $tax_assignments[ $tax ] = array_map(
                            function( $t ) {
                                return [
                                    'term_id' => (int) $t->term_id,
                                    'slug'    => $t->slug,
                                    'name'    => $t->name,
                                ];
                            },
                            $terms
                        );
                    }
                    $result['taxonomies'] = $tax_assignments;
                }

                if ( ! empty( $input['include_meta_keys'] ) && is_array( $input['include_meta_keys'] ) ) {
                    $meta = [];
                    foreach ( $input['include_meta_keys'] as $key ) {
                        $key = (string) $key;
                        // Skip private/internal meta even if explicitly requested.
                        if ( $key === '' || strpos( $key, '_' ) === 0 ) {
                            continue;
                        }
                        $value = get_post_meta( $post->ID, $key, true );
                        $meta[ $key ] = $value;
                    }
                    $result['meta'] = $meta;
                }

                return $result;
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- create-content ----
        wp_register_ability( 'atarim/create-content', [
            'label'               => 'Create Content',
            'description'         => 'Creates a new post, page, or custom post type item. Required: post_type and title. All other fields are optional — WordPress auto-generates the slug from the title if omitted, and status defaults to "draft". The content body can be supplied inline (content — plain text, raw HTML, or Gutenberg block markup) or pulled from a URL (content_url); see content_format to control processing. Use list-post-types to discover available post_type values.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type slug (e.g. "post", "page", or a custom slug).',
                        'minLength'   => 1,
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Post title.',
                        'minLength'   => 1,
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'Desired URL slug. Omit to let WordPress generate one from the title. WordPress auto-suffixes on conflict (slug-2, slug-3).',
                        'minLength'   => 1,
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Publish status. Defaults to "draft". Use "future" together with a date in the future to schedule.',
                        'enum'        => [ 'publish', 'draft', 'pending', 'private', 'future' ],
                        'default'     => 'draft',
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'Post body as an inline string. Plain text, raw HTML, or Gutenberg block markup. See content_format to control processing. Mutually exclusive with content_url — provide one, not both.',
                    ],
                    'content_url' => [
                        'type'        => 'string',
                        'description' => 'Alternative to content: a URL to pull the post body from. The response body is fetched verbatim (HTML, PHP source, plain text, or block markup — no sanitisation) and then processed per content_format; use content_format:"raw" to store it byte-for-byte. Must be a publicly reachable http/https URL — requests to private/loopback addresses are rejected. Mutually exclusive with content.',
                    ],
                    'content_format' => [
                        'type'        => 'string',
                        'description' => 'How to process the content (or fetched content_url) body. "auto" (default): detect block delimiters and pass through if present, otherwise wrap paragraphs as wp:paragraph blocks so the result stays editable in the block editor. "raw": store content exactly as provided, no processing (use this for HTML/PHP/other file content that must not be altered). "blocks": caller asserts content is already valid block markup; pass through with no detection.',
                        'enum'        => [ 'auto', 'raw', 'blocks' ],
                        'default'     => 'auto',
                    ],
                    'excerpt' => [
                        'type'        => 'string',
                        'description' => 'Hand-written excerpt. Omit to let WordPress generate one from the content.',
                    ],
                    'date' => [
                        'type'        => 'string',
                        'description' => 'Publish date in site-local time. Accepts ISO 8601 (2026-05-22T14:30:00) or any strtotime()-parseable string. For status="future", must be in the future.',
                    ],
                    'author' => [
                        'type'        => 'integer',
                        'description' => 'User ID of the post author. Defaults to the current user. Setting this to another user requires edit_others_posts capability for the post type.',
                        'minimum'     => 1,
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'Parent post ID for hierarchical post types (pages, custom hierarchical CPTs). 0 means no parent.',
                        'minimum'     => 0,
                    ],
                    'featured_media' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID to use as the featured image. The attachment must already exist in the media library and be an image (not a PDF, video, or audio file).',
                        'minimum'     => 1,
                    ],
                    'comment_status' => [
                        'type'        => 'string',
                        'description' => 'Whether comments are allowed. Defaults to the site-wide setting.',
                        'enum'        => [ 'open', 'closed' ],
                    ],
                    'ping_status' => [
                        'type'        => 'string',
                        'description' => 'Whether pingbacks and trackbacks are allowed. Defaults to the site-wide setting.',
                        'enum'        => [ 'open', 'closed' ],
                    ],
                ],
                'required'             => [ 'post_type', 'title' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'id'             => [ 'type' => 'integer' ],
                    'title'          => [ 'type' => 'string' ],
                    'slug'           => [ 'type' => 'string' ],
                    'status'         => [ 'type' => 'string' ],
                    'post_type'      => [ 'type' => 'string' ],
                    'url'            => [ 'type' => 'string' ],
                    'excerpt'        => [ 'type' => 'string' ],
                    'date'           => [ 'type' => 'string' ],
                    'author'         => [ 'type' => 'integer' ],
                    'parent'         => [ 'type' => 'integer' ],
                    'featured_media' => [ 'type' => 'integer' ],
                    'comment_status' => [ 'type' => 'string' ],
                    'ping_status'    => [ 'type' => 'string' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';
                $title     = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';

                if ( empty( $post_type ) || empty( $title ) ) {
                    return [
                        'success' => false,
                        'message' => 'post_type and title are required.',
                    ];
                }

                if ( ! post_type_exists( $post_type ) ) {
                    return [
                        'success' => false,
                        'message' => sprintf( 'Post type "%s" does not exist on this site.', $post_type ),
                    ];
                }

                if ( in_array( $post_type, $this->avcf_write_protected_post_types(), true ) ) {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            'Post type "%s" cannot be created through this ability (internal/structural type).%s',
                            $post_type,
                            $this->avcf_write_protected_hint( $post_type )
                        ),
                    ];
                }

                $pt_obj = get_post_type_object( $post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_posts ) ) {
                    return [
                        'success' => false,
                        'message' => sprintf( 'You do not have permission to create %s.', $post_type ),
                    ];
                }

                // Status — default to draft, validate enum.
                $allowed_statuses = [ 'publish', 'draft', 'pending', 'private', 'future' ];
                $status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
                if ( ! in_array( $status, $allowed_statuses, true ) ) {
                    $status = 'draft';
                }

                // Publishing requires the publish capability for this post type.
                if ( in_array( $status, [ 'publish', 'future', 'private' ], true ) ) {
                    if ( $pt_obj && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
                        return [
                            'success' => false,
                            'message' => sprintf( 'You do not have permission to publish %s.', $post_type ),
                        ];
                    }
                }

                $postarr = [
                    'post_type'   => $post_type,
                    'post_title'  => $title,
                    'post_status' => $status,
                ];

                // Slug — optional; let wp_insert_post auto-generate from title if omitted.
                if ( isset( $input['slug'] ) && $input['slug'] !== '' ) {
                    $postarr['post_name'] = sanitize_title( $input['slug'] );
                }

                // Content body — inline (content) or pulled from a URL (content_url).
                $has_content     = array_key_exists( 'content', $input );
                $has_content_url = isset( $input['content_url'] ) && trim( (string) $input['content_url'] ) !== '';
                if ( $has_content && $has_content_url ) {
                    return [
                        'success' => false,
                        'message' => 'Provide either content or content_url, not both.',
                    ];
                }
                if ( $has_content || $has_content_url ) {
                    $format = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'auto';
                    if ( $has_content_url ) {
                        list( $fetched_content, $fetch_err ) = $this->avcf_fetch_content_from_url( (string) $input['content_url'] );
                        if ( $fetch_err !== null ) {
                            return [ 'success' => false, 'message' => $fetch_err ];
                        }
                        $raw_content = $fetched_content;
                    } else {
                        $raw_content = (string) $input['content'];
                    }
                    $postarr['post_content'] = $this->avcf_prepare_content_body( $raw_content, $format );
                }

                // Excerpt.
                if ( isset( $input['excerpt'] ) ) {
                    $postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
                }

                // Date — parse, normalise, validate "future" constraint.
                if ( isset( $input['date'] ) && $input['date'] !== '' ) {
                    list( $local_date, $gmt_date, $date_err ) = $this->avcf_normalize_post_date( (string) $input['date'] );
                    if ( $date_err !== null ) {
                        return [ 'success' => false, 'message' => $date_err ];
                    }
                    if ( $status === 'future' && strtotime( $gmt_date ) <= time() ) {
                        return [
                            'success' => false,
                            'message' => 'status="future" requires a date in the future.',
                        ];
                    }
                    $postarr['post_date']     = $local_date;
                    $postarr['post_date_gmt'] = $gmt_date;
                }

                // Author — defaults to current user; requires edit_others_posts to set someone else.
                if ( isset( $input['author'] ) ) {
                    $author_id = (int) $input['author'];
                    if ( $author_id <= 0 ) {
                        return [ 'success' => false, 'message' => 'author must be a positive user ID.' ];
                    }
                    if ( ! get_userdata( $author_id ) ) {
                        return [ 'success' => false, 'message' => sprintf( 'User %d does not exist.', $author_id ) ];
                    }
                    if ( $author_id !== get_current_user_id() ) {
                        if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_others_posts ) ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'You do not have permission to assign %s to another author.', $post_type ),
                            ];
                        }
                    }
                    $postarr['post_author'] = $author_id;
                }

                // Parent — validate hierarchical and that the parent exists.
                if ( isset( $input['parent'] ) ) {
                    $parent_id = (int) $input['parent'];
                    if ( $parent_id < 0 ) {
                        return [ 'success' => false, 'message' => 'parent must be 0 or a positive post ID.' ];
                    }
                    if ( $parent_id > 0 ) {
                        if ( ! is_post_type_hierarchical( $post_type ) ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'Post type "%s" is not hierarchical; parent must be 0.', $post_type ),
                            ];
                        }
                        $parent_post = get_post( $parent_id );
                        if ( ! $parent_post || $parent_post->post_type !== $post_type ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'Parent %d does not exist or is not a %s.', $parent_id, $post_type ),
                            ];
                        }
                    }
                    $postarr['post_parent'] = $parent_id;
                }

                // Featured media — validate existence + is-image. Stored via _thumbnail_id meta after insert.
                $featured_media_id = null;
                if ( isset( $input['featured_media'] ) ) {
                    $featured_media_id = (int) $input['featured_media'];
                    $attach_err = $this->avcf_validate_attachment_id( $featured_media_id );
                    if ( $attach_err !== null ) {
                        return [ 'success' => false, 'message' => $attach_err ];
                    }
                }

                // Comment / ping status.
                if ( isset( $input['comment_status'] ) ) {
                    $cs = sanitize_key( $input['comment_status'] );
                    if ( ! in_array( $cs, [ 'open', 'closed' ], true ) ) {
                        return [ 'success' => false, 'message' => 'comment_status must be "open" or "closed".' ];
                    }
                    $postarr['comment_status'] = $cs;
                }
                if ( isset( $input['ping_status'] ) ) {
                    $ps = sanitize_key( $input['ping_status'] );
                    if ( ! in_array( $ps, [ 'open', 'closed' ], true ) ) {
                        return [ 'success' => false, 'message' => 'ping_status must be "open" or "closed".' ];
                    }
                    $postarr['ping_status'] = $ps;
                }

                $post_id = wp_insert_post( $postarr, true );

                if ( is_wp_error( $post_id ) ) {
                    return [
                        'success' => false,
                        'message' => 'Creation failed: ' . $post_id->get_error_message(),
                    ];
                }

                // Set featured image after insert (wp_insert_post does not accept _thumbnail_id directly).
                if ( $featured_media_id !== null ) {
                    set_post_thumbnail( $post_id, $featured_media_id );
                }

                $post = get_post( $post_id );

                return [
                    'success'        => true,
                    'id'             => $post_id,
                    'title'          => $post->post_title,
                    'slug'           => $post->post_name,
                    'status'         => $post->post_status,
                    'post_type'      => $post->post_type,
                    'url'            => get_permalink( $post_id ),
                    'excerpt'        => $post->post_excerpt,
                    'date'           => $post->post_date,
                    'author'         => (int) $post->post_author,
                    'parent'         => (int) $post->post_parent,
                    'featured_media' => (int) get_post_thumbnail_id( $post_id ),
                    'comment_status' => $post->comment_status,
                    'ping_status'    => $post->ping_status,
                    'message'        => 'Content created.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- update-content ----
        wp_register_ability( 'atarim/update-content', [
            'label'               => 'Update Content',
            'description'         => 'Updates an existing post, page, or custom post type item. Only the id is required; pass any subset of the other fields to update those. Omitted fields are left unchanged. The content body can be supplied inline (content — plain text, raw HTML, or Gutenberg block markup) or pulled from a URL (content_url); see content_format.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID. Required.',
                        'minimum'     => 1,
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'New title. Omit to leave unchanged.',
                        'minLength'   => 1,
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'New URL slug. WordPress auto-suffixes on conflict. Omit to leave unchanged.',
                        'minLength'   => 1,
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'New publish status. Use "future" with a future-dated "date" to schedule. Omit to leave unchanged.',
                        'enum'        => [ 'publish', 'draft', 'pending', 'private', 'future' ],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'New post body as an inline string. Plain text, raw HTML, or Gutenberg block markup. See content_format. Pass an empty string to clear the body. Omit to leave unchanged. Mutually exclusive with content_url.',
                    ],
                    'content_url' => [
                        'type'        => 'string',
                        'description' => 'Alternative to content: a URL to pull the new post body from. The response body is fetched verbatim (HTML, PHP source, plain text, or block markup — no sanitisation) and then processed per content_format; use content_format:"raw" to store it byte-for-byte. Must be a publicly reachable http/https URL — requests to private/loopback addresses are rejected. Mutually exclusive with content.',
                    ],
                    'content_format' => [
                        'type'        => 'string',
                        'description' => 'How to process the content (or fetched content_url) body. "auto" (default), "raw", or "blocks". See create-content for details.',
                        'enum'        => [ 'auto', 'raw', 'blocks' ],
                        'default'     => 'auto',
                    ],
                    'excerpt' => [
                        'type'        => 'string',
                        'description' => 'New excerpt. Pass an empty string to clear. Omit to leave unchanged.',
                    ],
                    'date' => [
                        'type'        => 'string',
                        'description' => 'New publish date in site-local time. ISO 8601 or strtotime()-parseable. Omit to leave unchanged.',
                    ],
                    'author' => [
                        'type'        => 'integer',
                        'description' => 'New author user ID. Requires edit_others_posts capability if different from current author. Omit to leave unchanged.',
                        'minimum'     => 1,
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'New parent post ID for hierarchical post types. 0 removes the parent. Omit to leave unchanged.',
                        'minimum'     => 0,
                    ],
                    'featured_media' => [
                        'type'        => 'integer',
                        'description' => 'New featured image attachment ID. Must exist and be an image. Pass 0 to remove the featured image. Omit to leave unchanged.',
                        'minimum'     => 0,
                    ],
                    'comment_status' => [
                        'type'        => 'string',
                        'description' => 'New comment status. Omit to leave unchanged.',
                        'enum'        => [ 'open', 'closed' ],
                    ],
                    'ping_status' => [
                        'type'        => 'string',
                        'description' => 'New pingback/trackback status. Omit to leave unchanged.',
                        'enum'        => [ 'open', 'closed' ],
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'id'             => [ 'type' => 'integer' ],
                    'title'          => [ 'type' => 'string' ],
                    'slug'           => [ 'type' => 'string' ],
                    'status'         => [ 'type' => 'string' ],
                    'post_type'      => [ 'type' => 'string' ],
                    'url'            => [ 'type' => 'string' ],
                    'excerpt'        => [ 'type' => 'string' ],
                    'date'           => [ 'type' => 'string' ],
                    'author'         => [ 'type' => 'integer' ],
                    'parent'         => [ 'type' => 'integer' ],
                    'featured_media' => [ 'type' => 'integer' ],
                    'comment_status' => [ 'type' => 'string' ],
                    'ping_status'    => [ 'type' => 'string' ],
                    'updated'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [
                        'success' => false,
                        'message' => 'id is required and must be a positive integer.',
                    ];
                }

                $post = get_post( $id );
                if ( ! $post ) {
                    return [
                        'success' => false,
                        'message' => sprintf( 'Post %d not found.', $id ),
                    ];
                }

                if ( in_array( $post->post_type, $this->avcf_write_protected_post_types(), true ) ) {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            'Post type "%s" cannot be edited through this ability (internal/structural type).%s',
                            $post->post_type,
                            $this->avcf_write_protected_hint( $post->post_type )
                        ),
                    ];
                }

                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $id ) ) {
                    return [
                        'success' => false,
                        'message' => sprintf( 'You do not have permission to edit this %s.', $post->post_type ),
                    ];
                }

                // Build the update payload from only the fields the caller actually sent.
                // wp_update_post leaves omitted fields untouched, but we track what we
                // changed so we can return a useful `updated` array.
                $update  = [ 'ID' => $id ];
                $updated = [];

                if ( array_key_exists( 'title', $input ) ) {
                    $update['post_title'] = sanitize_text_field( (string) $input['title'] );
                    $updated[] = 'title';
                }

                if ( array_key_exists( 'slug', $input ) ) {
                    $update['post_name'] = sanitize_title( (string) $input['slug'] );
                    $updated[] = 'slug';
                }

                // Status — needs publish_posts cap if moving into publish/future/private.
                if ( array_key_exists( 'status', $input ) ) {
                    $status  = sanitize_key( (string) $input['status'] );
                    $allowed = [ 'publish', 'draft', 'pending', 'private', 'future' ];
                    if ( ! in_array( $status, $allowed, true ) ) {
                        return [
                            'success' => false,
                            'message' => sprintf( 'Invalid status "%s". Allowed: publish, draft, pending, private, future.', $status ),
                        ];
                    }
                    if ( in_array( $status, [ 'publish', 'future', 'private' ], true ) ) {
                        if ( $pt_obj && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'You do not have permission to publish %s.', $post->post_type ),
                            ];
                        }
                    }
                    $update['post_status'] = $status;
                    $updated[] = 'status';
                }

                // Content body — inline (content) or pulled from a URL (content_url).
                $has_content     = array_key_exists( 'content', $input );
                $has_content_url = isset( $input['content_url'] ) && trim( (string) $input['content_url'] ) !== '';
                if ( $has_content && $has_content_url ) {
                    return [
                        'success' => false,
                        'message' => 'Provide either content or content_url, not both.',
                    ];
                }
                if ( $has_content || $has_content_url ) {
                    $format = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'auto';
                    if ( $has_content_url ) {
                        list( $fetched_content, $fetch_err ) = $this->avcf_fetch_content_from_url( (string) $input['content_url'] );
                        if ( $fetch_err !== null ) {
                            return [ 'success' => false, 'message' => $fetch_err ];
                        }
                        $raw_content = $fetched_content;
                    } else {
                        $raw_content = (string) $input['content'];
                    }
                    $update['post_content'] = $this->avcf_prepare_content_body( $raw_content, $format );
                    $updated[] = 'content';
                }

                if ( array_key_exists( 'excerpt', $input ) ) {
                    $update['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
                    $updated[] = 'excerpt';
                }

                if ( array_key_exists( 'date', $input ) && $input['date'] !== '' ) {
                    list( $local_date, $gmt_date, $date_err ) = $this->avcf_normalize_post_date( (string) $input['date'] );
                    if ( $date_err !== null ) {
                        return [ 'success' => false, 'message' => $date_err ];
                    }
                    // If status is being set to "future" in this same call, validate date is in the future.
                    $effective_status = isset( $update['post_status'] ) ? $update['post_status'] : $post->post_status;
                    if ( $effective_status === 'future' && strtotime( $gmt_date ) <= time() ) {
                        return [
                            'success' => false,
                            'message' => 'status="future" requires a date in the future.',
                        ];
                    }
                    $update['post_date']     = $local_date;
                    $update['post_date_gmt'] = $gmt_date;
                    $updated[] = 'date';
                }

                if ( array_key_exists( 'author', $input ) ) {
                    $author_id = (int) $input['author'];
                    if ( $author_id <= 0 ) {
                        return [ 'success' => false, 'message' => 'author must be a positive user ID.' ];
                    }
                    if ( ! get_userdata( $author_id ) ) {
                        return [ 'success' => false, 'message' => sprintf( 'User %d does not exist.', $author_id ) ];
                    }
                    if ( $author_id !== get_current_user_id() ) {
                        if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_others_posts ) ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'You do not have permission to assign %s to another author.', $post->post_type ),
                            ];
                        }
                    }
                    $update['post_author'] = $author_id;
                    $updated[] = 'author';
                }

                if ( array_key_exists( 'parent', $input ) ) {
                    $parent_id = (int) $input['parent'];
                    if ( $parent_id < 0 ) {
                        return [ 'success' => false, 'message' => 'parent must be 0 or a positive post ID.' ];
                    }
                    if ( $parent_id > 0 ) {
                        if ( ! is_post_type_hierarchical( $post->post_type ) ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'Post type "%s" is not hierarchical; parent must be 0.', $post->post_type ),
                            ];
                        }
                        if ( $parent_id === $id ) {
                            return [ 'success' => false, 'message' => 'A post cannot be its own parent.' ];
                        }
                        $parent_post = get_post( $parent_id );
                        if ( ! $parent_post || $parent_post->post_type !== $post->post_type ) {
                            return [
                                'success' => false,
                                'message' => sprintf( 'Parent %d does not exist or is not a %s.', $parent_id, $post->post_type ),
                            ];
                        }
                    }
                    $update['post_parent'] = $parent_id;
                    $updated[] = 'parent';
                }

                // Featured media — handled separately (post-insert via set_post_thumbnail).
                // 0 = remove the featured image, positive = validate and set.
                $featured_media_change = null; // null = unchanged, 0 = remove, >0 = set
                if ( array_key_exists( 'featured_media', $input ) ) {
                    $featured_media_id = (int) $input['featured_media'];
                    if ( $featured_media_id < 0 ) {
                        return [ 'success' => false, 'message' => 'featured_media must be 0 or a positive attachment ID.' ];
                    }
                    if ( $featured_media_id > 0 ) {
                        $attach_err = $this->avcf_validate_attachment_id( $featured_media_id );
                        if ( $attach_err !== null ) {
                            return [ 'success' => false, 'message' => $attach_err ];
                        }
                    }
                    $featured_media_change = $featured_media_id;
                    $updated[] = 'featured_media';
                }

                if ( array_key_exists( 'comment_status', $input ) ) {
                    $cs = sanitize_key( (string) $input['comment_status'] );
                    if ( ! in_array( $cs, [ 'open', 'closed' ], true ) ) {
                        return [ 'success' => false, 'message' => 'comment_status must be "open" or "closed".' ];
                    }
                    $update['comment_status'] = $cs;
                    $updated[] = 'comment_status';
                }

                if ( array_key_exists( 'ping_status', $input ) ) {
                    $ps = sanitize_key( (string) $input['ping_status'] );
                    if ( ! in_array( $ps, [ 'open', 'closed' ], true ) ) {
                        return [ 'success' => false, 'message' => 'ping_status must be "open" or "closed".' ];
                    }
                    $update['ping_status'] = $ps;
                    $updated[] = 'ping_status';
                }

                // Nothing actually changed (only ID was passed, and no featured_media change).
                if ( count( $update ) === 1 && $featured_media_change === null ) {
                    return [
                        'success' => false,
                        'message' => 'No fields provided to update. Pass at least one field to change.',
                    ];
                }

                // Only call wp_update_post if there's something in the post table to update.
                if ( count( $update ) > 1 ) {
                    $result = wp_update_post( $update, true );
                    if ( is_wp_error( $result ) ) {
                        return [
                            'success' => false,
                            'message' => 'Update failed: ' . $result->get_error_message(),
                        ];
                    }
                }

                // Apply featured image change (separately because wp_update_post does not handle _thumbnail_id).
                if ( $featured_media_change !== null ) {
                    if ( $featured_media_change === 0 ) {
                        delete_post_thumbnail( $id );
                    } else {
                        set_post_thumbnail( $id, $featured_media_change );
                    }
                }

                $fresh = get_post( $id );

                return [
                    'success'        => true,
                    'id'             => $id,
                    'title'          => $fresh->post_title,
                    'slug'           => $fresh->post_name,
                    'status'         => $fresh->post_status,
                    'post_type'      => $fresh->post_type,
                    'url'            => get_permalink( $id ),
                    'excerpt'        => $fresh->post_excerpt,
                    'date'           => $fresh->post_date,
                    'author'         => (int) $fresh->post_author,
                    'parent'         => (int) $fresh->post_parent,
                    'featured_media' => (int) get_post_thumbnail_id( $id ),
                    'comment_status' => $fresh->comment_status,
                    'ping_status'    => $fresh->ping_status,
                    'updated'        => $updated,
                    'message'        => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- bulk-update-content ----
        wp_register_ability( 'atarim/bulk-update-content', [
            'label'               => 'Bulk Update Content',
            'description'         => 'Updates one field across many posts in a single call. Designed for sweeping changes — moving many drafts to published, reassigning posts to a new author after a user leaves, closing comments across a batch. Mixed-field-per-id updates are not supported by design; use update-content in a loop for those. Returns per-id success/failure tracking so partial failures (e.g. capability checks) don\'t mask the rest.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'ids' => [
                        'type'        => 'array',
                        'description' => 'Post IDs to update.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                        'minItems'    => 1,
                        'maxItems'    => 500,
                    ],
                    'field' => [
                        'type'        => 'string',
                        'description' => 'Which field to update on every targeted post. Bulk operations are limited to fields that make sense applied uniformly — status, author, parent, comment_status, ping_status. Use update-content for per-post fields like title or content.',
                        'enum'        => [ 'status', 'author', 'parent', 'comment_status', 'ping_status' ],
                    ],
                    'value' => [
                        'description' => 'The new value for the chosen field. Type depends on the field: string for status / comment_status / ping_status, integer for author / parent.',
                    ],
                ],
                'required'             => [ 'ids', 'field', 'value' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'attempted' => [ 'type' => 'integer' ],
                    'updated'   => [ 'type' => 'integer' ],
                    'failed'    => [ 'type' => 'integer' ],
                    'results'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'      => [ 'type' => 'integer' ],
                                'success' => [ 'type' => 'boolean' ],
                                'message' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'attempted', 'updated', 'failed', 'results', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $ids   = isset( $input['ids'] ) && is_array( $input['ids'] ) ? array_values( array_unique( array_map( 'intval', $input['ids'] ) ) ) : [];
                $field = isset( $input['field'] ) ? sanitize_key( $input['field'] ) : '';
                $value = $input['value'] ?? null;

                if ( empty( $ids ) ) {
                    return [
                        'success'   => false,
                        'attempted' => 0,
                        'updated'   => 0,
                        'failed'    => 0,
                        'results'   => [],
                        'message'   => 'ids is required and must be a non-empty array of positive integers.',
                    ];
                }

                $allowed_fields = [ 'status', 'author', 'parent', 'comment_status', 'ping_status' ];
                if ( ! in_array( $field, $allowed_fields, true ) ) {
                    return [
                        'success'   => false,
                        'attempted' => count( $ids ),
                        'updated'   => 0,
                        'failed'    => 0,
                        'results'   => [],
                        'message'   => sprintf( 'field must be one of: %s.', implode( ', ', $allowed_fields ) ),
                    ];
                }

                // Validate the value once up-front. Same rules apply to every post.
                $normalized_value = null;
                $value_error      = null;

                switch ( $field ) {
                    case 'status':
                        $s = is_string( $value ) ? sanitize_key( $value ) : '';
                        if ( ! in_array( $s, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) ) {
                            $value_error = 'value must be one of: publish, draft, pending, private, future.';
                        }
                        $normalized_value = $s;
                        break;
                    case 'author':
                        $a = (int) $value;
                        if ( $a <= 0 ) {
                            $value_error = 'value must be a positive user ID.';
                        } elseif ( ! get_userdata( $a ) ) {
                            $value_error = sprintf( 'User %d does not exist.', $a );
                        }
                        $normalized_value = $a;
                        break;
                    case 'parent':
                        $p = (int) $value;
                        if ( $p < 0 ) {
                            $value_error = 'value must be 0 or a positive post ID.';
                        }
                        $normalized_value = $p;
                        break;
                    case 'comment_status':
                    case 'ping_status':
                        $s = is_string( $value ) ? sanitize_key( $value ) : '';
                        if ( ! in_array( $s, [ 'open', 'closed' ], true ) ) {
                            $value_error = sprintf( 'value must be "open" or "closed" for %s.', $field );
                        }
                        $normalized_value = $s;
                        break;
                }

                if ( $value_error !== null ) {
                    return [
                        'success'   => false,
                        'attempted' => count( $ids ),
                        'updated'   => 0,
                        'failed'    => 0,
                        'results'   => [],
                        'message'   => $value_error,
                    ];
                }

                // Per-id processing.
                $results = [];
                $updated = 0;
                $failed  = 0;

                foreach ( $ids as $id ) {
                    if ( $id <= 0 ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Invalid id.' ];
                        $failed++;
                        continue;
                    }

                    $post = get_post( $id );
                    if ( ! $post ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Post not found.' ];
                        $failed++;
                        continue;
                    }

                    $pt_obj = get_post_type_object( $post->post_type );
                    if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $id ) ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Permission denied.' ];
                        $failed++;
                        continue;
                    }

                    // Field-specific additional checks.
                    if ( $field === 'status' && in_array( $normalized_value, [ 'publish', 'private', 'future' ], true ) ) {
                        if ( $pt_obj && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
                            $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Permission denied (publish capability required).' ];
                            $failed++;
                            continue;
                        }
                    }
                    if ( $field === 'author' && $normalized_value !== (int) $post->post_author ) {
                        if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_others_posts ) ) {
                            $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Permission denied (edit_others_posts capability required).' ];
                            $failed++;
                            continue;
                        }
                    }
                    if ( $field === 'parent' && $normalized_value > 0 ) {
                        if ( ! is_post_type_hierarchical( $post->post_type ) ) {
                            $results[] = [ 'id' => $id, 'success' => false, 'message' => sprintf( 'Post type "%s" is not hierarchical.', $post->post_type ) ];
                            $failed++;
                            continue;
                        }
                        if ( $normalized_value === $id ) {
                            $results[] = [ 'id' => $id, 'success' => false, 'message' => 'A post cannot be its own parent.' ];
                            $failed++;
                            continue;
                        }
                    }

                    $update = [ 'ID' => $id ];
                    switch ( $field ) {
                        case 'status':         $update['post_status']    = $normalized_value; break;
                        case 'author':         $update['post_author']    = $normalized_value; break;
                        case 'parent':         $update['post_parent']    = $normalized_value; break;
                        case 'comment_status': $update['comment_status'] = $normalized_value; break;
                        case 'ping_status':    $update['ping_status']    = $normalized_value; break;
                    }

                    $result = wp_update_post( $update, true );
                    if ( is_wp_error( $result ) ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => $result->get_error_message() ];
                        $failed++;
                        continue;
                    }

                    $results[] = [ 'id' => $id, 'success' => true, 'message' => 'OK.' ];
                    $updated++;
                }

                $attempted = count( $ids );

                return [
                    'success'   => ( $failed === 0 ),
                    'attempted' => $attempted,
                    'updated'   => $updated,
                    'failed'    => $failed,
                    'results'   => $results,
                    'message'   => sprintf( '%d of %d updated, %d failed.', $updated, $attempted, $failed ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- delete-content ----
        wp_register_ability( 'atarim/delete-content', [
            'label'               => 'Delete Content',
            'description'         => 'Moves a post, page, or custom post type item to trash by default. Pass force: true to permanently delete (skips trash, irreversible).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID. Required.',
                        'minimum'     => 1,
                    ],
                    'force' => [
                        'type'        => 'boolean',
                        'description' => 'If true, permanently delete (bypass trash). Defaults to false (move to trash).',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'id'        => [ 'type' => 'integer' ],
                    'post_type' => [ 'type' => 'string' ],
                    'action'    => [ 'type' => 'string' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [
                        'success' => false,
                        'message' => 'id is required and must be a positive integer.',
                    ];
                }

                $force = ! empty( $input['force'] );

                $post = get_post( $id );
                if ( ! $post ) {
                    return [
                        'success' => false,
                        'message' => sprintf( 'Post %d not found.', $id ),
                    ];
                }

                // Per-post-type capability check.
                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->delete_post, $id ) ) {
                    return [
                        'success' => false,
                        'message' => sprintf( 'You do not have permission to delete this %s.', $post->post_type ),
                    ];
                }

                $post_type = $post->post_type;

                if ( $force ) {
                    $result = wp_delete_post( $id, true );

                    if ( ! $result ) {
                        return [
                            'success'   => false,
                            'id'        => $id,
                            'post_type' => $post_type,
                            'action'    => 'force_delete',
                            'message'   => 'Permanent delete failed.',
                        ];
                    }

                    return [
                        'success'   => true,
                        'id'        => $id,
                        'post_type' => $post_type,
                        'action'    => 'force_delete',
                        'message'   => 'Post permanently deleted.',
                    ];
                }

                // Trash path — wp_trash_post handles post types that support trash;
                // for those that don't (e.g. some CPTs registered without trash support),
                // it falls back to wp_delete_post internally.
                if ( $post->post_status === 'trash' ) {
                    return [
                        'success'   => false,
                        'id'        => $id,
                        'post_type' => $post_type,
                        'action'    => 'trash',
                        'message'   => 'Post is already in trash. Use force: true to permanently delete.',
                    ];
                }

                $result = wp_trash_post( $id );

                if ( ! $result ) {
                    return [
                        'success'   => false,
                        'id'        => $id,
                        'post_type' => $post_type,
                        'action'    => 'trash',
                        'message'   => 'Move to trash failed.',
                    ];
                }

                return [
                    'success'   => true,
                    'id'        => $id,
                    'post_type' => $post_type,
                    'action'    => 'trash',
                    'message'   => 'Post moved to trash.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'delete_posts' );
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

        // ---- list-revisions ----
        wp_register_ability( 'atarim/list-revisions', [
            'label'               => 'List Revisions',
            'description'         => 'Returns the revision history for a post, page, or custom post type item. Newest revision first. Each revision includes its ID, the author who saved it, the timestamp, the title and a content_preview (first ~200 chars). Pass include_content: true to also return full revision bodies — useful when the AI needs to diff revisions, but response size grows. Revisions are WordPress\'s automatic save history; not all post types track revisions (post and page do by default).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Parent post ID.',
                        'minimum'     => 1,
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max revisions to return. -1 for all. Defaults to 20.',
                        'default'     => 20,
                        'minimum'     => -1,
                    ],
                    'include_content' => [
                        'type'        => 'boolean',
                        'description' => 'Include the full content of each revision under "content". Defaults to false; only a short content_preview is returned per revision.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'post_id'   => [ 'type' => 'integer' ],
                    'total'     => [ 'type' => 'integer' ],
                    'returned'  => [ 'type' => 'integer' ],
                    'revisions' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'revision_id'     => [ 'type' => 'integer' ],
                                'date'            => [ 'type' => 'string' ],
                                'author'          => [ 'type' => 'integer' ],
                                'author_name'     => [ 'type' => 'string' ],
                                'title'           => [ 'type' => 'string' ],
                                'excerpt'         => [ 'type' => 'string' ],
                                'content_preview' => [ 'type' => 'string' ],
                                'content'         => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'post_id', 'total', 'returned', 'revisions' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [
                        'success'   => false,
                        'post_id'   => 0,
                        'total'     => 0,
                        'returned'  => 0,
                        'revisions' => [],
                        'message'   => 'id is required and must be a positive integer.',
                    ];
                }

                $post = get_post( $id );
                if ( ! $post ) {
                    return [
                        'success'   => false,
                        'post_id'   => $id,
                        'total'     => 0,
                        'returned'  => 0,
                        'revisions' => [],
                        'message'   => sprintf( 'Post %d not found.', $id ),
                    ];
                }

                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $id ) ) {
                    return [
                        'success'   => false,
                        'post_id'   => $id,
                        'total'     => 0,
                        'returned'  => 0,
                        'revisions' => [],
                        'message'   => sprintf( 'You do not have permission to read revisions for this %s.', $post->post_type ),
                    ];
                }

                $limit           = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
                $include_content = ! empty( $input['include_content'] );

                // wp_get_post_revisions returns newest-first by default. Auto-draft revisions
                // are included; we filter those out as they're noise for an AI caller.
                $args = [];
                if ( $limit > 0 ) {
                    $args['posts_per_page'] = $limit;
                }
                $all = wp_get_post_revisions( $id, $args );

                $revisions = [];
                foreach ( $all as $rev ) {
                    // Skip autosaves — not part of the human-visible revision history.
                    if ( wp_is_post_autosave( $rev ) ) {
                        continue;
                    }

                    $author_obj  = get_userdata( (int) $rev->post_author );
                    $author_name = $author_obj ? $author_obj->display_name : '';

                    $stripped        = trim( wp_strip_all_tags( strip_shortcodes( $rev->post_content ) ) );
                    $stripped        = preg_replace( '/\s+/', ' ', $stripped );
                    $content_preview = ( strlen( $stripped ) > 200 )
                        ? substr( $stripped, 0, 200 ) . '…'
                        : $stripped;

                    $entry = [
                        'revision_id'     => (int) $rev->ID,
                        'date'            => $rev->post_date_gmt,
                        'author'          => (int) $rev->post_author,
                        'author_name'     => $author_name,
                        'title'           => $rev->post_title,
                        'excerpt'         => $rev->post_excerpt,
                        'content_preview' => $content_preview,
                    ];
                    if ( $include_content ) {
                        $entry['content'] = $rev->post_content;
                    }
                    $revisions[] = $entry;
                }

                return [
                    'success'   => true,
                    'post_id'   => $id,
                    'total'     => count( $revisions ),
                    'returned'  => count( $revisions ),
                    'revisions' => $revisions,
                    'message'   => sprintf( '%d revision(s) found for post %d.', count( $revisions ), $id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- restore-revision ----
        wp_register_ability( 'atarim/restore-revision', [
            'label'               => 'Restore Revision',
            'description'         => 'Restores a post to the state captured in a prior revision. The revision_id is taken from list-revisions output. The current post content is replaced by the revision\'s content; WordPress typically captures the pre-restore state as a new revision in the chain (so the operation is not destructive in the catastrophic sense), but the AI should not rely on that for rollback safety and should call list-revisions before AND after to confirm.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'revision_id' => [
                        'type'        => 'integer',
                        'description' => 'Revision ID to restore. Obtained from list-revisions.',
                        'minimum'     => 1,
                    ],
                ],
                'required'             => [ 'revision_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'revision_id'    => [ 'type' => 'integer' ],
                    'post_id'        => [ 'type' => 'integer' ],
                    'title'          => [ 'type' => 'string' ],
                    'restored_from_date' => [ 'type' => 'string' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $revision_id = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
                if ( $revision_id <= 0 ) {
                    return [
                        'success'     => false,
                        'revision_id' => 0,
                        'post_id'     => 0,
                        'message'     => 'revision_id is required and must be a positive integer.',
                    ];
                }

                $revision = wp_get_post_revision( $revision_id );
                if ( ! $revision ) {
                    return [
                        'success'     => false,
                        'revision_id' => $revision_id,
                        'post_id'     => 0,
                        'message'     => sprintf( 'Revision %d not found.', $revision_id ),
                    ];
                }

                $parent_id = (int) $revision->post_parent;
                $parent    = get_post( $parent_id );
                if ( ! $parent ) {
                    return [
                        'success'     => false,
                        'revision_id' => $revision_id,
                        'post_id'     => $parent_id,
                        'message'     => sprintf( 'Parent post %d for revision %d no longer exists.', $parent_id, $revision_id ),
                    ];
                }

                $pt_obj = get_post_type_object( $parent->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $parent_id ) ) {
                    return [
                        'success'     => false,
                        'revision_id' => $revision_id,
                        'post_id'     => $parent_id,
                        'message'     => sprintf( 'You do not have permission to restore revisions for this %s.', $parent->post_type ),
                    ];
                }

                $result = wp_restore_post_revision( $revision_id );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'     => false,
                        'revision_id' => $revision_id,
                        'post_id'     => $parent_id,
                        'message'     => 'Restore failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === null || $result === false ) {
                    return [
                        'success'     => false,
                        'revision_id' => $revision_id,
                        'post_id'     => $parent_id,
                        'message'     => 'Restore failed: WordPress reported the operation did not complete.',
                    ];
                }

                $restored_post = get_post( $parent_id );

                return [
                    'success'            => true,
                    'revision_id'        => $revision_id,
                    'post_id'            => $parent_id,
                    'title'              => $restored_post ? $restored_post->post_title : '',
                    'restored_from_date' => $revision->post_date_gmt,
                    'message'            => sprintf( 'Post %d restored from revision %d (dated %s).', $parent_id, $revision_id, $revision->post_date_gmt ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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

        // ---- duplicate-post ----
        wp_register_ability( 'atarim/duplicate-post', [
            'label'               => 'Duplicate Post',
            'description'         => 'Duplicates a post, page, or custom post type item. Copies core fields, all post meta (including page-builder payloads such as Elementor/Bricks data) except editing-lock and old-slug keys, and all taxonomy terms (categories, tags, custom taxonomies). The featured image is shared (same attachment). The new item is set to draft status with " (Copy)" appended to the title and a unique slug, and the acting user becomes the author. Comments are not copied. Single item only: child posts and attachments are not duplicated. Only public, non-internal post types are allowed. Returns the new post id, slug, status, title, and edit/preview links.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post, page, or custom post type item to duplicate.',
                    ],
                ],
                'required'             => [ 'post_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'new_post_id'    => [ 'type' => 'integer' ],
                    'source_post_id' => [ 'type' => 'integer' ],
                    'new_slug'       => [ 'type' => 'string' ],
                    'status'         => [ 'type' => 'string' ],
                    'title'          => [ 'type' => 'string' ],
                    'edit_link'      => [ 'type' => 'string' ],
                    'preview_link'   => [ 'type' => 'string' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required'   => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input ) {
                $source_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
                if ( ! $source_id ) {
                    return [ 'success' => false, 'message' => 'A valid post_id is required.' ];
                }

                $source = get_post( $source_id );
                if ( ! $source ) {
                    return [ 'success' => false, 'message' => sprintf( 'Post %d not found.', $source_id ) ];
                }

                $post_type = $source->post_type;
                $pt_obj    = get_post_type_object( $post_type );
                if ( ! $pt_obj ) {
                    return [ 'success' => false, 'message' => sprintf( 'Unknown post type "%s".', $post_type ) ];
                }

                // Block internal / non-public post types.
                $excluded = [
                    'attachment', 'revision', 'nav_menu_item', 'custom_css',
                    'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
                    'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
                ];
                if ( in_array( $post_type, $excluded, true ) || empty( $pt_obj->public ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Post type "%s" cannot be duplicated (internal or non-public).', $post_type ) ];
                }

                // Capability: must be able to edit the source and create the target type.
                if ( ! current_user_can( $pt_obj->cap->edit_post, $source_id ) ) {
                    return [ 'success' => false, 'message' => 'You do not have permission to duplicate this post.' ];
                }
                if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'You do not have permission to create %s items.', $post_type ) ];
                }

                // Build the new title + a unique draft slug (wp_insert_post does not
                // uniquify slugs for drafts, so compute it explicitly).
                $new_title    = ( '' !== $source->post_title ) ? $source->post_title . ' (Copy)' : '(Copy)';
                $desired_slug = sanitize_title( $new_title );
                $unique_slug  = wp_unique_post_slug( $desired_slug, 0, 'draft', $post_type, (int) $source->post_parent );

                $acting_user = get_current_user_id();

                $postarr = [
                    'post_title'     => $new_title,
                    'post_name'      => $unique_slug,
                    'post_content'   => $source->post_content,
                    'post_excerpt'   => $source->post_excerpt,
                    'post_status'    => 'draft',
                    'post_type'      => $post_type,
                    'post_author'    => $acting_user ? $acting_user : (int) $source->post_author,
                    'post_parent'    => (int) $source->post_parent,
                    'menu_order'     => (int) $source->menu_order,
                    'comment_status' => $source->comment_status,
                    'ping_status'    => $source->ping_status,
                    'post_password'  => $source->post_password,
                ];

                $new_id = wp_insert_post( wp_slash( $postarr ), true );
                if ( is_wp_error( $new_id ) ) {
                    return [ 'success' => false, 'message' => 'Duplicate failed: ' . $new_id->get_error_message() ];
                }

                // Copy taxonomy terms for every taxonomy on this post type
                // (includes categories, tags, custom taxonomies and post_format).
                foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
                    $term_ids = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );
                    if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
                        wp_set_object_terms( $new_id, $term_ids, $taxonomy, false );
                    }
                }

                // Copy post meta (multi-value safe). _thumbnail_id is copied here, so the
                // featured image is shared. Editing-lock and old-slug keys are skipped.
                $skip_meta = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' ];
                $all_meta  = get_post_meta( $source_id );
                if ( is_array( $all_meta ) ) {
                    foreach ( $all_meta as $meta_key => $meta_values ) {
                        if ( in_array( $meta_key, $skip_meta, true ) ) {
                            continue;
                        }
                        foreach ( (array) $meta_values as $meta_value ) {
                            add_post_meta( $new_id, $meta_key, wp_slash( maybe_unserialize( $meta_value ) ) );
                        }
                    }
                }

                $new_post     = get_post( $new_id );
                $preview_link = get_preview_post_link( $new_id );

                return [
                    'success'        => true,
                    'new_post_id'    => (int) $new_id,
                    'source_post_id' => $source_id,
                    'new_slug'       => $new_post ? $new_post->post_name : $unique_slug,
                    'status'         => 'draft',
                    'title'          => $new_title,
                    'edit_link'      => admin_url( 'post.php?post=' . (int) $new_id . '&action=edit' ),
                    'preview_link'   => $preview_link ? $preview_link : '',
                    'message'        => sprintf( 'Duplicated post %d as draft %d ("%s").', $source_id, (int) $new_id, $new_title ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
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
    }
}