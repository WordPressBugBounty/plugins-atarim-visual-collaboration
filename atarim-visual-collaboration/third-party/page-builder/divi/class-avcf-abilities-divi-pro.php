<?php
/**
 * Divi 5 — Advanced MCP abilities.
 *
 * Loops, dynamic content, global presets, and theme-builder templates. These
 * reach into Divi 5 internals (loop attr schema, DynamicContentOptions,
 * GlobalPreset, theme-builder API), so they are best-effort/EXPERIMENTAL and
 * degrade cleanly when an API isn't present on the build. Loops and dynamic
 * content edit module attrs in the content tree (reusing the core tree helpers);
 * presets read site-wide preset data and write a preset reference onto a module.
 *
 * Divi 5 only; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Divi_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Divi_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Divi_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_divi_is_available() ) {
            return;
        }
        $this->register_loops();
        $this->register_dynamic_content();
        $this->register_global_presets();
        $this->register_theme_builder();
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
    private function post_id_prop() {
        return [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Target post/page id (also accepts "post").' ];
    }
    private function guard( $input, $need_edit = false ) {
        if ( ! $this->detector->avcf_divi_d5_enabled() ) {
            return [ 'err' => [ 'success' => false, 'message' => 'Divi 5 (block-based builder) is not enabled. These abilities support Divi 5 only.' ] ];
        }
        $pid = 0;
        if ( isset( $input['post_id'] ) ) { $pid = (int) $input['post_id']; }
        elseif ( isset( $input['post'] ) ) { $pid = (int) $input['post']; }
        if ( $pid <= 0 || ! get_post( $pid ) ) { return [ 'err' => [ 'success' => false, 'message' => 'A valid post_id is required.' ] ]; }
        if ( $need_edit && ! ( current_user_can( 'edit_post', $pid ) || current_user_can( 'edit_posts' ) ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'No permission to edit this post.' ] ];
        }
        return [ 'post_id' => $pid ];
    }

    /* ----- attr dot-path helpers (public for closures) ----- */

    public function path_set( $arr, $path, $value ) {
        $keys = explode( '.', $path );
        $ref =& $arr;
        $last = count( $keys ) - 1;
        foreach ( $keys as $i => $k ) {
            if ( $i === $last ) { $ref[ $k ] = $value; }
            else { if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) { $ref[ $k ] = []; } $ref =& $ref[ $k ]; }
        }
        unset( $ref );
        return $arr;
    }
    public function path_get( $arr, $path ) {
        foreach ( explode( '.', $path ) as $k ) {
            if ( ! is_array( $arr ) || ! array_key_exists( $k, $arr ) ) { return null; }
            $arr = $arr[ $k ];
        }
        return $arr;
    }
    public function path_unset( $arr, $path ) {
        $keys = explode( '.', $path );
        $ref =& $arr;
        $last = count( $keys ) - 1;
        foreach ( $keys as $i => $k ) {
            if ( $i === $last ) { unset( $ref[ $k ] ); }
            else { if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) { return $arr; } $ref =& $ref[ $k ]; }
        }
        unset( $ref );
        return $arr;
    }

    /** Load a module node at address (with d5/edit guard). Returns [tree,node,post_id] or ['err'=>...]. */
    public function load_module( $input ) {
        $g = $this->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g; }
        $address = isset( $input['address'] ) ? (string) $input['address'] : '';
        $tree = AVCF_Divi_Helpers::read_tree( $g['post_id'] );
        $node = AVCF_Divi_Helpers::tree_get( $tree, $address );
        if ( $node === null ) { return [ 'err' => [ 'success' => false, 'message' => sprintf( 'No module at address "%s".', $address ) ] ]; }
        if ( isset( $node['_raw'] ) ) { return [ 'err' => [ 'success' => false, 'message' => 'That address is a non-Divi passthrough block.' ] ]; }
        return [ 'post_id' => $g['post_id'], 'tree' => $tree, 'node' => $node, 'address' => $address ];
    }
    public function save_module( $post_id, $tree, $address, $node ) {
        $new = AVCF_Divi_Helpers::tree_replace( $tree, $address, $node );
        if ( $new === null ) { return false; }
        return AVCF_Divi_Helpers::write_tree( $post_id, $new );
    }

    /* ------------------------------- loops ----------------------------- */

    private function register_loops() {
        $self = $this; $std = $this->std_out();
        $LOOP_PATH = 'module.advanced.loop.desktop.value';

        wp_register_ability( 'atarim/divi-list-loop-query-types', [
            'label' => 'List Divi Loop Query Types', 'category' => 'atarim',
            'description' => 'List the Divi 5 query-loop query types and ordering options (reference for enable-loop / edit-loop).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'query_types' => [ 'type' => 'array' ], 'order_by' => [ 'type' => 'array' ], 'order' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                return [
                    'success' => true,
                    'query_types' => [ 'post_types', 'post_taxonomies', 'terms', 'users', 'user_roles', 'menus' ],
                    'order_by' => [ 'date', 'title', 'menu_order', 'rand', 'ID', 'modified', 'comment_count' ],
                    'order' => [ 'ASC', 'DESC' ],
                    'message' => 'OK.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/divi-enable-loop', [
            'label' => 'Enable Divi Loop', 'category' => 'atarim',
            'description' => 'Turn a module into a repeating query loop. Identify it by address. query_type (default "post_types") + sub_types (post-type slugs / taxonomy names / role slugs / menu ids) define what to loop over; order_by/order/per_page/offset tune the query. Writes module.advanced.loop. Inside the loop, bind fields to loop sources via apply-dynamic-content. (Loop attr schema is best-effort; verify in the Divi editor.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => $self->post_id_prop(),
                'address'    => [ 'type' => 'string' ],
                'query_type' => [ 'type' => 'string', 'enum' => [ 'post_types', 'post_taxonomies', 'terms', 'users', 'user_roles', 'menus' ], 'default' => 'post_types' ],
                'sub_types'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'order_by'   => [ 'type' => 'string', 'default' => 'date' ],
                'order'      => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
                'per_page'   => [ 'type' => 'integer', 'minimum' => 1 ],
                'offset'     => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self, $LOOP_PATH ) {
                $m = $self->load_module( $input ); if ( isset( $m['err'] ) ) { return $m['err']; }
                $loop = [
                    'enabled'   => 'on',
                    'queryType' => isset( $input['query_type'] ) ? (string) $input['query_type'] : 'post_types',
                    'subTypes'  => isset( $input['sub_types'] ) && is_array( $input['sub_types'] ) ? array_values( $input['sub_types'] ) : [],
                    'orderBy'   => isset( $input['order_by'] ) ? (string) $input['order_by'] : 'date',
                    'order'     => isset( $input['order'] ) ? (string) $input['order'] : 'DESC',
                    'loopId'    => substr( md5( uniqid( '', true ) ), 0, 8 ),
                ];
                if ( isset( $input['per_page'] ) ) { $loop['perPage'] = (int) $input['per_page']; }
                if ( isset( $input['offset'] ) )   { $loop['offset'] = (int) $input['offset']; }
                $node = $m['node'];
                $node['attrs'] = $self->path_set( isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [], $LOOP_PATH, $loop );
                if ( ! $self->save_module( $m['post_id'], $m['tree'], $m['address'], $node ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Loop enabled on module at "%s".', $m['address'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/divi-edit-loop', [
            'label' => 'Edit Divi Loop', 'category' => 'atarim',
            'description' => 'Update an existing loop\'s query parameters (query_type, sub_types, order_by, order, per_page, offset) without changing its loop id. Only provided params change. Enable the loop first with enable-loop.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => $self->post_id_prop(),
                'address'    => [ 'type' => 'string' ],
                'query_type' => [ 'type' => 'string', 'enum' => [ 'post_types', 'post_taxonomies', 'terms', 'users', 'user_roles', 'menus' ] ],
                'sub_types'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'order_by'   => [ 'type' => 'string' ],
                'order'      => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ] ],
                'per_page'   => [ 'type' => 'integer', 'minimum' => 1 ],
                'offset'     => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self, $LOOP_PATH ) {
                $m = $self->load_module( $input ); if ( isset( $m['err'] ) ) { return $m['err']; }
                $node = $m['node'];
                $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
                $loop = $self->path_get( $attrs, $LOOP_PATH );
                if ( ! is_array( $loop ) || empty( $loop ) ) { return [ 'success' => false, 'message' => 'No loop on this module. Use enable-loop first.' ]; }
                $map = [ 'query_type' => 'queryType', 'order_by' => 'orderBy', 'order' => 'order', 'per_page' => 'perPage', 'offset' => 'offset' ];
                foreach ( $map as $in => $out ) { if ( isset( $input[ $in ] ) ) { $loop[ $out ] = in_array( $in, [ 'per_page', 'offset' ], true ) ? (int) $input[ $in ] : (string) $input[ $in ]; } }
                if ( isset( $input['sub_types'] ) && is_array( $input['sub_types'] ) ) { $loop['subTypes'] = array_values( $input['sub_types'] ); }
                $node['attrs'] = $self->path_set( $attrs, $LOOP_PATH, $loop );
                if ( ! $self->save_module( $m['post_id'], $m['tree'], $m['address'], $node ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Loop updated.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/divi-disable-loop', [
            'label' => 'Disable Divi Loop', 'category' => 'atarim',
            'description' => 'Remove the query loop from a module (clears module.advanced.loop). The module reverts to a single static instance.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop(), 'address' => [ 'type' => 'string' ] ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> $std,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $m = $self->load_module( $input ); if ( isset( $m['err'] ) ) { return $m['err']; }
                $node = $m['node'];
                $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
                if ( $self->path_get( $attrs, 'module.advanced.loop' ) === null ) { return [ 'success' => true, 'message' => 'No loop set; nothing to disable.' ]; }
                $node['attrs'] = $self->path_unset( $attrs, 'module.advanced.loop' );
                if ( ! $self->save_module( $m['post_id'], $m['tree'], $m['address'], $node ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Loop disabled.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* -------------------------- dynamic content ------------------------ */

    private function register_dynamic_content() {
        $self = $this;

        wp_register_ability( 'atarim/divi-list-dynamic-sources', [
            'label' => 'List Divi Dynamic Sources', 'category' => 'atarim',
            'description' => 'List the Divi 5 dynamic-content sources available for a post (e.g. post_title, post_excerpt, featured image, post_meta_key, author fields). Use a source id with apply-dynamic-content.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop() ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'sources' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $cls = '\ET\Builder\Packages\Module\Layout\Components\DynamicContent\DynamicContentOptions';
                if ( ! class_exists( $cls ) || ! method_exists( $cls, 'get_options' ) ) {
                    return [ 'success' => false, 'message' => 'Divi 5 dynamic-content options API not available on this build.' ];
                }
                try {
                    $options = call_user_func( [ $cls, 'get_options' ], (int) $g['post_id'], 'edit' );
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ];
                }
                $out = [];
                if ( is_array( $options ) ) {
                    foreach ( $options as $id => $opt ) {
                        $out[] = [ 'id' => (string) $id, 'label' => is_array( $opt ) && isset( $opt['label'] ) ? $opt['label'] : '' ];
                    }
                }
                return [ 'success' => true, 'sources' => $out, 'message' => sprintf( '%d source(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/divi-apply-dynamic-content', [
            'label' => 'Apply Divi Dynamic Content', 'category' => 'atarim',
            'description' => 'Bind a module field to a dynamic source so it resolves at render time (e.g. a heading to the post title). address = module (from get-content); field = the field attrName (from get-module-schema, fields with dynamic:true, e.g. "content.innerContent"); source = a dynamic source id (from list-dynamic-sources). Optional settings carry source options (e.g. {"meta_key":"price"}). Writes Divi\'s {type:"content",value:{name:source,settings:…}} at <field>.<breakpoint>.value. Protected meta keys are rejected.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => $self->post_id_prop(),
                'address'    => [ 'type' => 'string' ],
                'field'      => [ 'type' => 'string', 'description' => 'Field attrName, e.g. "content.innerContent".' ],
                'source'     => [ 'type' => 'string' ],
                'settings'   => [ 'type' => 'object' ],
                'breakpoint' => [ 'type' => 'string', 'default' => 'desktop' ],
            ], 'required' => [ 'post_id', 'address', 'field', 'source' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $m = $self->load_module( $input ); if ( isset( $m['err'] ) ) { return $m['err']; }
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                $meta_key = isset( $settings['meta_key'] ) ? (string) $settings['meta_key'] : '';
                if ( $meta_key !== '' && is_protected_meta( $meta_key ) ) { return [ 'success' => false, 'message' => sprintf( 'Refusing to bind to protected meta key "%s".', $meta_key ) ]; }
                $field = (string) $input['field'];
                $bp    = isset( $input['breakpoint'] ) ? (string) $input['breakpoint'] : 'desktop';
                $value = [ 'type' => 'content', 'value' => [ 'name' => (string) $input['source'], 'settings' => $settings ] ];
                $node = $m['node'];
                $node['attrs'] = $self->path_set( isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [], $field . '.' . $bp . '.value', $value );
                if ( ! $self->save_module( $m['post_id'], $m['tree'], $m['address'], $node ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Bound %s to dynamic source "%s".', $field, $input['source'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/divi-clear-dynamic-content', [
            'label' => 'Clear Divi Dynamic Content', 'category' => 'atarim',
            'description' => 'Remove a dynamic-content binding from a module field (clears <field>.<breakpoint>.value). The field reverts to whatever static value you set afterward via edit-module.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => $self->post_id_prop(),
                'address'    => [ 'type' => 'string' ],
                'field'      => [ 'type' => 'string' ],
                'breakpoint' => [ 'type' => 'string', 'default' => 'desktop' ],
            ], 'required' => [ 'post_id', 'address', 'field' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $m = $self->load_module( $input ); if ( isset( $m['err'] ) ) { return $m['err']; }
                $field = (string) $input['field'];
                $bp    = isset( $input['breakpoint'] ) ? (string) $input['breakpoint'] : 'desktop';
                $node = $m['node'];
                $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
                $node['attrs'] = $self->path_unset( $attrs, $field . '.' . $bp . '.value' );
                if ( ! $self->save_module( $m['post_id'], $m['tree'], $m['address'], $node ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Cleared dynamic binding on %s.', $field ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- global presets ------------------------ */

    private function module_presets() {
        $cls = '\ET\Builder\Packages\GlobalData\GlobalPreset';
        if ( ! class_exists( $cls ) || ! method_exists( $cls, 'get_data' ) ) { return null; }
        try {
            $data = call_user_func( [ $cls, 'get_data' ] );
        } catch ( \Throwable $e ) { return null; }
        return is_array( $data ) && isset( $data['module'] ) && is_array( $data['module'] ) ? $data['module'] : [];
    }

    private function register_global_presets() {
        $self = $this;

        wp_register_ability( 'atarim/divi-list-global-presets', [
            'label' => 'List Divi Global Presets', 'category' => 'atarim',
            'description' => 'List Divi 5 global module presets (module, preset_id, name). Optionally filter by module (with or without "divi/" prefix). An empty list means no presets exist yet (or the preset API is unavailable).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'module' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'presets' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->detector_d5() ) { return [ 'success' => false, 'message' => 'Divi 5 is not enabled.' ]; }
                $presets = $self->module_presets_public();
                if ( $presets === null ) { return [ 'success' => false, 'message' => 'Divi 5 global preset API not available on this build.' ]; }
                $filter = isset( $input['module'] ) ? AVCF_Divi_Helpers::normalize_module_name( $input['module'] ) : '';
                $out = [];
                foreach ( $presets as $module_name => $entry ) {
                    if ( ! is_array( $entry ) ) { continue; }
                    if ( $filter !== '' && $module_name !== $filter ) { continue; }
                    $items = isset( $entry['items'] ) && is_array( $entry['items'] ) ? $entry['items'] : [];
                    foreach ( $items as $pid => $preset ) {
                        $out[] = [ 'module' => (string) $module_name, 'preset_id' => (string) $pid, 'name' => is_array( $preset ) && isset( $preset['name'] ) ? $preset['name'] : '' ];
                    }
                }
                return [ 'success' => true, 'presets' => $out, 'message' => sprintf( '%d preset(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/divi-get-global-preset', [
            'label' => 'Get Divi Global Preset', 'category' => 'atarim',
            'description' => 'Read one global preset\'s data (its styling attrs) by module + preset_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'module' => [ 'type' => 'string' ], 'preset_id' => [ 'type' => 'string' ] ], 'required' => [ 'module', 'preset_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'preset' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->detector_d5() ) { return [ 'success' => false, 'message' => 'Divi 5 is not enabled.' ]; }
                $presets = $self->module_presets_public();
                if ( $presets === null ) { return [ 'success' => false, 'message' => 'Divi 5 global preset API not available on this build.' ]; }
                $name = AVCF_Divi_Helpers::normalize_module_name( $input['module'] );
                $pid  = (string) $input['preset_id'];
                if ( ! isset( $presets[ $name ]['items'][ $pid ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Preset "%s" not found for module "%s".', $pid, $name ) ]; }
                return [ 'success' => true, 'preset' => (array) $presets[ $name ]['items'][ $pid ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/divi-apply-global-preset', [
            'label' => 'Apply Divi Global Preset', 'category' => 'atarim',
            'description' => 'Apply a global preset to a module on a page by writing the preset reference (modulePreset) onto the module at address. The preset must belong to that module\'s type.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'   => $self->post_id_prop(),
                'address'   => [ 'type' => 'string' ],
                'preset_id' => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'address', 'preset_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $m = $self->load_module( $input ); if ( isset( $m['err'] ) ) { return $m['err']; }
                $presets = $self->module_presets_public();
                if ( $presets === null ) { return [ 'success' => false, 'message' => 'Divi 5 global preset API not available on this build.' ]; }
                $name = isset( $m['node']['name'] ) ? (string) $m['node']['name'] : '';
                $pid  = (string) $input['preset_id'];
                if ( ! isset( $presets[ $name ]['items'][ $pid ] ) ) { return [ 'success' => false, 'message' => sprintf( 'Preset "%s" is not a preset for module "%s".', $pid, $name ) ]; }
                $node = $m['node'];
                $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
                $attrs['modulePreset'] = $pid;
                $node['attrs'] = $attrs;
                if ( ! $self->save_module( $m['post_id'], $m['tree'], $m['address'], $node ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Applied preset "%s" to module at "%s".', $pid, $m['address'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /** Public wrappers for closures. */
    public function detector_d5() { return $this->detector->avcf_divi_d5_enabled(); }
    public function module_presets_public() { return $this->module_presets(); }

    /* --------------------------- theme builder ------------------------- */

    private function register_theme_builder() {
        wp_register_ability( 'atarim/divi-list-theme-builder-templates', [
            'label' => 'List Divi Theme Builder Templates', 'category' => 'atarim',
            'description' => 'List the Divi Theme Builder templates (global/custom header, body, footer layouts and their assignments).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'templates' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! function_exists( 'et_theme_builder_get_theme_builder_templates' ) ) {
                    return [ 'success' => false, 'message' => 'Divi Theme Builder API not available on this build.' ];
                }
                try {
                    $templates = et_theme_builder_get_theme_builder_templates( true );
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed: ' . $e->getMessage() ];
                }
                $out = is_array( $templates ) ? array_values( $templates ) : [];
                return [ 'success' => true, 'templates' => $out, 'message' => sprintf( '%d template(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
