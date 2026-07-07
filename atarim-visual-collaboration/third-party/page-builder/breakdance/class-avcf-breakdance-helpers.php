<?php
/**
 * Shared helpers for the Breakdance ability cluster.
 *
 * Breakdance stores page content in the `_breakdance_data` post meta as a
 * double-encoded envelope: {"tree_json_string": "<json>"} wrapping the tree
 * { root:{ id, data:{type,properties}, children[] }, _nextNodeId, ... }. Nodes
 * have STABLE INTEGER ids (allocated from _nextNodeId), so elements are
 * addressed by id (not tree path). After every structural mutation we rebuild
 * the denormalized exportedLookupTable and the per-child _parentId annotations
 * (Breakdance relies on these), and verify the encode round-trips before
 * committing (a known boundary bug silently wipes the canvas otherwise).
 *
 * NOTE: cross-process write locking (Breakdance does GET_LOCK/object-cache
 * locking around the read-modify-write) is NOT reproduced here — concurrent
 * edits to the same post could race. The round-trip guard and cache flush ARE
 * kept. Built from the Novamira reference; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Breakdance_Helpers {

    const META = '_breakdance_data';

    public static function empty_tree() {
        return [
            'root'                => [ 'id' => 1, 'data' => [ 'type' => 'root', 'properties' => [] ], 'children' => [] ],
            '_nextNodeId'         => 100,
            'exportedLookupTable' => [],
            'status'              => 'exported',
        ];
    }

    public static function read_tree( $post_id ) {
        $raw = get_post_meta( (int) $post_id, self::META, true );
        if ( ! is_string( $raw ) || $raw === '' ) { return self::empty_tree(); }
        $envelope = json_decode( $raw, true );
        if ( ! is_array( $envelope ) || ! isset( $envelope['tree_json_string'] ) || ! is_string( $envelope['tree_json_string'] ) ) { return self::empty_tree(); }
        $decoded = json_decode( $envelope['tree_json_string'], true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['root'] ) ) { return self::empty_tree(); }
        return $decoded;
    }

    /** @return true|string  true on success, error message string on failure. */
    public static function write_tree( $post_id, $tree ) {
        $post_id = (int) $post_id;
        if ( get_post_status( $post_id ) === 'trash' ) { return 'Post is in the trash; refusing to write.'; }
        if ( get_post( $post_id ) === null ) { return 'Post not found.'; }
        self::rebuild_lookup( $tree );
        $inner = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $inner === false ) { return 'Failed to encode tree (likely JSON depth > 512).'; }
        if ( json_decode( $inner, true ) === null ) { return 'Encoded tree cannot be decoded back (depth at PHP cap); refusing to avoid a canvas wipe.'; }
        $outer = wp_json_encode( [ 'tree_json_string' => $inner ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $outer === false ) { return 'Failed to encode tree envelope.'; }
        update_post_meta( $post_id, self::META, wp_slash( $outer ) );
        if ( function_exists( 'clean_post_cache' ) ) { clean_post_cache( $post_id ); }
        return true;
    }

    public static function node_children( $node ) {
        if ( ! is_array( $node ) || ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) { return []; }
        $out = [];
        foreach ( $node['children'] as $c ) { if ( is_array( $c ) ) { $out[] = $c; } }
        return $out;
    }

    public static function allocate_id( &$tree ) {
        $next = isset( $tree['_nextNodeId'] ) && is_int( $tree['_nextNodeId'] ) ? $tree['_nextNodeId'] : 100;
        $tree['_nextNodeId'] = $next + 1;
        return $next;
    }

    /** Find a node by id. Returns ['node'=>, 'parent_id'=>] or null. */
    public static function find_node( $tree, $id ) {
        if ( ! isset( $tree['root'] ) || ! is_array( $tree['root'] ) ) { return null; }
        $id = (int) $id;
        if ( (int) ( $tree['root']['id'] ?? 0 ) === $id ) { return [ 'node' => $tree['root'], 'parent_id' => null ]; }
        return self::find_in( self::node_children( $tree['root'] ), $id, (int) ( $tree['root']['id'] ?? 0 ) );
    }
    private static function find_in( $children, $id, $parent_id ) {
        foreach ( $children as $child ) {
            if ( (int) ( $child['id'] ?? 0 ) === $id ) { return [ 'node' => $child, 'parent_id' => $parent_id ]; }
            $hit = self::find_in( self::node_children( $child ), $id, (int) ( $child['id'] ?? 0 ) );
            if ( $hit !== null ) { return $hit; }
        }
        return null;
    }

    /** Apply $fn to the node with $id (returns modified node). Returns new root subtree. */
    public static function map_node( $node, $id, $fn ) {
        if ( (int) ( $node['id'] ?? 0 ) === (int) $id ) { return call_user_func( $fn, $node ); }
        $children = self::node_children( $node );
        if ( $children ) {
            $new = [];
            foreach ( $children as $c ) { $new[] = self::map_node( $c, $id, $fn ); }
            $node['children'] = $new;
        }
        return $node;
    }

    /** Remove the node with $id from $node's descendants. Returns modified node + flag via &$removed. */
    public static function remove_in( $node, $id, &$removed ) {
        $children = self::node_children( $node );
        if ( ! $children ) { return $node; }
        $kept = [];
        foreach ( $children as $c ) {
            if ( (int) ( $c['id'] ?? 0 ) === (int) $id ) { $removed = true; continue; }
            $kept[] = self::remove_in( $c, $id, $removed );
        }
        $node['children'] = $kept;
        return $node;
    }

    /** Insert $child into $parent_id's children at $position (null=append). Returns node + &$ok. */
    public static function insert_in( $node, $parent_id, $child, $position, &$ok ) {
        if ( (int) ( $node['id'] ?? 0 ) === (int) $parent_id ) {
            $children = self::node_children( $node );
            if ( $position === null ) { $children[] = $child; }
            else {
                $pos = max( 0, min( (int) $position, count( $children ) ) );
                array_splice( $children, $pos, 0, [ $child ] );
            }
            $node['children'] = $children;
            $ok = true;
            return $node;
        }
        $children = self::node_children( $node );
        if ( $children ) {
            $new = [];
            foreach ( $children as $c ) { $new[] = self::insert_in( $c, $parent_id, $child, $position, $ok ); }
            $node['children'] = $new;
        }
        return $node;
    }

    /* -------------------- lookup table + parent annotations ------------ */

    public static function rebuild_lookup( &$tree ) {
        $table = [];
        if ( ! isset( $tree['root'] ) || ! is_array( $tree['root'] ) ) { $tree['exportedLookupTable'] = $table; return; }
        $root = $tree['root'];
        $root_id = (int) ( $root['id'] ?? 0 );
        $root['children'] = self::annotate_parents( self::node_children( $root ), $root_id );
        $tree['root'] = $root;
        self::collect_lookup( $root, $table );
        $tree['exportedLookupTable'] = $table;
        $tree['status'] = 'exported';
    }
    private static function annotate_parents( $children, $parent_node_id ) {
        foreach ( $children as $i => $child ) {
            $child['_parentId'] = (int) $parent_node_id;
            $grand = self::node_children( $child );
            if ( $grand !== [] ) { $child['children'] = self::annotate_parents( $grand, (int) ( $child['id'] ?? 0 ) ); }
            $children[ $i ] = $child;
        }
        return $children;
    }
    private static function collect_lookup( $node, &$table ) {
        $table[ (int) ( $node['id'] ?? 0 ) ] = $node;
        foreach ( self::node_children( $node ) as $child ) { self::collect_lookup( $child, $table ); }
    }

    /* ----------------------------- views ------------------------------- */

    public static function summary( $node, $depth = 1 ) {
        $data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : [];
        $children = self::node_children( $node );
        $row = [ 'id' => (int) ( $node['id'] ?? 0 ), 'type' => isset( $data['type'] ) ? (string) $data['type'] : '', 'child_count' => count( $children ) ];
        if ( $depth === 0 ) { return $row; }
        $next = $depth === -1 ? -1 : $depth - 1;
        $kids = [];
        foreach ( $children as $c ) { $kids[] = self::summary( $c, $next ); }
        $row['children'] = $kids;
        return $row;
    }
    public static function to_array( $node, $parent_id ) {
        $data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : [];
        return [
            'id'          => (int) ( $node['id'] ?? 0 ),
            'parent_id'   => $parent_id,
            'type'        => isset( $data['type'] ) ? (string) $data['type'] : '',
            'properties'  => isset( $data['properties'] ) ? $data['properties'] : [],
            'children'    => self::node_children( $node ),
            'child_count' => count( self::node_children( $node ) ),
        ];
    }

    /* --------------------------- deep merge ---------------------------- */

    public static function deep_merge( $base, $patch ) {
        foreach ( (array) $patch as $k => $v ) {
            if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && self::is_assoc( $v ) && self::is_assoc( $base[ $k ] ) ) {
                $base[ $k ] = self::deep_merge( $base[ $k ], $v );
            } else { $base[ $k ] = $v; }
        }
        return $base;
    }
    private static function is_assoc( $a ) {
        if ( ! is_array( $a ) || $a === [] ) { return false; }
        return array_keys( $a ) !== range( 0, count( $a ) - 1 );
    }

    /* --------------------------- registry ------------------------------ */

    public static function element_types() {
        $out = [];
        $fn = '\Breakdance\Elements\get_elements';
        if ( function_exists( $fn ) ) {
            try {
                $els = call_user_func( $fn );
                if ( is_array( $els ) ) {
                    foreach ( $els as $el ) {
                        if ( is_object( $el ) && method_exists( $el, 'slug' ) ) {
                            $out[] = [ 'type' => (string) $el->slug(), 'name' => method_exists( $el, 'label' ) ? (string) $el->label() : '' ];
                        } elseif ( is_array( $el ) && isset( $el['slug'] ) ) {
                            $out[] = [ 'type' => (string) $el['slug'], 'name' => isset( $el['label'] ) ? (string) $el['label'] : '' ];
                        }
                    }
                }
            } catch ( \Throwable $e ) { $out = []; }
        }
        return $out;
    }
}
