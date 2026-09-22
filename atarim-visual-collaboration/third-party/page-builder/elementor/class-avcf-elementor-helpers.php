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

    public static function deep_merge_settings( $existing, $patch ) {
        if ( ! is_array( $existing ) ) {
            return $patch;
        }
        foreach ( $patch as $key => $value ) {
            if (
                is_array( $value ) && self::is_assoc_array( $value )
                && isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && self::is_assoc_array( $existing[ $key ] )
            ) {
                $existing[ $key ] = self::deep_merge_settings( $existing[ $key ], $value );
            } else {
                $existing[ $key ] = $value;
            }
        }
        return $existing;
    }

    private static function is_assoc_array( array $arr ) {
        if ( $arr === [] ) {
            return false;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }


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
    /**
     * Whether an element tree contains any Elementor v4 atomic element. Atomic
     * elements (widgetType "e-*", or a node carrying a non-empty "styles" object)
     * must be written raw — Document::save() silently strips them.
     *
     * @param array $elements
     * @return bool
     */
    /**
     * Resolve the valid top-level setting/prop keys for an Elementor element type.
     * Returns [ 'mode' => 'atomic'|'v3'|'unknown', 'keys' => string[] ]. Atomic
     * (v4) elements expose get_props_schema(); classic (v3) widgets use
     * get_controls(). Used to flag settings keys the element does not define
     * (silently dropped writes) WITHOUT blocking the write. 'unknown' means the
     * type could not be resolved, so callers should skip validation rather than
     * warn on everything.
     *
     * @param string $type widgetType (e.g. "heading", "e-heading") or elType (e.g. "e-div-block").
     * @return array
     */
    public static function valid_setting_keys( $type ) {
        $out = [ 'mode' => 'unknown', 'keys' => [] ];
        $type = (string) $type;
        if ( $type === '' || ! class_exists( '\Elementor\Plugin' ) ) {
            return $out;
        }
        $p  = \Elementor\Plugin::$instance;
        $wm = isset( $p->widgets_manager ) ? $p->widgets_manager : null;
        $em = isset( $p->elements_manager ) ? $p->elements_manager : null;

        $obj = ( is_object( $wm ) && method_exists( $wm, 'get_widget_types' ) ) ? $wm->get_widget_types( $type ) : null;
        if ( ! is_object( $obj ) && is_object( $em ) && method_exists( $em, 'get_element_types' ) ) {
            $obj = $em->get_element_types( $type );
        }
        if ( ! is_object( $obj ) ) {
            return $out;
        }
        try {
            if ( method_exists( $obj, 'get_props_schema' ) ) {
                $class  = get_class( $obj );
                $schema = $class::get_props_schema();
                $out    = [ 'mode' => 'atomic', 'keys' => is_array( $schema ) ? array_keys( $schema ) : [] ];
            } elseif ( method_exists( $obj, 'get_controls' ) ) {
                $out = [ 'mode' => 'v3', 'keys' => array_keys( (array) $obj->get_controls() ) ];
            }
        } catch ( \Throwable $e ) {
            return [ 'mode' => 'unknown', 'keys' => [] ];
        }
        return $out;
    }

    public static function tree_has_atomic( $elements ) {
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            if ( isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) && strpos( $el['widgetType'], 'e-' ) === 0 ) {
                return true;
            }
            if ( isset( $el['styles'] ) && ! empty( $el['styles'] ) ) {
                return true;
            }
            if ( ! empty( $el['elements'] ) && self::tree_has_atomic( $el['elements'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate atomic-element setting VALUES against Elementor's own props schema.
     *
     * Returns a list of offending elements — [ { id, widgetType, keys[] } ] — or an
     * empty array when everything validates OR when the Elementor atomic API is not
     * available (in which case we never block). Only PROVIDED setting values are
     * checked (not schema defaults), using each prop type's own public validate(),
     * so this reproduces exactly the check Elementor runs at save without importing
     * its parser or re-deriving enums.
     */
    public static function atomic_settings_errors( $elements ) {
        $errors = [];
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return $errors;
        }
        self::collect_atomic_settings_errors( (array) $elements, $errors );
        return $errors;
    }

    private static function collect_atomic_settings_errors( $elements, &$errors ) {
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            $type = ( isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) ) ? $el['widgetType'] : '';
            // Only atomic (v4) elements carry a props schema to validate against;
            // they are the widgets whose widgetType begins "e-".
            if ( $type !== '' && strpos( $type, 'e-' ) === 0 && isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
                $bad = self::atomic_element_bad_keys( $type, $el['settings'] );
                if ( ! empty( $bad ) ) {
                    $errors[] = [
                        'id'         => isset( $el['id'] ) ? (string) $el['id'] : '',
                        'widgetType' => $type,
                        'keys'       => $bad,
                    ];
                }
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                self::collect_atomic_settings_errors( $el['elements'], $errors );
            }
        }
    }

    /**
     * Return the list of PROVIDED setting keys on one atomic element whose value
     * fails Elementor's own prop-type validation. Empty when all valid, or when the
     * type / schema cannot be resolved (nothing to validate against).
     */
    private static function atomic_element_bad_keys( $type, $settings ) {
        $bad = [];
        try {
            $p  = \Elementor\Plugin::$instance;
            $wm = isset( $p->widgets_manager ) ? $p->widgets_manager : null;
            $em = isset( $p->elements_manager ) ? $p->elements_manager : null;

            $obj = ( is_object( $wm ) && method_exists( $wm, 'get_widget_types' ) ) ? $wm->get_widget_types( $type ) : null;
            if ( ! is_object( $obj ) && is_object( $em ) && method_exists( $em, 'get_element_types' ) ) {
                $obj = $em->get_element_types( $type );
            }
            if ( ! is_object( $obj ) || ! method_exists( $obj, 'get_props_schema' ) ) {
                return $bad;
            }

            $class  = get_class( $obj );
            $schema = $class::get_props_schema();
            if ( ! is_array( $schema ) || empty( $schema ) ) {
                return $bad;
            }

            foreach ( (array) $settings as $key => $value ) {
                if ( ! isset( $schema[ $key ] ) ) {
                    // Unknown key: a key-shape concern handled separately by
                    // valid_setting_keys, not a value-validity one. Skip here.
                    continue;
                }
                $prop = $schema[ $key ];
                if ( is_object( $prop ) && method_exists( $prop, 'validate' ) && ! $prop->validate( $value ) ) {
                    $bad[] = (string) $key;
                }
            }
        } catch ( \Throwable $e ) {
            // Validation must never itself break a write.
            return [];
        }
        return $bad;
    }

    /**
     * Human-readable message for a write blocked by atomic_settings_errors(),
     * naming each offending element and its bad setting keys. Falls back to the
     * generic save-failure text when there are no validation errors (i.e. the
     * write failed for another reason).
     */
    public static function write_error_message( $errors, $fallback = 'Failed to save the Elementor tree.' ) {
        if ( empty( $errors ) || ! is_array( $errors ) ) {
            return $fallback;
        }
        $parts = [];
        foreach ( $errors as $e ) {
            if ( ! is_array( $e ) ) {
                continue;
            }
            $id   = isset( $e['id'] ) ? (string) $e['id'] : '';
            $type = isset( $e['widgetType'] ) ? (string) $e['widgetType'] : 'element';
            $keys = ( isset( $e['keys'] ) && is_array( $e['keys'] ) ) ? implode( ', ', $e['keys'] ) : '';
            $parts[] = $type . ( $id !== '' ? ' #' . $id : '' ) . ( $keys !== '' ? ' (' . $keys . ')' : '' );
        }
        if ( empty( $parts ) ) {
            return $fallback;
        }
        return 'Rejected before save: invalid Elementor setting value(s) that would abort a later editor Publish — '
            . implode( '; ', $parts )
            . '. Set these to values the element\'s schema allows and retry.';
    }

    public static function write_tree( $post_id, $elements, $force_raw = false, &$errors = null ) {
        // Validate atomic (v4) setting VALUES against Elementor's own props schema
        // BEFORE persisting. Atomic writes bypass Document::save() (it strips atomic
        // widgets), so without this an out-of-enum value — e.g. an e-heading tag of
        // "span" when the schema permits only h1-h6 — persists silently and renders
        // fine, then aborts the ENTIRE document save the next time a human presses
        // Publish ("Settings validation failed ... invalid_value"). Reject at write
        // time instead, naming the offending element and key, so it is fixable now
        // rather than an invisible landmine later. No-ops when the Elementor atomic
        // API is unavailable, so it can never block an otherwise-valid write.
        $errors = self::atomic_settings_errors( $elements );
        if ( ! empty( $errors ) ) {
            return false;
        }

        $template_type = get_post_meta( $post_id, '_elementor_template_type', true );
        if ( $template_type === '' ) {
            $template_type = 'wp-page';
        }

        // Atomic-widget trees must bypass Document::save() (it silently strips
        // atomic elements). Callers may pass $force_raw explicitly; we ALSO
        // auto-detect atomic elements so a normal edit/move/delete write can't
        // accidentally route through Document::save() and destroy them.
        if ( $force_raw || self::tree_has_atomic( $elements ) ) {
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
        // Also clear the per-post element render cache and page assets. Clearing
        // only the CSS leaves stale element HTML being served after an edit —
        // the front end then shows the old markup even though the write landed.
        delete_post_meta( $post_id, '_elementor_element_cache' );
        delete_post_meta( $post_id, '_elementor_page_assets' );
    }

    /**
     * Force-regenerate a single post's Elementor CSS now, so a verification read
     * reflects the edit without waiting for the next front-end view. Returns true
     * if regeneration ran.
     */
    /**
     * Force-regenerate a post's Elementor CSS and REPORT what was produced, so a
     * caller can validate it (e.g. detect CSS that came out empty or truncated —
     * the "styles drop past section N / fall back to kit defaults" failure). Does
     * a real delete + rebuild (writes now, not lazily).
     *
     * Returns [ regenerated(bool), css_bytes, css_sha1, css_status('file'|'inline'
     * |'empty'), css_empty(bool), content(string) ] on success, or
     * [ regenerated => false, reason ] on failure.
     *
     * @param int $post_id
     * @return array
     */
    public static function regenerate_post_css( $post_id ) {
        if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            return [ 'regenerated' => false, 'reason' => 'Elementor CSS class is unavailable.' ];
        }
        try {
            $css = new \Elementor\Core\Files\CSS\Post( (int) $post_id );
            $css->delete(); // drop the stale file + meta
            $css->update(); // rebuild and write now
            $content = method_exists( $css, 'get_content' ) ? (string) $css->get_content() : '';
            $status  = method_exists( $css, 'get_meta' ) ? (string) $css->get_meta( 'status' ) : '';
            return [
                'regenerated' => true,
                'css_bytes'   => strlen( $content ),
                'css_sha1'    => sha1( $content ),
                'css_status'  => $status,
                'css_empty'   => ( '' === trim( $content ) ),
                'content'     => $content,
            ];
        } catch ( \Throwable $e ) {
            return [ 'regenerated' => false, 'reason' => $e->getMessage() ];
        }
    }

    /**
     * Clear ALL Elementor generated CSS/files site-wide (regenerates lazily on
     * next view). Returns true if the files manager was reachable.
     */
    public static function purge_all_css() {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return false;
        }
        $plugin = \Elementor\Plugin::$instance;
        $files_manager = isset( $plugin->files_manager ) ? $plugin->files_manager : null;
        if ( is_object( $files_manager ) && method_exists( $files_manager, 'clear_cache' ) ) {
            $files_manager->clear_cache();
            return true;
        }
        return false;
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
    public static function summarize( $elements, $max_depth = 6, $depth = 0, &$truncated = false ) {
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
            if ( $children ) {
                if ( $depth < $max_depth ) {
                    $node['elements'] = self::summarize( $children, $max_depth, $depth + 1, $truncated );
                } else {
                    // Children exist but are beyond max_depth — flag the cut so a
                    // caller doing read -> recompose -> write knows this branch is
                    // NOT round-trippable from the summary.
                    $node['truncated'] = true;
                    $truncated = true;
                }
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Recursively count every node in an elements tree (sections, columns,
     * containers, widgets). Used as a write-back receipt to detect elements
     * dropped on save.
     */
    public static function count_nodes( $elements ) {
        $n = 0;
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            $n++;
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $n += self::count_nodes( $el['elements'] );
            }
        }
        return $n;
    }
}