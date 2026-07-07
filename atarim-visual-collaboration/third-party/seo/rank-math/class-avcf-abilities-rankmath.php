<?php
/**
 * Rank Math SEO — MCP abilities (orchestrator + all abilities).
 *
 * Rank Math stores per-post and per-term SEO as prefixed post/term meta
 * (rank_math_*), so reads/writes go through update_post_meta / update_term_meta
 * directly. Robots is stored as an array of tokens (e.g. ["noindex"]); the
 * abilities expose friendly index/follow labels and translate.
 *
 * Exposed abilities (atarim/rankmath-*):
 *   check-setup, get-post-seo, edit-post-seo, get-term-seo, edit-term-seo,
 *   get-post-schema, edit-post-schema, get-settings
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_RankMath extends AVCF_Abilities_Base {

    /** @var AVCF_RankMath_Detector */
    private $detector;

    /** Friendly field => post meta key. */
    private $post_map = [
        'seo_title'        => 'rank_math_title',
        'meta_description' => 'rank_math_description',
        'focus_keyphrase'  => 'rank_math_focus_keyword',
        'canonical'        => 'rank_math_canonical_url',
        'og_title'         => 'rank_math_facebook_title',
        'og_description'   => 'rank_math_facebook_description',
        'og_image'         => 'rank_math_facebook_image',
        'twitter_title'    => 'rank_math_twitter_title',
        'twitter_description' => 'rank_math_twitter_description',
        'twitter_image'    => 'rank_math_twitter_image',
    ];

    public function __construct() {
        $this->detector = new AVCF_RankMath_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_rankmath_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_post_seo();
        $this->register_term_seo();
        $this->register_post_schema();
        $this->register_settings();
    }

    /* ----------------------------- helpers ----------------------------- */

    private function can_edit_post( $post_id ) {
        return current_user_can( 'edit_post', (int) $post_id ) || current_user_can( 'edit_posts' );
    }
    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ] ];
    }

    /** robots token array => friendly {index, follow}. */
    private function decode_robots( $tokens ) {
        $tokens = is_array( $tokens ) ? $tokens : [];
        $index  = in_array( 'noindex', $tokens, true ) ? 'noindex' : ( in_array( 'index', $tokens, true ) ? 'index' : 'default' );
        $follow = in_array( 'nofollow', $tokens, true ) ? 'nofollow' : 'follow';
        return [ 'index' => $index, 'follow' => $follow ];
    }

    /** Merge friendly {index, follow} into an existing token array. */
    private function encode_robots( $existing, $robots ) {
        $tokens = is_array( $existing ) ? array_values( $existing ) : [];
        $strip  = function( $arr, $vals ) { return array_values( array_diff( $arr, $vals ) ); };
        if ( array_key_exists( 'index', $robots ) ) {
            $tokens = $strip( $tokens, [ 'index', 'noindex' ] );
            if ( $robots['index'] === 'noindex' ) { $tokens[] = 'noindex'; }
            elseif ( $robots['index'] === 'index' ) { $tokens[] = 'index'; }
        }
        if ( array_key_exists( 'follow', $robots ) ) {
            $tokens = $strip( $tokens, [ 'nofollow' ] );
            if ( $robots['follow'] === 'nofollow' ) { $tokens[] = 'nofollow'; }
        }
        return array_values( array_unique( $tokens ) );
    }

    private function read_post_seo( $post_id ) {
        $out = [];
        foreach ( $this->post_map as $friendly => $key ) {
            $out[ $friendly ] = get_post_meta( $post_id, $key, true );
        }
        $out['robots']           = $this->decode_robots( get_post_meta( $post_id, 'rank_math_robots', true ) );
        $out['cornerstone']      = get_post_meta( $post_id, 'rank_math_pillar_content', true ) === 'on';
        $pc                      = get_post_meta( $post_id, 'rank_math_primary_category', true );
        $out['primary_category'] = $pc !== '' ? (int) $pc : null;
        $out['seo_score']        = (int) get_post_meta( $post_id, 'rank_math_seo_score', true ); // read-only
        return $out;
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $detector = $this->detector;
        wp_register_ability( 'atarim/rankmath-check-setup', [
            'label'        => 'Check Rank Math Setup',
            'description'  => 'Call first before other Rank Math abilities. Reports whether Rank Math is active and its version.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $detector ) {
                return [ 'success' => true, 'active' => $detector->avcf_rankmath_is_available(), 'version' => $detector->avcf_rankmath_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- post SEO ------------------------------ */

    private function register_post_seo() {
        $self = $this;

        wp_register_ability( 'atarim/rankmath-get-post-seo', [
            'label'        => 'Get Post SEO (Rank Math)',
            'description'  => 'Read a post\'s Rank Math SEO: seo_title, meta_description, focus_keyphrase, canonical, robots (index/follow), cornerstone, OG/Twitter fields, primary_category (term id or null), and the read-only seo_score.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                return [ 'success' => true, 'post_id' => $post_id, 'seo' => $self->read_post_seo( $post_id ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/rankmath-edit-post-seo', [
            'label'        => 'Edit Post SEO (Rank Math)',
            'description'  => 'Partial update of a post\'s Rank Math SEO — only fields you send change. Fields: seo_title, meta_description, focus_keyphrase, canonical, cornerstone (bool), robots {index: default|index|noindex, follow: follow|nofollow}, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, primary_category (term id, or null to clear). seo_score is read-only.',
            'category'     => 'atarim',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                    'seo_title'        => [ 'type' => 'string' ],
                    'meta_description' => [ 'type' => 'string' ],
                    'focus_keyphrase'  => [ 'type' => 'string' ],
                    'canonical'        => [ 'type' => 'string' ],
                    'cornerstone'      => [ 'type' => 'boolean' ],
                    'robots'           => [ 'type' => 'object', 'properties' => [ 'index' => [ 'type' => 'string', 'enum' => [ 'default', 'index', 'noindex' ] ], 'follow' => [ 'type' => 'string', 'enum' => [ 'follow', 'nofollow' ] ] ] ],
                    'og_title'         => [ 'type' => 'string' ],
                    'og_description'   => [ 'type' => 'string' ],
                    'og_image'         => [ 'type' => 'string' ],
                    'twitter_title'    => [ 'type' => 'string' ],
                    'twitter_description' => [ 'type' => 'string' ],
                    'twitter_image'    => [ 'type' => 'string' ],
                    'primary_category' => [ 'type' => [ 'integer', 'null' ] ],
                ],
                'required' => [ 'post_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit_post( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                foreach ( $self->post_map as $friendly => $key ) {
                    if ( array_key_exists( $friendly, $input ) ) {
                        update_post_meta( $post_id, $key, (string) $input[ $friendly ] );
                    }
                }
                if ( array_key_exists( 'cornerstone', $input ) ) {
                    update_post_meta( $post_id, 'rank_math_pillar_content', ! empty( $input['cornerstone'] ) ? 'on' : 'off' );
                }
                if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
                    $existing = get_post_meta( $post_id, 'rank_math_robots', true );
                    update_post_meta( $post_id, 'rank_math_robots', $self->encode_robots( $existing, $input['robots'] ) );
                }
                if ( array_key_exists( 'primary_category', $input ) ) {
                    if ( $input['primary_category'] === null ) {
                        delete_post_meta( $post_id, 'rank_math_primary_category' );
                    } else {
                        update_post_meta( $post_id, 'rank_math_primary_category', (int) $input['primary_category'] );
                    }
                }
                return [ 'success' => true, 'post_id' => $post_id, 'seo' => $self->read_post_seo( $post_id ), 'message' => 'Updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta(),
        ] );
    }

    /* --------------------------- term SEO ------------------------------ */

    private function register_term_seo() {
        $self = $this;
        $term_map = [
            'seo_title'        => 'rank_math_title',
            'meta_description' => 'rank_math_description',
            'focus_keyphrase'  => 'rank_math_focus_keyword',
            'canonical'        => 'rank_math_canonical_url',
        ];

        wp_register_ability( 'atarim/rankmath-get-term-seo', [
            'label'        => 'Get Term SEO (Rank Math)',
            'description'  => 'Read a term\'s Rank Math SEO: title, description, focus keyphrase, canonical, robots (index/follow).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'term_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'taxonomy' => [ 'type' => 'string' ] ], 'required' => [ 'term_id', 'taxonomy' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $term_map ) {
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
                $term     = ( $term_id && $taxonomy ) ? get_term( $term_id, $taxonomy ) : null;
                if ( ! $term || is_wp_error( $term ) ) { return [ 'success' => false, 'message' => 'Term not found.' ]; }
                $seo = [];
                foreach ( $term_map as $friendly => $key ) {
                    $seo[ $friendly ] = get_term_meta( $term_id, $key, true );
                }
                $seo['robots'] = $self->decode_robots( get_term_meta( $term_id, 'rank_math_robots', true ) );
                return [ 'success' => true, 'seo' => $seo, 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_categories' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/rankmath-edit-term-seo', [
            'label'        => 'Edit Term SEO (Rank Math)',
            'description'  => 'Partial update of a term\'s Rank Math SEO. Fields: seo_title, meta_description, focus_keyphrase, canonical, robots {index, follow}.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'term_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'taxonomy' => [ 'type' => 'string' ],
                'seo_title'        => [ 'type' => 'string' ],
                'meta_description' => [ 'type' => 'string' ],
                'focus_keyphrase'  => [ 'type' => 'string' ],
                'canonical'        => [ 'type' => 'string' ],
                'robots'           => [ 'type' => 'object', 'properties' => [ 'index' => [ 'type' => 'string', 'enum' => [ 'default', 'index', 'noindex' ] ], 'follow' => [ 'type' => 'string', 'enum' => [ 'follow', 'nofollow' ] ] ] ],
            ], 'required' => [ 'term_id', 'taxonomy' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $term_map ) {
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
                $term     = ( $term_id && $taxonomy ) ? get_term( $term_id, $taxonomy ) : null;
                if ( ! $term || is_wp_error( $term ) ) { return [ 'success' => false, 'message' => 'Term not found.' ]; }
                foreach ( $term_map as $friendly => $key ) {
                    if ( array_key_exists( $friendly, $input ) ) {
                        update_term_meta( $term_id, $key, (string) $input[ $friendly ] );
                    }
                }
                if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
                    $existing = get_term_meta( $term_id, 'rank_math_robots', true );
                    update_term_meta( $term_id, 'rank_math_robots', $self->encode_robots( $existing, $input['robots'] ) );
                }
                return [ 'success' => true, 'message' => 'Updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_categories' ); },
            'meta' => $this->write_meta(),
        ] );
    }

    /* --------------------------- post schema --------------------------- */

    private function register_post_schema() {
        wp_register_ability( 'atarim/rankmath-get-post-schema', [
            'label'        => 'Get Post Schema (Rank Math)',
            'description'  => 'Read the Rank Math rich-snippet / schema configuration for a post: the rich_snippet type (article, product, etc., or "off") and any stored schema meta entries.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'schema' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                $rich = get_post_meta( $post_id, 'rank_math_rich_snippet', true );
                $entries = [];
                foreach ( (array) get_post_meta( $post_id ) as $key => $vals ) {
                    if ( strpos( $key, 'rank_math_schema_' ) === 0 ) {
                        $entries[ $key ] = maybe_unserialize( is_array( $vals ) ? reset( $vals ) : $vals );
                    }
                }
                return [ 'success' => true, 'schema' => [ 'rich_snippet' => $rich !== '' ? $rich : 'off', 'entries' => $entries ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/rankmath-edit-post-schema', [
            'label'        => 'Edit Post Schema (Rank Math)',
            'description'  => 'Set the Rank Math rich-snippet type for a post (e.g. "article", "product", "off"). Optionally pass a schema object whose keys/values are stored as a rank_math_schema_<Type> meta entry. This is an advanced ability; read with rankmath-get-post-schema first.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'rich_snippet' => [ 'type' => 'string', 'description' => 'Schema type token, or "off" to disable.' ],
                'schema'       => [ 'type' => 'object', 'description' => 'Optional schema data stored under rank_math_schema_<Type>.' ],
            ], 'required' => [ 'post_id', 'rich_snippet' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! ( current_user_can( 'edit_post', $post_id ) || current_user_can( 'edit_posts' ) ) ) {
                    return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ];
                }
                $type = (string) $input['rich_snippet'];
                update_post_meta( $post_id, 'rank_math_rich_snippet', $type );
                if ( isset( $input['schema'] ) && is_array( $input['schema'] ) && $type !== 'off' && $type !== '' ) {
                    update_post_meta( $post_id, 'rank_math_schema_' . ucfirst( $type ), $input['schema'] );
                }
                return [ 'success' => true, 'message' => sprintf( 'Set rich snippet to "%s".', $type ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta(),
        ] );
    }

    /* --------------------------- settings ------------------------------ */

    private function register_settings() {
        wp_register_ability( 'atarim/rankmath-get-settings', [
            'label'        => 'Get Rank Math Settings',
            'description'  => 'Read selected site-wide Rank Math settings (read-only): active modules, and key title/general option values.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $modules = get_option( 'rank_math_modules', [] );
                $titles  = get_option( 'rank-math-options-titles', [] );
                $general = get_option( 'rank-math-options-general', [] );
                $pick = function( $arr, $keys ) {
                    $o = [];
                    foreach ( $keys as $k ) { if ( isset( $arr[ $k ] ) ) { $o[ $k ] = $arr[ $k ]; } }
                    return $o;
                };
                return [ 'success' => true, 'settings' => [
                    'modules' => array_values( (array) $modules ),
                    'titles'  => $pick( (array) $titles, [ 'title_separator', 'homepage_title', 'homepage_description', 'knowledgegraph_type', 'knowledgegraph_name' ] ),
                    'general' => $pick( (array) $general, [ 'breadcrumbs', 'redirections', 'support_rcp' ] ),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
