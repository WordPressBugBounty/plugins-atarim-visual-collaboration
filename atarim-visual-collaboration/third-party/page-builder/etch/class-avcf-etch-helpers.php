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
        foreach ( (array) $blocks as $i => $block ) {
            $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
            $inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
            $inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

            if ( $name === '' && trim( $inner_html ) === '' ) { continue; } // whitespace

            $node = [ 'index' => (int) $i, 'block' => $name ];
            if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) && $block['attrs'] !== [] ) { $node['attrs'] = $block['attrs']; }
            if ( $inner_blocks ) { $node['children'] = self::blocks_to_tree( $inner_blocks ); }
            elseif ( trim( $inner_html ) !== '' ) { $node['html'] = $inner_html; }
            $tree[] = $node;
        }
        return $tree;
    }

    /**
     * WHOLE-DOCUMENT OVERWRITE ONLY (set-content), where the caller supplies the
     * entire tree and there is no prior markup to preserve. Never use this to
     * write back a tree that was read from an existing post: the read projection
     * has already discarded that post's wrapper markup, so re-serialising it
     * destroys every container in the document. Granular edits go through the
     * raw_* engine below.
     */
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

    /**
     * Lossy = a whole-tree overwrite would destroy something the tree can't carry:
     * freeform HTML nodes with real content, or any container holding literal
     * wrapper markup around its children (<ul class="wp-block-list">, <div
     * class="wp-block-group">, ...). Granular raw_* edits are never lossy; this
     * guard is for set-content.
     */
    public static function is_lossy( $content ) {
        return self::scan_lossy( parse_blocks( (string) $content ) );
    }
    private static function scan_lossy( $blocks ) {
        foreach ( (array) $blocks as $b ) {
            $name = isset( $b['blockName'] ) ? $b['blockName'] : null;
            $html = isset( $b['innerHTML'] ) ? (string) $b['innerHTML'] : '';
            if ( ( $name === null || $name === '' ) && trim( $html ) !== '' ) { return true; }
            $kids = isset( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ? $b['innerBlocks'] : [];
            if ( $kids ) {
                foreach ( ( isset( $b['innerContent'] ) && is_array( $b['innerContent'] ) ? $b['innerContent'] : [] ) as $chunk ) {
                    if ( is_string( $chunk ) && trim( $chunk ) !== '' ) { return true; } // wrapper markup
                }
                if ( self::scan_lossy( $kids ) ) { return true; }
            }
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
        foreach ( $nodes as $node ) {
            if ( $count[0] >= $cap ) { return; }
            $i = isset( $node['index'] ) ? (int) $node['index'] : 0;
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

    /* ------------------------- raw block writes -------------------------
     *
     * The tree above is a READ projection and is LOSSY BY DESIGN: it drops the
     * literal HTML chunks a container wraps its children in, and the whitespace
     * between siblings. Writes must never round-trip through it -- that is the
     * bug this section replaced. Everything below mutates the raw parse_blocks()
     * array and serializes that, so nothing is translated and nothing is lost.
     *
     * innerContent is the interleave serialize_block() walks: every string is
     * printed literally, every null consumes the next entry of innerBlocks.
     * Adding or removing a child without keeping the null count in step silently
     * drops or duplicates content. raw_splice_children() and raw_unsplice_child()
     * are the only two places allowed to touch it.
     *
     * Addresses are chains of RAW parse_blocks indices. The read view skips
     * whitespace nodes, so published addresses are not contiguous. Never renumber.
     */

    public static function parse_raw( $content ) { return parse_blocks( (string) $content ); }
    public static function serialize_raw( $blocks ) { return serialize_blocks( (array) $blocks ); }

    public static function read_raw( $post_id ) {
        $post = get_post( $post_id );
        return $post ? self::parse_raw( $post->post_content ) : [];
    }
    public static function write_raw( $post_id, $blocks ) {
        $res = wp_update_post( [ 'ID' => (int) $post_id, 'post_content' => self::serialize_raw( $blocks ) ], true );
        return ! is_wp_error( $res );
    }

    /** Strict index-path -> int list. '' / 'root' = document root. null = malformed. */
    public static function raw_address_parts( $address ) {
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
    public static function raw_split_address( $address ) {
        $parts = self::raw_address_parts( $address );
        if ( $parts === null || $parts === [] ) { return [ '', null ]; }
        $index = array_pop( $parts );
        return [ implode( '/', $parts ), $index ];
    }

    public static function raw_node_at( $blocks, $address ) {
        $parts = self::raw_address_parts( $address );
        if ( $parts === null ) { return null; }
        $nodes = (array) $blocks; $found = null;
        foreach ( $parts as $i ) {
            if ( ! array_key_exists( $i, $nodes ) ) { return null; }
            $found = $nodes[ $i ];
            $nodes = isset( $found['innerBlocks'] ) && is_array( $found['innerBlocks'] ) ? $found['innerBlocks'] : [];
        }
        return $found;
    }

    /*
     * The document root is not a block and has no innerContent. Wrapping it in a
     * synthetic parent lets every address -- root included -- share one splice
     * path; the synthetic innerContent is discarded on unwrap.
     */
    private static function raw_wrap_root( $blocks ) {
        $blocks = array_values( (array) $blocks );
        return [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => $blocks, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $blocks ), null ) ];
    }
    private static function raw_unwrap_root( $root ) {
        return ( is_array( $root ) && isset( $root['innerBlocks'] ) && is_array( $root['innerBlocks'] ) ) ? array_values( $root['innerBlocks'] ) : [];
    }
    private static function raw_apply_at( $block, $parts, $fn ) {
        if ( $parts === [] ) { return call_user_func( $fn, $block ); }
        $i = array_shift( $parts );
        $inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
        if ( ! array_key_exists( $i, $inner ) ) { return null; }
        $updated = self::raw_apply_at( $inner[ $i ], $parts, $fn );
        if ( $updated === null ) { return null; }
        $inner[ $i ] = $updated;
        $block['innerBlocks'] = $inner;
        return $block;
    }

    private static function raw_placeholder_offsets( $inner_content ) {
        $pos = [];
        foreach ( (array) $inner_content as $i => $chunk ) { if ( ! is_string( $chunk ) ) { $pos[] = $i; } }
        return $pos;
    }

    /**
     * A container with no children has no placeholder to insert against and its
     * wrapper is one opaque literal. Split it just before its final closing tag
     * so the first child lands inside. null = cannot open safely; caller refuses.
     */
    private static function raw_open_empty_container( $inner_content ) {
        $joined = '';
        foreach ( (array) $inner_content as $chunk ) { if ( is_string( $chunk ) ) { $joined .= $chunk; } }
        if ( $joined === '' ) { return [ null ]; }
        if ( trim( $joined ) === '' ) { return [ $joined, null ]; }
        if ( ! preg_match( '~</[A-Za-z][A-Za-z0-9:-]*>\s*$~', $joined, $m, PREG_OFFSET_CAPTURE ) ) { return null; }
        $at = (int) $m[0][1];
        return [ substr( $joined, 0, $at ), null, substr( $joined, $at ) ];
    }

    private static function raw_splice_children( $parent, $k, $new ) {
        $new = array_values( (array) $new );
        if ( $new === [] ) { return $parent; }
        $inner = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? $parent['innerBlocks'] : [];
        $ic    = isset( $parent['innerContent'] ) && is_array( $parent['innerContent'] ) ? array_values( $parent['innerContent'] ) : [];
        $k     = max( 0, min( (int) $k, count( $inner ) ) );
        $slots = self::raw_placeholder_offsets( $ic );
        if ( $slots === [] ) {
            $opened = self::raw_open_empty_container( $ic );
            if ( $opened === null ) { return null; }
            $ic = $opened;
            $s  = self::raw_placeholder_offsets( $ic );
            $at = $s[0];
            array_splice( $ic, $at, 1 );
        } else {
            $at = ( $k < count( $slots ) ) ? $slots[ $k ] : ( $slots[ count( $slots ) - 1 ] + 1 );
        }
        array_splice( $inner, $k, 0, $new );
        array_splice( $ic, $at, 0, array_fill( 0, count( $new ), null ) );
        $parent['innerBlocks']  = $inner;
        $parent['innerContent'] = $ic;
        return $parent;
    }

    private static function raw_unsplice_child( $parent, $k ) {
        $inner = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? $parent['innerBlocks'] : [];
        $ic    = isset( $parent['innerContent'] ) && is_array( $parent['innerContent'] ) ? array_values( $parent['innerContent'] ) : [];
        if ( ! array_key_exists( $k, $inner ) ) { return null; }
        $slots = self::raw_placeholder_offsets( $ic );
        array_splice( $inner, $k, 1 );
        if ( isset( $slots[ $k ] ) ) { array_splice( $ic, $slots[ $k ], 1 ); }
        $parent['innerBlocks']  = $inner;
        $parent['innerContent'] = $ic;
        return $parent;
    }

    public static function raw_replace_at( $blocks, $address, $new_block ) {
        list( $parent, $index ) = self::raw_split_address( $address );
        if ( $index === null ) { return null; }
        $parts = self::raw_address_parts( $parent );
        if ( $parts === null ) { return null; }
        $root = self::raw_apply_at( self::raw_wrap_root( $blocks ), $parts, function( $p ) use ( $index, $new_block ) {
            $inner = isset( $p['innerBlocks'] ) && is_array( $p['innerBlocks'] ) ? $p['innerBlocks'] : [];
            if ( ! array_key_exists( $index, $inner ) ) { return null; }
            $inner[ $index ] = $new_block;
            $p['innerBlocks'] = $inner;
            return $p;
        } );
        return $root === null ? null : self::raw_unwrap_root( $root );
    }

    public static function raw_remove_at( $blocks, $address ) {
        list( $parent, $index ) = self::raw_split_address( $address );
        if ( $index === null ) { return null; }
        $parts = self::raw_address_parts( $parent );
        if ( $parts === null ) { return null; }
        $root = self::raw_apply_at( self::raw_wrap_root( $blocks ), $parts, function( $p ) use ( $index ) {
            return self::raw_unsplice_child( $p, $index );
        } );
        return $root === null ? null : self::raw_unwrap_root( $root );
    }

    /** $new_blocks is a list of raw blocks. position null = append. */
    public static function raw_insert_at( $blocks, $parent_address, $position, $new_blocks ) {
        $parts = self::raw_address_parts( $parent_address );
        if ( $parts === null ) { return null; }
        $root = self::raw_apply_at( self::raw_wrap_root( $blocks ), $parts, function( $p ) use ( $position, $new_blocks ) {
            $n = isset( $p['innerBlocks'] ) && is_array( $p['innerBlocks'] ) ? count( $p['innerBlocks'] ) : 0;
            return self::raw_splice_children( $p, $position === null ? $n : (int) $position, $new_blocks );
        } );
        return $root === null ? null : self::raw_unwrap_root( $root );
    }

    /** Build a leaf raw block. Containers must come from markup, not from a name. */
    public static function raw_make_block( $name, $attrs = [], $html = '' ) {
        $name  = (string) $name;
        $html  = (string) $html;
        $attrs = is_array( $attrs ) ? $attrs : [];
        return [ 'blockName' => $name !== '' ? $name : null, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => $html !== '' ? [ $html ] : [] ];
    }

    /** Set a raw block's own inner HTML. Refuses when it would orphan children. */
    public static function raw_set_html( $block, $html ) {
        $kids = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? count( $block['innerBlocks'] ) : 0;
        if ( $kids > 0 ) { return null; }
        $html = (string) $html;
        $block['innerHTML']    = $html;
        $block['innerContent'] = $html !== '' ? [ $html ] : [];
        return $block;
    }

}