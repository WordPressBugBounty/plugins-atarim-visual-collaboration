<?php
/**
 * Taxonomy and term management MCP abilities.
 *
 * Registers Atarim/* abilities for working with categories, tags, and
 * custom taxonomies — listing taxonomies and their terms, full CRUD on
 * terms, assigning terms to posts, and audit/cleanup primitives for
 * taxonomy bloat (empty terms, merge near-duplicates).
 *
 * Note: WordPress taxonomies themselves can only be REGISTERED at
 * code-time via register_taxonomy(). This cluster works with TERMS
 * inside taxonomies that already exist — that covers ~95% of what
 * "create a category" or "create a tag" actually means in practice.
 *
 * Exposed abilities:
 *   atarim/list-taxonomies           Discover registered taxonomies + post type bindings.
 *   atarim/list-terms                Terms in a taxonomy with counts, parents, optional search.
 *   atarim/get-term                  Single term by ID or slug — full detail.
 *   atarim/create-term               Create a term (with optional parent for hierarchical).
 *   atarim/update-term               Rename, change slug/description/parent.
 *   atarim/delete-term               Delete a term (refuses to leave posts orphaned without opt-in).
 *   atarim/assign-terms              Assign terms to a post; replace or append mode.
 *   atarim/find-empty-terms          Terms with zero posts in a taxonomy.
 *   atarim/merge-terms               Reassign all posts from source term to target, then delete source.
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

class AVCF_Abilities_Taxonomies extends AVCF_Abilities_Base {

    /**
     * Register all taxonomy/term abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {

        // ---- list-taxonomies ----
        wp_register_ability( 'atarim/list-taxonomies', [
            'label'               => 'List Taxonomies',
            'description'         => 'Returns all registered taxonomies on this site, including built-in (category, post_tag) and custom taxonomies registered by themes or plugins. Each entry includes the slug, label, hierarchical flag, and which post types it applies to. Use this for discovery before list-terms or create-term.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'public_only' => [
                        'type'        => 'boolean',
                        'description' => 'Filter to only public taxonomies (those exposed in the admin and on the front end). Defaults to true. Set false to also see internal taxonomies (e.g. nav_menu, link_category).',
                        'default'     => true,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'      => [ 'type' => 'integer' ],
                    'taxonomies' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'         => [ 'type' => 'string' ],
                                'label'        => [ 'type' => 'string' ],
                                'description'  => [ 'type' => 'string' ],
                                'hierarchical' => [ 'type' => 'boolean' ],
                                'public'       => [ 'type' => 'boolean' ],
                                'post_types'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                                'term_count'   => [ 'type' => 'integer' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'taxonomies' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $public_only = ! isset( $input['public_only'] ) ? true : (bool) $input['public_only'];

                $args     = $public_only ? [ 'public' => true ] : [];
                $all      = get_taxonomies( $args, 'objects' );
                $taxonomies = [];

                foreach ( $all as $tax ) {
                    $count = wp_count_terms( [ 'taxonomy' => $tax->name, 'hide_empty' => false ] );
                    $taxonomies[] = [
                        'slug'         => $tax->name,
                        'label'        => $tax->label,
                        'description'  => isset( $tax->description ) ? (string) $tax->description : '',
                        'hierarchical' => (bool) $tax->hierarchical,
                        'public'       => (bool) $tax->public,
                        'post_types'   => array_values( (array) $tax->object_type ),
                        'term_count'   => is_wp_error( $count ) ? 0 : (int) $count,
                    ];
                }

                return [
                    'total'      => count( $taxonomies ),
                    'taxonomies' => $taxonomies,
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

        // ---- list-terms ----
        wp_register_ability( 'atarim/list-terms', [
            'label'               => 'List Terms',
            'description'         => 'Returns terms in a taxonomy with post counts, parent IDs (for hierarchical taxonomies), and descriptions. Optional search filter matches name and slug. Use hide_empty: true to skip terms not assigned to any post.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug (e.g. "category", "post_tag", or a custom taxonomy).',
                        'minLength'   => 1,
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Match term name or slug containing this string.',
                        'minLength'   => 1,
                    ],
                    'hide_empty' => [
                        'type'        => 'boolean',
                        'description' => 'Skip terms with zero posts. Defaults to false (show all).',
                        'default'     => false,
                    ],
                    'orderby' => [
                        'type'        => 'string',
                        'description' => 'Field to sort by.',
                        'enum'        => [ 'name', 'slug', 'count', 'term_id', 'parent' ],
                        'default'     => 'name',
                    ],
                    'order' => [
                        'type'        => 'string',
                        'enum'        => [ 'ASC', 'DESC' ],
                        'default'     => 'ASC',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max terms. -1 for all. Defaults to 100.',
                        'default'     => 100,
                        'minimum'     => -1,
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Skip this many terms (for pagination).',
                        'default'     => 0,
                        'minimum'     => 0,
                    ],
                ],
                'required'             => [ 'taxonomy' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'taxonomy' => [ 'type' => 'string' ],
                    'total'    => [ 'type' => 'integer' ],
                    'returned' => [ 'type' => 'integer' ],
                    'terms'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'term_id'     => [ 'type' => 'integer' ],
                                'name'        => [ 'type' => 'string' ],
                                'slug'        => [ 'type' => 'string' ],
                                'description' => [ 'type' => 'string' ],
                                'parent'      => [ 'type' => 'integer' ],
                                'count'       => [ 'type' => 'integer' ],
                            ],
                        ],
                    ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'total', 'returned', 'terms' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                if ( $taxonomy === '' ) {
                    return [
                        'success'  => false,
                        'taxonomy' => '',
                        'total'    => 0,
                        'returned' => 0,
                        'terms'    => [],
                        'message'  => 'taxonomy is required.',
                    ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [
                        'success'  => false,
                        'taxonomy' => $taxonomy,
                        'total'    => 0,
                        'returned' => 0,
                        'terms'    => [],
                        'message'  => sprintf( 'Taxonomy "%s" does not exist on this site.', $taxonomy ),
                    ];
                }

                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 100;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

                $args = [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => ! empty( $input['hide_empty'] ),
                    'orderby'    => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'name',
                    'order'      => ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'DESC' ) ? 'DESC' : 'ASC',
                ];
                if ( $limit > 0 ) {
                    $args['number'] = $limit;
                    $args['offset'] = $offset;
                }
                if ( isset( $input['search'] ) && $input['search'] !== '' ) {
                    $args['search'] = (string) $input['search'];
                }

                $terms = get_terms( $args );

                if ( is_wp_error( $terms ) ) {
                    return [
                        'success'  => false,
                        'taxonomy' => $taxonomy,
                        'total'    => 0,
                        'returned' => 0,
                        'terms'    => [],
                        'message'  => 'Query failed: ' . $terms->get_error_message(),
                    ];
                }

                // Get total without pagination for the response envelope.
                $count_args = $args;
                unset( $count_args['number'], $count_args['offset'] );
                $total = wp_count_terms( $count_args );
                $total = is_wp_error( $total ) ? count( $terms ) : (int) $total;

                $items = [];
                foreach ( $terms as $term ) {
                    $items[] = [
                        'term_id'     => (int) $term->term_id,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'description' => (string) $term->description,
                        'parent'      => (int) $term->parent,
                        'count'       => (int) $term->count,
                    ];
                }

                return [
                    'success'  => true,
                    'taxonomy' => $taxonomy,
                    'total'    => $total,
                    'returned' => count( $items ),
                    'terms'    => $items,
                    'message'  => sprintf( 'Returned %d of %d term(s).', count( $items ), $total ),
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

        // ---- get-term ----
        wp_register_ability( 'atarim/get-term', [
            'label'               => 'Get Term',
            'description'         => 'Returns full detail for a single term by ID or slug within a given taxonomy. Useful before update-term or merge-terms when the AI needs to read the current description, parent, or count.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug.',
                        'minLength'   => 1,
                    ],
                    'term_id' => [
                        'type'        => 'integer',
                        'description' => 'Term ID. Mutually exclusive with slug — pass one or the other.',
                        'minimum'     => 1,
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'Term slug. Mutually exclusive with term_id.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'taxonomy' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'taxonomy'    => [ 'type' => 'string' ],
                    'term_id'     => [ 'type' => 'integer' ],
                    'name'        => [ 'type' => 'string' ],
                    'slug'        => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'parent'      => [ 'type' => 'integer' ],
                    'count'       => [ 'type' => 'integer' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $slug     = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';

                if ( $taxonomy === '' ) {
                    return [ 'success' => false, 'taxonomy' => '', 'message' => 'taxonomy is required.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not exist on this site.', $taxonomy ) ];
                }
                if ( $term_id <= 0 && $slug === '' ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Pass either term_id or slug.' ];
                }

                $term = ( $term_id > 0 )
                    ? get_term( $term_id, $taxonomy )
                    : get_term_by( 'slug', $slug, $taxonomy );

                if ( ! $term || is_wp_error( $term ) ) {
                    $msg = ( $term_id > 0 )
                        ? sprintf( 'Term %d not found in taxonomy "%s".', $term_id, $taxonomy )
                        : sprintf( 'Term with slug "%s" not found in taxonomy "%s".', $slug, $taxonomy );
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => $msg ];
                }

                return [
                    'success'     => true,
                    'taxonomy'    => $taxonomy,
                    'term_id'     => (int) $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => (string) $term->description,
                    'parent'      => (int) $term->parent,
                    'count'       => (int) $term->count,
                    'message'     => 'OK.',
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

        // ---- create-term ----
        wp_register_ability( 'atarim/create-term', [
            'label'               => 'Create Term',
            'description'         => 'Creates a new term in an existing taxonomy. The taxonomy itself must already be registered — taxonomies (e.g. "category", "product_brand") are defined in code via register_taxonomy() and cannot be created at runtime. Pass parent for hierarchical taxonomies to nest under another term.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug the new term belongs to. Must already exist.',
                        'minLength'   => 1,
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Display name of the term.',
                        'minLength'   => 1,
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'URL slug. Omit to let WordPress generate from name. WordPress enforces uniqueness within the taxonomy.',
                        'minLength'   => 1,
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Optional description for the term.',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'Parent term ID for hierarchical taxonomies. 0 (default) means no parent. Hard-fails on non-hierarchical taxonomies or unknown parent.',
                        'minimum'     => 0,
                        'default'     => 0,
                    ],
                ],
                'required'             => [ 'taxonomy', 'name' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'taxonomy' => [ 'type' => 'string' ],
                    'term_id'  => [ 'type' => 'integer' ],
                    'name'     => [ 'type' => 'string' ],
                    'slug'     => [ 'type' => 'string' ],
                    'parent'   => [ 'type' => 'integer' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $name     = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';

                if ( $taxonomy === '' || $name === '' ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'taxonomy and name are required.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not exist on this site.', $taxonomy ) ];
                }

                $tax_obj = get_taxonomy( $taxonomy );
                if ( $tax_obj && ! current_user_can( $tax_obj->cap->edit_terms ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'You do not have permission to create terms in "%s".', $taxonomy ) ];
                }

                $args = [];
                if ( isset( $input['slug'] ) && $input['slug'] !== '' ) {
                    $args['slug'] = sanitize_title( (string) $input['slug'] );
                }
                if ( isset( $input['description'] ) ) {
                    $args['description'] = wp_kses_post( (string) $input['description'] );
                }

                $parent = isset( $input['parent'] ) ? (int) $input['parent'] : 0;
                if ( $parent > 0 ) {
                    if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
                        return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" is not hierarchical; parent must be 0.', $taxonomy ) ];
                    }
                    $parent_term = get_term( $parent, $taxonomy );
                    if ( ! $parent_term || is_wp_error( $parent_term ) ) {
                        return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Parent term %d does not exist in "%s".', $parent, $taxonomy ) ];
                    }
                    $args['parent'] = $parent;
                }

                $result = wp_insert_term( $name, $taxonomy, $args );

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Create failed: ' . $result->get_error_message() ];
                }

                $term_id = (int) $result['term_id'];
                $created = get_term( $term_id, $taxonomy );

                return [
                    'success'  => true,
                    'taxonomy' => $taxonomy,
                    'term_id'  => $term_id,
                    'name'     => $created ? $created->name : $name,
                    'slug'     => $created ? $created->slug : '',
                    'parent'   => $created ? (int) $created->parent : 0,
                    'message'  => 'Term created.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_categories' );
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

        // ---- update-term ----
        wp_register_ability( 'atarim/update-term', [
            'label'               => 'Update Term',
            'description'         => 'Updates an existing term. Only term_id (or slug) + taxonomy are required; pass any subset of name, slug, description, parent. Omitted fields are left unchanged. To remove a parent (move a child to the top level), pass parent: 0.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug.',
                        'minLength'   => 1,
                    ],
                    'term_id' => [
                        'type'        => 'integer',
                        'description' => 'Term ID to update. Mutually exclusive with slug.',
                        'minimum'     => 1,
                    ],
                    'slug_lookup' => [
                        'type'        => 'string',
                        'description' => 'Lookup the term by this slug. Mutually exclusive with term_id.',
                        'minLength'   => 1,
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'New display name. Omit to leave unchanged.',
                        'minLength'   => 1,
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'New URL slug. Omit to leave unchanged. WordPress enforces uniqueness.',
                        'minLength'   => 1,
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'New description. Pass empty string to clear. Omit to leave unchanged.',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'New parent term ID. 0 removes the parent. Hard-fails for non-hierarchical taxonomies, unknown parents, or self-parenting.',
                        'minimum'     => 0,
                    ],
                ],
                'required'             => [ 'taxonomy' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'taxonomy' => [ 'type' => 'string' ],
                    'term_id'  => [ 'type' => 'integer' ],
                    'name'     => [ 'type' => 'string' ],
                    'slug'     => [ 'type' => 'string' ],
                    'parent'   => [ 'type' => 'integer' ],
                    'updated'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $lookup   = isset( $input['slug_lookup'] ) ? sanitize_title( (string) $input['slug_lookup'] ) : '';

                if ( $taxonomy === '' ) {
                    return [ 'success' => false, 'taxonomy' => '', 'message' => 'taxonomy is required.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) ];
                }
                if ( $term_id <= 0 && $lookup === '' ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Pass either term_id or slug_lookup.' ];
                }

                $term = ( $term_id > 0 )
                    ? get_term( $term_id, $taxonomy )
                    : get_term_by( 'slug', $lookup, $taxonomy );

                if ( ! $term || is_wp_error( $term ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Term not found.' ];
                }
                $term_id = (int) $term->term_id;

                $tax_obj = get_taxonomy( $taxonomy );
                if ( $tax_obj && ! current_user_can( $tax_obj->cap->edit_terms ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'You do not have permission to edit terms in "%s".', $taxonomy ) ];
                }

                $args    = [];
                $updated = [];

                if ( array_key_exists( 'name', $input ) ) {
                    $args['name'] = sanitize_text_field( (string) $input['name'] );
                    $updated[]    = 'name';
                }
                if ( array_key_exists( 'slug', $input ) ) {
                    $args['slug'] = sanitize_title( (string) $input['slug'] );
                    $updated[]    = 'slug';
                }
                if ( array_key_exists( 'description', $input ) ) {
                    $args['description'] = wp_kses_post( (string) $input['description'] );
                    $updated[]           = 'description';
                }
                if ( array_key_exists( 'parent', $input ) ) {
                    $new_parent = (int) $input['parent'];
                    if ( $new_parent < 0 ) {
                        return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'parent must be 0 or a positive term ID.' ];
                    }
                    if ( $new_parent > 0 ) {
                        if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
                            return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" is not hierarchical; parent must be 0.', $taxonomy ) ];
                        }
                        if ( $new_parent === $term_id ) {
                            return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'A term cannot be its own parent.' ];
                        }
                        $parent_term = get_term( $new_parent, $taxonomy );
                        if ( ! $parent_term || is_wp_error( $parent_term ) ) {
                            return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Parent term %d does not exist in "%s".', $new_parent, $taxonomy ) ];
                        }
                    }
                    $args['parent'] = $new_parent;
                    $updated[]      = 'parent';
                }

                if ( empty( $args ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'No fields provided to update.' ];
                }

                $result = wp_update_term( $term_id, $taxonomy, $args );

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Update failed: ' . $result->get_error_message() ];
                }

                $fresh = get_term( $term_id, $taxonomy );

                return [
                    'success'  => true,
                    'taxonomy' => $taxonomy,
                    'term_id'  => $term_id,
                    'name'     => $fresh ? $fresh->name : '',
                    'slug'     => $fresh ? $fresh->slug : '',
                    'parent'   => $fresh ? (int) $fresh->parent : 0,
                    'updated'  => $updated,
                    'message'  => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_categories' );
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

        // ---- delete-term ----
        wp_register_ability( 'atarim/delete-term', [
            'label'               => 'Delete Term',
            'description'         => 'Deletes a term from a taxonomy. By default refuses if any post is assigned to the term — pass confirm_orphans: true to delete anyway (posts lose this term but keep all others). Refuses to delete the site\'s default category (would break WordPress assumptions). Hierarchical: child terms are NOT cascaded; their parent is set to 0 (top level).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug.',
                        'minLength'   => 1,
                    ],
                    'term_id' => [
                        'type'        => 'integer',
                        'description' => 'Term ID. Mutually exclusive with slug.',
                        'minimum'     => 1,
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'Term slug. Mutually exclusive with term_id.',
                        'minLength'   => 1,
                    ],
                    'confirm_orphans' => [
                        'type'        => 'boolean',
                        'description' => 'If true, allow deletion even when the term has posts assigned. Without this flag, deletion of a non-empty term hard-fails so the caller can audit the impact first.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'taxonomy' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'taxonomy'       => [ 'type' => 'string' ],
                    'term_id'        => [ 'type' => 'integer' ],
                    'orphaned_posts' => [ 'type' => 'integer' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $slug     = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
                $confirm  = ! empty( $input['confirm_orphans'] );

                if ( $taxonomy === '' ) {
                    return [ 'success' => false, 'taxonomy' => '', 'message' => 'taxonomy is required.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) ];
                }
                if ( $term_id <= 0 && $slug === '' ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Pass either term_id or slug.' ];
                }

                $term = ( $term_id > 0 )
                    ? get_term( $term_id, $taxonomy )
                    : get_term_by( 'slug', $slug, $taxonomy );

                if ( ! $term || is_wp_error( $term ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Term not found.' ];
                }
                $term_id = (int) $term->term_id;

                $tax_obj = get_taxonomy( $taxonomy );
                if ( $tax_obj && ! current_user_can( $tax_obj->cap->delete_terms ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'You do not have permission to delete terms in "%s".', $taxonomy ) ];
                }

                // Refuse to delete the site's default category.
                if ( $taxonomy === 'category' && $term_id === (int) get_option( 'default_category' ) ) {
                    return [
                        'success'  => false,
                        'taxonomy' => $taxonomy,
                        'term_id'  => $term_id,
                        'message'  => 'Cannot delete the default category. Change the default category in Settings → Writing first.',
                    ];
                }

                $assigned = (int) $term->count;
                if ( $assigned > 0 && ! $confirm ) {
                    return [
                        'success'        => false,
                        'taxonomy'       => $taxonomy,
                        'term_id'        => $term_id,
                        'orphaned_posts' => $assigned,
                        'message'        => sprintf(
                            'Term "%s" is assigned to %d post(s). Pass confirm_orphans: true to delete anyway, or use merge-terms to reassign first.',
                            $term->slug,
                            $assigned
                        ),
                    ];
                }

                $result = wp_delete_term( $term_id, $taxonomy );

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'message' => 'Delete failed: ' . $result->get_error_message() ];
                }
                if ( $result === false || $result === 0 ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'message' => 'Delete failed: WordPress reported the operation did not complete.' ];
                }

                return [
                    'success'        => true,
                    'taxonomy'       => $taxonomy,
                    'term_id'        => $term_id,
                    'orphaned_posts' => $assigned,
                    'message'        => $assigned > 0
                        ? sprintf( 'Term deleted; %d post(s) were untagged.', $assigned )
                        : 'Term deleted.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_categories' );
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

        // ---- assign-terms ----
        wp_register_ability( 'atarim/assign-terms', [
            'label'               => 'Assign Terms',
            'description'         => 'Assigns taxonomy terms to a post. Two modes: "replace" (the post will have exactly the terms you pass, all others are removed) and "append" (the listed terms are added; existing terms are kept). Identify terms by slug (recommended for AI workflows) or by term_ids. Unknown slugs are created automatically by WordPress when the taxonomy allows it (matches admin UI behaviour).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to modify.',
                        'minimum'     => 1,
                    ],
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug.',
                        'minLength'   => 1,
                    ],
                    'terms' => [
                        'type'        => 'array',
                        'description' => 'Term slugs to assign. Mutually exclusive with term_ids.',
                        'items'       => [ 'type' => 'string' ],
                        'minItems'    => 0,
                    ],
                    'term_ids' => [
                        'type'        => 'array',
                        'description' => 'Term IDs to assign. Mutually exclusive with terms.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                        'minItems'    => 0,
                    ],
                    'mode' => [
                        'type'        => 'string',
                        'description' => '"replace" (default): the listed terms become the complete set on the post; all others are removed. "append": listed terms are added to existing ones.',
                        'enum'        => [ 'replace', 'append' ],
                        'default'     => 'replace',
                    ],
                ],
                'required'             => [ 'post_id', 'taxonomy' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'         => [ 'type' => 'boolean' ],
                    'post_id'         => [ 'type' => 'integer' ],
                    'taxonomy'        => [ 'type' => 'string' ],
                    'mode'            => [ 'type' => 'string' ],
                    'assigned_count'  => [ 'type' => 'integer' ],
                    'assigned_terms'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'         => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'post_id', 'taxonomy', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $mode     = ( isset( $input['mode'] ) && $input['mode'] === 'append' ) ? 'append' : 'replace';

                if ( $post_id <= 0 || $taxonomy === '' ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'message' => 'post_id and taxonomy are required.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) ];
                }

                $post = get_post( $post_id );
                if ( ! $post ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Post %d not found.', $post_id ) ];
                }

                // Taxonomy must apply to this post's type.
                $applicable = get_object_taxonomies( $post->post_type, 'names' );
                if ( ! in_array( $taxonomy, $applicable, true ) ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not apply to post type "%s".', $taxonomy, $post->post_type ) ];
                }

                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $post_id ) ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'message' => sprintf( 'You do not have permission to edit this %s.', $post->post_type ) ];
                }

                // Build the terms array. WordPress's wp_set_object_terms accepts mixed:
                // when passing IDs, every value must be an integer; when passing slugs/names,
                // strings. We split the two paths.
                $has_slugs = isset( $input['terms'] ) && is_array( $input['terms'] );
                $has_ids   = isset( $input['term_ids'] ) && is_array( $input['term_ids'] );

                if ( $has_slugs && $has_ids ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'message' => 'Pass either terms or term_ids, not both.' ];
                }

                if ( $has_ids ) {
                    $terms_input  = array_values( array_map( 'intval', $input['term_ids'] ) );
                    $use_ids_path = true;
                } else {
                    $terms_input  = array_values( array_map( 'strval', $input['terms'] ?? [] ) );
                    $use_ids_path = false;
                }

                $append = ( $mode === 'append' );

                $result = $use_ids_path
                    ? wp_set_object_terms( $post_id, $terms_input, $taxonomy, $append )
                    : wp_set_object_terms( $post_id, $terms_input, $taxonomy, $append );

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'mode' => $mode, 'message' => 'Assignment failed: ' . $result->get_error_message() ];
                }

                // Reload the current terms for response.
                $now           = wp_get_object_terms( $post_id, $taxonomy );
                $current_slugs = is_wp_error( $now ) ? [] : array_map( function( $t ) { return $t->slug; }, $now );

                return [
                    'success'        => true,
                    'post_id'        => $post_id,
                    'taxonomy'       => $taxonomy,
                    'mode'           => $mode,
                    'assigned_count' => count( $current_slugs ),
                    'assigned_terms' => $current_slugs,
                    'message'        => sprintf( 'Post now has %d term(s) in "%s" (mode: %s).', count( $current_slugs ), $taxonomy, $mode ),
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

        // ---- find-empty-terms ----
        wp_register_ability( 'atarim/find-empty-terms', [
            'label'               => 'Find Empty Terms',
            'description'         => 'Returns terms in a taxonomy with zero post assignments — useful for taxonomy bloat cleanup. The "default category" is intentionally excluded since it must always exist. Pair with delete-term to actually remove them. To find near-duplicate terms (e.g. "design" vs "Design" vs "designs"), call list-terms and run similarity checks on the slugs in your own logic.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug.',
                        'minLength'   => 1,
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max terms to return. -1 for all. Defaults to 100.',
                        'default'     => 100,
                        'minimum'     => -1,
                    ],
                ],
                'required'             => [ 'taxonomy' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'taxonomy' => [ 'type' => 'string' ],
                    'total'    => [ 'type' => 'integer' ],
                    'terms'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'term_id' => [ 'type' => 'integer' ],
                                'name'    => [ 'type' => 'string' ],
                                'slug'    => [ 'type' => 'string' ],
                                'parent'  => [ 'type' => 'integer' ],
                            ],
                        ],
                    ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'total', 'terms' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $limit    = isset( $input['limit'] ) ? (int) $input['limit'] : 100;

                if ( $taxonomy === '' ) {
                    return [ 'success' => false, 'taxonomy' => '', 'total' => 0, 'terms' => [], 'message' => 'taxonomy is required.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'total' => 0, 'terms' => [], 'message' => sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) ];
                }

                $args = [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                    // get_terms returns full term objects; we filter by count below.
                ];
                if ( $limit > 0 ) {
                    // We need to filter after the fact, so over-fetch a bit.
                    $args['number'] = $limit * 4;
                }

                $all = get_terms( $args );
                if ( is_wp_error( $all ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'total' => 0, 'terms' => [], 'message' => $all->get_error_message() ];
                }

                $default_cat = ( $taxonomy === 'category' ) ? (int) get_option( 'default_category' ) : 0;

                $empty = [];
                foreach ( $all as $term ) {
                    if ( (int) $term->count !== 0 ) {
                        continue;
                    }
                    if ( $default_cat > 0 && (int) $term->term_id === $default_cat ) {
                        continue;
                    }
                    $empty[] = [
                        'term_id' => (int) $term->term_id,
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                        'parent'  => (int) $term->parent,
                    ];
                    if ( $limit > 0 && count( $empty ) >= $limit ) {
                        break;
                    }
                }

                return [
                    'success'  => true,
                    'taxonomy' => $taxonomy,
                    'total'    => count( $empty ),
                    'terms'    => $empty,
                    'message'  => sprintf( '%d empty term(s) found in "%s".', count( $empty ), $taxonomy ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_categories' );
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

        // ---- merge-terms ----
        wp_register_ability( 'atarim/merge-terms', [
            'label'               => 'Merge Terms',
            'description'         => 'Reassigns all posts currently tagged with the source term to the target term, then deletes the source. Use to deduplicate taxonomy bloat (e.g. merge "design" → "Design"). Source and target must be in the same taxonomy. Source must not be the default category. Operations are not transactional: posts are reassigned first, then the source is deleted. If reassignment partially fails, the source is NOT deleted and the operation returns success: false with details.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug containing both terms.',
                        'minLength'   => 1,
                    ],
                    'source_slug' => [
                        'type'        => 'string',
                        'description' => 'Slug of the term to merge FROM (will be deleted at the end).',
                        'minLength'   => 1,
                    ],
                    'target_slug' => [
                        'type'        => 'string',
                        'description' => 'Slug of the term to merge INTO (will receive all of source\'s posts).',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'taxonomy', 'source_slug', 'target_slug' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'           => [ 'type' => 'boolean' ],
                    'taxonomy'          => [ 'type' => 'string' ],
                    'source_term_id'    => [ 'type' => 'integer' ],
                    'target_term_id'    => [ 'type' => 'integer' ],
                    'reassigned_posts'  => [ 'type' => 'integer' ],
                    'reassign_failures' => [ 'type' => 'integer' ],
                    'source_deleted'    => [ 'type' => 'boolean' ],
                    'message'           => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'taxonomy', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $taxonomy    = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
                $source_slug = isset( $input['source_slug'] ) ? sanitize_title( (string) $input['source_slug'] ) : '';
                $target_slug = isset( $input['target_slug'] ) ? sanitize_title( (string) $input['target_slug'] ) : '';

                if ( $taxonomy === '' || $source_slug === '' || $target_slug === '' ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'taxonomy, source_slug, and target_slug are all required.' ];
                }
                if ( $source_slug === $target_slug ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'source_slug and target_slug must be different.' ];
                }
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) ];
                }

                $source = get_term_by( 'slug', $source_slug, $taxonomy );
                $target = get_term_by( 'slug', $target_slug, $taxonomy );
                if ( ! $source ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Source term "%s" not found in "%s".', $source_slug, $taxonomy ) ];
                }
                if ( ! $target ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'Target term "%s" not found in "%s".', $target_slug, $taxonomy ) ];
                }

                $source_id = (int) $source->term_id;
                $target_id = (int) $target->term_id;

                // Permission and safety guards.
                $tax_obj = get_taxonomy( $taxonomy );
                if ( $tax_obj && ! current_user_can( $tax_obj->cap->delete_terms ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => sprintf( 'You do not have permission to delete terms in "%s".', $taxonomy ) ];
                }
                if ( $taxonomy === 'category' && $source_id === (int) get_option( 'default_category' ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Cannot merge the default category. Change the default in Settings → Writing first.' ];
                }

                // Find every post assigned to source.
                $post_ids = get_objects_in_term( $source_id, $taxonomy );
                if ( is_wp_error( $post_ids ) ) {
                    return [ 'success' => false, 'taxonomy' => $taxonomy, 'message' => 'Could not look up posts assigned to source: ' . $post_ids->get_error_message() ];
                }
                $post_ids = array_values( array_unique( array_map( 'intval', (array) $post_ids ) ) );

                $reassigned = 0;
                $failures   = 0;

                foreach ( $post_ids as $pid ) {
                    if ( $pid <= 0 ) {
                        continue;
                    }
                    // Append target then remove source. wp_remove_object_terms handles the
                    // unassignment idempotently. Using append-mode avoids stomping other
                    // terms the post already has.
                    $add = wp_set_object_terms( $pid, [ $target_id ], $taxonomy, true );
                    if ( is_wp_error( $add ) ) {
                        $failures++;
                        continue;
                    }
                    $remove = wp_remove_object_terms( $pid, [ $source_id ], $taxonomy );
                    if ( is_wp_error( $remove ) ) {
                        $failures++;
                        continue;
                    }
                    $reassigned++;
                }

                if ( $failures > 0 ) {
                    return [
                        'success'           => false,
                        'taxonomy'          => $taxonomy,
                        'source_term_id'    => $source_id,
                        'target_term_id'    => $target_id,
                        'reassigned_posts'  => $reassigned,
                        'reassign_failures' => $failures,
                        'source_deleted'    => false,
                        'message'           => sprintf( 'Partial merge: %d post(s) reassigned, %d failed. Source term NOT deleted; rerun after investigating.', $reassigned, $failures ),
                    ];
                }

                $delete = wp_delete_term( $source_id, $taxonomy );
                if ( is_wp_error( $delete ) || $delete === false || $delete === 0 ) {
                    $err = is_wp_error( $delete ) ? $delete->get_error_message() : 'unknown';
                    return [
                        'success'           => false,
                        'taxonomy'          => $taxonomy,
                        'source_term_id'    => $source_id,
                        'target_term_id'    => $target_id,
                        'reassigned_posts'  => $reassigned,
                        'reassign_failures' => 0,
                        'source_deleted'    => false,
                        'message'           => sprintf( 'Posts reassigned but source delete failed: %s', $err ),
                    ];
                }

                return [
                    'success'           => true,
                    'taxonomy'          => $taxonomy,
                    'source_term_id'    => $source_id,
                    'target_term_id'    => $target_id,
                    'reassigned_posts'  => $reassigned,
                    'reassign_failures' => 0,
                    'source_deleted'    => true,
                    'message'           => sprintf( 'Merged "%s" into "%s" (%d post(s) reassigned, source deleted).', $source_slug, $target_slug, $reassigned ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_categories' );
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
    }
}
