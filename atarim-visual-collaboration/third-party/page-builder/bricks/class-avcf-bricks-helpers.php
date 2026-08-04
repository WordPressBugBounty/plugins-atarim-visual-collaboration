<?php
/**
 * Shared helpers for the Bricks ability cluster.
 *
 * Bricks stores page content as a FLAT array of element nodes in post meta
 * (one meta key per "area" — content / header / footer). Each node:
 *   { id, name (element type), parent (parent id or 0), children (array of
 *     child ids), settings (assoc), label? }
 * Unlike Elementor's nested tree, parent/child relationships are pointers, so
 * tree operations edit a flat list and keep the parent/children ids in sync.
 *
 * IMPORTANT: Bricks' own ~1,200-line validation/normalization layer (control
 * validation, CSS resolution, circular-parent checks) and its CODE-ELEMENT
 * SIGNING are NOT reproduced here. Code elements are written through untouched
 * and therefore remain UNSIGNED — Bricks will not execute unsigned code, which
 * is the safe default; this cluster never signs code (that is the execute-PHP
 * risk class, deliberately excluded). Reads are reliable; writes persist the
 * structure but should be eyeballed in the Bricks editor. Not tested live here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Bricks_Helpers {

    /** Element ids are 6-char lowercase alphanumeric, matching Bricks. */
    public static function generate_id() {
        return substr( str_replace( [ '0', '1', 'o', 'l' ], [ 'a', 'b', 'c', 'd' ], strtolower( wp_generate_password( 8, false, false ) ) ), 0, 6 );
    }

    /** Resolve the post-meta key for a content area. */
    public static function area_meta_key( $area ) {
        $area = in_array( $area, [ 'content', 'header', 'footer' ], true ) ? $area : 'content';
        if ( $area === 'header' && defined( 'BRICKS_DB_PAGE_HEADER' ) ) { return BRICKS_DB_PAGE_HEADER; }
        if ( $area === 'footer' && defined( 'BRICKS_DB_PAGE_FOOTER' ) ) { return BRICKS_DB_PAGE_FOOTER; }
        if ( defined( 'BRICKS_DB_PAGE_CONTENT' ) ) { return BRICKS_DB_PAGE_CONTENT; }
        // Documented fallbacks if the constants aren't loaded.
        return [ 'content' => '_bricks_page_content_2', 'header' => '_bricks_page_header_2', 'footer' => '_bricks_page_footer_2' ][ $area ];
    }

    /** Read the flat element array for a post + area. */
    public static function read_area( $post_id, $area = 'content' ) {
        $data = get_post_meta( $post_id, self::area_meta_key( $area ), true );
        return is_array( $data ) ? array_values( $data ) : [];
    }

    /** Write the flat element array for a post + area. */
    public static function write_area( $post_id, $area, $elements ) {
        $elements = self::normalize( $elements );
        return (bool) update_post_meta( $post_id, self::area_meta_key( $area ), $elements );
    }

    /** Whether the post is built with Bricks. */
    public static function is_bricks_post( $post_id ) {
        return get_post_meta( $post_id, '_bricks_editor_mode', true ) === 'bricks'
            || ! empty( get_post_meta( $post_id, self::area_meta_key( 'content' ), true ) );
    }

    /** Find an element (by id) in a flat array; returns the node or null. */
    public static function find( $elements, $id ) {
        foreach ( (array) $elements as $el ) {
            if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
                return $el;
            }
        }
        return null;
    }

    /** Merge $patch settings (and optional name/label) into element $id. */
    public static function patch( $elements, $id, $patch, &$found ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
                if ( isset( $patch['settings'] ) && is_array( $patch['settings'] ) ) {
                    $cur = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
                    $el['settings'] = array_merge( $cur, $patch['settings'] );
                }
                if ( isset( $patch['label'] ) ) { $el['label'] = (string) $patch['label']; }
                if ( isset( $patch['name'] ) )  { $el['name'] = (string) $patch['name']; }
                $found = true;
            }
            $out[] = $el;
        }
        return $out;
    }

    /**
     * Insert a new element node under $parent_id (0/empty = top level).
     * Returns [elements, inserted_bool]. Fixes parent + children pointers.
     */
    public static function insert( $elements, $parent_id, $node ) {
        $elements = array_values( (array) $elements );
        $parent_id = ( $parent_id === null || $parent_id === '' ) ? 0 : $parent_id;
        $node['parent']   = $parent_id ? (string) $parent_id : 0;
        $node['children'] = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
        if ( ! $parent_id ) {
            $elements[] = $node;
            return [ $elements, true ];
        }
        $inserted = false;
        foreach ( $elements as &$el ) {
            if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === (string) $parent_id ) {
                $children = isset( $el['children'] ) && is_array( $el['children'] ) ? $el['children'] : [];
                $children[] = $node['id'];
                $el['children'] = $children;
                $inserted = true;
                break;
            }
        }
        unset( $el );
        if ( $inserted ) { $elements[] = $node; }
        return [ $elements, $inserted ];
    }

    /**
     * Remove an element by id, plus all descendants, and detach from parent.
     * Returns [elements, removed_bool].
     */
    public static function remove( $elements, $id ) {
        $elements = array_values( (array) $elements );
        if ( self::find( $elements, $id ) === null ) {
            return [ $elements, false ];
        }
        // Collect id + descendants.
        $to_remove = [];
        $stack = [ (string) $id ];
        $by_id = [];
        foreach ( $elements as $el ) { if ( isset( $el['id'] ) ) { $by_id[ (string) $el['id'] ] = $el; } }
        while ( $stack ) {
            $cur = array_pop( $stack );
            $to_remove[ $cur ] = true;
            if ( isset( $by_id[ $cur ]['children'] ) && is_array( $by_id[ $cur ]['children'] ) ) {
                foreach ( $by_id[ $cur ]['children'] as $c ) { $stack[] = (string) $c; }
            }
        }
        $out = [];
        foreach ( $elements as $el ) {
            if ( isset( $el['id'] ) && isset( $to_remove[ (string) $el['id'] ] ) ) {
                continue;
            }
            // Detach the removed id from any parent's children list.
            if ( isset( $el['children'] ) && is_array( $el['children'] ) ) {
                $el['children'] = array_values( array_filter( $el['children'], function( $c ) use ( $to_remove ) { return ! isset( $to_remove[ (string) $c ] ); } ) );
            }
            $out[] = $el;
        }
        return [ $out, true ];
    }

    /** Normalize an element array for storage (ensure required keys present). */
    public static function normalize( $elements ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) { continue; }
            if ( empty( $el['id'] ) )   { $el['id'] = self::generate_id(); }
            if ( ! isset( $el['name'] ) )     { $el['name'] = 'div'; }
            if ( ! array_key_exists( 'parent', $el ) ) { $el['parent'] = 0; }
            if ( ! isset( $el['children'] ) || ! is_array( $el['children'] ) ) { $el['children'] = []; }
            if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) { $el['settings'] = []; }
            $out[] = $el;
        }
        return $out;
    }

    /** Compact structural view (id, name, parent, label, child count). */
    public static function summarize( $elements ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) { continue; }
            $out[] = [
                'id'       => isset( $el['id'] ) ? (string) $el['id'] : '',
                'name'     => isset( $el['name'] ) ? (string) $el['name'] : '',
                'parent'   => isset( $el['parent'] ) ? $el['parent'] : 0,
                'label'    => isset( $el['label'] ) ? (string) $el['label'] : '',
                'children' => isset( $el['children'] ) && is_array( $el['children'] ) ? count( $el['children'] ) : 0,
            ];
        }
        return $out;
    }

    /** List registered Bricks element types (name + label). */
    public static function element_types() {
        $out = [];
        if ( class_exists( '\Bricks\Elements' ) ) {
            $reg = null;
            if ( method_exists( '\Bricks\Elements', 'get_elements' ) ) {
                $reg = \Bricks\Elements::get_elements();
            } elseif ( isset( \Bricks\Elements::$elements ) ) {
                $reg = \Bricks\Elements::$elements;
            }
            if ( is_array( $reg ) ) {
                foreach ( $reg as $name => $def ) {
                    $label = is_array( $def ) && isset( $def['label'] ) ? $def['label'] : ( is_object( $def ) && isset( $def->label ) ? $def->label : (string) $name );
                    $out[] = [ 'name' => (string) $name, 'label' => (string) $label ];
                }
            }
        }
        return $out;
    }
}
