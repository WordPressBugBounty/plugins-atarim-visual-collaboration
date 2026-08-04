<?php
/**
 * ASE (Admin and Site Enhancements) — MCP abilities (schema/authoring layer).
 *
 * Structure layer only; field VALUES are covered by the generic metadata
 * abilities. Definitions are stored as the asenha_cfgroup / asenha_cpt /
 * asenha_ctax post types (plain wp_insert_post + post meta).
 *
 * Confidence (untested against live ASE here):
 *   SOLID    — all reads; field-group create/edit/delete (clear meta keys:
 *              cfgroup_fields / cfgroup_rules / cfgroup_extras).
 *   FLAGGED  — CPT / taxonomy authoring: ASE auto-generates ~31 label meta
 *              keys from singular/plural; we store the core keys + any provided
 *              labels/settings, but the full label schema needs live validation.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_ASE extends AVCF_Abilities_Base {

    /** @var AVCF_ASE_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_ASE_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_ase_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_field_groups();
        $this->register_builder( 'asenha_cpt', 'cpt', [ 'list' => 'post-types', 'one' => 'post-type', 'One' => 'Post Type', 'plural' => 'post types' ], 'avcf_ase_has_post_types' );
        $this->register_builder( 'asenha_ctax', 'ctax', [ 'list' => 'taxonomies', 'one' => 'taxonomy', 'One' => 'Taxonomy', 'plural' => 'taxonomies' ], 'avcf_ase_has_taxonomies' );
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
        wp_register_ability( 'atarim/ase-check-setup', [
            'label' => 'Check ASE Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether ASE (custom fields / CPT / taxonomy module) is active and which features are present.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_ase_is_available(), 'version' => $d->avcf_ase_version(), 'features' => [
                    'field_groups' => $d->avcf_ase_has_field_groups(),
                    'post_types'   => $d->avcf_ase_has_post_types(),
                    'taxonomies'   => $d->avcf_ase_has_taxonomies(),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- field groups -------------------------- */

    private function register_field_groups() {
        $self = $this; $d = $this->detector; $std = $this->std_out();
        $guard = function() use ( $d ) {
            if ( ! $d->avcf_ase_has_field_groups() ) { return [ 'success' => false, 'message' => 'ASE field groups (asenha_cfgroup) are not available.' ]; }
            return null;
        };

        wp_register_ability( 'atarim/ase-list-field-groups', [
            'label' => 'List ASE Field Groups', 'category' => 'atarim',
            'description' => 'List ASE field groups (id, title, slug).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_groups' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $out = [];
                foreach ( AVCF_ASE_Helpers::list_cpt( 'asenha_cfgroup' ) as $p ) { $out[] = AVCF_ASE_Helpers::shape_post( $p ); }
                return [ 'success' => true, 'field_groups' => $out, 'message' => sprintf( '%d field group(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/ase-get-field-group', [
            'label' => 'Get ASE Field Group', 'category' => 'atarim',
            'description' => 'Get one field group by id or slug, including its fields, rules, and extras.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group' => [ 'type' => 'string' ] ], 'required' => [ 'group' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_group' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_ASE_Helpers::find_cpt( 'asenha_cfgroup', (string) $input['group'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $input['group'] ) ]; }
                return [ 'success' => true, 'field_group' => [
                    'id'     => (int) $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name,
                    'fields' => get_post_meta( $p->ID, 'cfgroup_fields', true ),
                    'rules'  => get_post_meta( $p->ID, 'cfgroup_rules', true ),
                    'extras' => get_post_meta( $p->ID, 'cfgroup_extras', true ),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/ase-create-field-group', [
            'label' => 'Create ASE Field Group', 'category' => 'atarim',
            'description' => 'Create an ASE field group. Provide title; fields (array of field defs), rules (location/placement rules), and extras are optional.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'array' ], 'rules' => [ 'type' => 'object' ], 'extras' => [ 'type' => 'object' ] ], 'required' => [ 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $meta = [
                    'cfgroup_fields' => isset( $input['fields'] ) ? (array) $input['fields'] : [],
                    'cfgroup_rules'  => isset( $input['rules'] ) ? (array) $input['rules'] : [],
                    'cfgroup_extras' => isset( $input['extras'] ) ? (array) $input['extras'] : [],
                ];
                $id = AVCF_ASE_Helpers::upsert( 'asenha_cfgroup', (string) $input['title'], $meta );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Field group created.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/ase-edit-field-group', [
            'label' => 'Edit ASE Field Group', 'category' => 'atarim',
            'description' => 'Update a field group by id/slug. Provided keys (title, fields, rules, extras) replace those parts.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'array' ], 'rules' => [ 'type' => 'object' ], 'extras' => [ 'type' => 'object' ] ], 'required' => [ 'group' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_ASE_Helpers::find_cpt( 'asenha_cfgroup', (string) $input['group'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $input['group'] ) ]; }
                $meta = [];
                if ( isset( $input['fields'] ) ) { $meta['cfgroup_fields'] = (array) $input['fields']; }
                if ( isset( $input['rules'] ) )  { $meta['cfgroup_rules'] = (array) $input['rules']; }
                if ( isset( $input['extras'] ) ) { $meta['cfgroup_extras'] = (array) $input['extras']; }
                $title = isset( $input['title'] ) ? (string) $input['title'] : $p->post_title;
                $id = AVCF_ASE_Helpers::upsert( 'asenha_cfgroup', $title, $meta, $p->ID );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Field group updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/ase-delete-field-group', [
            'label' => 'Delete ASE Field Group', 'category' => 'atarim',
            'description' => 'Delete a field group by id/slug. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'group' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_ASE_Helpers::find_cpt( 'asenha_cfgroup', (string) $input['group'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $input['group'] ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete field group "%s". Re-call with confirm:true.', $p->post_title ) ]; }
                $r = wp_delete_post( $p->ID, true );
                return $r ? [ 'success' => true, 'deleted' => true, 'message' => 'Field group deleted.' ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------- post types / taxonomies (CPT) ----------------- */

    private function register_builder( $cpt, $slug_prefix, $labels, $detector_check ) {
        $self = $this; $d = $this->detector; $std = $this->std_out();
        $slug_key = $slug_prefix . '_slug';
        $note = sprintf( ' BEST-EFFORT: stored as the "%s" post type; ASE auto-generates a full label schema from singular/plural — validate on a live install.', $cpt );

        $guard = function() use ( $d, $detector_check, $labels ) {
            if ( ! call_user_func( [ $d, $detector_check ] ) ) { return [ 'success' => false, 'message' => sprintf( 'ASE %s are not available.', $labels['plural'] ) ]; }
            return null;
        };
        $build_meta = function( $input ) use ( $slug_prefix, $slug_key ) {
            $meta = [];
            if ( isset( $input['slug'] ) )     { $meta[ $slug_key ] = sanitize_key( (string) $input['slug'] ); }
            if ( isset( $input['singular'] ) ) { $meta[ $slug_prefix . '_singular' ] = (string) $input['singular']; }
            if ( isset( $input['plural'] ) )   { $meta[ $slug_prefix . '_plural' ] = (string) $input['plural']; }
            if ( isset( $input['labels'] ) && is_array( $input['labels'] ) ) {
                foreach ( $input['labels'] as $k => $v ) { $meta[ $slug_prefix . '_label_' . $k ] = $v; }
            }
            if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
                foreach ( $input['settings'] as $k => $v ) { $meta[ $k ] = $v; }
            }
            return $meta;
        };
        $create_props = [
            'slug'     => [ 'type' => 'string', 'description' => 'Type slug.' ],
            'title'    => [ 'type' => 'string', 'description' => 'Plural display name (used as the post title).' ],
            'singular' => [ 'type' => 'string' ],
            'plural'   => [ 'type' => 'string' ],
            'labels'   => [ 'type' => 'object', 'description' => 'Override individual labels (stored as ' . $slug_prefix . '_label_<key>).' ],
            'settings' => [ 'type' => 'object', 'description' => 'Extra meta keys to store verbatim.' ],
        ];

        wp_register_ability( 'atarim/ase-list-' . $labels['list'], [
            'label' => 'List ASE ' . $labels['One'] . 's', 'category' => 'atarim',
            'description' => sprintf( 'List ASE %s (id, title, slug).', $labels['plural'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'items' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt ) {
                $g = $guard(); if ( $g ) { return $g; }
                $out = [];
                foreach ( AVCF_ASE_Helpers::list_cpt( $cpt ) as $p ) { $out[] = AVCF_ASE_Helpers::shape_post( $p ); }
                return [ 'success' => true, 'items' => $out, 'message' => sprintf( '%d item(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/ase-get-' . $labels['one'], [
            'label' => 'Get ASE ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Get one ASE %s by id or slug, with its stored settings.', $labels['one'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'string' ] ], 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'item' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_ASE_Helpers::find_cpt( $cpt, (string) $input['item'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $input['item'] ) ]; }
                return [ 'success' => true, 'item' => AVCF_ASE_Helpers::shape_post( $p, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/ase-create-' . $labels['one'], [
            'label' => 'Create ASE ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Create an ASE %s.%s Provide slug and title.', $labels['one'], $note ),
            'input_schema' => [ 'type' => 'object', 'properties' => $create_props, 'required' => [ 'slug', 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $build_meta ) {
                $g = $guard(); if ( $g ) { return $g; }
                $id = AVCF_ASE_Helpers::upsert( $cpt, (string) $input['title'], call_user_func( $build_meta, $input ) );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Created (best-effort — verify in ASE).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/ase-edit-' . $labels['one'], [
            'label' => 'Edit ASE ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Update an ASE %s by id/slug.%s', $labels['one'], $note ),
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [ 'item' => [ 'type' => 'string' ] ], $create_props ), 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $labels, $build_meta ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_ASE_Helpers::find_cpt( $cpt, (string) $input['item'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $input['item'] ) ]; }
                $title = isset( $input['title'] ) ? (string) $input['title'] : $p->post_title;
                $id = AVCF_ASE_Helpers::upsert( $cpt, $title, call_user_func( $build_meta, $input ), $p->ID );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Updated (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/ase-delete-' . $labels['one'], [
            'label' => 'Delete ASE ' . $labels['One'], 'category' => 'atarim',
            'description' => sprintf( 'Delete an ASE %s by id/slug. Existing content is preserved (just unregistered). Dry run unless confirm:true.', $labels['one'] ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'item' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'item' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $cpt, $labels ) {
                $g = $guard(); if ( $g ) { return $g; }
                $p = AVCF_ASE_Helpers::find_cpt( $cpt, (string) $input['item'] );
                if ( ! $p ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $labels['One'], $input['item'] ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete %s "%s". Re-call with confirm:true.', $labels['one'], $p->post_title ) ]; }
                $r = wp_delete_post( $p->ID, true );
                if ( $r && function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules(); }
                return $r ? [ 'success' => true, 'deleted' => true, 'message' => 'Deleted.' ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
