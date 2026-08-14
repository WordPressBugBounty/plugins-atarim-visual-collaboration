<?php
/**
 * Divi 5 — MCP abilities (core: module tree).
 *
 * Divi 5 ONLY (block-based model). On a Divi 4 / non-D5 site every ability here
 * refuses with a clear message. Content round-trip is core parse_blocks/
 * serialize_blocks (reliable); module registry list/schema depend on Divi 5
 * internals and degrade gracefully. Built from the Novamira reference; not
 * runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Divi extends AVCF_Abilities_Base {

    /** @var AVCF_Divi_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Divi_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_divi_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_get_content();
        $this->register_list_modules();
        $this->register_get_module_schema();
        $this->register_get_style_schema();
        $this->register_add_module();
        $this->register_edit_module();
        $this->register_delete_module();
        $this->register_move_module();
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
    /** Resolve post id from "post_id" or "post"; verify D5 + editability. */
    private function guard( $input, $need_edit = false ) {
        if ( ! $this->detector->avcf_divi_d5_enabled() ) {
            return [ 'err' => [ 'success' => false, 'message' => 'Divi 5 (block-based builder) is not enabled on this site. These abilities support Divi 5 only, not Divi 4 shortcodes.' ] ];
        }
        $pid = 0;
        if ( isset( $input['post_id'] ) ) { $pid = (int) $input['post_id']; }
        elseif ( isset( $input['post'] ) ) { $pid = (int) $input['post']; }
        if ( $pid <= 0 || ! get_post( $pid ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'A valid post_id is required.' ] ];
        }
        if ( $need_edit && ! ( current_user_can( 'edit_post', $pid ) || current_user_can( 'edit_posts' ) ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'No permission to edit this post.' ] ];
        }
        return [ 'post_id' => $pid ];
    }
    private function post_id_prop() {
        return [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Target post/page id (also accepts "post").' ];
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $d = $this->detector;
        wp_register_ability( 'atarim/divi-check-setup', [
            'label' => 'Check Divi Setup', 'category' => 'atarim',
            'description' => 'Call first. Reports whether Divi is active, the Divi version, whether the Divi 5 block builder is enabled (d5_enabled — REQUIRED; these abilities do not support Divi 4 shortcodes), and whether the module registry is queryable.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'd5_enabled' => [ 'type' => 'boolean' ], 'registry_available' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'd5_enabled', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $d ) {
                $d5 = $d->avcf_divi_d5_enabled();
                return [
                    'success' => true,
                    'active' => $d->avcf_divi_is_available(),
                    'd5_enabled' => $d5,
                    'registry_available' => $d->avcf_divi_registry_available(),
                    'version' => $d->avcf_divi_version(),
                    'message' => $d5 ? 'Divi 5 ready.' : 'Divi present but the Divi 5 block builder is not enabled — these abilities require Divi 5.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- get-content --------------------------- */

    private function register_get_content() {
        $self = $this;
        wp_register_ability( 'atarim/divi-get-content', [
            'label' => 'Get Divi Content', 'category' => 'atarim',
            'description' => 'Parse a post\'s Divi 5 content into a flat, addressed module list. Each row: address (slash-path like "0/1/0" locating it in the tree), name (module type), parent address, child_count, and passthrough (true for preserved non-Divi blocks — they occupy an address but are not editable). Pass include_attrs:true to include each module\'s attributes. Node count capped (default 400).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'       => $self->post_id_prop(),
                'include_attrs' => [ 'type' => 'boolean', 'default' => false ],
                'cap'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 400 ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'modules' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'truncated' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $tree = AVCF_Divi_Helpers::read_tree( $g['post_id'] );
                $cap  = isset( $input['cap'] ) ? (int) $input['cap'] : 400;
                $rows = AVCF_Divi_Helpers::flatten( $tree, ! empty( $input['include_attrs'] ), $cap );
                $total = AVCF_Divi_Helpers::tree_count( $tree );
                return [ 'success' => true, 'modules' => $rows, 'total' => $total, 'truncated' => count( $rows ) < $total, 'message' => sprintf( '%d module(s) shown of %d.', count( $rows ), $total ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-modules -------------------------- */

    private function register_list_modules() {
        $self = $this;
        wp_register_ability( 'atarim/divi-list-modules', [
            'label' => 'List Divi Modules', 'category' => 'atarim',
            'description' => 'List available Divi 5 module types (name, category). Structural modules nest divi/section > divi/row > divi/column > leaf modules. Filter by a name/title search substring. If the live registry can\'t be enumerated, a curated common-module list is returned (flagged via source).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'search'   => [ 'type' => 'string' ],
                'category' => [ 'type' => 'string', 'description' => 'structure | module | child-module | fullwidth-module' ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'modules' => [ 'type' => 'array' ], 'source' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->detector_d5() ) { return [ 'success' => false, 'message' => 'Divi 5 is not enabled.' ]; }
                $list = []; $source = 'registry';
                $cls = '\ET\Builder\Packages\ModuleLibrary\ModuleRegistration';
                if ( class_exists( $cls ) && method_exists( $cls, 'get_registered_modules' ) ) {
                    try {
                        $reg = call_user_func( [ $cls, 'get_registered_modules' ] );
                        if ( is_array( $reg ) ) {
                            foreach ( $reg as $name => $meta ) {
                                $nm = is_string( $name ) ? $name : ( is_array( $meta ) && isset( $meta['name'] ) ? $meta['name'] : '' );
                                if ( $nm === '' ) { continue; }
                                $list[] = [ 'name' => $nm, 'category' => is_array( $meta ) && isset( $meta['category'] ) ? $meta['category'] : '' ];
                            }
                        }
                    } catch ( \Throwable $e ) { $list = []; }
                }
                if ( empty( $list ) ) { $list = AVCF_Divi_Helpers::fallback_modules(); $source = 'fallback'; }
                $search = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
                $cat    = isset( $input['category'] ) ? (string) $input['category'] : '';
                $out = [];
                foreach ( $list as $m ) {
                    if ( $cat !== '' && ( $m['category'] ?? '' ) !== $cat ) { continue; }
                    if ( $search !== '' && strpos( strtolower( $m['name'] ), $search ) === false ) { continue; }
                    $out[] = $m;
                }
                return [ 'success' => true, 'modules' => $out, 'source' => $source, 'message' => sprintf( '%d module(s) [%s].', count( $out ), $source ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /** exposed for closures */
    public function detector_d5() { return $this->detector->avcf_divi_d5_enabled(); }

    /* ------------------------- get-module-schema ----------------------- */

    private function register_get_module_schema() {
        $self = $this;
        wp_register_ability( 'atarim/divi-get-module-schema', [
            'label' => 'Get Divi Module Schema', 'category' => 'atarim',
            'description' => 'Return the metadata/settable fields for one Divi 5 module (accepts name with or without the "divi/" prefix), plus its allowed children. Depends on the live Divi 5 registry; if unavailable, reports that the schema can\'t be introspected on this build.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'name' => [ 'type' => 'string' ], 'metadata' => [ 'type' => 'object' ], 'allowed_children' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->detector_d5() ) { return [ 'success' => false, 'message' => 'Divi 5 is not enabled.' ]; }
                $name = AVCF_Divi_Helpers::normalize_module_name( $input['name'] );
                $meta = AVCF_Divi_Helpers::module_metadata( $name );
                if ( $meta === null ) {
                    return [ 'success' => false, 'name' => $name, 'message' => 'Module schema not available (unknown module, or the Divi 5 module registry is not queryable on this build).' ];
                }
                $structure = AVCF_Divi_Helpers::structure_children();
                $allowed = isset( $structure[ $name ] ) ? $structure[ $name ] : ( isset( $meta['childrenName'] ) && is_array( $meta['childrenName'] ) ? $meta['childrenName'] : [] );
                return [ 'success' => true, 'name' => $name, 'metadata' => $meta, 'allowed_children' => array_values( $allowed ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-style-schema ----------------------- */

    private function register_get_style_schema() {
        $self = $this;
        wp_register_ability( 'atarim/divi-get-style-schema', [
            'label' => 'Get Divi Style Schema', 'category' => 'atarim',
            'description' => 'List the universal style groups Divi 5 modules expose under module.decoration.* (background, spacing, border, fonts, sizing, position, transform, filters, animation, box-shadow). Reference for building module attrs.',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'style_groups' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->detector_d5() ) { return [ 'success' => false, 'message' => 'Divi 5 is not enabled.' ]; }
                $groups = [ 'background', 'spacing', 'border', 'font', 'sizing', 'position', 'transform', 'filters', 'animation', 'boxShadow', 'overflow', 'zIndex' ];
                $out = array_map( function( $g ) { return [ 'group' => $g, 'path' => 'module.decoration.' . $g ]; }, $groups );
                return [ 'success' => true, 'style_groups' => $out, 'message' => sprintf( '%d style group(s).', count( $out ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- add-module --------------------------- */

    private function register_add_module() {
        $self = $this;
        wp_register_ability( 'atarim/divi-add-module', [
            'label' => 'Add Divi Module', 'category' => 'atarim',
            'description' => 'Insert a Divi 5 module. name is the module type (e.g. "divi/text"). parent_address is the slash-path of the container to insert into ("" / omit = top level, where only divi/section is valid). position is the 0-based index among the parent\'s children (clamped; omit to append). attrs is the module\'s attribute object. Structural rules are enforced (section>row>column>modules); returns errors if violated. Re-read addresses afterward, as they shift.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'        => $self->post_id_prop(),
                'name'           => [ 'type' => 'string' ],
                'parent_address' => [ 'type' => 'string', 'description' => 'Container address. Omit/"" for top level.' ],
                'position'       => [ 'type' => 'integer', 'minimum' => 0 ],
                'attrs'          => [ 'type' => 'object' ],
            ], 'required' => [ 'post_id', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'address' => [ 'type' => 'string' ], 'warnings' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $name = AVCF_Divi_Helpers::normalize_module_name( $input['name'] );
                $parent = isset( $input['parent_address'] ) ? (string) $input['parent_address'] : '';
                $attrs  = isset( $input['attrs'] ) && is_array( $input['attrs'] ) ? $input['attrs'] : [];
                $block  = AVCF_Divi_Helpers::raw_make_block( $name, $attrs, '' );
                $blocks = AVCF_Divi_Helpers::read_raw( $g['post_id'] );

                // Determine parent name for structural validation.
                $parent_name = null;
                if ( $parent !== '' ) {
                    $pblock = AVCF_Divi_Helpers::raw_node_at( $blocks, $parent );
                    if ( $pblock === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_address "%s" does not resolve.', $parent ) ]; }
                    $parent_name = isset( $pblock['blockName'] ) ? (string) $pblock['blockName'] : '';
                }
                // Validate this single insertion structurally.
                $node  = AVCF_Divi_Helpers::raw_to_probe( $block );
                $probe = $parent_name === null ? [ $node ] : [ [ 'name' => $parent_name, 'attrs' => [], 'children' => [ $node ] ] ];
                $v = AVCF_Divi_Helpers::validate_tree( $probe );
                if ( ! empty( $v['errors'] ) ) { return [ 'success' => false, 'message' => implode( ' ', $v['errors'] ) ]; }

                $pos = isset( $input['position'] ) ? (int) $input['position'] : null;
                $new = AVCF_Divi_Helpers::raw_insert_at( $blocks, $parent, $pos, [ $block ] );
                if ( $new === null ) { return [ 'success' => false, 'message' => 'Insert failed: bad parent_address, or the parent has no children yet and its wrapper markup could not be opened safely.' ]; }
                if ( ! AVCF_Divi_Helpers::write_raw( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'warnings' => $v['warnings'], 'message' => sprintf( 'Inserted "%s". Re-read get-content for current addresses.', $name ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- edit-module -------------------------- */

    private function register_edit_module() {
        $self = $this;
        wp_register_ability( 'atarim/divi-edit-module', [
            'label' => 'Edit Divi Module', 'category' => 'atarim',
            'description' => 'Update a module\'s attributes by address. attrs is deep-merged into the module\'s existing attrs (nested objects merged; list values replaced). Read the module first via get-content (include_attrs:true).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'address' => [ 'type' => 'string' ],
                'attrs'   => [ 'type' => 'object' ],
            ], 'required' => [ 'post_id', 'address', 'attrs' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $address = (string) $input['address'];
                $blocks = AVCF_Divi_Helpers::read_raw( $g['post_id'] );
                $node = AVCF_Divi_Helpers::raw_node_at( $blocks, $address );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No module at address "%s".', $address ) ]; }
                $bname = isset( $node['blockName'] ) && is_string( $node['blockName'] ) ? $node['blockName'] : '';
                if ( strpos( $bname, 'divi/' ) !== 0 ) { return [ 'success' => false, 'message' => 'That address is a non-Divi passthrough block and cannot be edited here.' ]; }
                $node['attrs'] = AVCF_Divi_Helpers::deep_merge( isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [], is_array( $input['attrs'] ) ? $input['attrs'] : [] );
                $new = AVCF_Divi_Helpers::raw_replace_at( $blocks, $address, $node );
                if ( $new === null || ! AVCF_Divi_Helpers::write_raw( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Updated module at "%s".', $address ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- delete-module ------------------------- */

    private function register_delete_module() {
        $self = $this;
        wp_register_ability( 'atarim/divi-delete-module', [
            'label' => 'Delete Divi Module', 'category' => 'atarim',
            'description' => 'Remove a module (and its subtree) by address. Dry run unless confirm:true. Addresses shift after a delete — re-read get-content.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'address' => [ 'type' => 'string' ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'address' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'removed' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $address = (string) $input['address'];
                $blocks = AVCF_Divi_Helpers::read_raw( $g['post_id'] );
                if ( AVCF_Divi_Helpers::raw_node_at( $blocks, $address ) === null ) { return [ 'success' => false, 'message' => sprintf( 'No module at address "%s".', $address ) ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'removed' => false, 'message' => sprintf( 'Dry run: would remove module at "%s" and its subtree. Re-call with confirm:true.', $address ) ]; }
                $new = AVCF_Divi_Helpers::raw_remove_at( $blocks, $address );
                if ( $new === null || ! AVCF_Divi_Helpers::write_raw( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to remove/save.' ]; }
                return [ 'success' => true, 'removed' => true, 'message' => sprintf( 'Removed module at "%s".', $address ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- move-module -------------------------- */

    private function register_move_module() {
        $self = $this;
        wp_register_ability( 'atarim/divi-move-module', [
            'label' => 'Move Divi Module', 'category' => 'atarim',
            'description' => 'Relocate a module (with its subtree) from one address to a new parent_address + position. The node is removed then re-inserted; because indices shift on removal, treat positions as best-effort and re-read get-content afterward. Structural rules are enforced at the destination.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'        => $self->post_id_prop(),
                'from_address'   => [ 'type' => 'string' ],
                'parent_address' => [ 'type' => 'string', 'description' => 'Destination container ("" = top level).' ],
                'position'       => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'from_address' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std_out(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $from = (string) $input['from_address'];
                $to   = isset( $input['parent_address'] ) ? (string) $input['parent_address'] : '';
                $blocks = AVCF_Divi_Helpers::read_raw( $g['post_id'] );
                $node = AVCF_Divi_Helpers::raw_node_at( $blocks, $from );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No module at from_address "%s".', $from ) ]; }
                // Disallow moving a node into its own subtree.
                if ( $to === $from || strpos( $to . '/', $from . '/' ) === 0 ) { return [ 'success' => false, 'message' => 'Cannot move a module into itself or its own descendant.' ]; }

                // Structural check at destination.
                $parent_name = null;
                if ( $to !== '' ) {
                    $pblock = AVCF_Divi_Helpers::raw_node_at( $blocks, $to );
                    if ( $pblock === null ) { return [ 'success' => false, 'message' => sprintf( 'parent_address "%s" does not resolve.', $to ) ]; }
                    $parent_name = isset( $pblock['blockName'] ) ? (string) $pblock['blockName'] : '';
                }
                $pnode = AVCF_Divi_Helpers::raw_to_probe( $node );
                $probe = $parent_name === null ? [ $pnode ] : [ [ 'name' => $parent_name, 'attrs' => [], 'children' => [ $pnode ] ] ];
                $v = AVCF_Divi_Helpers::validate_tree( $probe );
                if ( ! empty( $v['errors'] ) ) { return [ 'success' => false, 'message' => implode( ' ', $v['errors'] ) ]; }

                $removed = AVCF_Divi_Helpers::raw_remove_at( $blocks, $from );
                if ( $removed === null ) { return [ 'success' => false, 'message' => 'Move failed during removal.' ]; }
                $pos = isset( $input['position'] ) ? (int) $input['position'] : null;
                $new = AVCF_Divi_Helpers::raw_insert_at( $removed, $to, $pos, [ $node ] );
                if ( $new === null ) { return [ 'success' => false, 'message' => 'Move failed during insert (destination shifted). Re-read get-content and retry.' ]; }
                if ( ! AVCF_Divi_Helpers::write_raw( $g['post_id'], $new ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'message' => 'Module moved. Re-read get-content for current addresses.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/divi-set-content', [
            'label' => 'Set Divi Content', 'category' => 'atarim',
            'description' => 'Replace a post\'s ENTIRE Divi 5 layout with the provided module tree. tree is a nested array of nodes { name, attrs, children[] }. The whole tree is structurally validated (must be divi/section at the root, section>row>column>modules) and rejected on errors. Full overwrite — for targeted edits use add/edit/delete/move-module.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id' => $self->post_id_prop(),
                'tree'    => [ 'type' => 'array' ],
            ], 'required' => [ 'post_id', 'tree' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'warnings' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input, true ); if ( isset( $g['err'] ) ) { return $g['err']; }
                if ( ! isset( $input['tree'] ) || ! is_array( $input['tree'] ) ) { return [ 'success' => false, 'message' => 'tree must be an array of module nodes.' ]; }
                $tree = $self->sanitize_tree( $input['tree'] );
                $v = AVCF_Divi_Helpers::validate_tree( $tree );
                if ( ! empty( $v['errors'] ) ) { return [ 'success' => false, 'message' => 'Validation failed: ' . implode( ' ', $v['errors'] ) ]; }
                if ( ! AVCF_Divi_Helpers::write_tree( $g['post_id'], $tree ) ) { return [ 'success' => false, 'message' => 'Failed to save.' ]; }
                return [ 'success' => true, 'warnings' => $v['warnings'], 'message' => 'Divi layout replaced.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /** Coerce an incoming tree to clean { name, attrs, children } nodes. */
    public function sanitize_tree( $nodes ) {
        $out = [];
        foreach ( (array) $nodes as $n ) {
            if ( ! is_array( $n ) || ! isset( $n['name'] ) ) { continue; }
            $out[] = [
                'name'     => AVCF_Divi_Helpers::normalize_module_name( $n['name'] ),
                'attrs'    => isset( $n['attrs'] ) && is_array( $n['attrs'] ) ? $n['attrs'] : [],
                'children' => isset( $n['children'] ) && is_array( $n['children'] ) ? $this->sanitize_tree( $n['children'] ) : [],
            ];
        }
        return $out;
    }
}