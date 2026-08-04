<?php
/**
 * Shared helpers for the ACF ability cluster.
 *
 * Static utilities used by the field-group, post-type, taxonomy, and
 * options-page ability classes: detecting where a field group is stored
 * (DB vs PHP vs local JSON), generating valid ACF field keys, mapping the
 * "kind" argument to ACF's internal post types, gating the management APIs
 * behind ACF Pro 6.5+, and normalising records for output.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ACF_Helpers {

    /**
     * Map a public "kind" to ACF's internal post type slug.
     *
     * @param string $kind  one of 'post-type' | 'taxonomy' | 'options-page'
     * @return string|null  ACF internal post type, or null if unknown.
     */
    public static function internal_post_type( $kind ) {
        switch ( $kind ) {
            case 'post-type':
                return 'acf-post-type';
            case 'taxonomy':
                return 'acf-taxonomy';
            case 'options-page':
                return 'acf-ui-options-page';
            default:
                return null;
        }
    }

    /**
     * Storage location of a field group array.
     *
     * ACF marks PHP- and JSON-registered groups via the 'local' key; groups
     * stored in the database have no 'local' marker and a positive numeric ID.
     *
     * @param array $group  An acf_get_field_group() result.
     * @return string  'php' | 'json' | 'db'
     */
    public static function storage_mode( $group ) {
        $local = isset( $group['local'] ) ? $group['local'] : null;
        if ( $local === 'php' ) {
            return 'php';
        }
        if ( $local === 'json' ) {
            return 'json';
        }
        return 'db';
    }

    /**
     * Whether a group can be written to (only DB-stored groups are mutable).
     *
     * @param array $group
     * @return bool
     */
    public static function is_mutable( $group ) {
        return self::storage_mode( $group ) === 'db';
    }

    /**
     * Human message explaining why a non-DB group cannot be modified.
     *
     * @param array  $group
     * @param string $key
     * @return string
     */
    public static function immutable_message( $group, $key ) {
        $mode = self::storage_mode( $group );
        return sprintf(
            'Field group "%s" is registered via %s and is read-only. Only field groups stored in the database (created in the ACF admin UI or via these abilities) can be modified or deleted.',
            $key,
            $mode === 'php' ? 'PHP (acf_add_local_field_group / register code)' : 'local JSON (acf-json sync)'
        );
    }

    /**
     * Generate a valid, unique ACF field key.
     *
     * ACF field keys must be prefixed with "field_". We never let the agent
     * invent keys — any field supplied without one gets a generated key here.
     *
     * @return string
     */
    public static function generate_field_key() {
        return 'field_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 13 );
    }

    /**
     * Generate a valid, unique ACF field group key.
     *
     * @return string
     */
    public static function generate_group_key() {
        return 'group_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 13 );
    }

    /**
     * Ensure every field in a (possibly nested) fields array has a key.
     *
     * Recurses into sub_fields (repeater/group) and layouts (flexible content)
     * so complex fields are fully keyed before import.
     *
     * @param array $fields
     * @return array
     */
    public static function ensure_field_keys( $fields ) {
        if ( ! is_array( $fields ) ) {
            return [];
        }
        foreach ( $fields as $idx => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            if ( empty( $field['key'] ) ) {
                $field['key'] = self::generate_field_key();
            }
            if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                $field['sub_fields'] = self::ensure_field_keys( $field['sub_fields'] );
            }
            if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
                foreach ( $field['layouts'] as $lidx => $layout ) {
                    if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
                        $field['layouts'][ $lidx ]['sub_fields'] = self::ensure_field_keys( $layout['sub_fields'] );
                    }
                }
            }
            $fields[ $idx ] = $field;
        }
        return $fields;
    }

    /**
     * Compact summary of a field group for list views.
     *
     * @param array $group
     * @return array
     */
    public static function group_summary( $group ) {
        return [
            'key'          => isset( $group['key'] ) ? (string) $group['key'] : '',
            'title'        => isset( $group['title'] ) ? (string) $group['title'] : '',
            'active'       => isset( $group['active'] ) ? (bool) $group['active'] : true,
            'storage'      => self::storage_mode( $group ),
            'mutable'      => self::is_mutable( $group ),
            'location'     => isset( $group['location'] ) ? $group['location'] : [],
            'field_count'  => function_exists( 'acf_get_fields' ) ? count( (array) acf_get_fields( isset( $group['key'] ) ? $group['key'] : '' ) ) : 0,
        ];
    }

    /**
     * Reduce a raw ACF field array to the schema-relevant keys for output.
     *
     * Recurses into sub_fields and flexible-content layouts.
     *
     * @param array $field
     * @return array
     */
    public static function field_schema( $field ) {
        if ( ! is_array( $field ) ) {
            return [];
        }
        $out = [
            'key'      => isset( $field['key'] ) ? (string) $field['key'] : '',
            'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
            'name'     => isset( $field['name'] ) ? (string) $field['name'] : '',
            'type'     => isset( $field['type'] ) ? (string) $field['type'] : '',
            'required' => isset( $field['required'] ) ? (bool) $field['required'] : false,
        ];
        foreach ( [ 'instructions', 'default_value', 'choices', 'min', 'max', 'return_format', 'allow_null', 'multiple' ] as $opt ) {
            if ( isset( $field[ $opt ] ) ) {
                $out[ $opt ] = $field[ $opt ];
            }
        }
        if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
            $out['sub_fields'] = array_map( [ __CLASS__, 'field_schema' ], $field['sub_fields'] );
        }
        if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
            $out['layouts'] = [];
            foreach ( $field['layouts'] as $layout ) {
                $lo = [
                    'key'        => isset( $layout['key'] ) ? (string) $layout['key'] : '',
                    'name'       => isset( $layout['name'] ) ? (string) $layout['name'] : '',
                    'label'      => isset( $layout['label'] ) ? (string) $layout['label'] : '',
                ];
                if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
                    $lo['sub_fields'] = array_map( [ __CLASS__, 'field_schema' ], $layout['sub_fields'] );
                }
                $out['layouts'][] = $lo;
            }
        }
        return $out;
    }
}
