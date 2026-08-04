<?php
/**
 * Elementor — MCP abilities (orchestrator + core tree editing).
 *
 * Agent-driven editing of Elementor's _elementor_data element tree. This is a
 * separate surface from any human inline-editing layer: it reads the tree,
 * exposes widget/style control schemas, and mutates the tree (add / edit /
 * move / delete element, or replace the whole tree) through the shared
 * AVCF_Elementor_Helpers round-trip, which persists via Elementor's Document
 * API where possible and regenerates CSS.
 *
 * Scope: the stable, load-bearing tree operations. Advanced Pro surfaces
 * (atomic widgets, global classes, global styles v3, dynamic tags,
 * interactions, variables) are intentionally out of this first cluster.
 *
 * Exposed abilities (atarim/elementor-*):
 *   check-setup, get-content, get-widget-schema, get-style-schema,
 *   add-element, edit-element, move-element, delete-element, set-content
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Elementor extends AVCF_Abilities_Base {

    /** @var AVCF_Elementor_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Elementor_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_elementor_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_get_content();
        $this->register_schemas();
        $this->register_add_element();
        $this->register_edit_element();
        $this->register_move_element();
        $this->register_delete_element();
        $this->register_set_content();
    }

    /* ----------------------------- helpers ----------------------------- */

    private function can_edit( $post_id ) {
        return current_user_can( 'edit_post', (int) $post_id ) || current_user_can( 'edit_posts' );
    }
    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }

    /** Validate a post is Elementor-built; returns error array or null. */
    private function require_elementor_post( $post_id ) {
        if ( $post_id <= 0 || ! get_post( $post_id ) ) {
            return [ 'success' => false, 'message' => 'A valid post_id is required.' ];
        }
        if ( ! AVCF_Elementor_Helpers::is_elementor_post( $post_id ) ) {
            return [ 'success' => false, 'message' => sprintf( 'Post %d is not built with Elementor (no builder data). Open it in Elementor once, or use set-content to initialise it.', $post_id ) ];
        }
        return null;
    }

    /** Shape an Elementor control for schema output. */
    private function shape_control( $name, $control ) {
        $c = is_array( $control ) ? $control : [];
        $out = [
            'name'  => (string) $name,
            'type'  => isset( $c['type'] ) ? (string) $c['type'] : '',
            'label' => isset( $c['label'] ) ? (string) $c['label'] : '',
            'tab'   => isset( $c['tab'] ) ? (string) $c['tab'] : '',
        ];
        if ( isset( $c['default'] ) )    { $out['default'] = $c['default']; }
        if ( isset( $c['options'] ) )    { $out['options'] = $c['options']; }
        if ( isset( $c['description'] ) ){ $out['description'] = (string) $c['description']; }
        return $out;
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $detector = $this->detector;
        wp_register_ability( 'atarim/elementor-check-setup', [
            'label'        => 'Check Elementor Setup',
            'description'  => 'Call first before other Elementor abilities. Reports whether Elementor is active, its version, and whether Elementor Pro is present.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'pro' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $detector ) {
                return [ 'success' => true, 'active' => $detector->avcf_elementor_is_available(), 'version' => $detector->avcf_elementor_version(), 'pro' => $detector->avcf_elementor_is_pro(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- get-content --------------------------- */

    private function register_get_content() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-get-content', [
            'label'        => 'Get Elementor Content',
            'description'  => 'Read a post\'s Elementor structure. By default returns a depth-limited structural tree (each node: id, elType, widgetType, child_count) — ideal for locating the element id you want to edit. Pass element_id to get that one element\'s full data (including settings) and its subtree. Pass include_settings:true to get the whole tree with settings (can be large).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id'       => [ 'type' => 'string', 'description' => 'Return the full data for just this element (and its subtree).' ],
                'include_settings' => [ 'type' => 'boolean', 'description' => 'Return the entire raw tree with settings. Defaults to false (structural summary).', 'default' => false ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'tree' => [ 'type' => 'array' ], 'element' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                $err = $self->require_elementor_post( $post_id );
                if ( $err ) { return $err; }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                if ( ! empty( $input['element_id'] ) ) {
                    $el = AVCF_Elementor_Helpers::find( $tree, (string) $input['element_id'] );
                    if ( $el === null ) {
                        return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found on post %d.', $input['element_id'], $post_id ) ];
                    }
                    return [ 'success' => true, 'post_id' => $post_id, 'element' => $el, 'message' => 'OK.' ];
                }
                if ( ! empty( $input['include_settings'] ) ) {
                    return [ 'success' => true, 'post_id' => $post_id, 'tree' => $tree, 'message' => 'OK (full tree).' ];
                }
                return [ 'success' => true, 'post_id' => $post_id, 'tree' => AVCF_Elementor_Helpers::summarize( $tree ), 'message' => 'OK (structural summary).' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- schemas ------------------------------ */

    private function register_schemas() {
        $self = $this;

        wp_register_ability( 'atarim/elementor-get-widget-schema', [
            'label'        => 'Get Elementor Widget Schema',
            'description'  => 'List available Elementor widgets, or get the control schema for one widget. Omit widget to list all registered widgets (name, title). Pass widget (e.g. "heading", "button", "image") to get its controls: name, type, label, tab (content/style/advanced), default, options. Use this to know which settings keys add-element / edit-element accept.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'widget' => [ 'type' => 'string', 'description' => 'Widget name. Omit to list all widgets.' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'widgets' => [ 'type' => 'array' ], 'widget' => [ 'type' => 'string' ], 'controls' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $plugin = \Elementor\Plugin::$instance;
                $wm = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
                if ( ! is_object( $wm ) ) {
                    return [ 'success' => false, 'message' => 'Elementor widgets manager unavailable.' ];
                }
                $name = isset( $input['widget'] ) ? (string) $input['widget'] : '';
                try {
                    if ( $name === '' ) {
                        $types = $wm->get_widget_types();
                        $list  = [];
                        foreach ( (array) $types as $key => $widget ) {
                            $list[] = [
                                'name'  => is_object( $widget ) && method_exists( $widget, 'get_name' ) ? $widget->get_name() : (string) $key,
                                'title' => is_object( $widget ) && method_exists( $widget, 'get_title' ) ? $widget->get_title() : (string) $key,
                            ];
                        }
                        return [ 'success' => true, 'widgets' => $list, 'message' => sprintf( '%d widgets.', count( $list ) ) ];
                    }
                    $widget = $wm->get_widget_types( $name );
                    if ( ! $widget ) {
                        return [ 'success' => false, 'message' => sprintf( 'Widget "%s" not found.', $name ) ];
                    }
                    $controls = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : [];
                    $out = [];
                    foreach ( $controls as $cname => $control ) {
                        $out[] = $self->shape_control( $cname, $control );
                    }
                    return [ 'success' => true, 'widget' => $name, 'controls' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read widget schema: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/elementor-get-style-schema', [
            'label'        => 'Get Elementor Style Schema',
            'description'  => 'Get the style-tab controls for a widget (typography, colors, spacing, borders, etc.) — the subset of a widget\'s controls whose tab is "style". Use alongside get-widget-schema (which covers content controls) when you need to set a widget\'s appearance.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'widget' => [ 'type' => 'string', 'description' => 'Widget name (e.g. "heading").' ] ], 'required' => [ 'widget' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'widget' => [ 'type' => 'string' ], 'controls' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $plugin = \Elementor\Plugin::$instance;
                $wm = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
                if ( ! is_object( $wm ) ) {
                    return [ 'success' => false, 'message' => 'Elementor widgets manager unavailable.' ];
                }
                $name = isset( $input['widget'] ) ? (string) $input['widget'] : '';
                if ( $name === '' ) { return [ 'success' => false, 'message' => 'widget is required.' ]; }
                try {
                    $widget = $wm->get_widget_types( $name );
                    if ( ! $widget ) { return [ 'success' => false, 'message' => sprintf( 'Widget "%s" not found.', $name ) ]; }
                    $controls = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : [];
                    $out = [];
                    foreach ( $controls as $cname => $control ) {
                        if ( isset( $control['tab'] ) && $control['tab'] === 'style' ) {
                            $out[] = $self->shape_control( $cname, $control );
                        }
                    }
                    return [ 'success' => true, 'widget' => $name, 'controls' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read style schema: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- add-element --------------------------- */

    private function register_add_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-add-element', [
            'label'        => 'Add Elementor Element',
            'description'  => 'Insert a new element into a post\'s Elementor tree. elType is section, column, container, or widget; when widget, widget_type is required (e.g. "heading"). parent_id is the element to nest under (omit for top level); index is the position among siblings (omit to append). settings is a key/value map matching the widget/element controls (see get-widget-schema). Returns the new element id.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                'elType'      => [ 'type' => 'string', 'enum' => [ 'section', 'column', 'container', 'widget' ] ],
                'widget_type' => [ 'type' => 'string', 'description' => 'Required when elType is "widget".' ],
                'parent_id'   => [ 'type' => 'string', 'description' => 'Element to nest under. Omit for top level.' ],
                'index'       => [ 'type' => 'integer', 'description' => 'Position among siblings. Omit to append.', 'minimum' => 0 ],
                'settings'    => [ 'type' => 'object', 'description' => 'Control values for the element.' ],
            ], 'required' => [ 'post_id', 'elType' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'element_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $elType = isset( $input['elType'] ) ? (string) $input['elType'] : '';
                if ( ! in_array( $elType, [ 'section', 'column', 'container', 'widget' ], true ) ) {
                    return [ 'success' => false, 'message' => 'elType must be section, column, container, or widget.' ];
                }
                if ( $elType === 'widget' && empty( $input['widget_type'] ) ) {
                    return [ 'success' => false, 'message' => 'widget_type is required when elType is "widget".' ];
                }
                $node = [
                    'id'       => AVCF_Elementor_Helpers::generate_id(),
                    'elType'   => $elType,
                    'settings' => isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : (object) [],
                    'elements' => [],
                ];
                if ( $elType === 'widget' ) {
                    $node['widgetType'] = (string) $input['widget_type'];
                }
                $tree  = AVCF_Elementor_Helpers::read_tree( $post_id );
                $index = isset( $input['index'] ) ? (int) $input['index'] : null;
                list( $tree, $inserted ) = AVCF_Elementor_Helpers::insert( $tree, isset( $input['parent_id'] ) ? (string) $input['parent_id'] : null, $node, $index );
                if ( ! $inserted ) {
                    return [ 'success' => false, 'message' => sprintf( 'parent_id "%s" not found.', isset( $input['parent_id'] ) ? $input['parent_id'] : '' ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'element_id' => $node['id'], 'message' => sprintf( 'Added %s element.', $elType === 'widget' ? $node['widgetType'] : $elType ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- edit-element -------------------------- */

    private function register_edit_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-edit-element', [
            'label'        => 'Edit Elementor Element',
            'description'  => 'Update an element\'s settings by id. The supplied settings are merged into the element\'s existing settings (shallow merge — top-level keys you send overwrite, others are kept). Read the element first with get-content (element_id) and the control names with get-widget-schema.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id' => [ 'type' => 'string' ],
                'settings'   => [ 'type' => 'object', 'description' => 'Settings to merge into the element.' ],
            ], 'required' => [ 'post_id', 'element_id', 'settings' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
                if ( $element_id === '' ) { return [ 'success' => false, 'message' => 'element_id is required.' ]; }
                $patch = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                if ( empty( $patch ) ) { return [ 'success' => false, 'message' => 'settings must be a non-empty object.' ]; }
                $tree  = AVCF_Elementor_Helpers::read_tree( $post_id );
                $found = false;
                $tree  = AVCF_Elementor_Helpers::map_edit( $tree, $element_id, function( $node ) use ( $patch ) {
                    $current = ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) ? $node['settings'] : [];
                    $node['settings'] = array_merge( $current, $patch );
                    return $node;
                }, $found );
                if ( ! $found ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Updated element "%s".', $element_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- move-element -------------------------- */

    private function register_move_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-move-element', [
            'label'        => 'Move Elementor Element',
            'description'  => 'Relocate an element (and its subtree) to a new parent and/or position. new_parent_id omitted moves it to the top level; index sets the position among the destination\'s children (omit to append).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id'    => [ 'type' => 'string' ],
                'new_parent_id' => [ 'type' => 'string', 'description' => 'Destination parent. Omit for top level.' ],
                'index'         => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
                if ( $element_id === '' ) { return [ 'success' => false, 'message' => 'element_id is required.' ]; }
                $new_parent = isset( $input['new_parent_id'] ) ? (string) $input['new_parent_id'] : null;
                if ( $new_parent !== null && $new_parent === $element_id ) {
                    return [ 'success' => false, 'message' => 'An element cannot be moved into itself.' ];
                }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                list( $tree, $removed ) = AVCF_Elementor_Helpers::remove( $tree, $element_id );
                if ( $removed === null ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                $index = isset( $input['index'] ) ? (int) $input['index'] : null;
                list( $tree, $inserted ) = AVCF_Elementor_Helpers::insert( $tree, $new_parent, $removed, $index );
                if ( ! $inserted ) {
                    return [ 'success' => false, 'message' => sprintf( 'new_parent_id "%s" not found (element was not moved).', $new_parent ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Moved element "%s".', $element_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- delete-element ------------------------- */

    private function register_delete_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-delete-element', [
            'label'        => 'Delete Elementor Element',
            'description'  => 'Remove an element (and everything nested inside it) from a post\'s Elementor tree by id. Dry run unless confirm:true.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id' => [ 'type' => 'string' ],
                'confirm'    => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
                if ( $element_id === '' ) { return [ 'success' => false, 'message' => 'element_id is required.' ]; }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                if ( AVCF_Elementor_Helpers::find( $tree, $element_id ) === null ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                if ( empty( $input['confirm'] ) ) {
                    return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete element "%s" and its children. Re-call with confirm:true.', $element_id ) ];
                }
                list( $tree, $removed ) = AVCF_Elementor_Helpers::remove( $tree, $element_id );
                if ( $removed === null ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Deleted element "%s".', $element_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-set-content', [
            'label'        => 'Set Elementor Content',
            'description'  => 'Replace a post\'s ENTIRE Elementor tree with the supplied elements array (the same shape get-content returns with include_settings:true). This is a full overwrite — use it to initialise an Elementor page or rebuild it wholesale; for targeted changes prefer add/edit/move/delete-element. Element ids are preserved as given (or generated for nodes missing one).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'elements' => [ 'type' => 'array', 'description' => 'Full element tree to store.' ],
            ], 'required' => [ 'post_id', 'elements' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                if ( ! isset( $input['elements'] ) || ! is_array( $input['elements'] ) ) {
                    return [ 'success' => false, 'message' => 'elements must be an array.' ];
                }
                $tree = $self->ensure_ids( $input['elements'] );
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Replaced Elementor content on post %d.', $post_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /** Recursively ensure every node has an id and an elements array. */
    private function ensure_ids( $elements ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            if ( empty( $el['id'] ) ) {
                $el['id'] = AVCF_Elementor_Helpers::generate_id();
            }
            if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
                $el['elements'] = [];
            } else {
                $el['elements'] = $this->ensure_ids( $el['elements'] );
            }
            $out[] = $el;
        }
        return $out;
    }
}
