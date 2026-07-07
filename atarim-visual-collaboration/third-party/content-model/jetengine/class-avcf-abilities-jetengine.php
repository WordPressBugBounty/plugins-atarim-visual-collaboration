<?php
/**
 * JetEngine — MCP abilities (schema/authoring + CCT records).
 *
 * Structure layer (CCT types, meta boxes, options pages) plus CCT RECORDS —
 * the latter included because JetEngine CCT records live in dedicated custom
 * tables (wp_jet_cct_<slug>) that the generic metadata abilities cannot reach,
 * so they are a genuine gap rather than a duplicate.
 *
 * Confidence (untested against live JetEngine here):
 *   SOLID    — reads (list/get) for CCT types, meta boxes, options pages.
 *   GOOD     — CCT record list/get/create/edit/delete via Factory->get_db(),
 *              CONTINGENT on resolving the type Factory (intricate internal).
 *   FLAGGED  — authoring of CCT types / meta boxes / options pages goes through
 *              JetEngine's own data layer (create_item/update_item/delete_item),
 *              which is architecturally correct but unverified; CCT type
 *              create/delete also perform table DDL. Validate on a live install.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_JetEngine extends AVCF_Abilities_Base {

    /** @var AVCF_JetEngine_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetEngine_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_je_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_ccts();
        $this->register_cct_records();
        $this->register_data_store( 'meta-box', [ 'list' => 'meta-boxes', 'one' => 'meta-box', 'One' => 'Meta Box', 'plural' => 'meta boxes' ], 'meta_boxes_data', 'avcf_je_has_meta_boxes' );
        $this->register_data_store( 'options-page', [ 'list' => 'options-pages', 'one' => 'options-page', 'One' => 'Options Page', 'plural' => 'options pages' ], 'options_pages_data', 'avcf_je_has_options_pages' );
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

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/jetengine-check-setup', [
            'label' => 'Check JetEngine Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports JetEngine presence/version and which modules are active: custom content types, meta boxes, options pages.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_je_is_available(), 'version' => $d->avcf_je_version(), 'modules' => [
                    'custom_content_types' => $d->avcf_je_has_cct(),
                    'meta_boxes'           => $d->avcf_je_has_meta_boxes(),
                    'options_pages'        => $d->avcf_je_has_options_pages(),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------------ CCTs ------------------------------- */

    private function register_ccts() {
        $self = $this; $d = $this->detector; $std = $this->std_out();
        $guard = function() use ( $d ) {
            if ( ! $d->avcf_je_has_cct() ) { return [ 'success' => false, 'message' => 'JetEngine Custom Content Types module is not active.' ]; }
            return null;
        };

        wp_register_ability( 'atarim/jetengine-list-ccts', [
            'label' => 'List JetEngine CCTs', 'category' => 'atarim',
            'description' => 'List JetEngine Custom Content Types (slug, name, fields).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'ccts' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $out = [];
                foreach ( AVCF_JetEngine_Helpers::store_items( AVCF_JetEngine_Helpers::cct_data() ) as $item ) { $out[] = AVCF_JetEngine_Helpers::shape_cct( $item ); }
                return [ 'success' => true, 'ccts' => $out, 'message' => sprintf( '%d CCT(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/jetengine-get-cct', [
            'label' => 'Get JetEngine CCT', 'category' => 'atarim',
            'description' => 'Get a CCT definition by slug.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ] ], 'required' => [ 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'cct' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $slug = (string) $input['slug'];
                foreach ( AVCF_JetEngine_Helpers::store_items( AVCF_JetEngine_Helpers::cct_data() ) as $item ) {
                    $a = (array) $item;
                    if ( isset( $a['slug'] ) && $a['slug'] === $slug ) { return [ 'success' => true, 'cct' => AVCF_JetEngine_Helpers::shape_cct( $item ), 'message' => 'OK.' ]; }
                }
                return [ 'success' => false, 'message' => sprintf( 'CCT "%s" not found.', $slug ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        $note = ' BEST-EFFORT: routes through JetEngine\'s CCT data layer (which also performs table DDL); validate on a live install.';
        wp_register_ability( 'atarim/jetengine-create-cct', [
            'label' => 'Create JetEngine CCT', 'category' => 'atarim',
            'description' => 'Create a Custom Content Type and its table (wp_jet_cct_<slug>).' . $note . ' Provide slug, name, and fields (array of field defs); args is optional extra config.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'array' ], 'args' => [ 'type' => 'object' ] ], 'required' => [ 'slug', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $item = [ 'slug' => sanitize_key( (string) $input['slug'] ), 'name' => (string) $input['name'], 'meta_fields' => isset( $input['fields'] ) ? (array) $input['fields'] : [], 'args' => isset( $input['args'] ) ? (array) $input['args'] : [] ];
                $r = AVCF_JetEngine_Helpers::store_create( AVCF_JetEngine_Helpers::cct_data(), $item );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'CCT created (best-effort — verify the table and type in JetEngine).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/jetengine-edit-cct', [
            'label' => 'Edit JetEngine CCT', 'category' => 'atarim',
            'description' => 'Update a CCT.' . $note . ' Note: JetEngine restricts schema migrations (field rename / SQL-type change / key-field toggle are rejected; add/remove field is allowed).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'array' ], 'args' => [ 'type' => 'object' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $item = [ 'id' => $input['id'] ];
                foreach ( [ 'slug', 'name', 'args' ] as $k ) { if ( isset( $input[ $k ] ) ) { $item[ $k ] = $input[ $k ]; } }
                if ( isset( $input['fields'] ) ) { $item['meta_fields'] = (array) $input['fields']; }
                $r = AVCF_JetEngine_Helpers::store_update( AVCF_JetEngine_Helpers::cct_data(), $item );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'CCT updated (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/jetengine-delete-cct', [
            'label' => 'Delete JetEngine CCT', 'category' => 'atarim',
            'description' => 'Delete a CCT and drop its table. Dry run unless confirm:true. This destroys all records in the type.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => 'Dry run: would delete this CCT and DROP its table (all records lost). Re-call with confirm:true.' ]; }
                $r = AVCF_JetEngine_Helpers::store_delete( AVCF_JetEngine_Helpers::cct_data(), $input['id'] );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => 'CCT deleted (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- CCT records --------------------------- */

    private function register_cct_records() {
        $self = $this; $d = $this->detector; $std = $this->std_out();
        $guard = function( $slug ) use ( $d ) {
            if ( ! $d->avcf_je_has_cct() ) { return [ 'success' => false, 'message' => 'JetEngine CCT module is not active.' ]; }
            $db = AVCF_JetEngine_Helpers::cct_db( $slug );
            if ( ! $db ) { return [ 'success' => false, 'message' => sprintf( 'Could not resolve the record table for CCT "%s" (type missing, or Factory not resolvable on this build).', $slug ) ]; }
            return $db;
        };

        wp_register_ability( 'atarim/jetengine-list-cct-records', [
            'label' => 'List JetEngine CCT Records', 'category' => 'atarim',
            'description' => 'List records in a CCT. where is a column=>value filter map; supports limit and offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'where' => [ 'type' => 'object' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'records' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $db = $guard( (string) $input['slug'] );
                if ( is_array( $db ) ) { return $db; }
                $where  = isset( $input['where'] ) && is_array( $input['where'] ) ? $input['where'] : [];
                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
                $offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
                try {
                    $rows  = method_exists( $db, 'query' ) ? $db->query( $where, $limit, $offset ) : [];
                    $total = method_exists( $db, 'count' ) ? (int) $db->count( $where ) : count( (array) $rows );
                    return [ 'success' => true, 'records' => array_map( function( $r ) { return (array) $r; }, (array) $rows ), 'total' => $total, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Query failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/jetengine-get-cct-record', [
            'label' => 'Get JetEngine CCT Record', 'category' => 'atarim',
            'description' => 'Get a single CCT record by its _ID.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'slug', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'record' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $db = $guard( (string) $input['slug'] );
                if ( is_array( $db ) ) { return $db; }
                try {
                    $rows = method_exists( $db, 'query' ) ? $db->query( [ '_ID' => (int) $input['id'] ], 1, 0 ) : [];
                    $rows = (array) $rows;
                    if ( empty( $rows ) ) { return [ 'success' => false, 'message' => sprintf( 'Record %d not found.', (int) $input['id'] ) ]; }
                    return [ 'success' => true, 'record' => (array) $rows[0], 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Query failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/jetengine-create-cct-record', [
            'label' => 'Create JetEngine CCT Record', 'category' => 'atarim',
            'description' => 'Insert a record into a CCT. values is a column=>value map matching the type\'s fields.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'slug', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $db = $guard( (string) $input['slug'] );
                if ( is_array( $db ) ) { return $db; }
                $values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : [];
                if ( empty( $values ) ) { return [ 'success' => false, 'message' => 'values must be a non-empty object.' ]; }
                try {
                    $new_id = method_exists( $db, 'insert' ) ? $db->insert( $values ) : null;
                    return [ 'success' => true, 'id' => (int) $new_id, 'message' => 'Record created.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Insert failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/jetengine-edit-cct-record', [
            'label' => 'Edit JetEngine CCT Record', 'category' => 'atarim',
            'description' => 'Update a CCT record by _ID. values is the column=>value map to write.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'slug', 'id', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $db = $guard( (string) $input['slug'] );
                if ( is_array( $db ) ) { return $db; }
                $values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : [];
                if ( empty( $values ) ) { return [ 'success' => false, 'message' => 'values must be a non-empty object.' ]; }
                try {
                    method_exists( $db, 'update' ) ? $db->update( $values, [ '_ID' => (int) $input['id'] ] ) : null;
                    return [ 'success' => true, 'message' => sprintf( 'Record %d updated.', (int) $input['id'] ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/jetengine-delete-cct-record', [
            'label' => 'Delete JetEngine CCT Record', 'category' => 'atarim',
            'description' => 'Delete a CCT record by _ID. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'slug', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $db = $guard( (string) $input['slug'] );
                if ( is_array( $db ) ) { return $db; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete record %d. Re-call with confirm:true.', (int) $input['id'] ) ]; }
                try {
                    method_exists( $db, 'delete' ) ? $db->delete( [ '_ID' => (int) $input['id'] ] ) : null;
                    return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Record %d deleted.', (int) $input['id'] ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Delete failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------- generic data-store registrar (MB/OP) ------------- */

    private function register_data_store( $key, $labels, $data_method, $detector_check ) {
        $self = $this; $d = $this->detector; $std = $this->std_out();
        $store = function() use ( $data_method ) { return call_user_func( [ 'AVCF_JetEngine_Helpers', $data_method ] ); };
        $guard = function() use ( $d, $detector_check, $labels ) {
            if ( ! call_user_func( [ $d, $detector_check ] ) ) { return [ 'success' => false, 'message' => sprintf( 'JetEngine %s are not active.', $labels['plural'] ) ]; }
            return null;
        };
        $note = sprintf( ' BEST-EFFORT: routes through JetEngine\'s %s data layer; validate on a live install.', $labels['one'] );

        wp_register_ability( 'atarim/jetengine-list-' . $labels['list'], [
            'label' => 'List JetEngine ' . $labels['One'] . 's', 'category' => 'atarim',
            'description' => sprintf( 'List JetEngine %s.', $labels['plural'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'items' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $store ) {
                $g = $guard(); if ( $g ) { return $g; }
                $items = AVCF_JetEngine_Helpers::store_items( $store() );
                return [ 'success' => true, 'items' => array_map( function( $i ) { return (array) $i; }, $items ), 'message' => sprintf( '%d item(s).', count( $items ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/jetengine-get-' . $labels['one'], [
            'label' => 'Get JetEngine ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Get one JetEngine %s by id.', $labels['one'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'item' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $store, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                $id = (string) $input['id'];
                foreach ( AVCF_JetEngine_Helpers::store_items( $store() ) as $item ) {
                    $a = (array) $item;
                    if ( ( isset( $a['id'] ) && (string) $a['id'] === $id ) || ( isset( $a['slug'] ) && (string) $a['slug'] === $id ) ) {
                        return [ 'success' => true, 'item' => $a, 'message' => 'OK.' ];
                    }
                }
                return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/jetengine-create-' . $labels['one'], [
            'label' => 'Create JetEngine ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Create a JetEngine %s.%s item is the full definition object as JetEngine stores it.', $labels['one'], $note ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'object' ] ], 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $store ) {
                $g = $guard(); if ( $g ) { return $g; }
                $r = AVCF_JetEngine_Helpers::store_create( $store(), (array) $input['item'] );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Created (best-effort — verify in JetEngine).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/jetengine-edit-' . $labels['one'], [
            'label' => 'Edit JetEngine ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Update a JetEngine %s.%s item must include its id.', $labels['one'], $note ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'object' ] ], 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $store ) {
                $g = $guard(); if ( $g ) { return $g; }
                $r = AVCF_JetEngine_Helpers::store_update( $store(), (array) $input['item'] );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Updated (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/jetengine-delete-' . $labels['one'], [
            'label' => 'Delete JetEngine ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Delete a JetEngine %s by id. Dry run unless confirm:true.', $labels['one'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $store, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete %s "%s". Re-call with confirm:true.', $labels['one'], $input['id'] ) ]; }
                $r = AVCF_JetEngine_Helpers::store_delete( $store(), $input['id'] );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => 'Deleted (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
