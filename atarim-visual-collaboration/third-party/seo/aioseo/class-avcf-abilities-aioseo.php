<?php
/**
 * All in One SEO (AIOSEO) — MCP abilities (orchestrator + all abilities).
 *
 * AIOSEO stores SEO data in its own tables, accessed via its ORM models
 * (\AIOSEO\Plugin\Common\Models\Post / Term). Per-post/term reads and writes
 * go through getPost()/getTerm() + ->save(). Robots is exposed via friendly
 * index/follow labels; AIOSEO's robots_default flag means "use site defaults".
 * Redirects are AIOSEO Pro only and self-gate on the Pro redirect model.
 *
 * Because the ORM surface varies across AIOSEO versions, every callback is
 * wrapped defensively and returns a structured message on failure rather than
 * fataling. Redirects in particular should be validated first on a live site.
 *
 * Exposed abilities (atarim/aioseo-*):
 *   check-setup, get-post-seo, edit-post-seo, get-term-seo, edit-term-seo,
 *   get-post-schema, edit-post-schema, get-settings,
 *   list-redirects, create-redirect, edit-redirect, delete-redirect
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_AIOSEO extends AVCF_Abilities_Base {

    /** @var AVCF_AIOSEO_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_AIOSEO_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_aioseo_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_post_seo();
        $this->register_term_seo();
        $this->register_post_schema();
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

    /** Read the focus keyphrase out of AIOSEO's keyphrases JSON. */
    private function read_focus( $keyphrases ) {
        if ( is_string( $keyphrases ) ) {
            $keyphrases = json_decode( $keyphrases, true );
        }
        if ( is_array( $keyphrases ) && isset( $keyphrases['focus']['keyphrase'] ) ) {
            return (string) $keyphrases['focus']['keyphrase'];
        }
        if ( is_object( $keyphrases ) && isset( $keyphrases->focus->keyphrase ) ) {
            return (string) $keyphrases->focus->keyphrase;
        }
        return '';
    }

    private function build_keyphrases( $kw ) {
        return wp_json_encode( [ 'focus' => [ 'keyphrase' => (string) $kw, 'score' => 0, 'analysis' => [] ], 'additional' => [] ] );
    }

    private function read_post_model( $model ) {
        $g = function( $prop ) use ( $model ) { return isset( $model->$prop ) ? $model->$prop : null; };
        $robots_default = (bool) $g( 'robots_default' );
        return [
            'seo_title'        => (string) $g( 'title' ),
            'meta_description' => (string) $g( 'description' ),
            'canonical'        => (string) $g( 'canonical_url' ),
            'focus_keyphrase'  => $this->read_focus( $g( 'keyphrases' ) ),
            'robots'           => [
                'index'  => $robots_default ? 'default' : ( $g( 'robots_noindex' ) ? 'noindex' : 'index' ),
                'follow' => $g( 'robots_nofollow' ) ? 'nofollow' : 'follow',
            ],
            'og_title'         => (string) $g( 'og_title' ),
            'og_description'   => (string) $g( 'og_description' ),
            'og_image'         => (string) $g( 'og_image_custom_url' ),
            'twitter_title'    => (string) $g( 'twitter_title' ),
            'twitter_description' => (string) $g( 'twitter_description' ),
            'twitter_image'    => (string) $g( 'twitter_image_custom_url' ),
        ];
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $detector = $this->detector;
        wp_register_ability( 'atarim/aioseo-check-setup', [
            'label'        => 'Check AIOSEO Setup',
            'description'  => 'Call first before other AIOSEO abilities. Reports whether AIOSEO is active, its version, and whether the Pro Redirects feature is available (required for the redirect abilities).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'redirects_available' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $detector ) {
                return [
                    'success'             => true,
                    'active'              => $detector->avcf_aioseo_is_available(),
                    'version'             => $detector->avcf_aioseo_version(),
                    'redirects_available' => $detector->avcf_aioseo_has_redirects(),
                    'message'             => 'OK.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- post SEO ------------------------------ */

    private function register_post_seo() {
        $self = $this;

        wp_register_ability( 'atarim/aioseo-get-post-seo', [
            'label'        => 'Get Post SEO (AIOSEO)',
            'description'  => 'Read a post\'s AIOSEO settings: seo_title, meta_description, canonical, focus_keyphrase, robots (index: default|index|noindex, follow: follow|nofollow), and OG/Twitter fields.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                try {
                    $model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                    return [ 'success' => true, 'post_id' => $post_id, 'seo' => $self->read_post_model( $model ), 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read AIOSEO data: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/aioseo-edit-post-seo', [
            'label'        => 'Edit Post SEO (AIOSEO)',
            'description'  => 'Partial update of a post\'s AIOSEO settings — only fields you send change. Fields: seo_title, meta_description, canonical, focus_keyphrase, robots {index: default|index|noindex, follow: follow|nofollow}, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image. Read first with aioseo-get-post-seo.',
            'category'     => 'atarim',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                    'seo_title'        => [ 'type' => 'string' ],
                    'meta_description' => [ 'type' => 'string' ],
                    'canonical'        => [ 'type' => 'string' ],
                    'focus_keyphrase'  => [ 'type' => 'string' ],
                    'robots'           => [ 'type' => 'object', 'properties' => [ 'index' => [ 'type' => 'string', 'enum' => [ 'default', 'index', 'noindex' ] ], 'follow' => [ 'type' => 'string', 'enum' => [ 'follow', 'nofollow' ] ] ] ],
                    'og_title'         => [ 'type' => 'string' ],
                    'og_description'   => [ 'type' => 'string' ],
                    'og_image'         => [ 'type' => 'string' ],
                    'twitter_title'    => [ 'type' => 'string' ],
                    'twitter_description' => [ 'type' => 'string' ],
                    'twitter_image'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'post_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit_post( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                try {
                    $model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                    $direct = [ 'seo_title' => 'title', 'meta_description' => 'description', 'canonical' => 'canonical_url', 'og_title' => 'og_title', 'og_description' => 'og_description', 'og_image' => 'og_image_custom_url', 'twitter_title' => 'twitter_title', 'twitter_description' => 'twitter_description', 'twitter_image' => 'twitter_image_custom_url' ];
                    foreach ( $direct as $friendly => $prop ) {
                        if ( array_key_exists( $friendly, $input ) ) {
                            $model->$prop = (string) $input[ $friendly ];
                        }
                    }
                    if ( array_key_exists( 'focus_keyphrase', $input ) ) {
                        $model->keyphrases = $self->build_keyphrases( $input['focus_keyphrase'] );
                    }
                    if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
                        if ( array_key_exists( 'index', $input['robots'] ) ) {
                            if ( $input['robots']['index'] === 'default' ) {
                                $model->robots_default = true;
                            } else {
                                $model->robots_default = false;
                                $model->robots_noindex = ( $input['robots']['index'] === 'noindex' );
                            }
                        }
                        if ( array_key_exists( 'follow', $input['robots'] ) ) {
                            $model->robots_default = false;
                            $model->robots_nofollow = ( $input['robots']['follow'] === 'nofollow' );
                        }
                    }
                    $model->save();
                    $fresh = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                    return [ 'success' => true, 'post_id' => $post_id, 'seo' => $self->read_post_model( $fresh ), 'message' => 'Updated.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- term SEO ------------------------------ */

    private function register_term_seo() {
        $self = $this;

        wp_register_ability( 'atarim/aioseo-get-term-seo', [
            'label'        => 'Get Term SEO (AIOSEO)',
            'description'  => 'Read a term\'s AIOSEO settings: seo_title, meta_description, canonical, robots (index/follow). Requires an AIOSEO version with term support.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'term_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'term_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'seo' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                if ( $term_id <= 0 ) { return [ 'success' => false, 'message' => 'A valid term_id is required.' ]; }
                if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Term' ) ) {
                    return [ 'success' => false, 'message' => 'This AIOSEO version does not expose term SEO via the Term model.' ];
                }
                try {
                    $model = \AIOSEO\Plugin\Common\Models\Term::getTerm( $term_id );
                    $g = function( $p ) use ( $model ) { return isset( $model->$p ) ? $model->$p : null; };
                    $robots_default = (bool) $g( 'robots_default' );
                    return [ 'success' => true, 'seo' => [
                        'seo_title'        => (string) $g( 'title' ),
                        'meta_description' => (string) $g( 'description' ),
                        'canonical'        => (string) $g( 'canonical_url' ),
                        'robots'           => [ 'index' => $robots_default ? 'default' : ( $g( 'robots_noindex' ) ? 'noindex' : 'index' ), 'follow' => $g( 'robots_nofollow' ) ? 'nofollow' : 'follow' ],
                    ], 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read AIOSEO term data: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_categories' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/aioseo-edit-term-seo', [
            'label'        => 'Edit Term SEO (AIOSEO)',
            'description'  => 'Partial update of a term\'s AIOSEO settings. Fields: seo_title, meta_description, canonical, robots {index, follow}. Requires an AIOSEO version with term support.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'term_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                'seo_title'        => [ 'type' => 'string' ],
                'meta_description' => [ 'type' => 'string' ],
                'canonical'        => [ 'type' => 'string' ],
                'robots'           => [ 'type' => 'object', 'properties' => [ 'index' => [ 'type' => 'string', 'enum' => [ 'default', 'index', 'noindex' ] ], 'follow' => [ 'type' => 'string', 'enum' => [ 'follow', 'nofollow' ] ] ] ],
            ], 'required' => [ 'term_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $term_id = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
                if ( $term_id <= 0 ) { return [ 'success' => false, 'message' => 'A valid term_id is required.' ]; }
                if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Term' ) ) {
                    return [ 'success' => false, 'message' => 'This AIOSEO version does not expose term SEO via the Term model.' ];
                }
                if ( ! current_user_can( 'manage_categories' ) ) {
                    return [ 'success' => false, 'message' => 'You do not have permission to edit terms.' ];
                }
                try {
                    $model = \AIOSEO\Plugin\Common\Models\Term::getTerm( $term_id );
                    $direct = [ 'seo_title' => 'title', 'meta_description' => 'description', 'canonical' => 'canonical_url' ];
                    foreach ( $direct as $friendly => $prop ) {
                        if ( array_key_exists( $friendly, $input ) ) { $model->$prop = (string) $input[ $friendly ]; }
                    }
                    if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
                        if ( array_key_exists( 'index', $input['robots'] ) ) {
                            if ( $input['robots']['index'] === 'default' ) { $model->robots_default = true; }
                            else { $model->robots_default = false; $model->robots_noindex = ( $input['robots']['index'] === 'noindex' ); }
                        }
                        if ( array_key_exists( 'follow', $input['robots'] ) ) {
                            $model->robots_default = false;
                            $model->robots_nofollow = ( $input['robots']['follow'] === 'nofollow' );
                        }
                    }
                    $model->save();
                    return [ 'success' => true, 'message' => 'Updated.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_categories' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- post schema --------------------------- */

    private function register_post_schema() {
        wp_register_ability( 'atarim/aioseo-get-post-schema', [
            'label'        => 'Get Post Schema (AIOSEO)',
            'description'  => 'Read the AIOSEO schema graph configuration for a post (decoded from the model\'s schema JSON). Returns an empty object if none is set or the AIOSEO version predates schema support.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'schema' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                try {
                    $model  = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                    $schema = isset( $model->schema ) ? $model->schema : null;
                    if ( is_string( $schema ) ) { $schema = json_decode( $schema, true ); }
                    return [ 'success' => true, 'schema' => is_array( $schema ) ? $schema : [], 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read schema: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/aioseo-edit-post-schema', [
            'label'        => 'Edit Post Schema (AIOSEO)',
            'description'  => 'Replace the AIOSEO schema graph for a post with the supplied schema object (stored as the model\'s schema JSON). Advanced — read with aioseo-get-post-schema first and pass the modified object back whole.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'schema'  => [ 'type' => 'object', 'description' => 'The full schema graph object to store.' ],
            ], 'required' => [ 'post_id', 'schema' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! ( current_user_can( 'edit_post', $post_id ) || current_user_can( 'edit_posts' ) ) ) {
                    return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ];
                }
                try {
                    $model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                    if ( ! property_exists( $model, 'schema' ) && ! isset( $model->schema ) ) {
                        return [ 'success' => false, 'message' => 'This AIOSEO version does not support per-post schema editing.' ];
                    }
                    $model->schema = wp_json_encode( $input['schema'] );
                    $model->save();
                    return [ 'success' => true, 'message' => 'Schema updated.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- settings ------------------------------ */

    private function register_settings() {
        wp_register_ability( 'atarim/aioseo-get-settings', [
            'label'        => 'Get AIOSEO Settings',
            'description'  => 'Read selected site-wide AIOSEO settings (read-only) from the stored options: separator and the global title/description templates where available.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $raw = get_option( 'aioseo_options', '' );
                $opts = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
                $sep = '';
                $home_title = '';
                $home_desc  = '';
                if ( is_array( $opts ) ) {
                    $sep        = isset( $opts['searchAppearance']['global']['separator'] ) ? $opts['searchAppearance']['global']['separator'] : '';
                    $home_title = isset( $opts['searchAppearance']['global']['siteTitle'] ) ? $opts['searchAppearance']['global']['siteTitle'] : '';
                    $home_desc  = isset( $opts['searchAppearance']['global']['metaDescription'] ) ? $opts['searchAppearance']['global']['metaDescription'] : '';
                }
                return [ 'success' => true, 'settings' => [ 'separator' => $sep, 'site_title' => $home_title, 'meta_description' => $home_desc ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- redirects ----------------------------- */

    private function register_redirects() {
        $detector = $this->detector;

        $guard = function() use ( $detector ) {
            if ( ! $detector->avcf_aioseo_has_redirects() ) {
                return [ 'success' => false, 'message' => 'AIOSEO Pro with the Redirects feature is required; it is not active on this site.' ];
            }
            return null;
        };

        wp_register_ability( 'atarim/aioseo-list-redirects', [
            'label'        => 'List Redirects (AIOSEO Pro)',
            'description'  => 'List AIOSEO redirects (source, target, type, enabled). Requires AIOSEO Pro Redirects.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'total' => [ 'type' => 'integer' ], 'redirects' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                global $wpdb;
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 100;
                $table = $wpdb->prefix . 'aioseo_redirects';
                try {
                    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_url, target_url, type, enabled FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
                    $out  = [];
                    foreach ( (array) $rows as $r ) {
                        $out[] = [ 'id' => (int) $r['id'], 'source' => $r['source_url'], 'target' => $r['target_url'], 'type' => (int) $r['type'], 'enabled' => (bool) $r['enabled'] ];
                    }
                    return [ 'success' => true, 'total' => count( $out ), 'redirects' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read redirects: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/aioseo-create-redirect', [
            'label'        => 'Create Redirect (AIOSEO Pro)',
            'description'  => 'Create an AIOSEO redirect from a source path to a target. type is the HTTP code (301, 302, 307, 410, 451). Requires AIOSEO Pro Redirects.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'source' => [ 'type' => 'string', 'description' => 'Source path (e.g. "/old-page/").' ],
                'target' => [ 'type' => 'string', 'description' => 'Destination URL or path.' ],
                'type'   => [ 'type' => 'integer', 'enum' => [ 301, 302, 307, 410, 451 ], 'default' => 301 ],
            ], 'required' => [ 'source' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $detector ) {
                $g = $guard(); if ( $g ) { return $g; }
                $source = isset( $input['source'] ) ? (string) $input['source'] : '';
                if ( $source === '' ) { return [ 'success' => false, 'message' => 'source is required.' ]; }
                $class = $detector->avcf_aioseo_redirect_model();
                if ( $class === '' ) { return [ 'success' => false, 'message' => 'AIOSEO redirect model not found.' ]; }
                try {
                    $model = new $class();
                    $model->source_url = $source;
                    $model->target_url = isset( $input['target'] ) ? (string) $input['target'] : '';
                    $model->type       = isset( $input['type'] ) ? (int) $input['type'] : 301;
                    $model->enabled    = true;
                    $model->save();
                    $id = isset( $model->id ) ? (int) $model->id : 0;
                    return [ 'success' => true, 'id' => $id, 'message' => sprintf( 'Created redirect from %s.', $source ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/aioseo-edit-redirect', [
            'label'        => 'Edit Redirect (AIOSEO Pro)',
            'description'  => 'Update an AIOSEO redirect by id. Supply any of target, type, enabled. Requires AIOSEO Pro Redirects.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'target'  => [ 'type' => 'string' ],
                'type'    => [ 'type' => 'integer', 'enum' => [ 301, 302, 307, 410, 451 ] ],
                'enabled' => [ 'type' => 'boolean' ],
            ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $detector ) {
                $g = $guard(); if ( $g ) { return $g; }
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) { return [ 'success' => false, 'message' => 'id is required.' ]; }
                $class = $detector->avcf_aioseo_redirect_model();
                if ( $class === '' ) { return [ 'success' => false, 'message' => 'AIOSEO redirect model not found.' ]; }
                try {
                    $model = new $class( $id );
                    if ( empty( $model->id ) ) { return [ 'success' => false, 'message' => sprintf( 'Redirect %d not found.', $id ) ]; }
                    if ( array_key_exists( 'target', $input ) ) { $model->target_url = (string) $input['target']; }
                    if ( array_key_exists( 'type', $input ) ) { $model->type = (int) $input['type']; }
                    if ( array_key_exists( 'enabled', $input ) ) { $model->enabled = (bool) $input['enabled']; }
                    $model->save();
                    return [ 'success' => true, 'message' => sprintf( 'Updated redirect %d.', $id ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Edit failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/aioseo-delete-redirect', [
            'label'        => 'Delete Redirect (AIOSEO Pro)',
            'description'  => 'Delete an AIOSEO redirect by id. Dry run unless confirm:true. Requires AIOSEO Pro Redirects.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $detector ) {
                $g = $guard(); if ( $g ) { return $g; }
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) { return [ 'success' => false, 'message' => 'id is required.' ]; }
                $class = $detector->avcf_aioseo_redirect_model();
                if ( $class === '' ) { return [ 'success' => false, 'message' => 'AIOSEO redirect model not found.' ]; }
                try {
                    $model = new $class( $id );
                    if ( empty( $model->id ) ) { return [ 'success' => false, 'message' => sprintf( 'Redirect %d not found.', $id ) ]; }
                    if ( empty( $input['confirm'] ) ) {
                        return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete redirect %d (%s). Re-call with confirm:true.', $id, isset( $model->source_url ) ? $model->source_url : '' ) ];
                    }
                    if ( method_exists( $model, 'delete' ) ) {
                        $model->delete();
                    } else {
                        global $wpdb;
                        $wpdb->delete( $wpdb->prefix . 'aioseo_redirects', [ 'id' => $id ] );
                    }
                    return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Deleted redirect %d.', $id ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Delete failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
