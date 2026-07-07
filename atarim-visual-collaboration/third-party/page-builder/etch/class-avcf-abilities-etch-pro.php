<?php
/**
 * Etch — Advanced MCP abilities (the "pro" file). Pass 1 here is content modeling (post types, taxonomies, custom fields, loops, queries); the design pass (components, templates, styles) is appended to this same file.
 *
 * Post types (etch_cpts), taxonomies (etch_taxonomies), custom field groups +
 * fields (etch_cfs), loops (etch_loops), and queries (etch_queries) — all plain
 * WP option maps. Definition CRUD is reliable option read/modify/write; actual
 * registration happens at Etch's runtime from these options. Site-wide writers
 * are confirm-gated. Built from the Novamira reference; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Etch_Pro extends AVCF_Abilities_Base {

    const O_CPT  = 'etch_cpts';
    const O_TAX  = 'etch_taxonomies';
    const O_CF   = 'etch_cfs';
    const O_LOOP = 'etch_loops';
    const O_QRY  = 'etch_queries';
    const O_STYLES = 'etch_styles';
    const O_SHEETS = 'etch_global_stylesheets';
    const COMPONENT_CPT = 'wp_block';
    const TEMPLATE_TAX  = 'wp_theme';

    /** @var AVCF_Etch_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Etch_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_etch_is_available() ) {
            return;
        }
        $this->register_post_types();
        $this->register_taxonomies();
        $this->register_custom_fields();
        $this->register_loops();
        $this->register_queries();
        $this->register_components();
        $this->register_templates();
        $this->register_styles();
    }

    /** Resolve the Etch template CPT (the WP block-template type). */
    public function template_cpt() {
        return defined( 'ETCH_TEMPLATE_POST_TYPE' ) ? constant( 'ETCH_TEMPLATE_POST_TYPE' ) : 'wp_template';
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
    public function opt_get( $name ) { $v = get_option( $name, [] ); return is_array( $v ) ? $v : []; }
    public function opt_set( $name, $map ) { return (bool) update_option( $name, $map ); }
    public function gen_id() { return substr( md5( uniqid( '', true ) ), 0, 10 ); }

    /* ------------------------ post types / taxonomies ------------------ */

    /** Shared slug=>args map CRUD registrar for post types & taxonomies. */
    private function register_slug_map( $opt, $singular, $extra_create = [] ) {
        $self = $this; $std = $this->std_out();
        $kind = $singular; // 'post-type' | 'taxonomy'
        $noun = str_replace( '-', ' ', $singular );

        wp_register_ability( 'atarim/etch-list-' . $kind . 's', [
            'label' => 'List Etch ' . ucwords( $noun ) . 's', 'category' => 'atarim',
            'description' => sprintf( 'List the %ss Etch registers (the %s option). Returns { slug, label } per entry; fetch full args with get-%s.', $noun, $opt, $kind ),
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'items' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $opt ) {
                $map = $self->opt_get( $opt );
                $out = [];
                foreach ( $map as $slug => $args ) {
                    $label = is_array( $args ) ? ( $args['label'] ?? ( isset( $args['labels']['name'] ) ? $args['labels']['name'] : (string) $slug ) ) : (string) $slug;
                    $out[] = [ 'slug' => (string) $slug, 'label' => $label ];
                }
                return [ 'success' => true, 'items' => $out, 'message' => sprintf( '%d %s(s).', count( $out ), $noun ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-' . $kind, [
            'label' => 'Get Etch ' . ucwords( $noun ), 'category' => 'atarim',
            'description' => sprintf( 'Get the full registration args for one %s by slug.', $noun ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ] ], 'required' => [ 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'slug' => [ 'type' => 'string' ], 'args' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $opt, $noun ) {
                $map = $self->opt_get( $opt ); $slug = (string) $input['slug'];
                if ( ! isset( $map[ $slug ] ) ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $noun ), $slug ) ]; }
                return [ 'success' => true, 'slug' => $slug, 'args' => (array) $map[ $slug ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        $create_props = array_merge( [
            'slug'    => [ 'type' => 'string' ],
            'label'   => [ 'type' => 'string' ],
            'args'    => [ 'type' => 'object', 'description' => 'Full register_' . str_replace( '-', '_', $kind ) . ' args (merged over sensible defaults).' ],
            'confirm' => [ 'type' => 'boolean', 'default' => false ],
        ], $extra_create );

        wp_register_ability( 'atarim/etch-create-' . $kind, [
            'label' => 'Create Etch ' . ucwords( $noun ), 'category' => 'atarim',
            'description' => sprintf( 'SITE-WIDE. Register a new %s. slug + label required. args is the full registration args object (merged over defaults). Dry run unless confirm:true.', $noun ) . ( $kind === 'taxonomy' ? ' object_type is the array of post-type slugs this taxonomy attaches to.' : '' ),
            'input_schema' => [ 'type' => 'object', 'properties' => $create_props, 'required' => [ 'slug', 'label' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self, $opt, $noun, $kind ) {
                $gate = $self->confirm_gate( $input, 'Registering a ' . $noun ); if ( $gate ) { return $gate; }
                $slug = sanitize_key( (string) $input['slug'] );
                if ( $slug === '' ) { return [ 'success' => false, 'message' => 'A valid slug is required.' ]; }
                $map = $self->opt_get( $opt );
                if ( isset( $map[ $slug ] ) ) { return [ 'success' => false, 'message' => sprintf( 'A %s with slug "%s" already exists.', $noun, $slug ) ]; }
                $args = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : [];
                $args['label'] = (string) $input['label'];
                if ( $kind === 'taxonomy' && isset( $input['object_type'] ) && is_array( $input['object_type'] ) ) { $args['object_type'] = array_values( array_map( 'sanitize_key', $input['object_type'] ) ); }
                if ( ! isset( $args['public'] ) ) { $args['public'] = true; }
                if ( ! isset( $args['show_in_rest'] ) ) { $args['show_in_rest'] = true; }
                if ( $kind === 'post-type' && ! isset( $args['supports'] ) ) { $args['supports'] = [ 'title', 'editor' ]; }
                $map[ $slug ] = $args;
                if ( ! $self->opt_set( $opt, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( '%s "%s" created (takes effect after Etch re-registers / next load).', ucfirst( $noun ), $slug ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-' . $kind, [
            'label' => 'Edit Etch ' . ucwords( $noun ), 'category' => 'atarim',
            'description' => sprintf( 'SITE-WIDE. Edit a %s by slug (args merged; label convenience). Dry run unless confirm:true.', $noun ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'args' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self, $opt, $noun ) {
                $gate = $self->confirm_gate( $input, 'Editing a ' . $noun ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( $opt ); $slug = (string) $input['slug'];
                if ( ! isset( $map[ $slug ] ) ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $noun ), $slug ) ]; }
                $args = is_array( $map[ $slug ] ) ? $map[ $slug ] : [];
                if ( isset( $input['args'] ) && is_array( $input['args'] ) ) { $args = array_merge( $args, $input['args'] ); }
                if ( isset( $input['label'] ) ) { $args['label'] = (string) $input['label']; }
                $map[ $slug ] = $args;
                if ( ! $self->opt_set( $opt, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( '%s "%s" updated.', ucfirst( $noun ), $slug ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-' . $kind, [
            'label' => 'Delete Etch ' . ucwords( $noun ), 'category' => 'atarim',
            'description' => sprintf( 'SITE-WIDE. Unregister a %s by slug (removes the definition; existing content is not deleted). Dry run unless confirm:true.', $noun ),
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'slug' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self, $opt, $noun ) {
                $gate = $self->confirm_gate( $input, 'Deleting a ' . $noun ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( $opt ); $slug = (string) $input['slug'];
                if ( ! isset( $map[ $slug ] ) ) { return [ 'success' => false, 'message' => sprintf( '%s "%s" not found.', ucfirst( $noun ), $slug ) ]; }
                unset( $map[ $slug ] );
                if ( ! $self->opt_set( $opt, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( '%s "%s" deleted.', ucfirst( $noun ), $slug ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    private function register_post_types() {
        $this->register_slug_map( self::O_CPT, 'post-type' );
    }
    private function register_taxonomies() {
        $this->register_slug_map( self::O_TAX, 'taxonomy', [ 'object_type' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Post-type slugs this taxonomy attaches to.' ] ] );
    }

    /* --------------------------- custom fields ------------------------- */

    private function register_custom_fields() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/etch-list-field-groups', [
            'label' => 'List Etch Field Groups', 'category' => 'atarim',
            'description' => 'List Etch custom field groups (etch_cfs): { id, label, post_types, field_count }. Fetch full definitions with get-field-group.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'groups' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF );
                $out = [];
                foreach ( $map as $id => $g ) {
                    if ( ! is_array( $g ) ) { continue; }
                    $fields = isset( $g['fields'] ) && is_array( $g['fields'] ) ? $g['fields'] : [];
                    $out[] = [ 'id' => (string) $id, 'label' => $g['label'] ?? '', 'post_types' => $g['post_types'] ?? ( $g['assigned_to'] ?? [] ), 'field_count' => count( $fields ) ];
                }
                return [ 'success' => true, 'groups' => $out, 'message' => sprintf( '%d group(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-field-group', [
            'label' => 'Get Etch Field Group', 'category' => 'atarim',
            'description' => 'Get a field group (label, post_types, and its fields) by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'group' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $id ) ]; }
                return [ 'success' => true, 'group' => array_merge( [ 'id' => $id ], (array) $map[ $id ] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-create-field-group', [
            'label' => 'Create Etch Field Group', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a custom field group. label required; post_types is the array of post-type slugs to attach to; fields is an optional initial array of field defs ({key, label, type, ...}). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'label' => [ 'type' => 'string' ], 'post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'fields' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'label' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a field group' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF );
                $id = $self->gen_id();
                $map[ $id ] = [
                    'label'      => (string) $input['label'],
                    'post_types' => isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_values( array_map( 'sanitize_key', $input['post_types'] ) ) : [],
                    'fields'     => isset( $input['fields'] ) && is_array( $input['fields'] ) ? array_values( $input['fields'] ) : [],
                ];
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_CF, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Field group created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-field-group', [
            'label' => 'Edit Etch Field Group', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a field group by id (label and/or post_types). To change fields use add/edit/delete-field. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a field group' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $id ) ]; }
                if ( isset( $input['label'] ) ) { $map[ $id ]['label'] = (string) $input['label']; }
                if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) { $map[ $id ]['post_types'] = array_values( array_map( 'sanitize_key', $input['post_types'] ) ); }
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_CF, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Field group updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-field-group', [
            'label' => 'Delete Etch Field Group', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a field group (and its fields) by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a field group' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $id ) ]; }
                unset( $map[ $id ] );
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_CF, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Field group deleted.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/etch-add-field', [
            'label' => 'Add Etch Field', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Add a field to a field group. field is the field def ({key, label, type, ...}); key must be unique within the group. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group_id' => [ 'type' => 'string' ], 'field' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'group_id', 'field' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Adding a field' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF ); $gid = (string) $input['group_id'];
                if ( ! isset( $map[ $gid ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $gid ) ]; }
                $field = is_array( $input['field'] ) ? $input['field'] : [];
                $key = isset( $field['key'] ) ? (string) $field['key'] : '';
                if ( $key === '' ) { return [ 'success' => false, 'message' => 'field.key is required.' ]; }
                $fields = isset( $map[ $gid ]['fields'] ) && is_array( $map[ $gid ]['fields'] ) ? $map[ $gid ]['fields'] : [];
                foreach ( $fields as $f ) { if ( is_array( $f ) && ( ( $f['key'] ?? null ) === $key ) ) { return [ 'success' => false, 'message' => sprintf( 'A field with key "%s" already exists in this group.', $key ) ]; } }
                $fields[] = $field;
                $map[ $gid ]['fields'] = array_values( $fields );
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_CF, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Field "%s" added.', $key ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-field', [
            'label' => 'Edit Etch Field', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a field within a group by key. patch is merged into the field def. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group_id' => [ 'type' => 'string' ], 'key' => [ 'type' => 'string' ], 'patch' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'group_id', 'key', 'patch' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a field' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF ); $gid = (string) $input['group_id']; $key = (string) $input['key'];
                if ( ! isset( $map[ $gid ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $gid ) ]; }
                $fields = isset( $map[ $gid ]['fields'] ) && is_array( $map[ $gid ]['fields'] ) ? $map[ $gid ]['fields'] : [];
                $found = false;
                foreach ( $fields as &$f ) { if ( is_array( $f ) && ( ( $f['key'] ?? null ) === $key ) ) { $f = array_merge( $f, is_array( $input['patch'] ) ? $input['patch'] : [] ); $f['key'] = $key; $found = true; break; } }
                unset( $f );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Field "%s" not found in group.', $key ) ]; }
                $map[ $gid ]['fields'] = array_values( $fields );
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_CF, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Field "%s" updated.', $key ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-field', [
            'label' => 'Delete Etch Field', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Remove a field from a group by key. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'group_id' => [ 'type' => 'string' ], 'key' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'group_id', 'key' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a field' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF ); $gid = (string) $input['group_id']; $key = (string) $input['key'];
                if ( ! isset( $map[ $gid ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $gid ) ]; }
                $fields = isset( $map[ $gid ]['fields'] ) && is_array( $map[ $gid ]['fields'] ) ? $map[ $gid ]['fields'] : [];
                $new = array_values( array_filter( $fields, function( $f ) use ( $key ) { return ! ( is_array( $f ) && ( ( $f['key'] ?? null ) === $key ) ); } ) );
                if ( count( $new ) === count( $fields ) ) { return [ 'success' => false, 'message' => sprintf( 'Field "%s" not found in group.', $key ) ]; }
                $map[ $gid ]['fields'] = $new;
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_CF, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Field "%s" deleted.', $key ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/etch-list-dynamic-fields', [
            'label' => 'List Etch Dynamic Fields', 'category' => 'atarim',
            'description' => 'List the dynamic field keys available to bind in the builder — the custom fields defined across all Etch field groups ({ key, label, type, group }). Standard post fields (title, content, excerpt, featured image, etc.) are also available in Etch independently of this list.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'fields' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_CF );
                $out = [];
                foreach ( $map as $gid => $g ) {
                    if ( ! is_array( $g ) ) { continue; }
                    foreach ( ( isset( $g['fields'] ) && is_array( $g['fields'] ) ? $g['fields'] : [] ) as $f ) {
                        if ( ! is_array( $f ) ) { continue; }
                        $out[] = [ 'key' => $f['key'] ?? '', 'label' => $f['label'] ?? '', 'type' => $f['type'] ?? '', 'group' => (string) $gid ];
                    }
                }
                return [ 'success' => true, 'fields' => $out, 'message' => sprintf( '%d dynamic field(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------------- loops ----------------------------- */

    private function register_loops() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/etch-list-loops', [
            'label' => 'List Etch Loops', 'category' => 'atarim',
            'description' => 'List Etch loops (etch_loops): { id, name, key }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'loops' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_LOOP );
                $out = [];
                foreach ( $map as $id => $l ) { if ( is_array( $l ) ) { $out[] = [ 'id' => (string) $id, 'name' => $l['name'] ?? '', 'key' => $l['key'] ?? '' ]; } }
                return [ 'success' => true, 'loops' => $out, 'message' => sprintf( '%d loop(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-loop', [
            'label' => 'Get Etch Loop', 'category' => 'atarim',
            'description' => 'Get a loop (name, key, config) by id. config.type is e.g. "wp-query" with config.args being WP_Query args.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'loop' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_LOOP ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Loop "%s" not found.', $id ) ]; }
                return [ 'success' => true, 'loop' => array_merge( [ 'id' => $id ], (array) $map[ $id ] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-create-loop', [
            'label' => 'Create Etch Loop', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a loop. name required; config is the loop config ({ type:"wp-query", args:{...WP_Query args} }) — model it on an existing loop (get-loop). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'config' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a loop' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_LOOP );
                $id = $self->gen_id();
                $map[ $id ] = [ 'name' => (string) $input['name'], 'key' => $id, 'config' => isset( $input['config'] ) && is_array( $input['config'] ) ? $input['config'] : [ 'type' => 'wp-query', 'args' => [] ], 'global' => false ];
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_LOOP, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Loop created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-loop', [
            'label' => 'Edit Etch Loop', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a loop by id (name and/or config; config merged). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'config' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a loop' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_LOOP ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Loop "%s" not found.', $id ) ]; }
                if ( isset( $input['name'] ) ) { $map[ $id ]['name'] = (string) $input['name']; }
                if ( isset( $input['config'] ) && is_array( $input['config'] ) ) {
                    $cur = isset( $map[ $id ]['config'] ) && is_array( $map[ $id ]['config'] ) ? $map[ $id ]['config'] : [];
                    $map[ $id ]['config'] = array_merge( $cur, $input['config'] );
                }
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_LOOP, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Loop updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-loop', [
            'label' => 'Delete Etch Loop', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a loop by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a loop' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_LOOP ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Loop "%s" not found.', $id ) ]; }
                unset( $map[ $id ] );
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_LOOP, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Loop deleted.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ queries ---------------------------- */

    private function register_queries() {
        $self = $this;

        wp_register_ability( 'atarim/etch-list-queries', [
            'label' => 'List Etch Queries', 'category' => 'atarim',
            'description' => 'List saved Etch queries (etch_queries): { id, name }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'queries' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_QRY );
                $out = [];
                foreach ( $map as $id => $q ) { if ( is_array( $q ) ) { $out[] = [ 'id' => (string) $id, 'name' => $q['name'] ?? '' ]; } }
                return [ 'success' => true, 'queries' => $out, 'message' => sprintf( '%d query(ies).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-query', [
            'label' => 'Get Etch Query', 'category' => 'atarim',
            'description' => 'Get a saved query (name, args) by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'query' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_QRY ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Query "%s" not found.', $id ) ]; }
                return [ 'success' => true, 'query' => array_merge( [ 'id' => $id ], (array) $map[ $id ] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-query-preview', [
            'label' => 'Get Etch Query Preview', 'category' => 'atarim',
            'description' => 'Run a query and return a preview of matched posts ({ id, title, type }). Provide query_id (a saved query) OR args (WP_Query args directly). Capped at 20 results.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'query_id' => [ 'type' => 'string' ], 'args' => [ 'type' => 'object' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'found_posts' => [ 'type' => 'integer' ], 'posts' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $args = [];
                if ( isset( $input['args'] ) && is_array( $input['args'] ) ) { $args = $input['args']; }
                elseif ( isset( $input['query_id'] ) ) {
                    $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_QRY ); $id = (string) $input['query_id'];
                    if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Query "%s" not found.', $id ) ]; }
                    $args = isset( $map[ $id ]['args'] ) && is_array( $map[ $id ]['args'] ) ? $map[ $id ]['args'] : [];
                } else {
                    return [ 'success' => false, 'message' => 'Provide query_id or args.' ];
                }
                $args['posts_per_page'] = min( 20, isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20 );
                $args['fields'] = 'ids';
                $q = new WP_Query( $args );
                $posts = [];
                foreach ( $q->posts as $pid ) { $posts[] = [ 'id' => (int) $pid, 'title' => get_the_title( $pid ), 'type' => get_post_type( $pid ) ]; }
                return [ 'success' => true, 'found_posts' => (int) $q->found_posts, 'posts' => $posts, 'message' => sprintf( '%d found; showing %d.', (int) $q->found_posts, count( $posts ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- components --------------------------- */

    private function register_components() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/etch-list-components', [
            'label' => 'List Etch Components', 'category' => 'atarim',
            'description' => 'List Etch components (reusable-block-backed): { id, name, key }. Components are wp_block posts carrying Etch component metadata.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'components' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $posts = get_posts( [ 'post_type' => AVCF_Abilities_Etch_Pro::COMPONENT_CPT, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => 'etch_component_html_key' ] );
                $out = [];
                foreach ( $posts as $p ) { $out[] = [ 'id' => $p->ID, 'name' => $p->post_title, 'key' => (string) get_post_meta( $p->ID, 'etch_component_html_key', true ) ]; }
                return [ 'success' => true, 'components' => $out, 'message' => sprintf( '%d component(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-component', [
            'label' => 'Get Etch Component', 'category' => 'atarim',
            'description' => 'Get one component by id: name, key, description, properties, and its element tree (parsed from the block content). lossy:true means it holds freeform HTML the tree can\'t represent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'component' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $id = (int) $input['id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Abilities_Etch_Pro::COMPONENT_CPT ) { return [ 'success' => false, 'message' => 'Not an Etch component id.' ]; }
                $props = get_post_meta( $id, 'etch_component_properties', true );
                $tree = AVCF_Etch_Helpers::parse_tree( $p->post_content );
                return [ 'success' => true, 'component' => [
                    'id' => $id, 'name' => $p->post_title, 'key' => (string) get_post_meta( $id, 'etch_component_html_key', true ),
                    'description' => $p->post_excerpt, 'properties' => is_array( $props ) ? $props : [],
                    'tree' => $tree, 'node_count' => AVCF_Etch_Helpers::tree_count( $tree ), 'lossy' => AVCF_Etch_Helpers::is_lossy( $p->post_content ),
                ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-create-component', [
            'label' => 'Create Etch Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a component. name required; description optional; tree is the element tree ([{block,attrs?,children?,html?}], serialized to block content); properties is the component\'s props object. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'name' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ],
                'tree' => [ 'type' => 'array' ], 'properties' => [ 'type' => 'object' ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'key' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a component' ); if ( $gate ) { return $gate; }
                $content = '';
                if ( isset( $input['tree'] ) && is_array( $input['tree'] ) ) { $content = AVCF_Etch_Helpers::serialize_tree( AVCF_Etch_Helpers::sanitize_tree( $input['tree'] ) ); }
                $id = wp_insert_post( [ 'post_type' => AVCF_Abilities_Etch_Pro::COMPONENT_CPT, 'post_title' => (string) $input['name'], 'post_excerpt' => isset( $input['description'] ) ? (string) $input['description'] : '', 'post_content' => $content, 'post_status' => 'publish' ], true );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                $key = 'c' . substr( md5( uniqid( 'etch_component', true ) ), 0, 12 );
                update_post_meta( $id, 'etch_component_html_key', $key );
                update_post_meta( $id, 'etch_component_properties', isset( $input['properties'] ) && is_array( $input['properties'] ) ? $input['properties'] : [] );
                return [ 'success' => true, 'id' => (int) $id, 'key' => $key, 'message' => 'Component created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-component', [
            'label' => 'Edit Etch Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a component by id (name, description, tree, and/or properties). tree replaces the block content; properties replaces the props object. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'name' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ],
                'tree' => [ 'type' => 'array' ], 'properties' => [ 'type' => 'object' ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a component' ); if ( $gate ) { return $gate; }
                $id = (int) $input['id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Abilities_Etch_Pro::COMPONENT_CPT ) { return [ 'success' => false, 'message' => 'Not an Etch component id.' ]; }
                $up = [ 'ID' => $id ];
                if ( isset( $input['name'] ) ) { $up['post_title'] = (string) $input['name']; }
                if ( isset( $input['description'] ) ) { $up['post_excerpt'] = (string) $input['description']; }
                if ( isset( $input['tree'] ) && is_array( $input['tree'] ) ) { $up['post_content'] = AVCF_Etch_Helpers::serialize_tree( AVCF_Etch_Helpers::sanitize_tree( $input['tree'] ) ); }
                if ( count( $up ) > 1 ) { $r = wp_update_post( $up, true ); if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; } }
                if ( isset( $input['properties'] ) && is_array( $input['properties'] ) ) { update_post_meta( $id, 'etch_component_properties', $input['properties'] ); }
                return [ 'success' => true, 'message' => sprintf( 'Component %d updated.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-component', [
            'label' => 'Delete Etch Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a component by id. Dry run unless confirm:true. (Instances referencing it may break.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Abilities_Etch_Pro::COMPONENT_CPT ) { return [ 'success' => false, 'message' => 'Not an Etch component id.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: would delete component %d. Re-call with confirm:true.', $id ) ]; }
                $r = wp_delete_post( $id, true );
                return $r ? [ 'success' => true, 'message' => sprintf( 'Component %d deleted.', $id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/etch-duplicate-component', [
            'label' => 'Duplicate Etch Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Duplicate a component by id (new post + new component key, title suffixed "(copy)"). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'name' => [ 'type' => 'string', 'description' => 'Optional name for the copy.' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'key' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Duplicating a component' ); if ( $gate ) { return $gate; }
                $id = (int) $input['id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Abilities_Etch_Pro::COMPONENT_CPT ) { return [ 'success' => false, 'message' => 'Not an Etch component id.' ]; }
                $title = isset( $input['name'] ) ? (string) $input['name'] : ( $p->post_title . ' (copy)' );
                $new = wp_insert_post( [ 'post_type' => AVCF_Abilities_Etch_Pro::COMPONENT_CPT, 'post_title' => $title, 'post_excerpt' => $p->post_excerpt, 'post_content' => $p->post_content, 'post_status' => 'publish' ], true );
                if ( is_wp_error( $new ) ) { return [ 'success' => false, 'message' => $new->get_error_message() ]; }
                $key = 'c' . substr( md5( uniqid( 'etch_component', true ) ), 0, 12 );
                update_post_meta( $new, 'etch_component_html_key', $key );
                $props = get_post_meta( $id, 'etch_component_properties', true );
                update_post_meta( $new, 'etch_component_properties', is_array( $props ) ? $props : [] );
                return [ 'success' => true, 'id' => (int) $new, 'key' => $key, 'message' => 'Component duplicated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- templates --------------------------- */

    private function register_templates() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/etch-list-templates', [
            'label' => 'List Etch Templates', 'category' => 'atarim',
            'description' => 'List Etch templates (WP block templates): { id, title, slug, theme }. The slug is the template-hierarchy slot (e.g. "single", "archive"); theme is the bound wp_theme term.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'templates' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $posts = get_posts( [ 'post_type' => $self->template_cpt(), 'post_status' => 'any', 'numberposts' => -1 ] );
                $out = [];
                foreach ( $posts as $p ) {
                    $terms = wp_get_post_terms( $p->ID, AVCF_Abilities_Etch_Pro::TEMPLATE_TAX, [ 'fields' => 'slugs' ] );
                    $out[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'theme' => ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : '' ];
                }
                return [ 'success' => true, 'templates' => $out, 'message' => sprintf( '%d template(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-template', [
            'label' => 'Get Etch Template', 'category' => 'atarim',
            'description' => 'Get one template by id: title, slug, theme. (Manage the template\'s element content with the core get-content/set-content abilities using its id.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'template' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== $self->template_cpt() ) { return [ 'success' => false, 'message' => 'Not an Etch template id.' ]; }
                $terms = wp_get_post_terms( $id, AVCF_Abilities_Etch_Pro::TEMPLATE_TAX, [ 'fields' => 'slugs' ] );
                return [ 'success' => true, 'template' => [ 'id' => $id, 'title' => $p->post_title, 'slug' => $p->post_name, 'theme' => ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : '' ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-create-template', [
            'label' => 'Create Etch Template', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a template. title + slug required (slug = the hierarchy slot, e.g. "single"); theme is the wp_theme term to bind to (defaults to the active theme). Element content is empty — author it via set-content with the new id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ], 'theme' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'title', 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a template' ); if ( $gate ) { return $gate; }
                $slug = sanitize_title( (string) $input['slug'] );
                if ( $slug === '' ) { return [ 'success' => false, 'message' => 'A valid slug is required.' ]; }
                $id = wp_insert_post( [ 'post_type' => $self->template_cpt(), 'post_title' => (string) $input['title'], 'post_name' => $slug, 'post_status' => 'publish' ], true );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                $theme = isset( $input['theme'] ) && $input['theme'] !== '' ? (string) $input['theme'] : get_stylesheet();
                wp_set_post_terms( $id, [ $theme ], AVCF_Abilities_Etch_Pro::TEMPLATE_TAX );
                return [ 'success' => true, 'id' => (int) $id, 'message' => 'Template created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-template', [
            'label' => 'Edit Etch Template', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a template by id (title, slug, and/or theme binding). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'title' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ], 'theme' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a template' ); if ( $gate ) { return $gate; }
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== $self->template_cpt() ) { return [ 'success' => false, 'message' => 'Not an Etch template id.' ]; }
                $up = [ 'ID' => $id ];
                if ( isset( $input['title'] ) ) { $up['post_title'] = (string) $input['title']; }
                if ( isset( $input['slug'] ) ) { $up['post_name'] = sanitize_title( (string) $input['slug'] ); }
                if ( count( $up ) > 1 ) { $r = wp_update_post( $up, true ); if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; } }
                if ( isset( $input['theme'] ) ) { wp_set_post_terms( $id, [ (string) $input['theme'] ], AVCF_Abilities_Etch_Pro::TEMPLATE_TAX ); }
                return [ 'success' => true, 'message' => sprintf( 'Template %d updated.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-template', [
            'label' => 'Delete Etch Template', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a template by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== $self->template_cpt() ) { return [ 'success' => false, 'message' => 'Not an Etch template id.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: would delete template %d. Re-call with confirm:true.', $id ) ]; }
                $r = wp_delete_post( $id, true );
                return $r ? [ 'success' => true, 'message' => sprintf( 'Template %d deleted.', $id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/etch-duplicate-template', [
            'label' => 'Duplicate Etch Template', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Duplicate a template by id (copies content + theme binding; new slug required to avoid a hierarchy clash). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'slug' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'template_id', 'slug' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Duplicating a template' ); if ( $gate ) { return $gate; }
                $id = (int) $input['template_id']; $p = get_post( $id );
                if ( ! $p || $p->post_type !== $self->template_cpt() ) { return [ 'success' => false, 'message' => 'Not an Etch template id.' ]; }
                $slug = sanitize_title( (string) $input['slug'] );
                if ( $slug === '' ) { return [ 'success' => false, 'message' => 'A valid slug is required.' ]; }
                $new = wp_insert_post( [ 'post_type' => $self->template_cpt(), 'post_title' => isset( $input['title'] ) ? (string) $input['title'] : ( $p->post_title . ' (copy)' ), 'post_name' => $slug, 'post_content' => $p->post_content, 'post_status' => 'publish' ], true );
                if ( is_wp_error( $new ) ) { return [ 'success' => false, 'message' => $new->get_error_message() ]; }
                $terms = wp_get_post_terms( $id, AVCF_Abilities_Etch_Pro::TEMPLATE_TAX, [ 'fields' => 'slugs' ] );
                if ( ! is_wp_error( $terms ) && $terms ) { wp_set_post_terms( $new, $terms, AVCF_Abilities_Etch_Pro::TEMPLATE_TAX ); }
                return [ 'success' => true, 'id' => (int) $new, 'message' => 'Template duplicated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- styles / stylesheets -------------------- */

    private function register_styles() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/etch-get-styles', [
            'label' => 'Get Etch Styles', 'category' => 'atarim',
            'description' => 'Read the Etch styles layer (the etch_styles option) — the design tokens/variables layer. Returns the raw structure (empty is normal before any styles are saved).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'styles' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                return [ 'success' => true, 'styles' => $self->opt_get( AVCF_Abilities_Etch_Pro::O_STYLES ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-list-stylesheets', [
            'label' => 'List Etch Stylesheets', 'category' => 'atarim',
            'description' => 'List Etch global stylesheets (etch_global_stylesheets): { id, name }. Fetch CSS with get-stylesheet.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stylesheets' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_SHEETS );
                $out = [];
                foreach ( $map as $id => $s ) { if ( is_array( $s ) ) { $out[] = [ 'id' => (string) $id, 'name' => $s['name'] ?? '' ]; } }
                return [ 'success' => true, 'stylesheets' => $out, 'message' => sprintf( '%d stylesheet(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-get-stylesheet', [
            'label' => 'Get Etch Stylesheet', 'category' => 'atarim',
            'description' => 'Get a global stylesheet by id: { id, name, css }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stylesheet' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_SHEETS ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) || ! is_array( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Stylesheet "%s" not found.', $id ) ]; }
                return [ 'success' => true, 'stylesheet' => [ 'id' => $id, 'name' => $map[ $id ]['name'] ?? '', 'css' => $map[ $id ]['css'] ?? '' ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/etch-create-stylesheet', [
            'label' => 'Create Etch Stylesheet', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a global stylesheet. name required; css is the stylesheet body. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'css' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a stylesheet' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_SHEETS );
                $id = $self->gen_id();
                $map[ $id ] = [ 'name' => (string) $input['name'], 'css' => isset( $input['css'] ) ? (string) $input['css'] : '' ];
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_SHEETS, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'id' => $id, 'message' => 'Stylesheet created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-edit-stylesheet', [
            'label' => 'Edit Etch Stylesheet', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a stylesheet by id (name and/or css). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'css' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a stylesheet' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_SHEETS ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) || ! is_array( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Stylesheet "%s" not found.', $id ) ]; }
                if ( isset( $input['name'] ) ) { $map[ $id ]['name'] = (string) $input['name']; }
                if ( isset( $input['css'] ) ) { $map[ $id ]['css'] = (string) $input['css']; }
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_SHEETS, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Stylesheet "%s" updated.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/etch-delete-stylesheet', [
            'label' => 'Delete Etch Stylesheet', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a stylesheet by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a stylesheet' ); if ( $gate ) { return $gate; }
                $map = $self->opt_get( AVCF_Abilities_Etch_Pro::O_SHEETS ); $id = (string) $input['id'];
                if ( ! isset( $map[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Stylesheet "%s" not found.', $id ) ]; }
                unset( $map[ $id ] );
                if ( ! $self->opt_set( AVCF_Abilities_Etch_Pro::O_SHEETS, $map ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Stylesheet "%s" deleted.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
