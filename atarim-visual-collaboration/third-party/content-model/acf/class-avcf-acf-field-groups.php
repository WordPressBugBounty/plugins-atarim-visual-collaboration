<?php
/**
 * ACF — Field Group MCP abilities.
 *
 * Manages ACF field group *structure* (the groups and their fields), not the
 * per-post values — value read/write is handled by the generic metadata
 * abilities (atarim/get-post-field, atarim/update-post-field) which already
 * route through ACF. This cluster is the authoring layer: introspect what
 * fields exist and create/modify the definitions themselves.
 *
 * All writes go through acf_import_field_group(), which upserts by key, so
 * ACF's own validation, key handling, and cache invalidation run as expected.
 * Only database-stored groups are mutable; PHP- and JSON-registered groups
 * are reported read-only (see AVCF_ACF_Helpers::is_mutable()).
 *
 * Exposed abilities:
 *   atarim/list-acf-field-groups   Enumerate groups (summary + storage mode).
 *   atarim/get-acf-field-group     Full schema for one group incl. nested fields.
 *   atarim/create-acf-field-group  New DB-stored group with fields + location.
 *   atarim/update-acf-field-group  Patch title/active/location/fields of a DB group.
 *   atarim/delete-acf-field-group  Delete a DB group and cascade its fields.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_ACF_Field_Groups extends AVCF_Abilities_Base {

    /**
     * @var AVCF_ACF_Detector
     */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_ACF_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_acf_is_available() ) {
            return;
        }

        // ---- list-acf-field-groups ----
        wp_register_ability( 'atarim/list-acf-field-groups', [
            'label'               => 'List ACF Field Groups',
            'description'         => 'Enumerate all ACF field groups on the site. Returns a summary per group: key, title, active state, storage mode (db / php / json), whether it is mutable (only db groups can be edited or deleted via these abilities), location rules, and field count. Use get-acf-field-group for the full field schema of one group.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'active_only' => [
                        'type'        => 'boolean',
                        'description' => 'Only return active field groups. Defaults to false (all groups).',
                        'default'     => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'total'   => [ 'type' => 'integer' ],
                    'groups'  => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'acf_get_field_groups' ) ) {
                    return [ 'success' => false, 'message' => 'ACF is not available.' ];
                }
                $active_only = ! empty( $input['active_only'] );
                $groups      = acf_get_field_groups();
                $out         = [];
                foreach ( (array) $groups as $group ) {
                    if ( $active_only && isset( $group['active'] ) && ! $group['active'] ) {
                        continue;
                    }
                    $out[] = AVCF_ACF_Helpers::group_summary( $group );
                }
                return [
                    'success' => true,
                    'total'   => count( $out ),
                    'groups'  => $out,
                    'message' => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-acf-field-group ----
        wp_register_ability( 'atarim/get-acf-field-group', [
            'label'               => 'Get ACF Field Group',
            'description'         => 'Full schema for one field group by key (e.g. "group_5f8a1b2c3d4e5"): title, active, location rules, settings, and the complete field tree — including nested sub_fields (repeater/group) and flexible-content layouts. Call this before update-acf-field-group so you know the existing field keys to preserve.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'key' => [
                        'type'        => 'string',
                        'description' => 'Field group key, as returned by list-acf-field-groups (starts with "group_").',
                        'pattern'     => '^group_',
                    ],
                ],
                'required'             => [ 'key' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'group'   => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'acf_get_field_group' ) ) {
                    return [ 'success' => false, 'message' => 'ACF is not available.' ];
                }
                $key   = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $key === '' ) {
                    return [ 'success' => false, 'message' => 'key is required.' ];
                }
                $group = acf_get_field_group( $key );
                if ( ! $group ) {
                    return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $key ) ];
                }
                $fields = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $key ) : [];
                return [
                    'success' => true,
                    'group'   => [
                        'key'      => isset( $group['key'] ) ? (string) $group['key'] : $key,
                        'title'    => isset( $group['title'] ) ? (string) $group['title'] : '',
                        'active'   => isset( $group['active'] ) ? (bool) $group['active'] : true,
                        'storage'  => AVCF_ACF_Helpers::storage_mode( $group ),
                        'mutable'  => AVCF_ACF_Helpers::is_mutable( $group ),
                        'location' => isset( $group['location'] ) ? $group['location'] : [],
                        'menu_order'   => isset( $group['menu_order'] ) ? (int) $group['menu_order'] : 0,
                        'position'     => isset( $group['position'] ) ? (string) $group['position'] : 'normal',
                        'style'        => isset( $group['style'] ) ? (string) $group['style'] : 'default',
                        'fields'   => array_map( [ 'AVCF_ACF_Helpers', 'field_schema' ], $fields ),
                    ],
                    'message' => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- create-acf-field-group ----
        wp_register_ability( 'atarim/create-acf-field-group', [
            'label'               => 'Create ACF Field Group',
            'description'         => 'Create a new database-stored ACF field group with fields and location rules. Field keys are generated automatically if omitted — never invent "field_" keys yourself. The group key is generated automatically. Location rules use ACF\'s standard nested structure: an array of OR-groups, each an array of AND-rules like {"param":"post_type","operator":"==","value":"page"}. Complex field types (repeater, group, flexible_content) require ACF Pro; their sub_fields/layouts are keyed recursively.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Human-readable field group title (e.g. "Page Hero").',
                        'minLength'   => 1,
                    ],
                    'fields' => [
                        'type'        => 'array',
                        'description' => 'Array of ACF field definitions. Each needs at least "label", "name", and "type". Keys are auto-generated. Repeater/group fields carry "sub_fields"; flexible_content carries "layouts".',
                    ],
                    'location' => [
                        'type'        => 'array',
                        'description' => 'ACF location rule groups (array of arrays of {param, operator, value}). Defaults to no location (group registered but not attached) if omitted.',
                    ],
                    'active' => [
                        'type'        => 'boolean',
                        'description' => 'Whether the group is active. Defaults to true.',
                        'default'     => true,
                    ],
                ],
                'required'             => [ 'title' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'key'     => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'acf_import_field_group' ) ) {
                    return [ 'success' => false, 'message' => 'ACF is not available.' ];
                }
                $title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';
                if ( $title === '' ) {
                    return [ 'success' => false, 'message' => 'title is required.' ];
                }
                $fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : [];
                $fields = AVCF_ACF_Helpers::ensure_field_keys( $fields );

                $payload = [
                    'key'      => AVCF_ACF_Helpers::generate_group_key(),
                    'title'    => $title,
                    'fields'   => $fields,
                    'location' => isset( $input['location'] ) && is_array( $input['location'] ) ? $input['location'] : [],
                    'active'   => isset( $input['active'] ) ? (bool) $input['active'] : true,
                ];

                $result = acf_import_field_group( $payload );
                if ( ! is_array( $result ) || empty( $result['key'] ) ) {
                    return [ 'success' => false, 'message' => 'ACF reported no key after import; create may have failed.' ];
                }
                return [
                    'success' => true,
                    'key'     => (string) $result['key'],
                    'message' => sprintf( 'Created field group "%s".', $title ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- update-acf-field-group ----
        wp_register_ability( 'atarim/update-acf-field-group', [
            'label'               => 'Update ACF Field Group',
            'description'         => 'Patch an existing database-stored field group. Supply only the keys you want to change (title, active, location, fields). IMPORTANT: if you pass "fields", it REPLACES the entire field set — call get-acf-field-group first, modify the returned fields array, and pass it back whole, preserving existing "key" values so ACF updates fields in place rather than recreating them. Refuses on php/json-registered groups (they are read-only).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'key' => [
                        'type'        => 'string',
                        'description' => 'Field group key to update (starts with "group_").',
                        'pattern'     => '^group_',
                    ],
                    'title'    => [ 'type' => 'string', 'description' => 'New title.' ],
                    'active'   => [ 'type' => 'boolean', 'description' => 'New active state.' ],
                    'location' => [ 'type' => 'array', 'description' => 'Replacement location rule groups.' ],
                    'fields'   => [ 'type' => 'array', 'description' => 'Replacement field set (whole). Preserve existing keys to update in place.' ],
                ],
                'required'             => [ 'key' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'key'     => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'acf_get_field_group' ) || ! function_exists( 'acf_import_field_group' ) ) {
                    return [ 'success' => false, 'message' => 'ACF is not available.' ];
                }
                $key = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $key === '' ) {
                    return [ 'success' => false, 'message' => 'key is required.' ];
                }
                $existing = acf_get_field_group( $key );
                if ( ! $existing ) {
                    return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $key ) ];
                }
                if ( ! AVCF_ACF_Helpers::is_mutable( $existing ) ) {
                    return [ 'success' => false, 'message' => AVCF_ACF_Helpers::immutable_message( $existing, $key ) ];
                }

                // Start from the existing group; acf_import_field_group replaces
                // wholesale, so unspecified keys must be carried forward.
                $payload = $existing;
                if ( isset( $input['title'] ) ) {
                    $payload['title'] = (string) $input['title'];
                }
                if ( isset( $input['active'] ) ) {
                    $payload['active'] = (bool) $input['active'];
                }
                if ( isset( $input['location'] ) && is_array( $input['location'] ) ) {
                    $payload['location'] = $input['location'];
                }
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
                    $payload['fields'] = AVCF_ACF_Helpers::ensure_field_keys( $input['fields'] );
                } else {
                    // Preserve current fields when not replacing them.
                    $payload['fields'] = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $key ) : [];
                }

                $result = acf_import_field_group( $payload );
                if ( ! is_array( $result ) || empty( $result['key'] ) ) {
                    return [ 'success' => false, 'message' => 'ACF reported no key after import; update may have failed.' ];
                }
                return [
                    'success' => true,
                    'key'     => (string) $result['key'],
                    'message' => sprintf( 'Updated field group "%s".', $key ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- delete-acf-field-group ----
        wp_register_ability( 'atarim/delete-acf-field-group', [
            'label'               => 'Delete ACF Field Group',
            'description'         => 'Delete a database-stored field group and its fields. This removes the field definitions only — existing post meta values written under those fields remain in the database untouched. Refuses on php/json-registered groups (read-only). Irreversible.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'key' => [
                        'type'        => 'string',
                        'description' => 'Field group key to delete (starts with "group_").',
                        'pattern'     => '^group_',
                    ],
                    'confirm' => [
                        'type'        => 'boolean',
                        'description' => 'Must be true to actually delete. Defaults to false (a dry run that reports what would be deleted).',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'key' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'deleted' => [ 'type' => 'boolean' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'acf_get_field_group' ) || ! function_exists( 'acf_delete_field_group' ) ) {
                    return [ 'success' => false, 'message' => 'ACF is not available.' ];
                }
                $key = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $key === '' ) {
                    return [ 'success' => false, 'message' => 'key is required.' ];
                }
                $existing = acf_get_field_group( $key );
                if ( ! $existing ) {
                    return [ 'success' => false, 'message' => sprintf( 'Field group "%s" not found.', $key ) ];
                }
                if ( ! AVCF_ACF_Helpers::is_mutable( $existing ) ) {
                    return [ 'success' => false, 'message' => AVCF_ACF_Helpers::immutable_message( $existing, $key ) ];
                }
                $title = isset( $existing['title'] ) ? (string) $existing['title'] : $key;
                if ( empty( $input['confirm'] ) ) {
                    return [
                        'success' => true,
                        'deleted' => false,
                        'message' => sprintf( 'Dry run: would delete field group "%s" (%s). Re-call with confirm:true to delete.', $title, $key ),
                    ];
                }
                $deleted = acf_delete_field_group( $key );
                return [
                    'success' => (bool) $deleted,
                    'deleted' => (bool) $deleted,
                    'message' => $deleted
                        ? sprintf( 'Deleted field group "%s".', $title )
                        : sprintf( 'ACF did not confirm deletion of "%s".', $key ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }
}
