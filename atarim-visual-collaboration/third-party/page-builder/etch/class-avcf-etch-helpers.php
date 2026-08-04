<?php
/**
 * Shared helpers for the Etch ability cluster.
 *
 * Etch stores its document as Gutenberg blocks in post_content. We parse with
 * core parse_blocks() into a tree of { block, attrs?, children?, html? } nodes
 * (block = Gutenberg block name like "etch/element"/"etch/text"; html = inner
 * HTML for leaf/freeform; block "" + html = freeform HTML), address nodes by
 * index-path ("0/1/2"), and serialize back with core serialize_blocks().
 * Built on stable WP core block functions; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Etch_Helpers {

    /* ---------------------------- round-trip --------------------------- */

    public static function parse_tree( $content ) {
        return self::blocks_to_tree( parse_blocks( (string) $content ) );
    }

    private static function blocks_to_tree( $blocks ) {
        $tree = [];
        foreach ( (array) $blocks as $block ) {
            $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
            $inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
            $inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

            if ( $name === '' && trim( $inner_html ) === '' ) { continue; } // whitespace

            $node = [ 'block' => $name ];
            if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) && $block['attrs'] !== [] ) { $node['attrs'] = $block['attrs']; }
            if ( $inner_blocks ) { $node['children'] = self::blocks_to_tree( $inner_blocks ); }
            elseif ( trim( $inner_html ) !== '' ) { $node['html'] = $inner_html; }
            $tree[] = $node;
        }
        return $tree;
    }

    public static function serialize_tree( $tree ) {
        return serialize_blocks( self::tree_to_blocks( $tree ) );
    }

    private static function tree_to_blocks( $tree ) {
        $blocks = [];
        foreach ( (array) $tree as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $name = isset( $node['block'] ) ? (string) $node['block'] : '';
            $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $html = isset( $node['html'] ) ? (string) $node['html'] : '';

            if ( $name === '' ) {
                // Freeform HTML node.
                $blocks[] = [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [ $html ] ];
                continue;
            }
            if ( $children ) {
                $inner = self::tree_to_blocks( $children );
                $blocks[] = [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => '', 'innerContent' => $inner ? array_fill( 0, count( $inner ), null ) : [] ];
            } else {
                $blocks[] = [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => $html !== '' ? [ $html ] : [] ];
            }
        }
        return $blocks;
    }

    public static function read_tree( $post_id ) {
        $post = get_post( $post_id );
        return $post ? self::parse_tree( $post->post_content ) : [];
    }
    public static function write_tree( $post_id, $tree ) {
        $res = wp_update_post( [ 'ID' => (int) $post_id, 'post_content' => self::serialize_tree( $tree ) ], true );
        return ! is_wp_error( $res );
    }

    /** Lossy = freeform HTML nodes with real content exist (a tree edit can drop nuance). */
    public static function is_lossy( $content ) {
        foreach ( parse_blocks( (string) $content ) as $b ) {
            $name = isset( $b['blockName'] ) ? $b['blockName'] : null;
            $html = isset( $b['innerHTML'] ) ? (string) $b['innerHTML'] : '';
            if ( ( $name === null || $name === '' ) && trim( $html ) !== '' ) { return true; }
        }
        return false;
    }

    /* ----------------------------- address ----------------------------- */

    public static function address_parts( $address ) {
        $address = trim( (string) $address );
        if ( $address === '' || $address === 'root' ) { return []; }
        $parts = [];
        foreach ( explode( '/', $address ) as $seg ) {
            if ( $seg === '' || ! ctype_digit( $seg ) ) { return null; }
            if ( $seg !== '0' && $seg[0] === '0' ) { return null; }
            $parts[] = (int) $seg;
        }
        return $parts;
    }

    public static function node_at( $tree, $address ) {
        $parts = self::address_parts( $address );
        if ( $parts === null ) { return null; }
        $nodes = $tree; $found = null;
        foreach ( $parts as $i ) {
            if ( ! array_key_exists( $i, $nodes ) ) { return null; }
            $found = $nodes[ $i ];
            $nodes = isset( $found['children'] ) && is_array( $found['children'] ) ? $found['children'] : [];
        }
        return $found;
    }

    /** Apply $op to the children list of the node at $parent_address (""=root). */
    public static function update_children( $tree, $parent_address, $op ) {
        $parts = self::address_parts( $parent_address );
        if ( $parts === null ) { return null; }
        if ( $parts === [] ) { return call_user_func( $op, $tree ); }
        return self::descend( $tree, $parts, $op );
    }
    private static function descend( $nodes, $parts, $op ) {
        $i = array_shift( $parts );
        if ( ! array_key_exists( $i, $nodes ) ) { return null; }
        $children = isset( $nodes[ $i ]['children'] ) && is_array( $nodes[ $i ]['children'] ) ? $nodes[ $i ]['children'] : [];
        $updated = empty( $parts ) ? call_user_func( $op, $children ) : self::descend( $children, $parts, $op );
        if ( $updated === null ) { return null; }
        $nodes[ $i ]['children'] = $updated;
        return $nodes;
    }

    public static function split_address( $address ) {
        $parts = self::address_parts( $address );
        if ( $parts === null || $parts === [] ) { return [ '', null ]; }
        $index = array_pop( $parts );
        return [ implode( '/', $parts ), $index ];
    }

    public static function replace_at( $tree, $address, $node ) {
        list( $parent, $index ) = self::split_address( $address );
        if ( $index === null ) { return null; }
        return self::update_children( $tree, $parent, function( $children ) use ( $index, $node ) {
            if ( ! array_key_exists( $index, $children ) ) { return null; }
            $children[ $index ] = $node;
            return $children;
        } );
    }
    public static function remove_at( $tree, $address ) {
        list( $parent, $index ) = self::split_address( $address );
        if ( $index === null ) { return null; }
        return self::update_children( $tree, $parent, function( $children ) use ( $index ) {
            if ( ! array_key_exists( $index, $children ) ) { return null; }
            array_splice( $children, $index, 1 );
            return $children;
        } );
    }
    public static function insert_at( $tree, $parent_address, $position, $node ) {
        return self::update_children( $tree, $parent_address, function( $children ) use ( $position, $node ) {
            $pos = $position === null ? count( $children ) : max( 0, min( (int) $position, count( $children ) ) );
            array_splice( $children, $pos, 0, [ $node ] );
            return $children;
        } );
    }

    /* ---------------------------- flatten/view ------------------------- */

    public static function flatten( $tree, $include_attrs = false, $cap = 400 ) {
        $out = []; $count = [ 0 ];
        self::flatten_level( $tree, '', '', $include_attrs, $cap, $out, $count );
        return $out;
    }
    private static function flatten_level( $nodes, $prefix, $parent, $include_attrs, $cap, &$out, &$count ) {
        foreach ( $nodes as $i => $node ) {
            if ( $count[0] >= $cap ) { return; }
            $addr = $prefix === '' ? (string) $i : $prefix . '/' . $i;
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $row = [ 'address' => $addr, 'block' => isset( $node['block'] ) ? (string) $node['block'] : '', 'parent' => $parent, 'child_count' => count( $children ), 'has_html' => isset( $node['html'] ) ];
            if ( $include_attrs ) {
                if ( isset( $node['attrs'] ) ) { $row['attrs'] = $node['attrs']; }
                if ( isset( $node['html'] ) ) { $row['html'] = (string) $node['html']; }
            }
            $out[] = $row;
            $count[0]++;
            if ( $children ) { self::flatten_level( $children, $addr, $addr, $include_attrs, $cap, $out, $count ); }
        }
    }
    public static function tree_count( $tree ) {
        $n = 0;
        foreach ( (array) $tree as $node ) {
            $n++;
            if ( isset( $node['children'] ) && is_array( $node['children'] ) ) { $n += self::tree_count( $node['children'] ); }
        }
        return $n;
    }

    /** Sanitize an incoming tree to clean {block, attrs?, children?, html?} nodes. */
    public static function sanitize_tree( $nodes ) {
        $out = [];
        foreach ( (array) $nodes as $n ) {
            if ( ! is_array( $n ) || ! isset( $n['block'] ) ) { continue; }
            $node = [ 'block' => (string) $n['block'] ];
            if ( isset( $n['attrs'] ) && is_array( $n['attrs'] ) ) { $node['attrs'] = $n['attrs']; }
            if ( isset( $n['children'] ) && is_array( $n['children'] ) ) { $node['children'] = self::sanitize_tree( $n['children'] ); }
            elseif ( isset( $n['html'] ) ) { $node['html'] = (string) $n['html']; }
            $out[] = $node;
        }
        return $out;
    }

    /* --------------------------- vocabulary ---------------------------- */

    public static function list_elements() {
        $out = [];
        if ( class_exists( '\WP_Block_Type_Registry' ) ) {
            $reg = \WP_Block_Type_Registry::get_instance();
            if ( $reg && method_exists( $reg, 'get_all_registered' ) ) {
                foreach ( $reg->get_all_registered() as $name => $type ) {
                    if ( strpos( (string) $name, 'etch/' ) === 0 ) {
                        $out[] = [ 'block' => (string) $name, 'title' => isset( $type->title ) ? (string) $type->title : '' ];
                    }
                }
            }
        }
        if ( empty( $out ) ) {
            foreach ( [ 'etch/element', 'etch/text', 'etch/component', 'etch/loop', 'etch/template-part', 'etch/fragment' ] as $b ) {
                $out[] = [ 'block' => $b, 'title' => '' ];
            }
        }
        return $out;
    }
}
