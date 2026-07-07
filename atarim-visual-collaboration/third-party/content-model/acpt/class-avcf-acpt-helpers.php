<?php
/**
 * Shared helpers for the ACPT ability cluster.
 *
 * ACPT uses an ORM-style Repository + Model layer:
 *   - <X>Repository::get() / ::exists() / ::getId() / ::save($model) / ::delete()
 *   - <X>Model::hydrateFromArray([...])  builds a model from a plain array
 *   - Uuid::v4()  generates ids
 * Reads are sound. Authoring of post types / taxonomies / option pages is solid
 * (hydrateFromArray + save), though the exact array shape is version-specific.
 * Meta-group (field group) authoring nests boxes/fields and is intricate —
 * best-effort. Not tested against a live ACPT here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ACPT_Helpers {

    /** Run an ACPT callable, normalizing exceptions to WP_Error. */
    public static function api_result( $cb ) {
        try {
            $r = call_user_func( $cb );
            return is_wp_error( $r ) ? $r : $r;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'acpt_error', $e->getMessage() );
        }
    }

    public static function uuid() {
        if ( class_exists( '\ACPT\Core\Helper\Uuid' ) && method_exists( '\ACPT\Core\Helper\Uuid', 'v4' ) ) {
            return \ACPT\Core\Helper\Uuid::v4();
        }
        return wp_generate_uuid4();
    }

    /** Shape an ACPT model to an array (prefer toArray(), fall back to getters). */
    public static function shape( $model ) {
        if ( is_object( $model ) && method_exists( $model, 'toArray' ) ) {
            try {
                return (array) $model->toArray();
            } catch ( \Throwable $e ) {
                // fall through to getters
            }
        }
        $out = [];
        if ( is_object( $model ) ) {
            foreach ( [ 'getId' => 'id', 'getName' => 'name', 'getLabel' => 'label', 'getSingular' => 'singular', 'getPlural' => 'plural' ] as $getter => $key ) {
                if ( method_exists( $model, $getter ) ) {
                    try { $out[ $key ] = $model->$getter(); } catch ( \Throwable $e ) {}
                }
            }
            return $out;
        }
        return (array) $model;
    }
}
