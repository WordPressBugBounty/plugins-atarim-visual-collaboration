<?php
/**
 * Bricks — MCP abilities (core: content tree + page settings).
 *
 * The stable, load-bearing surface: read/edit the flat element array Bricks
 * stores per area (content/header/footer), list element types, and read/write
 * page settings. The deeper Bricks surfaces (global classes, components, theme
 * styles, variables, interactions, dynamic data, templates) are a separate
 * advanced pass — they are site-wide / internal-heavy, like Elementor Pro's
 * advanced set.
 *
 * Built on the documented Bricks storage model (read from the Novamira
 * reference); NOT runtime-tested here. Bricks' own validation engine and
 * code-element signing are not reproduced — code elements are written UNSIGNED
 * (Bricks won't execute unsigned code; signing is the excluded execute-PHP
 * risk class).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Bricks extends AVCF_Abilities_Base {

    /** @var AVCF_Bricks_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Bricks_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_bricks_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_list_elements();
        $this->register_get_content();
        $this->register_set_content();
        $this->register_insert_content();
        $this->register_patch_elements();
        $this->register_remove_content();
        $this->register_settings();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }
    private function std_out() {
        return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ];
    }
    private function can_edit( $post_id ) {
        return current_user_can( 'edit_post', (int) $post_id ) || current_user_can( 'edit_posts' );
    }
    private function require_post( $post_id ) {
        if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
        return null;
    }
    private function area_prop() {
        return [ 'type' => 'string', 'enum' => [ 'content', 'header', 'footer' ], 'default' => 'content', 'description' => 'Which Bricks area to target.' ];
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/bricks-check-setup', [
            'label' => 'Check Bricks Setup', 'category' => 'atarim',
            'description' => 'Call first before other Bricks abilities. Reports whether Bricks is active and its version.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_bricks_is_available(), 'version' => $d->avcf_bricks_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-elements ------------------------- */

    private function register_list_elements() {
        wp_register_ability( 'atarim/bricks-list-elements', [
            'label' => 'List Bricks Elements', 'category' => 'atarim',
            'description' => 'List the registered Bricks element types (name + label) — the values you pass as "name" when inserting elements (e.g. "section", "container", "heading", "text", "image", "button").',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'elements' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = AVCF_Bricks_Helpers::element_types();
                return [ 'success' => true, 'elements' => $types, 'message' => $types ? sprintf( '%d element type(s).', count( $types ) ) : 'Element registry not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- get-content --------------------------- */

    private function register_get_content() {
        $self = $this;
        wp_register_ability( 'atarim/bricks-get-content', [
            'label' => 'Get Bricks Content', 'category' => 'atarim',
            'description' => 'Read a post\'s Bricks element structure for an area. Bricks stores a FLAT element list — each node has id, name (type), parent (parent id, 0 = top level), children (child ids), and settings. By default returns a structural summary (no settings); pass element_id for one element\'s full data, or include_settings:true for the whole area with settings.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                'area'             => $self->area_prop(),
                'element_id'       => [ 'type' => 'string' ],
                'include_settings' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'elements' => [ 'type' => 'array' ], 'element' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $els  = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                if ( ! empty( $input['element_id'] ) ) {
                    $el = AVCF_Bricks_Helpers::find( $els, (string) $input['element_id'] );
                    if ( $el === null ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found in %s.', $input['element_id'], $area ) ]; }
                    return [ 'success' => true, 'element' => $el, 'message' => 'OK.' ];
                }
                if ( ! empty( $input['include_settings'] ) ) {
                    return [ 'success' => true, 'elements' => $els, 'message' => 'OK (full).' ];
                }
                return [ 'success' => true, 'elements' => AVCF_Bricks_Helpers::summarize( $els ), 'message' => 'OK (summary).' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/bricks-set-content', [
            'label' => 'Set Bricks Content', 'category' => 'atarim',
            'description' => 'Replace a post\'s ENTIRE Bricks element list for an area with the supplied flat array (same shape get-content returns with include_settings:true). Full overwrite — for targeted changes prefer insert-content / patch-elements / remove-content. Missing ids/children are filled in. Code elements are stored UNSIGNED and will not execute until signed in the Bricks editor.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'area'     => $self->area_prop(),
                'elements' => [ 'type' => 'array' ],
            ], 'required' => [ 'post_id', 'elements' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                if ( ! isset( $input['elements'] ) || ! is_array( $input['elements'] ) ) { return [ 'success' => false, 'message' => 'elements must be an array.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                if ( ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $input['elements'] ) ) { return [ 'success' => false, 'message' => 'Failed to save (no change or write error).' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Replaced Bricks %s on post %d.', $area, $post_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* -------------------------- insert-content ------------------------- */

    private function register_insert_content() {
        $self = $this;
        wp_register_ability( 'atarim/bricks-insert-content', [
            'label' => 'Insert Bricks Element', 'category' => 'atarim',
            'description' => 'Insert a new element into a post\'s Bricks area. name is the element type (see list-elements). parent_id nests it under an existing element (omit/0 for top level); the parent/children pointers are kept in sync. settings is the element\'s control values. Returns the new element id. Code elements are stored UNSIGNED.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                'area'      => $self->area_prop(),
                'name'      => [ 'type' => 'string', 'description' => 'Element type, e.g. "section", "container", "heading".' ],
                'parent_id' => [ 'type' => 'string', 'description' => 'Parent element id. Omit for top level.' ],
                'settings'  => [ 'type' => 'object' ],
                'label'     => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'element_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $node = [
                    'id'       => AVCF_Bricks_Helpers::generate_id(),
                    'name'     => (string) $input['name'],
                    'settings' => isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [],
                    'children' => [],
                ];
                if ( isset( $input['label'] ) ) { $node['label'] = (string) $input['label']; }
                $els = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                list( $els, $ok ) = AVCF_Bricks_Helpers::insert( $els, isset( $input['parent_id'] ) ? (string) $input['parent_id'] : 0, $node );
                if ( ! $ok ) { return [ 'success' => false, 'message' => sprintf( 'parent_id "%s" not found.', isset( $input['parent_id'] ) ? $input['parent_id'] : '' ) ]; }
                if ( ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $els ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'element_id' => $node['id'], 'message' => sprintf( 'Inserted "%s".', $node['name'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- patch-elements ------------------------- */

    private function register_patch_elements() {
        $self = $this;
        wp_register_ability( 'atarim/bricks-patch-elements', [
            'label' => 'Patch Bricks Element', 'category' => 'atarim',
            'description' => 'Update one element by id. settings are shallow-merged into the element\'s existing settings (top-level keys you send overwrite; others kept). Optionally change label/name. Read the element first with get-content (element_id).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'area'       => $self->area_prop(),
                'element_id' => [ 'type' => 'string' ],
                'settings'   => [ 'type' => 'object' ],
                'label'      => [ 'type' => 'string' ],
                'name'       => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $patch = [];
                foreach ( [ 'settings', 'label', 'name' ] as $k ) { if ( isset( $input[ $k ] ) ) { $patch[ $k ] = $input[ $k ]; } }
                if ( empty( $patch ) ) { return [ 'success' => false, 'message' => 'Provide at least one of settings, label, name.' ]; }
                $els = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                $found = false;
                $els = AVCF_Bricks_Helpers::patch( $els, (string) $input['element_id'], $patch, $found );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $input['element_id'] ) ]; }
                if ( ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $els ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Updated element "%s".', $input['element_id'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- remove-content ------------------------- */

    private function register_remove_content() {
        $self = $this;
        wp_register_ability( 'atarim/bricks-remove-content', [
            'label' => 'Remove Bricks Element', 'category' => 'atarim',
            'description' => 'Remove an element (and all its descendants) from a Bricks area by id, detaching it from its parent. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'area'       => $self->area_prop(),
                'element_id' => [ 'type' => 'string' ],
                'confirm'    => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'removed' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $els  = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                if ( AVCF_Bricks_Helpers::find( $els, (string) $input['element_id'] ) === null ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $input['element_id'] ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'removed' => false, 'message' => sprintf( 'Dry run: would remove "%s" and its descendants. Re-call with confirm:true.', $input['element_id'] ) ]; }
                list( $els, $ok ) = AVCF_Bricks_Helpers::remove( $els, (string) $input['element_id'] );
                if ( ! $ok || ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $els ) ) { return [ 'success' => false, 'message' => 'Failed to remove/save.' ]; }
                return [ 'success' => true, 'removed' => true, 'message' => sprintf( 'Removed "%s".', $input['element_id'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ settings --------------------------- */

    private function register_settings() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/bricks-get-settings', [
            'label' => 'Get Bricks Page Settings', 'category' => 'atarim',
            'description' => 'Read a post\'s Bricks page settings (the _bricks_page_settings meta — page-level options like SEO, layout, code).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                $s = get_post_meta( $post_id, '_bricks_page_settings', true );
                return [ 'success' => true, 'settings' => is_array( $s ) ? $s : [], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-list-settings', [
            'label' => 'List Bricks Page Setting Keys', 'category' => 'atarim',
            'description' => 'List the keys present in a post\'s Bricks page settings.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'keys' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                $s = get_post_meta( $post_id, '_bricks_page_settings', true );
                $keys = is_array( $s ) ? array_keys( $s ) : [];
                return [ 'success' => true, 'keys' => $keys, 'message' => sprintf( '%d key(s).', count( $keys ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-set-settings', [
            'label' => 'Set Bricks Page Settings', 'category' => 'atarim',
            'description' => 'Update a post\'s Bricks page settings. settings is shallow-merged into the existing page settings by default; pass replace:true to overwrite the whole object.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'settings' => [ 'type' => 'object' ],
                'replace'  => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'settings' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id']; $err = $self->require_post( $post_id ); if ( $err ) { return $err; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) ) { return [ 'success' => false, 'message' => 'settings must be an object.' ]; }
                $new = $input['settings'];
                if ( empty( $input['replace'] ) ) {
                    $cur = get_post_meta( $post_id, '_bricks_page_settings', true );
                    $new = array_merge( is_array( $cur ) ? $cur : [], $new );
                }
                update_post_meta( $post_id, '_bricks_page_settings', $new );
                return [ 'success' => true, 'message' => 'Page settings updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-set-post-types', [
            'label' => 'Set Bricks Post Types', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Set which post types the Bricks builder is enabled for (writes the postTypes key in Bricks global settings). post_types is the full array of post type slugs to enable.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ], 'required' => [ 'post_types' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $pts = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_values( array_map( 'sanitize_key', $input['post_types'] ) ) : [];
                $opt = get_option( 'bricks_global_settings' );
                if ( ! is_array( $opt ) ) { $opt = []; }
                $opt['postTypes'] = $pts;
                update_option( 'bricks_global_settings', $opt );
                return [ 'success' => true, 'message' => sprintf( 'Bricks enabled for %d post type(s).', count( $pts ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
