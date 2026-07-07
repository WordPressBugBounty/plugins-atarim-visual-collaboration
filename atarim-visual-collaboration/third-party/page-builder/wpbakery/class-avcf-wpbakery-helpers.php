<?php
/**
 * Shared helpers for the WPBakery ability cluster.
 *
 * WPBakery stores page content as nested [vc_*] shortcodes in post_content. We
 * parse with WP core get_shortcode_regex() + shortcode_parse_atts() into a tree
 * of { tag, atts, children[], _content? } nodes (_content holds raw inner HTML
 * for leaf elements like vc_column_text), address nodes by slash-path of child
 * indices ("0/1/0"), and serialize back to a shortcode string. The registered
 * tag list and per-element schema come from WPBMap.
 *
 * LIMITATION: attributes are handled as plain strings. WPBakery's special param
 * encodings (vc_link, param_group, base64 textarea fields) are NOT decoded/
 * re-encoded — such values round-trip as their raw stored string. Reads and
 * structural edits of common elements (rows, columns, text, headings, buttons)
 * are reliable; exotic param types should be verified in the WPBakery editor.
 * Not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WPBakery_Helpers {

    /** Meta marking a post as built/edited with WPBakery (so it renders via VC). */
    const META_STATUS = '_wpb_vc_js_status';

    /** Grid-item shortcodes absent from WPBMap at page scope but valid inside vc_grid_item. */
    public static function static_grid_tags() {
        return [ 'vc_gitem_post_content', 'vc_gitem_post_image', 'vc_gitem_zone_a', 'vc_gitem_zone_b', 'vc_gitem_zone_c', 'vc_gitem_row', 'vc_gitem_col', 'vc_gitem_animated_block', 'vc_gitem_post_data' ];
    }

    public static function registered_tags() {
        $tags = [];
        if ( class_exists( '\WPBMap' ) && method_exists( '\WPBMap', 'getAllShortCodes' ) ) {
            $all = \WPBMap::getAllShortCodes();
            if ( is_array( $all ) ) { $tags = array_map( 'strval', array_keys( $all ) ); }
        }
        return array_values( array_unique( array_merge( $tags, self::static_grid_tags() ) ) );
    }

    /* ---------------------------- round-trip --------------------------- */

    public static function parse_content( $content, $depth = 0 ) {
        $content = (string) $content;
        if ( $depth > 15 || trim( $content ) === '' ) { return []; }
        $tags = self::registered_tags();
        if ( $tags === [] ) { return []; }

        $pattern = get_shortcode_regex( $tags );
        $matches = [];
        preg_match_all( '/' . $pattern . '/', $content, $matches, PREG_SET_ORDER );

        $nodes = [];
        foreach ( $matches as $match ) {
            // WP shortcode regex groups: [2] tag, [3] atts, [5] inner content.
            $tag = isset( $match[2] ) ? $match[2] : '';
            if ( $tag === '' ) { continue; }
            $atts_raw = isset( $match[3] ) ? $match[3] : '';
            $inner    = isset( $match[5] ) ? $match[5] : '';
            $atts = shortcode_parse_atts( trim( $atts_raw ) );
            if ( ! is_array( $atts ) ) { $atts = []; }
            $children = $inner !== '' ? self::parse_content( $inner, $depth + 1 ) : [];
            $node = [ 'tag' => $tag, 'atts' => $atts, 'children' => $children ];
            // Leaf inner HTML (e.g. vc_column_text) when the inner isn't shortcodes.
            if ( $inner !== '' && $children === [] ) { $node['_content'] = $inner; }
            $nodes[] = $node;
        }
        return $nodes;
    }

    public static function is_container( $tag ) {
        if ( class_exists( '\WPBMap' ) && method_exists( '\WPBMap', 'getShortCode' ) ) {
            $settings = \WPBMap::getShortCode( $tag );
            if ( is_array( $settings ) ) {
                if ( ! empty( $settings['is_container'] ) ) { return true; }
                $params = isset( $settings['params'] ) && is_array( $settings['params'] ) ? $settings['params'] : [];
                foreach ( $params as $param ) {
                    if ( is_array( $param ) && ( ( $param['type'] ?? '' ) === 'textarea_html' ) ) { return true; }
                }
            }
        }
        return false;
    }

    public static function build_atts_string( $atts ) {
        $parts = [];
        foreach ( (array) $atts as $k => $v ) {
            if ( is_array( $v ) ) { continue; } // complex params not re-encoded (left as-is if scalar)
            $parts[] = sprintf( '%s="%s"', $k, (string) $v );
        }
        return $parts ? ' ' . implode( ' ', $parts ) : '';
    }

    public static function serialize_content( $nodes ) {
        $out = '';
        foreach ( (array) $nodes as $node ) { $out .= self::serialize_node( $node ); }
        return $out;
    }
    private static function serialize_node( $node ) {
        $tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';
        if ( $tag === '' ) { return ''; }
        $atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : [];
        $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
        $atts_str = self::build_atts_string( $atts );

        $inner = '';
        if ( $children ) { $inner = self::serialize_content( $children ); }
        elseif ( isset( $node['_content'] ) ) { $inner = (string) $node['_content']; }

        $has_close = $children || isset( $node['_content'] ) || self::is_container( $tag );
        if ( $has_close ) {
            return sprintf( '[%s%s]%s[/%s]', $tag, $atts_str, $inner, $tag );
        }
        return sprintf( '[%s%s]', $tag, $atts_str );
    }

    public static function read_tree( $post_id ) {
        $post = get_post( $post_id );
        return $post ? self::parse_content( $post->post_content ) : [];
    }
    public static function write_tree( $post_id, $tree ) {
        $res = wp_update_post( [ 'ID' => (int) $post_id, 'post_content' => self::serialize_content( $tree ) ], true );
        if ( is_wp_error( $res ) ) { return false; }
        update_post_meta( (int) $post_id, self::META_STATUS, 'true' );
        return true;
    }
    public static function is_wpbakery_post( $post_id ) {
        return get_post_meta( $post_id, self::META_STATUS, true ) === 'true';
    }

    /* ----------------------------- address ----------------------------- */

    public static function split_address( $address ) {
        $segments = explode( '/', (string) $address );
        $index = (int) array_pop( $segments );
        return [ implode( '/', $segments ), $index ];
    }
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

    /* ---------------------------- flatten/view ------------------------- */

    public static function flatten( $tree, $include_atts = false, $cap = 400 ) {
        $out = []; $count = [ 0 ];
        self::flatten_level( $tree, '', '', $include_atts, $cap, $out, $count );
        return $out;
    }
    private static function flatten_level( $nodes, $prefix, $parent, $include_atts, $cap, &$out, &$count ) {
        foreach ( $nodes as $i => $node ) {
            if ( $count[0] >= $cap ) { return; }
            $addr = $prefix === '' ? (string) $i : $prefix . '/' . $i;
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $row = [
                'address'     => $addr,
                'tag'         => isset( $node['tag'] ) ? (string) $node['tag'] : '',
                'parent'      => $parent,
                'child_count' => count( $children ),
                'has_content' => isset( $node['_content'] ),
            ];
            if ( $include_atts ) {
                $row['atts'] = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : [];
                if ( isset( $node['_content'] ) ) { $row['content'] = (string) $node['_content']; }
            }
            $out[] = $row;
            $count[0]++;
            if ( $children ) { self::flatten_level( $children, $addr, $addr, $include_atts, $cap, $out, $count ); }
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
    /** Count occurrences of each tag (for inspect-page). */
    public static function tag_counts( $tree, &$acc = null ) {
        if ( $acc === null ) { $acc = []; }
        foreach ( (array) $tree as $node ) {
            $t = isset( $node['tag'] ) ? (string) $node['tag'] : '';
            if ( $t !== '' ) { $acc[ $t ] = isset( $acc[ $t ] ) ? $acc[ $t ] + 1 : 1; }
            if ( isset( $node['children'] ) && is_array( $node['children'] ) ) { self::tag_counts( $node['children'], $acc ); }
        }
        return $acc;
    }

    /* ----------------------------- schema ------------------------------ */

    public static function list_elements() {
        $out = [];
        if ( class_exists( '\WPBMap' ) && method_exists( '\WPBMap', 'getAllShortCodes' ) ) {
            $all = \WPBMap::getAllShortCodes();
            if ( is_array( $all ) ) {
                foreach ( $all as $tag => $def ) {
                    $out[] = [
                        'tag'      => (string) $tag,
                        'name'     => is_array( $def ) && isset( $def['name'] ) ? $def['name'] : (string) $tag,
                        'category' => is_array( $def ) && isset( $def['category'] ) ? ( is_array( $def['category'] ) ? implode( ',', $def['category'] ) : (string) $def['category'] ) : '',
                    ];
                }
            }
        }
        return $out;
    }

    public static function element_schema( $tag ) {
        if ( ! class_exists( '\WPBMap' ) || ! method_exists( '\WPBMap', 'getShortCode' ) ) { return null; }
        $settings = \WPBMap::getShortCode( $tag );
        if ( ! is_array( $settings ) ) { return null; }
        $fields = [];
        $params = isset( $settings['params'] ) && is_array( $settings['params'] ) ? $settings['params'] : [];
        foreach ( $params as $p ) {
            if ( ! is_array( $p ) ) { continue; }
            $fields[] = [
                'param_name'  => $p['param_name'] ?? '',
                'type'        => $p['type'] ?? '',
                'heading'     => $p['heading'] ?? '',
                'description' => $p['description'] ?? '',
                'value'       => $p['value'] ?? null,
            ];
        }
        return [
            'tag'        => (string) $tag,
            'name'       => $settings['name'] ?? (string) $tag,
            'base'       => $settings['base'] ?? (string) $tag,
            'category'   => $settings['category'] ?? '',
            'is_container'=> ! empty( $settings['is_container'] ),
            'params'     => $fields,
        ];
    }
}
