<?php
/**
 * Breakdance — Advanced MCP abilities.
 *
 * Templates (5 CPTs + the double-encoded _breakdance_template_settings),
 * global classes / variables / global settings (the JSON-blob global options
 * read via \Breakdance\Data\get_global_option / set_global_option, with CSS
 * cache regen), dynamic data fields, and form submissions (breakdance_form_res
 * CPT). Site-wide design stores are confirm-gated. Built from the Novamira
 * reference; not runtime-tested.
 *
 * Global option field names:
 *   variables               variables_json_string
 *   variable collections    variables_collections_json_string
 *   global classes          breakdance_classes_json_string   (keyed by name)
 *   global settings         global_settings_json_string
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Breakdance_Pro extends AVCF_Abilities_Base {

    const F_VARIABLES   = 'variables_json_string';
    const F_VAR_COLL    = 'variables_collections_json_string';
    const F_CLASSES     = 'breakdance_classes_json_string';
    const F_SETTINGS    = 'global_settings_json_string';
    const TPL_META      = '_breakdance_template_settings';
    const FORM_CPT      = 'breakdance_form_res';

    /** @var AVCF_Breakdance_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Breakdance_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_breakdance_is_available() ) {
            return;
        }
        $this->register_templates();
        $this->register_global_classes();
        $this->register_variables();
        $this->register_global_settings();
        $this->register_dynamic_data();
        $this->register_form_submissions();
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
    private function confirm_gate( $input, $what ) {
        if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'applied' => false, 'message' => sprintf( 'Dry run: %s is site-wide. Re-call with confirm:true.', $what ) ]; }
        return null;
    }

    public function template_post_types() {
        return [ 'breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_popup', 'breakdance_block' ];
    }

    /* ----- global option (JSON blob) access ----- */

    public function global_get( $field ) {
        $fn = '\Breakdance\Data\get_global_option';
        if ( ! function_exists( $fn ) ) { return null; }
        try { $raw = call_user_func( $fn, $field ); } catch ( \Throwable $e ) { return null; }
        if ( is_array( $raw ) ) { return $raw; }
        if ( is_string( $raw ) && $raw !== '' ) { $d = json_decode( $raw, true ); return is_array( $d ) ? $d : []; }
        return [];
    }
    public function global_set( $field, $arr ) {
        $fn = '\Breakdance\Data\set_global_option';
        if ( ! function_exists( $fn ) ) { return false; }
        try { call_user_func( $fn, $field, (string) wp_json_encode( $arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); }
        catch ( \Throwable $e ) { return false; }
        $regen = '\Breakdance\Render\generateCacheForGlobalSettings';
        if ( function_exists( $regen ) ) { try { call_user_func( $regen ); } catch ( \Throwable $e ) { /* non-fatal */ } }
        return true;
    }
    public function global_available() { return function_exists( '\Breakdance\Data\get_global_option' ); }

    /* ----- template settings (double-encoded meta) ----- */

    public function read_tpl_settings( $post_id ) {
        $raw = get_post_meta( (int) $post_id, self::TPL_META, true );
        if ( ! is_string( $raw ) || $raw === '' ) { return []; }
        $first = json_decode( $raw, true );
        if ( is_string( $first ) ) { $second = json_decode( $first, true ); return is_array( $second ) ? $second : []; }
        return is_array( $first ) ? $first : [];
    }
    public function write_tpl_settings( $post_id, $settings ) {
        $inner = wp_json_encode( $settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $inner === false ) { return false; }
        $outer = wp_json_encode( $inner );
        update_post_meta( (int) $post_id, self::TPL_META, wp_slash( (string) $outer ) );
        return true;
    }

    /* ------------------------------ templates -------------------------- */

    private function register_templates() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/breakdance-list-templates', [
            'label' => 'List Breakdance Templates', 'category' => 'atarim',
            'description' => 'List Breakdance template posts across the five template CPTs (breakdance_template / _header / _footer / _popup / _block). Optionally filter by post_type. Returns id, title, post_type, and the settings "type".',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_type' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'templates' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $types = $self->template_post_types();
                if ( isset( $input['post_type'] ) && in_array( $input['post_type'], $types, true ) ) { $types = [ $input['post_type'] ]; }
                $posts = get_posts( [ 'post_type' => $types, 'post_status' => 'any', 'numberposts' => -1 ] );
                $out = [];
                foreach ( $posts as $p ) {
                    $s = $self->read_tpl_settings( $p->ID );
                    $out[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'post_type' => $p->post_type, 'type' => isset( $s['type'] ) ? $s['type'] : null ];
                }
                return [ 'success' => true, 'templates' => $out, 'message' => sprintf( '%d template(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-get-template', [
            'label' => 'Get Breakdance Template', 'category' => 'atarim',
            'description' => 'Read one template by id: title, post_type, and its _breakdance_template_settings (type, ruleGroups/conditions, triggers, priority, fallback).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'template' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || ! in_array( $p->post_type, $self->template_post_types(), true ) ) { return [ 'success' => false, 'message' => 'Not a Breakdance template id.' ]; }
                return [ 'success' => true, 'template' => [ 'id' => $id, 'title' => $p->post_title, 'post_type' => $p->post_type, 'settings' => $self->read_tpl_settings( $id ) ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-create-template', [
            'label' => 'Create Breakdance Template', 'category' => 'atarim',
            'description' => 'Create a Breakdance template post. post_type must be one of breakdance_template / _header / _footer / _popup / _block. title required. Optional settings is the _breakdance_template_settings object ({type, ruleGroups, triggers, priority, fallback}); omit to create an empty template and set conditions later. Element content is NOT created here (use the element-tree abilities or the editor).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_type' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'post_type', 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! in_array( $input['post_type'], $self->template_post_types(), true ) ) { return [ 'success' => false, 'message' => 'post_type must be one of the five Breakdance template CPTs.' ]; }
                $id = wp_insert_post( [ 'post_type' => (string) $input['post_type'], 'post_title' => (string) $input['title'], 'post_status' => 'publish' ], true );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) { $self->write_tpl_settings( $id, $input['settings'] ); }
                return [ 'success' => true, 'id' => (int) $id, 'message' => 'Template created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/breakdance-edit-template', [
            'label' => 'Edit Breakdance Template', 'category' => 'atarim',
            'description' => 'Update a template\'s title and/or its _breakdance_template_settings (merged by default; pass replace_settings:true to overwrite the whole settings object).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'template_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'title'            => [ 'type' => 'string' ],
                'settings'         => [ 'type' => 'object' ],
                'replace_settings' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || ! in_array( $p->post_type, $self->template_post_types(), true ) ) { return [ 'success' => false, 'message' => 'Not a Breakdance template id.' ]; }
                if ( isset( $input['title'] ) ) { wp_update_post( [ 'ID' => $id, 'post_title' => (string) $input['title'] ] ); }
                if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
                    $new = ! empty( $input['replace_settings'] ) ? $input['settings'] : array_merge( $self->read_tpl_settings( $id ), $input['settings'] );
                    $self->write_tpl_settings( $id, $new );
                }
                return [ 'success' => true, 'message' => sprintf( 'Template %d updated.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/breakdance-delete-template', [
            'label' => 'Delete Breakdance Template', 'category' => 'atarim',
            'description' => 'Delete a Breakdance template by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || ! in_array( $p->post_type, $self->template_post_types(), true ) ) { return [ 'success' => false, 'message' => 'Not a Breakdance template id.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: would delete template %d. Re-call with confirm:true.', $id ) ]; }
                $res = wp_delete_post( $id, true );
                return $res ? [ 'success' => true, 'message' => sprintf( 'Template %d deleted.', $id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/breakdance-list-template-conditions', [
            'label' => 'List Breakdance Template Conditions', 'category' => 'atarim',
            'description' => 'Return the catalogue of display conditions a Breakdance template can use, for a given CPT (sourced from Breakdance\'s own conditions API, same as the admin UI). Each row is {slug, label, values}. Call once per post_type before composing set-template-conditions.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_type' => [ 'type' => 'string', 'default' => 'breakdance_template' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'conditions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $pt = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'breakdance_template';
                $fn = '\Breakdance\Themeless\getConditionsWithValuesForPostType';
                if ( ! function_exists( $fn ) ) { return [ 'success' => false, 'message' => 'Breakdance conditions API not available on this build.' ]; }
                try { $cond = call_user_func( $fn, $pt ); } catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ]; }
                return [ 'success' => true, 'conditions' => is_array( $cond ) ? array_values( $cond ) : [], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-set-template-conditions', [
            'label' => 'Set Breakdance Template Conditions', 'category' => 'atarim',
            'description' => 'Set a template\'s display conditions by writing rule_groups into its _breakdance_template_settings. rule_groups is the full conditions structure (see list-template-conditions). Replaces the existing conditions.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'rule_groups' => [ 'type' => 'array' ],
            ], 'required' => [ 'template_id', 'rule_groups' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || ! in_array( $p->post_type, $self->template_post_types(), true ) ) { return [ 'success' => false, 'message' => 'Not a Breakdance template id.' ]; }
                $settings = $self->read_tpl_settings( $id );
                $settings['ruleGroups'] = is_array( $input['rule_groups'] ) ? $input['rule_groups'] : [];
                if ( ! $self->write_tpl_settings( $id, $settings ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Template conditions set.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- global classes ------------------------ */

    private function register_global_classes() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/breakdance-list-global-classes', [
            'label' => 'List Breakdance Global Classes', 'category' => 'atarim',
            'description' => 'List Breakdance global CSS classes (name, type, property_count). Optionally filter by type. Classes are identified by name.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'type' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'classes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $classes = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_CLASSES );
                $filter = isset( $input['type'] ) ? (string) $input['type'] : '';
                $rows = [];
                foreach ( (array) $classes as $c ) {
                    if ( ! is_array( $c ) ) { continue; }
                    if ( $filter !== '' && ( ( $c['type'] ?? null ) !== $filter ) ) { continue; }
                    $props = isset( $c['properties'] ) && is_array( $c['properties'] ) ? $c['properties'] : [];
                    $rows[] = [ 'name' => $c['name'] ?? '', 'type' => $c['type'] ?? 'class', 'property_count' => count( $props ) ];
                }
                return [ 'success' => true, 'classes' => $rows, 'message' => sprintf( '%d class(es).', count( $rows ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-get-global-class', [
            'label' => 'Get Breakdance Global Class', 'category' => 'atarim',
            'description' => 'Get a global class (full properties) by name.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'class' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $name = (string) $input['name'];
                foreach ( $self->global_get( AVCF_Abilities_Breakdance_Pro::F_CLASSES ) as $c ) {
                    if ( is_array( $c ) && ( ( $c['name'] ?? null ) === $name ) ) { return [ 'success' => true, 'class' => $c, 'message' => 'OK.' ]; }
                }
                return [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $name ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-create-global-class', [
            'label' => 'Create Breakdance Global Class', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a global CSS class. name required (must be unique); properties is the class\'s style object; type defaults to "class". Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'properties' => [ 'type' => 'object' ], 'type' => [ 'type' => 'string', 'default' => 'class' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $gate = $self->confirm_gate( $input, 'Creating a global class' ); if ( $gate ) { return $gate; }
                $name = (string) $input['name'];
                $classes = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_CLASSES );
                foreach ( $classes as $c ) { if ( is_array( $c ) && ( ( $c['name'] ?? null ) === $name ) ) { return [ 'success' => false, 'message' => sprintf( 'A class named "%s" already exists.', $name ) ]; } }
                $classes[] = [ 'name' => $name, 'type' => isset( $input['type'] ) ? (string) $input['type'] : 'class', 'properties' => isset( $input['properties'] ) && is_array( $input['properties'] ) ? $input['properties'] : [] ];
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_CLASSES, array_values( $classes ) ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'applied' => true, 'message' => sprintf( 'Global class "%s" created.', $name ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/breakdance-edit-global-class', [
            'label' => 'Edit Breakdance Global Class', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a global class by name (properties merged; optional type). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'properties' => [ 'type' => 'object' ], 'type' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $gate = $self->confirm_gate( $input, 'Editing a global class' ); if ( $gate ) { return $gate; }
                $name = (string) $input['name'];
                $classes = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_CLASSES ); $found = false;
                foreach ( $classes as &$c ) {
                    if ( is_array( $c ) && ( ( $c['name'] ?? null ) === $name ) ) {
                        if ( isset( $input['type'] ) ) { $c['type'] = (string) $input['type']; }
                        if ( isset( $input['properties'] ) && is_array( $input['properties'] ) ) {
                            $cur = isset( $c['properties'] ) && is_array( $c['properties'] ) ? $c['properties'] : [];
                            $c['properties'] = array_merge( $cur, $input['properties'] );
                        }
                        $found = true; break;
                    }
                }
                unset( $c );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $name ) ]; }
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_CLASSES, array_values( $classes ) ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Global class "%s" updated.', $name ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/breakdance-delete-global-class', [
            'label' => 'Delete Breakdance Global Class', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a global class by name. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $gate = $self->confirm_gate( $input, 'Deleting a global class' ); if ( $gate ) { return $gate; }
                $name = (string) $input['name'];
                $classes = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_CLASSES );
                $new = array_values( array_filter( $classes, function( $c ) use ( $name ) { return ! ( is_array( $c ) && ( ( $c['name'] ?? null ) === $name ) ); } ) );
                if ( count( $new ) === count( $classes ) ) { return [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $name ) ]; }
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_CLASSES, $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Global class "%s" deleted.', $name ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- variables --------------------------- */

    private function var_match( $v, $ref ) {
        if ( ! is_array( $v ) ) { return false; }
        return ( isset( $v['id'] ) && (string) $v['id'] === $ref ) || ( isset( $v['name'] ) && (string) $v['name'] === $ref );
    }

    private function register_variables() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/breakdance-list-variables', [
            'label' => 'List Breakdance Variables', 'category' => 'atarim',
            'description' => 'List Breakdance global variables (and their collections). Optionally filter by collection. Each variable carries name/value/collection.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'collection' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'variables' => [ 'type' => 'array' ], 'collections' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $vars = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_VARIABLES );
                $coll = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_VAR_COLL );
                if ( isset( $input['collection'] ) && $input['collection'] !== '' ) {
                    $f = (string) $input['collection'];
                    $vars = array_values( array_filter( $vars, function( $v ) use ( $f ) { return is_array( $v ) && ( ( $v['collection'] ?? null ) === $f ); } ) );
                }
                return [ 'success' => true, 'variables' => array_values( $vars ), 'collections' => array_values( $coll ), 'message' => sprintf( '%d variable(s).', count( $vars ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-create-variable', [
            'label' => 'Create Breakdance Variable', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a global variable. name and value required; collection optional. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'collection' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name', 'value' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $gate = $self->confirm_gate( $input, 'Creating a variable' ); if ( $gate ) { return $gate; }
                $vars = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_VARIABLES );
                $vid = substr( md5( uniqid( '', true ) ), 0, 8 );
                $entry = [ 'id' => $vid, 'name' => (string) $input['name'], 'value' => (string) $input['value'] ];
                if ( isset( $input['collection'] ) ) { $entry['collection'] = (string) $input['collection']; }
                $vars[] = $entry;
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_VARIABLES, array_values( $vars ) ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'id' => $vid, 'message' => 'Variable created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/breakdance-edit-variable', [
            'label' => 'Edit Breakdance Variable', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a global variable by id or name. Update value/name/collection. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'variable' => [ 'type' => 'string', 'description' => 'Variable id or name.' ], 'name' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'collection' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'variable' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $gate = $self->confirm_gate( $input, 'Editing a variable' ); if ( $gate ) { return $gate; }
                $ref = (string) $input['variable'];
                $vars = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_VARIABLES ); $found = false;
                foreach ( $vars as &$v ) {
                    if ( $self->var_match_public( $v, $ref ) ) {
                        if ( isset( $input['name'] ) ) { $v['name'] = (string) $input['name']; }
                        if ( isset( $input['value'] ) ) { $v['value'] = (string) $input['value']; }
                        if ( isset( $input['collection'] ) ) { $v['collection'] = (string) $input['collection']; }
                        $found = true; break;
                    }
                }
                unset( $v );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Variable "%s" not found.', $ref ) ]; }
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_VARIABLES, array_values( $vars ) ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Variable updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/breakdance-delete-variable', [
            'label' => 'Delete Breakdance Variable', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a global variable by id or name. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'variable' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'variable' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                $gate = $self->confirm_gate( $input, 'Deleting a variable' ); if ( $gate ) { return $gate; }
                $ref = (string) $input['variable'];
                $vars = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_VARIABLES );
                $new = array_values( array_filter( $vars, function( $v ) use ( $self, $ref ) { return ! $self->var_match_public( $v, $ref ); } ) );
                if ( count( $new ) === count( $vars ) ) { return [ 'success' => false, 'message' => sprintf( 'Variable "%s" not found.', $ref ) ]; }
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_VARIABLES, $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Variable "%s" deleted.', $ref ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /** public wrapper for closures */
    public function var_match_public( $v, $ref ) { return $this->var_match( $v, $ref ); }

    /* -------------------------- global settings ------------------------ */

    private function register_global_settings() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/breakdance-get-global-settings', [
            'label' => 'Get Breakdance Global Settings', 'category' => 'atarim',
            'description' => 'Return the entire Breakdance Global Settings tree (typography, colors, buttons, containers, forms, woocommerce, …). On fresh installs this is empty until saved — an empty object is normal, not an error.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'settings' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                return [ 'success' => true, 'settings' => $self->global_get( AVCF_Abilities_Breakdance_Pro::F_SETTINGS ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-edit-global-settings', [
            'label' => 'Edit Breakdance Global Settings', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Patch the Breakdance Global Settings tree. settings is a top-level key→value patch (shallow-merged; set a key to null to remove it). The CSS cache is regenerated on save. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'settings' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'settings' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->global_available() ) { return [ 'success' => false, 'message' => 'Breakdance global option API not available on this build.' ]; }
                if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) ) { return [ 'success' => false, 'message' => 'settings must be an object.' ]; }
                $gate = $self->confirm_gate( $input, 'Editing global settings' ); if ( $gate ) { return $gate; }
                $existing = $self->global_get( AVCF_Abilities_Breakdance_Pro::F_SETTINGS );
                $merged = array_merge( $existing, $input['settings'] );
                foreach ( $input['settings'] as $k => $v ) { if ( $v === null ) { unset( $merged[ $k ] ); } }
                if ( ! $self->global_set( AVCF_Abilities_Breakdance_Pro::F_SETTINGS, $merged ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Global settings updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- dynamic data -------------------------- */

    private function register_dynamic_data() {
        wp_register_ability( 'atarim/breakdance-list-dynamic-data-fields', [
            'label' => 'List Breakdance Dynamic Data Fields', 'category' => 'atarim',
            'description' => 'List the Breakdance dynamic-data fields available (post/site/user/loop data sources). Depends on Breakdance\'s dynamic-data controller; degrades if unavailable on this build.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'fields' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cls = '\Breakdance\DynamicData\DynamicDataController';
                if ( class_exists( $cls ) ) {
                    foreach ( [ 'get_fields', 'getFields', 'all', 'get_all' ] as $m ) {
                        if ( method_exists( $cls, $m ) ) {
                            try { $fields = call_user_func( [ $cls, $m ] ); return [ 'success' => true, 'fields' => is_array( $fields ) ? array_values( $fields ) : [], 'message' => 'OK.' ]; }
                            catch ( \Throwable $e ) { /* try next */ }
                        }
                    }
                }
                return [ 'success' => false, 'message' => 'Breakdance dynamic-data API not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-get-dynamic-data-field', [
            'label' => 'Get Breakdance Dynamic Data Field', 'category' => 'atarim',
            'description' => 'Get one dynamic-data field definition by its id/slug, if the dynamic-data controller exposes it.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'field' => [ 'type' => 'string' ] ], 'required' => [ 'field' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cls = '\Breakdance\DynamicData\DynamicDataController';
                $want = (string) $input['field'];
                if ( class_exists( $cls ) ) {
                    foreach ( [ 'get_fields', 'getFields', 'all', 'get_all' ] as $m ) {
                        if ( method_exists( $cls, $m ) ) {
                            try {
                                $fields = call_user_func( [ $cls, $m ] );
                                if ( is_array( $fields ) ) {
                                    foreach ( $fields as $f ) {
                                        $id = is_array( $f ) ? ( $f['id'] ?? ( $f['slug'] ?? '' ) ) : '';
                                        if ( (string) $id === $want ) { return [ 'success' => true, 'field' => $f, 'message' => 'OK.' ]; }
                                    }
                                    return [ 'success' => false, 'message' => sprintf( 'Field "%s" not found.', $want ) ];
                                }
                            } catch ( \Throwable $e ) { /* try next */ }
                        }
                    }
                }
                return [ 'success' => false, 'message' => 'Breakdance dynamic-data API not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- form submissions ------------------------ */

    private function register_form_submissions() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/breakdance-list-form-submissions', [
            'label' => 'List Breakdance Form Submissions', 'category' => 'atarim',
            'description' => 'List rows from the Breakdance form-submissions CPT (breakdance_form_res): id, form_id, host post_id, date, status. Filter by form_id. Use get-form-submission for full field values. (Contains user-submitted data — admin only.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'submissions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $args = [ 'post_type' => AVCF_Abilities_Breakdance_Pro::FORM_CPT, 'post_status' => 'any', 'numberposts' => isset( $input['limit'] ) ? (int) $input['limit'] : 100 ];
                if ( isset( $input['form_id'] ) && $input['form_id'] !== '' ) { $args['meta_key'] = '_breakdance_form_id'; $args['meta_value'] = (string) $input['form_id']; }
                $posts = get_posts( $args );
                $out = [];
                foreach ( $posts as $p ) {
                    $out[] = [
                        'id'      => $p->ID,
                        'form_id' => (string) get_post_meta( $p->ID, '_breakdance_form_id', true ),
                        'post_id' => (int) get_post_meta( $p->ID, '_breakdance_post_id', true ),
                        'date'    => $p->post_date,
                        'status'  => $p->post_status,
                    ];
                }
                return [ 'success' => true, 'submissions' => $out, 'message' => sprintf( '%d submission(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-get-form-submission', [
            'label' => 'Get Breakdance Form Submission', 'category' => 'atarim',
            'description' => 'Read a single form submission (its field values and metadata) by id. (Contains user-submitted data — admin only.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'submission' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $id = (int) $input['id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Abilities_Breakdance_Pro::FORM_CPT ) { return [ 'success' => false, 'message' => 'Not a Breakdance form-submission id.' ]; }
                $meta = get_post_meta( $id );
                $fields = [];
                foreach ( (array) $meta as $k => $v ) {
                    if ( strpos( $k, '_breakdance_' ) === 0 ) { continue; } // internal
                    $fields[ $k ] = is_array( $v ) && count( $v ) === 1 ? maybe_unserialize( $v[0] ) : $v;
                }
                return [ 'success' => true, 'submission' => [
                    'id' => $id,
                    'form_id' => (string) get_post_meta( $id, '_breakdance_form_id', true ),
                    'post_id' => (int) get_post_meta( $id, '_breakdance_post_id', true ),
                    'date' => $p->post_date,
                    'fields' => $fields,
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/breakdance-delete-form-submission', [
            'label' => 'Delete Breakdance Form Submission', 'category' => 'atarim',
            'description' => 'Delete a form submission by id. Dry run unless confirm:true. (Admin only.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $id = (int) $input['id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Abilities_Breakdance_Pro::FORM_CPT ) { return [ 'success' => false, 'message' => 'Not a Breakdance form-submission id.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: would delete submission %d. Re-call with confirm:true.', $id ) ]; }
                $res = wp_delete_post( $id, true );
                return $res ? [ 'success' => true, 'message' => sprintf( 'Submission %d deleted.', $id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
