<?php
/**
 * Pods — MCP abilities (schema/authoring + items).
 *
 * Built on Pods' stable public API (pods_api() + pods()), so confidence is
 * higher than the builder-internal clusters. Items are included (not just a
 * duplicate of generic metadata) because Advanced Content Type records live in
 * custom tables; the pods() object abstracts storage and covers them uniformly
 * with CPT/taxonomy pods. Not tested against a live Pods install here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Pods extends AVCF_Abilities_Base {

    /** @var AVCF_Pods_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Pods_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_pods_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_pods();
        $this->register_fields();
        $this->register_groups();
        $this->register_items();
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
        wp_register_ability( 'atarim/pods-check-setup', [
            'label' => 'Check Pods Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether Pods is active and its version.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_pods_is_available(), 'version' => $d->avcf_pods_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------------ pods ------------------------------- */

    private function register_pods() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/pods-list-pods', [
            'label' => 'List Pods', 'category' => 'atarim',
            'description' => 'List all Pods (content types) with id, name, label, type, storage.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'pods' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $r = AVCF_Pods_Helpers::api_result( function() { return pods_api()->load_pods(); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                $out = [];
                foreach ( (array) $r as $pod ) { $out[] = AVCF_Pods_Helpers::shape_pod( $pod ); }
                return [ 'success' => true, 'pods' => $out, 'message' => sprintf( '%d pod(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-get-pod', [
            'label' => 'Get Pod', 'category' => 'atarim',
            'description' => 'Get a pod definition (with its fields) by name.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'pod' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['name'];
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name ) { return pods_api()->load_pod( [ 'name' => $name ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                if ( ! $r ) { return [ 'success' => false, 'message' => sprintf( 'Pod "%s" not found.', $name ) ]; }
                $shaped = AVCF_Pods_Helpers::shape_pod( $r );
                $args = is_object( $r ) && method_exists( $r, 'get_args' ) ? $r->get_args() : (array) $r;
                if ( isset( $args['fields'] ) ) {
                    $shaped['fields'] = [];
                    foreach ( (array) $args['fields'] as $f ) { $shaped['fields'][] = AVCF_Pods_Helpers::shape_field( $f ); }
                }
                return [ 'success' => true, 'pod' => $shaped, 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        // Pod creators (one per pod type).
        $make_creator = function( $ability, $label, $type, $top_keys, $extra_props, $required, $desc ) use ( $self ) {
            wp_register_ability( $ability, [
                'label' => $label, 'category' => 'atarim',
                'description' => $desc,
                'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [
                    'name'    => [ 'type' => 'string', 'description' => 'Machine name (slug).' ],
                    'label'   => [ 'type' => 'string', 'description' => 'Display label.' ],
                    'options' => [ 'type' => 'object', 'description' => 'Additional Pods args (public, hierarchical, supports, etc.).' ],
                ], $extra_props ), 'required' => $required, 'additionalProperties' => false ],
                'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'name' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
                'execute_callback' => function( $input = [] ) use ( $type, $top_keys ) {
                    $params = AVCF_Pods_Helpers::build_pod_params( $type, $input, $top_keys );
                    $r = AVCF_Pods_Helpers::api_result( function() use ( $params ) { return pods_api()->save_pod( $params ); } );
                    if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                    return [ 'success' => true, 'id' => (int) $r, 'name' => isset( $input['name'] ) ? (string) $input['name'] : '', 'message' => 'Pod created.' ];
                },
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
                'meta' => $this->write_meta( false ),
            ] );
        };

        $make_creator( 'atarim/pods-create-cpt', 'Create Pods CPT', 'post_type', [ 'name', 'label' ], [], [ 'name' ],
            'Create a Pods-managed Custom Post Type. Provide name and label; put flags like public, hierarchical, has_archive, supports into options.' );
        $make_creator( 'atarim/pods-create-taxonomy', 'Create Pods Taxonomy', 'taxonomy', [ 'name', 'label' ], [], [ 'name' ],
            'Create a Pods-managed taxonomy. Provide name and label; put flags and the attached post types into options.' );
        $make_creator( 'atarim/pods-create-act', 'Create Pods Advanced Content Type', 'pod', [ 'name', 'label' ], [], [ 'name' ],
            'Create a Pods Advanced Content Type (records stored in a custom table). Provide name and label; storage defaults to table.' );
        $make_creator( 'atarim/pods-create-settings', 'Create Pods Settings Page', 'settings', [ 'name', 'label' ], [], [ 'name' ],
            'Create a Pods settings page (options stored as a single settings group).' );
        $make_creator( 'atarim/pods-extend-builtin', 'Extend Built-in Type with Pods', 'post_type', [ 'name', 'label', 'object', 'extend_type' ],
            [ 'object' => [ 'type' => 'string', 'description' => 'Existing object to extend (e.g. "post", "page", "user").' ], 'extend_type' => [ 'type' => 'string', 'enum' => [ 'post_type', 'taxonomy', 'user', 'media', 'comment' ], 'description' => 'Kind of built-in object.' ] ],
            [ 'name', 'object' ],
            'Extend an existing WordPress object (post type, taxonomy, user, media, comment) with Pods fields. Provide name, object (the existing slug), and extend_type.' );

        wp_register_ability( 'atarim/pods-edit-pod', [
            'label' => 'Edit Pod', 'category' => 'atarim',
            'description' => 'Update a pod definition by name. Provide name and the args to change (label and/or an options object).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'options' => [ 'type' => 'object' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $params = [ 'name' => (string) $input['name'] ];
                if ( isset( $input['label'] ) ) { $params['label'] = (string) $input['label']; }
                if ( isset( $input['options'] ) && is_array( $input['options'] ) ) { foreach ( $input['options'] as $k => $v ) { $params[ $k ] = $v; } }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $params ) { return pods_api()->save_pod( $params ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Pod updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-delete-pod', [
            'label' => 'Delete Pod', 'category' => 'atarim',
            'description' => 'Delete a pod definition by name. Dry run unless confirm:true. For ACTs this removes the content type (and its table).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['name'];
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete pod "%s". Re-call with confirm:true.', $name ) ]; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name ) { return pods_api()->delete_pod( [ 'name' => $name ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Pod "%s" deleted.', $name ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ fields ----------------------------- */

    private function register_fields() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/pods-get-field-type-schema', [
            'label' => 'Get Pods Field Type Schema', 'category' => 'atarim',
            'description' => 'List the common Pods field types to use when authoring fields.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_types' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = [ 'text', 'website', 'phone', 'email', 'password', 'paragraph', 'wysiwyg', 'code', 'number', 'currency', 'date', 'datetime', 'time', 'boolean', 'color', 'file', 'avatar', 'pick', 'oembed', 'heading', 'html', 'slug' ];
                return [ 'success' => true, 'field_types' => array_map( function( $t ) { return [ 'type' => $t ]; }, $types ), 'message' => sprintf( '%d field types.', count( $types ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-list-fields', [
            'label' => 'List Pod Fields', 'category' => 'atarim',
            'description' => 'List the fields of a pod (id, name, label, type).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ] ], 'required' => [ 'pod' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'fields' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $pod = (string) $input['pod'];
                $r = AVCF_Pods_Helpers::api_result( function() use ( $pod ) { return pods_api()->load_fields( [ 'pod' => $pod ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                $out = [];
                foreach ( (array) $r as $f ) { $out[] = AVCF_Pods_Helpers::shape_field( $f ); }
                return [ 'success' => true, 'fields' => $out, 'message' => sprintf( '%d field(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-get-field', [
            'label' => 'Get Pod Field', 'category' => 'atarim',
            'description' => 'Get one field of a pod by name.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ] ], 'required' => [ 'pod', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $pod = (string) $input['pod']; $name = (string) $input['name'];
                $r = AVCF_Pods_Helpers::api_result( function() use ( $pod, $name ) { return pods_api()->load_field( [ 'pod' => $pod, 'name' => $name ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                if ( ! $r ) { return [ 'success' => false, 'message' => sprintf( 'Field "%s" not found on pod "%s".', $name, $pod ) ]; }
                return [ 'success' => true, 'field' => AVCF_Pods_Helpers::shape_field( $r ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-create-field', [
            'label' => 'Create Pod Field', 'category' => 'atarim',
            'description' => 'Add a field to a pod. Provide pod, name, type (see pods-get-field-type-schema), label; extra config goes in options.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'options' => [ 'type' => 'object' ] ], 'required' => [ 'pod', 'name', 'type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $params = [ 'pod' => (string) $input['pod'], 'name' => (string) $input['name'], 'type' => (string) $input['type'] ];
                if ( isset( $input['label'] ) ) { $params['label'] = (string) $input['label']; }
                if ( isset( $input['options'] ) && is_array( $input['options'] ) ) { foreach ( $input['options'] as $k => $v ) { $params[ $k ] = $v; } }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $params ) { return pods_api()->save_field( $params ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'id' => (int) $r, 'message' => 'Field created.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-edit-field', [
            'label' => 'Edit Pod Field', 'category' => 'atarim',
            'description' => 'Update a pod field by pod + name. Provide the args to change (label, type, or an options object).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ], 'options' => [ 'type' => 'object' ] ], 'required' => [ 'pod', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $params = [ 'pod' => (string) $input['pod'], 'name' => (string) $input['name'] ];
                foreach ( [ 'label', 'type' ] as $k ) { if ( isset( $input[ $k ] ) ) { $params[ $k ] = (string) $input[ $k ]; } }
                if ( isset( $input['options'] ) && is_array( $input['options'] ) ) { foreach ( $input['options'] as $k => $v ) { $params[ $k ] = $v; } }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $params ) { return pods_api()->save_field( $params ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Field updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-delete-field', [
            'label' => 'Delete Pod Field', 'category' => 'atarim',
            'description' => 'Delete a pod field by pod + name. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'pod', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $pod = (string) $input['pod']; $name = (string) $input['name'];
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete field "%s" on pod "%s". Re-call with confirm:true.', $name, $pod ) ]; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $pod, $name ) { return pods_api()->delete_field( [ 'pod' => $pod, 'name' => $name ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => 'Field deleted.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ groups ----------------------------- */

    private function register_groups() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/pods-list-groups', [
            'label' => 'List Pod Field Groups', 'category' => 'atarim',
            'description' => 'List the field groups of a pod.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ] ], 'required' => [ 'pod' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'groups' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $pod = (string) $input['pod'];
                $r = AVCF_Pods_Helpers::api_result( function() use ( $pod ) { return pods_api()->load_groups( [ 'pod' => $pod ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                $out = [];
                foreach ( (array) $r as $g ) {
                    $a = is_object( $g ) && method_exists( $g, 'get_args' ) ? $g->get_args() : (array) $g;
                    $out[] = [ 'id' => isset( $a['id'] ) ? (int) $a['id'] : null, 'name' => isset( $a['name'] ) ? $a['name'] : '', 'label' => isset( $a['label'] ) ? $a['label'] : '' ];
                }
                return [ 'success' => true, 'groups' => $out, 'message' => sprintf( '%d group(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-create-group', [
            'label' => 'Create Pod Field Group', 'category' => 'atarim',
            'description' => 'Create a field group on a pod. Provide pod, name, label.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'options' => [ 'type' => 'object' ] ], 'required' => [ 'pod', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $params = [ 'pod' => (string) $input['pod'], 'name' => (string) $input['name'] ];
                if ( isset( $input['label'] ) ) { $params['label'] = (string) $input['label']; }
                if ( isset( $input['options'] ) && is_array( $input['options'] ) ) { foreach ( $input['options'] as $k => $v ) { $params[ $k ] = $v; } }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $params ) { return pods_api()->save_group( $params ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'id' => (int) $r, 'message' => 'Group created.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-edit-group', [
            'label' => 'Edit Pod Field Group', 'category' => 'atarim',
            'description' => 'Update a pod field group by pod + name.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'options' => [ 'type' => 'object' ] ], 'required' => [ 'pod', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $params = [ 'pod' => (string) $input['pod'], 'name' => (string) $input['name'] ];
                if ( isset( $input['label'] ) ) { $params['label'] = (string) $input['label']; }
                if ( isset( $input['options'] ) && is_array( $input['options'] ) ) { foreach ( $input['options'] as $k => $v ) { $params[ $k ] = $v; } }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $params ) { return pods_api()->save_group( $params ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Group updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-delete-group', [
            'label' => 'Delete Pod Field Group', 'category' => 'atarim',
            'description' => 'Delete a pod field group by pod + name. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'pod', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $pod = (string) $input['pod']; $name = (string) $input['name'];
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete group "%s" on pod "%s". Re-call with confirm:true.', $name, $pod ) ]; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $pod, $name ) { return pods_api()->delete_group( [ 'pod' => $pod, 'name' => $name ] ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => 'Group deleted.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ items ------------------------------ */

    private function register_items() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/pods-list-items', [
            'label' => 'List Pod Items', 'category' => 'atarim',
            'description' => 'List items (records) of a pod. Works for CPT, taxonomy, and Advanced Content Type pods. Supports limit, offset, and a where string (SQL-like Pods where clause).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'where' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'default' => 0 ] ], 'required' => [ 'pod' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'items' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['pod'];
                $find = [ 'limit' => isset( $input['limit'] ) ? (int) $input['limit'] : 50, 'offset' => isset( $input['offset'] ) ? (int) $input['offset'] : 0 ];
                if ( isset( $input['where'] ) && $input['where'] !== '' ) { $find['where'] = (string) $input['where']; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name, $find ) {
                    $pod = pods( $name );
                    if ( ! $pod || ( method_exists( $pod, 'valid' ) && ! $pod->valid() ) ) { return new WP_Error( 'pod_invalid', sprintf( 'Pod "%s" not found.', $name ) ); }
                    $pod->find( $find );
                    $rows = [];
                    while ( $pod->fetch() ) {
                        $rows[] = method_exists( $pod, 'export' ) ? $pod->export() : [ 'id' => $pod->id() ];
                    }
                    return [ 'rows' => $rows, 'total' => method_exists( $pod, 'total_found' ) ? (int) $pod->total_found() : count( $rows ) ];
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'items' => $r['rows'], 'total' => $r['total'], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-get-item', [
            'label' => 'Get Pod Item', 'category' => 'atarim',
            'description' => 'Get a single pod item (record) by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'pod', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'item' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['pod']; $id = (int) $input['id'];
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name, $id ) {
                    $pod = pods( $name, $id );
                    if ( ! $pod || ( method_exists( $pod, 'exists' ) && ! $pod->exists() ) ) { return new WP_Error( 'not_found', sprintf( 'Item %d not found in pod "%s".', $id, $name ) ); }
                    return method_exists( $pod, 'export' ) ? $pod->export() : [ 'id' => $id ];
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'item' => (array) $r, 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/pods-create-item', [
            'label' => 'Create Pod Item', 'category' => 'atarim',
            'description' => 'Create an item (record) in a pod. data is a field=>value map. Returns the new item id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'data' => [ 'type' => 'object' ] ], 'required' => [ 'pod', 'data' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['pod'];
                $data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : [];
                if ( empty( $data ) ) { return [ 'success' => false, 'message' => 'data must be a non-empty object.' ]; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name, $data ) {
                    $pod = pods( $name );
                    if ( ! $pod ) { return new WP_Error( 'pod_invalid', sprintf( 'Pod "%s" not found.', $name ) ); }
                    return (int) $pod->add( $data );
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'id' => (int) $r, 'message' => 'Item created.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-edit-item', [
            'label' => 'Edit Pod Item', 'category' => 'atarim',
            'description' => 'Update a pod item by id. data is the field=>value map to write.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ], 'data' => [ 'type' => 'object' ] ], 'required' => [ 'pod', 'id', 'data' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['pod']; $id = (int) $input['id'];
                $data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : [];
                if ( empty( $data ) ) { return [ 'success' => false, 'message' => 'data must be a non-empty object.' ]; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name, $id, $data ) {
                    $pod = pods( $name, $id );
                    if ( ! $pod || ( method_exists( $pod, 'exists' ) && ! $pod->exists() ) ) { return new WP_Error( 'not_found', sprintf( 'Item %d not found in pod "%s".', $id, $name ) ); }
                    return (int) $pod->save( $data );
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => sprintf( 'Item %d updated.', $id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/pods-delete-item', [
            'label' => 'Delete Pod Item', 'category' => 'atarim',
            'description' => 'Delete a pod item by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'pod' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'pod', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $name = (string) $input['pod']; $id = (int) $input['id'];
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete item %d from pod "%s". Re-call with confirm:true.', $id, $name ) ]; }
                $r = AVCF_Pods_Helpers::api_result( function() use ( $name, $id ) {
                    $pod = pods( $name );
                    if ( ! $pod ) { return new WP_Error( 'pod_invalid', sprintf( 'Pod "%s" not found.', $name ) ); }
                    return $pod->delete( $id );
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Item %d deleted.', $id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
