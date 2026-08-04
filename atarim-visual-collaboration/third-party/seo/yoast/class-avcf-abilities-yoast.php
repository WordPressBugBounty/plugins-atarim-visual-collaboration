<?php
/**
 * Yoast SEO — MCP abilities (orchestrator + all abilities).
 *
 * Reads and writes per-post and per-term SEO through Yoast's own helper
 * classes (WPSEO_Meta, WPSEO_Taxonomy_Meta) so Yoast's storage conventions,
 * defaults, and indexable rebuilds run as expected. Redirects use the
 * Premium-only WPSEO_Redirect_Manager and self-gate on Premium presence.
 *
 * Friendly robots labels: index = default|index|noindex (default = follow the
 * post-type setting); follow = follow|nofollow. These map to Yoast's internal
 * meta-robots-noindex codes (default 0 / noindex 1 / index 2).
 *
 * Exposed abilities (atarim/yoast-*):
 *   check-setup, get-post-seo, edit-post-seo, get-term-seo, edit-term-seo,
 *   get-settings, list-redirects, create-redirect, edit-redirect, delete-redirect
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Yoast extends AVCF_Abilities_Base {

    /** @var AVCF_Yoast_Detector */
    private $detector;

    /** Friendly field => WPSEO_Meta key. */
    private $post_map = [
        'seo_title'        => 'title',
        'meta_description' => 'metadesc',
        'focus_keyphrase'  => 'focuskw',
        'canonical'        => 'canonical',
        'breadcrumb_title' => 'bctitle',
        'og_title'         => 'opengraph-title',
        'og_description'   => 'opengraph-description',
        'og_image'         => 'opengraph-image',
        'twitter_title'    => 'twitter-title',
        'twitter_description' => 'twitter-description',
        'twitter_image'    => 'twitter-image',
    ];

    public function __construct() {
        $this->detector = new AVCF_Yoast_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_yoast_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_post_seo();
        $this->register_term_seo();
        $this->register_settings();
        $this->register_redirects();
    }

    /* ----------------------------- helpers ----------------------------- */

    private function can_edit_post( $post_id ) {
        return current_user_can( 'edit_post', (int) $post_id ) || current_user_can( 'edit_posts' );
    }

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }

    /** default|index|noindex => Yoast meta-robots-noindex code. */
    private function index_to_code( $friendly ) {
        switch ( $friendly ) {
            case 'noindex': return '1';
            case 'index':   return '2';
            default:        return '0';
        }
    }
    private function code_to_index( $code ) {
        switch ( (string) $code ) {
            case '1': return 'noindex';
            case '2': return 'index';
            default:  return 'default';
        }
    }

    private function read_post_seo( $post_id ) {
        $out = [];
        foreach ( $this->post_map as $friendly => $key ) {
            $out[ $friendly ] = WPSEO_Meta::get_value( $key, $post_id );
        }
        $out['cornerstone'] = WPSEO_Meta::get_value( 'is_cornerstone', $post_id ) === '1';
        $out['robots'] = [
            'index'  => $this->code_to_index( WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id ) ),
            'follow' => WPSEO_Meta::get_value( 'meta-robots-nofollow', $post_id ) === '1' ? 'nofollow' : 'follow',
        ];
        $primary = get_post_meta( $post_id, '_yoast_wpseo_primary_category', true );
        $out['primary_category'] = $primary !== '' ? (int) $primary : null;
        return $out;
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $detector = $this->detector;
        wp_register_ability( 'atarim/yoast-check-setup', [
            'label'        => 'Check Yoast Setup',
            'description'  => 'Call first before other Yoast abilities. Reports whether Yoast SEO is active, its version, and whether Yoast Premium is present (Premium is required for the redirect abilities).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'premium' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $detector ) {
                return [
                    'success' => true,
                    'active'  => $detector->avcf_yoast_is_available(),
                    'version' => $detector->avcf_yoast_version(),
                    'premium' => $detector->avcf_yoast_is_premium(),
                    'message' => 'OK.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- post SEO ------------------------------ */

    private function register_post_seo() {
        $self = $this;

        wp_register_ability( 'atarim/yoast-get-post-seo', [
            'label'        => 'Get Post SEO (Yoast)',
            'description'  => 'Read a post or page\'s Yoast SEO: seo_title, meta_description, focus_keyphrase, canonical, breadcrumb_title, cornerstone, robots (index: default|index|noindex, follow: follow|nofollow), OG/Twitter social fields, and primary_category (term id or null). A fresh post returns Yoast defaults, not missing fields.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'description' => 'The post or page ID.', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) {
                    return [ 'success' => false, 'message' => 'A valid post_id is required.' ];
                }
                return [ 'success' => true, 'post_id' => $post_id, 'seo' => $self->read_post_seo( $post_id ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/yoast-edit-post-seo', [
            'label'        => 'Edit Post SEO (Yoast)',
            'description'  => 'Partial update of a post\'s Yoast SEO — only the fields you send change. Fields: seo_title, meta_description, focus_keyphrase, canonical, breadcrumb_title, cornerstone (bool), robots {index: default|index|noindex, follow: follow|nofollow}, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, primary_category (term id, or null to clear). Read first with yoast-get-post-seo when unsure.',
            'category'     => 'atarim',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                    'seo_title'        => [ 'type' => 'string' ],
                    'meta_description' => [ 'type' => 'string' ],
                    'focus_keyphrase'  => [ 'type' => 'string' ],
                    'canonical'        => [ 'type' => 'string' ],
                    'breadcrumb_title' => [ 'type' => 'string' ],
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
                if ( $post_id <= 0 || ! get_post( $post_id ) ) {
                    return [ 'success' => false, 'message' => 'A valid post_id is required.' ];
                }
                if ( ! $self->can_edit_post( $post_id ) ) {
                    return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ];
                }
                foreach ( $self->post_map as $friendly => $key ) {
                    if ( array_key_exists( $friendly, $input ) ) {
                        WPSEO_Meta::set_value( $key, (string) $input[ $friendly ], $post_id );
                    }
                }
                if ( array_key_exists( 'cornerstone', $input ) ) {
                    WPSEO_Meta::set_value( 'is_cornerstone', ! empty( $input['cornerstone'] ) ? '1' : '0', $post_id );
                }
                if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
                    if ( array_key_exists( 'index', $input['robots'] ) ) {
                        WPSEO_Meta::set_value( 'meta-robots-noindex', $self->index_to_code( (string) $input['robots']['index'] ), $post_id );
                    }
                    if ( array_key_exists( 'follow', $input['robots'] ) ) {
                        WPSEO_Meta::set_value( 'meta-robots-nofollow', $input['robots']['follow'] === 'nofollow' ? '1' : '0', $post_id );
                    }
                }
                if ( array_key_exists( 'primary_category', $input ) ) {
                    if ( $input['primary_category'] === null ) {
                        delete_post_meta( $post_id, '_yoast_wpseo_primary_category' );
                    } else {
                        update_post_meta( $post_id, '_yoast_wpseo_primary_category', (int) $input['primary_category'] );
                    }
                }
                return [ 'success' => true, 'post_id' => $post_id, 'seo' => $self->read_post_seo( $post_id ), 'message' => 'Updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- term SEO ------------------------------ */

    private function register_term_seo() {
        $self = $this;

        wp_register_ability( 'atarim/yoast-get-term-seo', [
            'label'        => 'Get Term SEO (Yoast)',
            'description'  => 'Read a taxonomy term\'s Yoast SEO: title, description, focus keyphrase, canonical, and noindex.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'term_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'taxonomy' => [ 'type' => 'string' ] ], 'required' => [ 'term_id', 'taxonomy' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
                $term     = ( $term_id && $taxonomy ) ? get_term( $term_id, $taxonomy ) : null;
                if ( ! $term || is_wp_error( $term ) ) {
                    return [ 'success' => false, 'message' => 'Term not found for the given term_id and taxonomy.' ];
                }
                $get = function( $key ) use ( $term_id, $taxonomy ) {
                    return WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, $key );
                };
                return [ 'success' => true, 'seo' => [
                    'seo_title'        => $get( 'title' ),
                    'meta_description' => $get( 'desc' ),
                    'focus_keyphrase'  => $get( 'focuskw' ),
                    'canonical'        => $get( 'canonical' ),
                    'noindex'          => $get( 'noindex' ),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_categories' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/yoast-edit-term-seo', [
            'label'        => 'Edit Term SEO (Yoast)',
            'description'  => 'Partial update of a term\'s Yoast SEO. Fields: seo_title, meta_description, focus_keyphrase, canonical, noindex (default|index|noindex). Only the fields you send change.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'term_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'taxonomy' => [ 'type' => 'string' ],
                'seo_title'        => [ 'type' => 'string' ],
                'meta_description' => [ 'type' => 'string' ],
                'focus_keyphrase'  => [ 'type' => 'string' ],
                'canonical'        => [ 'type' => 'string' ],
                'noindex'          => [ 'type' => 'string', 'enum' => [ 'default', 'index', 'noindex' ] ],
            ], 'required' => [ 'term_id', 'taxonomy' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                $taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
                $term     = ( $term_id && $taxonomy ) ? get_term( $term_id, $taxonomy ) : null;
                if ( ! $term || is_wp_error( $term ) ) {
                    return [ 'success' => false, 'message' => 'Term not found for the given term_id and taxonomy.' ];
                }
                $payload = [];
                $map = [ 'seo_title' => 'wpseo_title', 'meta_description' => 'wpseo_desc', 'focus_keyphrase' => 'wpseo_focuskw', 'canonical' => 'wpseo_canonical' ];
                foreach ( $map as $friendly => $key ) {
                    if ( array_key_exists( $friendly, $input ) ) {
                        $payload[ $key ] = (string) $input[ $friendly ];
                    }
                }
                if ( array_key_exists( 'noindex', $input ) ) {
                    // Yoast term noindex stores 'noindex' | 'index' | 'default'.
                    $payload['wpseo_noindex'] = in_array( $input['noindex'], [ 'noindex', 'index', 'default' ], true ) ? $input['noindex'] : 'default';
                }
                if ( empty( $payload ) ) {
                    return [ 'success' => true, 'message' => 'Nothing to update.' ];
                }
                WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $payload );
                return [ 'success' => true, 'message' => 'Updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_categories' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- settings ------------------------------ */

    private function register_settings() {
        wp_register_ability( 'atarim/yoast-get-settings', [
            'label'        => 'Get Yoast Settings',
            'description'  => 'Read selected site-wide Yoast settings (read-only): title separator, homepage title/description templates, knowledge-graph company/person type and name, and the configured social profile URLs.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $titles = get_option( 'wpseo_titles', [] );
                $social = get_option( 'wpseo_social', [] );
                $main   = get_option( 'wpseo', [] );
                $pick = function( $arr, $keys ) {
                    $o = [];
                    foreach ( $keys as $k ) { $o[ $k ] = isset( $arr[ $k ] ) ? $arr[ $k ] : null; }
                    return $o;
                };
                return [ 'success' => true, 'settings' => [
                    'titles' => $pick( (array) $titles, [ 'separator', 'title-home-wpseo', 'metadesc-home-wpseo', 'company_or_person', 'company_name', 'person_name' ] ),
                    'social' => $pick( (array) $social, [ 'facebook_site', 'twitter_site', 'instagram_url', 'linkedin_url', 'youtube_url', 'other_social_urls' ] ),
                    'site'   => $pick( (array) $main, [ 'website_name', 'company_or_person' ] ),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- redirects ----------------------------- */

    private function register_redirects() {
        $self     = $this;
        $detector = $this->detector;

        $premium_guard = function() use ( $detector ) {
            if ( ! $detector->avcf_yoast_is_premium() ) {
                return [ 'success' => false, 'message' => 'Yoast SEO Premium is required for redirect management; it is not active on this site.' ];
            }
            return null;
        };

        wp_register_ability( 'atarim/yoast-list-redirects', [
            'label'        => 'List Redirects (Yoast Premium)',
            'description'  => 'List all Yoast redirects (origin, target, type). Requires Yoast SEO Premium.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'total' => [ 'type' => 'integer' ], 'redirects' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $premium_guard ) {
                $g = $premium_guard(); if ( $g ) { return $g; }
                try {
                    $manager   = new WPSEO_Redirect_Manager();
                    $redirects = $manager->get_all_redirects();
                    $out = [];
                    foreach ( (array) $redirects as $r ) {
                        if ( is_object( $r ) && method_exists( $r, 'get_origin' ) ) {
                            $out[] = [ 'origin' => $r->get_origin(), 'target' => $r->get_target(), 'type' => (int) $r->get_type() ];
                        } elseif ( is_array( $r ) ) {
                            $out[] = [ 'origin' => $r['origin'] ?? '', 'target' => $r['url'] ?? ( $r['target'] ?? '' ), 'type' => (int) ( $r['type'] ?? 301 ) ];
                        }
                    }
                    return [ 'success' => true, 'total' => count( $out ), 'redirects' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read redirects: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/yoast-create-redirect', [
            'label'        => 'Create Redirect (Yoast Premium)',
            'description'  => 'Create a Yoast redirect from an origin path to a target. type is the HTTP code (301, 302, 307, 410, 451). Requires Yoast SEO Premium.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'origin' => [ 'type' => 'string', 'description' => 'Source path (e.g. "/old-page/").' ],
                'target' => [ 'type' => 'string', 'description' => 'Destination URL or path. Optional for 410/451.' ],
                'type'   => [ 'type' => 'integer', 'enum' => [ 301, 302, 307, 410, 451 ], 'default' => 301 ],
            ], 'required' => [ 'origin' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $premium_guard ) {
                $g = $premium_guard(); if ( $g ) { return $g; }
                $origin = isset( $input['origin'] ) ? (string) $input['origin'] : '';
                if ( $origin === '' ) { return [ 'success' => false, 'message' => 'origin is required.' ]; }
                $target = isset( $input['target'] ) ? (string) $input['target'] : '';
                $type   = isset( $input['type'] ) ? (int) $input['type'] : 301;
                try {
                    $redirect = new WPSEO_Redirect( $origin, $target, $type, defined( 'WPSEO_Redirect::FORMAT_PLAIN' ) ? WPSEO_Redirect::FORMAT_PLAIN : 'plain' );
                    $created  = ( new WPSEO_Redirect_Manager() )->create_redirect( $redirect );
                    if ( ! $created ) {
                        return [ 'success' => false, 'message' => 'Yoast did not confirm the redirect was created (it may already exist).' ];
                    }
                    return [ 'success' => true, 'message' => sprintf( 'Created %d redirect from %s.', $type, $origin ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/yoast-edit-redirect', [
            'label'        => 'Edit Redirect (Yoast Premium)',
            'description'  => 'Update an existing Yoast redirect identified by its current origin. Supply the new target and/or type. Requires Yoast SEO Premium.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'origin' => [ 'type' => 'string', 'description' => 'Current origin path identifying the redirect to edit.' ],
                'target' => [ 'type' => 'string' ],
                'type'   => [ 'type' => 'integer', 'enum' => [ 301, 302, 307, 410, 451 ] ],
            ], 'required' => [ 'origin' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $premium_guard ) {
                $g = $premium_guard(); if ( $g ) { return $g; }
                $origin = isset( $input['origin'] ) ? (string) $input['origin'] : '';
                if ( $origin === '' ) { return [ 'success' => false, 'message' => 'origin is required.' ]; }
                try {
                    $manager = new WPSEO_Redirect_Manager();
                    $current = $manager->get_redirect( $origin );
                    if ( ! $current ) {
                        return [ 'success' => false, 'message' => sprintf( 'No redirect found with origin "%s".', $origin ) ];
                    }
                    $target = isset( $input['target'] ) ? (string) $input['target'] : $current->get_target();
                    $type   = isset( $input['type'] ) ? (int) $input['type'] : (int) $current->get_type();
                    $new    = new WPSEO_Redirect( $origin, $target, $type, $current->get_format() );
                    $manager->update_redirect( $current, $new );
                    return [ 'success' => true, 'message' => sprintf( 'Updated redirect %s.', $origin ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Edit failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/yoast-delete-redirect', [
            'label'        => 'Delete Redirect (Yoast Premium)',
            'description'  => 'Delete a Yoast redirect by its origin. Dry run unless confirm:true. Requires Yoast SEO Premium.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'origin'  => [ 'type' => 'string' ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'origin' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $premium_guard ) {
                $g = $premium_guard(); if ( $g ) { return $g; }
                $origin = isset( $input['origin'] ) ? (string) $input['origin'] : '';
                if ( $origin === '' ) { return [ 'success' => false, 'message' => 'origin is required.' ]; }
                try {
                    $manager = new WPSEO_Redirect_Manager();
                    $current = $manager->get_redirect( $origin );
                    if ( ! $current ) {
                        return [ 'success' => false, 'message' => sprintf( 'No redirect found with origin "%s".', $origin ) ];
                    }
                    if ( empty( $input['confirm'] ) ) {
                        return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete redirect "%s". Re-call with confirm:true.', $origin ) ];
                    }
                    $manager->delete_redirects( [ $current ] );
                    return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Deleted redirect %s.', $origin ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Delete failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
