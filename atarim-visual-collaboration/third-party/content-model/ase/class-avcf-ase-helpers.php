<?php
/**
 * Shared helpers for the ASE ability cluster.
 *
 * ASE stores its definitions as ordinary WordPress post types with config in
 * post meta:
 *   - field groups -> asenha_cfgroup  (cfgroup_fields / cfgroup_rules / cfgroup_extras)
 *   - post types    -> asenha_cpt      (slug + cpt_label_* + settings)
 *   - taxonomies     -> asenha_ctax     (slug + ctax_label_* + settings)
 * Because storage is plain wp_insert_post + post meta, reads and the
 * field-group writes are reliable; the full label-key schema for CPT/taxonomy
 * (ASE auto-generates ~31 cpt_label_* keys) is version-specific, so CPT/tax
 * authoring is best-effort. Not tested against a live ASE here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ASE_Helpers {

    public static function list_cpt( $cpt ) {
        return get_posts( [ 'post_type' => $cpt, 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
    }

    public static function find_cpt( $cpt, $key ) {
        if ( is_numeric( $key ) ) {
            $p = get_post( (int) $key );
            return ( $p && $p->post_type === $cpt ) ? $p : null;
        }
        // Match by stored slug meta first, then by post_name.
        $posts = self::list_cpt( $cpt );
        foreach ( $posts as $p ) {
            $slug = get_post_meta( $p->ID, 'cpt_slug', true );
            if ( $slug === '' ) { $slug = get_post_meta( $p->ID, 'ctax_slug', true ); }
            if ( $slug === '' ) { $slug = get_post_meta( $p->ID, 'cfgroup_slug', true ); }
            if ( (string) $slug === (string) $key || $p->post_name === sanitize_title( (string) $key ) ) {
                return $p;
            }
        }
        return null;
    }

    public static function post_meta_flat( $post_id ) {
        $raw = get_post_meta( $post_id );
        $out = [];
        if ( is_array( $raw ) ) {
            foreach ( $raw as $k => $vals ) {
                if ( strpos( $k, '_' ) === 0 ) { continue; }
                $v = is_array( $vals ) && count( $vals ) === 1 ? $vals[0] : $vals;
                $out[ $k ] = is_string( $v ) ? maybe_unserialize( $v ) : $v;
            }
        }
        return $out;
    }

    public static function shape_post( $post, $with_meta = false ) {
        $out = [ 'id' => (int) $post->ID, 'title' => $post->post_title, 'slug' => $post->post_name, 'status' => $post->post_status ];
        if ( $with_meta ) { $out['settings'] = self::post_meta_flat( $post->ID ); }
        return $out;
    }

    /**
     * Insert/update an asenha_* post and write the supplied meta map.
     *
     * @return int|WP_Error
     */
    public static function upsert( $cpt, $title, $meta, $post_id = null ) {
        $postarr = [ 'post_type' => $cpt, 'post_title' => $title, 'post_status' => 'publish' ];
        if ( $post_id ) {
            $postarr['ID'] = (int) $post_id;
            $id = wp_update_post( $postarr, true );
        } else {
            $id = wp_insert_post( $postarr, true );
        }
        if ( is_wp_error( $id ) ) { return $id; }
        if ( is_array( $meta ) ) {
            foreach ( $meta as $k => $v ) {
                if ( strpos( (string) $k, '_' ) === 0 ) { continue; }
                update_post_meta( $id, $k, $v );
            }
        }
        return (int) $id;
    }
}
