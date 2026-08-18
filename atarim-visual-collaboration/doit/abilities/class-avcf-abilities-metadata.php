<?php
/**
 * Custom fields, options, and user meta MCP abilities.
 *
 * Registers Atarim/* abilities for working with WordPress's various
 * metadata stores. Unifies four backends behind a consistent surface:
 *
 *   1. Raw post_meta (any plugin / theme using add_post_meta)
 *   2. ACF (Advanced Custom Fields) — with field-key sentinel pairing
 *   3. Toolset Types — wpcf- prefix convention
 *   4. Meta Box — registry-walk detection
 *
 * Reads on post fields include a "type" hint telling the caller which
 * backend the field belongs to. Writes accept the type hint back (or
 * auto-detect when omitted) and route through the right API so that
 * framework-specific hooks, formatting, and field-key references stay
 * intact.
 *
 * Pods is intentionally not supported in this round — its custom-table
 * storage model is substantially more work than meta-backed frameworks
 * and deserves a focused PR.
 *
 * Exposed abilities:
 *   atarim/get-post-field         Read post fields with type detection; optional all-fields mode.
 *   atarim/update-post-field      Write a post field via the detected (or specified) backend.
 *   atarim/bulk-update-post-field Bulk write across many posts, same value or per-item values.
 *   atarim/get-option             Read from wp_options.
 *   atarim/update-option          Write to wp_options with critical-option blocklist.
 *   atarim/get-user-meta          Read user meta keys.
 *   atarim/update-user-meta       Write a single user meta key/value.
 *
 * Note: ability names registered here must also be added to the $tools
 * array in doit/class-avcf-mcp.php::avcf_mcp_setup_server() to be exposed
 * by the MCP server.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Metadata extends AVCF_Abilities_Base {

    /**
     * Options that must NEVER be written via update-option. Each entry
     * names why and (where applicable) the right ability to use instead.
     */
    private $option_blocklist = [
        'siteurl'                => 'Use atarim/update-general-settings (validates URL format).',
        'home'                   => 'Use atarim/update-general-settings (validates URL format).',
        'admin_email'            => 'Use atarim/update-general-settings (handles WordPress verification flow).',
        'active_plugins'         => 'Use atarim/activate-plugin and atarim/deactivate-plugin (runs activation hooks).',
        'active_sitewide_plugins'=> 'Use atarim/activate-plugin and atarim/deactivate-plugin (runs activation hooks).',
        'template'               => 'Use atarim/activate-theme (validates theme exists and runs switch hooks).',
        'stylesheet'             => 'Use atarim/activate-theme (validates theme exists and runs switch hooks).',
        'current_theme'          => 'Use atarim/activate-theme (validates theme exists and runs switch hooks).',
        'db_version'             => 'Internal WordPress version tracker — changing this manually breaks upgrades.',
        'WPLANG'                 => 'Use atarim/update-general-settings (validates locale).',
        'cron'                   => 'Use wp_schedule_event / wp_unschedule_event APIs instead.',
        'permalink_structure'    => 'Use atarim/update-permalink-settings (flushes rewrite rules).',
        'default_role'           => 'Privilege-escalation vector: the role assigned to new users. Change roles through a deliberate, reviewed workflow, not a generic option write.',
        'users_can_register'     => 'Security-sensitive: open registration combined with default_role is a takeover vector. Change via general settings deliberately.',
        'mailserver_url'         => 'Email-interception vector; not writable through the generic option tool.',
        'mailserver_login'       => 'Email-interception vector; not writable through the generic option tool.',
        'mailserver_pass'        => 'Email-interception vector; not writable through the generic option tool.',
        'mailserver_port'        => 'Email-interception vector; not writable through the generic option tool.',
    ];

    /**
     * Reason a raw option write is blocked, or null if allowed. Covers the static
     * infrastructure/security blocklist above plus the prefix-dependent
     * "{$prefix}user_roles" role-to-capability map, which cannot be a static key
     * because the table prefix varies per site. Editing user_roles can grant
     * administrator capabilities, so it is a privilege-escalation vector.
     *
     * @param string $name Option name.
     * @return string|null
     */
    private function avcf_blocked_option_reason( $name ) {
        global $wpdb;
        if ( isset( $this->option_blocklist[ $name ] ) ) {
            return $this->option_blocklist[ $name ];
        }
        if ( isset( $wpdb ) && is_object( $wpdb ) && $name === $wpdb->prefix . 'user_roles' ) {
            return 'This is the role-to-capability map; editing it can grant administrator capabilities. Manage roles through a dedicated, reviewed workflow.';
        }
        return null;
    }

    public function register() {

        // ---- get-post-field ----
        wp_register_ability( 'atarim/get-post-field', [
            'label'               => 'Get Post Field(s)',
            'description'         => 'Reads one or more custom fields (post meta) from a post, returning each with a "type" hint indicating which backend owns it: "acf" (Advanced Custom Fields), "toolset" (Toolset Types), "meta_box" (Meta Box plugin), or "raw" (generic post_meta or unknown framework). The type hint is what you pass back to update-post-field to route writes correctly. Omit the "fields" parameter to return ALL meta on the post (excluding keys starting with "_" by default; pass include_private: true to include those). All-fields mode caps the response at 100 entries with truncated: true if there are more.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID.',
                        'minimum'     => 1,
                    ],
                    'fields' => [
                        'type'        => 'array',
                        'description' => 'Specific field/meta keys to read. Omit to return all fields on the post.',
                        'items'       => [ 'type' => 'string', 'minLength' => 1 ],
                        'minItems'    => 1,
                    ],
                    'include_private' => [
                        'type'        => 'boolean',
                        'description' => 'In all-fields mode, include meta keys starting with "_" (WordPress private/internal convention). Defaults to false. Ignored when "fields" is specified — keys explicitly listed are always returned.',
                        'default'     => false,
                    ],
                    'format' => [
                        'type'        => 'string',
                        'description' => 'For ACF fields only: "raw" returns the stored database value (e.g. attachment ID); "formatted" returns ACF\'s post-processed value (e.g. full image array). Defaults to "raw" so AI sees ground truth.',
                        'enum'        => [ 'raw', 'formatted' ],
                        'default'     => 'raw',
                    ],
                ],
                'required'             => [ 'post_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'post_id'    => [ 'type' => 'integer' ],
                    'mode'       => [ 'type' => 'string' ],
                    'count'      => [ 'type' => 'integer' ],
                    'truncated'  => [ 'type' => 'boolean' ],
                    'total_keys' => [ 'type' => 'integer' ],
                    'fields'     => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'key'   => [ 'type' => 'string' ],
                                'value' => [],
                                'type'  => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 ) {
                    return [ 'success' => false, 'message' => 'post_id is required and must be a positive integer.' ];
                }

                $post = get_post( $post_id );
                if ( ! $post ) {
                    return [ 'success' => false, 'message' => sprintf( 'Post %d not found.', $post_id ) ];
                }

                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->read_post, $post_id ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'You do not have permission to read this %s.', $post->post_type ) ];
                }

                $format = isset( $input['format'] ) && $input['format'] === 'formatted' ? 'formatted' : 'raw';

                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) && ! empty( $input['fields'] ) ) {
                    // Targeted read.
                    $keys = array_values( array_filter( array_map( 'strval', $input['fields'] ) ) );
                    $fields = [];
                    foreach ( $keys as $key ) {
                        $detected_type = $this->avcf_detect_meta_type( $post_id, $key, null );
                        $value = $this->avcf_read_field_value( $post_id, $key, $detected_type, $format );
                        $fields[] = [
                            'key'   => $key,
                            'value' => $value,
                            'type'  => $detected_type,
                        ];
                    }
                    return [
                        'success' => true,
                        'post_id' => $post_id,
                        'mode'    => 'targeted',
                        'count'   => count( $fields ),
                        'fields'  => $fields,
                        'message' => sprintf( 'Read %d field(s) from post %d.', count( $fields ), $post_id ),
                    ];
                }

                // All-fields mode.
                $include_private = ! empty( $input['include_private'] );
                $all_meta        = get_post_meta( $post_id );
                if ( ! is_array( $all_meta ) ) {
                    $all_meta = [];
                }

                $all_keys = array_keys( $all_meta );
                if ( ! $include_private ) {
                    $all_keys = array_values( array_filter( $all_keys, function( $k ) {
                        return strpos( $k, '_' ) !== 0;
                    } ) );
                }

                $total_keys = count( $all_keys );
                $cap        = 100;
                $truncated  = $total_keys > $cap;
                $work_keys  = $truncated ? array_slice( $all_keys, 0, $cap ) : $all_keys;

                $fields = [];
                foreach ( $work_keys as $key ) {
                    $detected_type = $this->avcf_detect_meta_type( $post_id, $key, null );
                    $value         = $this->avcf_read_field_value( $post_id, $key, $detected_type, $format );
                    $fields[] = [
                        'key'   => $key,
                        'value' => $value,
                        'type'  => $detected_type,
                    ];
                }

                $msg = sprintf( 'Read %d of %d field(s) from post %d.', count( $fields ), $total_keys, $post_id );
                if ( $truncated ) {
                    $msg .= ' Response truncated to 100 fields — pass specific fields in the "fields" parameter to read more.';
                }

                return [
                    'success'    => true,
                    'post_id'    => $post_id,
                    'mode'       => 'all',
                    'count'      => count( $fields ),
                    'truncated'  => $truncated,
                    'total_keys' => $total_keys,
                    'fields'     => $fields,
                    'message'    => $msg,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-post-field ----
        wp_register_ability( 'atarim/update-post-field', [
            'label'               => 'Update Post Field',
            'description'         => 'Writes a single custom field (post meta) on a post. By default auto-detects which backend owns the field — ACF, Toolset Types, Meta Box, or raw post_meta — and routes the write through that framework\'s API so its hooks, formatting, and field-key references stay intact. Pass the "type" parameter to force a specific backend (acts as an escape hatch): "acf" routes via update_field(), "toolset" via raw post_meta (Toolset stores as post_meta), "meta_box" via rwmb_set_meta() if available, "raw" via update_post_meta() unconditionally. The caller\'s type is trusted without re-verification.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID.',
                        'minimum'     => 1,
                    ],
                    'field' => [
                        'type'        => 'string',
                        'description' => 'Field name (for ACF, the field name or field key like field_5f8a1b2c3d4e5; for others, the meta key).',
                        'minLength'   => 1,
                    ],
                    'value' => [
                        'description' => 'New value. Type depends on the field — strings, numbers, booleans, arrays, and objects are all accepted.',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Force the write backend. Omit to auto-detect. Caller\'s value is trusted.',
                        'enum'        => [ 'acf', 'toolset', 'meta_box', 'raw' ],
                    ],
                ],
                'required'             => [ 'post_id', 'field', 'value' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'post_id'        => [ 'type' => 'integer' ],
                    'field'          => [ 'type' => 'string' ],
                    'type'           => [ 'type' => 'string' ],
                    'type_source'    => [ 'type' => 'string' ],
                    'new_value'      => [],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                $field   = isset( $input['field'] ) ? (string) $input['field'] : '';
                if ( $post_id <= 0 || $field === '' ) {
                    return [ 'success' => false, 'message' => 'post_id and field are required.' ];
                }
                if ( ! array_key_exists( 'value', $input ) ) {
                    return [ 'success' => false, 'message' => 'value is required.' ];
                }

                $post = get_post( $post_id );
                if ( ! $post ) {
                    return [ 'success' => false, 'message' => sprintf( 'Post %d not found.', $post_id ) ];
                }

                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $post_id ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'You do not have permission to edit this %s.', $post->post_type ) ];
                }

                $value = $input['value'];

                // Security guard: refuse traversal/absolute values on path-bearing
                // internal meta (e.g. _wp_attached_file) that downstream abilities
                // resolve to a filesystem path for deletion/overwrite.
                $unsafe_meta = $this->avcf_reject_unsafe_meta_write( $field, $value );
                if ( null !== $unsafe_meta ) {
                    return [
                        'success' => false,
                        'post_id' => $post_id,
                        'field'   => $field,
                        'message' => $unsafe_meta,
                    ];
                }

                // Guard: _elementor_data holds a JSON STRING (an array of elements).
                // A malformed or non-JSON write silently corrupts the whole page
                // (Elementor fails to parse it), so validate before writing. For
                // structured edits prefer elementor-edit-element / -apply-operations,
                // which patch the tree without hand-writing raw JSON.
                if ( '_elementor_data' === $field ) {
                    if ( ! is_string( $value ) ) {
                        return [
                            'success' => false,
                            'post_id' => $post_id,
                            'field'   => $field,
                            'message' => '_elementor_data must be written as a JSON string, not a structured/array value. Pass the JSON as a string, or use elementor-edit-element / elementor-apply-operations for targeted structured edits.',
                        ];
                    }
                    $decoded = json_decode( $value, true );
                    if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
                        return [
                            'success' => false,
                            'post_id' => $post_id,
                            'field'   => $field,
                            'message' => sprintf( 'Refused: the value for _elementor_data is not valid JSON (%s). Writing malformed JSON would corrupt the Elementor page. Fix the JSON, or use elementor-edit-element / elementor-apply-operations for targeted edits.', json_last_error_msg() ),
                        ];
                    }
                    if ( ! is_array( $decoded ) ) {
                        return [
                            'success' => false,
                            'post_id' => $post_id,
                            'field'   => $field,
                            'message' => 'Refused: _elementor_data must be a JSON array of Elementor elements. The provided JSON does not decode to an array.',
                        ];
                    }
                }

                // Determine backend: caller-provided type, or auto-detect.
                $type_source = 'auto';
                if ( isset( $input['type'] ) && in_array( $input['type'], [ 'acf', 'toolset', 'meta_box', 'raw' ], true ) ) {
                    $type        = (string) $input['type'];
                    $type_source = 'caller';
                } else {
                    $type = $this->avcf_detect_meta_type( $post_id, $field, $value );
                }

                // Capture the prior stored value so we can report whether the write
                // actually changed anything (a "success" that is really a no-op).
                $before_value = $this->avcf_read_field_value( $post_id, $field, $type, 'raw' );

                $write_result = $this->avcf_write_field_value( $post_id, $field, $value, $type );
                if ( $write_result['error'] !== null ) {
                    return [
                        'success'     => false,
                        'post_id'     => $post_id,
                        'field'       => $field,
                        'type'        => $type,
                        'type_source' => $type_source,
                        'message'     => 'Write failed: ' . $write_result['error'],
                    ];
                }

                // Read back to confirm — uses raw format so AI sees ground truth.
                $confirmed_value = $this->avcf_read_field_value( $post_id, $field, $type, 'raw' );

                // Write receipt: compare requested vs stored vs prior so a silent
                // no-op or a coerced value is visible instead of a bare success.
                $req_json     = wp_json_encode( $value );
                $stored_json  = wp_json_encode( $confirmed_value );
                $before_json  = wp_json_encode( $before_value );
                $changed      = ( $stored_json !== $before_json );
                if ( $stored_json === $req_json ) {
                    $status = 'applied';
                } elseif ( $stored_json === $before_json ) {
                    $status = 'ignored'; // stored value did not change — the write had no effect
                } else {
                    $status = 'coerced'; // backend stored a transformed value (see new_value)
                }

                $message = sprintf( 'Field "%s" updated on post %d via %s backend (%s).', $field, $post_id, $type, $type_source === 'caller' ? 'caller-specified' : 'auto-detected' );
                if ( 'ignored' === $status ) {
                    $message = sprintf( 'WARNING: field "%s" on post %d was NOT changed — the stored value is unchanged after the write (possible silent no-op). Backend: %s (%s).', $field, $post_id, $type, $type_source === 'caller' ? 'caller-specified' : 'auto-detected' );
                } elseif ( 'coerced' === $status ) {
                    $message = sprintf( 'NOTE: field "%s" on post %d was stored with a transformed value (see new_value), which differs from the value sent. Backend: %s (%s).', $field, $post_id, $type, $type_source === 'caller' ? 'caller-specified' : 'auto-detected' );
                }

                return [
                    'success'     => true,
                    'post_id'     => $post_id,
                    'field'       => $field,
                    'type'        => $type,
                    'type_source' => $type_source,
                    'new_value'   => $confirmed_value,
                    'changed'     => $changed,
                    'status'      => $status,
                    'message'     => $message,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- bulk-update-post-field ----
        wp_register_ability( 'atarim/bulk-update-post-field', [
            'label'               => 'Bulk Update Post Field',
            'description'         => 'Writes a single field across many posts. Two modes via input variance: (1) "post_ids" + "value" applies the SAME value to every listed post; (2) "items" with [{post_id, value}, ...] applies per-post values. Pass exactly one of post_ids or items. Optional "type" parameter applies the same backend to every write (acf/toolset/meta_box/raw); omit to auto-detect per post. Per-id success tracking — partial failures surface in the results array with their own messages. Max 500 items per call.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'field' => [
                        'type'        => 'string',
                        'description' => 'Field name to update on every targeted post.',
                        'minLength'   => 1,
                    ],
                    'value' => [
                        'description' => 'Single value for same-value-to-many mode. Required when post_ids is used; ignored when items is used.',
                    ],
                    'post_ids' => [
                        'type'        => 'array',
                        'description' => 'Same-value mode: post IDs to update with the single "value". Mutually exclusive with items.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                        'minItems'    => 1,
                        'maxItems'    => 500,
                    ],
                    'items' => [
                        'type'        => 'array',
                        'description' => 'Per-item mode: array of {post_id, value} objects. Mutually exclusive with post_ids.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                                'value'   => [],
                            ],
                            'required'             => [ 'post_id', 'value' ],
                            'additionalProperties' => false,
                        ],
                        'minItems'    => 1,
                        'maxItems'    => 500,
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Force the write backend for ALL items. Omit to auto-detect per post.',
                        'enum'        => [ 'acf', 'toolset', 'meta_box', 'raw' ],
                    ],
                ],
                'required'             => [ 'field' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'mode'      => [ 'type' => 'string' ],
                    'attempted' => [ 'type' => 'integer' ],
                    'updated'   => [ 'type' => 'integer' ],
                    'failed'    => [ 'type' => 'integer' ],
                    'results'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'post_id' => [ 'type' => 'integer' ],
                                'success' => [ 'type' => 'boolean' ],
                                'type'    => [ 'type' => 'string' ],
                                'message' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'attempted', 'updated', 'failed', 'results', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $field = isset( $input['field'] ) ? (string) $input['field'] : '';
                if ( $field === '' ) {
                    return [
                        'success' => false, 'mode' => 'unknown',
                        'attempted' => 0, 'updated' => 0, 'failed' => 0,
                        'results' => [], 'message' => 'field is required.',
                    ];
                }

                $has_ids   = isset( $input['post_ids'] ) && is_array( $input['post_ids'] );
                $has_items = isset( $input['items'] ) && is_array( $input['items'] );

                if ( $has_ids && $has_items ) {
                    return [
                        'success' => false, 'mode' => 'unknown',
                        'attempted' => 0, 'updated' => 0, 'failed' => 0,
                        'results' => [], 'message' => 'Pass either post_ids or items, not both.',
                    ];
                }
                if ( ! $has_ids && ! $has_items ) {
                    return [
                        'success' => false, 'mode' => 'unknown',
                        'attempted' => 0, 'updated' => 0, 'failed' => 0,
                        'results' => [], 'message' => 'Either post_ids or items is required.',
                    ];
                }

                // Build the working list as [post_id => value, ...]
                $work = [];
                $mode = 'unknown';
                if ( $has_ids ) {
                    if ( ! array_key_exists( 'value', $input ) ) {
                        return [
                            'success' => false, 'mode' => 'same_value',
                            'attempted' => 0, 'updated' => 0, 'failed' => 0,
                            'results' => [], 'message' => 'value is required in same-value-to-many mode.',
                        ];
                    }
                    $mode  = 'same_value';
                    $value = $input['value'];
                    foreach ( array_unique( array_map( 'intval', $input['post_ids'] ) ) as $pid ) {
                        if ( $pid > 0 ) {
                            $work[ $pid ] = $value;
                        }
                    }
                } else {
                    $mode = 'per_item';
                    foreach ( $input['items'] as $row ) {
                        if ( ! is_array( $row ) || ! isset( $row['post_id'] ) || ! array_key_exists( 'value', $row ) ) {
                            continue;
                        }
                        $pid = (int) $row['post_id'];
                        if ( $pid > 0 ) {
                            $work[ $pid ] = $row['value'];
                        }
                    }
                }

                if ( empty( $work ) ) {
                    return [
                        'success' => false, 'mode' => $mode,
                        'attempted' => 0, 'updated' => 0, 'failed' => 0,
                        'results' => [], 'message' => 'No valid post_id values to process.',
                    ];
                }

                $forced_type = isset( $input['type'] ) && in_array( $input['type'], [ 'acf', 'toolset', 'meta_box', 'raw' ], true )
                    ? (string) $input['type']
                    : null;

                $results = [];
                $updated = 0;
                $failed  = 0;

                foreach ( $work as $pid => $val ) {
                    $post = get_post( $pid );
                    if ( ! $post ) {
                        $results[] = [ 'post_id' => $pid, 'success' => false, 'type' => 'unknown', 'message' => 'Post not found.' ];
                        $failed++;
                        continue;
                    }
                    $pt_obj = get_post_type_object( $post->post_type );
                    if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $pid ) ) {
                        $results[] = [ 'post_id' => $pid, 'success' => false, 'type' => 'unknown', 'message' => 'Permission denied.' ];
                        $failed++;
                        continue;
                    }

                    $unsafe_meta = $this->avcf_reject_unsafe_meta_write( $field, $val );
                    if ( null !== $unsafe_meta ) {
                        $results[] = [ 'post_id' => $pid, 'success' => false, 'type' => 'unknown', 'message' => $unsafe_meta ];
                        $failed++;
                        continue;
                    }

                    $type = $forced_type !== null ? $forced_type : $this->avcf_detect_meta_type( $pid, $field, $val );
                    $write = $this->avcf_write_field_value( $pid, $field, $val, $type );
                    if ( $write['error'] !== null ) {
                        $results[] = [ 'post_id' => $pid, 'success' => false, 'type' => $type, 'message' => $write['error'] ];
                        $failed++;
                        continue;
                    }
                    $results[] = [ 'post_id' => $pid, 'success' => true, 'type' => $type, 'message' => 'OK.' ];
                    $updated++;
                }

                $attempted = count( $work );

                return [
                    'success'   => ( $failed === 0 ),
                    'mode'      => $mode,
                    'attempted' => $attempted,
                    'updated'   => $updated,
                    'failed'    => $failed,
                    'results'   => $results,
                    'message'   => sprintf( '%s mode: %d of %d updated, %d failed.', $mode === 'same_value' ? 'Same-value' : 'Per-item', $updated, $attempted, $failed ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-option-field ----
        wp_register_ability( 'atarim/get-option-field', [
            'label'               => 'Get Option Field',
            'description'         => 'Reads one or more framework field values stored on an options/settings page (NOT on a post). This is the options-page counterpart to get-post-field. Currently routes to ACF, whose option values live in wp_options under the special "option" store rather than as post meta. Pass option_ref to target a specific ACF options page that uses a custom post_id; omit it for the default "option" store. Each result carries a "type" hint (currently always "acf"). For a raw wp_options key (not a framework field) use get-option instead.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'fields'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'minItems' => 1, 'description' => 'Field name(s) to read from the options page.' ],
                    'option_ref' => [ 'type' => 'string', 'description' => 'ACF options target. Default "option". Use a custom value only if the options page was registered with a custom post_id.', 'default' => 'option' ],
                    'format'     => [ 'type' => 'string', 'enum' => [ 'raw', 'formatted' ], 'default' => 'raw', 'description' => 'raw returns the stored value; formatted applies ACF formatting.' ],
                ],
                'required'             => [ 'fields' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'fields'  => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'get_field' ) ) {
                    return [ 'success' => false, 'message' => 'No options-field backend available (ACF is not active).' ];
                }
                $ref    = isset( $input['option_ref'] ) && $input['option_ref'] !== '' ? (string) $input['option_ref'] : 'option';
                $format = isset( $input['format'] ) ? (string) $input['format'] : 'raw';
                $fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : [];
                if ( empty( $fields ) ) {
                    return [ 'success' => false, 'message' => 'fields must be a non-empty array.' ];
                }
                $out = [];
                foreach ( $fields as $key ) {
                    $out[] = [ 'key' => (string) $key, 'value' => get_field( (string) $key, $ref, $format === 'formatted' ), 'type' => 'acf' ];
                }
                return [ 'success' => true, 'option_ref' => $ref, 'fields' => $out, 'message' => 'OK.' ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-option-field ----
        wp_register_ability( 'atarim/update-option-field', [
            'label'               => 'Update Option Field',
            'description'         => 'Writes a single framework field value on an options/settings page (NOT a post) — the options-page counterpart to update-post-field. Routes through the framework\'s API so hooks and formatting stay intact. Currently supports type "acf" (writes via update_field() against the ACF "option" store); Meta Box settings pages, Pods, and ACPT option pages are planned and will report a clear unsupported message until wired. Pass option_ref for an ACF options page registered with a custom post_id; omit for the default "option" store. To set a raw wp_options key use update-option instead.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'field'      => [ 'type' => 'string', 'description' => 'Field name to write.' ],
                    'value'      => [ 'description' => 'Value to store (any JSON type; ACF handles serialization).' ],
                    'option_ref' => [ 'type' => 'string', 'description' => 'ACF options target. Default "option".', 'default' => 'option' ],
                    'type'       => [ 'type' => 'string', 'enum' => [ 'acf' ], 'default' => 'acf', 'description' => 'Backend to route through. Only "acf" is wired this round.' ],
                ],
                'required'             => [ 'field', 'value' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'field'     => [ 'type' => 'string' ],
                    'new_value' => [ 'description' => 'Value read back after the write.' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $field = isset( $input['field'] ) ? (string) $input['field'] : '';
                if ( $field === '' || ! array_key_exists( 'value', $input ) ) {
                    return [ 'success' => false, 'message' => 'field and value are required.' ];
                }
                $ref  = isset( $input['option_ref'] ) && $input['option_ref'] !== '' ? (string) $input['option_ref'] : 'option';
                $type = isset( $input['type'] ) ? (string) $input['type'] : 'acf';
                $res  = $this->avcf_write_option_field_value( $field, $input['value'], $ref, $type );
                if ( $res['error'] !== null ) {
                    return [ 'success' => false, 'field' => $field, 'message' => 'Write failed: ' . $res['error'] ];
                }
                $confirmed = function_exists( 'get_field' ) ? get_field( $field, $ref, false ) : null;
                return [ 'success' => true, 'field' => $field, 'option_ref' => $ref, 'new_value' => $confirmed, 'message' => sprintf( 'Field "%s" updated on options target "%s" via %s.', $field, $ref, $type ) ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- bulk-update-option-field ----
        wp_register_ability( 'atarim/bulk-update-option-field', [
            'label'               => 'Bulk Update Option Field',
            'description'         => 'Writes several framework fields on a single options/settings page in one call. items is [{field, value}, ...]; option_ref and type are shared across all items (default "option" / "acf"). Per-item success is tracked — partial failures surface in results. Max 200 items.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'items'      => [ 'type' => 'array', 'maxItems' => 200, 'items' => [ 'type' => 'object', 'properties' => [ 'field' => [ 'type' => 'string' ], 'value' => [] ], 'required' => [ 'field', 'value' ], 'additionalProperties' => false ] ],
                    'option_ref' => [ 'type' => 'string', 'default' => 'option' ],
                    'type'       => [ 'type' => 'string', 'enum' => [ 'acf' ], 'default' => 'acf' ],
                ],
                'required'             => [ 'items' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'results' => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];
                if ( empty( $items ) ) {
                    return [ 'success' => false, 'message' => 'items must be a non-empty array.' ];
                }
                $ref  = isset( $input['option_ref'] ) && $input['option_ref'] !== '' ? (string) $input['option_ref'] : 'option';
                $type = isset( $input['type'] ) ? (string) $input['type'] : 'acf';
                $results = [];
                $ok = 0;
                foreach ( $items as $item ) {
                    $field = isset( $item['field'] ) ? (string) $item['field'] : '';
                    if ( $field === '' || ! array_key_exists( 'value', $item ) ) {
                        $results[] = [ 'field' => $field, 'success' => false, 'message' => 'field and value required.' ];
                        continue;
                    }
                    $res = $this->avcf_write_option_field_value( $field, $item['value'], $ref, $type );
                    if ( $res['error'] !== null ) {
                        $results[] = [ 'field' => $field, 'success' => false, 'message' => $res['error'] ];
                    } else {
                        $ok++;
                        $results[] = [ 'field' => $field, 'success' => true, 'message' => 'Updated.' ];
                    }
                }
                return [ 'success' => true, 'option_ref' => $ref, 'results' => $results, 'message' => sprintf( '%d of %d field(s) updated.', $ok, count( $items ) ) ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-option ----
        wp_register_ability( 'atarim/get-option', [
            'label'               => 'Get Option',
            'description'         => 'Reads a value from the WordPress wp_options table by name. Many WordPress settings (site title, active plugins, theme settings, plugin configurations) live in wp_options. Use the dedicated settings abilities (atarim/get-general-settings, atarim/get-reading-settings, etc.) for WP-native option groups; use this for plugin/theme option keys that don\'t have dedicated abilities. Returns the value as stored, including unserialized arrays.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'option_name' => [
                        'type'        => 'string',
                        'description' => 'Option name as stored in wp_options.',
                        'minLength'   => 1,
                    ],
                    'default' => [
                        'description' => 'Value to return if the option does not exist. Defaults to false (WordPress\'s own default).',
                    ],
                ],
                'required'             => [ 'option_name' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'option_name' => [ 'type' => 'string' ],
                    'value'       => [],
                    'exists'      => [ 'type' => 'boolean' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';
                if ( $name === '' ) {
                    return [ 'success' => false, 'message' => 'option_name is required.' ];
                }

                // Use a sentinel to distinguish "doesn't exist" from "exists with falsy value".
                $sentinel = '__avcf_not_found_' . uniqid();
                $value    = get_option( $name, $sentinel );
                $exists   = ( $value !== $sentinel );

                if ( ! $exists ) {
                    $default = array_key_exists( 'default', $input ) ? $input['default'] : false;
                    return [
                        'success'     => true,
                        'option_name' => $name,
                        'value'       => $default,
                        'exists'      => false,
                        'message'     => sprintf( 'Option "%s" does not exist; returning provided default.', $name ),
                    ];
                }

                return [
                    'success'     => true,
                    'option_name' => $name,
                    'value'       => $value,
                    'exists'      => true,
                    'message'     => sprintf( 'Option "%s" read successfully.', $name ),
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

        // ---- update-option ----
        wp_register_ability( 'atarim/update-option', [
            'label'               => 'Update Option',
            'description'         => 'Writes a value to the WordPress wp_options table. Refuses to write to critical infrastructure options (siteurl, home, active_plugins, template, stylesheet, db_version, admin_email, cron, permalink_structure, etc.) — those have dedicated abilities that handle the proper workflows (validation, activation hooks, rewrite-rules flush, verification flows). Use this for plugin/theme option keys that don\'t have dedicated abilities. Arrays and objects are serialized automatically by WordPress.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'option_name' => [
                        'type'        => 'string',
                        'description' => 'Option name as stored in wp_options.',
                        'minLength'   => 1,
                    ],
                    'value' => [
                        'description' => 'Value to store. Arrays and objects are automatically serialized.',
                    ],
                    'autoload' => [
                        'type'        => 'boolean',
                        'description' => 'Whether to autoload this option on every page load. Defaults to true. Set false for large/rarely-read options to reduce per-request overhead.',
                        'default'     => true,
                    ],
                ],
                'required'             => [ 'option_name', 'value' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'option_name' => [ 'type' => 'string' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';
                if ( $name === '' ) {
                    return [ 'success' => false, 'message' => 'option_name is required.' ];
                }
                if ( ! array_key_exists( 'value', $input ) ) {
                    return [ 'success' => false, 'option_name' => $name, 'message' => 'value is required.' ];
                }

                $blocked_reason = $this->avcf_blocked_option_reason( $name );
                if ( null !== $blocked_reason ) {
                    return [
                        'success'     => false,
                        'option_name' => $name,
                        'message'     => sprintf( 'Option "%s" is blocked for direct write. %s', $name, $blocked_reason ),
                    ];
                }

                $autoload = ! isset( $input['autoload'] ) ? true : (bool) $input['autoload'];
                $result   = update_option( $name, $input['value'], $autoload );

                // update_option returns false when the value didn't change OR when it failed. Distinguish:
                if ( ! $result ) {
                    $current = get_option( $name );
                    if ( $current === $input['value'] ) {
                        return [
                            'success'     => true,
                            'option_name' => $name,
                            'message'     => sprintf( 'Option "%s" already had this value; no change written.', $name ),
                        ];
                    }
                    return [
                        'success'     => false,
                        'option_name' => $name,
                        'message'     => sprintf( 'Could not write option "%s" (WordPress reported failure).', $name ),
                    ];
                }

                return [
                    'success'     => true,
                    'option_name' => $name,
                    'message'     => sprintf( 'Option "%s" updated successfully.', $name ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-user-meta ----
        wp_register_ability( 'atarim/get-user-meta', [
            'label'               => 'Get User Meta',
            'description'         => 'Reads user meta keys for a user. Pass "keys" to read specific ones; omit to read ALL non-private user meta (keys starting with "_" excluded by default; pass include_private: true to include those). All-keys mode is capped at 100 keys with truncated: true if there are more. User meta currently does NOT include framework type detection — all entries return type: "raw". User-level ACF and similar frameworks aren\'t supported in this round.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'user_id' => [
                        'type'        => 'integer',
                        'description' => 'User ID.',
                        'minimum'     => 1,
                    ],
                    'keys' => [
                        'type'        => 'array',
                        'description' => 'Specific meta keys to read. Omit to return all.',
                        'items'       => [ 'type' => 'string', 'minLength' => 1 ],
                        'minItems'    => 1,
                    ],
                    'include_private' => [
                        'type'        => 'boolean',
                        'description' => 'In all-keys mode, include keys starting with "_". Defaults to false.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'user_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'user_id'    => [ 'type' => 'integer' ],
                    'mode'       => [ 'type' => 'string' ],
                    'count'      => [ 'type' => 'integer' ],
                    'truncated'  => [ 'type' => 'boolean' ],
                    'total_keys' => [ 'type' => 'integer' ],
                    'fields'     => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'key'   => [ 'type' => 'string' ],
                                'value' => [],
                                'type'  => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
                if ( $user_id <= 0 ) {
                    return [ 'success' => false, 'message' => 'user_id is required and must be a positive integer.' ];
                }
                if ( ! get_userdata( $user_id ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $user_id ) ];
                }

                if ( isset( $input['keys'] ) && is_array( $input['keys'] ) && ! empty( $input['keys'] ) ) {
                    $keys   = array_values( array_filter( array_map( 'strval', $input['keys'] ) ) );
                    $fields = [];
                    foreach ( $keys as $key ) {
                        $fields[] = [
                            'key'   => $key,
                            'value' => get_user_meta( $user_id, $key, true ),
                            'type'  => 'raw',
                        ];
                    }
                    return [
                        'success' => true,
                        'user_id' => $user_id,
                        'mode'    => 'targeted',
                        'count'   => count( $fields ),
                        'fields'  => $fields,
                        'message' => sprintf( 'Read %d key(s) from user %d.', count( $fields ), $user_id ),
                    ];
                }

                $include_private = ! empty( $input['include_private'] );
                $all_meta        = get_user_meta( $user_id );
                if ( ! is_array( $all_meta ) ) {
                    $all_meta = [];
                }

                $all_keys = array_keys( $all_meta );
                if ( ! $include_private ) {
                    $all_keys = array_values( array_filter( $all_keys, function( $k ) {
                        return strpos( $k, '_' ) !== 0;
                    } ) );
                }

                $total_keys = count( $all_keys );
                $cap        = 100;
                $truncated  = $total_keys > $cap;
                $work_keys  = $truncated ? array_slice( $all_keys, 0, $cap ) : $all_keys;

                $fields = [];
                foreach ( $work_keys as $key ) {
                    $fields[] = [
                        'key'   => $key,
                        'value' => get_user_meta( $user_id, $key, true ),
                        'type'  => 'raw',
                    ];
                }

                $msg = sprintf( 'Read %d of %d key(s) from user %d.', count( $fields ), $total_keys, $user_id );
                if ( $truncated ) {
                    $msg .= ' Response truncated to 100 keys — pass specific keys in the "keys" parameter to read more.';
                }

                return [
                    'success'    => true,
                    'user_id'    => $user_id,
                    'mode'       => 'all',
                    'count'      => count( $fields ),
                    'truncated'  => $truncated,
                    'total_keys' => $total_keys,
                    'fields'     => $fields,
                    'message'    => $msg,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'list_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-user-meta ----
        wp_register_ability( 'atarim/update-user-meta', [
            'label'               => 'Update User Meta',
            'description'         => 'Writes a single user meta key/value. Generic post_meta-style write — no framework type detection on user meta this round. The value is passed directly to update_user_meta and serialized automatically if it\'s an array or object.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'user_id' => [
                        'type'        => 'integer',
                        'description' => 'User ID.',
                        'minimum'     => 1,
                    ],
                    'key' => [
                        'type'        => 'string',
                        'description' => 'Meta key.',
                        'minLength'   => 1,
                    ],
                    'value' => [
                        'description' => 'New value.',
                    ],
                ],
                'required'             => [ 'user_id', 'key', 'value' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'user_id'   => [ 'type' => 'integer' ],
                    'key'       => [ 'type' => 'string' ],
                    'new_value' => [],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
                $key     = isset( $input['key'] ) ? (string) $input['key'] : '';
                if ( $user_id <= 0 || $key === '' ) {
                    return [ 'success' => false, 'message' => 'user_id and key are required.' ];
                }
                if ( ! array_key_exists( 'value', $input ) ) {
                    return [ 'success' => false, 'message' => 'value is required.' ];
                }
                if ( ! get_userdata( $user_id ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'User %d not found.', $user_id ) ];
                }
                if ( ! current_user_can( 'edit_user', $user_id ) ) {
                    return [ 'success' => false, 'message' => 'Permission denied.' ];
                }

                $result = update_user_meta( $user_id, $key, $input['value'] );
                if ( $result === false ) {
                    // update_user_meta returns false on "same value" OR failure; distinguish.
                    $current = get_user_meta( $user_id, $key, true );
                    if ( $current === $input['value'] ) {
                        return [
                            'success'   => true,
                            'user_id'   => $user_id,
                            'key'       => $key,
                            'new_value' => $current,
                            'message'   => sprintf( 'User meta "%s" already had this value; no change written.', $key ),
                        ];
                    }
                    return [
                        'success'   => false,
                        'user_id'   => $user_id,
                        'key'       => $key,
                        'message'   => sprintf( 'Could not write user meta "%s" (WordPress reported failure).', $key ),
                    ];
                }

                return [
                    'success'   => true,
                    'user_id'   => $user_id,
                    'key'       => $key,
                    'new_value' => get_user_meta( $user_id, $key, true ),
                    'message'   => sprintf( 'User meta "%s" updated for user %d.', $key, $user_id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_users' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );
    }

    // ─── Detection and routing helpers ──────────────────────────────────────

    /**
     * Detect which framework manages a given meta key on a given post.
     *
     * Returns one of: 'acf' | 'toolset' | 'meta_box' | 'raw'.
     *
     * Detection order (cheapest first):
     *   1. Toolset Types — keys with "wpcf-" prefix
     *   2. ACF — paired _fieldname meta whose value starts with "field_"
     *   3. Meta Box — registry walk for the post's type (strict: must be a registered field)
     *   4. raw — fallback
     *
     * The value parameter is currently unused but reserved — some frameworks
     * could be identified by value shape (e.g. ACF image arrays).
     *
     * @param int    $post_id
     * @param string $key
     * @param mixed  $value (reserved; currently unused)
     * @return string
     */
    private function avcf_detect_meta_type( $post_id, $key, $value ) {
        // 1. Toolset Types: prefix-based.
        if ( strpos( $key, 'wpcf-' ) === 0 ) {
            return 'toolset';
        }

        // 2. ACF: paired field-key reference.
        // Skip the check entirely for keys starting with _ (those would be the sentinel meta themselves).
        if ( strpos( $key, '_' ) !== 0 ) {
            $sentinel = get_post_meta( $post_id, '_' . $key, true );
            if ( is_string( $sentinel ) && strpos( $sentinel, 'field_' ) === 0 ) {
                return 'acf';
            }
        }

        // 3. Meta Box: strict registry check (Option A from design discussion).
        if ( $this->avcf_metabox_owns_field( $post_id, $key ) ) {
            return 'meta_box';
        }

        return 'raw';
    }

    /**
     * Check whether Meta Box has the given field registered for the given post's type.
     *
     * Returns true only when Meta Box's API is loaded AND the field is in a
     * registered meta-box for this post's type. Returns false in any other case
     * (Meta Box not active, field not registered, can't determine).
     *
     * @param int    $post_id
     * @param string $key
     * @return bool
     */
    private function avcf_metabox_owns_field( $post_id, $key ) {
        // Sentinel: Meta Box exposes rwmb_get_object_fields or the RWMB_Loader class.
        if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
            return false;
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }
        // rwmb_get_object_fields returns a flat array of fields keyed by id for a given object type.
        $fields = @rwmb_get_object_fields( $post->post_type, 'post' );
        if ( ! is_array( $fields ) ) {
            return false;
        }
        return isset( $fields[ $key ] );
    }

    /**
     * Read a field's value via the appropriate backend.
     *
     * @param int    $post_id
     * @param string $key
     * @param string $type   acf|toolset|meta_box|raw
     * @param string $format For ACF only: 'raw' or 'formatted'. Ignored otherwise.
     * @return mixed
     */
    private function avcf_read_field_value( $post_id, $key, $type, $format = 'raw' ) {
        switch ( $type ) {
            case 'acf':
                if ( function_exists( 'get_field' ) ) {
                    return get_field( $key, $post_id, $format === 'formatted' );
                }
                // Fallback if ACF not loaded — read raw.
                return get_post_meta( $post_id, $key, true );

            case 'meta_box':
                if ( function_exists( 'rwmb_meta' ) ) {
                    return rwmb_meta( $key, [], $post_id );
                }
                return get_post_meta( $post_id, $key, true );

            case 'toolset':
            case 'raw':
            default:
                return get_post_meta( $post_id, $key, true );
        }
    }

    /**
     * Write a field's value via the appropriate backend.
     *
     * Returns [ 'ok' => bool, 'error' => string|null ].
     *
     * @param int    $post_id
     * @param string $key
     * @param mixed  $value
     * @param string $type acf|toolset|meta_box|raw
     * @return array
     */
    private function avcf_write_field_value( $post_id, $key, $value, $type ) {
        switch ( $type ) {
            case 'acf':
                if ( function_exists( 'update_field' ) ) {
                    $result = update_field( $key, $value, $post_id );
                    if ( $result === false ) {
                        return [ 'ok' => false, 'error' => 'ACF update_field returned false. The field may not exist for this post, or the field group may not be assigned.' ];
                    }
                    return [ 'ok' => true, 'error' => null ];
                }
                return [ 'ok' => false, 'error' => 'type=acf requested but ACF is not active on this site.' ];

            case 'meta_box':
                if ( function_exists( 'rwmb_set_meta' ) ) {
                    rwmb_set_meta( $post_id, $key, $value );
                    return [ 'ok' => true, 'error' => null ];
                }
                // Fallback: raw write but warn the AI that Meta Box hooks didn't fire.
                update_post_meta( $post_id, $key, wp_slash( $value ) );
                return [ 'ok' => true, 'error' => null ];

            case 'toolset':
            case 'raw':
            default:
                update_post_meta( $post_id, $key, wp_slash( $value ) );
                return [ 'ok' => true, 'error' => null ];
        }
    }
    /**
     * Write a framework field value on an options/settings page.
     * Currently handles ACF (the "option" store); other backends report a
     * clear unsupported message (seam for Meta Box settings pages / Pods / ACPT).
     *
     * @param string $field
     * @param mixed  $value
     * @param string $ref   ACF options post_id ("option" or a custom one)
     * @param string $type  backend (currently only "acf")
     * @return array [ 'ok' => bool, 'error' => string|null ]
     */
    private function avcf_write_option_field_value( $field, $value, $ref, $type ) {
        switch ( $type ) {
            case 'acf':
                if ( function_exists( 'update_field' ) ) {
                    $result = update_field( $field, $value, $ref );
                    if ( $result === false ) {
                        return [ 'ok' => false, 'error' => 'ACF update_field returned false — the field may not exist on this options page, or its field group is not assigned to the options page.' ];
                    }
                    return [ 'ok' => true, 'error' => null ];
                }
                return [ 'ok' => false, 'error' => 'type=acf requested but ACF is not active on this site.' ];

            default:
                return [ 'ok' => false, 'error' => sprintf( 'Option/settings backend "%s" is not wired yet (only "acf" is supported this round; Meta Box settings pages, Pods, and ACPT option pages are planned).', $type ) ];
        }
    }

    /**
     * Reject writes to security-sensitive internal meta keys.
     *
     * Two tiers:
     *
     * 1. Serialized attachment structures WordPress manages itself
     *    (_wp_attachment_metadata, _wp_attachment_backup_sizes). Their file /
     *    sizes[].file / backup entries drive on-disk path building and deletion in
     *    core (wp_delete_attachment, the image editor's restore) and in other
     *    abilities. Hand-writing them through a generic string field writer has no
     *    legitimate use and can corrupt them or point file ops at attacker-chosen
     *    paths, so they are refused outright.
     *
     * 2. _wp_attached_file — the attachment's uploads-relative path, resolved by
     *    get_attached_file() for deletion/overwrite (e.g. atarim/replace-media-file).
     *    A traversal ("../") or absolute value here escapes the uploads directory
     *    (arbitrary file deletion, RCE via wp-config.php). Core only ever stores a
     *    clean relative path, so anything else is refused. This is the write-source
     *    guard complementing the containment check in the media abilities.
     *
     * @param string $field The meta key being written.
     * @param mixed  $value The value to be written.
     * @return string|null Error message if the write must be rejected, otherwise null.
     */
    private function avcf_reject_unsafe_meta_write( $field, $value ) {
        // Tier 1: WordPress-managed attachment structures — never writable here.
        $reserved_structures = [
            '_wp_attachment_metadata',     // file + sizes[].file drive path building / deletion
            '_wp_attachment_backup_sizes', // backup files unlinked on image-editor restore
        ];
        if ( in_array( $field, $reserved_structures, true ) ) {
            return sprintf(
                'Refused: "%s" is WordPress-managed attachment metadata and cannot be written through this ability. Use the dedicated media abilities (e.g. atarim/replace-media-file) instead.',
                $field
            );
        }

        // Tier 2: _wp_attached_file — allow only a clean uploads-relative path.
        if ( '_wp_attached_file' === $field ) {
            if ( ! is_string( $value ) ) {
                return 'Refused: _wp_attached_file must be a plain uploads-relative path string.';
            }

            $normalized = str_replace( '\\', '/', $value );

            if (
                '' === $value
                || strpos( $value, "\0" ) !== false                              // null byte
                || strpos( $normalized, '../' ) !== false                        // parent traversal
                || '/' === substr( $normalized, 0, 1 )                           // absolute (unix)
                || preg_match( '#^[a-zA-Z]:/#', $normalized )                    // absolute (windows drive)
                || preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $normalized )   // stream wrapper / URL
            ) {
                return 'Refused: _wp_attached_file must be a relative path inside the uploads directory (no "..", absolute paths, or stream wrappers). This guards against file operations that resolve outside uploads.';
            }
        }

        return null;
    }
}