<?php
/**
 * Meta Box — MCP abilities (schema / authoring layer).
 *
 * Covers the structure layer only; field VALUES are already handled by the
 * generic metadata abilities (which detect and route Meta Box at the value
 * layer). Here we introspect and author Meta Box definitions.
 *
 * Confidence split (none of this could be tested against a live Meta Box here):
 *   SOLID    — all reads (list/get), and relationship object connect /
 *              disconnect / list-connected (persisted connection table).
 *   FLAGGED  — builder-CPT authoring (create/edit/delete field groups, post
 *              types, taxonomies, settings pages): the exact meta shape Meta
 *              Box Builder expects is version-specific; best-effort.
 *   WARNING  — relationship *definition* create/edit/delete: MB_Relationships
 *              API registration is runtime-only and does NOT persist across
 *              requests unless declared in code on every load. Surfaced in the
 *              ability output, not silently ignored.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_MetaBox extends AVCF_Abilities_Base {

    /** @var AVCF_MetaBox_Detector */
    private $detector;

    const REL_PERSIST_WARNING = 'Meta Box relationship definitions registered via the API are runtime-only — they are NOT saved and will vanish on the next request unless also declared in code (mb_relationships_init). Use this to inspect/prototype; persist the definition in code.';

    public function __construct() {
        $this->detector = new AVCF_MetaBox_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_mb_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_field_groups();
        $this->register_post_types();
        $this->register_taxonomies();
        $this->register_settings_pages();
        $this->register_relationships();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }
    private function admin_can() { return current_user_can( 'manage_options' ); }

    private function std_out() {
        return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ];
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/metabox-check-setup', [
            'label' => 'Check Meta Box Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports Meta Box presence/version and which extensions are active: custom post types, custom taxonomies, settings pages, field-group builder, relationships.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [
                    'success' => true,
                    'active'  => $d->avcf_mb_is_available(),
                    'version' => $d->avcf_mb_version(),
                    'extensions' => [
                        'post_types'    => $d->avcf_mb_has_cpt(),
                        'taxonomies'    => $d->avcf_mb_has_taxonomy_builder(),
                        'settings_pages'=> $d->avcf_mb_has_settings_page(),
                        'builder'       => $d->avcf_mb_has_builder(),
                        'relationships' => $d->avcf_mb_has_relationships(),
                    ],
                    'message' => 'OK.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- field groups -------------------------- */

    private function register_field_groups() {
        $self = $this;
        $d    = $this->detector;
        $std  = $this->std_out();

        wp_register_ability( 'atarim/metabox-list-field-groups', [
            'label' => 'List Meta Box Field Groups', 'category' => 'atarim',
            'description' => 'List Meta Box field groups created in the Builder (id, title, slug). Code-registered groups are not stored as posts and may not appear.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_groups' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                if ( ! $d->avcf_mb_has_builder() ) { return [ 'success' => false, 'message' => 'Meta Box Builder (field-group storage) is not active.' ]; }
                $out = [];
                foreach ( AVCF_MetaBox_Helpers::list_cpt( 'meta-box' ) as $p ) { $out[] = AVCF_MetaBox_Helpers::shape_post( $p ); }
                return [ 'success' => true, 'field_groups' => $out, 'message' => sprintf( '%d field group(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/metabox-get-field-group-schema', [
            'label' => 'Get Meta Box Field Group Schema', 'category' => 'atarim',
            'description' => 'Get one field group\'s full stored settings and fields by id or slug.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group' => [ 'type' => 'string', 'description' => 'Field group id or slug.' ] ], 'required' => [ 'group' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_group' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                if ( ! $d->avcf_mb_has_builder() ) { return [ 'success' => false, 'message' => 'Meta Box Builder is not active.' ]; }
                $p = AVCF_MetaBox_Helpers::find_cpt( 'meta-box', (string) $input['group'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $input['group'] ) ]; }
                return [ 'success' => true, 'field_group' => AVCF_MetaBox_Helpers::shape_post( $p, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/metabox-get-field-type-schema', [
            'label' => 'Get Meta Box Field Type Schema', 'category' => 'atarim',
            'description' => 'List the common Meta Box field types (type key + summary) to use when authoring field groups. Reference list; consult Meta Box docs for full per-type settings.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_types' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = [
                    'text', 'textarea', 'number', 'email', 'url', 'tel', 'password', 'date', 'datetime', 'time',
                    'select', 'select_advanced', 'radio', 'checkbox', 'checkbox_list', 'switch',
                    'image', 'image_advanced', 'single_image', 'file', 'file_advanced', 'file_upload',
                    'wysiwyg', 'color', 'map', 'oembed', 'post', 'taxonomy', 'taxonomy_advanced', 'user',
                    'group', 'heading', 'custom_html', 'divider', 'key_value', 'background', 'slider',
                ];
                $out = array_map( function( $t ) { return [ 'type' => $t ]; }, $types );
                return [ 'success' => true, 'field_types' => $out, 'message' => sprintf( '%d field types.', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        // Authoring (best-effort, builder-CPT storage).
        $note = ' BEST-EFFORT: stored as the "meta-box" CPT; the exact Builder meta shape is version-specific and needs validation on a live Meta Box install.';
        wp_register_ability( 'atarim/metabox-create-field-group', [
            'label' => 'Create Meta Box Field Group', 'category' => 'atarim',
            'description' => 'Create a Meta Box field group.' . $note . ' Provide title, and fields (array of field definitions) and/or settings (group settings such as post_types).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'array' ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                if ( ! $d->avcf_mb_has_builder() ) { return [ 'success' => false, 'message' => 'Meta Box Builder is not active.' ]; }
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) { $settings['fields'] = $input['fields']; }
                $id = AVCF_MetaBox_Helpers::upsert_cpt( 'meta-box', (string) $input['title'], $settings );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Field group created (best-effort — verify in Meta Box Builder).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/metabox-edit-field-group', [
            'label' => 'Edit Meta Box Field Group', 'category' => 'atarim',
            'description' => 'Update a field group by id/slug.' . $note,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'array' ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'group' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $d ) {
                if ( ! $d->avcf_mb_has_builder() ) { return [ 'success' => false, 'message' => 'Meta Box Builder is not active.' ]; }
                $p = AVCF_MetaBox_Helpers::find_cpt( 'meta-box', (string) $input['group'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $input['group'] ) ]; }
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) { $settings['fields'] = $input['fields']; }
                $title = isset( $input['title'] ) ? (string) $input['title'] : $p->post_title;
                $id = AVCF_MetaBox_Helpers::upsert_cpt( 'meta-box', $title, $settings, $p->ID );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Field group updated (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/metabox-delete-field-group', [
            'label' => 'Delete Meta Box Field Group', 'category' => 'atarim',
            'description' => 'Delete a field group by id/slug. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'group' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $d ) {
                if ( ! $d->avcf_mb_has_builder() ) { return [ 'success' => false, 'message' => 'Meta Box Builder is not active.' ]; }
                $p = AVCF_MetaBox_Helpers::find_cpt( 'meta-box', (string) $input['group'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $input['group'] ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete field group "%s". Re-call with confirm:true.', $p->post_title ) ]; }
                $r = wp_delete_post( $p->ID, true );
                return $r ? [ 'success' => true, 'deleted' => true, 'message' => 'Field group deleted.' ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------- post types / taxonomies / settings -------------- */

    /** Shared CRUD registrar for the three builder CPTs. */
    private function register_builder_cpt( $cpt, $slug_prefix, $labels, $extra_props, $detector_check ) {
        $self = $this;
        $d    = $this->detector;
        $std  = $this->std_out();
        $note = sprintf( ' BEST-EFFORT: stored as the "%s" CPT; the exact Builder meta shape is version-specific — validate on a live install.', $cpt );

        $guard = function() use ( $d, $detector_check, $labels ) {
            if ( ! call_user_func( [ $d, $detector_check ] ) ) {
                return [ 'success' => false, 'message' => sprintf( 'Meta Box %s builder is not active.', $labels['one'] ) ];
            }
            return null;
        };

        wp_register_ability( 'atarim/metabox-list-' . $labels['list'], [
            'label' => 'List Meta Box ' . $labels['List'], 'category' => 'atarim',
            'description' => sprintf( 'List Meta Box %s created in the Builder (id, title, slug).', $labels['plural'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'items' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt ) {
                $g = $guard(); if ( $g ) { return $g; }
                $out = [];
                foreach ( AVCF_MetaBox_Helpers::list_cpt( $cpt ) as $p ) { $out[] = AVCF_MetaBox_Helpers::shape_post( $p ); }
                return [ 'success' => true, 'items' => $out, 'message' => sprintf( '%d item(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/metabox-get-' . $labels['one'], [
            'label' => 'Get Meta Box ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Get one Meta Box %s\'s full stored settings by id or slug.', $labels['one'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'string', 'description' => 'id or slug' ] ], 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'item' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_MetaBox_Helpers::find_cpt( $cpt, (string) $input['item'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $input['item'] ) ]; }
                return [ 'success' => true, 'item' => AVCF_MetaBox_Helpers::shape_post( $p, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/metabox-create-' . $labels['one'], [
            'label' => 'Create Meta Box ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Create a Meta Box %s.%s Provide title, %s, and optional settings (stored as the Builder config meta).', $labels['one'], $note, $extra_props['req_desc'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [ 'title' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ] ], $extra_props['props'] ), 'required' => array_merge( [ 'title' ], $extra_props['required'] ), 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $extra_props ) {
                $g = $guard(); if ( $g ) { return $g; }
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                foreach ( $extra_props['store'] as $key ) {
                    if ( isset( $input[ $key ] ) ) { $settings[ $key ] = $input[ $key ]; }
                }
                $id = AVCF_MetaBox_Helpers::upsert_cpt( $cpt, (string) $input['title'], $settings );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Created (best-effort — verify in Meta Box Builder).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/metabox-edit-' . $labels['one'], [
            'label' => 'Edit Meta Box ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Update a Meta Box %s by id/slug.%s', $labels['one'], $note ),
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [ 'item' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ] ], $extra_props['props'] ), 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $extra_props, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_MetaBox_Helpers::find_cpt( $cpt, (string) $input['item'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $input['item'] ) ]; }
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                foreach ( $extra_props['store'] as $key ) {
                    if ( isset( $input[ $key ] ) ) { $settings[ $key ] = $input[ $key ]; }
                }
                $title = isset( $input['title'] ) ? (string) $input['title'] : $p->post_title;
                $id = AVCF_MetaBox_Helpers::upsert_cpt( $cpt, $title, $settings, $p->ID );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Updated (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/metabox-delete-' . $labels['one'], [
            'label' => 'Delete Meta Box ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Delete a Meta Box %s by id/slug. Dry run unless confirm:true.', $labels['one'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_MetaBox_Helpers::find_cpt( $cpt, (string) $input['item'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $input['item'] ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete %s "%s". Re-call with confirm:true.', $labels['one'], $p->post_title ) ]; }
                $r = wp_delete_post( $p->ID, true );
                return $r ? [ 'success' => true, 'deleted' => true, 'message' => 'Deleted.' ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    private function register_post_types() {
        $this->register_builder_cpt( 'mb-post-type', 'cpt',
            [ 'list' => 'post-types', 'List' => 'Post Types', 'one' => 'post-type', 'One' => 'Post Type', 'plural' => 'post types' ],
            [ 'props' => [ 'post_type' => [ 'type' => 'string', 'description' => 'Post type slug.' ] ], 'required' => [ 'post_type' ], 'store' => [ 'post_type' ], 'req_desc' => 'post_type (slug)' ],
            'avcf_mb_has_cpt'
        );
    }
    private function register_taxonomies() {
        $this->register_builder_cpt( 'mb-taxonomy', 'tax',
            [ 'list' => 'taxonomies', 'List' => 'Taxonomies', 'one' => 'taxonomy', 'One' => 'Taxonomy', 'plural' => 'taxonomies' ],
            [ 'props' => [ 'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy slug.' ], 'post_types' => [ 'type' => 'array' ] ], 'required' => [ 'taxonomy' ], 'store' => [ 'taxonomy', 'post_types' ], 'req_desc' => 'taxonomy (slug)' ],
            'avcf_mb_has_taxonomy_builder'
        );
    }
    private function register_settings_pages() {
        $this->register_builder_cpt( 'mb-settings-page', 'sp',
            [ 'list' => 'settings-pages', 'List' => 'Settings Pages', 'one' => 'settings-page', 'One' => 'Settings Page', 'plural' => 'settings pages' ],
            [ 'props' => [ 'menu_title' => [ 'type' => 'string' ], 'option_name' => [ 'type' => 'string' ] ], 'required' => [], 'store' => [ 'menu_title', 'option_name' ], 'req_desc' => 'optional menu_title / option_name' ],
            'avcf_mb_has_settings_page'
        );
    }

    /* --------------------------- relationships ------------------------- */

    private function register_relationships() {
        $self = $this;
        $d    = $this->detector;
        $std  = $this->std_out();

        $guard = function() use ( $d ) {
            if ( ! $d->avcf_mb_has_relationships() ) { return [ 'success' => false, 'message' => 'MB Relationships is not active.' ]; }
            return null;
        };

        wp_register_ability( 'atarim/metabox-get-relationship', [
            'label' => 'Get Meta Box Relationship', 'category' => 'atarim',
            'description' => 'Get a registered relationship\'s settings by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'relationship' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                try {
                    $settings = method_exists( 'MB_Relationships_API', 'get_relationship_settings' ) ? MB_Relationships_API::get_relationship_settings( (string) $input['id'] ) : null;
                    if ( ! $settings ) { return [ 'success' => false, 'message' => sprintf( 'Relationship "%s" not found (or not registered this request).', $input['id'] ) ]; }
                    return [ 'success' => true, 'relationship' => (array) $settings, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/metabox-list-relationships', [
            'label' => 'List Meta Box Relationships', 'category' => 'atarim',
            'description' => 'List relationship ids registered this request. NOTE: only relationships declared in code (mb_relationships_init) are present; this cannot enumerate definitions stored elsewhere.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'relationships' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $ids = [];
                try {
                    if ( method_exists( 'MB_Relationships_API', 'get_all_relationships' ) ) {
                        $all = MB_Relationships_API::get_all_relationships();
                        $ids = is_array( $all ) ? array_keys( $all ) : [];
                    }
                } catch ( \Throwable $e ) {}
                return [ 'success' => true, 'relationships' => $ids, 'message' => empty( $ids ) ? 'No enumerable relationships (or enumeration not exposed on this version).' : sprintf( '%d relationship(s).', count( $ids ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        // Definition create/edit/delete — runtime-only; surfaces the persistence warning.
        wp_register_ability( 'atarim/metabox-create-relationship', [
            'label' => 'Create Meta Box Relationship', 'category' => 'atarim',
            'description' => 'Register a relationship definition (id, from, to). WARNING: runtime-only — not persisted. ' . self::REL_PERSIST_WARNING,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'from' => [ 'type' => 'object' ], 'to' => [ 'type' => 'object' ] ], 'required' => [ 'id', 'from', 'to' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                try {
                    MB_Relationships_API::register( [ 'id' => (string) $input['id'], 'from' => (array) $input['from'], 'to' => (array) $input['to'] ] );
                    return [ 'success' => true, 'persisted' => false, 'message' => 'Registered for this request only. ' . AVCF_Abilities_MetaBox::REL_PERSIST_WARNING ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Register failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/metabox-edit-relationship', [
            'label' => 'Edit Meta Box Relationship', 'category' => 'atarim',
            'description' => 'Re-register a relationship with changed settings. WARNING: runtime-only — not persisted. ' . self::REL_PERSIST_WARNING,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'from' => [ 'type' => 'object' ], 'to' => [ 'type' => 'object' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                return [ 'success' => false, 'message' => 'Relationship definitions are code-declared and runtime-only; editing must be done in code (mb_relationships_init). ' . AVCF_Abilities_MetaBox::REL_PERSIST_WARNING ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/metabox-delete-relationship', [
            'label' => 'Delete Meta Box Relationship', 'category' => 'atarim',
            'description' => 'Relationship definitions are code-declared; they cannot be deleted at runtime. ' . self::REL_PERSIST_WARNING,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                return [ 'success' => false, 'message' => 'Cannot delete a code-declared relationship definition at runtime; remove it from code (mb_relationships_init). To remove links between objects, use metabox-disconnect-objects.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );

        // Object connections — persisted (SOLID).
        wp_register_ability( 'atarim/metabox-connect-objects', [
            'label' => 'Connect Meta Box Objects', 'category' => 'atarim',
            'description' => 'Create a link between two objects in a relationship (persisted). from/to are object ids (post/term/user ids per the relationship definition).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string', 'description' => 'Relationship id.' ], 'from' => [ 'type' => 'integer' ], 'to' => [ 'type' => 'integer' ] ], 'required' => [ 'id', 'from', 'to' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                try {
                    MB_Relationships_API::add( (int) $input['from'], (int) $input['to'], (string) $input['id'] );
                    return [ 'success' => true, 'message' => 'Objects connected.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Connect failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/metabox-disconnect-objects', [
            'label' => 'Disconnect Meta Box Objects', 'category' => 'atarim',
            'description' => 'Remove a link between two objects in a relationship (persisted).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'from' => [ 'type' => 'integer' ], 'to' => [ 'type' => 'integer' ] ], 'required' => [ 'id', 'from', 'to' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                try {
                    MB_Relationships_API::delete( (int) $input['from'], (int) $input['to'], (string) $input['id'] );
                    return [ 'success' => true, 'message' => 'Objects disconnected.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Disconnect failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
        wp_register_ability( 'atarim/metabox-list-connected-objects', [
            'label' => 'List Connected Meta Box Objects', 'category' => 'atarim',
            'description' => 'List objects connected to a given object in a relationship. Provide the relationship id, a direction (from|to), and the source object id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'direction' => [ 'type' => 'string', 'enum' => [ 'from', 'to' ], 'default' => 'from' ], 'object_id' => [ 'type' => 'integer' ] ], 'required' => [ 'id', 'object_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'connected' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $direction = isset( $input['direction'] ) ? (string) $input['direction'] : 'from';
                try {
                    $args = [ 'id' => (string) $input['id'], $direction => (int) $input['object_id'] ];
                    $connected = MB_Relationships_API::get_connected( $args );
                    $ids = [];
                    if ( is_array( $connected ) || is_object( $connected ) ) {
                        foreach ( $connected as $obj ) {
                            if ( is_object( $obj ) && isset( $obj->ID ) ) { $ids[] = (int) $obj->ID; }
                            elseif ( is_numeric( $obj ) ) { $ids[] = (int) $obj; }
                        }
                    }
                    return [ 'success' => true, 'connected' => $ids, 'message' => sprintf( '%d connected object(s).', count( $ids ) ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Lookup failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
