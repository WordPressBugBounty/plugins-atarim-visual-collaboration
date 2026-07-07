<?php
/**
 * Etch — MCP abilities (core: node tree).
 *
 * Read/edit the Gutenberg-block tree Etch stores in post_content. Nodes are
 * { block, attrs?, children?, html? }, addressed by index-path. Round-trip is
 * core parse_blocks/serialize_blocks (reliable). Built from the Novamira
 * reference; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Etch extends AVCF_Abilities_Base {

    /** @var AVCF_Etch_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Etch_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_etch_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_list_elements();
        $this->register_get_content();
        $this->register_set_content();
        $this->register_add_node();
        $this->register_edit_node();
        $this->register_delete_node();
        $this->register_move_node();
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
        return [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Target post/template id (also accepts "post").' ];
    }
    private function guard( $input, $need_edit = false ) {
        $pid = isset( $input['post_id'] ) ? (int) $input['post_id'] : ( isset( $input['post'] ) ? (int) $input['post'] : 0 );
        if ( $pid <= 0 || ! get_post( $pid ) ) { return [ 'err' => [ 'success' => false, 'message' => 'A valid post_id is required.' ] ]; }
        if ( $need_edit && ! ( current_user_can( 'edit_post', $pid ) || current_user_can( 'edit_posts' ) ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'No permission to edit this post.' ] ];
        }
        return [ 'post_id' => $pid ];
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/etch-check-setup', [
            'label' => 'Check Etch Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether Etch is active and its version.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_etch_is_available(), 'version' => $d->avcf_etch_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-elements ------------------------- */

    private function register_list_elements() {
        wp_register_ability( 'atarim/etch-list-elements', [
            'label' => 'List Etch Elements', 'category' => 'atarim',
            'description' => 'List the Etch block vocabulary (etch/* blocks: block name + title) — the "block" values you use in the node tree. Falls back to a curated list if the block registry can\'t be read.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'elements' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $els = AVCF_Etch_Helpers::list_elements();
                return [ 'success' => true, 'elements' => $els, 'message' => sprintf( '%d element(s).', count( $els ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- get-content --------------------------- */

    private function register_get_content() {
        $self = $this;
        wp_register_ability( 'atarim/etch-get-content', [
            'label' => 'Get Etch Content', 'category' => 'atarim',
            'description' => 'Return a post/template\'s Etch element tree, parsed from its block markup. Flat addressed rows by default: address (index-path "0/1/2"), block (Gutenberg block name), parent, child_count, has_html. Pass include_attrs:true for each node\'s attrs and inner html. lossy:true means the content holds freeform HTML the tree can\'t fully represent — set-content will refuse to overwrite it without force. Node count capped (default 400).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'       => $self->post_id_prop(),
                'include_attrs' => [ 'type' => 'boolean', 'default' => false ],
                'cap'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 400 ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'nodes' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'truncated' => [ 'type' => 'boolean' ], 'lossy' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $post = get_post( $g['post_id'] );
                $tree = AVCF_Etch_Helpers::parse_tree( $post->post_content );
                $cap = isset( $input['cap'] ) ? (int) $input['cap'] : 400;
                $rows = AVCF_Etch_Helpers::flatten( $tree, ! empty( $input['include_attrs'] ), $cap );
                $total = AVCF_Etch_Helpers::tree_count( $tree );
                return [ 'success' => true, 'nodes' => $rows, 'total' => $total, 'truncated' => count( $rows ) < $total, 'lossy' => AVCF_Etch_Helpers::is_lossy( $post->post_content ), 'message' => sprintf( '%d node(s) shown of %d.', count( $rows ), $total ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/etch-set-content', [
            'label' => 'Set Etch Content', 'category' => 'atarim',
            'description' => 'Replace a post/template\'s ENTIRE Etch tree. tree is a list of { block, attrs?, children?, html? } nodes (same shape get-content returns). Full overwrite — read first if editing. If the current content is lossy (holds freeform HTML the tree can\'t represent), the write is refused unless force:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'tree'    => [ 'type' => 'array' ],
                'force'   => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'tree' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'bytes' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                if ( ! isset( $input['tree'] ) || ! is_array( $input['tree'] ) ) { return [ 'success' => false, 'message' => 'tree must be an array of nodes.' ]; }
                $post = get_post( $g['post_id'] );
                if ( empty( $input['force'] ) && AVCF_Etch_Helpers::is_lossy( $post->post_content ) ) {
                    return [ 'success' => false, 'message' => 'Refusing to overwrite: current content holds freeform HTML the tree can\'t represent (lossy). Re-call with force:true to overwrite anyway.' ];
                }
                $tree = AVCF_Etch_Helpers::sanitize_tree( $input['tree'] );
                $markup = AVCF_Etch_Helpers::serialize_tree( $tree );
                $res = wp_update_post( [ 'ID' => $g['post_id'], 'post_content' => $markup ], true );
                if ( is_wp_error( $res ) ) { return [ 'success' => false, 'message' => $res->get_error_message() ]; }
                return [ 'success' => true, 'bytes' => strlen( $markup ), 'message' => 'Etch content replaced.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- add-node ---------------------------- */

    private function register_add_node() {
        $self = $this;
        wp_register_ability( 'atarim/etch-add-node', [
            'label' => 'Add Etch Node', 'category' => 'atarim',
            'description' => 'Insert a node. block is the Gutenberg block name (see list-elements). parent_address is the index-path of the container ("" / omit = top level). position is the 0-based index among the parent\'s children (omit = append). attrs is the block attributes; html is inner HTML for leaf/freeform nodes (use block "" for pure freeform HTML). Re-read addresses afterward — they shift.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'        => $self->post_id_prop(),
                'block'          => [ 'type' => 'string' ],
                'parent_address' => [ 'type' => 'string' ],
                'position'       => [ 'type' => 'integer', 'minimum' => 0 ],
                'attrs'          => [ 'type' => 'object' ],
                'html'           => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'block' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Etch_Helpers::read_tree( $g['post_id'] );
                $parent = isset( $input['parent_address'] ) ? (string) $input['parent_address'] : '';
                if ( $parent !== '' && AVCF_Etch_Helpers::node_at( $tree, $parent ) === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_address "%s" does not resolve.', $parent ) ]; }
                $node = [ 'block' => (string) $input['block'] ];
                if ( isset( $input['attrs'] ) && is_array( $input['attrs'] ) ) { $node['attrs'] = $input['attrs']; }
                if ( isset( $input['html'] ) ) { $node['html'] = (string) $input['html']; }
                $pos = isset( $input['position'] ) ? (int) $input['position'] : null;
                $new = AVCF_Etch_Helpers::insert_at( $tree, $parent, $pos, $node );
                if ( $new === null ) { return [ 'success' => false, 'message' => 'Insert failed (bad parent_address).' ]; }
                if ( ! AVCF_Etch_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Inserted "%s". Re-read get-content for current addresses.', $input['block'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- edit-node --------------------------- */

    private function register_edit_node() {
        $self = $this;
        wp_register_ability( 'atarim/etch-edit-node', [
            'label' => 'Edit Etch Node', 'category' => 'atarim',
            'description' => 'Update a node by address. attrs is shallow-merged into the node\'s existing attrs; html (if provided) replaces inner HTML; block (if provided) changes the block type. Read it first via get-content (include_attrs:true).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'address' => [ 'type' => 'string' ],
                'attrs'   => [ 'type' => 'object' ],
                'html'    => [ 'type' => 'string' ],
                'block'   => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                if ( ! isset( $input['attrs'] ) && ! isset( $input['html'] ) && ! isset( $input['block'] ) ) { return [ 'success' => false, 'message' => 'Provide attrs, html, and/or block.' ]; }
                $address = (string) $input['address'];
                $tree = AVCF_Etch_Helpers::read_tree( $g['post_id'] );
                $node = AVCF_Etch_Helpers::node_at( $tree, $address );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No node at address "%s".', $address ) ]; }
                if ( isset( $input['block'] ) ) { $node['block'] = (string) $input['block']; }
                if ( isset( $input['attrs'] ) && is_array( $input['attrs'] ) ) {
                    $cur = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
                    $node['attrs'] = array_merge( $cur, $input['attrs'] );
                }
                if ( isset( $input['html'] ) ) { $node['html'] = (string) $input['html']; }
                $new = AVCF_Etch_Helpers::replace_at( $tree, $address, $node );
                if ( $new === null || ! AVCF_Etch_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Updated node at "%s".', $address ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- delete-node -------------------------- */

    private function register_delete_node() {
        $self = $this;
        wp_register_ability( 'atarim/etch-delete-node', [
            'label' => 'Delete Etch Node', 'category' => 'atarim',
            'description' => 'Remove a node (and its subtree) by address. Dry run unless confirm:true. Addresses shift after a delete — re-read get-content.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop(), 'address' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'removed' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $address = (string) $input['address'];
                $tree = AVCF_Etch_Helpers::read_tree( $g['post_id'] );
                if ( AVCF_Etch_Helpers::node_at( $tree, $address ) === null ) { return [ 'success' => false, 'message' => sprintf( 'No node at address "%s".', $address ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'removed' => false, 'message' => sprintf( 'Dry run: would remove node at "%s" and its subtree. Re-call with confirm:true.', $address ) ]; }
                $new = AVCF_Etch_Helpers::remove_at( $tree, $address );
                if ( $new === null || ! AVCF_Etch_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to remove/save.' ]; }
                return [ 'success' => true, 'removed' => true, 'message' => sprintf( 'Removed node at "%s".', $address ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- move-node --------------------------- */

    private function register_move_node() {
        $self = $this;
        wp_register_ability( 'atarim/etch-move-node', [
            'label' => 'Move Etch Node', 'category' => 'atarim',
            'description' => 'Relocate a node (with its subtree) from one address to a new parent_address + position. Removed then re-inserted; positions are best-effort (indices shift on removal) — re-read get-content afterward. Cannot move a node into its own subtree.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'        => $self->post_id_prop(),
                'from_address'   => [ 'type' => 'string' ],
                'parent_address' => [ 'type' => 'string' ],
                'position'       => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'from_address' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $from = (string) $input['from_address'];
                $to   = isset( $input['parent_address'] ) ? (string) $input['parent_address'] : '';
                if ( $to === $from || strpos( $to . '/', $from . '/' ) === 0 ) { return [ 'success' => false, 'message' => 'Cannot move a node into itself or its own descendant.' ]; }
                $tree = AVCF_Etch_Helpers::read_tree( $g['post_id'] );
                $node = AVCF_Etch_Helpers::node_at( $tree, $from );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No node at from_address "%s".', $from ) ]; }
                if ( $to !== '' && AVCF_Etch_Helpers::node_at( $tree, $to ) === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_address "%s" does not resolve.', $to ) ]; }
                $removed = AVCF_Etch_Helpers::remove_at( $tree, $from );
                if ( $removed === null ) { return [ 'success' => false, 'message' => 'Move failed during removal.' ]; }
                $pos = isset( $input['position'] ) ? (int) $input['position'] : null;
                $new = AVCF_Etch_Helpers::insert_at( $removed, $to, $pos, $node );
                if ( $new === null ) { return [ 'success' => false, 'message' => 'Move failed during insert (destination shifted). Re-read get-content and retry.' ]; }
                if ( ! AVCF_Etch_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Node moved. Re-read get-content for current addresses.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
