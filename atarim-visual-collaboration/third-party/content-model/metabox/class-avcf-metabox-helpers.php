<?php
/**
 * Shared helpers for the Meta Box ability cluster.
 *
 * Meta Box's UI stores its definitions as its own custom post types:
 *   - field groups     -> "meta-box"        (settings + fields in post meta)
 *   - post types        -> "mb-post-type"
 *   - taxonomies        -> "mb-taxonomy"
 *   - settings pages     -> "mb-settings-page"
 *
 * Reads here query those CPTs and flatten their post meta. Writes insert/update
 * those CPT posts and store the supplied config as post meta. NOTE: the exact
 * meta shape Meta Box Builder expects (and the MBBParser transform for field
 * groups) is version-specific and could not be validated here — authoring is
 * best-effort and must be checked against a live Meta Box install.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_MetaBox_Helpers {

    /**
     * Query a Meta Box builder CPT.
     *
     * @param string $cpt
     * @return WP_Post[]
     */
    public static function list_cpt( $cpt ) {
        return get_posts( [
            'post_type'      => $cpt,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'suppress_filters' => false,
        ] );
    }

    /**
     * Flatten a builder CPT post's meta to single values.
     *
     * @param int $post_id
     * @return array
     */
    public static function post_settings( $post_id ) {
        $raw = get_post_meta( $post_id );
        $out = [];
        if ( is_array( $raw ) ) {
            foreach ( $raw as $key => $vals ) {
                if ( strpos( $key, '_' ) === 0 ) {
                    continue; // skip protected/internal meta
                }
                $val = is_array( $vals ) && count( $vals ) === 1 ? $vals[0] : $vals;
                $maybe = is_string( $val ) ? maybe_unserialize( $val ) : $val;
                $out[ $key ] = $maybe;
            }
        }
        return $out;
    }

    /**
     * Shape a builder CPT post for output.
     *
     * @param WP_Post $post
     * @param bool    $with_settings
     * @return array
     */
    public static function shape_post( $post, $with_settings = false ) {
        $out = [
            'id'    => (int) $post->ID,
            'title' => $post->post_title,
            'slug'  => $post->post_name,
            'status'=> $post->post_status,
        ];
        if ( $with_settings ) {
            $out['settings'] = self::post_settings( $post->ID );
        }
        return $out;
    }

    /**
     * Find a builder CPT post by numeric id or by slug.
     *
     * @param string $cpt
     * @param string|int $key
     * @return WP_Post|null
     */
    public static function find_cpt( $cpt, $key ) {
        if ( is_numeric( $key ) ) {
            $p = get_post( (int) $key );
            return ( $p && $p->post_type === $cpt ) ? $p : null;
        }
        $posts = get_posts( [ 'post_type' => $cpt, 'post_status' => 'any', 'name' => sanitize_title( (string) $key ), 'numberposts' => 1 ] );
        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Insert or update a builder CPT post with config stored as post meta.
     *
     * @param string   $cpt
     * @param string   $title
     * @param array    $settings   key => value meta to store
     * @param int|null $post_id    when updating
     * @return int|WP_Error
     */
    public static function upsert_cpt( $cpt, $title, $settings, $post_id = null ) {
        $postarr = [
            'post_type'   => $cpt,
            'post_title'  => $title,
            'post_status' => 'publish',
        ];
        if ( $post_id ) {
            $postarr['ID'] = (int) $post_id;
            $id = wp_update_post( $postarr, true );
        } else {
            $id = wp_insert_post( $postarr, true );
        }
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( is_array( $settings ) ) {
            foreach ( $settings as $k => $v ) {
                if ( strpos( (string) $k, '_' ) === 0 ) {
                    continue;
                }
                update_post_meta( $id, $k, $v );
            }
        }
        return (int) $id;
    }
}
