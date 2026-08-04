<?php
/**
 * Breakdance — MCP abilities (core: element tree).
 *
 * Read/edit the node tree Breakdance stores in `_breakdance_data`. Nodes have
 * stable integer ids; mutations rebuild the lookup table + parent annotations
 * and round-trip-guard the write. Element registry (types/schema) depends on
 * Breakdance internals and degrades gracefully. Built from the Novamira
 * reference; not runtime-tested. No cross-process write lock (see helpers note).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Breakdance extends AVCF_Abilities_Base {

    /** @var AVCF_Breakdance_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Breakdance_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_breakdance_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_list_element_types();
        $this->register_get_element_schema();
        $this->register_get_element_tree();
        $this->register_get_element();
        $this->register_add_element();
        $this->register_edit_element();
        $this->register_delete_element();
        $this->register_move_element();
        $this->register_set_content();
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
        $pid = isset( $input['post_id'] ) ? (int) $input['post_id'] : ( isset( $input['post'] ) ? (int) $input['post'] : 0 );
        if ( $pid <= 0 || ! get_post( $pid ) ) { return [ 'err' => [ 'success' => false, 'message' => 'A valid post_id is required.' ] ]; }
        if ( $need_edit && ! ( current_user_can( 'edit_post', $pid ) || current_user_can( 'edit_posts' ) ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'No permission to edit this post.' ] ];
        }
        return [ 'post_id' => $pid ];
    }
    /** Commit a tree; returns null on success or an error result array. */
    public function commit( $post_id, $tree ) {
        $res = AVCF_Breakdance_Helpers::write_tree( $post_id, $tree );
        if ( $res !== true ) { return [ 'success' => false, 'message' => is_string( $res ) ? $res : 'Failed to save.' ]; }
        return null;
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/breakdance-check-setup', [
            'label' => 'Check Breakdance Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether Breakdance is active and its version.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_breakdance_is_available(), 'version' => $d->avcf_breakdance_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ list-element-types ----------------------- */

    private function register_list_element_types() {
        wp_register_ability( 'atarim/breakdance-list-element-types', [
            'label' => 'List Breakdance Element Types', 'category' => 'atarim',
            'description' => 'List the registered Breakdance element types (type slug + name). The type slug is what you pass to add-element. If the registry can\'t be read on this build, returns an empty list.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'types' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = AVCF_Breakdance_Helpers::element_types();
                $search = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
                if ( $search !== '' ) {
                    $types = array_values( array_filter( $types, function( $t ) use ( $search ) {
                        return strpos( strtolower( $t['type'] ), $search ) !== false || strpos( strtolower( (string) $t['name'] ), $search ) !== false;
                    } ) );
                }
                return [ 'success' => true, 'types' => $types, 'message' => $types ? sprintf( '%d type(s).', count( $types ) ) : 'Element registry not readable on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-element-schema ----------------------- */

    private function register_get_element_schema() {
        wp_register_ability( 'atarim/breakdance-get-element-schema', [
            'label' => 'Get Breakdance Element Schema', 'category' => 'atarim',
            'description' => 'Return the controls/properties schema for one Breakdance element type, if the build exposes the schema API. Use the property paths it returns when setting properties on add/edit-element.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'type' => [ 'type' => 'string' ] ], 'required' => [ 'type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'schema' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $type = (string) $input['type'];
                foreach ( [ '\Breakdance\Elements\get_element_schema', '\Breakdance\breakdance_get_element_schema', '\Breakdance\Elements\element_schema' ] as $fn ) {
                    if ( function_exists( $fn ) ) {
                        try {
                            $schema = call_user_func( $fn, $type );
                            if ( is_array( $schema ) ) { return [ 'success' => true, 'schema' => $schema, 'message' => 'OK.' ]; }
                        } catch ( \Throwable $e ) { /* try next */ }
                    }
                }
                return [ 'success' => false, 'message' => 'Element schema API not available on this build. Use get-element on an existing instance to see its properties shape.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- get-element-tree ------------------------ */

    private function register_get_element_tree() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-get-element-tree', [
            'label' => 'Get Breakdance Element Tree', 'category' => 'atarim',
            'description' => 'Return a post\'s Breakdance element tree as a nested {id, type, child_count, children} summary (no properties). depth controls how deep to walk (-1 = entire tree, default -1). Use get-element for one node\'s full properties.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop(), 'depth' => [ 'type' => 'integer', 'default' => -1 ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'tree' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Breakdance_Helpers::read_tree( $g['post_id'] );
                $depth = isset( $input['depth'] ) ? (int) $input['depth'] : -1;
                $root = isset( $tree['root'] ) && is_array( $tree['root'] ) ? $tree['root'] : AVCF_Breakdance_Helpers::empty_tree()['root'];
                return [ 'success' => true, 'tree' => AVCF_Breakdance_Helpers::summary( $root, $depth ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-element -------------------------- */

    private function register_get_element() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-get-element', [
            'label' => 'Get Breakdance Element', 'category' => 'atarim',
            'description' => 'Return one element by id with its full data: type, parent_id, properties, and child ids.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop(), 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'post_id', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'element' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Breakdance_Helpers::read_tree( $g['post_id'] );
                $hit = AVCF_Breakdance_Helpers::find_node( $tree, (int) $input['id'] );
                if ( $hit === null ) { return [ 'success' => false, 'message' => sprintf( 'No element with id %d.', (int) $input['id'] ) ]; }
                return [ 'success' => true, 'element' => AVCF_Breakdance_Helpers::to_array( $hit['node'], $hit['parent_id'] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- add-element -------------------------- */

    private function register_add_element() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-add-element', [
            'label' => 'Add Breakdance Element', 'category' => 'atarim',
            'description' => 'Insert a new element. type is the element slug (see list-element-types). parent_id is the container to add into (omit = the root). position is the 0-based index among the parent\'s children (omit = append). properties is the element\'s settings object. Returns the new element id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => $self->post_id_prop(),
                'type'       => [ 'type' => 'string' ],
                'parent_id'  => [ 'type' => 'integer' ],
                'position'   => [ 'type' => 'integer', 'minimum' => 0 ],
                'properties' => [ 'type' => 'object' ],
            ], 'required' => [ 'post_id', 'type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Breakdance_Helpers::read_tree( $g['post_id'] );
                $parent_id = isset( $input['parent_id'] ) ? (int) $input['parent_id'] : (int) ( $tree['root']['id'] ?? 1 );
                if ( AVCF_Breakdance_Helpers::find_node( $tree, $parent_id ) === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_id %d not found.', $parent_id ) ]; }
                $new_id = AVCF_Breakdance_Helpers::allocate_id( $tree );
                $node = [ 'id' => $new_id, 'data' => [ 'type' => (string) $input['type'], 'properties' => isset( $input['properties'] ) && is_array( $input['properties'] ) ? $input['properties'] : [] ], 'children' => [] ];
                $position = isset( $input['position'] ) ? (int) $input['position'] : null;
                $ok = false;
                $tree['root'] = AVCF_Breakdance_Helpers::insert_in( $tree['root'], $parent_id, $node, $position, $ok );
                if ( ! $ok ) { return [ 'success' => false, 'message' => 'Insert failed.' ]; }
                $err = $self->commit( $g['post_id'], $tree ); if ( $err ) { return $err; }
                return [ 'success' => true, 'id' => $new_id, 'message' => sprintf( 'Inserted "%s" (id %d).', $input['type'], $new_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- edit-element ------------------------- */

    private function register_edit_element() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-edit-element', [
            'label' => 'Edit Breakdance Element', 'category' => 'atarim',
            'description' => 'Update an element\'s properties by id. properties is deep-merged into the element\'s existing properties (nested objects merged; list values replaced). Read it first via get-element.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => $self->post_id_prop(),
                'id'         => [ 'type' => 'integer' ],
                'properties' => [ 'type' => 'object' ],
                'replace'    => [ 'type' => 'boolean', 'default' => false, 'description' => 'Replace the whole properties object instead of merging.' ],
            ], 'required' => [ 'post_id', 'id', 'properties' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Breakdance_Helpers::read_tree( $g['post_id'] );
                $id = (int) $input['id'];
                if ( AVCF_Breakdance_Helpers::find_node( $tree, $id ) === null ) { return [ 'success' => false, 'message' => sprintf( 'No element with id %d.', $id ) ]; }
                $props = is_array( $input['properties'] ) ? $input['properties'] : [];
                $replace = ! empty( $input['replace'] );
                $tree['root'] = AVCF_Breakdance_Helpers::map_node( $tree['root'], $id, function( $node ) use ( $props, $replace ) {
                    if ( ! isset( $node['data'] ) || ! is_array( $node['data'] ) ) { $node['data'] = [ 'type' => '', 'properties' => [] ]; }
                    $cur = isset( $node['data']['properties'] ) && is_array( $node['data']['properties'] ) ? $node['data']['properties'] : [];
                    $node['data']['properties'] = $replace ? $props : AVCF_Breakdance_Helpers::deep_merge( $cur, $props );
                    return $node;
                } );
                $err = $self->commit( $g['post_id'], $tree ); if ( $err ) { return $err; }
                return [ 'success' => true, 'message' => sprintf( 'Updated element %d.', $id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- delete-element ------------------------ */

    private function register_delete_element() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-delete-element', [
            'label' => 'Delete Breakdance Element', 'category' => 'atarim',
            'description' => 'Remove an element (and its subtree) by id. Dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop(), 'id' => [ 'type' => 'integer' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'post_id', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'removed' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Breakdance_Helpers::read_tree( $g['post_id'] );
                $id = (int) $input['id'];
                if ( AVCF_Breakdance_Helpers::find_node( $tree, $id ) === null ) { return [ 'success' => false, 'message' => sprintf( 'No element with id %d.', $id ) ]; }
                if ( $id === (int) ( $tree['root']['id'] ?? 1 ) ) { return [ 'success' => false, 'message' => 'Cannot delete the root node.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'removed' => false, 'message' => sprintf( 'Dry run: would remove element %d and its subtree. Re-call with confirm:true.', $id ) ]; }
                $removed = false;
                $tree['root'] = AVCF_Breakdance_Helpers::remove_in( $tree['root'], $id, $removed );
                if ( ! $removed ) { return [ 'success' => false, 'message' => 'Remove failed.' ]; }
                $err = $self->commit( $g['post_id'], $tree ); if ( $err ) { return $err; }
                return [ 'success' => true, 'removed' => true, 'message' => sprintf( 'Removed element %d.', $id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- move-element ------------------------- */

    private function register_move_element() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-move-element', [
            'label' => 'Move Breakdance Element', 'category' => 'atarim',
            'description' => 'Move an element (with its subtree) under a new parent_id at position (omit = append). The element keeps its id. Cannot move an element into its own subtree.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'   => $self->post_id_prop(),
                'id'        => [ 'type' => 'integer' ],
                'parent_id' => [ 'type' => 'integer' ],
                'position'  => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'id', 'parent_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Breakdance_Helpers::read_tree( $g['post_id'] );
                $id = (int) $input['id']; $parent_id = (int) $input['parent_id'];
                if ( $id === $parent_id ) { return [ 'success' => false, 'message' => 'An element cannot be its own parent.' ]; }
                $hit = AVCF_Breakdance_Helpers::find_node( $tree, $id );
                if ( $hit === null ) { return [ 'success' => false, 'message' => sprintf( 'No element with id %d.', $id ) ]; }
                if ( AVCF_Breakdance_Helpers::find_node( $tree, $parent_id ) === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_id %d not found.', $parent_id ) ]; }
                // Guard: parent must not be inside the moving node's subtree.
                if ( AVCF_Breakdance_Helpers::find_node( [ 'root' => $hit['node'] ], $parent_id ) !== null ) { return [ 'success' => false, 'message' => 'Cannot move an element into its own descendant.' ]; }
                $node = $hit['node'];
                $removed = false;
                $tree['root'] = AVCF_Breakdance_Helpers::remove_in( $tree['root'], $id, $removed );
                if ( ! $removed ) { return [ 'success' => false, 'message' => 'Move failed during removal.' ]; }
                $position = isset( $input['position'] ) ? (int) $input['position'] : null;
                $ok = false;
                $tree['root'] = AVCF_Breakdance_Helpers::insert_in( $tree['root'], $parent_id, $node, $position, $ok );
                if ( ! $ok ) { return [ 'success' => false, 'message' => 'Move failed during insert.' ]; }
                $err = $self->commit( $g['post_id'], $tree ); if ( $err ) { return $err; }
                return [ 'success' => true, 'message' => sprintf( 'Moved element %d under %d.', $id, $parent_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/breakdance-set-content', [
            'label' => 'Set Breakdance Content', 'category' => 'atarim',
            'description' => 'Replace a post\'s ENTIRE Breakdance tree. Provide tree as the full structure { root:{ id, data, children[] }, _nextNodeId } (same shape get-element-tree exposes, but with full node data). The lookup table is rebuilt on save. Full overwrite — for targeted edits use add/edit/delete/move-element.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop(), 'tree' => [ 'type' => 'object' ] ], 'required' => [ 'post_id', 'tree' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = isset( $input['tree'] ) && is_array( $input['tree'] ) ? $input['tree'] : [];
                if ( ! isset( $tree['root'] ) || ! is_array( $tree['root'] ) ) { return [ 'success' => false, 'message' => 'tree must contain a "root" node object.' ]; }
                if ( ! isset( $tree['_nextNodeId'] ) || ! is_int( $tree['_nextNodeId'] ) ) { $tree['_nextNodeId'] = 100; }
                $err = $self->commit( $g['post_id'], $tree ); if ( $err ) { return $err; }
                return [ 'success' => true, 'message' => 'Breakdance tree replaced.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
