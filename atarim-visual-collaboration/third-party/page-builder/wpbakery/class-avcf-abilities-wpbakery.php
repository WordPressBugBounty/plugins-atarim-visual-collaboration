<?php
/**
 * WPBakery — MCP abilities (core: shortcode element tree).
 *
 * Read/edit the [vc_*] shortcode tree WPBakery stores in post_content, plus
 * element discovery (WPBMap). Content round-trip is WP-core shortcode parsing +
 * a serializer; element identity is by tree address ("0/1/0"). Attribute
 * handling is plain-string (WPBakery's special param encodings aren't decoded).
 * Built from the Novamira reference; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_WPBakery extends AVCF_Abilities_Base {

    /** @var AVCF_WPBakery_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WPBakery_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wpbakery_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_get_content();
        $this->register_list_elements();
        $this->register_get_element_schema();
        $this->register_inspect_page();
        $this->register_add_element();
        $this->register_edit_element();
        $this->register_edit_elements();
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
        $pid = 0;
        if ( isset( $input['post_id'] ) ) { $pid = (int) $input['post_id']; }
        elseif ( isset( $input['post'] ) ) { $pid = (int) $input['post']; }
        if ( $pid <= 0 || ! get_post( $pid ) ) { return [ 'err' => [ 'success' => false, 'message' => 'A valid post_id is required.' ] ]; }
        if ( $need_edit && ! ( current_user_can( 'edit_post', $pid ) || current_user_can( 'edit_posts' ) ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'No permission to edit this post.' ] ];
        }
        return [ 'post_id' => $pid ];
    }
    /** Build a node from input (tag/atts/content). */
    public function make_node( $input ) {
        $node = [ 'tag' => (string) $input['tag'], 'atts' => isset( $input['atts'] ) && is_array( $input['atts'] ) ? $input['atts'] : [], 'children' => [] ];
        if ( isset( $input['content'] ) && $input['content'] !== '' ) { $node['_content'] = (string) $input['content']; }
        return $node;
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/wpbakery-check-setup', [
            'label' => 'Check WPBakery Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether WPBakery is active, its version, and whether the WPBMap element registry is queryable (needed for list-elements / get-element-schema and accurate parsing).',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'registry_available' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                return [ 'success' => true, 'active' => $d->avcf_wpbakery_is_available(), 'registry_available' => $d->avcf_wpbakery_registry_available(), 'version' => $d->avcf_wpbakery_version(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- get-content --------------------------- */

    private function register_get_content() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-get-content', [
            'label' => 'Get WPBakery Content', 'category' => 'atarim',
            'description' => 'Parse a post\'s WPBakery shortcodes into a flat, addressed element list. Each row: address (slash-path "0/1/0"), tag (e.g. vc_row, vc_column, vc_column_text), parent address, child_count, has_content (leaf HTML present). Pass include_atts:true for each element\'s attributes (and inner content). Node count capped (default 400).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'      => $self->post_id_prop(),
                'include_atts' => [ 'type' => 'boolean', 'default' => false ],
                'cap'          => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 400 ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'elements' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'truncated' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                $cap  = isset( $input['cap'] ) ? (int) $input['cap'] : 400;
                $rows = AVCF_WPBakery_Helpers::flatten( $tree, ! empty( $input['include_atts'] ), $cap );
                $total = AVCF_WPBakery_Helpers::tree_count( $tree );
                $msg = $total === 0 ? 'No WPBakery shortcodes found (empty, not a WPBakery page, or WPBMap registry unavailable).' : sprintf( '%d element(s) shown of %d.', count( $rows ), $total );
                return [ 'success' => true, 'elements' => $rows, 'total' => $total, 'truncated' => count( $rows ) < $total, 'message' => $msg ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-elements ------------------------- */

    private function register_list_elements() {
        wp_register_ability( 'atarim/wpbakery-list-elements', [
            'label' => 'List WPBakery Elements', 'category' => 'atarim',
            'description' => 'List available WPBakery element types from WPBMap (tag, name, category). The tag is what you pass to add-element. Filter by a name/tag search substring.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'elements' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $list = AVCF_WPBakery_Helpers::list_elements();
                $search = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
                if ( $search !== '' ) {
                    $list = array_values( array_filter( $list, function( $e ) use ( $search ) {
                        return strpos( strtolower( $e['tag'] ), $search ) !== false || strpos( strtolower( (string) $e['name'] ), $search ) !== false;
                    } ) );
                }
                return [ 'success' => true, 'elements' => $list, 'message' => $list ? sprintf( '%d element(s).', count( $list ) ) : 'WPBMap registry not available on this build.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-element-schema ----------------------- */

    private function register_get_element_schema() {
        wp_register_ability( 'atarim/wpbakery-get-element-schema', [
            'label' => 'Get WPBakery Element Schema', 'category' => 'atarim',
            'description' => 'Return the settable params for one WPBakery element (from WPBMap): each param\'s param_name, type, heading, description, and value options. Use param_name keys as atts when adding/editing the element.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'tag' => [ 'type' => 'string' ] ], 'required' => [ 'tag' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'schema' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $schema = AVCF_WPBakery_Helpers::element_schema( (string) $input['tag'] );
                if ( $schema === null ) { return [ 'success' => false, 'message' => sprintf( 'Schema for "%s" not available (unknown element or WPBMap registry unavailable).', $input['tag'] ) ]; }
                return [ 'success' => true, 'schema' => $schema, 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- inspect-page -------------------------- */

    private function register_inspect_page() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-inspect-page', [
            'label' => 'Inspect WPBakery Page', 'category' => 'atarim',
            'description' => 'High-level overview of a post\'s WPBakery content: total element count, a per-tag count breakdown, and whether the post is flagged as a WPBakery page.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'post_id' => $self->post_id_prop() ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'total' => [ 'type' => 'integer' ], 'tag_counts' => [ 'type' => 'object' ], 'is_wpbakery_page' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                $counts = AVCF_WPBakery_Helpers::tag_counts( $tree );
                arsort( $counts );
                return [ 'success' => true, 'total' => AVCF_WPBakery_Helpers::tree_count( $tree ), 'tag_counts' => $counts, 'is_wpbakery_page' => AVCF_WPBakery_Helpers::is_wpbakery_post( $g['post_id'] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- add-element -------------------------- */

    private function register_add_element() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-add-element', [
            'label' => 'Add WPBakery Element', 'category' => 'atarim',
            'description' => 'Insert a WPBakery element. tag is the element type (e.g. "vc_column_text"). parent_address is the slash-path of the container to insert into ("" / omit = top level). position is the 0-based index among the parent\'s children (clamped; omit to append). atts is the element\'s attributes; content is inner HTML for text-style elements. Re-read addresses afterward — they shift.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'        => $self->post_id_prop(),
                'tag'            => [ 'type' => 'string' ],
                'parent_address' => [ 'type' => 'string' ],
                'position'       => [ 'type' => 'integer', 'minimum' => 0 ],
                'atts'           => [ 'type' => 'object' ],
                'content'        => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'tag' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $parent = isset( $input['parent_address'] ) ? (string) $input['parent_address'] : '';
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                if ( $parent !== '' && AVCF_WPBakery_Helpers::tree_get( $tree, $parent ) === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_address "%s" does not resolve.', $parent ) ]; }
                $node = $self->make_node( $input );
                $pos = isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX;
                $new = AVCF_WPBakery_Helpers::tree_insert( $tree, $parent, $pos, $node );
                if ( $new === null ) { return [ 'success' => false, 'message' => 'Insert failed.' ]; }
                if ( ! AVCF_WPBakery_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Inserted "%s". Re-read get-content for current addresses.', $input['tag'] ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- edit-element ------------------------- */

    private function register_edit_element() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-edit-element', [
            'label' => 'Edit WPBakery Element', 'category' => 'atarim',
            'description' => 'Update one element by address. atts is shallow-merged into existing attributes (keys you send overwrite). content (if provided) replaces the element\'s inner HTML. Read it first via get-content (include_atts:true).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'address' => [ 'type' => 'string' ],
                'atts'    => [ 'type' => 'object' ],
                'content' => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                if ( ! isset( $input['atts'] ) && ! isset( $input['content'] ) ) { return [ 'success' => false, 'message' => 'Provide atts and/or content.' ]; }
                $address = (string) $input['address'];
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                $node = AVCF_WPBakery_Helpers::tree_get( $tree, $address );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No element at address "%s".', $address ) ]; }
                if ( isset( $input['atts'] ) && is_array( $input['atts'] ) ) {
                    $cur = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : [];
                    $node['atts'] = array_merge( $cur, $input['atts'] );
                }
                if ( isset( $input['content'] ) ) { $node['_content'] = (string) $input['content']; }
                $new = AVCF_WPBakery_Helpers::tree_replace( $tree, $address, $node );
                if ( $new === null || ! AVCF_WPBakery_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Updated element at "%s".', $address ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- edit-elements ------------------------- */

    private function register_edit_elements() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-edit-elements', [
            'label' => 'Edit WPBakery Elements (batch)', 'category' => 'atarim',
            'description' => 'Apply several element edits in one call. edits is [{address, atts?, content?}, ...], each applied like edit-element. Edits are applied in array order against a single read; since addresses are stable within one read this is safe for attribute/content changes (not structural). Per-item results returned.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'edits'   => [ 'type' => 'array', 'maxItems' => 200, 'items' => [ 'type' => 'object', 'properties' => [ 'address' => [ 'type' => 'string' ], 'atts' => [ 'type' => 'object' ], 'content' => [ 'type' => 'string' ] ], 'required' => [ 'address' ] ] ],
            ], 'required' => [ 'post_id', 'edits' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'results' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $edits = isset( $input['edits'] ) && is_array( $input['edits'] ) ? $input['edits'] : [];
                if ( empty( $edits ) ) { return [ 'success' => false, 'message' => 'edits must be a non-empty array.' ]; }
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                $results = []; $ok = 0;
                foreach ( $edits as $e ) {
                    $addr = isset( $e['address'] ) ? (string) $e['address'] : '';
                    $node = AVCF_WPBakery_Helpers::tree_get( $tree, $addr );
                    if ( $node === null ) { $results[] = [ 'address' => $addr, 'success' => false, 'message' => 'not found' ]; continue; }
                    if ( isset( $e['atts'] ) && is_array( $e['atts'] ) ) {
                        $cur = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : [];
                        $node['atts'] = array_merge( $cur, $e['atts'] );
                    }
                    if ( isset( $e['content'] ) ) { $node['_content'] = (string) $e['content']; }
                    $tree = AVCF_WPBakery_Helpers::tree_replace( $tree, $addr, $node );
                    if ( $tree === null ) { return [ 'success' => false, 'message' => sprintf( 'Internal error applying edit at "%s".', $addr ) ]; }
                    $ok++; $results[] = [ 'address' => $addr, 'success' => true ];
                }
                if ( ! AVCF_WPBakery_Helpers::write_tree( $g['post_id'], $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'results' => $results, 'message' => sprintf( '%d of %d edit(s) applied.', $ok, count( $edits ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- delete-element ------------------------ */

    private function register_delete_element() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-delete-element', [
            'label' => 'Delete WPBakery Element', 'category' => 'atarim',
            'description' => 'Remove an element (and its subtree) by address. Dry run unless confirm:true. Addresses shift after a delete — re-read get-content.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'address' => [ 'type' => 'string' ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'removed' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $address = (string) $input['address'];
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                if ( AVCF_WPBakery_Helpers::tree_get( $tree, $address ) === null ) { return [ 'success' => false, 'message' => sprintf( 'No element at address "%s".', $address ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'removed' => false, 'message' => sprintf( 'Dry run: would remove element at "%s" and its subtree. Re-call with confirm:true.', $address ) ]; }
                $new = AVCF_WPBakery_Helpers::tree_remove( $tree, $address );
                if ( $new === null || ! AVCF_WPBakery_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to remove/save.' ]; }
                return [ 'success' => true, 'removed' => true, 'message' => sprintf( 'Removed element at "%s".', $address ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- move-element ------------------------- */

    private function register_move_element() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-move-element', [
            'label' => 'Move WPBakery Element', 'category' => 'atarim',
            'description' => 'Relocate an element (with its subtree) from one address to a new parent_address + position. Removed then re-inserted; positions are best-effort because indices shift on removal — re-read get-content afterward. Cannot move an element into its own subtree.',
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
                if ( $to === $from || strpos( $to . '/', $from . '/' ) === 0 ) { return [ 'success' => false, 'message' => 'Cannot move an element into itself or its own descendant.' ]; }
                $tree = AVCF_WPBakery_Helpers::read_tree( $g['post_id'] );
                $node = AVCF_WPBakery_Helpers::tree_get( $tree, $from );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No element at from_address "%s".', $from ) ]; }
                if ( $to !== '' && AVCF_WPBakery_Helpers::tree_get( $tree, $to ) === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_address "%s" does not resolve.', $to ) ]; }
                $removed = AVCF_WPBakery_Helpers::tree_remove( $tree, $from );
                if ( $removed === null ) { return [ 'success' => false, 'message' => 'Move failed during removal.' ]; }
                $pos = isset( $input['position'] ) ? (int) $input['position'] : PHP_INT_MAX;
                $new = AVCF_WPBakery_Helpers::tree_insert( $removed, $to, $pos, $node );
                if ( $new === null ) { return [ 'success' => false, 'message' => 'Move failed during insert (destination shifted). Re-read get-content and retry.' ]; }
                if ( ! AVCF_WPBakery_Helpers::write_tree( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Element moved. Re-read get-content for current addresses.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/wpbakery-set-content', [
            'label' => 'Set WPBakery Content', 'category' => 'atarim',
            'description' => 'Replace a post\'s ENTIRE WPBakery content. Provide either tree (nested array of { tag, atts, children[], content? } nodes) OR shortcode (a raw WPBakery shortcode string). Full overwrite — for targeted edits use add/edit/delete/move-element.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'   => $self->post_id_prop(),
                'tree'      => [ 'type' => 'array' ],
                'shortcode' => [ 'type' => 'string' ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                if ( isset( $input['shortcode'] ) && is_string( $input['shortcode'] ) ) {
                    $res = wp_update_post( [ 'ID' => $g['post_id'], 'post_content' => $input['shortcode'] ], true );
                    if ( is_wp_error( $res ) ) { return [ 'success' => false, 'message' => $res->get_error_message() ]; }
                    update_post_meta( $g['post_id'], AVCF_WPBakery_Helpers::META_STATUS, 'true' );
                    return [ 'success' => true, 'message' => 'WPBakery content replaced (from raw shortcode).' ];
                }
                if ( ! isset( $input['tree'] ) || ! is_array( $input['tree'] ) ) { return [ 'success' => false, 'message' => 'Provide tree (array of nodes) or shortcode (string).' ]; }
                $tree = $self->sanitize_tree( $input['tree'] );
                if ( ! AVCF_WPBakery_Helpers::write_tree( $g['post_id'], $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'WPBakery content replaced.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /** Coerce an incoming tree to clean { tag, atts, children, _content } nodes. */
    public function sanitize_tree( $nodes ) {
        $out = [];
        foreach ( (array) $nodes as $n ) {
            if ( ! is_array( $n ) || ! isset( $n['tag'] ) ) { continue; }
            $node = [
                'tag'      => (string) $n['tag'],
                'atts'     => isset( $n['atts'] ) && is_array( $n['atts'] ) ? $n['atts'] : [],
                'children' => isset( $n['children'] ) && is_array( $n['children'] ) ? $this->sanitize_tree( $n['children'] ) : [],
            ];
            if ( isset( $n['content'] ) && $n['content'] !== '' ) { $node['_content'] = (string) $n['content']; }
            elseif ( isset( $n['_content'] ) && $n['_content'] !== '' ) { $node['_content'] = (string) $n['_content']; }
            $out[] = $node;
        }
        return $out;
    }
}
