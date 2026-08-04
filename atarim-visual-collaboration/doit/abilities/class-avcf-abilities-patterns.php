<?php
/**
 * Block pattern MCP abilities.
 *
 * Block patterns are ready-made arrangements of blocks — hero sections, feature
 * grids, testimonials, calls-to-action, pricing tables, headers, footers — that
 * ship with the active theme and with WordPress core. They are the fastest route
 * from a blank page to a professional-looking layout, but the pattern registry
 * is invisible to the generic content tools.
 *
 * list-patterns surfaces the catalogue (optionally filtered by category or
 * keyword) without the heavy block markup; get-pattern returns one pattern's
 * full markup so it can be dropped into a page/post via create-content or
 * gutenberg-apply-operations, or into a template via update-template.
 *
 * Exposed abilities:
 *   atarim/list-patterns   Registered patterns (name/title/categories), optionally filtered.
 *   atarim/get-pattern     One pattern's full block markup, ready to insert.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Patterns extends AVCF_Abilities_Base {

    public function register() {
        $this->register_list();
        $this->register_get();
    }

    private function register_list() {
        wp_register_ability( 'atarim/list-patterns', [
            'label'               => 'List Block Patterns',
            'description'         => 'Returns registered block patterns — the pre-built section layouts (heroes, feature grids, testimonials, CTAs, headers, footers, etc.) from the active theme and WordPress core. Each item gives name (the id for get-pattern), title, description, categories, keywords, block_types (contexts where the pattern is offered, e.g. core/template-part/header), and viewport_width. Block markup is omitted here to keep the list lean — fetch it with get-pattern. Filter with category (a slug from get-site-editor-overview\'s pattern_categories, e.g. "call-to-action", "testimonials", "header") and/or search (matched against title, description and keywords). The response also lists all available categories.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'category' => [ 'type' => 'string', 'description' => 'Filter to patterns in this category slug.' ],
                    'search'   => [ 'type' => 'string', 'description' => 'Free-text filter over title, description and keywords.' ],
                    'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'total'      => [ 'type' => 'integer' ],
                    'returned'   => [ 'type' => 'integer' ],
                    'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'patterns'   => [ 'type' => 'array' ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
                    return [ 'success' => false, 'message' => 'Block patterns are not supported on this WordPress version.' ];
                }

                $all      = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
                $category = isset( $input['category'] ) ? sanitize_title( (string) $input['category'] ) : '';
                $search   = isset( $input['search'] ) ? mb_strtolower( trim( (string) $input['search'] ) ) : '';
                $limit    = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 100;

                $matched = [];
                foreach ( $all as $pattern ) {
                    if ( $category !== '' && ! in_array( $category, array_map( 'sanitize_title', (array) ( $pattern['categories'] ?? [] ) ), true ) ) {
                        continue;
                    }
                    if ( $search !== '' && ! $this->avcf_pattern_matches_search( $pattern, $search ) ) {
                        continue;
                    }
                    $matched[] = $this->avcf_pattern_summary( $pattern );
                }

                $total    = count( $matched );
                $returned = array_slice( $matched, 0, $limit );

                return [
                    'success'    => true,
                    'total'      => $total,
                    'returned'   => count( $returned ),
                    'categories' => $this->avcf_all_pattern_categories(),
                    'patterns'   => $returned,
                    'message'    => $total > count( $returned )
                        ? sprintf( '%d pattern(s) matched; returning first %d. Narrow with category/search.', $total, count( $returned ) )
                        : sprintf( '%d pattern(s) matched.', $total ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    private function register_get() {
        wp_register_ability( 'atarim/get-pattern', [
            'label'               => 'Get Block Pattern',
            'description'         => 'Returns one registered block pattern in full by its name (from list-patterns, e.g. "twentytwentyfive/hero"). content is ready-to-use Gutenberg block markup: pass it as the content of create-content (content_format:"blocks"), splice it into a page with gutenberg-apply-operations (insert op with markup), or drop it into a header/footer via update-template. Also returns the pattern\'s title, description, categories and viewport_width.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Pattern name/id from list-patterns.' ],
                ],
                'required' => [ 'name' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'pattern' => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
                    return [ 'success' => false, 'message' => 'Block patterns are not supported on this WordPress version.' ];
                }
                $name = isset( $input['name'] ) ? (string) $input['name'] : '';
                if ( $name === '' ) {
                    return [ 'success' => false, 'message' => 'name is required.' ];
                }

                $registry = WP_Block_Patterns_Registry::get_instance();
                if ( ! $registry->is_registered( $name ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'No registered pattern named "%s".', $name ) ];
                }

                $pattern = $registry->get_registered( $name );
                $summary = $this->avcf_pattern_summary( $pattern );
                $summary['content'] = isset( $pattern['content'] ) ? (string) $pattern['content'] : '';

                return [
                    'success' => true,
                    'pattern' => $summary,
                    'message' => sprintf( 'Pattern "%s" returned (%d bytes of block markup).', $name, strlen( $summary['content'] ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    /**
     * Build a lean summary of a pattern (no block markup).
     *
     * @param array $pattern
     * @return array
     */
    private function avcf_pattern_summary( $pattern ) {
        return [
            'name'           => isset( $pattern['name'] ) ? (string) $pattern['name'] : '',
            'title'          => isset( $pattern['title'] ) ? (string) $pattern['title'] : '',
            'description'    => isset( $pattern['description'] ) ? (string) $pattern['description'] : '',
            'categories'     => array_values( array_map( 'strval', (array) ( $pattern['categories'] ?? [] ) ) ),
            'keywords'       => array_values( array_map( 'strval', (array) ( $pattern['keywords'] ?? [] ) ) ),
            'block_types'    => array_values( array_map( 'strval', (array) ( $pattern['blockTypes'] ?? [] ) ) ),
            'viewport_width' => isset( $pattern['viewportWidth'] ) ? (int) $pattern['viewportWidth'] : 0,
        ];
    }

    /**
     * Whether a pattern matches a lowercased free-text search over its title,
     * description and keywords.
     *
     * @param array  $pattern
     * @param string $search
     * @return bool
     */
    private function avcf_pattern_matches_search( $pattern, $search ) {
        $haystack = mb_strtolower(
            (string) ( $pattern['title'] ?? '' ) . ' ' .
            (string) ( $pattern['description'] ?? '' ) . ' ' .
            implode( ' ', (array) ( $pattern['keywords'] ?? [] ) )
        );
        return mb_strpos( $haystack, $search ) !== false;
    }

    /**
     * All registered pattern category slugs.
     *
     * @return array<int,string>
     */
    private function avcf_all_pattern_categories() {
        if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
            return [];
        }
        $cats = [];
        foreach ( WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered() as $cat ) {
            $cats[] = (string) $cat['name'];
        }
        sort( $cats );
        return $cats;
    }
}
