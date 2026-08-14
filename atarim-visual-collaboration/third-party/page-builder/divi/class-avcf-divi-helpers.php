<?php
/**
 * Shared helpers for the Divi 5 ability cluster.
 *
 * Divi 5 stores page content as `divi/*` WordPress blocks in post_content. We
 * parse with core parse_blocks() into a nested tree of { name, attrs, children }
 * nodes (non-Divi blocks preserved verbatim as opaque passthrough so writes
 * never drop them), address nodes by slash-path of child indices ("0/1/0"), and
 * serialize back with core serialize_blocks(). Built on stable WP core block
 * functions, so the content round-trip is reliable; the module *registry*
 * (list/schema) depends on Divi 5 internals and degrades gracefully.
 *
 * Divi 4 shortcodes are NOT handled. Not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Divi_Helpers {

    /** Structural skeleton: section > row > column > (modules / group). */
    public static function structure_children() {
        return [
            'divi/section'      => [ 'divi/row' ],
            'divi/row'          => [ 'divi/column' ],
            'divi/row-inner'    => [ 'divi/column-inner' ],
            'divi/column'       => [ 'divi/row-inner', 'divi/group' ],
            'divi/column-inner' => [ 'divi/group' ],
            'divi/group'        => [],
        ];
    }
    public static function raw_html_modules() {
        return [ 'divi/code', 'divi/fullwidth-code' ];
    }

    /* ---------------------------- round-trip --------------------------- */

    public static function parse_tree( $content ) {
        return self::blocks_to_tree( parse_blocks( (string) $content ) );
    }

    private static function blocks_to_tree( $blocks ) {
        $tree = [];
        foreach ( (array) $blocks as $i => $block ) {
            $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
            if ( strpos( $name, 'divi/' ) === 0 ) {
                $inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
                $tree[] = [
                    'index'    => (int) $i,
                    'name'     => $name,
                    'attrs'    => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
                    'children' => self::blocks_to_tree( $inner ),
                ];
                continue;
            }
            $html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
            if ( $name === '' && trim( $html ) === '' ) {
                continue; // insignificant whitespace
            }
            $tree[] = [ 'index' => (int) $i, 'name' => $name, '_raw' => $block, 'children' => [] ];
        }
        return $tree;
    }

    /**
     * WHOLE-DOCUMENT OVERWRITE ONLY (set-content), where the caller supplies the
     * entire tree and there is no prior markup to preserve. Never use this to
     * write back a tree that was read from an existing post: the read projection
     * discards each divi/* block's own inner HTML (which is where divi/code and
     * divi/fullwidth-code keep their content) and all inter-block whitespace, so
     * re-serialising it destroys them. Granular edits go through the raw_* engine.
     */
    public static function serialize_tree( $tree ) {
        return serialize_blocks( self::tree_to_blocks( $tree ) );
    }

    private static function tree_to_blocks( $tree ) {
        $blocks = [];
        foreach ( (array) $tree as $node ) {
            if ( isset( $node['_raw'] ) && is_array( $node['_raw'] ) ) {
                $blocks[] = self::passthrough_block( $node['_raw'] );
                continue;
            }
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $inner = self::tree_to_blocks( $children );
            $blocks[] = [
                'blockName'    => isset( $node['name'] ) && is_string( $node['name'] ) ? $node['name'] : '',
                'attrs'        => isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [],
                'innerBlocks'  => $inner,
                'innerHTML'    => '',
                'innerContent' => $inner ? array_fill( 0, count( $inner ), null ) : [],
            ];
        }
        return $blocks;
    }

    private static function passthrough_block( $raw ) {
        $inner = [];
        foreach ( ( isset( $raw['innerBlocks'] ) && is_array( $raw['innerBlocks'] ) ? $raw['innerBlocks'] : [] ) as $child ) {
            if ( is_array( $child ) ) { $inner[] = $child; }
        }
        return [
            'blockName'    => isset( $raw['blockName'] ) && is_string( $raw['blockName'] ) ? $raw['blockName'] : null,
            'attrs'        => isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ? $raw['attrs'] : [],
            'innerBlocks'  => $inner,
            'innerHTML'    => isset( $raw['innerHTML'] ) && is_string( $raw['innerHTML'] ) ? $raw['innerHTML'] : '',
            'innerContent' => isset( $raw['innerContent'] ) && is_array( $raw['innerContent'] ) ? $raw['innerContent'] : [],
        ];
    }

    public static function read_tree( $post_id ) {
        $post = get_post( $post_id );
        return $post ? self::parse_tree( $post->post_content ) : [];
    }
    public static function write_tree( $post_id, $tree ) {
        $res = wp_update_post( [ 'ID' => (int) $post_id, 'post_content' => self::serialize_tree( $tree ) ], true );
        return ! is_wp_error( $res );
    }

    /* ----------------------------- address ----------------------------- */

    public static function split_address( $address ) {
        $segments = explode( '/', (string) $address );
        $index = (int) array_pop( $segments );
        return [ implode( '/', $segments ), $index ];
    }

    /** Strict: numeric, non-empty, no leading-zero aliasing. */
    private static function valid_segment( $segment ) {
        if ( $segment === '' || ! ctype_digit( $segment ) ) { return false; }
        if ( $segment !== '0' && $segment[0] === '0' ) { return false; }
        return true;
    }

    public static function tree_get( $tree, $address ) {
        $nodes = $tree; $found = null;
        foreach ( explode( '/', (string) $address ) as $segment ) {
            if ( ! self::valid_segment( $segment ) ) { return null; }
            $index = (int) $segment;
            if ( ! array_key_exists( $index, $nodes ) ) { return null; }
            $found = $nodes[ $index ];
            $nodes = isset( $found['children'] ) && is_array( $found['children'] ) ? $found['children'] : [];
        }
        return $found;
    }

    public static function tree_update_children( $tree, $parent_address, $op ) {
        if ( $parent_address === '' ) { return call_user_func( $op, $tree ); }
        return self::tree_descend( $tree, explode( '/', $parent_address ), $op );
    }
    private static function tree_descend( $nodes, $path, $op ) {
        $index = (int) array_shift( $path );
        if ( ! array_key_exists( $index, $nodes ) ) { return null; }
        $children = isset( $nodes[ $index ]['children'] ) && is_array( $nodes[ $index ]['children'] ) ? $nodes[ $index ]['children'] : [];
        $updated = empty( $path ) ? call_user_func( $op, $children ) : self::tree_descend( $children, $path, $op );
        if ( $updated === null ) { return null; }
        $nodes[ $index ]['children'] = $updated;
        return $nodes;
    }

    public static function tree_replace( $tree, $address, $node ) {
        list( $parent, $index ) = self::split_address( $address );
        return self::tree_update_children( $tree, $parent, function( $children ) use ( $index, $node ) {
            if ( ! array_key_exists( $index, $children ) ) { return null; }
            $children[ $index ] = $node;
            return $children;
        } );
    }
    public static function tree_remove( $tree, $address ) {
        list( $parent, $index ) = self::split_address( $address );
        return self::tree_update_children( $tree, $parent, function( $children ) use ( $index ) {
            if ( ! array_key_exists( $index, $children ) ) { return null; }
            array_splice( $children, $index, 1 );
            return $children;
        } );
    }
    public static function tree_insert( $tree, $parent_address, $position, $node ) {
        return self::tree_update_children( $tree, $parent_address, function( $children ) use ( $position, $node ) {
            $pos = max( 0, min( (int) $position, count( $children ) ) );
            array_splice( $children, $pos, 0, [ $node ] );
            return $children;
        } );
    }

    /* --------------------------- flatten / view ------------------------ */

    /** Flatten to addressed rows for get-content. */
    public static function flatten( $tree, $include_attrs = false, $cap = 400 ) {
        $out = []; $count = [ 0 ];
        self::flatten_level( $tree, '', '', $include_attrs, $cap, $out, $count );
        return $out;
    }
    private static function flatten_level( $nodes, $prefix, $parent, $include_attrs, $cap, &$out, &$count ) {
        foreach ( $nodes as $node ) {
            $i = isset( $node['index'] ) ? (int) $node['index'] : 0;
            if ( $count[0] >= $cap ) { return; }
            $addr = $prefix === '' ? (string) $i : $prefix . '/' . $i;
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $is_raw = isset( $node['_raw'] );
            $row = [
                'address'      => $addr,
                'name'         => isset( $node['name'] ) ? (string) $node['name'] : '',
                'parent'       => $parent,
                'child_count'  => count( $children ),
                'passthrough'  => $is_raw,
            ];
            if ( $include_attrs && ! $is_raw ) {
                $row['attrs'] = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
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

    /* --------------------------- module meta --------------------------- */

    public static function normalize_module_name( $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) { return ''; }
        return strpos( $name, 'divi/' ) === 0 ? $name : 'divi/' . ltrim( $name, '/' );
    }

    /** Module metadata from the Divi 5 registry, or null if unavailable. */
    public static function module_metadata( $name ) {
        $name = self::normalize_module_name( $name );
        $cls = '\ET\Builder\Packages\ModuleLibrary\ModuleRegistration';
        if ( $name === '' || ! class_exists( $cls ) || ! method_exists( $cls, 'get_core_module_metadata' ) ) {
            return null;
        }
        try {
            $meta = call_user_func( [ $cls, 'get_core_module_metadata' ], $name );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( ! is_array( $meta ) || $meta === [] || ( ( $meta['name'] ?? null ) !== $name ) ) {
            return null;
        }
        return $meta;
    }

    /** Curated fallback list of common Divi 5 modules (when registry can't enumerate). */
    public static function fallback_modules() {
        return [
            [ 'name' => 'divi/section', 'category' => 'structure' ],
            [ 'name' => 'divi/row', 'category' => 'structure' ],
            [ 'name' => 'divi/column', 'category' => 'structure' ],
            [ 'name' => 'divi/text', 'category' => 'module' ],
            [ 'name' => 'divi/heading', 'category' => 'module' ],
            [ 'name' => 'divi/image', 'category' => 'module' ],
            [ 'name' => 'divi/button', 'category' => 'module' ],
            [ 'name' => 'divi/blurb', 'category' => 'module' ],
            [ 'name' => 'divi/cta', 'category' => 'module' ],
            [ 'name' => 'divi/divider', 'category' => 'module' ],
            [ 'name' => 'divi/icon', 'category' => 'module' ],
            [ 'name' => 'divi/code', 'category' => 'module' ],
            [ 'name' => 'divi/blog', 'category' => 'module' ],
            [ 'name' => 'divi/gallery', 'category' => 'module' ],
            [ 'name' => 'divi/accordion', 'category' => 'module' ],
            [ 'name' => 'divi/tabs', 'category' => 'module' ],
            [ 'name' => 'divi/toggle', 'category' => 'module' ],
            [ 'name' => 'divi/slider', 'category' => 'module' ],
            [ 'name' => 'divi/testimonial', 'category' => 'module' ],
            [ 'name' => 'divi/video', 'category' => 'module' ],
        ];
    }

    /** Lite structural validation. Returns ['errors'=>[], 'warnings'=>[]]. */
    public static function validate_tree( $tree ) {
        $errors = []; $warnings = [];
        self::validate_level( $tree, null, $errors, $warnings );
        return [ 'errors' => array_values( array_unique( $errors ) ), 'warnings' => array_values( array_unique( $warnings ) ) ];
    }
    private static function validate_level( $nodes, $parent_name, &$errors, &$warnings ) {
        $structure = self::structure_children();
        foreach ( (array) $nodes as $node ) {
            $name = isset( $node['name'] ) ? (string) $node['name'] : '';
            if ( isset( $node['_raw'] ) ) { continue; } // passthrough, not validated
            if ( $parent_name === null ) {
                if ( $name !== 'divi/section' ) {
                    $errors[] = sprintf( '"%s" is not allowed at the top level — only divi/section may sit at the root.', $name );
                }
            } elseif ( array_key_exists( $parent_name, $structure ) ) {
                $allowed = $structure[ $parent_name ];
                // Containers that hold leaf modules (column/column-inner/group) accept any module.
                $is_container_for_modules = in_array( $parent_name, [ 'divi/column', 'divi/column-inner', 'divi/group' ], true );
                if ( ! $is_container_for_modules && ! in_array( $name, $allowed, true ) ) {
                    $errors[] = sprintf( '"%s" is not allowed inside "%s".', $name, $parent_name );
                }
            }
            if ( in_array( $name, self::raw_html_modules(), true ) ) {
                $warnings[] = sprintf( '"%s" embeds raw HTML; prefer native Divi modules so the layout stays editable.', $name );
            }
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            self::validate_level( $children, $name, $errors, $warnings );
        }
    }

    /** Recursive deep-merge (associative merged; list values replaced). */
    public static function deep_merge( $base, $patch ) {
        foreach ( (array) $patch as $k => $v ) {
            if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && self::is_assoc( $v ) && self::is_assoc( $base[ $k ] ) ) {
                $base[ $k ] = self::deep_merge( $base[ $k ], $v );
            } else {
                $base[ $k ] = $v;
            }
        }
        return $base;
    }
    private static function is_assoc( $arr ) {
        if ( ! is_array( $arr ) || $arr === [] ) { return false; }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    /** Shallow {name, attrs, children} projection of a raw block, for validate_tree() only. */
    public static function raw_to_probe( $block ) {
        $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
        $kids = [];
        foreach ( ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [] ) as $c ) {
            $kids[] = self::raw_to_probe( $c );
        }
        return [ 'name' => $name, 'attrs' => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [], 'children' => $kids ];
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