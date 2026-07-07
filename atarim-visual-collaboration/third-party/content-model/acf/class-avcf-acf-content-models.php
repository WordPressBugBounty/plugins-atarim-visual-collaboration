<?php
/**
 * ACF — Content model MCP abilities (CPTs, taxonomies, options pages).
 *
 * ACF Pro 6.1+ can register custom post types and taxonomies, and 6.2+ can
 * register UI options pages, storing them as its own "internal post types":
 * acf-post-type, acf-taxonomy, acf-ui-options-page. All three share one CRUD
 * surface (acf_get_internal_post_type_posts / acf_get_internal_post_type /
 * acf_update_internal_post_type / acf_delete_internal_post_type), so this
 * single class registers all 15 abilities through one shared dispatcher,
 * varying only the labels, schemas, and payload shaping per kind.
 *
 * REQUIRES ACF Pro 6.5+ — every ability self-gates via the detector and
 * returns a clear message when the internal-post-type APIs are unavailable.
 * Only database-stored records are mutable; PHP/JSON-registered ones are
 * reported read-only.
 *
 * Exposed (per kind = post-type | taxonomy | options-page):
 *   atarim/list-acf-post-types     get-acf-post-type     create/update/delete
 *   atarim/list-acf-taxonomies     get-acf-taxonomy      create/update/delete
 *   atarim/list-acf-options-pages  get-acf-options-page  create/update/delete
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ACF_Content_Models extends AVCF_Abilities_Base {

    /**
     * @var AVCF_ACF_Detector
     */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_ACF_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_acf_is_available() ) {
            return;
        }

        $this->register_post_types();
        $this->register_taxonomies();
        $this->register_options_pages();
    }

    /* ---------------------------------------------------------------------
     * Shared internals
     * ------------------------------------------------------------------- */

    /**
     * ACF internal post type slug for a kind, plus the key prefix ACF uses.
     */
    private function kind_meta( $kind ) {
        switch ( $kind ) {
            case 'post-type':
                return [ 'pt' => 'acf-post-type',       'prefix' => 'post_type_',       'label' => 'post type' ];
            case 'taxonomy':
                return [ 'pt' => 'acf-taxonomy',        'prefix' => 'taxonomy_',        'label' => 'taxonomy' ];
            case 'options-page':
                return [ 'pt' => 'acf-ui-options-page', 'prefix' => 'ui_options_page_', 'label' => 'options page' ];
            default:
                return null;
        }
    }

    /**
     * Guard: ACF Pro 6.5+ internal-post-type APIs present.
     *
     * @return array|null  Error response if unsupported, null if OK.
     */
    private function guard() {
        if ( ! $this->detector->avcf_acf_supports_internal_post_types() ) {
            return [
                'success' => false,
                'message' => 'Managing ACF post types, taxonomies, and options pages requires ACF Pro 6.5 or newer (the internal-post-type APIs are unavailable on this install).',
            ];
        }
        return null;
    }

    private function generate_key( $kind ) {
        $meta = $this->kind_meta( $kind );
        return $meta['prefix'] . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 13 );
    }

    private function storage_mode( $record ) {
        $local = isset( $record['local'] ) ? $record['local'] : null;
        if ( $local === 'php' ) {
            return 'php';
        }
        if ( $local === 'json' ) {
            return 'json';
        }
        return 'db';
    }

    /**
     * Resolve a key-or-slug to an internal-post-type key.
     *
     * @return string|null
     */
    private function resolve_key( $key_or_slug, $kind ) {
        $meta = $this->kind_meta( $kind );
        // Direct key hit.
        $record = acf_get_internal_post_type( $key_or_slug, $meta['pt'] );
        if ( $record ) {
            return isset( $record['key'] ) ? (string) $record['key'] : (string) $key_or_slug;
        }
        // Fall back to matching by slug field.
        $slug_field = ( $kind === 'taxonomy' ) ? 'taxonomy' : ( $kind === 'post-type' ? 'post_type' : 'menu_slug' );
        foreach ( (array) acf_get_internal_post_type_posts( $meta['pt'] ) as $rec ) {
            if ( isset( $rec[ $slug_field ] ) && (string) $rec[ $slug_field ] === (string) $key_or_slug ) {
                return isset( $rec['key'] ) ? (string) $rec['key'] : null;
            }
        }
        return null;
    }

    /**
     * Shape an internal-post-type record for output.
     */
    private function shape( $record, $kind ) {
        $out = [
            'key'     => isset( $record['key'] ) ? (string) $record['key'] : '',
            'title'   => isset( $record['title'] ) ? (string) $record['title'] : '',
            'active'  => isset( $record['active'] ) ? (bool) $record['active'] : true,
            'storage' => $this->storage_mode( $record ),
            'mutable' => $this->storage_mode( $record ) === 'db',
        ];
        if ( $kind === 'post-type' ) {
            $out['post_type']    = isset( $record['post_type'] ) ? (string) $record['post_type'] : '';
            $out['public']       = isset( $record['public'] ) ? (bool) $record['public'] : false;
            $out['hierarchical'] = isset( $record['hierarchical'] ) ? (bool) $record['hierarchical'] : false;
        } elseif ( $kind === 'taxonomy' ) {
            $out['taxonomy']     = isset( $record['taxonomy'] ) ? (string) $record['taxonomy'] : '';
            $out['object_type']  = isset( $record['object_type'] ) ? (array) $record['object_type'] : [];
            $out['hierarchical'] = isset( $record['hierarchical'] ) ? (bool) $record['hierarchical'] : false;
        } else {
            $out['menu_slug']    = isset( $record['menu_slug'] ) ? (string) $record['menu_slug'] : '';
            $out['page_title']   = isset( $record['page_title'] ) ? (string) $record['page_title'] : '';
            $out['parent_slug']  = isset( $record['parent_slug'] ) ? (string) $record['parent_slug'] : '';
        }
        return $out;
    }

    /**
     * Build the create/update payload from input for a given kind.
     */
    private function build_payload( $kind, $input, $base = [] ) {
        $payload = is_array( $base ) ? $base : [];

        // Generic passthrough for advanced/native args.
        if ( isset( $input['advanced'] ) && is_array( $input['advanced'] ) ) {
            $payload = array_merge( $payload, $input['advanced'] );
        }
        if ( isset( $input['active'] ) ) {
            $payload['active'] = (bool) $input['active'];
        }

        if ( $kind === 'post-type' ) {
            if ( isset( $input['post_type'] ) ) {
                $payload['post_type'] = sanitize_key( (string) $input['post_type'] );
            }
            if ( isset( $input['plural_label'] ) ) {
                $payload['title'] = (string) $input['plural_label'];
            }
            if ( isset( $input['singular_label'] ) ) {
                $payload['labels']['singular_name'] = (string) $input['singular_label'];
            }
            if ( isset( $input['public'] ) ) {
                $payload['public'] = (bool) $input['public'];
            }
            if ( isset( $input['hierarchical'] ) ) {
                $payload['hierarchical'] = (bool) $input['hierarchical'];
            }
        } elseif ( $kind === 'taxonomy' ) {
            if ( isset( $input['taxonomy'] ) ) {
                $payload['taxonomy'] = sanitize_key( (string) $input['taxonomy'] );
            }
            if ( isset( $input['plural_label'] ) ) {
                $payload['title'] = (string) $input['plural_label'];
            }
            if ( isset( $input['object_type'] ) && is_array( $input['object_type'] ) ) {
                $payload['object_type'] = array_map( 'sanitize_key', $input['object_type'] );
            }
            if ( isset( $input['hierarchical'] ) ) {
                $payload['hierarchical'] = (bool) $input['hierarchical'];
            }
            if ( isset( $input['public'] ) ) {
                $payload['public'] = (bool) $input['public'];
            }
        } else { // options-page
            if ( isset( $input['page_title'] ) ) {
                $payload['page_title'] = (string) $input['page_title'];
                $payload['title']      = (string) $input['page_title'];
            }
            if ( isset( $input['menu_slug'] ) ) {
                $payload['menu_slug'] = sanitize_title( (string) $input['menu_slug'] );
            }
            if ( isset( $input['parent_slug'] ) ) {
                $payload['parent_slug'] = (string) $input['parent_slug'];
            }
            if ( isset( $input['capability'] ) ) {
                $payload['capability'] = (string) $input['capability'];
            }
        }

        return $payload;
    }

    private function can() {
        return current_user_can( 'manage_options' );
    }

    private function ro_meta() {
        return [
            'mcp' => [ 'public' => true, 'type' => 'tool' ],
            'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ];
    }

    private function write_meta( $destructive = false ) {
        return [
            'mcp' => [ 'public' => true, 'type' => 'tool' ],
            'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ],
        ];
    }

    /**
     * Register the five list/get/create/update/delete abilities for a kind.
     *
     * @param string $kind
     * @param array  $names   ['list'=>..,'get'=>..,'create'=>..,'update'=>..,'delete'=>..]
     * @param array  $labels  human labels keyed the same way
     * @param array  $create_props  input_schema properties for create
     * @param array  $create_required
     * @param array  $update_props
     */
    private function register_kind( $kind, $names, $labels, $create_props, $create_required, $update_props ) {
        $self  = $this;
        $meta  = $this->kind_meta( $kind );
        $klbl  = $meta['label'];

        // list
        wp_register_ability( $names['list'], [
            'label'        => $labels['list'],
            'description'  => sprintf( 'List all ACF-registered %ss. Returns key, title, slug, active state, and storage mode (db / php / json; only db records are mutable). Requires ACF Pro 6.5+.', $klbl ),
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'total' => [ 'type' => 'integer' ], 'items' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $kind, $meta ) {
                $g = $self->guard(); if ( $g ) { return $g; }
                $items = [];
                foreach ( (array) acf_get_internal_post_type_posts( $meta['pt'] ) as $rec ) {
                    $items[] = $self->shape( $rec, $kind );
                }
                return [ 'success' => true, 'total' => count( $items ), 'items' => $items, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );

        // get
        wp_register_ability( $names['get'], [
            'label'        => $labels['get'],
            'description'  => sprintf( 'Get one ACF %s by key or slug, including its full raw ACF definition (use this as the template for update — pass changed fields back via "advanced"). Requires ACF Pro 6.5+.', $klbl ),
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'key' => [ 'type' => 'string', 'description' => 'The record key or its slug.' ] ], 'required' => [ 'key' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'item' => [ 'type' => 'object' ], 'raw' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $kind, $meta ) {
                $g = $self->guard(); if ( $g ) { return $g; }
                $key = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $key === '' ) { return [ 'success' => false, 'message' => 'key is required.' ]; }
                $resolved = $self->resolve_key( $key, $kind );
                if ( ! $resolved ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $meta['label'] ), $key ) ]; }
                $record = acf_get_internal_post_type( $resolved, $meta['pt'] );
                if ( ! $record ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $meta['label'] ), $key ) ]; }
                return [ 'success' => true, 'item' => $self->shape( $record, $kind ), 'raw' => $record, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );

        // create
        wp_register_ability( $names['create'], [
            'label'        => $labels['create'],
            'description'  => sprintf( 'Create a new ACF-registered %s (stored in the database). For settings beyond the common ones, pass an "advanced" object whose keys mirror the raw definition from get-acf-%s. Requires ACF Pro 6.5+.', $klbl, str_replace( ' ', '-', $klbl ) ),
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => $create_props, 'required' => $create_required, 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'key' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $kind, $meta ) {
                $g = $self->guard(); if ( $g ) { return $g; }
                $payload = $self->build_payload( $kind, $input, [ 'key' => $self->generate_key( $kind ), 'active' => isset( $input['active'] ) ? (bool) $input['active'] : true ] );
                $result  = acf_update_internal_post_type( $payload, $meta['pt'] );
                if ( ! is_array( $result ) || empty( $result['key'] ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'ACF reported no key after creating the %s.', $meta['label'] ) ];
                }
                if ( function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }
                return [ 'success' => true, 'key' => (string) $result['key'], 'message' => sprintf( 'Created %s.', $meta['label'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );

        // update
        wp_register_ability( $names['update'], [
            'label'        => $labels['update'],
            'description'  => sprintf( 'Update an existing database-stored ACF %s. Supply the key plus only the properties to change; use "advanced" for raw-definition keys. Refuses on php/json-registered records. Requires ACF Pro 6.5+.', $klbl ),
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [ 'key' => [ 'type' => 'string', 'description' => 'Record key or slug to update.' ] ], $update_props ), 'required' => [ 'key' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'key' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $kind, $meta ) {
                $g = $self->guard(); if ( $g ) { return $g; }
                $key = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $key === '' ) { return [ 'success' => false, 'message' => 'key is required.' ]; }
                $resolved = $self->resolve_key( $key, $kind );
                if ( ! $resolved ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $meta['label'] ), $key ) ]; }
                $existing = acf_get_internal_post_type( $resolved, $meta['pt'] );
                if ( ! $existing ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $meta['label'] ), $key ) ]; }
                if ( $self->storage_mode( $existing ) !== 'db' ) {
                    return [ 'success' => false, 'message' => sprintf( '%s "%s" is registered via %s and is read-only.', ucfirst( $meta['label'] ), $key, $self->storage_mode( $existing ) ) ];
                }
                $payload = $self->build_payload( $kind, $input, $existing );
                $result  = acf_update_internal_post_type( $payload, $meta['pt'] );
                if ( ! is_array( $result ) || empty( $result['key'] ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'ACF reported no key after updating the %s.', $meta['label'] ) ];
                }
                if ( function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }
                return [ 'success' => true, 'key' => (string) $result['key'], 'message' => sprintf( 'Updated %s.', $meta['label'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );

        // delete
        wp_register_ability( $names['delete'], [
            'label'        => $labels['delete'],
            'description'  => sprintf( 'Delete a database-stored ACF %s. This removes the registration only; existing content (posts/terms) is not deleted. Dry run unless confirm:true. Refuses on php/json-registered records. Requires ACF Pro 6.5+.', $klbl ),
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'key' => [ 'type' => 'string', 'description' => 'Record key or slug to delete.' ], 'confirm' => [ 'type' => 'boolean', 'description' => 'Must be true to delete. Defaults to false (dry run).', 'default' => false ] ], 'required' => [ 'key' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $kind, $meta ) {
                $g = $self->guard(); if ( $g ) { return $g; }
                $key = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $key === '' ) { return [ 'success' => false, 'message' => 'key is required.' ]; }
                $resolved = $self->resolve_key( $key, $kind );
                if ( ! $resolved ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $meta['label'] ), $key ) ]; }
                $existing = acf_get_internal_post_type( $resolved, $meta['pt'] );
                if ( ! $existing ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $meta['label'] ), $key ) ]; }
                if ( $self->storage_mode( $existing ) !== 'db' ) {
                    return [ 'success' => false, 'message' => sprintf( '%s "%s" is registered via %s and is read-only.', ucfirst( $meta['label'] ), $key, $self->storage_mode( $existing ) ) ];
                }
                if ( empty( $input['confirm'] ) ) {
                    return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete %s "%s". Re-call with confirm:true.', $meta['label'], $resolved ) ];
                }
                $deleted = acf_delete_internal_post_type( $resolved, $meta['pt'] );
                if ( $deleted && function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }
                return [ 'success' => (bool) $deleted, 'deleted' => (bool) $deleted, 'message' => $deleted ? sprintf( 'Deleted %s "%s".', $meta['label'], $resolved ) : 'ACF did not confirm deletion.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------------------------------------------------
     * Per-kind registration (names + schemas)
     * ------------------------------------------------------------------- */

    private function register_post_types() {
        $create_props = [
            'post_type'      => [ 'type' => 'string', 'description' => 'Post type slug (lowercase, max 20 chars, e.g. "team_member").' ],
            'singular_label' => [ 'type' => 'string', 'description' => 'Singular label (e.g. "Team Member").' ],
            'plural_label'   => [ 'type' => 'string', 'description' => 'Plural label (e.g. "Team Members"). Used as the title.' ],
            'public'         => [ 'type' => 'boolean', 'description' => 'Public (queryable on the front end). Defaults to ACF default.' ],
            'hierarchical'   => [ 'type' => 'boolean', 'description' => 'Hierarchical (page-like) vs flat (post-like).' ],
            'active'         => [ 'type' => 'boolean', 'description' => 'Active. Defaults to true.' ],
            'advanced'       => [ 'type' => 'object', 'description' => 'Raw ACF post-type definition keys to merge (supports, taxonomies, rewrite, menu_icon, etc.). Mirror the shape from get-acf-post-type.' ],
        ];
        $update_props = $create_props;
        $this->register_kind(
            'post-type',
            [ 'list' => 'atarim/list-acf-post-types', 'get' => 'atarim/get-acf-post-type', 'create' => 'atarim/create-acf-post-type', 'update' => 'atarim/update-acf-post-type', 'delete' => 'atarim/delete-acf-post-type' ],
            [ 'list' => 'List ACF Post Types', 'get' => 'Get ACF Post Type', 'create' => 'Create ACF Post Type', 'update' => 'Update ACF Post Type', 'delete' => 'Delete ACF Post Type' ],
            $create_props, [ 'post_type', 'plural_label' ], $update_props
        );
    }

    private function register_taxonomies() {
        $create_props = [
            'taxonomy'       => [ 'type' => 'string', 'description' => 'Taxonomy slug (lowercase, max 32 chars, e.g. "genre").' ],
            'singular_label' => [ 'type' => 'string', 'description' => 'Singular label (e.g. "Genre").' ],
            'plural_label'   => [ 'type' => 'string', 'description' => 'Plural label (e.g. "Genres"). Used as the title.' ],
            'object_type'    => [ 'type' => 'array', 'description' => 'Post type slugs this taxonomy attaches to (e.g. ["post","team_member"]).' ],
            'hierarchical'   => [ 'type' => 'boolean', 'description' => 'Hierarchical (category-like) vs flat (tag-like).' ],
            'public'         => [ 'type' => 'boolean', 'description' => 'Public.' ],
            'active'         => [ 'type' => 'boolean', 'description' => 'Active. Defaults to true.' ],
            'advanced'       => [ 'type' => 'object', 'description' => 'Raw ACF taxonomy definition keys to merge. Mirror get-acf-taxonomy.' ],
        ];
        $this->register_kind(
            'taxonomy',
            [ 'list' => 'atarim/list-acf-taxonomies', 'get' => 'atarim/get-acf-taxonomy', 'create' => 'atarim/create-acf-taxonomy', 'update' => 'atarim/update-acf-taxonomy', 'delete' => 'atarim/delete-acf-taxonomy' ],
            [ 'list' => 'List ACF Taxonomies', 'get' => 'Get ACF Taxonomy', 'create' => 'Create ACF Taxonomy', 'update' => 'Update ACF Taxonomy', 'delete' => 'Delete ACF Taxonomy' ],
            $create_props, [ 'taxonomy', 'plural_label', 'object_type' ], $create_props
        );
    }

    private function register_options_pages() {
        $create_props = [
            'page_title'  => [ 'type' => 'string', 'description' => 'Options page title (e.g. "Theme Settings"). Used as the menu and page title.' ],
            'menu_slug'   => [ 'type' => 'string', 'description' => 'Menu slug. Auto-derived from the title if omitted.' ],
            'parent_slug' => [ 'type' => 'string', 'description' => 'Parent menu slug to nest under (omit for a top-level page).' ],
            'capability'  => [ 'type' => 'string', 'description' => 'Capability required to view the page. Defaults to edit_posts.' ],
            'active'      => [ 'type' => 'boolean', 'description' => 'Active. Defaults to true.' ],
            'advanced'    => [ 'type' => 'object', 'description' => 'Raw ACF options-page definition keys to merge (icon_url, position, redirect, etc.). Mirror get-acf-options-page.' ],
        ];
        $this->register_kind(
            'options-page',
            [ 'list' => 'atarim/list-acf-options-pages', 'get' => 'atarim/get-acf-options-page', 'create' => 'atarim/create-acf-options-page', 'update' => 'atarim/update-acf-options-page', 'delete' => 'atarim/delete-acf-options-page' ],
            [ 'list' => 'List ACF Options Pages', 'get' => 'Get ACF Options Page', 'create' => 'Create ACF Options Page', 'update' => 'Update ACF Options Page', 'delete' => 'Delete ACF Options Page' ],
            $create_props, [ 'page_title' ], $create_props
        );
    }
}
