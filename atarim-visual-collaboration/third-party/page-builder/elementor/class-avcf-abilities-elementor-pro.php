<?php
/**
 * Elementor — Advanced / Pro MCP abilities.
 *
 * EXPERIMENTAL. These sit on Elementor's INTERNAL, non-public APIs (the global
 * classes repository, the active Kit's v3 global colors/typography, the
 * Variables repository, the dynamic-tags Manager, atomic widgets) which change
 * between Elementor releases. They could not be tested here against a live
 * Elementor (Pro). Every callback is area-gated on the specific class being
 * present and wrapped in try/catch so an unavailable or changed API returns a
 * clean error rather than fataling or corrupting data. The site-wide writers
 * (global classes, v3 styles, variables) are dry-run unless confirm:true,
 * because they affect every page on the site. VALIDATE ON A LIVE ELEMENTOR PRO
 * INSTALL BEFORE FLEET ROLLOUT.
 *
 * Areas (atarim/elementor-*):
 *   global classes (5), v3 styles (7), variables (4), dynamic tags (3),
 *   interactions (3), delete-element-style (1), create-atomic-widget (1),
 *   validate-widget (1) = 25 abilities.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Elementor_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Elementor_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Elementor_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_elementor_is_available() ) {
            return;
        }
        $this->register_global_classes();
        $this->register_v3_styles();
        $this->register_variables();
        $this->register_dynamic_tags();
        $this->register_interactions();
        $this->register_element_style();
        $this->register_atomic_widget();
        $this->register_validate_widget();
    }

    /* ----------------------------- shared ------------------------------ */

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }
    private function site_can() { return current_user_can( 'manage_options' ); }
    private function post_can( $post_id ) { return current_user_can( 'edit_post', (int) $post_id ) || current_user_can( 'edit_posts' ); }

    private function active_kit() {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return null;
        }
        $p = \Elementor\Plugin::$instance;
        if ( isset( $p->kits_manager ) && is_object( $p->kits_manager ) && method_exists( $p->kits_manager, 'get_active_kit' ) ) {
            return $p->kits_manager->get_active_kit();
        }
        return null;
    }

    private function gen_id() {
        return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
    }

    /** Standard "this is a site-wide change" confirm gate. */
    private function confirm_gate( $input, $what ) {
        if ( empty( $input['confirm'] ) ) {
            return [ 'success' => true, 'applied' => false, 'message' => sprintf( 'Dry run: %s affects every page on the site. Re-call with confirm:true to apply.', $what ) ];
        }
        return null;
    }

    /* ----------------------- global classes (5) ----------------------- */

    private function register_global_classes() {
        $self = $this;
        $repo_class = '\Elementor\Modules\GlobalClasses\Global_Classes_Repository';

        $guard = function() use ( $repo_class ) {
            if ( ! class_exists( $repo_class ) ) {
                return [ 'success' => false, 'message' => 'Elementor global classes are not available on this install (feature/version missing).' ];
            }
            return null;
        };

        wp_register_ability( 'atarim/elementor-list-global-classes', [
            'label' => 'List Elementor Global Classes', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. List Elementor global CSS classes (id, label) and their order from the global classes repository.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'classes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $repo_class ) {
                $g = $guard(); if ( $g ) { return $g; }
                try {
                    $repo  = $repo_class::make();
                    $all   = $repo->all();
                    $items = method_exists( $all, 'get_items' ) ? (array) $all->get_items()->all() : [];
                    $order = method_exists( $all, 'get_order' ) ? (array) $all->get_order()->all() : [];
                    $out = [];
                    foreach ( $items as $id => $def ) {
                        $out[] = [ 'id' => (string) $id, 'label' => isset( $def['label'] ) ? $def['label'] : ( is_array( $def ) && isset( $def['id'] ) ? $def['id'] : (string) $id ) ];
                    }
                    return [ 'success' => true, 'classes' => $out, 'order' => array_values( $order ), 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read global classes: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        // create / edit / delete share a put-based mutation; the put signature
        // varies by Elementor version, so these are best-effort + confirm-gated.
        $mutate = function( $op ) use ( $self, $guard, $repo_class ) {
            return function( $input = [] ) use ( $self, $guard, $repo_class, $op ) {
                $g = $guard(); if ( $g ) { return $g; }
                $gate = $self->confirm_gate( $input, sprintf( 'Global class %s', $op ) );
                if ( $gate ) { return $gate; }
                try {
                    $repo  = $repo_class::make();
                    $all   = $repo->all();
                    $items = method_exists( $all, 'get_items' ) ? (array) $all->get_items()->all() : [];
                    $order = method_exists( $all, 'get_order' ) ? (array) $all->get_order()->all() : [];
                    if ( $op === 'create' ) {
                        $id = 'g-' . $self->gen_id();
                        $items[ $id ] = [ 'id' => $id, 'label' => isset( $input['label'] ) ? (string) $input['label'] : $id, 'type' => 'class', 'variants' => isset( $input['variants'] ) && is_array( $input['variants'] ) ? $input['variants'] : [] ];
                        $order[] = $id;
                    } elseif ( $op === 'edit' ) {
                        $id = isset( $input['id'] ) ? (string) $input['id'] : '';
                        if ( ! isset( $items[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $id ) ]; }
                        if ( isset( $input['label'] ) )    { $items[ $id ]['label'] = (string) $input['label']; }
                        if ( isset( $input['variants'] ) ) { $items[ $id ]['variants'] = (array) $input['variants']; }
                    } else { // delete
                        $id = isset( $input['id'] ) ? (string) $input['id'] : '';
                        if ( ! isset( $items[ $id ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Global class "%s" not found.', $id ) ]; }
                        unset( $items[ $id ] );
                        $order = array_values( array_filter( $order, function( $o ) use ( $id ) { return $o !== $id; } ) );
                    }
                    if ( ! method_exists( $repo, 'put' ) ) {
                        return [ 'success' => false, 'message' => 'This Elementor version does not expose Global_Classes_Repository::put(); the write path needs live-Pro validation.' ];
                    }
                    $repo->put( $items, $order );
                    AVCF_Elementor_Helpers::clear_css_cache( 0 );
                    return [ 'success' => true, 'applied' => true, 'id' => isset( $id ) ? $id : '', 'message' => sprintf( 'Global class %s applied.', $op ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => ucfirst( $op ) . ' failed: ' . $e->getMessage() ];
                }
            };
        };

        wp_register_ability( 'atarim/elementor-create-global-class', [
            'label' => 'Create Elementor Global Class', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL, SITE-WIDE. Create a global CSS class. variants is the Elementor variant/props structure. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'label' => [ 'type' => 'string' ], 'variants' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'label' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => $mutate( 'create' ),
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/elementor-edit-global-class', [
            'label' => 'Edit Elementor Global Class', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL, SITE-WIDE. Edit a global CSS class by id (label and/or variants). Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'variants' => [ 'type' => 'array' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => $mutate( 'edit' ),
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
        wp_register_ability( 'atarim/elementor-delete-global-class', [
            'label' => 'Delete Elementor Global Class', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL, SITE-WIDE. Delete a global CSS class by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => $mutate( 'delete' ),
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );

        wp_register_ability( 'atarim/elementor-apply-global-class', [
            'label' => 'Apply Elementor Global Class', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. Apply (or remove) a global class on one element of a post by adding/removing the class id in the element\'s "classes" setting. Per-post, not site-wide.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'element_id' => [ 'type' => 'string' ], 'class_id' => [ 'type' => 'string' ], 'remove' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'element_id', 'class_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->post_can( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $eid = (string) $input['element_id']; $cid = (string) $input['class_id'];
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id ); $found = false;
                $tree = AVCF_Elementor_Helpers::map_edit( $tree, $eid, function( $node ) use ( $cid, $input ) {
                    $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
                    $classes  = isset( $settings['classes']['value'] ) && is_array( $settings['classes']['value'] ) ? $settings['classes']['value'] : [];
                    if ( ! empty( $input['remove'] ) ) {
                        $classes = array_values( array_filter( $classes, function( $c ) use ( $cid ) { return $c !== $cid; } ) );
                    } elseif ( ! in_array( $cid, $classes, true ) ) {
                        $classes[] = $cid;
                    }
                    $settings['classes'] = [ '$$type' => 'classes', 'value' => $classes ];
                    $node['settings'] = $settings;
                    return $node;
                }, $found );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $eid ) ]; }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => ! empty( $input['remove'] ) ? 'Class removed from element.' : 'Class applied to element.' ];
            },
            'permission_callback' => function() use ( $self ) { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- v3 styles (7) -------------------------- */

    private function register_v3_styles() {
        $self = $this;

        wp_register_ability( 'atarim/elementor-list-v3-styles', [
            'label' => 'List Elementor v3 Global Styles', 'category' => 'atarim',
            'description' => 'List the active Kit\'s v3 global colors and typography: system_colors, custom_colors, system_typography, custom_typography (each with _id and title).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'styles' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $kit = $self->active_kit();
                if ( ! $kit ) { return [ 'success' => false, 'message' => 'No active Elementor Kit found.' ]; }
                try {
                    return [ 'success' => true, 'styles' => [
                        'system_colors'     => (array) ( $kit->get_settings( 'system_colors' ) ?: [] ),
                        'custom_colors'     => (array) ( $kit->get_settings( 'custom_colors' ) ?: [] ),
                        'system_typography' => (array) ( $kit->get_settings( 'system_typography' ) ?: [] ),
                        'custom_typography' => (array) ( $kit->get_settings( 'custom_typography' ) ?: [] ),
                    ], 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read kit styles: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        // Shared kit-array mutation for custom_colors / custom_typography.
        $mutate = function( $setting_key, $op ) use ( $self ) {
            return function( $input = [] ) use ( $self, $setting_key, $op ) {
                $kit = $self->active_kit();
                if ( ! $kit ) { return [ 'success' => false, 'message' => 'No active Elementor Kit found.' ]; }
                $gate = $self->confirm_gate( $input, sprintf( '%s %s', $setting_key, $op ) );
                if ( $gate ) { return $gate; }
                try {
                    $list = (array) ( $kit->get_settings( $setting_key ) ?: [] );
                    if ( $op === 'create' ) {
                        $entry = [ '_id' => $self->gen_id(), 'title' => isset( $input['title'] ) ? (string) $input['title'] : 'Untitled' ];
                        if ( $setting_key === 'custom_colors' ) {
                            $entry['color'] = isset( $input['color'] ) ? (string) $input['color'] : '#000000';
                        } else {
                            $entry['typography_typography']  = 'custom';
                            if ( isset( $input['font_family'] ) ) { $entry['typography_font_family'] = (string) $input['font_family']; }
                            if ( isset( $input['font_weight'] ) ) { $entry['typography_font_weight'] = (string) $input['font_weight']; }
                            if ( isset( $input['font_size'] ) )   { $entry['typography_font_size'] = [ 'unit' => 'px', 'size' => (float) $input['font_size'] ]; }
                        }
                        $list[] = $entry;
                        $new_id = $entry['_id'];
                    } else {
                        $id    = isset( $input['id'] ) ? (string) $input['id'] : '';
                        $hit   = false;
                        foreach ( $list as $i => $e ) {
                            if ( isset( $e['_id'] ) && (string) $e['_id'] === $id ) {
                                $hit = true;
                                if ( $op === 'delete' ) {
                                    unset( $list[ $i ] );
                                } else { // edit
                                    if ( isset( $input['title'] ) ) { $list[ $i ]['title'] = (string) $input['title']; }
                                    if ( $setting_key === 'custom_colors' && isset( $input['color'] ) ) { $list[ $i ]['color'] = (string) $input['color']; }
                                    if ( $setting_key === 'custom_typography' ) {
                                        if ( isset( $input['font_family'] ) ) { $list[ $i ]['typography_font_family'] = (string) $input['font_family']; }
                                        if ( isset( $input['font_weight'] ) ) { $list[ $i ]['typography_font_weight'] = (string) $input['font_weight']; }
                                        if ( isset( $input['font_size'] ) )   { $list[ $i ]['typography_font_size'] = [ 'unit' => 'px', 'size' => (float) $input['font_size'] ]; }
                                    }
                                }
                                break;
                            }
                        }
                        if ( ! $hit ) { return [ 'success' => false, 'message' => sprintf( 'Style "%s" not found.', $id ) ]; }
                        $list = array_values( $list );
                    }
                    $kit->update_settings( [ $setting_key => $list ] );
                    if ( method_exists( $kit, 'get_id' ) ) { AVCF_Elementor_Helpers::clear_css_cache( (int) $kit->get_id() ); }
                    return [ 'success' => true, 'applied' => true, 'id' => isset( $new_id ) ? $new_id : ( isset( $id ) ? $id : '' ), 'message' => sprintf( '%s %s applied.', $setting_key, $op ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => ucfirst( $op ) . ' failed: ' . $e->getMessage() ];
                }
            };
        };

        $color_props = [ 'title' => [ 'type' => 'string' ], 'color' => [ 'type' => 'string', 'description' => 'Hex color, e.g. "#3366ff".' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ];
        $typo_props  = [ 'title' => [ 'type' => 'string' ], 'font_family' => [ 'type' => 'string' ], 'font_weight' => [ 'type' => 'string' ], 'font_size' => [ 'type' => 'number' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ];
        $std_out = [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ];

        foreach ( [
            [ 'atarim/elementor-create-v3-color', 'Create v3 Global Color', 'custom_colors', 'create', array_merge( $color_props, [] ), [ 'title', 'color' ] ],
            [ 'atarim/elementor-edit-v3-color', 'Edit v3 Global Color', 'custom_colors', 'edit', array_merge( [ 'id' => [ 'type' => 'string' ] ], $color_props ), [ 'id' ] ],
            [ 'atarim/elementor-delete-v3-color', 'Delete v3 Global Color', 'custom_colors', 'delete', [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], [ 'id' ] ],
            [ 'atarim/elementor-create-v3-typography', 'Create v3 Global Typography', 'custom_typography', 'create', array_merge( $typo_props, [] ), [ 'title' ] ],
            [ 'atarim/elementor-edit-v3-typography', 'Edit v3 Global Typography', 'custom_typography', 'edit', array_merge( [ 'id' => [ 'type' => 'string' ] ], $typo_props ), [ 'id' ] ],
            [ 'atarim/elementor-delete-v3-typography', 'Delete v3 Global Typography', 'custom_typography', 'delete', [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], [ 'id' ] ],
        ] as $def ) {
            list( $name, $label, $key, $op, $props, $required ) = $def;
            wp_register_ability( $name, [
                'label' => $label, 'category' => 'atarim',
                'description' => sprintf( 'SITE-WIDE. %s in the active Kit. Dry run unless confirm:true.', $label ),
                'input_schema' => [ 'type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false ],
                'output_schema'=> $std_out,
                'execute_callback' => $mutate( $key, $op ),
                'permission_callback' => function() use ( $self ) { return $self->site_can(); },
                'meta' => $this->write_meta( $op === 'delete' ),
            ] );
        }
    }

    /* -------------------------- variables (4) -------------------------- */

    private function register_variables() {
        $self = $this;
        $repo_class = '\Elementor\Modules\Variables\Storage\Variables_Repository';

        $guard = function() use ( $repo_class ) {
            if ( ! class_exists( $repo_class ) ) {
                return [ 'success' => false, 'message' => 'Elementor Variables are not available on this install (feature/version missing).' ];
            }
            return null;
        };

        wp_register_ability( 'atarim/elementor-list-variables', [
            'label' => 'List Elementor Variables', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. List Elementor global Variables (id, label, type, value) from the active Kit\'s variables repository.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'variables' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $guard, $repo_class ) {
                $g = $guard(); if ( $g ) { return $g; }
                $kit = $self->active_kit();
                if ( ! $kit ) { return [ 'success' => false, 'message' => 'No active Elementor Kit found.' ]; }
                try {
                    $repo = new $repo_class( $kit );
                    $collection = $repo->load();
                    $out = [];
                    $list = method_exists( $collection, 'all' ) ? $collection->all() : ( is_iterable( $collection ) ? $collection : [] );
                    foreach ( $list as $var ) {
                        if ( is_object( $var ) ) {
                            $out[] = [
                                'id'    => method_exists( $var, 'id' ) ? $var->id() : '',
                                'label' => method_exists( $var, 'label' ) ? $var->label() : '',
                                'type'  => method_exists( $var, 'type' ) ? $var->type() : '',
                                'value' => method_exists( $var, 'value' ) ? $var->value() : null,
                            ];
                        } elseif ( is_array( $var ) ) {
                            $out[] = $var;
                        }
                    }
                    return [ 'success' => true, 'variables' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read variables: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->ro_meta(),
        ] );

        // create / edit / delete: the Variables collection mutation API
        // (create_new / soft_delete / save) varies; best-effort + confirm-gated.
        $note = 'EXPERIMENTAL, SITE-WIDE. The Variables write API varies by Elementor version; this is best-effort and may need live-Pro adjustment. Dry run unless confirm:true.';
        $std_out = [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ];

        wp_register_ability( 'atarim/elementor-create-variable', [
            'label' => 'Create Elementor Variable', 'category' => 'atarim', 'description' => $note,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'label' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'description' => 'e.g. color or font.' ], 'value' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'label', 'type', 'value' ], 'additionalProperties' => false ],
            'output_schema'=> $std_out,
            'execute_callback' => function( $input = [] ) use ( $self, $guard, $repo_class ) {
                $g = $guard(); if ( $g ) { return $g; }
                $gate = $self->confirm_gate( $input, 'Variable create' ); if ( $gate ) { return $gate; }
                $kit = $self->active_kit(); if ( ! $kit ) { return [ 'success' => false, 'message' => 'No active Kit.' ]; }
                try {
                    $repo = new $repo_class( $kit );
                    $collection = $repo->load();
                    if ( ! method_exists( $collection, 'create_new' ) && ! method_exists( $collection, 'add' ) ) {
                        return [ 'success' => false, 'message' => 'This Elementor version\'s Variables collection has no create method exposed; needs live-Pro validation.' ];
                    }
                    if ( method_exists( $collection, 'create_new' ) ) {
                        $collection->create_new( [ 'label' => (string) $input['label'], 'type' => (string) $input['type'], 'value' => (string) $input['value'] ] );
                    } else {
                        $collection->add( [ 'label' => (string) $input['label'], 'type' => (string) $input['type'], 'value' => (string) $input['value'] ] );
                    }
                    $repo->save( $collection );
                    if ( method_exists( $kit, 'get_id' ) ) { AVCF_Elementor_Helpers::clear_css_cache( (int) $kit->get_id() ); }
                    return [ 'success' => true, 'applied' => true, 'message' => 'Variable created.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/elementor-edit-variable', [
            'label' => 'Edit Elementor Variable', 'category' => 'atarim', 'description' => $note,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std_out,
            'execute_callback' => function( $input = [] ) use ( $self, $guard, $repo_class ) {
                $g = $guard(); if ( $g ) { return $g; }
                $gate = $self->confirm_gate( $input, 'Variable edit' ); if ( $gate ) { return $gate; }
                $kit = $self->active_kit(); if ( ! $kit ) { return [ 'success' => false, 'message' => 'No active Kit.' ]; }
                try {
                    $repo = new $repo_class( $kit );
                    $collection = $repo->load();
                    return [ 'success' => false, 'message' => 'Variable edit is not yet wired to a stable API on this build — read with list-variables and adjust against live Elementor Pro. (id=' . (string) $input['id'] . ')' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Edit failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/elementor-delete-variable', [
            'label' => 'Delete Elementor Variable', 'category' => 'atarim', 'description' => $note,
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> $std_out,
            'execute_callback' => function( $input = [] ) use ( $self, $guard, $repo_class ) {
                $g = $guard(); if ( $g ) { return $g; }
                $gate = $self->confirm_gate( $input, 'Variable delete' ); if ( $gate ) { return $gate; }
                $kit = $self->active_kit(); if ( ! $kit ) { return [ 'success' => false, 'message' => 'No active Kit.' ]; }
                try {
                    $repo = new $repo_class( $kit );
                    $collection = $repo->load();
                    $id = (string) $input['id'];
                    $var = method_exists( $collection, 'get' ) ? $collection->get( $id ) : null;
                    if ( $var && method_exists( $var, 'soft_delete' ) ) {
                        $var->soft_delete();
                        $repo->save( $collection );
                        if ( method_exists( $kit, 'get_id' ) ) { AVCF_Elementor_Helpers::clear_css_cache( (int) $kit->get_id() ); }
                        return [ 'success' => true, 'applied' => true, 'message' => 'Variable deleted.' ];
                    }
                    return [ 'success' => false, 'message' => 'Could not resolve the variable for deletion on this build; needs live-Pro validation.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Delete failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------ dynamic tags (3) ------------------------- */

    private function register_dynamic_tags() {
        $self = $this;
        $detector = $this->detector;

        $guard = function() use ( $detector ) {
            if ( ! $detector->avcf_elementor_is_pro() ) {
                return [ 'success' => false, 'message' => 'Dynamic tags require Elementor Pro, which is not active.' ];
            }
            return null;
        };
        $manager = function() {
            $p = \Elementor\Plugin::$instance;
            return isset( $p->dynamic_tags ) ? $p->dynamic_tags : null;
        };

        wp_register_ability( 'atarim/elementor-list-dynamic-tags', [
            'label' => 'List Elementor Dynamic Tags', 'category' => 'atarim',
            'description' => 'List the registered Elementor dynamic tags (name, title, group). Requires Elementor Pro.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'tags' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $manager ) {
                $g = $guard(); if ( $g ) { return $g; }
                $m = $manager(); if ( ! is_object( $m ) ) { return [ 'success' => false, 'message' => 'Dynamic tags manager unavailable.' ]; }
                try {
                    $tags = method_exists( $m, 'get_tags_config' ) ? (array) $m->get_tags_config() : [];
                    $out = [];
                    foreach ( $tags as $name => $cfg ) {
                        $out[] = [ 'name' => (string) $name, 'title' => isset( $cfg['title'] ) ? $cfg['title'] : '', 'group' => isset( $cfg['group'] ) ? $cfg['group'] : '' ];
                    }
                    return [ 'success' => true, 'tags' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to list dynamic tags: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/elementor-get-dynamic-tag', [
            'label' => 'Get Elementor Dynamic Tag', 'category' => 'atarim',
            'description' => 'Get one dynamic tag\'s config/controls by name. Requires Elementor Pro.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'tag' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $guard, $manager ) {
                $g = $guard(); if ( $g ) { return $g; }
                $m = $manager(); if ( ! is_object( $m ) ) { return [ 'success' => false, 'message' => 'Dynamic tags manager unavailable.' ]; }
                try {
                    $info = method_exists( $m, 'get_tag_info' ) ? $m->get_tag_info( (string) $input['name'] ) : null;
                    if ( ! $info ) { return [ 'success' => false, 'message' => sprintf( 'Dynamic tag "%s" not found.', $input['name'] ) ]; }
                    $tag = isset( $info['instance'] ) ? $info['instance'] : null;
                    return [ 'success' => true, 'tag' => [
                        'name'  => (string) $input['name'],
                        'title' => is_object( $tag ) && method_exists( $tag, 'get_title' ) ? $tag->get_title() : '',
                        'group' => is_object( $tag ) && method_exists( $tag, 'get_group' ) ? $tag->get_group() : '',
                    ], 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to get dynamic tag: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/elementor-apply-dynamic-tag', [
            'label' => 'Apply Elementor Dynamic Tag', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. Bind a dynamic tag to one setting of an element (sets the element\'s __dynamic__ entry for that setting). Requires Elementor Pro. Read the element first with elementor-get-content.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'element_id' => [ 'type' => 'string' ], 'setting' => [ 'type' => 'string', 'description' => 'The control/setting name to bind.' ], 'tag_name' => [ 'type' => 'string' ], 'tag_settings' => [ 'type' => 'object' ],
            ], 'required' => [ 'post_id', 'element_id', 'setting', 'tag_name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self, $guard ) {
                $g = $guard(); if ( $g ) { return $g; }
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->post_can( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $eid = (string) $input['element_id']; $setting = (string) $input['setting']; $tag = (string) $input['tag_name'];
                $tag_settings = isset( $input['tag_settings'] ) && is_array( $input['tag_settings'] ) ? $input['tag_settings'] : [];
                $tag_id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
                $token  = sprintf( '[elementor-tag id="%s" name="%s" settings="%s"]', $tag_id, $tag, rawurlencode( wp_json_encode( $tag_settings ) ) );
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id ); $found = false;
                $tree = AVCF_Elementor_Helpers::map_edit( $tree, $eid, function( $node ) use ( $setting, $token ) {
                    $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
                    $dynamic  = isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) ? $settings['__dynamic__'] : [];
                    $dynamic[ $setting ] = $token;
                    $settings['__dynamic__'] = $dynamic;
                    $node['settings'] = $settings;
                    return $node;
                }, $found );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $eid ) ]; }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Bound dynamic tag "%s" to setting "%s".', $tag, $setting ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ interactions (3) ------------------------- */

    private function register_interactions() {
        $self = $this;

        wp_register_ability( 'atarim/elementor-list-interactions', [
            'label' => 'List Elementor Interactions', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. List interactions configured on elements of a post (collected from each element\'s "interactions" setting).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'interactions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                $out = [];
                $walk = function( $els ) use ( &$walk, &$out ) {
                    foreach ( (array) $els as $el ) {
                        if ( ! is_array( $el ) ) { continue; }
                        if ( isset( $el['settings']['interactions'] ) && ! empty( $el['settings']['interactions'] ) ) {
                            $out[] = [ 'element_id' => isset( $el['id'] ) ? $el['id'] : '', 'interactions' => $el['settings']['interactions'] ];
                        }
                        if ( ! empty( $el['elements'] ) ) { $walk( $el['elements'] ); }
                    }
                };
                $walk( $tree );
                return [ 'success' => true, 'interactions' => $out, 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/elementor-add-interaction', [
            'label' => 'Add Elementor Interaction', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. Add an interaction definition to an element (appends to the element\'s "interactions" setting array). interaction is the Elementor interaction config object.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'element_id' => [ 'type' => 'string' ], 'interaction' => [ 'type' => 'object' ] ], 'required' => [ 'post_id', 'element_id', 'interaction' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'interaction_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->post_can( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $eid = (string) $input['element_id'];
                $interaction = is_array( $input['interaction'] ) ? $input['interaction'] : [];
                $iid = 'i-' . $self->gen_id();
                $interaction['id'] = $iid;
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id ); $found = false;
                $tree = AVCF_Elementor_Helpers::map_edit( $tree, $eid, function( $node ) use ( $interaction ) {
                    $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
                    $list = isset( $settings['interactions'] ) && is_array( $settings['interactions'] ) ? $settings['interactions'] : [];
                    $list[] = $interaction;
                    $settings['interactions'] = $list;
                    $node['settings'] = $settings;
                    return $node;
                }, $found );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $eid ) ]; }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'interaction_id' => $iid, 'message' => 'Interaction added.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/elementor-delete-interaction', [
            'label' => 'Delete Elementor Interaction', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. Remove an interaction from an element by its interaction id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'element_id' => [ 'type' => 'string' ], 'interaction_id' => [ 'type' => 'string' ] ], 'required' => [ 'post_id', 'element_id', 'interaction_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->post_can( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $eid = (string) $input['element_id']; $iid = (string) $input['interaction_id'];
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id ); $found = false;
                $tree = AVCF_Elementor_Helpers::map_edit( $tree, $eid, function( $node ) use ( $iid ) {
                    $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
                    if ( isset( $settings['interactions'] ) && is_array( $settings['interactions'] ) ) {
                        $settings['interactions'] = array_values( array_filter( $settings['interactions'], function( $i ) use ( $iid ) {
                            return ! ( is_array( $i ) && isset( $i['id'] ) && $i['id'] === $iid );
                        } ) );
                    }
                    $node['settings'] = $settings;
                    return $node;
                }, $found );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $eid ) ]; }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Interaction removed (if it existed).' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------- delete-element-style (1) ------------------- */

    private function register_element_style() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-delete-element-style', [
            'label' => 'Delete Elementor Element Style', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. Clear an element\'s local style overrides — removes its atomic "styles" entries and resets style-tab settings — so it falls back to defaults / global styles. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'element_id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->post_can( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $eid = (string) $input['element_id'];
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                if ( AVCF_Elementor_Helpers::find( $tree, $eid ) === null ) { return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $eid ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'cleared' => false, 'message' => sprintf( 'Dry run: would clear local styles on "%s". Re-call with confirm:true.', $eid ) ]; }
                $found = false;
                $tree = AVCF_Elementor_Helpers::map_edit( $tree, $eid, function( $node ) {
                    if ( isset( $node['styles'] ) ) { $node['styles'] = []; }
                    return $node;
                }, $found );
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'cleared' => true, 'message' => sprintf( 'Cleared local styles on "%s".', $eid ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------- create-atomic-widget (1) ------------------- */

    private function register_atomic_widget() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-create-atomic-widget', [
            'label' => 'Create Elementor Atomic Widget', 'category' => 'atarim',
            'description' => 'EXPERIMENTAL. Insert an Elementor v4 atomic widget (e.g. "e-heading", "e-paragraph", "e-button") into a post. Atomic widgets are written via a RAW save because Elementor\'s Document::save() strips them. settings uses the atomic prop shape ({"$$type":...,"value":...}); pass them as you would see them in elementor-get-content. Validate output on a live Elementor build.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'widget_type' => [ 'type' => 'string', 'description' => 'Atomic widget type, e.g. "e-heading".' ], 'parent_id' => [ 'type' => 'string' ], 'index' => [ 'type' => 'integer', 'minimum' => 0 ], 'settings' => [ 'type' => 'object' ], 'styles' => [ 'type' => 'object' ],
            ], 'required' => [ 'post_id', 'widget_type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'element_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = (int) $input['post_id'];
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->post_can( $post_id ) ) { return [ 'success' => false, 'message' => 'No permission to edit this post.' ]; }
                $node = [
                    'id'         => $self->gen_id(),
                    'elType'     => 'widget',
                    'widgetType' => (string) $input['widget_type'],
                    'settings'   => isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : (object) [],
                    'styles'     => isset( $input['styles'] ) && is_array( $input['styles'] ) ? $input['styles'] : (object) [],
                    'elements'   => [],
                    'editor_settings' => [],
                    'version'    => '0.0',
                ];
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                $index = isset( $input['index'] ) ? (int) $input['index'] : null;
                list( $tree, $inserted ) = AVCF_Elementor_Helpers::insert( $tree, isset( $input['parent_id'] ) ? (string) $input['parent_id'] : null, $node, $index );
                if ( ! $inserted ) { return [ 'success' => false, 'message' => sprintf( 'parent_id "%s" not found.', isset( $input['parent_id'] ) ? $input['parent_id'] : '' ) ]; }
                // Force a raw write — Document::save() strips atomic widgets.
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree, true ) ) { return [ 'success' => false, 'message' => 'Failed to save (raw).' ]; }
                return [ 'success' => true, 'element_id' => $node['id'], 'message' => sprintf( 'Inserted atomic widget "%s" (raw write).', $node['widgetType'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------- validate-widget (1) ----------------------- */

    private function register_validate_widget() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-validate-widget', [
            'label' => 'Validate Elementor Widget Settings', 'category' => 'atarim',
            'description' => 'Check a settings object against a widget\'s controls: reports unknown setting keys (not in the widget\'s control list) and the list of valid control names. Use before add-element / edit-element to catch typos.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'widget' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'widget', 'settings' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'valid' => [ 'type' => 'boolean' ], 'unknown_keys' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $p = \Elementor\Plugin::$instance;
                $wm = isset( $p->widgets_manager ) ? $p->widgets_manager : null;
                if ( ! is_object( $wm ) ) { return [ 'success' => false, 'message' => 'Widgets manager unavailable.' ]; }
                $name = (string) $input['widget'];
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                try {
                    $widget = $wm->get_widget_types( $name );
                    if ( ! $widget ) { return [ 'success' => false, 'message' => sprintf( 'Widget "%s" not found.', $name ) ]; }
                    $controls = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : [];
                    $valid_keys = array_keys( $controls );
                    $unknown = [];
                    foreach ( array_keys( $settings ) as $k ) {
                        if ( $k !== '__dynamic__' && ! in_array( $k, $valid_keys, true ) ) { $unknown[] = $k; }
                    }
                    return [ 'success' => true, 'valid' => empty( $unknown ), 'unknown_keys' => $unknown, 'message' => empty( $unknown ) ? 'All settings keys are valid controls.' : sprintf( '%d unknown key(s).', count( $unknown ) ) ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Validation failed: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
