<?php
/**
 * Bricks — Advanced MCP abilities.
 *
 * The deeper Bricks surfaces beyond the core content tree. Most are site-wide
 * option arrays (global classes, theme styles, color palette, variables,
 * components), so they are more tractable than Elementor's advanced set — but
 * still untested here and internal to Bricks, so treat as best-effort and
 * validate on a live install. Site-wide writers are dry-run unless confirm:true.
 * Interactions edit element settings in the content tree; dynamic data is
 * read/resolve only.
 *
 * Storage keys (Bricks constants, with documented fallbacks):
 *   global classes   BRICKS_DB_GLOBAL_CLASSES
 *   variables         BRICKS_DB_GLOBAL_VARIABLES
 *   components        BRICKS_DB_COMPONENTS
 *   theme styles      BRICKS_DB_THEME_STYLES   (assoc keyed by id)
 *   color palette     BRICKS_DB_COLOR_PALETTE  (palettes -> colors)
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Bricks_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Bricks_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Bricks_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_bricks_is_available() ) {
            return;
        }
        $this->register_global_classes();
        $this->register_variables();
        $this->register_components();
        $this->register_theme_styles();
        $this->register_color_palette();
        $this->register_interactions();
        $this->register_dynamic_data();
        $this->register_templates();
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
        if ( empty( $input['confirm'] ) ) {
            return [ 'success' => true, 'applied' => false, 'message' => sprintf( 'Dry run: %s is site-wide (affects every page using it). Re-call with confirm:true.', $what ) ];
        }
        return null;
    }
    private function opt_key( $which ) {
        $map = [
            'global_classes' => [ 'BRICKS_DB_GLOBAL_CLASSES', 'bricks_global_classes' ],
            'variables'      => [ 'BRICKS_DB_GLOBAL_VARIABLES', 'bricks_global_variables' ],
            'components'     => [ 'BRICKS_DB_COMPONENTS', 'bricks_components' ],
            'theme_styles'   => [ 'BRICKS_DB_THEME_STYLES', 'bricks_theme_styles' ],
            'color_palette'  => [ 'BRICKS_DB_COLOR_PALETTE', 'bricks_color_palette' ],
        ];
        list( $const, $fallback ) = $map[ $which ];
        return defined( $const ) ? constant( $const ) : $fallback;
    }
    private function store_read( $which ) {
        $v = get_option( $this->opt_key( $which ), [] );
        return is_array( $v ) ? $v : [];
    }
    private function store_write( $which, $arr ) {
        return (bool) update_option( $this->opt_key( $which ), $arr );
    }

    /* ----- generic id-list helpers (indexed array of {id,...} entries) ---- */

    private function idlist_find( $which, $id ) {
        foreach ( $this->store_read( $which ) as $e ) {
            if ( is_array( $e ) && ( ( $e['id'] ?? null ) === $id ) ) { return $e; }
        }
        return null;
    }
    private function idlist_create( $which, $entry ) {
        $arr = $this->store_read( $which );
        $arr[] = $entry;
        $this->store_write( $which, array_values( $arr ) );
        return $entry['id'];
    }
    private function idlist_edit( $which, $id, $patch ) {
        $arr = $this->store_read( $which ); $found = false;
        foreach ( $arr as &$e ) {
            if ( is_array( $e ) && ( ( $e['id'] ?? null ) === $id ) ) { $e = array_merge( $e, $patch ); $found = true; break; }
        }
        unset( $e );
        if ( $found ) { $this->store_write( $which, array_values( $arr ) ); }
        return $found;
    }
    private function idlist_delete( $which, $id ) {
        $arr = $this->store_read( $which );
        $new = array_values( array_filter( $arr, function( $e ) use ( $id ) { return ! ( is_array( $e ) && ( ( $e['id'] ?? null ) === $id ) ); } ) );
        $changed = count( $new ) !== count( $arr );
        if ( $changed ) { $this->store_write( $which, $new ); }
        return $changed;
    }

    /* -------------------------- global classes ------------------------- */

    private function register_global_classes() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/bricks-list-global-classes', [
            'label' => 'List Bricks Global Classes', 'category' => 'atarim',
            'description' => 'List Bricks global CSS classes (id, name, category). Pass include_settings:true to inline each class\'s settings (large).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'category' => [ 'type' => 'string' ], 'include_settings' => [ 'type' => 'boolean', 'default' => false ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'classes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $classes = $self->store_read( 'global_classes' );
                $cat = isset( $input['category'] ) ? (string) $input['category'] : '';
                $inc = ! empty( $input['include_settings'] );
                $out = [];
                foreach ( $classes as $c ) {
                    if ( ! is_array( $c ) ) { continue; }
                    if ( $cat !== '' && ( $c['category'] ?? null ) !== $cat ) { continue; }
                    $e = [ 'id' => $c['id'] ?? null, 'name' => $c['name'] ?? '', 'category' => $c['category'] ?? null ];
                    if ( $inc ) { $e['settings'] = $c['settings'] ?? []; }
                    $out[] = $e;
                }
                return [ 'success' => true, 'classes' => $out, 'message' => sprintf( '%d class(es).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-get-global-class', [
            'label' => 'Get Bricks Global Class', 'category' => 'atarim',
            'description' => 'Get a global class (full settings) by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'class' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $c = $self->idlist_find( 'global_classes', (string) $input['id'] );
                return $c ? [ 'success' => true, 'class' => $c, 'message' => 'OK.' ] : [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-create-global-class', [
            'label' => 'Create Bricks Global Class', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a global CSS class. Provide name; settings is the Bricks settings object; category optional. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'category' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a global class' ); if ( $gate ) { return $gate; }
                $entry = [ 'id' => AVCF_Bricks_Helpers::generate_id(), 'name' => (string) $input['name'], 'settings' => isset( $input['settings'] ) ? (array) $input['settings'] : [] ];
                if ( isset( $input['category'] ) ) { $entry['category'] = (string) $input['category']; }
                $id = $self->idlist_create( 'global_classes', $entry );
                return [ 'success' => true, 'applied' => true, 'id' => $id, 'message' => 'Global class created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-edit-global-class', [
            'label' => 'Edit Bricks Global Class', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a global class by id (name / settings / category merged in). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'category' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a global class' ); if ( $gate ) { return $gate; }
                $patch = [];
                foreach ( [ 'name', 'category' ] as $k ) { if ( isset( $input[ $k ] ) ) { $patch[ $k ] = (string) $input[ $k ]; } }
                if ( isset( $input['settings'] ) ) { $patch['settings'] = (array) $input['settings']; }
                $ok = $self->idlist_edit( 'global_classes', (string) $input['id'], $patch );
                return $ok ? [ 'success' => true, 'message' => 'Global class updated.' ] : [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-delete-global-class', [
            'label' => 'Delete Bricks Global Class', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a global class by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a global class' ); if ( $gate ) { return $gate; }
                $ok = $self->idlist_delete( 'global_classes', (string) $input['id'] );
                return $ok ? [ 'success' => true, 'message' => 'Global class deleted.' ] : [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/bricks-apply-global-class', [
            'label' => 'Apply Bricks Global Class', 'category' => 'atarim',
            'description' => 'Apply (or remove) a global class on one element of a post by adding/removing its class id in the element\'s _cssGlobalClasses setting. Per-post, not site-wide.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'area' => [ 'type' => 'string', 'enum' => [ 'content', 'header', 'footer' ], 'default' => 'content' ], 'element_id' => [ 'type' => 'string' ], 'class_id' => [ 'type' => 'string' ], 'remove' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'element_id', 'class_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! ( current_user_can( 'edit_post', $post_id ) || current_user_can( 'edit_posts' ) ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $cid  = (string) $input['class_id'];
                $els  = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                $found = false;
                $els = AVCF_Bricks_Helpers::patch( $els, (string) $input['element_id'], [], $found ); // no-op patch to locate
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $input['element_id'] ) ]; }
                // Apply class to the located element's settings._cssGlobalClasses.
                foreach ( $els as &$el ) {
                    if ( is_array( $el ) && ( ( $el['id'] ?? null ) === (string) $input['element_id'] ) ) {
                        $settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
                        $list = isset( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ? $settings['_cssGlobalClasses'] : [];
                        if ( ! empty( $input['remove'] ) ) {
                            $list = array_values( array_filter( $list, function( $x ) use ( $cid ) { return $x !== $cid; } ) );
                        } elseif ( ! in_array( $cid, $list, true ) ) {
                            $list[] = $cid;
                        }
                        $settings['_cssGlobalClasses'] = $list;
                        $el['settings'] = $settings;
                        break;
                    }
                }
                unset( $el );
                if ( ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $els ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => ! empty( $input['remove'] ) ? 'Class removed from element.' : 'Class applied to element.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- variables --------------------------- */

    private function register_variables() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/bricks-list-variables', [
            'label' => 'List Bricks Variables', 'category' => 'atarim',
            'description' => 'List Bricks global variables (id, name, value, category).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'category' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'variables' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $vars = $self->store_read( 'variables' );
                $cat = isset( $input['category'] ) ? (string) $input['category'] : '';
                $out = [];
                foreach ( $vars as $v ) {
                    if ( ! is_array( $v ) ) { continue; }
                    if ( $cat !== '' && ( $v['category'] ?? null ) !== $cat ) { continue; }
                    $out[] = [ 'id' => $v['id'] ?? null, 'name' => $v['name'] ?? '', 'value' => $v['value'] ?? null, 'category' => $v['category'] ?? null ];
                }
                return [ 'success' => true, 'variables' => $out, 'message' => sprintf( '%d variable(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-get-variable', [
            'label' => 'Get Bricks Variable', 'category' => 'atarim',
            'description' => 'Get a global variable by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'variable' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $v = $self->idlist_find( 'variables', (string) $input['id'] );
                return $v ? [ 'success' => true, 'variable' => $v, 'message' => 'OK.' ] : [ 'success' => false, 'message' => sprintf( 'Variable "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-create-variable', [
            'label' => 'Create Bricks Variable', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a global variable. Provide name and value (e.g. "--primary" / "#3366ff"); category optional. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'category' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name', 'value' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a variable' ); if ( $gate ) { return $gate; }
                $entry = [ 'id' => AVCF_Bricks_Helpers::generate_id(), 'name' => (string) $input['name'], 'value' => (string) $input['value'] ];
                if ( isset( $input['category'] ) ) { $entry['category'] = (string) $input['category']; }
                $id = $self->idlist_create( 'variables', $entry );
                return [ 'success' => true, 'applied' => true, 'id' => $id, 'message' => 'Variable created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-edit-variable', [
            'label' => 'Edit Bricks Variable', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a global variable by id (name / value / category). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'category' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a variable' ); if ( $gate ) { return $gate; }
                $patch = [];
                foreach ( [ 'name', 'value', 'category' ] as $k ) { if ( isset( $input[ $k ] ) ) { $patch[ $k ] = (string) $input[ $k ]; } }
                $ok = $self->idlist_edit( 'variables', (string) $input['id'], $patch );
                return $ok ? [ 'success' => true, 'message' => 'Variable updated.' ] : [ 'success' => false, 'message' => sprintf( 'Variable "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-delete-variable', [
            'label' => 'Delete Bricks Variable', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a global variable by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a variable' ); if ( $gate ) { return $gate; }
                $ok = $self->idlist_delete( 'variables', (string) $input['id'] );
                return $ok ? [ 'success' => true, 'message' => 'Variable deleted.' ] : [ 'success' => false, 'message' => sprintf( 'Variable "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- components -------------------------- */

    private function register_components() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/bricks-list-components', [
            'label' => 'List Bricks Components', 'category' => 'atarim',
            'description' => 'List Bricks components (id, name). Pass include_elements:true to inline each component\'s element tree (large).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'include_elements' => [ 'type' => 'boolean', 'default' => false ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'components' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $inc = ! empty( $input['include_elements'] );
                $out = [];
                foreach ( $self->store_read( 'components' ) as $c ) {
                    if ( ! is_array( $c ) ) { continue; }
                    $e = [ 'id' => $c['id'] ?? null, 'name' => $c['name'] ?? ( $c['label'] ?? '' ) ];
                    if ( $inc ) { $e['elements'] = $c['elements'] ?? []; }
                    $out[] = $e;
                }
                return [ 'success' => true, 'components' => $out, 'message' => sprintf( '%d component(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-create-component', [
            'label' => 'Create Bricks Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a component. Provide name and elements (the component\'s element tree, same flat shape as page content). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ], 'elements' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a component' ); if ( $gate ) { return $gate; }
                $entry = [ 'id' => AVCF_Bricks_Helpers::generate_id(), 'name' => (string) $input['name'], 'elements' => isset( $input['elements'] ) ? AVCF_Bricks_Helpers::normalize( (array) $input['elements'] ) : [] ];
                $id = $self->idlist_create( 'components', $entry );
                return [ 'success' => true, 'applied' => true, 'id' => $id, 'message' => 'Component created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-edit-component', [
            'label' => 'Edit Bricks Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a component by id (name and/or elements). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'elements' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a component' ); if ( $gate ) { return $gate; }
                $patch = [];
                if ( isset( $input['name'] ) ) { $patch['name'] = (string) $input['name']; }
                if ( isset( $input['elements'] ) ) { $patch['elements'] = AVCF_Bricks_Helpers::normalize( (array) $input['elements'] ); }
                $ok = $self->idlist_edit( 'components', (string) $input['id'], $patch );
                return $ok ? [ 'success' => true, 'message' => 'Component updated.' ] : [ 'success' => false, 'message' => sprintf( 'Component "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-delete-component', [
            'label' => 'Delete Bricks Component', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a component by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a component' ); if ( $gate ) { return $gate; }
                $ok = $self->idlist_delete( 'components', (string) $input['id'] );
                return $ok ? [ 'success' => true, 'message' => 'Component deleted.' ] : [ 'success' => false, 'message' => sprintf( 'Component "%s" not found.', $input['id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/bricks-apply-component', [
            'label' => 'Apply Bricks Component', 'category' => 'atarim',
            'description' => 'Insert an instance of a component into a post\'s area. Adds a component-instance element referencing the component id under parent_id (omit for top level). Per-post.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'area' => [ 'type' => 'string', 'enum' => [ 'content', 'header', 'footer' ], 'default' => 'content' ], 'component_id' => [ 'type' => 'string' ], 'parent_id' => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'component_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'element_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! ( current_user_can( 'edit_post', $post_id ) || current_user_can( 'edit_posts' ) ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                if ( $self->idlist_find( 'components', (string) $input['component_id'] ) === null ) { return [ 'success' => false, 'message' => sprintf( 'Component "%s" not found.', $input['component_id'] ) ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $node = [ 'id' => AVCF_Bricks_Helpers::generate_id(), 'name' => 'component', 'settings' => [], 'children' => [], 'cid' => (string) $input['component_id'] ];
                $els = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                list( $els, $ok ) = AVCF_Bricks_Helpers::insert( $els, isset( $input['parent_id'] ) ? (string) $input['parent_id'] : 0, $node );
                if ( ! $ok ) { return [ 'success' => false, 'message' => sprintf( 'parent_id "%s" not found.', $input['parent_id'] ?? '' ) ]; }
                if ( ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $els ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'element_id' => $node['id'], 'message' => 'Component instance inserted.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- theme styles -------------------------- */

    private function register_theme_styles() {
        $self = $this; $std = $this->std_out();
        $key = 'theme_styles';

        wp_register_ability( 'atarim/bricks-list-theme-styles', [
            'label' => 'List Bricks Theme Styles', 'category' => 'atarim',
            'description' => 'List Bricks theme styles (id, label, conditions). Theme styles are stored keyed by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'theme_styles' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $store = $self->store_read( 'theme_styles' );
                $out = [];
                foreach ( $store as $id => $entry ) {
                    if ( ! is_array( $entry ) ) { continue; }
                    $out[] = [ 'id' => (string) $id, 'label' => $entry['label'] ?? '' ];
                }
                return [ 'success' => true, 'theme_styles' => $out, 'message' => sprintf( '%d theme style(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-get-theme-style', [
            'label' => 'Get Bricks Theme Style', 'category' => 'atarim',
            'description' => 'Get a theme style (label + settings) by id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'theme_style' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $store = $self->store_read( 'theme_styles' ); $id = (string) $input['id'];
                if ( ! isset( $store[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Theme style "%s" not found.', $id ) ]; }
                return [ 'success' => true, 'theme_style' => array_merge( [ 'id' => $id ], (array) $store[ $id ] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-create-theme-style', [
            'label' => 'Create Bricks Theme Style', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Create a theme style. Provide label; settings (typography/colors/etc.) and conditions optional. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'label' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'label' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Creating a theme style' ); if ( $gate ) { return $gate; }
                $store = $self->store_read( 'theme_styles' );
                $id = AVCF_Bricks_Helpers::generate_id();
                $store[ $id ] = [ 'label' => (string) $input['label'], 'settings' => isset( $input['settings'] ) ? (array) $input['settings'] : [] ];
                $self->store_write( 'theme_styles', $store );
                return [ 'success' => true, 'applied' => true, 'id' => $id, 'message' => 'Theme style created.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-edit-theme-style', [
            'label' => 'Edit Bricks Theme Style', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a theme style by id (label and/or settings merged). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Editing a theme style' ); if ( $gate ) { return $gate; }
                $store = $self->store_read( 'theme_styles' ); $id = (string) $input['id'];
                if ( ! isset( $store[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Theme style "%s" not found.', $id ) ]; }
                if ( isset( $input['label'] ) ) { $store[ $id ]['label'] = (string) $input['label']; }
                if ( isset( $input['settings'] ) ) {
                    $cur = isset( $store[ $id ]['settings'] ) && is_array( $store[ $id ]['settings'] ) ? $store[ $id ]['settings'] : [];
                    $store[ $id ]['settings'] = array_merge( $cur, (array) $input['settings'] );
                }
                $self->store_write( 'theme_styles', $store );
                return [ 'success' => true, 'message' => 'Theme style updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-delete-theme-style', [
            'label' => 'Delete Bricks Theme Style', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a theme style by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $gate = $self->confirm_gate( $input, 'Deleting a theme style' ); if ( $gate ) { return $gate; }
                $store = $self->store_read( 'theme_styles' ); $id = (string) $input['id'];
                if ( ! isset( $store[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Theme style "%s" not found.', $id ) ]; }
                unset( $store[ $id ] );
                $self->store_write( 'theme_styles', $store );
                return [ 'success' => true, 'message' => 'Theme style deleted.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- color palette ------------------------- */

    private function register_color_palette() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/bricks-list-color-palette', [
            'label' => 'List Bricks Color Palette', 'category' => 'atarim',
            'description' => 'List Bricks color palettes and their colors. Pass palette_id to flatten just one palette\'s colors.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'palette_id' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'palettes' => [ 'type' => 'array' ], 'colors' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $palettes = array_values( $self->store_read( 'color_palette' ) );
                if ( empty( $input['palette_id'] ) ) {
                    return [ 'success' => true, 'palettes' => $palettes, 'message' => sprintf( '%d palette(s).', count( $palettes ) ) ];
                }
                $pid = (string) $input['palette_id'];
                foreach ( $palettes as $p ) {
                    if ( is_array( $p ) && ( ( $p['id'] ?? null ) === $pid ) ) {
                        return [ 'success' => true, 'colors' => array_values( $p['colors'] ?? [] ), 'message' => 'OK.' ];
                    }
                }
                return [ 'success' => false, 'message' => sprintf( 'Palette "%s" not found.', $pid ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-get-color-palette-entry', [
            'label' => 'Get Bricks Color', 'category' => 'atarim',
            'description' => 'Get one color (by color_id) from a palette (by palette_id).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'palette_id' => [ 'type' => 'string' ], 'color_id' => [ 'type' => 'string' ] ], 'required' => [ 'palette_id', 'color_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'color' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $palettes = $self->store_read( 'color_palette' );
                foreach ( $palettes as $p ) {
                    if ( is_array( $p ) && ( ( $p['id'] ?? null ) === (string) $input['palette_id'] ) ) {
                        foreach ( (array) ( $p['colors'] ?? [] ) as $c ) {
                            if ( is_array( $c ) && ( ( $c['id'] ?? null ) === (string) $input['color_id'] ) ) {
                                return [ 'success' => true, 'color' => $c, 'message' => 'OK.' ];
                            }
                        }
                    }
                }
                return [ 'success' => false, 'message' => 'Color not found.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        // add / edit / delete a color entry within a palette (site-wide).
        $mutate = function( $op ) use ( $self ) {
            return function( $input = [] ) use ( $self, $op ) {
                $gate = $self->confirm_gate( $input, ucfirst( $op ) . ' a palette color' ); if ( $gate ) { return $gate; }
                $palettes = array_values( $self->store_read( 'color_palette' ) );
                $pid = (string) ( $input['palette_id'] ?? '' );
                $hit = false;
                foreach ( $palettes as &$p ) {
                    if ( ! is_array( $p ) || ( ( $p['id'] ?? null ) !== $pid ) ) { continue; }
                    $colors = isset( $p['colors'] ) && is_array( $p['colors'] ) ? array_values( $p['colors'] ) : [];
                    if ( $op === 'add' ) {
                        $cid = AVCF_Bricks_Helpers::generate_id();
                        $colors[] = [ 'id' => $cid, 'name' => (string) ( $input['name'] ?? '' ), 'raw' => (string) ( $input['raw'] ?? '' ) ];
                        $p['colors'] = $colors; $hit = true; $new_id = $cid;
                    } else {
                        $cid = (string) ( $input['color_id'] ?? '' );
                        if ( $op === 'delete' ) {
                            $p['colors'] = array_values( array_filter( $colors, function( $c ) use ( $cid ) { return ! ( is_array( $c ) && ( ( $c['id'] ?? null ) === $cid ) ); } ) );
                            $hit = true;
                        } else { // edit
                            foreach ( $colors as &$c ) {
                                if ( is_array( $c ) && ( ( $c['id'] ?? null ) === $cid ) ) {
                                    if ( isset( $input['name'] ) ) { $c['name'] = (string) $input['name']; }
                                    if ( isset( $input['raw'] ) )  { $c['raw'] = (string) $input['raw']; }
                                    $hit = true;
                                }
                            }
                            unset( $c );
                            $p['colors'] = $colors;
                        }
                    }
                    break;
                }
                unset( $p );
                if ( ! $hit ) { return [ 'success' => false, 'message' => 'Palette (or color) not found.' ]; }
                $self->store_write( 'color_palette', $palettes );
                return [ 'success' => true, 'applied' => true, 'id' => isset( $new_id ) ? $new_id : ( $input['color_id'] ?? '' ), 'message' => sprintf( 'Color %sed.', $op ) ];
            };
        };

        wp_register_ability( 'atarim/bricks-add-color-palette-entry', [
            'label' => 'Add Bricks Color', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Add a color to a palette. Provide palette_id, name, raw (e.g. "#3366ff" or "rgba(...)"). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'palette_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'raw' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'palette_id', 'raw' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => $mutate( 'add' ),
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/bricks-edit-color-palette-entry', [
            'label' => 'Edit Bricks Color', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Edit a color (palette_id + color_id; name and/or raw). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'palette_id' => [ 'type' => 'string' ], 'color_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'raw' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'palette_id', 'color_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => $mutate( 'edit' ),
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/bricks-delete-color-palette-entry', [
            'label' => 'Delete Bricks Color', 'category' => 'atarim',
            'description' => 'SITE-WIDE. Delete a color (palette_id + color_id). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'palette_id' => [ 'type' => 'string' ], 'color_id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'palette_id', 'color_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => $mutate( 'delete' ),
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- interactions -------------------------- */

    private function register_interactions() {
        $self = $this; $std = $this->std_out();
        $area_prop = [ 'type' => 'string', 'enum' => [ 'content', 'header', 'footer' ], 'default' => 'content' ];

        wp_register_ability( 'atarim/bricks-list-interaction-events-and-actions', [
            'label' => 'List Bricks Interaction Events & Actions', 'category' => 'atarim',
            'description' => 'List the interaction events and actions Bricks supports (reference for building interaction configs).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'data' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( class_exists( '\Bricks\Interactions' ) && method_exists( '\Bricks\Interactions', 'get_controls_data' ) ) {
                    try { return [ 'success' => true, 'data' => (array) \Bricks\Interactions::get_controls_data(), 'message' => 'OK.' ]; }
                    catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ]; }
                }
                return [ 'success' => false, 'message' => 'Bricks Interactions controls API not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-list-interactions', [
            'label' => 'List Bricks Interactions', 'category' => 'atarim',
            'description' => 'List interactions configured on a post\'s elements (from each element\'s _interactions setting).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'area' => $area_prop ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'interactions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $out = [];
                foreach ( AVCF_Bricks_Helpers::read_area( $post_id, $area ) as $el ) {
                    if ( is_array( $el ) && ! empty( $el['settings']['_interactions'] ) ) {
                        $out[] = [ 'element_id' => $el['id'] ?? '', 'interactions' => $el['settings']['_interactions'] ];
                    }
                }
                return [ 'success' => true, 'interactions' => $out, 'message' => sprintf( '%d element(s) with interactions.', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        $edit_int = function( $mode ) use ( $self, $area_prop ) {
            return function( $input = [] ) use ( $self, $mode ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! ( current_user_can( 'edit_post', $post_id ) || current_user_can( 'edit_posts' ) ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $area = isset( $input['area'] ) ? (string) $input['area'] : 'content';
                $eid  = (string) $input['element_id'];
                $els  = AVCF_Bricks_Helpers::read_area( $post_id, $area );
                $hit  = false; $new_id = '';
                foreach ( $els as &$el ) {
                    if ( ! is_array( $el ) || ( ( $el['id'] ?? null ) !== $eid ) ) { continue; }
                    $settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
                    $list = isset( $settings['_interactions'] ) && is_array( $settings['_interactions'] ) ? array_values( $settings['_interactions'] ) : [];
                    if ( $mode === 'add' ) {
                        $intr = is_array( $input['interaction'] ?? null ) ? $input['interaction'] : [];
                        $new_id = AVCF_Bricks_Helpers::generate_id(); $intr['id'] = $new_id;
                        $list[] = $intr; $hit = true;
                    } elseif ( $mode === 'delete' ) {
                        $iid = (string) ( $input['interaction_id'] ?? '' );
                        $list = array_values( array_filter( $list, function( $i ) use ( $iid ) { return ! ( is_array( $i ) && ( ( $i['id'] ?? null ) === $iid ) ); } ) );
                        $hit = true;
                    } else { // edit
                        $iid = (string) ( $input['interaction_id'] ?? '' );
                        foreach ( $list as &$i ) {
                            if ( is_array( $i ) && ( ( $i['id'] ?? null ) === $iid ) ) { $i = array_merge( $i, is_array( $input['interaction'] ?? null ) ? $input['interaction'] : [] ); $i['id'] = $iid; $hit = true; }
                        }
                        unset( $i );
                    }
                    $settings['_interactions'] = $list;
                    $el['settings'] = $settings;
                    break;
                }
                unset( $el );
                if ( ! $hit ) { return [ 'success' => false, 'message' => 'Element (or interaction) not found.' ]; }
                if ( ! AVCF_Bricks_Helpers::write_area( $post_id, $area, $els ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'interaction_id' => $new_id, 'message' => sprintf( 'Interaction %sed.', $mode ) ];
            };
        };

        wp_register_ability( 'atarim/bricks-add-interaction', [
            'label' => 'Add Bricks Interaction', 'category' => 'atarim',
            'description' => 'Add an interaction to an element (appends to its _interactions). interaction is the Bricks interaction config (see list-interaction-events-and-actions).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'area' => $area_prop, 'element_id' => [ 'type' => 'string' ], 'interaction' => [ 'type' => 'object' ] ], 'required' => [ 'post_id', 'element_id', 'interaction' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'interaction_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => $edit_int( 'add' ),
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/bricks-edit-interaction', [
            'label' => 'Edit Bricks Interaction', 'category' => 'atarim',
            'description' => 'Edit an interaction on an element by interaction_id (config merged in).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'area' => $area_prop, 'element_id' => [ 'type' => 'string' ], 'interaction_id' => [ 'type' => 'string' ], 'interaction' => [ 'type' => 'object' ] ], 'required' => [ 'post_id', 'element_id', 'interaction_id', 'interaction' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => $edit_int( 'edit' ),
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/bricks-delete-interaction', [
            'label' => 'Delete Bricks Interaction', 'category' => 'atarim',
            'description' => 'Remove an interaction from an element by interaction_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'area' => $area_prop, 'element_id' => [ 'type' => 'string' ], 'interaction_id' => [ 'type' => 'string' ] ], 'required' => [ 'post_id', 'element_id', 'interaction_id' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => $edit_int( 'delete' ),
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- dynamic data -------------------------- */

    private function register_dynamic_data() {
        wp_register_ability( 'atarim/bricks-list-dynamic-data', [
            'label' => 'List Bricks Dynamic Data Tags', 'category' => 'atarim',
            'description' => 'List the Bricks dynamic data tags available (the {tags} you can use in settings).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'tags' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cls = '\Bricks\Integrations\Dynamic_Data\Providers';
                if ( class_exists( $cls ) && method_exists( $cls, 'get_dynamic_tags_list' ) ) {
                    try {
                        $pid = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                        $tags = $cls::get_dynamic_tags_list( $pid );
                        return [ 'success' => true, 'tags' => is_array( $tags ) ? $tags : [], 'message' => 'OK.' ];
                    } catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ]; }
                }
                return [ 'success' => false, 'message' => 'Bricks dynamic data provider API not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-resolve-dynamic-data', [
            'label' => 'Resolve Bricks Dynamic Data', 'category' => 'atarim',
            'description' => 'Render a string containing Bricks dynamic data tag(s) against a given post and return the resolved output.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'content' => [ 'type' => 'string' ], 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'content', 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'resolved' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $content = (string) $input['content']; $pid = (int) $input['post_id'];
                if ( function_exists( 'bricks_render_dynamic_data' ) ) {
                    try { return [ 'success' => true, 'resolved' => (string) bricks_render_dynamic_data( $content, $pid ), 'message' => 'OK.' ]; }
                    catch ( \Throwable $e ) { return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ]; }
                }
                return [ 'success' => false, 'message' => 'bricks_render_dynamic_data() not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- templates --------------------------- */

    private function register_templates() {
        $self = $this; $std = $this->std_out();

        wp_register_ability( 'atarim/bricks-list-templates', [
            'label' => 'List Bricks Templates', 'category' => 'atarim',
            'description' => 'List Bricks templates (id, title, template type).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_type' => [ 'type' => 'string', 'description' => 'Filter by type (header/footer/section/content/...).' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'templates' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $posts = get_posts( [ 'post_type' => 'bricks_template', 'post_status' => 'any', 'numberposts' => -1 ] );
                $type_filter = isset( $input['template_type'] ) ? (string) $input['template_type'] : '';
                $out = [];
                foreach ( $posts as $p ) {
                    $t = get_post_meta( $p->ID, '_bricks_template_type', true );
                    if ( $type_filter !== '' && $t !== $type_filter ) { continue; }
                    $out[] = [ 'id' => $p->ID, 'title' => $p->post_title, 'template_type' => $t ];
                }
                return [ 'success' => true, 'templates' => $out, 'message' => sprintf( '%d template(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-create-template', [
            'label' => 'Create Bricks Template', 'category' => 'atarim',
            'description' => 'Create a Bricks template. Provide title and template_type (e.g. "header", "footer", "section", "content", "popup"). Optionally elements (the template\'s content area).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'template_type' => [ 'type' => 'string' ], 'elements' => [ 'type' => 'array' ] ], 'required' => [ 'title', 'template_type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $id = wp_insert_post( [ 'post_type' => 'bricks_template', 'post_title' => (string) $input['title'], 'post_status' => 'publish' ], true );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => $id->get_error_message() ]; }
                update_post_meta( $id, '_bricks_template_type', (string) $input['template_type'] );
                if ( isset( $input['elements'] ) && is_array( $input['elements'] ) ) {
                    AVCF_Bricks_Helpers::write_area( $id, 'content', $input['elements'] );
                }
                return [ 'success' => true, 'id' => (int) $id, 'message' => 'Template created.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/bricks-list-template-condition-schema', [
            'label' => 'List Bricks Template Condition Schema', 'category' => 'atarim',
            'description' => 'List the template condition types Bricks supports (for set-template-conditions).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'conditions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = [ 'entire-website', 'front-page', 'post-page', 'archive-page', 'search-page', 'error-page', 'post-ids', 'post-type', 'taxonomy', 'terms', 'user-roles' ];
                return [ 'success' => true, 'conditions' => array_map( function( $t ) { return [ 'type' => $t ]; }, $types ), 'message' => sprintf( '%d condition type(s).', count( $types ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-list-template-conditions', [
            'label' => 'List Bricks Template Conditions', 'category' => 'atarim',
            'description' => 'Read the display conditions set on a template (by template id).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'template_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'conditions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $tid = (int) $input['template_id'];
                if ( $tid <= 0 || get_post_type( $tid ) !== 'bricks_template' ) { return [ 'success' => false, 'message' => 'Not a Bricks template id.' ]; }
                $settings = get_post_meta( $tid, '_bricks_template_settings', true );
                $cond = is_array( $settings ) && isset( $settings['templateConditions'] ) ? $settings['templateConditions'] : [];
                return [ 'success' => true, 'conditions' => is_array( $cond ) ? $cond : [], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/bricks-set-template-conditions', [
            'label' => 'Set Bricks Template Conditions', 'category' => 'atarim',
            'description' => 'Set the display conditions for a template. conditions is the full array of condition rules (see list-template-condition-schema). Replaces existing conditions.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'template_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'conditions' => [ 'type' => 'array' ] ], 'required' => [ 'template_id', 'conditions' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) {
                $tid = (int) $input['template_id'];
                if ( $tid <= 0 || get_post_type( $tid ) !== 'bricks_template' ) { return [ 'success' => false, 'message' => 'Not a Bricks template id.' ]; }
                if ( ! ( current_user_can( 'edit_post', $tid ) || current_user_can( 'manage_options' ) ) ) { return [ 'success' => false, 'message' => 'No permission to edit this template.' ]; }
                $settings = get_post_meta( $tid, '_bricks_template_settings', true );
                if ( ! is_array( $settings ) ) { $settings = []; }
                $settings['templateConditions'] = is_array( $input['conditions'] ) ? $input['conditions'] : [];
                update_post_meta( $tid, '_bricks_template_settings', $settings );
                return [ 'success' => true, 'message' => 'Template conditions set.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
