<?php
/**
 * ACPT (Advanced Custom Post Types) — MCP abilities (schema/authoring layer).
 *
 * Structure layer only; field VALUES are covered by the generic metadata
 * abilities. Built on ACPT's Repository + Model ORM.
 *
 * Confidence (untested against live ACPT here):
 *   SOLID    — reads (list/get) for post types, taxonomies, option pages, field
 *              groups; and create/edit/delete for post types / taxonomies /
 *              option pages (Model::hydrateFromArray + Repository::save).
 *   FLAGGED  — field-group (meta group) create/edit nests boxes & fields; the
 *              MetaGroupModel shape is intricate — best-effort, validate live.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_ACPT extends AVCF_Abilities_Base {

    /** @var AVCF_ACPT_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_ACPT_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_acpt_is_available() || ! $this->detector->avcf_acpt_has_repositories() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_field_type_schema();

        $this->register_model( [
            'prefix' => 'post-type', 'list' => 'post-types', 'label' => 'Post Type', 'plural' => 'post types',
            'repo'   => '\ACPT\Core\Repository\CustomPostTypeRepository',
            'model'  => '\ACPT\Core\Models\CustomPostType\CustomPostTypeModel',
            'create_props' => [
                'name'     => [ 'type' => 'string', 'description' => 'Post type key (slug).' ],
                'singular' => [ 'type' => 'string' ], 'plural' => [ 'type' => 'string' ],
                'icon'     => [ 'type' => 'string' ],
                'supports' => [ 'type' => 'array' ], 'labels' => [ 'type' => 'object' ], 'settings' => [ 'type' => 'object' ],
            ],
            'create_required' => [ 'name', 'singular', 'plural' ],
            'hydrate' => function( $input, $uuid ) {
                return [
                    'id' => $uuid, 'name' => (string) $input['name'],
                    'singular' => isset( $input['singular'] ) ? (string) $input['singular'] : (string) $input['name'],
                    'plural'   => isset( $input['plural'] ) ? (string) $input['plural'] : (string) $input['name'],
                    'icon'     => isset( $input['icon'] ) ? (string) $input['icon'] : 'dashicons-admin-post',
                    'native'   => false,
                    'supports' => isset( $input['supports'] ) ? (array) $input['supports'] : [ 'title', 'editor' ],
                    'labels'   => isset( $input['labels'] ) ? (array) $input['labels'] : [],
                    'settings' => isset( $input['settings'] ) ? (array) $input['settings'] : [],
                ];
            },
            'delete' => function( $repo, $name, $input ) { return $repo::delete( $name, ! empty( $input['delete_content'] ) ); },
            'delete_props' => [ 'delete_content' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Also delete posts of this type.' ] ],
        ] );

        $this->register_model( [
            'prefix' => 'taxonomy', 'list' => 'taxonomies', 'label' => 'Taxonomy', 'plural' => 'taxonomies',
            'repo'   => '\ACPT\Core\Repository\TaxonomyRepository',
            'model'  => '\ACPT\Core\Models\Taxonomy\TaxonomyModel',
            'create_props' => [
                'slug'      => [ 'type' => 'string', 'description' => 'Taxonomy key (slug).' ],
                'singular'  => [ 'type' => 'string' ], 'plural' => [ 'type' => 'string' ],
                'post_types'=> [ 'type' => 'array', 'description' => 'Post type keys to attach to.' ],
                'labels'    => [ 'type' => 'object' ], 'settings' => [ 'type' => 'object' ],
            ],
            'create_required' => [ 'slug', 'singular', 'plural' ],
            'hydrate' => function( $input, $uuid ) {
                return [
                    'id' => $uuid, 'slug' => (string) $input['slug'],
                    'singular' => isset( $input['singular'] ) ? (string) $input['singular'] : (string) $input['slug'],
                    'plural'   => isset( $input['plural'] ) ? (string) $input['plural'] : (string) $input['slug'],
                    'native'   => false,
                    'postTypes'=> isset( $input['post_types'] ) ? (array) $input['post_types'] : [],
                    'labels'   => isset( $input['labels'] ) ? (array) $input['labels'] : [],
                    'settings' => isset( $input['settings'] ) ? (array) $input['settings'] : [],
                ];
            },
            'delete' => function( $repo, $name, $input ) { return $repo::delete( $name ); },
            'name_key' => 'slug',
        ] );

        $this->register_model( [
            'prefix' => 'option-page', 'list' => 'option-pages', 'label' => 'Option Page', 'plural' => 'option pages',
            'repo'   => '\ACPT\Core\Repository\OptionPageRepository',
            'model'  => '\ACPT\Core\Models\OptionPage\OptionPageModel',
            'create_props' => [
                'name'      => [ 'type' => 'string', 'description' => 'Option page name.' ],
                'menu_slug' => [ 'type' => 'string' ], 'menu_title' => [ 'type' => 'string' ],
                'settings'  => [ 'type' => 'object' ],
            ],
            'create_required' => [ 'name' ],
            'hydrate' => function( $input, $uuid ) {
                return [
                    'id' => $uuid, 'name' => (string) $input['name'],
                    'menuSlug'  => isset( $input['menu_slug'] ) ? (string) $input['menu_slug'] : sanitize_title( (string) $input['name'] ),
                    'menuTitle' => isset( $input['menu_title'] ) ? (string) $input['menu_title'] : (string) $input['name'],
                    'settings'  => isset( $input['settings'] ) ? (array) $input['settings'] : [],
                ];
            },
            'delete' => function( $repo, $name, $input ) { return $repo::delete( $name ); },
        ] );

        $this->register_field_groups();
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
        wp_register_ability( 'atarim/acpt-check-setup', [
            'label' => 'Check ACPT Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether ACPT is active and its version.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_acpt_is_available(), 'version' => $d->avcf_acpt_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_field_type_schema() {
        wp_register_ability( 'atarim/acpt-get-field-type-schema', [
            'label' => 'Get ACPT Field Type Schema', 'category' => 'atarim',
            'description' => 'List the common ACPT field types to use when authoring field groups.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_types' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = [ 'text', 'textarea', 'number', 'email', 'url', 'phone', 'password', 'date', 'date_time', 'time', 'select', 'select_multi', 'radio', 'checkbox', 'toggle', 'range', 'image', 'gallery', 'file', 'video', 'wysiwyg', 'editor', 'color', 'post_object', 'page_select', 'taxonomy', 'user', 'currency', 'repeater', 'flexible', 'heading', 'html' ];
                return [ 'success' => true, 'field_types' => array_map( function( $t ) { return [ 'type' => $t ]; }, $types ), 'message' => sprintf( '%d field types.', count( $types ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------- generic Repository+Model CRUD ----------------- */

    private function register_model( $cfg ) {
        $self = $this; $std = $this->std_out();
        $repo  = $cfg['repo'];
        $model = $cfg['model'];
        $prefix = $cfg['prefix'];
        $label  = $cfg['label'];
        $plural = $cfg['plural'];
        $name_key = isset( $cfg['name_key'] ) ? $cfg['name_key'] : 'name';
        $list_slug = isset( $cfg['list'] ) ? $cfg['list'] : $prefix . 's';
        $hydrate  = $cfg['hydrate'];
        $delete_cb = $cfg['delete'];

        $guard = function() use ( $repo, $label ) {
            if ( ! class_exists( $repo ) ) { return [ 'success' => false, 'message' => sprintf( 'ACPT %s repository is unavailable.', $label ) ]; }
            return null;
        };
        $find_by_name = function( $name ) use ( $repo ) {
            $all = AVCF_ACPT_Helpers::api_result( function() use ( $repo ) { return $repo::get(); } );
            if ( is_wp_error( $all ) ) { return $all; }
            foreach ( (array) $all as $m ) {
                if ( is_object( $m ) && method_exists( $m, 'getName' ) && (string) $m->getName() === (string) $name ) { return $m; }
            }
            return null;
        };

        wp_register_ability( 'atarim/acpt-list-' . $list_slug, [
            'label' => 'List ACPT ' . $label . 's', 'category' => 'atarim',
            'description' => sprintf( 'List ACPT %s.', $plural ),
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'items' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $repo ) {
                $g = $guard(); if ( $g ) { return $g; }
                $all = AVCF_ACPT_Helpers::api_result( function() use ( $repo ) { return $repo::get(); } );
                if ( is_wp_error( $all ) ) { return [ 'success' => false, 'message' => $all->get_error_message() ]; }
                $out = [];
                foreach ( (array) $all as $m ) { $out[] = AVCF_ACPT_Helpers::shape( $m ); }
                return [ 'success' => true, 'items' => $out, 'message' => sprintf( '%d item(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/acpt-get-' . $prefix, [
            'label' => 'Get ACPT ' . $label, 'category' => 'atarim',
            'description' => sprintf( 'Get one ACPT %s by %s.', $prefix, $name_key ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ $name_key => [ 'type' => 'string' ] ], 'required' => [ $name_key ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'item' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $find_by_name, $name_key, $label ) {
                $g = $guard(); if ( $g ) { return $g; }
                $m = $find_by_name( (string) $input[ $name_key ] );
                if ( is_wp_error( $m ) ) { return [ 'success' => false, 'message' => $m->get_error_message() ]; }
                if ( ! $m ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $label, $input[ $name_key ] ) ]; }
                return [ 'success' => true, 'item' => AVCF_ACPT_Helpers::shape( $m ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/acpt-create-' . $prefix, [
            'label' => 'Create ACPT ' . $label, 'category' => 'atarim',
            'description' => sprintf( 'Create an ACPT %s.', $prefix ),
            'input_schema' => [ 'type' => 'object', 'properties' => $cfg['create_props'], 'required' => $cfg['create_required'], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $repo, $model, $hydrate ) {
                $g = $guard(); if ( $g ) { return $g; }
                $uuid = AVCF_ACPT_Helpers::uuid();
                $arr  = call_user_func( $hydrate, $input, $uuid );
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $model, $repo, $arr ) {
                    $obj = $model::hydrateFromArray( $arr );
                    $repo::save( $obj );
                    return true;
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'id' => $uuid, 'message' => 'Created.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/acpt-edit-' . $prefix, [
            'label' => 'Edit ACPT ' . $label, 'category' => 'atarim',
            'description' => sprintf( 'Update an ACPT %s by %s. Supplied keys are merged into the existing definition.', $prefix, $name_key ),
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [ $name_key => [ 'type' => 'string' ] ], $cfg['create_props'] ), 'required' => [ $name_key ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $find_by_name, $repo, $model, $name_key, $label, $hydrate ) {
                $g = $guard(); if ( $g ) { return $g; }
                $existing = $find_by_name( (string) $input[ $name_key ] );
                if ( is_wp_error( $existing ) ) { return [ 'success' => false, 'message' => $existing->get_error_message() ]; }
                if ( ! $existing ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', $label, $input[ $name_key ] ) ]; }
                $base = AVCF_ACPT_Helpers::shape( $existing );
                $uuid = isset( $base['id'] ) ? $base['id'] : AVCF_ACPT_Helpers::uuid();
                // Re-hydrate from input, preserving the existing id.
                $arr = call_user_func( $hydrate, array_merge( $base, $input ), $uuid );
                $arr['id'] = $uuid;
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $model, $repo, $arr ) {
                    $obj = $model::hydrateFromArray( $arr );
                    $repo::save( $obj );
                    return true;
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        $delete_props = isset( $cfg['delete_props'] ) ? $cfg['delete_props'] : [];
        wp_register_ability( 'atarim/acpt-delete-' . $prefix, [
            'label' => 'Delete ACPT ' . $label, 'category' => 'atarim',
            'description' => sprintf( 'Delete an ACPT %s by %s. Dry run unless confirm:true.', $prefix, $name_key ),
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( [ $name_key => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], $delete_props ), 'required' => [ $name_key ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $repo, $name_key, $label, $delete_cb ) {
                $g = $guard(); if ( $g ) { return $g; }
                $name = (string) $input[ $name_key ];
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete %s "%s". Re-call with confirm:true.', $label, $name ) ]; }
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $delete_cb, $repo, $name, $input ) { return call_user_func( $delete_cb, $repo, $name, $input ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => sprintf( '%s "%s" deleted.', $label, $name ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- field groups -------------------------- */

    private function register_field_groups() {
        $self = $this; $std = $this->std_out();
        $repo = '\ACPT\Core\Repository\MetaRepository';
        $guard = function() use ( $repo ) {
            if ( ! class_exists( $repo ) ) { return [ 'success' => false, 'message' => 'ACPT MetaRepository is unavailable.' ]; }
            return null;
        };

        wp_register_ability( 'atarim/acpt-list-field-groups', [
            'label' => 'List ACPT Field Groups', 'category' => 'atarim',
            'description' => 'List ACPT meta (field) groups (id, name, label).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_groups' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $repo ) {
                $g = $guard(); if ( $g ) { return $g; }
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $repo ) { return $repo::get(); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                $out = [];
                foreach ( (array) $r as $g2 ) { $out[] = AVCF_ACPT_Helpers::shape( $g2 ); }
                return [ 'success' => true, 'field_groups' => $out, 'message' => sprintf( '%d group(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/acpt-get-field-group-schema', [
            'label' => 'Get ACPT Field Group Schema', 'category' => 'atarim',
            'description' => 'Get one ACPT meta group (with its boxes/fields) by name.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_group' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $repo ) {
                $g = $guard(); if ( $g ) { return $g; }
                $name = (string) $input['name'];
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $repo ) { return $repo::get(); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                foreach ( (array) $r as $grp ) {
                    if ( is_object( $grp ) && method_exists( $grp, 'getName' ) && (string) $grp->getName() === $name ) {
                        return [ 'success' => true, 'field_group' => AVCF_ACPT_Helpers::shape( $grp ), 'message' => 'OK.' ];
                    }
                }
                return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $name ) ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->ro_meta(),
        ] );

        $note = ' BEST-EFFORT: ACPT meta groups nest boxes and fields; the MetaGroupModel shape is intricate and unverified — validate on a live ACPT install.';
        $model = '\ACPT\Core\Models\Meta\MetaGroupModel';

        wp_register_ability( 'atarim/acpt-create-field-group', [
            'label' => 'Create ACPT Field Group', 'category' => 'atarim',
            'description' => 'Create an ACPT meta (field) group.' . $note . ' Provide name, label, and optionally boxes (array of box defs with fields).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'boxes' => [ 'type' => 'array' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $repo, $model ) {
                $g = $guard(); if ( $g ) { return $g; }
                if ( ! class_exists( $model ) || ! method_exists( $model, 'hydrateFromArray' ) ) {
                    return [ 'success' => false, 'message' => 'ACPT MetaGroupModel is not available on this build; field-group authoring needs live-ACPT validation.' ];
                }
                $uuid = AVCF_ACPT_Helpers::uuid();
                $arr  = [ 'id' => $uuid, 'name' => (string) $input['name'], 'label' => isset( $input['label'] ) ? (string) $input['label'] : (string) $input['name'], 'boxes' => isset( $input['boxes'] ) ? (array) $input['boxes'] : [] ];
                $save = method_exists( $repo, 'saveMetaGroup' ) ? 'saveMetaGroup' : 'save';
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $model, $repo, $arr, $save ) {
                    $obj = $model::hydrateFromArray( $arr );
                    call_user_func( [ $repo, $save ], $obj );
                    return true;
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'id' => $uuid, 'message' => 'Field group created (best-effort — verify in ACPT).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/acpt-edit-field-group', [
            'label' => 'Edit ACPT Field Group', 'category' => 'atarim',
            'description' => 'Update an ACPT meta group by name.' . $note,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'boxes' => [ 'type' => 'array' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $repo, $model ) {
                $g = $guard(); if ( $g ) { return $g; }
                $name = (string) $input['name'];
                $all = AVCF_ACPT_Helpers::api_result( function() use ( $repo ) { return $repo::get(); } );
                if ( is_wp_error( $all ) ) { return [ 'success' => false, 'message' => $all->get_error_message() ]; }
                $existing = null;
                foreach ( (array) $all as $grp ) { if ( is_object( $grp ) && method_exists( $grp, 'getName' ) && (string) $grp->getName() === $name ) { $existing = $grp; break; } }
                if ( ! $existing ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $name ) ]; }
                $base = AVCF_ACPT_Helpers::shape( $existing );
                $arr  = array_merge( $base, [ 'name' => $name ] );
                if ( isset( $input['label'] ) ) { $arr['label'] = (string) $input['label']; }
                if ( isset( $input['boxes'] ) ) { $arr['boxes'] = (array) $input['boxes']; }
                $save = method_exists( $repo, 'saveMetaGroup' ) ? 'saveMetaGroup' : 'save';
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $model, $repo, $arr, $save ) {
                    $obj = $model::hydrateFromArray( $arr );
                    call_user_func( [ $repo, $save ], $obj );
                    return true;
                } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Field group updated (best-effort).' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/acpt-delete-field-group', [
            'label' => 'Delete ACPT Field Group', 'category' => 'atarim',
            'description' => 'Delete an ACPT meta group by name. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $guard, $repo ) {
                $g = $guard(); if ( $g ) { return $g; }
                $name = (string) $input['name'];
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete field group "%s". Re-call with confirm:true.', $name ) ]; }
                if ( ! method_exists( $repo, 'deleteMetaGroup' ) ) { return [ 'success' => false, 'message' => 'MetaRepository::deleteMetaGroup() not available on this build.' ]; }
                $r = AVCF_ACPT_Helpers::api_result( function() use ( $repo, $name ) { return $repo::deleteMetaGroup( $name ); } );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'deleted' => true, 'message' => 'Field group deleted.' ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
