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
        foreach ( (array) $blocks as $block ) {
            $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
            if ( strpos( $name, 'divi/' ) === 0 ) {
                $inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
                $tree[] = [
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
            $tree[] = [ 'name' => $name, '_raw' => $block, 'children' => [] ];
        }
        return $tree;
    }

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
        foreach ( $nodes as $i => $node ) {
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
}
