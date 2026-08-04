<?php
/**
 * Navigation and site structure MCP abilities.
 *
 * Covers classic WordPress menus (nav_menu taxonomy + nav_menu_item posts)
 * and widget areas (classic and block-based widgets). Block-theme
 * navigation (the wp_navigation post type used by the Navigation block) is
 * out of scope here — it is handled by AVCF_Abilities_Block_Navigation
 * (atarim/list-navigation, get-navigation, update-navigation).
 *
 * Per-widget mode detection: each widget instance carries its own type
 * (classic / block) in reads, and the same type on writes — same pattern
 * as the type-hint approach in the metadata cluster.
 *
 * Menu item writes are REPLACE-mode: the AI sends the full new item set
 * including a parent_index for each item to express the tree. Order is
 * the array index. Per-item meta (mega-menu plugin settings, etc.) is
 * preserved when the AI passes it back on the write.
 *
 * Exposed abilities:
 *   atarim/list-menus            All menus + locations + counts.
 *   atarim/get-menu              One menu with full item tree + per-item meta.
 *   atarim/create-menu           New menu; optional location assignment.
 *   atarim/update-menu           Rename + reassign location.
 *   atarim/delete-menu           Permanent delete (no trash).
 *   atarim/update-menu-items     REPLACE menu items with array-index ordering and parent_index tree.
 *   atarim/list-menu-locations   Theme-registered locations + currently assigned menu.
 *   atarim/list-widget-areas     Sidebars + per-widget type detection.
 *   atarim/update-widget-area    REPLACE widgets in a sidebar (classic and block items heterogeneous).
 *   atarim/get-widget-types      Available widget type registry.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Navigation extends AVCF_Abilities_Base {

    public function register() {

        // ---- list-menus ----
        wp_register_ability( 'atarim/list-menus', [
            'label'               => 'List Navigation Menus',
            'description'         => 'Returns all classic WordPress navigation menus with their item counts and the theme locations each is assigned to. If the active theme is a block theme, returns a block_theme: true flag with a note — block themes typically use wp_navigation posts and the Site Editor for primary navigation, which is out of scope for these abilities. Block themes that ALSO register classic menu locations work normally for those locations.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'       => [ 'type' => 'boolean' ],
                    'total'         => [ 'type' => 'integer' ],
                    'menus'         => [ 'type' => 'array' ],
                    'block_theme'   => [ 'type' => 'boolean' ],
                    'block_theme_note' => [ 'type' => 'string' ],
                    'message'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $menus_raw = wp_get_nav_menus();
                $locations = (array) get_nav_menu_locations();

                $menus = [];
                foreach ( $menus_raw as $menu ) {
                    $assigned_to = [];
                    foreach ( $locations as $location => $menu_id ) {
                        if ( (int) $menu_id === (int) $menu->term_id ) {
                            $assigned_to[] = (string) $location;
                        }
                    }
                    $menus[] = [
                        'id'           => (int) $menu->term_id,
                        'name'         => (string) $menu->name,
                        'slug'         => (string) $menu->slug,
                        'description'  => (string) $menu->description,
                        'item_count'   => (int) $menu->count,
                        'locations'    => $assigned_to,
                    ];
                }

                $is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
                $note           = '';
                if ( $is_block_theme ) {
                    $note = 'Active theme is a block theme. Classic menus here may still be used by widgets, footer, or legacy locations, but primary navigation in block themes typically uses wp_navigation posts edited via the Site Editor — those are out of scope for these abilities.';
                }

                return [
                    'success'          => true,
                    'total'            => count( $menus ),
                    'menus'            => $menus,
                    'block_theme'      => $is_block_theme,
                    'block_theme_note' => $note,
                    'message'          => sprintf( '%d classic menu(s) on the site.', count( $menus ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-menu ----
        wp_register_ability( 'atarim/get-menu', [
            'label'               => 'Get Menu',
            'description'         => 'Returns full detail for one menu including the ordered item tree. Each item has: type (custom/post_type/taxonomy), object (post type slug or taxonomy slug for non-custom), object_id, url, label, target, css_classes, description, xfn, menu_item_id, parent_item_id (for the tree), order, and meta (an object of all non-WordPress-internal meta keys on the item, useful for preserving mega-menu plugin settings on round-trips). Pass through items[].meta unchanged in update-menu-items to preserve third-party plugin settings.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Menu term ID. Pass id OR slug.' ],
                    'slug' => [ 'type' => 'string', 'description' => 'Menu slug.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'menu'    => [ 'type' => 'object' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
                $slug = isset( $input['slug'] ) ? (string) $input['slug'] : '';

                if ( $id <= 0 && $slug === '' ) {
                    return [ 'success' => false, 'message' => 'Pass id or slug.' ];
                }
                $menu = $id > 0 ? wp_get_nav_menu_object( $id ) : wp_get_nav_menu_object( $slug );
                if ( ! $menu ) {
                    return [ 'success' => false, 'message' => 'Menu not found.' ];
                }

                $items_raw = wp_get_nav_menu_items( $menu->term_id, [ 'update_post_term_cache' => false ] );
                if ( ! is_array( $items_raw ) ) {
                    $items_raw = [];
                }

                $items = [];
                foreach ( $items_raw as $item ) {
                    // Pull all non-WordPress-internal meta for round-trip preservation.
                    $all_meta = get_post_meta( $item->ID );
                    $clean_meta = [];
                    // WordPress-internal nav menu meta keys we manage ourselves on write.
                    $internal_keys = [
                        '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id',
                        '_menu_item_object', '_menu_item_target', '_menu_item_classes',
                        '_menu_item_xfn', '_menu_item_url', '_menu_item_orphaned',
                    ];
                    foreach ( $all_meta as $k => $v ) {
                        if ( in_array( $k, $internal_keys, true ) ) continue;
                        // get_post_meta($id) returns arrays; unwrap single values.
                        $clean_meta[ $k ] = ( is_array( $v ) && count( $v ) === 1 ) ? $v[0] : $v;
                    }

                    $items[] = [
                        'menu_item_id'   => (int) $item->ID,
                        'parent_item_id' => (int) $item->menu_item_parent,
                        'order'          => (int) $item->menu_order,
                        'type'           => (string) $item->type,
                        'object'         => (string) $item->object,
                        'object_id'      => (int) $item->object_id,
                        'url'            => (string) $item->url,
                        'label'          => (string) $item->title,
                        'target'         => (string) $item->target,
                        'css_classes'    => array_values( array_filter( (array) $item->classes ) ),
                        'description'    => (string) $item->description,
                        'xfn'            => (string) $item->xfn,
                        'meta'           => $clean_meta,
                    ];
                }

                // Sort by order (wp_get_nav_menu_items returns this way by default but be defensive).
                usort( $items, function( $a, $b ) {
                    return ( $a['order'] <=> $b['order'] );
                } );

                $locations = (array) get_nav_menu_locations();
                $assigned_to = [];
                foreach ( $locations as $loc => $mid ) {
                    if ( (int) $mid === (int) $menu->term_id ) {
                        $assigned_to[] = (string) $loc;
                    }
                }

                return [
                    'success' => true,
                    'menu'    => [
                        'id'          => (int) $menu->term_id,
                        'name'        => (string) $menu->name,
                        'slug'        => (string) $menu->slug,
                        'description' => (string) $menu->description,
                        'locations'   => $assigned_to,
                        'item_count'  => count( $items ),
                        'items'       => $items,
                    ],
                    'message' => sprintf( 'Menu "%s" has %d item(s).', $menu->name, count( $items ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- create-menu ----
        wp_register_ability( 'atarim/create-menu', [
            'label'               => 'Create Menu',
            'description'         => 'Create a new classic menu by name. Optional assign_locations: array of theme location names to attach the new menu to. Use list-menu-locations first to know what locations are available. Returns the new menu ID.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string', 'minLength' => 1 ],
                    'assign_locations' => [
                        'type'        => 'array',
                        'description' => 'Optional list of theme location slugs to assign this new menu to.',
                        'items'       => [ 'type' => 'string' ],
                    ],
                ],
                'required' => [ 'name' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'           => [ 'type' => 'boolean' ],
                    'id'                => [ 'type' => 'integer' ],
                    'name'              => [ 'type' => 'string' ],
                    'assigned_locations'=> [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'skipped_locations' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'           => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
                if ( $name === '' ) {
                    return [ 'success' => false, 'message' => 'name is required.' ];
                }
                if ( wp_get_nav_menu_object( $name ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Menu "%s" already exists.', $name ) ];
                }

                $id = wp_create_nav_menu( $name );
                if ( is_wp_error( $id ) ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $id->get_error_message() ];
                }

                $assigned = [];
                $skipped  = [];
                if ( ! empty( $input['assign_locations'] ) && is_array( $input['assign_locations'] ) ) {
                    $registered = (array) get_registered_nav_menus();
                    $current    = (array) get_nav_menu_locations();
                    foreach ( $input['assign_locations'] as $loc ) {
                        $loc = sanitize_key( (string) $loc );
                        if ( $loc === '' ) continue;
                        if ( ! isset( $registered[ $loc ] ) ) {
                            $skipped[] = $loc;
                            continue;
                        }
                        $current[ $loc ] = (int) $id;
                        $assigned[]      = $loc;
                    }
                    if ( ! empty( $assigned ) ) {
                        set_theme_mod( 'nav_menu_locations', $current );
                    }
                }

                return [
                    'success'            => true,
                    'id'                 => (int) $id,
                    'name'               => $name,
                    'assigned_locations' => $assigned,
                    'skipped_locations'  => $skipped,
                    'message'            => empty( $skipped )
                        ? sprintf( 'Menu "%s" created (ID %d).', $name, $id )
                        : sprintf( 'Menu "%s" created (ID %d). Locations not registered by theme were skipped: %s.', $name, $id, implode( ', ', $skipped ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- update-menu ----
        wp_register_ability( 'atarim/update-menu', [
            'label'               => 'Update Menu',
            'description'         => 'Rename a menu and/or reassign its location set. assign_locations REPLACES the menu\'s location assignments — pass empty array to remove from all locations, or omit the field to leave assignments untouched. Note: assigning this menu to a location that already has another menu assigned WILL move the assignment to this menu (last-write-wins).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'name' => [ 'type' => 'string', 'description' => 'New menu name. Omit to leave unchanged.' ],
                    'description' => [ 'type' => 'string' ],
                    'assign_locations' => [
                        'type'        => 'array',
                        'description' => 'REPLACE the menu\'s assigned location set. Omit to leave assignments untouched. Empty array unassigns from all locations.',
                        'items'       => [ 'type' => 'string' ],
                    ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'           => [ 'type' => 'boolean' ],
                    'id'                => [ 'type' => 'integer' ],
                    'updated'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'assigned_locations'=> [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'skipped_locations' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'           => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                $menu = wp_get_nav_menu_object( $id );
                if ( ! $menu ) {
                    return [ 'success' => false, 'message' => sprintf( 'Menu %d not found.', $id ) ];
                }

                $updated = [];
                $args    = [];
                if ( array_key_exists( 'name', $input ) && $input['name'] !== '' ) {
                    $args['menu-name'] = sanitize_text_field( (string) $input['name'] );
                    $updated[] = 'name';
                }
                if ( array_key_exists( 'description', $input ) ) {
                    $args['description'] = sanitize_text_field( (string) $input['description'] );
                    $updated[] = 'description';
                }

                if ( ! empty( $args ) ) {
                    $result = wp_update_nav_menu_object( $id, $args );
                    if ( is_wp_error( $result ) ) {
                        return [ 'success' => false, 'id' => $id, 'updated' => $updated, 'message' => 'Update failed: ' . $result->get_error_message() ];
                    }
                }

                $assigned = [];
                $skipped  = [];
                if ( array_key_exists( 'assign_locations', $input ) && is_array( $input['assign_locations'] ) ) {
                    $registered = (array) get_registered_nav_menus();
                    $current    = (array) get_nav_menu_locations();

                    // Remove this menu from any locations it's currently in.
                    foreach ( $current as $loc => $mid ) {
                        if ( (int) $mid === $id ) {
                            unset( $current[ $loc ] );
                        }
                    }

                    foreach ( $input['assign_locations'] as $loc ) {
                        $loc = sanitize_key( (string) $loc );
                        if ( $loc === '' ) continue;
                        if ( ! isset( $registered[ $loc ] ) ) {
                            $skipped[] = $loc;
                            continue;
                        }
                        $current[ $loc ] = $id;
                        $assigned[]      = $loc;
                    }
                    set_theme_mod( 'nav_menu_locations', $current );
                    $updated[] = 'assign_locations';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                return [
                    'success'            => true,
                    'id'                 => $id,
                    'updated'            => $updated,
                    'assigned_locations' => $assigned,
                    'skipped_locations'  => $skipped,
                    'message'            => sprintf( 'Menu %d updated: %s.', $id, implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- delete-menu ----
        wp_register_ability( 'atarim/delete-menu', [
            'label'               => 'Delete Menu',
            'description'         => 'Permanently delete a menu and all its items. WordPress\'s wp_delete_nav_menu does NOT trash — this is irreversible. Any location assignments for this menu are dropped automatically. To soft-delete, unassign from all locations via update-menu instead.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'minimum' => 1 ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                $menu = wp_get_nav_menu_object( $id );
                if ( ! $menu ) {
                    return [ 'success' => false, 'id' => $id, 'message' => sprintf( 'Menu %d not found.', $id ) ];
                }
                $name = $menu->name;
                $result = wp_delete_nav_menu( $id );
                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'id' => $id, 'message' => 'Delete failed: ' . $result->get_error_message() ];
                }
                if ( $result === false ) {
                    return [ 'success' => false, 'id' => $id, 'message' => 'Delete failed.' ];
                }
                return [
                    'success' => true,
                    'id'      => $id,
                    'message' => sprintf( 'Menu "%s" (ID %d) permanently deleted.', $name, $id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );

        // ---- update-menu-items ----
        wp_register_ability( 'atarim/update-menu-items', [
            'label'               => 'Update Menu Items',
            'description'         => 'REPLACE all items in a menu. Send the full new tree as an array of items; order is the array index. Each item has: type (custom/post_type/taxonomy), object (for non-custom: post type or taxonomy slug), object_id (for non-custom: the referenced ID), url (for custom only), label, target (_blank or empty), css_classes, description, xfn, parent_index (index into THIS array, or null for top-level — must reference an EARLIER index, no forward refs), meta (object of plugin-specific meta keys to preserve, e.g. mega-menu settings from get-menu). Existing items not in the new list are deleted. Per-item meta is preserved when passed back unchanged.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'menu_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'items' => [
                        'type'        => 'array',
                        'description' => 'New ordered list of menu items. Empty array clears the menu.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'type'        => [ 'type' => 'string', 'enum' => [ 'custom', 'post_type', 'taxonomy' ] ],
                                'object'      => [ 'type' => 'string', 'description' => 'For type=post_type: the post type slug. For type=taxonomy: the taxonomy slug. Required for non-custom types.' ],
                                'object_id'   => [ 'type' => 'integer', 'description' => 'Referenced post ID or term ID. Required for non-custom types.' ],
                                'url'         => [ 'type' => 'string', 'description' => 'Only used when type=custom.' ],
                                'label'       => [ 'type' => 'string' ],
                                'target'      => [ 'type' => 'string', 'enum' => [ '', '_blank' ] ],
                                'css_classes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                                'description' => [ 'type' => 'string' ],
                                'xfn'         => [ 'type' => 'string' ],
                                'parent_index'=> [ 'type' => [ 'integer', 'null' ], 'description' => 'Zero-based index into this items array, or null for top-level. Must point to an EARLIER index than this one.' ],
                                'meta'        => [ 'type' => 'object', 'description' => 'Optional. Per-item meta from the get-menu response, passed back to preserve third-party plugin settings.' ],
                            ],
                            'required' => [ 'type', 'label' ],
                        ],
                    ],
                ],
                'required' => [ 'menu_id', 'items' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'menu_id' => [ 'type' => 'integer' ],
                    'created' => [ 'type' => 'integer' ],
                    'deleted' => [ 'type' => 'integer' ],
                    'warnings'=> [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
                if ( $menu_id <= 0 ) {
                    return [ 'success' => false, 'message' => 'menu_id is required.' ];
                }
                $menu = wp_get_nav_menu_object( $menu_id );
                if ( ! $menu ) {
                    return [ 'success' => false, 'message' => sprintf( 'Menu %d not found.', $menu_id ) ];
                }

                $items_in = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : null;
                if ( $items_in === null ) {
                    return [ 'success' => false, 'message' => 'items is required (pass empty array to clear the menu).' ];
                }

                $warnings = [];

                // 1. Validate parent_index references — must point to earlier index or be null.
                $validated = [];
                foreach ( $items_in as $idx => $item ) {
                    if ( ! is_array( $item ) ) {
                        $warnings[] = sprintf( 'Item at index %d is not an object — skipped.', $idx );
                        continue;
                    }
                    if ( empty( $item['type'] ) || empty( $item['label'] ) ) {
                        $warnings[] = sprintf( 'Item at index %d is missing type or label — skipped.', $idx );
                        continue;
                    }
                    if ( ! in_array( $item['type'], [ 'custom', 'post_type', 'taxonomy' ], true ) ) {
                        $warnings[] = sprintf( 'Item at index %d has unknown type "%s" — skipped.', $idx, $item['type'] );
                        continue;
                    }
                    if ( $item['type'] !== 'custom' ) {
                        if ( empty( $item['object'] ) || empty( $item['object_id'] ) ) {
                            $warnings[] = sprintf( 'Item at index %d is type=%s but missing object or object_id — skipped.', $idx, $item['type'] );
                            continue;
                        }
                        // Validate reference exists.
                        if ( $item['type'] === 'post_type' ) {
                            $referenced = get_post( (int) $item['object_id'] );
                            if ( ! $referenced || $referenced->post_type !== (string) $item['object'] ) {
                                $warnings[] = sprintf( 'Item at index %d references %s ID %d but it does not exist or is not of type "%s" — skipped.', $idx, $item['object'], $item['object_id'], $item['object'] );
                                continue;
                            }
                        } elseif ( $item['type'] === 'taxonomy' ) {
                            $referenced = get_term( (int) $item['object_id'], (string) $item['object'] );
                            if ( ! $referenced || is_wp_error( $referenced ) ) {
                                $warnings[] = sprintf( 'Item at index %d references %s term %d but it does not exist — skipped.', $idx, $item['object'], $item['object_id'] );
                                continue;
                            }
                        }
                    }

                    // Validate parent_index points to an EARLIER index that wasn't itself skipped.
                    $parent_idx = array_key_exists( 'parent_index', $item ) ? $item['parent_index'] : null;
                    if ( $parent_idx !== null ) {
                        $parent_idx = (int) $parent_idx;
                        if ( $parent_idx < 0 || $parent_idx >= $idx ) {
                            $warnings[] = sprintf( 'Item at index %d has invalid parent_index %d (must reference an earlier index) — treated as top-level.', $idx, $parent_idx );
                            $parent_idx = null;
                        }
                    }

                    $validated[ $idx ] = [ 'item' => $item, 'parent_index' => $parent_idx ];
                }

                // 2. Delete existing items.
                $existing = wp_get_nav_menu_items( $menu_id );
                $deleted_count = 0;
                if ( is_array( $existing ) ) {
                    foreach ( $existing as $existing_item ) {
                        wp_delete_post( (int) $existing_item->ID, true );
                        $deleted_count++;
                    }
                }

                // 3. Create new items in order, building index -> new_post_id map for parent resolution.
                $index_to_new_id = [];
                $created_count   = 0;

                foreach ( $validated as $idx => $entry ) {
                    $item = $entry['item'];
                    $parent_idx = $entry['parent_index'];
                    $parent_post_id = ( $parent_idx !== null && isset( $index_to_new_id[ $parent_idx ] ) )
                        ? $index_to_new_id[ $parent_idx ]
                        : 0;

                    $item_data = [
                        'menu-item-type'        => (string) $item['type'],
                        'menu-item-title'       => sanitize_text_field( (string) $item['label'] ),
                        'menu-item-status'      => 'publish',
                        'menu-item-parent-id'   => (int) $parent_post_id,
                        'menu-item-target'      => isset( $item['target'] ) && $item['target'] === '_blank' ? '_blank' : '',
                        'menu-item-classes'     => isset( $item['css_classes'] ) && is_array( $item['css_classes'] )
                            ? implode( ' ', array_map( 'sanitize_html_class', $item['css_classes'] ) )
                            : '',
                        'menu-item-description' => isset( $item['description'] ) ? sanitize_text_field( (string) $item['description'] ) : '',
                        'menu-item-xfn'         => isset( $item['xfn'] ) ? sanitize_text_field( (string) $item['xfn'] ) : '',
                    ];

                    if ( $item['type'] === 'custom' ) {
                        $item_data['menu-item-url'] = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
                    } else {
                        $item_data['menu-item-object']    = (string) $item['object'];
                        $item_data['menu-item-object-id'] = (int) $item['object_id'];
                    }

                    $new_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );
                    if ( is_wp_error( $new_id ) ) {
                        $warnings[] = sprintf( 'Failed to create item at index %d: %s', $idx, $new_id->get_error_message() );
                        continue;
                    }
                    if ( ! $new_id ) {
                        $warnings[] = sprintf( 'Failed to create item at index %d (no ID returned).', $idx );
                        continue;
                    }

                    // Restore third-party meta passed back from get-menu.
                    if ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) {
                        foreach ( $item['meta'] as $mk => $mv ) {
                            if ( ! is_string( $mk ) || $mk === '' ) continue;
                            // Don't allow override of internal nav_menu meta keys.
                            if ( strpos( $mk, '_menu_item_' ) === 0 ) continue;
                            update_post_meta( (int) $new_id, $mk, $mv );
                        }
                    }

                    $index_to_new_id[ $idx ] = (int) $new_id;
                    $created_count++;
                }

                return [
                    'success'  => true,
                    'menu_id'  => $menu_id,
                    'created'  => $created_count,
                    'deleted'  => $deleted_count,
                    'warnings' => $warnings,
                    'message'  => sprintf( 'Menu %d updated: %d item(s) created, %d removed.%s', $menu_id, $created_count, $deleted_count, empty( $warnings ) ? '' : sprintf( ' %d warning(s).', count( $warnings ) ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
            ],
        ] );

        // ---- list-menu-locations ----
        wp_register_ability( 'atarim/list-menu-locations', [
            'label'               => 'List Menu Locations',
            'description'         => 'Returns theme-registered menu locations (e.g. "primary", "footer", "social") with the menu currently assigned to each (if any). A location with no assigned menu has assigned_menu_id = 0. The location keys come from the theme; the AI can use them with create-menu / update-menu assign_locations.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'total'     => [ 'type' => 'integer' ],
                    'locations' => [ 'type' => 'array' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $registered = (array) get_registered_nav_menus();
                $assignments = (array) get_nav_menu_locations();

                $locations = [];
                foreach ( $registered as $slug => $label ) {
                    $assigned_id = isset( $assignments[ $slug ] ) ? (int) $assignments[ $slug ] : 0;
                    $assigned_name = '';
                    if ( $assigned_id > 0 ) {
                        $m = wp_get_nav_menu_object( $assigned_id );
                        $assigned_name = $m ? (string) $m->name : '';
                    }
                    $locations[] = [
                        'slug'              => (string) $slug,
                        'label'             => (string) $label,
                        'assigned_menu_id'  => $assigned_id,
                        'assigned_menu_name'=> $assigned_name,
                    ];
                }

                return [
                    'success'   => true,
                    'total'     => count( $locations ),
                    'locations' => $locations,
                    'message'   => sprintf( '%d menu location(s) registered by the active theme.', count( $locations ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- list-widget-areas ----
        wp_register_ability( 'atarim/list-widget-areas', [
            'label'               => 'List Widget Areas',
            'description'         => 'Returns all theme-registered widget areas (sidebars) with the widgets currently in each. Per-widget type detection: each widget reports its own type ("classic" — a traditional widget like text-1 or nav_menu-3, with settings; or "block" — a block-based widget, with block_markup). A single sidebar can contain both types (common when a pre-WP-5.8 site adopts block widgets gradually). Use update-widget-area to replace the widget set.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'           => [ 'type' => 'boolean' ],
                    'total'             => [ 'type' => 'integer' ],
                    'sidebars'          => [ 'type' => 'array' ],
                    'block_widgets_used'=> [ 'type' => 'boolean' ],
                    'message'           => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_registered_sidebars;

                $sidebars_widgets = wp_get_sidebars_widgets();
                $registered = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : [];

                $sidebars = [];
                $any_block = false;

                foreach ( $registered as $sidebar_id => $sidebar_def ) {
                    if ( $sidebar_id === 'wp_inactive_widgets' ) continue;
                    $widget_ids = isset( $sidebars_widgets[ $sidebar_id ] ) ? (array) $sidebars_widgets[ $sidebar_id ] : [];
                    $widgets    = [];
                    foreach ( $widget_ids as $widget_id ) {
                        $info = $this->avcf_unpack_widget( (string) $widget_id );
                        if ( $info['type'] === 'block' ) $any_block = true;
                        $widgets[] = $info;
                    }
                    $sidebars[] = [
                        'id'           => (string) $sidebar_id,
                        'name'         => isset( $sidebar_def['name'] ) ? (string) $sidebar_def['name'] : '',
                        'description'  => isset( $sidebar_def['description'] ) ? (string) $sidebar_def['description'] : '',
                        'widget_count' => count( $widgets ),
                        'widgets'      => $widgets,
                    ];
                }

                // Inactive widgets — surface as a special pseudo-sidebar so the AI can see what's parked.
                if ( isset( $sidebars_widgets['wp_inactive_widgets'] ) && ! empty( $sidebars_widgets['wp_inactive_widgets'] ) ) {
                    $inactive = [];
                    foreach ( (array) $sidebars_widgets['wp_inactive_widgets'] as $widget_id ) {
                        $info = $this->avcf_unpack_widget( (string) $widget_id );
                        $inactive[] = $info;
                    }
                    $sidebars[] = [
                        'id'           => 'wp_inactive_widgets',
                        'name'         => 'Inactive Widgets',
                        'description'  => 'Widgets not currently assigned to any active sidebar.',
                        'widget_count' => count( $inactive ),
                        'widgets'      => $inactive,
                    ];
                }

                return [
                    'success'           => true,
                    'total'             => count( $sidebars ),
                    'sidebars'          => $sidebars,
                    'block_widgets_used'=> $any_block,
                    'message'           => sprintf( '%d sidebar(s); block widgets %s', count( $sidebars ), $any_block ? 'in use somewhere' : 'not currently used' ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-widget-area ----
        wp_register_ability( 'atarim/update-widget-area', [
            'label'               => 'Update Widget Area',
            'description'         => 'REPLACE the widget set for one sidebar. items is an array of widget instances in order. Each item: { type: "classic", widget_type: "text", settings: {title: "...", text: "..."} } OR { type: "block", block_markup: "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->" }. For classic widgets, settings keys depend on the widget_type — see get-widget-types and existing classic widgets in list-widget-areas for shape. For block widgets, block_markup must be valid WordPress block markup (gets validated via parse_blocks). Existing widgets in the sidebar are removed; their underlying settings remain in widget_{type} options for forensics but are no longer referenced. Empty items array clears the sidebar.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sidebar_id' => [ 'type' => 'string', 'minLength' => 1 ],
                    'items' => [
                        'type'        => 'array',
                        'description' => 'New widget set. Empty array clears the sidebar.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'type'         => [ 'type' => 'string', 'enum' => [ 'classic', 'block' ] ],
                                'widget_type'  => [ 'type' => 'string', 'description' => 'For type=classic: the widget type ID (text, nav_menu, categories, etc).' ],
                                'settings'     => [ 'type' => 'object', 'description' => 'For type=classic: the widget instance settings.' ],
                                'block_markup' => [ 'type' => 'string', 'description' => 'For type=block: valid WordPress block markup.' ],
                            ],
                            'required' => [ 'type' ],
                        ],
                    ],
                ],
                'required' => [ 'sidebar_id', 'items' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'sidebar_id' => [ 'type' => 'string' ],
                    'created'    => [ 'type' => 'integer' ],
                    'removed'    => [ 'type' => 'integer' ],
                    'warnings'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_registered_sidebars, $wp_widget_factory;

                $sidebar_id = isset( $input['sidebar_id'] ) ? (string) $input['sidebar_id'] : '';
                if ( $sidebar_id === '' ) {
                    return [ 'success' => false, 'message' => 'sidebar_id is required.' ];
                }
                if ( ! is_array( $wp_registered_sidebars ) || ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Sidebar "%s" is not registered by the active theme.', $sidebar_id ) ];
                }
                $items_in = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : null;
                if ( $items_in === null ) {
                    return [ 'success' => false, 'message' => 'items is required (empty array to clear).' ];
                }

                $warnings = [];

                $sidebars_widgets = wp_get_sidebars_widgets();
                $existing_in_sidebar = isset( $sidebars_widgets[ $sidebar_id ] ) ? (array) $sidebars_widgets[ $sidebar_id ] : [];
                $removed_count = count( $existing_in_sidebar );

                $new_widget_ids = [];

                foreach ( $items_in as $idx => $item ) {
                    if ( ! is_array( $item ) || empty( $item['type'] ) ) {
                        $warnings[] = sprintf( 'Item at index %d is malformed — skipped.', $idx );
                        continue;
                    }

                    if ( $item['type'] === 'classic' ) {
                        if ( empty( $item['widget_type'] ) ) {
                            $warnings[] = sprintf( 'Item at index %d is type=classic but missing widget_type — skipped.', $idx );
                            continue;
                        }
                        $widget_type = sanitize_key( (string) $item['widget_type'] );
                        $settings    = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : [];

                        // Validate widget_type exists in the factory.
                        $type_exists = false;
                        if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
                            foreach ( $wp_widget_factory->widgets as $w ) {
                                if ( method_exists( $w, 'id_base' ) ) {
                                    if ( $w->id_base === $widget_type ) { $type_exists = true; break; }
                                } elseif ( property_exists( $w, 'id_base' ) ) {
                                    if ( $w->id_base === $widget_type ) { $type_exists = true; break; }
                                }
                            }
                        }
                        if ( ! $type_exists ) {
                            $warnings[] = sprintf( 'Item at index %d uses widget_type "%s" which is not registered — skipped.', $idx, $widget_type );
                            continue;
                        }

                        // Allocate a new instance number for this widget_type.
                        $option_key = 'widget_' . $widget_type;
                        $instances = get_option( $option_key );
                        if ( ! is_array( $instances ) ) $instances = [];
                        $next_n = 1;
                        foreach ( array_keys( $instances ) as $k ) {
                            if ( is_numeric( $k ) && (int) $k >= $next_n ) {
                                $next_n = ( (int) $k ) + 1;
                            }
                        }
                        $instances[ $next_n ] = $settings;
                        // _multiwidget marker is required for multi-widget storage.
                        $instances['_multiwidget'] = 1;
                        update_option( $option_key, $instances );

                        $new_widget_ids[] = $widget_type . '-' . $next_n;
                        continue;
                    }

                    if ( $item['type'] === 'block' ) {
                        $markup = isset( $item['block_markup'] ) ? (string) $item['block_markup'] : '';
                        if ( $markup === '' ) {
                            $warnings[] = sprintf( 'Item at index %d is type=block but block_markup is empty — skipped.', $idx );
                            continue;
                        }
                        // Validate parseable.
                        $parsed = function_exists( 'parse_blocks' ) ? parse_blocks( $markup ) : null;
                        if ( ! is_array( $parsed ) || empty( $parsed ) ) {
                            $warnings[] = sprintf( 'Item at index %d block_markup is not valid block markup — skipped.', $idx );
                            continue;
                        }

                        // Block widgets are stored as widget_block instances with { content: markup }.
                        $option_key = 'widget_block';
                        $instances = get_option( $option_key );
                        if ( ! is_array( $instances ) ) $instances = [];
                        $next_n = 1;
                        foreach ( array_keys( $instances ) as $k ) {
                            if ( is_numeric( $k ) && (int) $k >= $next_n ) {
                                $next_n = ( (int) $k ) + 1;
                            }
                        }
                        $instances[ $next_n ] = [ 'content' => $markup ];
                        $instances['_multiwidget'] = 1;
                        update_option( $option_key, $instances );

                        $new_widget_ids[] = 'block-' . $next_n;
                        continue;
                    }

                    $warnings[] = sprintf( 'Item at index %d has unknown type "%s" — skipped.', $idx, $item['type'] );
                }

                // Update the sidebars_widgets option for this sidebar only.
                $sidebars_widgets[ $sidebar_id ] = $new_widget_ids;
                wp_set_sidebars_widgets( $sidebars_widgets );

                return [
                    'success'    => true,
                    'sidebar_id' => $sidebar_id,
                    'created'    => count( $new_widget_ids ),
                    'removed'    => $removed_count,
                    'warnings'   => $warnings,
                    'message'    => sprintf( 'Sidebar "%s" updated: %d widget(s) set, %d removed.%s', $sidebar_id, count( $new_widget_ids ), $removed_count, empty( $warnings ) ? '' : sprintf( ' %d warning(s).', count( $warnings ) ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );

        // ---- get-widget-types ----
        wp_register_ability( 'atarim/get-widget-types', [
            'label'               => 'Get Widget Types',
            'description'         => 'Returns the catalogue of classic widget TYPES registered on the site (text, nav_menu, categories, recent_posts, etc., plus any added by plugins). Each entry shows the type ID and human-readable name. Block widgets are not enumerated here because they use the full Gutenberg block library — for block widgets, use any block type from the standard WordPress core/* block namespace.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'total'   => [ 'type' => 'integer' ],
                    'types'   => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_widget_factory;
                $types = [];
                if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
                    foreach ( $wp_widget_factory->widgets as $widget ) {
                        $id_base = property_exists( $widget, 'id_base' ) ? (string) $widget->id_base : '';
                        $name    = property_exists( $widget, 'name' ) ? (string) $widget->name : '';
                        $desc    = ( property_exists( $widget, 'widget_options' ) && is_array( $widget->widget_options ) && isset( $widget->widget_options['description'] ) )
                            ? (string) $widget->widget_options['description']
                            : '';
                        if ( $id_base === '' ) continue;
                        $types[] = [
                            'id_base'     => $id_base,
                            'name'        => $name,
                            'description' => $desc,
                        ];
                    }
                }
                usort( $types, function( $a, $b ) {
                    return strcmp( $a['id_base'], $b['id_base'] );
                } );

                return [
                    'success' => true,
                    'total'   => count( $types ),
                    'types'   => $types,
                    'message' => sprintf( '%d classic widget type(s) registered.', count( $types ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    /**
     * Unpack a widget instance ID like "text-3" or "block-1" into its
     * type, settings, and (for block widgets) block markup.
     *
     * @param string $widget_id
     * @return array
     */
    private function avcf_unpack_widget( $widget_id ) {
        // Widget IDs are {id_base}-{n}, where id_base may itself contain hyphens (rare but valid).
        if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
            return [
                'id'      => $widget_id,
                'type'    => 'classic',
                'widget_type' => $widget_id,
                'instance_n'  => 0,
                'settings'    => [],
            ];
        }
        $id_base = $m[1];
        $n       = (int) $m[2];

        if ( $id_base === 'block' ) {
            $instances = get_option( 'widget_block' );
            $markup    = '';
            if ( is_array( $instances ) && isset( $instances[ $n ]['content'] ) ) {
                $markup = (string) $instances[ $n ]['content'];
            }
            return [
                'id'           => $widget_id,
                'type'         => 'block',
                'instance_n'   => $n,
                'block_markup' => $markup,
            ];
        }

        $instances = get_option( 'widget_' . $id_base );
        $settings  = ( is_array( $instances ) && isset( $instances[ $n ] ) && is_array( $instances[ $n ] ) ) ? $instances[ $n ] : [];

        return [
            'id'          => $widget_id,
            'type'        => 'classic',
            'widget_type' => $id_base,
            'instance_n'  => $n,
            'settings'    => $settings,
        ];
    }
}
