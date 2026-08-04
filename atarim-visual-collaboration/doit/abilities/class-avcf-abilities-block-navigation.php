<?php
/**
 * Block-theme navigation (wp_navigation) MCP abilities.
 *
 * Block themes do not use classic menus for primary navigation — they use the
 * Navigation block, whose menus are stored as wp_navigation posts (block markup
 * of wp:navigation-link / wp:navigation-submenu / wp:page-list). The classic
 * menu abilities in the navigation category deliberately leave these out of
 * scope; this category covers them, closing the gap for the common modern case
 * where a block theme's header nav is a wp_navigation post.
 *
 * The generic content tools cannot reach wp_navigation because it is not a
 * public post type. These abilities list, read and replace the block markup of
 * a navigation menu; the markup is standard Gutenberg markup, so it can also be
 * edited structurally with the gutenberg-* abilities once its post id is known.
 *
 * Exposed abilities:
 *   atarim/list-navigation    All wp_navigation menus.
 *   atarim/get-navigation     One menu's block markup + block summary.
 *   atarim/update-navigation  Create or replace a navigation menu's block markup.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Block_Navigation extends AVCF_Abilities_Base {

    public function register() {
        $this->register_list();
        $this->register_get();
        $this->register_update();
    }

    private function register_list() {
        wp_register_ability( 'atarim/list-navigation', [
            'label'               => 'List Navigation Menus',
            'description'         => 'Returns the block-theme navigation menus (wp_navigation posts) used by the Navigation block in block themes — the header/footer menus edited in the Site Editor. Each item gives id, title, slug and status. Use get-navigation to read one\'s block markup and update-navigation to change it. (For CLASSIC themes that register nav_menu locations, use list-menus instead.)',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'total'   => [ 'type' => 'integer' ],
                    'menus'   => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $posts = get_posts( [
                    'post_type'        => 'wp_navigation',
                    'post_status'      => [ 'publish', 'draft' ],
                    'numberposts'      => -1,
                    'orderby'          => 'title',
                    'order'            => 'ASC',
                    'suppress_filters' => false,
                ] );

                $menus = [];
                foreach ( $posts as $post ) {
                    $menus[] = [
                        'id'     => (int) $post->ID,
                        'title'  => (string) $post->post_title,
                        'slug'   => (string) $post->post_name,
                        'status' => (string) $post->post_status,
                    ];
                }

                return [
                    'success' => true,
                    'total'   => count( $menus ),
                    'menus'   => $menus,
                    'message' => sprintf( '%d block navigation menu(s).', count( $menus ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    private function register_get() {
        wp_register_ability( 'atarim/get-navigation', [
            'label'               => 'Get Navigation Menu',
            'description'         => 'Returns one block navigation menu (wp_navigation post) by id, including its raw Gutenberg block markup (content) and a top-level block summary. The markup is composed of blocks like wp:navigation-link (a single link, attrs label/url/kind/id), wp:navigation-submenu (a link with children) and wp:page-list (auto-lists pages). Read this before update-navigation.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'wp_navigation post id from list-navigation.' ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'menu'    => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
                $post = $id > 0 ? get_post( $id ) : null;
                if ( ! $post || $post->post_type !== 'wp_navigation' ) {
                    return [ 'success' => false, 'message' => sprintf( 'No navigation menu with id %d.', $id ) ];
                }

                $content = (string) $post->post_content;
                $summary = [];
                if ( function_exists( 'parse_blocks' ) ) {
                    foreach ( parse_blocks( $content ) as $block ) {
                        if ( ! empty( $block['blockName'] ) ) {
                            $summary[] = (string) $block['blockName'];
                        }
                    }
                }

                return [
                    'success' => true,
                    'menu'    => [
                        'id'            => (int) $post->ID,
                        'title'         => (string) $post->post_title,
                        'slug'          => (string) $post->post_name,
                        'status'        => (string) $post->post_status,
                        'content'       => $content,
                        'block_summary' => $summary,
                    ],
                    'message' => sprintf( 'Navigation "%s" has %d top-level block(s).', $post->post_title, count( $summary ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    private function register_update() {
        wp_register_ability( 'atarim/update-navigation', [
            'label'               => 'Update Navigation Menu',
            'description'         => 'Creates or replaces a block navigation menu (wp_navigation post). Pass id to REPLACE an existing menu\'s block markup, or omit id and pass title to create a new one. content is the full Gutenberg navigation block markup — a sequence of wp:navigation-link / wp:navigation-submenu / wp:page-list blocks, e.g. \'<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->\'. content REPLACES the previous markup. To change which menu the header shows, edit the header template part (update-template) so its wp:navigation block references the intended ref id. Malformed markup can break the menu — validate structure first.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Existing menu id to replace. Omit to create.' ],
                    'title'   => [ 'type' => 'string', 'description' => 'Menu title. Required when creating.' ],
                    'content' => [ 'type' => 'string', 'description' => 'Full replacement navigation block markup.' ],
                    'status'  => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ], 'default' => 'publish' ],
                ],
                'required' => [ 'content' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'created' => [ 'type' => 'boolean' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) || $input['content'] === '' ) {
                    return [ 'success' => false, 'message' => 'content is required.' ];
                }
                $content = (string) $input['content'];
                $status  = isset( $input['status'] ) && $input['status'] === 'draft' ? 'draft' : 'publish';
                $id      = isset( $input['id'] ) ? (int) $input['id'] : 0;

                if ( $id > 0 ) {
                    $existing = get_post( $id );
                    if ( ! $existing || $existing->post_type !== 'wp_navigation' ) {
                        return [ 'success' => false, 'message' => sprintf( 'No navigation menu with id %d.', $id ) ];
                    }
                    $postarr = [ 'ID' => $id, 'post_content' => $content, 'post_status' => $status ];
                    if ( isset( $input['title'] ) && $input['title'] !== '' ) {
                        $postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
                    }
                    $result = wp_update_post( $postarr, true );
                    return $this->avcf_nav_result( $result, $id, false );
                }

                $title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
                if ( $title === '' ) {
                    return [ 'success' => false, 'message' => 'title is required when creating a navigation menu.' ];
                }
                $result = wp_insert_post( [
                    'post_type'    => 'wp_navigation',
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => $status,
                ], true );
                return $this->avcf_nav_result( $result, 0, true );
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }

    /**
     * Shape a create/update result into the ability response.
     *
     * @param int|WP_Error $result
     * @param int          $id       Existing id (0 when creating).
     * @param bool         $created
     * @return array
     */
    private function avcf_nav_result( $result, $id, $created ) {
        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => 'Save failed: ' . $result->get_error_message() ];
        }
        $final_id = $created ? (int) $result : $id;
        return [
            'success' => true,
            'id'      => $final_id,
            'created' => $created,
            'message' => sprintf( 'Navigation menu %d %s.', $final_id, $created ? 'created' : 'updated' ),
        ];
    }
}
