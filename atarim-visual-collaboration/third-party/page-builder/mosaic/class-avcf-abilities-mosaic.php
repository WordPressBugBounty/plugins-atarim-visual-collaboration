<?php
/**
 * Mosaic — MCP abilities (core: element nodes).
 *
 * Reads/edits the node tree Mosaic stores in {prefix}mosaic_nodes. HIGH-RISK
 * WRITERS: node writes are raw $wpdb against Mosaic's schema, placed with
 * Mosaic's own \Mosaic\Common\FractionalIndex ordering (writes refuse if it's
 * unavailable). Built from the Novamira reference; NOT runtime-tested — validate
 * against a live Mosaic install before fleet use.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Mosaic extends AVCF_Abilities_Base {

    /** @var AVCF_Mosaic_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Mosaic_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_mosaic_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_get_element_tree();
        $this->register_list_components();
        $this->register_add_element();
        $this->register_edit_element();
        $this->register_delete_element();
        $this->register_move_element();
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
    private function doc_props() {
        return [
            'document_type' => [ 'type' => 'string', 'enum' => [ 'template', 'master', 'styleGuide', 'component' ], 'description' => 'Which Mosaic document the node belongs to.' ],
            'document_id'   => [ 'type' => 'string', 'description' => 'The document id (template/component/master/styleGuide id).' ],
        ];
    }
    public function ordering_ready() { return $this->detector->avcf_mosaic_ordering_available(); }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/mosaic-check-setup', [
            'label' => 'Check Mosaic Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether Mosaic is active, its version, and whether the fractional-ordering class needed for element writes is available.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'writes_available' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_mosaic_is_available(), 'version' => $d->avcf_mosaic_version(), 'writes_available' => $d->avcf_mosaic_ordering_available(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- get-element-tree ------------------------ */

    private function register_get_element_tree() {
        $self = $this;
        wp_register_ability( 'atarim/mosaic-get-element-tree', [
            'label' => 'Get Mosaic Element Tree', 'category' => 'atarim',
            'description' => 'Return every published node of a Mosaic document as a flat list AND a hierarchical "roots" tree. Each node has id, type (element-widget id e.g. section/div/text), theme_id, document_type, document_id, parent_type ("node" for inner nodes, or the document_type for roots), parent_id, ordering (fractional-index string), status, and the decoded data blob. Caps: max_nodes (default 500, max 5000), max_field_bytes (per-node data cap; oversized blobs become {_truncated,_bytes}; 0 disables), max_depth (roots recursion, default 64).',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $self->doc_props(), [
                'max_nodes'       => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 500 ],
                'max_field_bytes' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 4096 ],
                'max_depth'       => [ 'type' => 'integer', 'minimum' => 1, 'default' => 64 ],
            ] ), 'required' => [ 'document_type', 'document_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'document_type' => [ 'type' => 'string' ], 'document_id' => [ 'type' => 'string' ], 'total' => [ 'type' => 'integer' ], 'truncated' => [ 'type' => 'boolean' ], 'nodes' => [ 'type' => 'array' ], 'roots' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $dt = (string) $input['document_type']; $did = (string) $input['document_id'];
                $max_nodes = isset( $input['max_nodes'] ) ? max( 1, min( 5000, (int) $input['max_nodes'] ) ) : 500;
                $max_field = isset( $input['max_field_bytes'] ) ? (int) $input['max_field_bytes'] : 4096;
                $max_depth = isset( $input['max_depth'] ) ? max( 1, (int) $input['max_depth'] ) : 64;
                $rows = AVCF_Mosaic_Helpers::select( 'nodes', [ 'documentType' => $dt, 'documentID' => $did ], $max_nodes + 1, 0 );
                $total = AVCF_Mosaic_Helpers::count( 'nodes', [ 'documentType' => $dt, 'documentID' => $did ] );
                $truncated = count( $rows ) > $max_nodes;
                if ( $truncated ) { $rows = array_slice( $rows, 0, $max_nodes ); }
                $flat = []; $by_parent = [];
                foreach ( $rows as $r ) {
                    $node = AVCF_Mosaic_Helpers::present_node( $r );
                    if ( $max_field > 0 ) {
                        $enc = AVCF_Mosaic_Helpers::encode_data( $node['data'] );
                        if ( strlen( $enc ) > $max_field ) { $node['data'] = [ '_truncated' => true, '_bytes' => strlen( $enc ) ]; }
                    }
                    $flat[] = $node;
                    $by_parent[ $node['parent_id'] ][] = $node;
                }
                $build = function( $parent_id, $depth ) use ( &$build, &$by_parent, $max_depth ) {
                    $out = [];
                    foreach ( ( isset( $by_parent[ $parent_id ] ) ? $by_parent[ $parent_id ] : [] ) as $n ) {
                        $entry = $n;
                        if ( $depth >= $max_depth ) { $entry['children_truncated'] = true; $entry['children'] = []; }
                        else { $entry['children'] = $build( $n['id'], $depth + 1 ); }
                        $out[] = $entry;
                    }
                    return $out;
                };
                // Roots: parent_id == document_id (root nodes carry parentType=document_type).
                $roots = $build( $did, 1 );
                return [ 'success' => true, 'document_type' => $dt, 'document_id' => $did, 'total' => $total, 'truncated' => $truncated, 'nodes' => $flat, 'roots' => $roots, 'message' => sprintf( '%d node(s) shown of %d.', count( $flat ), $total ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-components ----------------------- */

    private function register_list_components() {
        wp_register_ability( 'atarim/mosaic-list-components', [
            'label' => 'List Mosaic Components', 'category' => 'atarim',
            'description' => 'List Mosaic components (reusable element documents): { id, theme_id, name, path, parent_id, parent_type, ordering, status }. Filter by theme_id. Use get-element-tree with document_type=component to read a component\'s nodes.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'theme_id' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'components' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $filters = [];
                if ( isset( $input['theme_id'] ) && $input['theme_id'] !== '' ) { $filters['themeID'] = (string) $input['theme_id']; }
                $limit = isset( $input['limit'] ) ? (int) $input['limit'] : 100;
                $rows = AVCF_Mosaic_Helpers::select( 'components', $filters, $limit, 0 );
                $out = [];
                foreach ( $rows as $r ) { $out[] = AVCF_Mosaic_Helpers::map_component_row( $r ); }
                return [ 'success' => true, 'components' => $out, 'total' => AVCF_Mosaic_Helpers::count( 'components', $filters ), 'message' => sprintf( '%d component(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- add-element -------------------------- */

    private function register_add_element() {
        $self = $this;
        wp_register_ability( 'atarim/mosaic-add-element', [
            'label' => 'Add Mosaic Element', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Insert a new element node into a Mosaic document. type is the element-widget id (e.g. section/div/text/button). parent_id is the container node id, or the document_id for a root (on a template this auto-nests under the internal canvas wrapper so the renderer picks it up). placement = at_end (default) / at_start / before / after (before/after need anchor_id). data is the element-type-specific settings blob. NOTE: a text element is a holder — the visible string lives in a child wysiwyg-text node ({text:"..."}). Returns the new node.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $self->doc_props(), [
                'theme_id'  => [ 'type' => 'string' ],
                'type'      => [ 'type' => 'string' ],
                'parent_id' => [ 'type' => 'string', 'description' => 'Parent node id, or the document_id for a root.' ],
                'placement' => [ 'type' => 'string', 'enum' => [ 'at_end', 'at_start', 'before', 'after' ], 'default' => 'at_end' ],
                'anchor_id' => [ 'type' => 'string' ],
                'data'      => [ 'type' => 'object' ],
            ] ), 'required' => [ 'document_type', 'document_id', 'type' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'node' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->ordering_ready() ) { return [ 'success' => false, 'message' => 'Mosaic\\Common\\FractionalIndex unavailable — cannot place a node. Mosaic Pro not fully loaded.' ]; }
                $dt = (string) $input['document_type']; $did = (string) $input['document_id'];
                $parent_in = isset( $input['parent_id'] ) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : $did;
                $resolved = AVCF_Mosaic_Helpers::resolve_parent( $dt, $did, $parent_in );
                if ( $resolved === null ) { return [ 'success' => false, 'message' => 'parent_id does not resolve to a valid container in this document.' ]; }
                list( $ptype, $pid ) = $resolved;
                $placement = isset( $input['placement'] ) ? (string) $input['placement'] : 'at_end';
                $anchor = isset( $input['anchor_id'] ) ? (string) $input['anchor_id'] : '';
                $ordering = AVCF_Mosaic_Helpers::compute_placement( $dt, $did, $ptype, $pid, $placement, $anchor );
                if ( $ordering === null ) { return [ 'success' => false, 'message' => 'Could not compute placement (check anchor_id for before/after).' ]; }
                $theme_id = isset( $input['theme_id'] ) ? (string) $input['theme_id'] : (string) AVCF_Mosaic_Helpers::str( (array) AVCF_Mosaic_Helpers::load( $dt === 'component' ? 'components' : 'templates', $did ), 'themeID' );
                $node_id = AVCF_Mosaic_Helpers::insert_node( [
                    'theme_id' => $theme_id, 'document_type' => $dt, 'document_id' => $did,
                    'parent_type' => $ptype, 'parent_id' => $pid, 'ordering' => $ordering,
                    'type' => (string) $input['type'], 'data' => isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : [],
                ] );
                if ( $node_id === null ) { return [ 'success' => false, 'message' => 'Insert failed.' ]; }
                $row = AVCF_Mosaic_Helpers::load_node( $dt, $did, $node_id );
                return [ 'success' => true, 'node' => $row ? AVCF_Mosaic_Helpers::present_node( $row ) : [ 'id' => $node_id ], 'message' => sprintf( 'Inserted "%s" (id %s).', $input['type'], $node_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- edit-element ------------------------- */

    private function register_edit_element() {
        $self = $this;
        wp_register_ability( 'atarim/mosaic-edit-element', [
            'label' => 'Edit Mosaic Element', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Update a node\'s data blob. mode=replace (default) overwrites the blob verbatim; mode=merge does a shallow array-merge against the existing blob. Use move-element for re-parenting/re-ordering, not this.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $self->doc_props(), [
                'id'   => [ 'type' => 'string' ],
                'data' => [ 'type' => 'object' ],
                'mode' => [ 'type' => 'string', 'enum' => [ 'replace', 'merge' ], 'default' => 'replace' ],
            ] ), 'required' => [ 'document_type', 'document_id', 'id', 'data' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'node' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $dt = (string) $input['document_type']; $did = (string) $input['document_id']; $id = (string) $input['id'];
                $row = AVCF_Mosaic_Helpers::load_node( $dt, $did, $id );
                if ( $row === null || ( $row['status'] ?? '' ) !== 'publish' ) { return [ 'success' => false, 'message' => 'Node not found in this document.' ]; }
                $data = is_array( $input['data'] ) ? $input['data'] : [];
                $mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
                if ( $mode === 'merge' ) { $data = array_merge( AVCF_Mosaic_Helpers::decode_data( isset( $row['data'] ) ? $row['data'] : null ), $data ); }
                if ( AVCF_Mosaic_Helpers::array_depth( $data ) > AVCF_Mosaic_Helpers::BLOB_MAX_DEPTH ) { return [ 'success' => false, 'message' => 'data nesting too deep.' ]; }
                $ok = $wpdb->update( AVCF_Mosaic_Helpers::table( 'nodes' ), [ 'data' => AVCF_Mosaic_Helpers::encode_data( $data ), 'revision' => AVCF_Mosaic_Helpers::uuid() ], [ 'documentType' => $dt, 'documentID' => $did, 'ID' => $id ], [ '%s', '%s' ], [ '%s', '%s', '%s' ] );
                if ( $ok === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                $row2 = AVCF_Mosaic_Helpers::load_node( $dt, $did, $id );
                return [ 'success' => true, 'node' => $row2 ? AVCF_Mosaic_Helpers::present_node( $row2 ) : [ 'id' => $id ], 'message' => sprintf( 'Updated node %s.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- delete-element ------------------------ */

    private function register_delete_element() {
        $self = $this;
        wp_register_ability( 'atarim/mosaic-delete-element', [
            'label' => 'Delete Mosaic Element', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Soft-delete a node and all its descendants (status flips to "delete"; rows stay but are hidden from reads and Mosaic\'s renderer). Dry run unless confirm:true. Note: deleting a component does not cascade to component-instance references elsewhere — Mosaic empty-renders stale refs.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $self->doc_props(), [ 'id' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ] ), 'required' => [ 'document_type', 'document_id', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted_count' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $dt = (string) $input['document_type']; $did = (string) $input['document_id']; $id = (string) $input['id'];
                $row = AVCF_Mosaic_Helpers::load_node( $dt, $did, $id );
                if ( $row === null || ( $row['status'] ?? '' ) !== 'publish' ) { return [ 'success' => false, 'message' => 'Node not found in this document.' ]; }
                $ids = AVCF_Mosaic_Helpers::collect_subtree_ids( $dt, $did, $id );
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'deleted_count' => 0, 'message' => sprintf( 'Dry run: would soft-delete %d node(s) (the node + descendants). Re-call with confirm:true.', count( $ids ) ) ]; }
                $table = AVCF_Mosaic_Helpers::table( 'nodes' );
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
                $params = array_merge( [ $dt, $did ], $ids );
                $sql = "UPDATE `{$table}` SET `status`='delete' WHERE `documentType`=%s AND `documentID`=%s AND `ID` IN ({$placeholders})";
                $count = $wpdb->query( $wpdb->prepare( $sql, $params ) );
                return [ 'success' => true, 'deleted_count' => (int) $count, 'message' => sprintf( 'Soft-deleted %d node(s).', (int) $count ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- move-element ------------------------- */

    private function register_move_element() {
        $self = $this;
        wp_register_ability( 'atarim/mosaic-move-element', [
            'label' => 'Move Mosaic Element', 'category' => 'atarim',
            'description' => 'HIGH-RISK (raw DB write). Re-parent and/or re-order a node within the same document (its subtree follows). parent_id = a node id to move under, or the document_id to make it a root. placement = at_end / at_start / before / after (before/after need anchor_id). Cross-document moves are not supported.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $self->doc_props(), [
                'id'        => [ 'type' => 'string' ],
                'parent_id' => [ 'type' => 'string' ],
                'placement' => [ 'type' => 'string', 'enum' => [ 'at_end', 'at_start', 'before', 'after' ], 'default' => 'at_end' ],
                'anchor_id' => [ 'type' => 'string' ],
            ] ), 'required' => [ 'document_type', 'document_id', 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'node' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                if ( ! $self->ordering_ready() ) { return [ 'success' => false, 'message' => 'Mosaic\\Common\\FractionalIndex unavailable — cannot place a node.' ]; }
                $dt = (string) $input['document_type']; $did = (string) $input['document_id']; $id = (string) $input['id'];
                $row = AVCF_Mosaic_Helpers::load_node( $dt, $did, $id );
                if ( $row === null || ( $row['status'] ?? '' ) !== 'publish' ) { return [ 'success' => false, 'message' => 'Node not found in this document.' ]; }
                $parent_in = isset( $input['parent_id'] ) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : $did;
                if ( $parent_in === $id ) { return [ 'success' => false, 'message' => 'A node cannot be its own parent.' ]; }
                // Guard: new parent must not be inside the moving node's subtree.
                $subtree = AVCF_Mosaic_Helpers::collect_subtree_ids( $dt, $did, $id );
                if ( in_array( $parent_in, $subtree, true ) ) { return [ 'success' => false, 'message' => 'Cannot move a node into its own descendant.' ]; }
                $resolved = AVCF_Mosaic_Helpers::resolve_parent( $dt, $did, $parent_in );
                if ( $resolved === null ) { return [ 'success' => false, 'message' => 'parent_id does not resolve to a valid container.' ]; }
                list( $ptype, $pid ) = $resolved;
                $placement = isset( $input['placement'] ) ? (string) $input['placement'] : 'at_end';
                $anchor = isset( $input['anchor_id'] ) ? (string) $input['anchor_id'] : '';
                $ordering = AVCF_Mosaic_Helpers::compute_placement( $dt, $did, $ptype, $pid, $placement, $anchor );
                if ( $ordering === null ) { return [ 'success' => false, 'message' => 'Could not compute placement (check anchor_id).' ]; }
                $ok = $wpdb->update( AVCF_Mosaic_Helpers::table( 'nodes' ), [ 'parentType' => $ptype, 'parentID' => $pid, 'ordering' => $ordering, 'revision' => AVCF_Mosaic_Helpers::uuid() ], [ 'documentType' => $dt, 'documentID' => $did, 'ID' => $id ], [ '%s', '%s', '%s', '%s' ], [ '%s', '%s', '%s' ] );
                if ( $ok === false ) { return [ 'success' => false, 'message' => 'Move failed.' ]; }
                $row2 = AVCF_Mosaic_Helpers::load_node( $dt, $did, $id );
                return [ 'success' => true, 'node' => $row2 ? AVCF_Mosaic_Helpers::present_node( $row2 ) : [ 'id' => $id ], 'message' => sprintf( 'Moved node %s.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->site_can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
