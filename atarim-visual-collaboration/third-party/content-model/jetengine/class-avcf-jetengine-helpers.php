<?php
/**
 * Shared helpers for the JetEngine ability cluster.
 *
 * JetEngine keeps its definitions in "data" objects that share a common base
 * (get_items / create_item / update_item / delete_item):
 *   - CCT types     -> CCT module manager's data store (also handles table DDL)
 *   - meta boxes     -> jet_engine()->meta_boxes->data
 *   - options pages  -> jet_engine()->options_pages->data
 * CCT *records* live in per-type custom tables (wp_jet_cct_<slug>), reached
 * through the type's Factory->get_db() handler.
 *
 * None of this could be tested against a live JetEngine here. Reads are sound;
 * authoring goes through JetEngine's own data layer (architecturally correct
 * but unverified); record CRUD depends on resolving the Factory, which is
 * intricate — treat as best-effort pending live validation.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_JetEngine_Helpers {

    /** jet_engine() instance or null. */
    public static function je() {
        return function_exists( 'jet_engine' ) ? jet_engine() : null;
    }

    /** The CCT module object, or null. */
    public static function cct_module() {
        $je = self::je();
        if ( ! $je || ! isset( $je->modules ) || ! method_exists( $je->modules, 'get_module' ) ) {
            return null;
        }
        return $je->modules->get_module( 'custom-content-types' );
    }

    /** CCT types data store (get_items / create_item / update_item / delete_item). */
    public static function cct_data() {
        $module = self::cct_module();
        if ( ! $module ) {
            return null;
        }
        $manager = isset( $module->manager ) ? $module->manager : ( isset( $module->instance->manager ) ? $module->instance->manager : null );
        if ( $manager && isset( $manager->data ) ) {
            return $manager->data;
        }
        return null;
    }

    public static function meta_boxes_data() {
        $je = self::je();
        return ( $je && isset( $je->meta_boxes ) && isset( $je->meta_boxes->data ) ) ? $je->meta_boxes->data : null;
    }

    public static function options_pages_data() {
        $je = self::je();
        return ( $je && isset( $je->options_pages ) && isset( $je->options_pages->data ) ) ? $je->options_pages->data : null;
    }

    /** Read items from a JetEngine data store as an array. */
    public static function store_items( $store ) {
        if ( ! $store || ! method_exists( $store, 'get_items' ) ) {
            return [];
        }
        $items = $store->get_items();
        return is_array( $items ) ? $items : (array) $items;
    }

    /**
     * Create/update/delete via a JetEngine data store. The base data class
     * reads from the request by default; passing $from_request=false uses the
     * provided item array. Returns the store result or a WP_Error.
     */
    public static function store_create( $store, $item ) {
        if ( ! $store || ! method_exists( $store, 'create_item' ) ) {
            return new WP_Error( 'no_create', 'This JetEngine data store does not expose create_item().' );
        }
        try {
            return $store->create_item( false, $item );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'create_failed', $e->getMessage() );
        }
    }
    public static function store_update( $store, $item ) {
        if ( ! $store || ! method_exists( $store, 'update_item' ) ) {
            return new WP_Error( 'no_update', 'This JetEngine data store does not expose update_item().' );
        }
        try {
            return $store->update_item( false, $item );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'update_failed', $e->getMessage() );
        }
    }
    public static function store_delete( $store, $id ) {
        if ( ! $store || ! method_exists( $store, 'delete_item' ) ) {
            return new WP_Error( 'no_delete', 'This JetEngine data store does not expose delete_item().' );
        }
        try {
            return $store->delete_item( false, $id );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'delete_failed', $e->getMessage() );
        }
    }

    /**
     * Resolve a CCT Factory by slug (best-effort across known manager APIs).
     * Record CRUD depends on this. Returns object|null.
     */
    public static function cct_factory( $slug ) {
        $module  = self::cct_module();
        if ( ! $module ) {
            return null;
        }
        $manager = isset( $module->manager ) ? $module->manager : null;
        if ( ! $manager ) {
            return null;
        }
        try {
            if ( method_exists( $manager, 'get_content_types' ) ) {
                $types = $manager->get_content_types();
                if ( is_array( $types ) && isset( $types[ $slug ] ) ) {
                    return $types[ $slug ];
                }
            }
            if ( method_exists( $manager, 'get_factory_instances' ) ) {
                $factories = $manager->get_factory_instances();
                if ( is_array( $factories ) && isset( $factories[ $slug ] ) ) {
                    return $factories[ $slug ];
                }
            }
            if ( method_exists( $manager, 'get_content_type_for_slug' ) ) {
                return $manager->get_content_type_for_slug( $slug );
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        return null;
    }

    /** Get the record DB handler for a CCT slug, or null. */
    public static function cct_db( $slug ) {
        $factory = self::cct_factory( $slug );
        if ( $factory && method_exists( $factory, 'get_db' ) ) {
            try {
                return $factory->get_db();
            } catch ( \Throwable $e ) {
                return null;
            }
        }
        return null;
    }

    /** Shape a CCT type item for output. */
    public static function shape_cct( $item ) {
        $a = (array) $item;
        return [
            'id'     => isset( $a['id'] ) ? $a['id'] : null,
            'slug'   => isset( $a['slug'] ) ? $a['slug'] : '',
            'name'   => isset( $a['name'] ) ? $a['name'] : '',
            'args'   => isset( $a['args'] ) ? $a['args'] : null,
            'fields' => isset( $a['meta_fields'] ) ? $a['meta_fields'] : ( isset( $a['fields'] ) ? $a['fields'] : null ),
        ];
    }
}
