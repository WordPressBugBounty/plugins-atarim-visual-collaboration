<?php
/**
 * Shared helpers for the Elementor ability cluster.
 *
 * Owns the _elementor_data round-trip and the tree operations the abilities
 * build on. The data is a nested JSON array of element nodes:
 *   { id, elType (section|column|container|widget), settings:{}, elements:[],
 *     widgetType (when elType === "widget") }
 *
 * Writes prefer Elementor's Document::save() pipeline (normalisation + CSS
 * regeneration + hooks) and fall back to a raw post-meta write plus a manual
 * CSS-cache clear when the document API is unavailable or throws.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Elementor_Helpers {

    /** Element ids are 7-char lowercase hex, matching Elementor's own format. */
    public static function generate_id() {
        return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
    }

    /**
     * Read the element tree for a post. Returns a list of element arrays.
     *
     * @param int $post_id
     * @return array
     */
    public static function read_tree( $post_id ) {
        // Prefer the document API (already-normalised data).
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $plugin = \Elementor\Plugin::$instance;
            if ( isset( $plugin->documents ) && is_object( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
                $doc = $plugin->documents->get( $post_id );
                if ( $doc && method_exists( $doc, 'get_elements_data' ) ) {
                    $data = $doc->get_elements_data();
                    if ( is_array( $data ) ) {
                        return $data;
                    }
                }
            }
        }
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Whether the post is actually built with Elementor.
     */
    public static function is_elementor_post( $post_id ) {
        return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
    }

    /**
     * Persist an element tree for a post.
     *
     * @param int   $post_id
     * @param array $elements
     * @return bool
     */
    public static function write_tree( $post_id, $elements, $force_raw = false ) {
        $template_type = get_post_meta( $post_id, '_elementor_template_type', true );
        if ( $template_type === '' ) {
            $template_type = 'wp-page';
        }

        // Atomic-widget trees must bypass Document::save() (it silently strips
        // atomic elements); callers pass $force_raw to write directly.
        if ( $force_raw ) {
            return self::write_tree_raw( $post_id, $elements, $template_type );
        }

        // Try the Document API first.
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $plugin = \Elementor\Plugin::$instance;
            if ( isset( $plugin->documents ) && is_object( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
                $doc = $plugin->documents->get( $post_id );
                if ( $doc ) {
                    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
                    update_post_meta( $post_id, '_elementor_template_type', $template_type );
                    try {
                        $result = $doc->save( [ 'elements' => $elements ] );
                        if ( $result !== false ) {
                            return true;
                        }
                    } catch ( \Throwable $e ) {
                        // fall through to raw write
                    }
                }
            }
        }
        return self::write_tree_raw( $post_id, $elements, $template_type );
    }

    /**
     * Raw post-meta write + manual CSS-cache clear.
     */
    private static function write_tree_raw( $post_id, $elements, $template_type ) {
        $encoded = wp_json_encode( $elements );
        if ( $encoded === false ) {
            return false;
        }
        update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', $template_type );
        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }
        self::clear_css_cache( $post_id );
        return true;
    }

    /**
     * Regenerate Elementor CSS so the front end reflects the change.
     */
    public static function clear_css_cache( $post_id ) {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return;
        }
        $plugin = \Elementor\Plugin::$instance;
        $files_manager = isset( $plugin->files_manager ) ? $plugin->files_manager : null;
        if ( is_object( $files_manager ) && method_exists( $files_manager, 'clear_cache' ) ) {
            $files_manager->clear_cache();
        }
        delete_post_meta( $post_id, '_elementor_css' );
    }

    /**
     * Find an element node by id (deep). Returns the node array or null.
     *
     * @param array  $elements
     * @param string $id
     * @return array|null
     */
    public static function find( $elements, $id ) {
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
                return $el;
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $hit = self::find( $el['elements'], $id );
                if ( $hit !== null ) {
                    return $hit;
                }
            }
        }
        return null;
    }

    /**
     * Return a new tree with the node matching $id transformed by $cb.
     * $found is set true when a match is hit.
     *
     * @param array    $elements
     * @param string   $id
     * @param callable $cb       fn(array $node): array
     * @param bool     $found
     * @return array
     */
    public static function map_edit( $elements, $id, $cb, &$found ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
                $el    = call_user_func( $cb, $el );
                $found = true;
            } elseif ( is_array( $el ) && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $el['elements'] = self::map_edit( $el['elements'], $id, $cb, $found );
            }
            $out[] = $el;
        }
        return $out;
    }

    /**
     * Remove the node matching $id. Returns [newTree, removedNode|null].
     *
     * @param array  $elements
     * @param string $id
     * @return array{0:array,1:?array}
     */
    public static function remove( $elements, $id ) {
        $removed = null;
        $out     = [];
        foreach ( (array) $elements as $el ) {
            if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
                $removed = $el;
                continue;
            }
            if ( is_array( $el ) && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                list( $childTree, $childRemoved ) = self::remove( $el['elements'], $id );
                $el['elements'] = $childTree;
                if ( $childRemoved !== null ) {
                    $removed = $childRemoved;
                }
            }
            $out[] = $el;
        }
        return [ $out, $removed ];
    }

    /**
     * Insert $node under $parent_id at $index (or root when parent is empty).
     * Returns [newTree, inserted bool].
     *
     * @param array       $elements
     * @param string|null $parent_id
     * @param array       $node
     * @param int|null    $index
     * @return array{0:array,1:bool}
     */
    public static function insert( $elements, $parent_id, $node, $index = null ) {
        if ( $parent_id === null || $parent_id === '' ) {
            $elements = self::splice_in( $elements, $node, $index );
            return [ $elements, true ];
        }
        $inserted = false;
        $out      = [];
        foreach ( (array) $elements as $el ) {
            if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === (string) $parent_id ) {
                $children       = ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) ? $el['elements'] : [];
                $el['elements'] = self::splice_in( $children, $node, $index );
                $inserted       = true;
            } elseif ( is_array( $el ) && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                list( $childTree, $childInserted ) = self::insert( $el['elements'], $parent_id, $node, $index );
                $el['elements'] = $childTree;
                if ( $childInserted ) {
                    $inserted = true;
                }
            }
            $out[] = $el;
        }
        return [ $out, $inserted ];
    }

    private static function splice_in( $list, $node, $index ) {
        $list = array_values( (array) $list );
        if ( $index === null || $index < 0 || $index > count( $list ) ) {
            $list[] = $node;
            return $list;
        }
        array_splice( $list, (int) $index, 0, [ $node ] );
        return $list;
    }

    /**
     * Compact structural summary of a tree (no raw settings), depth-limited.
     *
     * @param array $elements
     * @param int   $max_depth
     * @return array
     */
    public static function summarize( $elements, $max_depth = 6, $depth = 0 ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            $node = [
                'id'      => isset( $el['id'] ) ? (string) $el['id'] : '',
                'elType'  => isset( $el['elType'] ) ? (string) $el['elType'] : '',
            ];
            if ( isset( $el['widgetType'] ) ) {
                $node['widgetType'] = (string) $el['widgetType'];
            }
            $children = ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) ? $el['elements'] : [];
            $node['child_count'] = count( $children );
            if ( $children && $depth < $max_depth ) {
                $node['elements'] = self::summarize( $children, $max_depth, $depth + 1 );
            }
            $out[] = $node;
        }
        return $out;
    }
}
