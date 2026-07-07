<?php
/**
 * Mosaic — Advanced MCP abilities (the "pro" file).
 *
 * Pass A (here): reads + direct-write edits/deletes/assigns/theme-settings for
 * collections, templates, themes, utility classes, variables, and template
 * assigns. Pass B (appended later): the 4 facade-driven creates
 * (create-template/utility-class/collection/variable) that must go through
 * Mosaic's own MResource framework.
 *
 * RISK NOTE: every write is raw $wpdb against Mosaic's third-party schema —
 * version-fragile, bypasses parts of Mosaic's integrity/cache logic, NOT
 * runtime-tested. Edits use Mosaic's optimistic-concurrency revision guard.
 * Deletes are soft (status='delete'). Validate live before fleet use.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Mosaic_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Mosaic_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Mosaic_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_mosaic_is_available() ) {
            return;
        }
        $this->register_collections();
        $this->register_templates();
        $this->register_themes();
        $this->register_theme_settings();
        $this->register_utility_classes();
        $this->register_variables();
        $this->register_template_assigns();
        $this->register_creates();
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
    private function site_can() { return current_user_can( 'manage_options' ); }

    /** Update an entity's data blob via a mutator($data)->$data, with Mosaic's revision guard. */
    public function edit_data_blob( $entity, $id, $mutator ) {
        global $wpdb;
        $row = AVCF_Mosaic_Helpers::load( $entity, $id );
        if ( $row === null || ( $row['status'] ?? '' ) !== 'publish' ) { return [ 'err' => 'Not found (or not published).' ]; }
        $old_rev = isset( $row['revision'] ) ? (string) $row['revision'] : '';
        $data = AVCF_Mosaic_Helpers::decode_data( isset( $row['data'] ) ? $row['data'] : null );
        $data = call_user_func( $mutator, $data );
        if ( AVCF_Mosaic_Helpers::array_depth( $data ) > AVCF_Mosaic_Helpers::BLOB_MAX_DEPTH ) { return [ 'err' => 'data nesting too deep.' ]; }
        $ok = $wpdb->update(
            AVCF_Mosaic_Helpers::table( $entity ),
            [ 'data' => AVCF_Mosaic_Helpers::encode_data( $data ), 'revision' => AVCF_Mosaic_Helpers::uuid() ],
            [ 'ID' => $id, 'status' => 'publish', 'revision' => $old_rev ],
            [ '%s', '%s' ], [ '%s', '%s', '%s' ]
        );
        if ( $ok === false ) { return [ 'err' => 'Update failed.' ]; }
        if ( (int) $ok === 0 ) { return [ 'err' => 'Concurrent modification — the row changed since it was read. Re-read and retry.' ]; }
        return [ 'ok' => true ];
    }

    /** Soft-delete a row (status='delete'). */
    public function soft_delete( $entity, $id ) {
        global $wpdb;
        $row = AVCF_Mosaic_Helpers::load( $entity, $id );
        if ( $row === null || ( $row['status'] ?? '' ) !== 'publish' ) { return false; }
        $ok = $wpdb->update( AVCF_Mosaic_Helpers::table( $entity ), [ 'status' => 'delete' ], [ 'ID' => $id ], [ '%s' ], [ '%s' ] );
        return $ok !== false;
    }

    private function theme_filter( $input ) {
        $f = [];
        if ( isset( $input['theme_id'] ) && $input['theme_id'] !== '' ) { $f['themeID'] = (string) $input['theme_id']; }
        return $f;
    }

    /* --------------------------- collections --------------------------- */

    private function register_collections() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-list-collections', [
            'label' => 'List Mosaic Collections', 'category' => 'atarim',
            'description' => 'List Mosaic variable collections: { id, theme_id, name, parent_id, parent_type, ordering, status }. Filter by theme_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'collections' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $f = $self->theme_filter_public( $input );
                $rows = AVCF_Mosaic_Helpers::select( 'collections', $f, isset( $input['limit'] ) ? (int) $input['limit'] : 100, 0 );
                $out = []; foreach ( $rows as $r ) { $out[] = AVCF_Mosaic_Helpers::map_collection_row( $r ); }
                return [ 'success' => true, 'collections' => $out, 'total' => AVCF_Mosaic_Helpers::count( 'collections', $f ), 'message' => sprintf( '%d collection(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-get-collection', [
            'label' => 'Get Mosaic Collection', 'category' => 'atarim',
            'description' => 'Get one collection by id (full decoded data).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'collection' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $row = AVCF_Mosaic_Helpers::load( 'collections', (string) $input['id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Collection not found.' ]; }
                return [ 'success' => true, 'collection' => AVCF_Mosaic_Helpers::map_collection_row( $row ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-edit-collection', [
            'label' => 'Edit Mosaic Collection', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Rename a collection by id (updates data.name). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $name = (string) $input['name'];
                $r = $self->edit_data_blob( 'collections', (string) $input['id'], function( $data ) use ( $name ) { $data['name'] = $name; return $data; } );
                return isset( $r['err'] ) ? [ 'success' => false, 'message' => $r['err'] ] : [ 'success' => true, 'message' => 'Collection updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-delete-collection', [
            'label' => 'Delete Mosaic Collection', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Soft-delete a collection by id (status=delete). Dry run unless confirm:true. Variables under it are not auto-removed.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                return $self->soft_delete( 'collections', (string) $input['id'] ) ? [ 'success' => true, 'message' => 'Collection soft-deleted.' ] : [ 'success' => false, 'message' => 'Not found or delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- templates --------------------------- */

    private function register_templates() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-list-templates', [
            'label' => 'List Mosaic Templates', 'category' => 'atarim',
            'description' => 'List Mosaic templates: { id, name, theme_id, master_id, assign, path, ordering, status }. Filter by theme_id. Read element content via get-element-tree (document_type=template).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'templates' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $f = $self->theme_filter_public( $input );
                $rows = AVCF_Mosaic_Helpers::select( 'templates', $f, isset( $input['limit'] ) ? (int) $input['limit'] : 100, 0 );
                $out = []; foreach ( $rows as $r ) { $out[] = AVCF_Mosaic_Helpers::map_template_row( $r ); }
                return [ 'success' => true, 'templates' => $out, 'total' => AVCF_Mosaic_Helpers::count( 'templates', $f ), 'message' => sprintf( '%d template(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-get-template', [
            'label' => 'Get Mosaic Template', 'category' => 'atarim',
            'description' => 'Get one template by id, including its decoded conditions JSON. The element tree lives separately (get-element-tree).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'template' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $row = AVCF_Mosaic_Helpers::load( 'templates', (string) $input['id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Template not found.' ]; }
                return [ 'success' => true, 'template' => AVCF_Mosaic_Helpers::present_template( $row ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-edit-template', [
            'label' => 'Edit Mosaic Template', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Edit a template by id: name (column) and/or conditions (JSON column, replaced). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'conditions' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                if ( ! isset( $input['name'] ) && ! isset( $input['conditions'] ) ) { return [ 'success' => false, 'message' => 'Provide name and/or conditions.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $row = AVCF_Mosaic_Helpers::load( 'templates', (string) $input['id'] );
                if ( $row === null || ( $row['status'] ?? '' ) !== 'publish' ) { return [ 'success' => false, 'message' => 'Template not found.' ]; }
                $set = [ 'revision' => AVCF_Mosaic_Helpers::uuid() ]; $fmt = [ '%s' ];
                if ( isset( $input['name'] ) ) { $set['name'] = (string) $input['name']; $fmt[] = '%s'; }
                if ( isset( $input['conditions'] ) ) { $set['conditions'] = AVCF_Mosaic_Helpers::encode_data( is_array( $input['conditions'] ) ? $input['conditions'] : [] ); $fmt[] = '%s'; }
                $ok = $wpdb->update( AVCF_Mosaic_Helpers::table( 'templates' ), $set, [ 'ID' => (string) $input['id'], 'status' => 'publish' ], $fmt, [ '%s', '%s' ] );
                return $ok === false ? [ 'success' => false, 'message' => 'Update failed.' ] : [ 'success' => true, 'message' => 'Template updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-delete-template', [
            'label' => 'Delete Mosaic Template', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Soft-delete a template by id (status=delete). Its nodes remain in the table but are hidden. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                return $self->soft_delete( 'templates', (string) $input['id'] ) ? [ 'success' => true, 'message' => 'Template soft-deleted.' ] : [ 'success' => false, 'message' => 'Not found or delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ themes ----------------------------- */

    private function register_themes() {
        wp_register_ability( 'atarim/mosaic-list-themes', [
            'label' => 'List Mosaic Themes', 'category' => 'atarim',
            'description' => 'List Mosaic themes: { id, name, version, revision, ordering, status, modified_gmt }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'themes' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $rows = AVCF_Mosaic_Helpers::select( 'themes', [], isset( $input['limit'] ) ? (int) $input['limit'] : 100, 0 );
                $out = []; foreach ( $rows as $r ) { $out[] = AVCF_Mosaic_Helpers::map_theme_row( $r ); }
                return [ 'success' => true, 'themes' => $out, 'total' => AVCF_Mosaic_Helpers::count( 'themes', [] ), 'message' => sprintf( '%d theme(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-get-theme', [
            'label' => 'Get Mosaic Theme', 'category' => 'atarim',
            'description' => 'Get one theme by id, including its decoded data blob (settings/metadata).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'theme' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $row = AVCF_Mosaic_Helpers::load( 'themes', (string) $input['id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Theme not found.' ]; }
                return [ 'success' => true, 'theme' => AVCF_Mosaic_Helpers::present_theme( $row ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- theme settings ------------------------- */

    private function register_theme_settings() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-get-theme-settings', [
            'label' => 'Get Mosaic Theme Settings', 'category' => 'atarim',
            'description' => 'Read a theme\'s settings (the theme\'s data blob).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ] ], 'required' => [ 'theme_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $row = AVCF_Mosaic_Helpers::load( 'themes', (string) $input['theme_id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Theme not found.' ]; }
                return [ 'success' => true, 'settings' => AVCF_Mosaic_Helpers::decode_data( isset( $row['data'] ) ? $row['data'] : null ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-edit-theme-settings', [
            'label' => 'Edit Mosaic Theme Settings', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Patch a theme\'s settings blob. settings is the patch; mode=merge (shallow) or replace (default merge here is replace-by-key via merge). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'mode' => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'settings' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                if ( ! is_array( $input['settings'] ) ) { return [ 'success' => false, 'message' => 'settings must be an object.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $row = AVCF_Mosaic_Helpers::load( 'themes', (string) $input['theme_id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Theme not found.' ]; }
                $mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'merge';
                $next = $mode === 'replace' ? $input['settings'] : array_merge( AVCF_Mosaic_Helpers::decode_data( isset( $row['data'] ) ? $row['data'] : null ), $input['settings'] );
                if ( AVCF_Mosaic_Helpers::array_depth( $next ) > AVCF_Mosaic_Helpers::BLOB_MAX_DEPTH ) { return [ 'success' => false, 'message' => 'settings nesting too deep.' ]; }
                $ok = $wpdb->update( AVCF_Mosaic_Helpers::table( 'themes' ), [ 'data' => AVCF_Mosaic_Helpers::encode_data( $next ), 'revision' => AVCF_Mosaic_Helpers::uuid() ], [ 'ID' => (string) $input['theme_id'] ], [ '%s', '%s' ], [ '%s' ] );
                return $ok === false ? [ 'success' => false, 'message' => 'Update failed.' ] : [ 'success' => true, 'message' => 'Theme settings updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- utility classes ------------------------ */

    private function register_utility_classes() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-list-utility-classes', [
            'label' => 'List Mosaic Utility Classes', 'category' => 'atarim',
            'description' => 'List Mosaic utility classes (compact: id, theme_id, name, css_class, ordering, status). Filter by theme_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'utility_classes' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $f = $self->theme_filter_public( $input );
                $rows = AVCF_Mosaic_Helpers::select( 'utility_classes', $f, isset( $input['limit'] ) ? (int) $input['limit'] : 100, 0 );
                $out = []; foreach ( $rows as $r ) { $out[] = AVCF_Mosaic_Helpers::map_utility_class_row( $r ); }
                return [ 'success' => true, 'utility_classes' => $out, 'total' => AVCF_Mosaic_Helpers::count( 'utility_classes', $f ), 'message' => sprintf( '%d utility class(es).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-get-utility-class', [
            'label' => 'Get Mosaic Utility Class', 'category' => 'atarim',
            'description' => 'Get one utility class by id (full decoded data, including any CSS state/breakpoint structure).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'utility_class' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $row = AVCF_Mosaic_Helpers::load( 'utility_classes', (string) $input['id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Utility class not found.' ]; }
                return [ 'success' => true, 'utility_class' => array_merge( AVCF_Mosaic_Helpers::map_utility_class_row( $row ), [ 'data' => AVCF_Mosaic_Helpers::decode_data( isset( $row['data'] ) ? $row['data'] : null ) ] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-edit-utility-class', [
            'label' => 'Edit Mosaic Utility Class', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Edit a utility class by id: name and/or css_class (updates data.name / data.cssClass). The deeper CSS rule payload is not written here. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'css_class' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! isset( $input['name'] ) && ! isset( $input['css_class'] ) ) { return [ 'success' => false, 'message' => 'Provide name and/or css_class.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $r = $self->edit_data_blob( 'utility_classes', (string) $input['id'], function( $data ) use ( $input ) {
                    if ( isset( $input['name'] ) ) { $data['name'] = (string) $input['name']; }
                    if ( isset( $input['css_class'] ) ) { $data['cssClass'] = (string) $input['css_class']; }
                    return $data;
                } );
                return isset( $r['err'] ) ? [ 'success' => false, 'message' => $r['err'] ] : [ 'success' => true, 'message' => 'Utility class updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-delete-utility-class', [
            'label' => 'Delete Mosaic Utility Class', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Soft-delete a utility class by id (status=delete). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                return $self->soft_delete( 'utility_classes', (string) $input['id'] ) ? [ 'success' => true, 'message' => 'Utility class soft-deleted.' ] : [ 'success' => false, 'message' => 'Not found or delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- variables --------------------------- */

    private function register_variables() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-list-variables', [
            'label' => 'List Mosaic Variables', 'category' => 'atarim',
            'description' => 'List Mosaic variables (compact: id, theme_id, collection_id, name, custom_property, ordering, status). Filter by theme_id and/or collection_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'collection_id' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'variables' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $f = $self->theme_filter_public( $input );
                if ( isset( $input['collection_id'] ) && $input['collection_id'] !== '' ) { $f['parentID'] = (string) $input['collection_id']; }
                $rows = AVCF_Mosaic_Helpers::select( 'variables', $f, isset( $input['limit'] ) ? (int) $input['limit'] : 200, 0 );
                $out = []; foreach ( $rows as $r ) { $out[] = AVCF_Mosaic_Helpers::map_variable_row( $r ); }
                return [ 'success' => true, 'variables' => $out, 'total' => AVCF_Mosaic_Helpers::count( 'variables', $f ), 'message' => sprintf( '%d variable(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-get-variable', [
            'label' => 'Get Mosaic Variable', 'category' => 'atarim',
            'description' => 'Get one variable by id (full decoded data, including value(s) per mode/breakpoint).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'variable' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $row = AVCF_Mosaic_Helpers::load( 'variables', (string) $input['id'] );
                if ( $row === null ) { return [ 'success' => false, 'message' => 'Variable not found.' ]; }
                return [ 'success' => true, 'variable' => array_merge( AVCF_Mosaic_Helpers::map_variable_row( $row ), [ 'data' => AVCF_Mosaic_Helpers::decode_data( isset( $row['data'] ) ? $row['data'] : null ) ] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-edit-variable', [
            'label' => 'Edit Mosaic Variable', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Edit a variable by id: name and/or custom_property (updates data.name / data.customProperty), plus an optional data patch (shallow-merged into the blob for value edits). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'custom_property' => [ 'type' => 'string' ], 'data' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! isset( $input['name'] ) && ! isset( $input['custom_property'] ) && ! isset( $input['data'] ) ) { return [ 'success' => false, 'message' => 'Provide name, custom_property, and/or data.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $r = $self->edit_data_blob( 'variables', (string) $input['id'], function( $data ) use ( $input ) {
                    if ( isset( $input['data'] ) && is_array( $input['data'] ) ) { $data = array_merge( $data, $input['data'] ); }
                    if ( isset( $input['name'] ) ) { $data['name'] = (string) $input['name']; }
                    if ( isset( $input['custom_property'] ) ) { $data['customProperty'] = (string) $input['custom_property']; }
                    return $data;
                } );
                return isset( $r['err'] ) ? [ 'success' => false, 'message' => $r['err'] ] : [ 'success' => true, 'message' => 'Variable updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-delete-variable', [
            'label' => 'Delete Mosaic Variable', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Soft-delete a variable by id (status=delete). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                return $self->soft_delete( 'variables', (string) $input['id'] ) ? [ 'success' => true, 'message' => 'Variable soft-deleted.' ] : [ 'success' => false, 'message' => 'Not found or delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------- template assigns ------------------------ */

    private function register_template_assigns() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-list-template-assigns', [
            'label' => 'List Mosaic Template Assigns', 'category' => 'atarim',
            'description' => 'List the template→content bindings for a theme (the template_assigns table): each is { theme_id, type, type_identifier, template_id, parent_type }. type is the WP post-type slug (e.g. post/page) and type_identifier the numeric post id. (Archive/taxonomy/404/etc. surfaces are bound via template conditions, not this table.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ] ], 'required' => [ 'theme_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'assigns' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $theme_id = (string) $input['theme_id'];
                $table = AVCF_Mosaic_Helpers::table( 'template_assigns' );
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `themeID` = %s ORDER BY `type` ASC, `typeIdentifier` ASC", $theme_id ), 'ARRAY_A' );
                $rows = is_array( $rows ) ? $rows : [];
                $out = [];
                foreach ( $rows as $r ) {
                    $out[] = [ 'theme_id' => AVCF_Mosaic_Helpers::str( $r, 'themeID' ), 'type' => AVCF_Mosaic_Helpers::str( $r, 'type' ), 'type_identifier' => AVCF_Mosaic_Helpers::str( $r, 'typeIdentifier' ), 'template_id' => AVCF_Mosaic_Helpers::str( $r, 'parentID' ), 'parent_type' => AVCF_Mosaic_Helpers::str( $r, 'parentType' ) ];
                }
                return [ 'success' => true, 'assigns' => $out, 'total' => count( $out ), 'message' => sprintf( '%d assign(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/mosaic-set-template-assign', [
            'label' => 'Set Mosaic Template Assign', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Bind a content surface (theme_id + type + type_identifier, e.g. type=page, type_identifier=42) to a template_id. Replaces any existing binding for that triple. The template must be published and belong to the theme. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ], 'type_identifier' => [ 'type' => 'string' ], 'template_id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'type', 'type_identifier', 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $theme_id = (string) $input['theme_id']; $type = (string) $input['type']; $tid = (string) $input['type_identifier']; $template_id = (string) $input['template_id'];
                if ( $theme_id === '' || $type === '' || $tid === '' || $template_id === '' ) { return [ 'success' => false, 'message' => 'theme_id, type, type_identifier, template_id are all required.' ]; }
                $tpl = AVCF_Mosaic_Helpers::load( 'templates', $template_id );
                if ( $tpl === null || ( $tpl['status'] ?? '' ) !== 'publish' ) { return [ 'success' => false, 'message' => 'Template not found or not published.' ]; }
                if ( AVCF_Mosaic_Helpers::str( $tpl, 'themeID' ) !== $theme_id ) { return [ 'success' => false, 'message' => 'template_id does not belong to theme_id.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $table = AVCF_Mosaic_Helpers::table( 'template_assigns' );
                $wpdb->delete( $table, [ 'themeID' => $theme_id, 'type' => $type, 'typeIdentifier' => $tid ], [ '%s', '%s', '%s' ] );
                $ordering = AVCF_Mosaic_Helpers::ordering_between( null, null );
                $ok = $wpdb->insert( $table, [ 'ID' => AVCF_Mosaic_Helpers::uuid(), 'themeID' => $theme_id, 'type' => $type, 'typeIdentifier' => $tid, 'parentType' => 'template', 'parentID' => $template_id, 'ordering' => is_string( $ordering ) ? $ordering : '' ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
                return $ok === false ? [ 'success' => false, 'message' => 'Insert failed.' ] : [ 'success' => true, 'message' => 'Template assign set.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-clear-template-assign', [
            'label' => 'Clear Mosaic Template Assign', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Remove the binding for a content surface (theme_id + type + type_identifier). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ], 'type_identifier' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'type', 'type_identifier' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'removed' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'removed' => 0, 'message' => 'Dry run: re-call with confirm:true.' ]; }
                $table = AVCF_Mosaic_Helpers::table( 'template_assigns' );
                $n = $wpdb->delete( $table, [ 'themeID' => (string) $input['theme_id'], 'type' => (string) $input['type'], 'typeIdentifier' => (string) $input['type_identifier'] ], [ '%s', '%s', '%s' ] );
                return [ 'success' => true, 'removed' => (int) $n, 'message' => sprintf( '%d assign(s) removed.', (int) $n ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /** public wrapper for closures */
    public function theme_filter_public( $input ) { return $this->theme_filter( $input ); }

    /* ----------------------- facade creates (pass B) ------------------- */
    /*
     * MOST EXPERIMENTAL CODE IN THE CLUSTER. These 4 creates cannot be naive
     * INSERTs — Mosaic requires new design-system entities to be wired through
     * its own MResource framework (FinishWizardEditorInstance -> managers ->
     * restore + factory -> pushToDB), so we drive that via reflection against
     * Mosaic Pro's internal class graph (pinned to ~1.0.3 class names). If a
     * Mosaic release renames those classes/methods, these throw a typed error
     * rather than corrupt data. NOTE: we do NOT reproduce Mosaic's orphan-chain
     * pre-clean (a 120-line hard-delete routine); on a theme with orphaned
     * MResource chains, create-template may fail — open the Mosaic admin once
     * to let it self-heal, then retry. NOT runtime-tested — validate live.
     */

    private function register_creates() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/mosaic-create-collection', [
            'label' => 'Create Mosaic Collection', 'category' => 'atarim',
            'description' => 'HIGH-RISK / EXPERIMENTAL (drives Mosaic\'s MResource framework). Create a variable collection in a theme. theme_id + name required. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run (EXPERIMENTAL facade write): re-call with confirm:true.' ]; }
                return $self->facade_create_collection( (string) $input['theme_id'], (string) $input['name'] );
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-create-utility-class', [
            'label' => 'Create Mosaic Utility Class', 'category' => 'atarim',
            'description' => 'HIGH-RISK / EXPERIMENTAL (drives Mosaic\'s MResource framework). Create a utility class in a theme. theme_id + name + css_class required. This declares the class identity only — the CSS rule payload is added in Mosaic\'s editor. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'css_class' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'name', 'css_class' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run (EXPERIMENTAL facade write): re-call with confirm:true.' ]; }
                return $self->facade_create_utility_class( (string) $input['theme_id'], (string) $input['name'], (string) $input['css_class'] );
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-create-variable', [
            'label' => 'Create Mosaic Variable', 'category' => 'atarim',
            'description' => 'HIGH-RISK / EXPERIMENTAL (drives Mosaic\'s MResource framework). Create a design-token variable under a collection. theme_id + collection_id + name + custom_property + type required (type e.g. "color"). The per-skin/per-mode value defaults to whatever Mosaic seeds for that type. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'collection_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'custom_property' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'collection_id', 'name', 'custom_property', 'type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run (EXPERIMENTAL facade write): re-call with confirm:true.' ]; }
                return $self->facade_create_variable( (string) $input['theme_id'], (string) $input['collection_id'], (string) $input['name'], (string) $input['custom_property'], (string) $input['type'] );
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/mosaic-create-template', [
            'label' => 'Create Mosaic Template', 'category' => 'atarim',
            'description' => 'HIGH-RISK / EXPERIMENTAL (drives Mosaic\'s MResource framework). Create a renderable template (wired to the theme master + a seeded root template-internal node). theme_id + name required; assign + path optional; conditions optional (written to the conditions column after create). Requires a published master in the theme. May fail on themes with orphaned MResource chains (open Mosaic admin once to self-heal, then retry). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'assign' => [ 'type' => 'string' ], 'path' => [ 'type' => 'string' ], 'conditions' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'theme_id', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run (EXPERIMENTAL facade write): re-call with confirm:true.' ]; }
                return $self->facade_create_template(
                    (string) $input['theme_id'], (string) $input['name'],
                    isset( $input['assign'] ) ? (string) $input['assign'] : '',
                    isset( $input['path'] ) ? (string) $input['path'] : '',
                    isset( $input['conditions'] ) && is_array( $input['conditions'] ) ? $input['conditions'] : []
                );
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----- facade reflection (public so closures can reach them) ----- */

    private function classes_present( $classes ) {
        foreach ( $classes as $c ) { if ( ! class_exists( $c ) ) { return $c; } }
        return null;
    }

    public function facade_create_collection( $theme_id, $name ) {
        $miss = $this->classes_present( [ '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance', '\Mosaic\EditorInstance\MResources\Collections\CollectionsMResourceManager' ] );
        if ( $miss !== null ) { return [ 'success' => false, 'message' => 'Mosaic class missing: ' . $miss . ' (needs Mosaic Pro 1.0.3+).' ]; }
        try {
            $fqn = '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance';
            $editor = new $fqn( $theme_id );
            call_user_func( [ $editor, 'load' ] );
            $mgr = call_user_func( [ $editor, 'getMResourceManager' ], 'collection' );
            if ( $mgr === null ) { return [ 'success' => false, 'message' => 'Theme does not expose a collection MResource manager.' ]; }
            $id = AVCF_Mosaic_Helpers::uuid();
            call_user_func( [ $mgr, 'restoreCollectionMResource' ], (object) [ 'ID' => $id, 'data' => (object) [ 'name' => $name ] ] );
            call_user_func( [ $editor, 'pushToDB' ] );
            return [ 'success' => true, 'id' => $id, 'message' => 'Collection created.' ];
        } catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Mosaic facade create-collection failed: ' . $e->getMessage() ]; }
    }

    public function facade_create_utility_class( $theme_id, $name, $css_class ) {
        $miss = $this->classes_present( [ '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance', '\Mosaic\EditorInstance\MResources\UtilityClasses\UtilityClassesMResourceManager' ] );
        if ( $miss !== null ) { return [ 'success' => false, 'message' => 'Mosaic class missing: ' . $miss . ' (needs Mosaic Pro 1.0.3+).' ]; }
        try {
            $fqn = '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance';
            $editor = new $fqn( $theme_id );
            call_user_func( [ $editor, 'load' ] );
            $mgr = call_user_func( [ $editor, 'getMResourceManager' ], 'utilityClass' );
            if ( $mgr === null ) { return [ 'success' => false, 'message' => 'Theme does not expose a utilityClass MResource manager.' ]; }
            $id = AVCF_Mosaic_Helpers::uuid();
            call_user_func( [ $mgr, 'restoreUtilityClassMResource' ], (object) [
                'ID' => $id,
                'data' => (object) [ 'name' => $name, 'cssClass' => $css_class, 'states' => new \stdClass(), 'localStates' => [], 'interactions' => [], 'favoredElementClasses' => [] ],
            ] );
            call_user_func( [ $editor, 'pushToDB' ] );
            return [ 'success' => true, 'id' => $id, 'message' => 'Utility class created.' ];
        } catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Mosaic facade create-utility-class failed: ' . $e->getMessage() ]; }
    }

    public function facade_create_variable( $theme_id, $collection_id, $name, $custom_property, $type ) {
        $miss = $this->classes_present( [ '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance', '\Mosaic\EditorInstance\MResources\CollectionVariable\CollectionVariablesMResourceManager' ] );
        if ( $miss !== null ) { return [ 'success' => false, 'message' => 'Mosaic class missing: ' . $miss . ' (needs Mosaic Pro 1.0.3+).' ]; }
        try {
            $fqn = '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance';
            $editor = new $fqn( $theme_id );
            call_user_func( [ $editor, 'load' ] );
            $mgr = call_user_func( [ $editor, 'getMResourceManager' ], 'collectionVariable' );
            if ( $mgr === null ) { return [ 'success' => false, 'message' => 'Theme does not expose a collectionVariable MResource manager.' ]; }
            $id = AVCF_Mosaic_Helpers::uuid();
            $mr = call_user_func( [ $mgr, 'restoreCollectionVariableMResource' ], (object) [
                'ID' => $id, 'parentType' => 'collection', 'parentID' => $collection_id,
                'data' => (object) [ 'type' => $type, 'name' => $name, 'customProperty' => $custom_property, 'modesData' => new \stdClass(), 'skinsData' => new \stdClass(), 'propertyGroups' => [] ],
            ] );
            if ( $mr === null ) { return [ 'success' => false, 'message' => sprintf( 'Mosaic refused to create the variable — collection "%s" missing/deleted, or type "%s" unsupported.', $collection_id, $type ) ]; }
            call_user_func( [ $editor, 'pushToDB' ] );
            return [ 'success' => true, 'id' => $id, 'message' => 'Variable created.' ];
        } catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Mosaic facade create-variable failed: ' . $e->getMessage() ]; }
    }

    public function facade_create_template( $theme_id, $name, $assign, $path, $conditions ) {
        global $wpdb;
        $miss = $this->classes_present( [
            '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance',
            '\Mosaic\EditorInstance\MResources\Node\NodeMResourceInserterHelper',
            '\Mosaic\Common\FractionalIndex',
            '\Mosaic\Database\MosaicDB',
        ] );
        if ( $miss !== null ) { return [ 'success' => false, 'message' => 'Mosaic class missing: ' . $miss . ' (template create needs Mosaic Pro 1.0.3+ with the wizard module).' ]; }
        // Gate: a published master must exist (Mosaic's getFirstMasterMResource doesn't filter soft-deleted).
        $masters_tbl = AVCF_Mosaic_Helpers::table( 'masters' );
        $master_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$masters_tbl}` WHERE `themeID` = %s AND `status` = 'publish'", $theme_id ) );
        if ( $master_count === 0 ) { return [ 'success' => false, 'message' => 'Theme has no published master. Open the Mosaic admin once to seed the default master, then retry.' ]; }
        try {
            $editor_fqn = '\Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance';
            $inserter_fqn = '\Mosaic\EditorInstance\MResources\Node\NodeMResourceInserterHelper';
            $editor = new $editor_fqn( $theme_id );
            call_user_func( [ $editor, 'load' ] );
            $master_mgr = call_user_func( [ $editor, 'getMasterMResourceManager' ] );
            $master = call_user_func( [ $master_mgr, 'getFirstMasterMResource' ] );
            if ( $master === null ) { return [ 'success' => false, 'message' => 'Theme has no master MResource.' ]; }
            $template_id = AVCF_Mosaic_Helpers::uuid();
            $tpl_mgr = call_user_func( [ $editor, 'getTemplateMResourceManager' ] );
            $record = call_user_func( [ $tpl_mgr, 'createRevisionRecord' ], (object) [
                'ID' => $template_id, 'name' => $name, 'masterID' => call_user_func( [ $master, 'getID' ] ),
                'assign' => $assign, 'path' => $path, 'ordering' => call_user_func( [ $tpl_mgr, 'generateLastOrderingForTemplate' ] ),
            ], true );
            $factory = call_user_func( [ $tpl_mgr, 'getFactory' ] );
            $tpl_mr = call_user_func( [ $factory, 'create' ], $record, false );
            call_user_func( [ $tpl_mgr, 'addMResource' ], $tpl_mr );
            call_user_func( [ $tpl_mr, 'initSync' ] );
            // Seed the required root template-internal node.
            call_user_func( [ $inserter_fqn, 'createNodeMResource' ], call_user_func( [ $tpl_mr, 'getNodeMResourceManager' ] ), (object) [
                'ID' => AVCF_Mosaic_Helpers::uuid(),
                'parentType' => call_user_func( [ $tpl_mr, 'getMResourceType' ] ),
                'parentID' => call_user_func( [ $tpl_mr, 'getID' ] ),
                'ordering' => call_user_func( [ $tpl_mr, 'generateLastOrderingForNode' ] ),
                'version' => '', 'type' => 'template-internal', 'data' => new \stdClass(),
            ] );
            call_user_func( [ $editor, 'pushToDB' ] );
            // The facade does not write the conditions column — patch it if provided.
            if ( $conditions !== [] ) {
                $wpdb->update( AVCF_Mosaic_Helpers::table( 'templates' ), [ 'conditions' => AVCF_Mosaic_Helpers::encode_data( $conditions ) ], [ 'ID' => $template_id ], [ '%s' ], [ '%s' ] );
            }
            return [ 'success' => true, 'id' => $template_id, 'message' => 'Template created.' ];
        } catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Mosaic facade create-template failed: ' . $e->getMessage() ]; }
    }
}
