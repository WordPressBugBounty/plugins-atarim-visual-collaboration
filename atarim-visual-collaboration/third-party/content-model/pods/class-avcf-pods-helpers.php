<?php
/**
 * Shared helpers for the Pods ability cluster.
 *
 * Pods exposes a stable public API, so this cluster is more robust than the
 * builder-internal ones:
 *   - definitions: pods_api()->save_pod / load_pod(s) / delete_pod,
 *                  save_field / load_field / delete_field,
 *                  save_group / delete_group
 *   - items:        pods($pod)->add / save / delete / find / fetch / export
 * Item CRUD goes through the pods() object, which abstracts storage — so it
 * covers Advanced Content Type records (custom tables) as well as CPT/taxonomy
 * pods. Not tested against a live Pods here, but built on documented APIs.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Pods_Helpers {

    /** Run a pods_api callable, normalizing exceptions/WP_Error to WP_Error. */
    public static function api_result( $cb ) {
        try {
            $r = call_user_func( $cb );
            if ( is_wp_error( $r ) ) {
                return $r;
            }
            return $r;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'pods_api_error', $e->getMessage() );
        }
    }

    /** Shape a pod definition (array or Pod object) for output. */
    public static function shape_pod( $pod ) {
        $a = is_object( $pod ) && method_exists( $pod, 'get_args' ) ? $pod->get_args() : (array) $pod;
        return [
            'id'      => isset( $a['id'] ) ? (int) $a['id'] : null,
            'name'    => isset( $a['name'] ) ? $a['name'] : '',
            'label'   => isset( $a['label'] ) ? $a['label'] : '',
            'type'    => isset( $a['type'] ) ? $a['type'] : '',
            'storage' => isset( $a['storage'] ) ? $a['storage'] : '',
            'object'  => isset( $a['object'] ) ? $a['object'] : '',
        ];
    }

    /** Shape a field definition for output. */
    public static function shape_field( $field ) {
        $a = is_object( $field ) && method_exists( $field, 'get_args' ) ? $field->get_args() : (array) $field;
        return [
            'id'    => isset( $a['id'] ) ? (int) $a['id'] : null,
            'name'  => isset( $a['name'] ) ? $a['name'] : '',
            'label' => isset( $a['label'] ) ? $a['label'] : '',
            'type'  => isset( $a['type'] ) ? $a['type'] : '',
        ];
    }

    /**
     * Build save_pod params for creating a pod of a given type. Known scalar
     * keys map to top-level args; everything else folds into options.
     */
    public static function build_pod_params( $type, $input, $top_keys ) {
        $params = [ 'type' => $type ];
        foreach ( $top_keys as $k ) {
            if ( isset( $input[ $k ] ) ) {
                $params[ $k ] = $input[ $k ];
            }
        }
        if ( isset( $input['options'] ) && is_array( $input['options'] ) ) {
            foreach ( $input['options'] as $k => $v ) {
                $params[ $k ] = $v;
            }
        }
        return $params;
    }
}
