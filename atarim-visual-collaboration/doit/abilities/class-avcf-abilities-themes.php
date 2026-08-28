<?php
/**
 * Theme management MCP abilities.
 *
 * Registers Atarim/* abilities for installing, switching, updating, and
 * removing WordPress themes via the AI action layer. Mirrors the plugin
 * lifecycle abilities (class-avcf-abilities-plugins.php) where possible —
 * same WP_Upgrader pattern, same silent skin, same shape of response.
 *
 * Exposed abilities:
 *   atarim/list-themes               All installed themes + update info.
 *   atarim/install-theme             Install a free theme from WordPress.org.
 *   atarim/activate-theme            Switch the active theme (with safety checks).
 *   atarim/update-theme              Update an installed theme to latest.
 *   atarim/delete-theme              Permanently remove a theme from disk.
 *
 * Compatibility checks on activate-theme:
 *   Hard fail — theme missing, broken (errors()), WP version too old,
 *               PHP version too old, child theme with missing parent.
 *   Soft warn — block-vs-classic switch, WooCommerce version, multisite hints.
 *
 * Note: ability names registered here must also be added to the $tools array
 * in doit/class-avcf-mcp.php::avcf_mcp_setup_server() to be exposed by the
 * MCP server.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Themes extends AVCF_Abilities_Base {

    /**
     * Register all theme management abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {

        // ---- list-themes ----
        wp_register_ability( 'atarim/list-themes', [
            'label'               => 'List Themes',
            'description'         => 'Returns all installed WordPress themes with their version, author, status (active/inactive), block-theme vs classic flag, parent theme for child themes, and update availability.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Filter by activation status. Omit for all.',
                        'enum'        => [ 'active', 'inactive', 'all' ],
                        'default'     => 'all',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'  => [ 'type' => 'integer' ],
                    'themes' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'stylesheet'       => [ 'type' => 'string' ],
                                'name'             => [ 'type' => 'string' ],
                                'version'          => [ 'type' => 'string' ],
                                'author'           => [ 'type' => 'string' ],
                                'description'      => [ 'type' => 'string' ],
                                'status'           => [ 'type' => 'string' ],
                                'is_block_theme'   => [ 'type' => 'boolean' ],
                                'is_child_theme'   => [ 'type' => 'boolean' ],
                                'parent'           => [ 'type' => 'string' ],
                                'requires_wp'      => [ 'type' => 'string' ],
                                'requires_php'     => [ 'type' => 'string' ],
                                'update_available' => [ 'type' => 'boolean' ],
                                'new_version'      => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'themes' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $status_filter = isset( $input['status'] ) ? $input['status'] : 'all';
                $all_themes    = wp_get_themes();
                $active_slug   = get_stylesheet();

                // See atarim/list-plugins: a stale transient reports every theme as
                // up to date, so refresh before reading.
                wp_update_themes();

                $updates       = get_site_transient( 'update_themes' );
                $update_list   = ( $updates && ! empty( $updates->response ) ) ? $updates->response : [];

                $themes = [];
                foreach ( $all_themes as $stylesheet => $theme ) {
                    $is_active      = ( $stylesheet === $active_slug );
                    $current_status = $is_active ? 'active' : 'inactive';

                    if ( $status_filter !== 'all' && $status_filter !== $current_status ) {
                        continue;
                    }

                    $is_child  = ( $theme->parent() !== false );
                    $parent    = $is_child ? $theme->parent()->get_stylesheet() : '';
                    $has_update  = isset( $update_list[ $stylesheet ] );
                    $new_version = $has_update ? $update_list[ $stylesheet ]['new_version'] : '';

                    $themes[] = [
                        'stylesheet'       => $stylesheet,
                        'name'             => $theme->get( 'Name' ),
                        'version'          => $theme->get( 'Version' ),
                        'author'           => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
                        'description'      => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
                        'status'           => $current_status,
                        'is_block_theme'   => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
                        'is_child_theme'   => $is_child,
                        'parent'           => $parent,
                        'requires_wp'      => (string) $theme->get( 'RequiresWP' ),
                        'requires_php'     => (string) $theme->get( 'RequiresPHP' ),
                        'update_available' => $has_update,
                        'new_version'      => $new_version,
                    ];
                }

                return [
                    'total'  => count( $themes ),
                    'themes' => $themes,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'switch_themes' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // ---- install-theme ----
        wp_register_ability( 'atarim/install-theme', [
            'label'               => 'Install Theme',
            'description'         => 'Installs a free theme from the WordPress.org theme repository. Uses the WordPress upgrader so files are downloaded, verified, and unpacked into wp-content/themes/. The theme is NOT activated by installation — call activate-theme separately. For paid/third-party themes use upload-from-URL or upload-from-ZIP (not yet supported by this ability).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Theme slug (folder name) as it appears on wordpress.org/themes (e.g. "twentytwentyfour", "astra").',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'stylesheet' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'stylesheet' => [ 'type' => 'string' ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'stylesheet', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
                if ( empty( $stylesheet ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => '',
                        'message'    => 'Theme stylesheet is required.',
                    ];
                }

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/theme.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                // Query wordpress.org for the theme — confirms free repo and gets verified download.
                $api = themes_api( 'theme_information', [
                    'slug'   => $stylesheet,
                    'fields' => [ 'sections' => false ],
                ] );

                if ( is_wp_error( $api ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'Theme not found in WordPress.org repository: ' . $api->get_error_message(),
                    ];
                }

                if ( empty( $api->download_link ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'No download link available — only free WordPress.org themes are supported.',
                    ];
                }

                $skin     = new \WP_Ajax_Upgrader_Skin();
                $upgrader = new \Theme_Upgrader( $skin );
                $result   = $upgrader->install( $api->download_link );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'Install failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === false ) {
                    $skin_errors = $skin->get_errors();
                    $err_msg     = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
                        ? $skin_errors->get_error_message()
                        : 'Unknown installer error (filesystem permissions or unavailable updates).';
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'Install failed: ' . $err_msg,
                    ];
                }

                return [
                    'success'    => true,
                    'stylesheet' => $stylesheet,
                    'message'    => 'Theme installed successfully. Call activate-theme separately to switch to it.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'install_themes' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // ---- activate-theme ----
        wp_register_ability( 'atarim/activate-theme', [
            'label'               => 'Activate Theme',
            'description'         => 'Switches the active theme. Only one theme can be active at a time — the previously active theme is implicitly deactivated. Performs hard-fail compatibility checks (theme exists, not broken, WordPress / PHP version requirements met, child theme parent present) and returns soft warnings for fuzzy compatibility concerns (block-vs-classic switch, plugin compatibility hints).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug of the theme to activate.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'stylesheet' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'         => [ 'type' => 'boolean' ],
                    'stylesheet'      => [ 'type' => 'string' ],
                    'previous'        => [ 'type' => 'string' ],
                    'is_block_theme'  => [ 'type' => 'boolean' ],
                    'warnings'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'         => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'stylesheet', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
                if ( empty( $stylesheet ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => '',
                        'previous'   => '',
                        'warnings'   => [],
                        'message'    => 'Theme stylesheet is required.',
                    ];
                }

                $theme = wp_get_theme( $stylesheet );

                // --- Hard fails ---

                if ( ! $theme->exists() ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'previous'   => get_stylesheet(),
                        'warnings'   => [],
                        'message'    => sprintf( 'Theme "%s" is not installed. Use install-theme first.', $stylesheet ),
                    ];
                }

                if ( $theme->errors() ) {
                    $errors = $theme->errors()->get_error_messages();
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'previous'   => get_stylesheet(),
                        'warnings'   => [],
                        'message'    => 'Theme is broken: ' . implode( '; ', $errors ),
                    ];
                }

                $requires_wp  = (string) $theme->get( 'RequiresWP' );
                $requires_php = (string) $theme->get( 'RequiresPHP' );

                if ( $requires_wp !== '' ) {
                    global $wp_version;
                    if ( version_compare( $wp_version, $requires_wp, '<' ) ) {
                        return [
                            'success'    => false,
                            'stylesheet' => $stylesheet,
                            'previous'   => get_stylesheet(),
                            'warnings'   => [],
                            'message'    => sprintf(
                                'Theme requires WordPress %s; this site runs %s. Update WordPress before activating.',
                                $requires_wp,
                                $wp_version
                            ),
                        ];
                    }
                }

                if ( $requires_php !== '' ) {
                    if ( version_compare( PHP_VERSION, $requires_php, '<' ) ) {
                        return [
                            'success'    => false,
                            'stylesheet' => $stylesheet,
                            'previous'   => get_stylesheet(),
                            'warnings'   => [],
                            'message'    => sprintf(
                                'Theme requires PHP %s; this site runs %s. Upgrade PHP before activating.',
                                $requires_php,
                                PHP_VERSION
                            ),
                        ];
                    }
                }

                // Child theme: parent must be installed and not broken.
                if ( $theme->parent() !== false ) {
                    $parent = $theme->parent();
                    if ( ! $parent->exists() || $parent->errors() ) {
                        return [
                            'success'    => false,
                            'stylesheet' => $stylesheet,
                            'previous'   => get_stylesheet(),
                            'warnings'   => [],
                            'message'    => sprintf(
                                'Child theme "%s" requires parent theme "%s", which is missing or broken.',
                                $stylesheet,
                                $theme->get_template()
                            ),
                        ];
                    }
                }

                // --- Soft warnings ---
                $warnings = [];

                $previous_slug   = get_stylesheet();
                $previous_theme  = wp_get_theme( $previous_slug );
                $previous_is_block = ( $previous_theme->exists() && method_exists( $previous_theme, 'is_block_theme' ) )
                    ? $previous_theme->is_block_theme()
                    : false;
                $new_is_block = method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false;

                if ( $previous_is_block && ! $new_is_block ) {
                    $warnings[] = 'Switching from a block theme to a classic theme. Full Site Editing templates from the previous theme will no longer be editable; the site falls back to PHP templates.';
                } elseif ( ! $previous_is_block && $new_is_block ) {
                    $warnings[] = 'Switching from a classic theme to a block theme. Existing classic-editor posts will render fine, but template editing moves to the Site Editor (Full Site Editing). Customizer options from the previous theme will not carry over.';
                }

                // WooCommerce hint — if Woo is active, warn when the theme declares it supports it OR makes no declaration.
                if ( class_exists( 'WooCommerce' ) ) {
                    $supports_woo = (string) $theme->get( 'WC tested up to' );
                    if ( $supports_woo === '' ) {
                        $warnings[] = 'WooCommerce is active on this site but the new theme does not declare WooCommerce compatibility. Verify product / cart / checkout pages render correctly after switching.';
                    } elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, $supports_woo, '>' ) ) {
                        $warnings[] = sprintf(
                            'Theme declares WooCommerce compatibility up to %s; this site runs WooCommerce %s.',
                            $supports_woo,
                            WC_VERSION
                        );
                    }
                }

                // --- Switch the theme ---
                switch_theme( $stylesheet );

                // switch_theme has no return value; verify by reading back.
                if ( get_stylesheet() !== $stylesheet ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'previous'   => $previous_slug,
                        'warnings'   => $warnings,
                        'message'    => 'Theme switch failed: WordPress did not register the new active theme.',
                    ];
                }

                return [
                    'success'        => true,
                    'stylesheet'     => $stylesheet,
                    'previous'       => $previous_slug,
                    'is_block_theme' => $new_is_block,
                    'warnings'       => $warnings,
                    'message'        => sprintf(
                        'Theme switched to "%s" (was "%s"). %s',
                        $theme->get( 'Name' ),
                        $previous_theme->exists() ? $previous_theme->get( 'Name' ) : $previous_slug,
                        empty( $warnings ) ? 'No compatibility warnings.' : sprintf( '%d warning(s) returned.', count( $warnings ) )
                    ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'switch_themes' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // ---- update-theme ----
        wp_register_ability( 'atarim/update-theme', [
            'label'               => 'Update Theme',
            'description'         => 'Updates an installed theme to the latest version available from its source (WordPress.org for repo themes, the theme\'s own update server for paid themes that have registered their update mechanism). Uses the WordPress upgrader; falls back gracefully if no update is available.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug of the theme to update.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'stylesheet' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'stylesheet'     => [ 'type' => 'string' ],
                    'from_version'   => [ 'type' => 'string' ],
                    'to_version'     => [ 'type' => 'string' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'stylesheet', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
                if ( empty( $stylesheet ) ) {
                    return [
                        'success'      => false,
                        'stylesheet'   => '',
                        'from_version' => '',
                        'to_version'   => '',
                        'message'      => 'Theme stylesheet is required.',
                    ];
                }

                $theme = wp_get_theme( $stylesheet );
                if ( ! $theme->exists() ) {
                    return [
                        'success'      => false,
                        'stylesheet'   => $stylesheet,
                        'from_version' => '',
                        'to_version'   => '',
                        'message'      => sprintf( 'Theme "%s" is not installed.', $stylesheet ),
                    ];
                }

                $from_version = (string) $theme->get( 'Version' );

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/theme.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                // Refresh the update transient so we have current update info.
                wp_update_themes();

                $updates     = get_site_transient( 'update_themes' );
                $update_list = ( $updates && ! empty( $updates->response ) ) ? $updates->response : [];

                if ( ! isset( $update_list[ $stylesheet ] ) ) {
                    return [
                        'success'      => true,
                        'stylesheet'   => $stylesheet,
                        'from_version' => $from_version,
                        'to_version'   => $from_version,
                        'message'      => 'No update available — theme is already at the latest version.',
                    ];
                }

                $skin     = new \WP_Ajax_Upgrader_Skin();
                $upgrader = new \Theme_Upgrader( $skin );
                $result   = $upgrader->upgrade( $stylesheet );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'      => false,
                        'stylesheet'   => $stylesheet,
                        'from_version' => $from_version,
                        'to_version'   => '',
                        'message'      => 'Update failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === false ) {
                    $skin_errors = $skin->get_errors();
                    $err_msg     = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
                        ? $skin_errors->get_error_message()
                        : 'Unknown updater error (filesystem permissions or download failed).';
                    return [
                        'success'      => false,
                        'stylesheet'   => $stylesheet,
                        'from_version' => $from_version,
                        'to_version'   => '',
                        'message'      => 'Update failed: ' . $err_msg,
                    ];
                }

                // Re-read the theme to confirm the new version.
                wp_clean_themes_cache();
                $fresh        = wp_get_theme( $stylesheet );
                $to_version   = (string) $fresh->get( 'Version' );

                return [
                    'success'      => true,
                    'stylesheet'   => $stylesheet,
                    'from_version' => $from_version,
                    'to_version'   => $to_version,
                    'message'      => sprintf( 'Theme updated from %s to %s.', $from_version, $to_version ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'update_themes' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // ---- delete-theme ----
        wp_register_ability( 'atarim/delete-theme', [
            'label'               => 'Delete Theme',
            'description'         => 'Permanently removes a theme from disk (wp-content/themes/{stylesheet}/). Refuses to delete the currently active theme — switch to a different theme first. Refuses to delete a parent theme that has an installed child. Irreversible: no trash, no recovery without reinstalling.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug of the theme to delete.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'stylesheet' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'stylesheet' => [ 'type' => 'string' ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'stylesheet', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $stylesheet = isset( $input['stylesheet'] ) ? sanitize_key( $input['stylesheet'] ) : '';
                if ( empty( $stylesheet ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => '',
                        'message'    => 'Theme stylesheet is required.',
                    ];
                }

                $theme = wp_get_theme( $stylesheet );
                if ( ! $theme->exists() ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => sprintf( 'Theme "%s" is not installed; nothing to delete.', $stylesheet ),
                    ];
                }

                if ( $stylesheet === get_stylesheet() || $stylesheet === get_template() ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => sprintf(
                            'Cannot delete "%s" because it is the currently active theme. Switch to a different theme first.',
                            $stylesheet
                        ),
                    ];
                }

                // Check if any installed theme has this one as its parent.
                $all_themes = wp_get_themes();
                $children   = [];
                foreach ( $all_themes as $other_slug => $other ) {
                    if ( $other_slug === $stylesheet ) {
                        continue;
                    }
                    if ( $other->parent() !== false && $other->get_template() === $stylesheet ) {
                        $children[] = $other_slug;
                    }
                }

                if ( ! empty( $children ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => sprintf(
                            'Cannot delete "%s" because it is the parent of installed child theme(s): %s. Delete the child first or switch dependency.',
                            $stylesheet,
                            implode( ', ', $children )
                        ),
                    ];
                }

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/theme.php';

                $result = delete_theme( $stylesheet );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'Delete failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === false ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'Delete failed: WordPress reported the operation did not complete (filesystem permissions or theme.php unavailable).',
                    ];
                }

                if ( $result === null ) {
                    return [
                        'success'    => false,
                        'stylesheet' => $stylesheet,
                        'message'    => 'Delete failed: filesystem credentials were required and could not be obtained.',
                    ];
                }

                return [
                    'success'    => true,
                    'stylesheet' => $stylesheet,
                    'message'    => sprintf( 'Theme "%s" deleted successfully.', $stylesheet ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'delete_themes' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // ---- get-additional-css ----
        wp_register_ability( 'atarim/get-additional-css', [
            'label'               => 'Get Additional CSS',
            'description'         => 'Returns the site-wide Additional CSS (the Customizer > Additional CSS panel) for a theme. WordPress stores this as a per-theme custom_css post and prints it inline in the <head> on every front-end page - it is not a file on disk. Defaults to the active theme.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Theme stylesheet slug (folder name) whose Additional CSS to read. Omit for the active theme.',
                        'minLength'   => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'stylesheet' => [ 'type' => 'string' ],
                    'css'        => [ 'type' => 'string' ],
                    'sha1'       => [ 'type' => 'string' ],
                    'bytes'      => [ 'type' => 'integer' ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $stylesheet = isset( $input['stylesheet'] ) && $input['stylesheet'] !== ''
                    ? sanitize_text_field( (string) $input['stylesheet'] )
                    : get_stylesheet();

                if ( ! wp_get_theme( $stylesheet )->exists() ) {
                    return [ 'success' => false, 'message' => sprintf( 'Theme "%s" is not installed.', $stylesheet ) ];
                }

                $css = (string) wp_get_custom_css( $stylesheet );

                return [
                    'success'    => true,
                    'stylesheet' => $stylesheet,
                    'css'        => $css,
                    'sha1'       => sha1( $css ),
                    'bytes'      => strlen( $css ),
                    'message'    => ( '' === $css ) ? 'No Additional CSS is set for this theme.' : sprintf( 'OK. Pass this sha1 as expected_sha1 to set-additional-css for a safe replace. (%d bytes)', strlen( $css ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // ---- set-additional-css ----
        wp_register_ability( 'atarim/set-additional-css', [
            'label'               => 'Set Additional CSS',
            'description'         => 'Sets the site-wide Additional CSS (Customizer > Additional CSS) for a theme. Accepts RAW CSS ONLY - do NOT pass Gutenberg block markup, HTML tags, <p> / <br>, or block comments. The value is written through the native WordPress custom-CSS pipeline (wp_update_custom_css_post) and printed inline exactly like the Customizer panel; it is not written to a file. Incoming content is defensively normalised (block comments and tags stripped, HTML entities decoded, smart quotes fixed) but callers should still send clean CSS. Defaults to the active theme. mode "replace" (default) overwrites all Additional CSS; "append" adds the given CSS after the existing CSS.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'css' => [
                        'type'        => 'string',
                        'description' => 'Raw CSS to store. No HTML, no block markup, no block comments.',
                    ],
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Theme stylesheet slug (folder name) to target. Omit for the active theme.',
                        'minLength'   => 1,
                    ],
                    'mode' => [
                        'type'        => 'string',
                        'description' => '"replace" overwrites all Additional CSS (default). "append" adds the given CSS after the existing CSS.',
                        'enum'        => [ 'replace', 'append' ],
                        'default'     => 'replace',
                    ],
                    'expected_sha1' => [
                        'type'        => 'string',
                        'description' => 'Optional optimistic-concurrency guard. If given, the write proceeds only when the CURRENT Additional CSS has this sha1 (get it from get-additional-css). If it does not match, the write is refused with the current sha1 so you can re-read and retry — preventing a blind replace from clobbering a change made since you read. Omit to force the write.',
                    ],
                ],
                'required'             => [ 'css' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'      => [ 'type' => 'boolean' ],
                    'conflict'     => [ 'type' => 'boolean' ],
                    'stylesheet'   => [ 'type' => 'string' ],
                    'mode'         => [ 'type' => 'string' ],
                    'changed'      => [ 'type' => 'boolean' ],
                    'before_sha1'  => [ 'type' => 'string' ],
                    'stored_sha1'  => [ 'type' => 'string' ],
                    'stored_bytes' => [ 'type' => 'integer' ],
                    'current_sha1' => [ 'type' => 'string' ],
                    'message'      => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! isset( $input['css'] ) || ! is_string( $input['css'] ) ) {
                    return [ 'success' => false, 'message' => 'css is required and must be a string.' ];
                }

                $stylesheet = isset( $input['stylesheet'] ) && $input['stylesheet'] !== ''
                    ? sanitize_text_field( (string) $input['stylesheet'] )
                    : get_stylesheet();

                if ( ! wp_get_theme( $stylesheet )->exists() ) {
                    return [ 'success' => false, 'message' => sprintf( 'Theme "%s" is not installed.', $stylesheet ) ];
                }

                $mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'replace';
                if ( ! in_array( $mode, [ 'replace', 'append' ], true ) ) {
                    $mode = 'replace';
                }

                // Current stored CSS (raw) for the concurrency guard and change reporting.
                $existing_raw = (string) wp_get_custom_css( $stylesheet );
                $before_sha1  = sha1( $existing_raw );

                // Optimistic-concurrency guard: only proceed if the current CSS is
                // what the caller expected. Stops a blind replace from silently
                // clobbering a change made since the caller last read the value.
                if ( isset( $input['expected_sha1'] ) && '' !== (string) $input['expected_sha1'] ) {
                    if ( $before_sha1 !== (string) $input['expected_sha1'] ) {
                        return [
                            'success'      => false,
                            'conflict'     => true,
                            'stylesheet'   => $stylesheet,
                            'current_sha1' => $before_sha1,
                            'message'      => 'Conflict: the current Additional CSS does not match expected_sha1 (it changed since you read it). Re-read it with get-additional-css and retry with the new sha1, or omit expected_sha1 to force the write.',
                        ];
                    }
                }

                $css = $this->avcf_normalize_css( (string) $input['css'] );

                if ( 'append' === $mode ) {
                    $css = ( '' !== trim( $existing_raw ) ) ? rtrim( $existing_raw ) . "\n\n" . $css : $css;
                }

                $result = wp_update_custom_css_post( $css, [ 'stylesheet' => $stylesheet ] );

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $result->get_error_message() ];
                }

                // Receipt from the re-read stored value (no full-CSS echo).
                $stored      = (string) wp_get_custom_css( $stylesheet );
                $stored_sha1 = sha1( $stored );

                return [
                    'success'      => true,
                    'stylesheet'   => $stylesheet,
                    'mode'         => $mode,
                    'changed'      => ( $before_sha1 !== $stored_sha1 ),
                    'before_sha1'  => $before_sha1,
                    'stored_sha1'  => $stored_sha1,
                    'stored_bytes' => strlen( $stored ),
                    'message'      => sprintf( 'Additional CSS %s for theme "%s" (%d bytes). Use stored_sha1 as expected_sha1 for your next safe replace.', ( 'append' === $mode ? 'appended' : 'updated' ), $stylesheet, strlen( $stored ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => true,
                ],
            ],
        ] );
    }

    /**
     * Defensively normalise a CSS string that may have been copied out of the
     * block editor. Strips Gutenberg block comments and HTML tags, converts
     * <br> and closing block tags to newlines, decodes HTML entities (so e.g.
     * a child combinator encoded as &gt; is restored), and converts smart
     * quotes to straight quotes so content declarations stay valid. Genuine
     * CSS selectors and comment blocks are preserved.
     *
     * @param string $css
     * @return string
     */
    private function avcf_normalize_css( $css ) {
        if ( ! is_string( $css ) || '' === $css ) {
            return '';
        }

        // Normalise line endings.
        $css = str_replace( [ "\r\n", "\r" ], "\n", $css );

        // Protect CSS comment blocks (/* ... */) before any HTML-tag munging:
        // their contents may legitimately contain "<...>" (e.g. documentation or
        // selector examples), which the tag-strip below would otherwise destroy.
        $avcf_css_comments = [];
        $css = preg_replace_callback( '#/\*.*?\*/#s', function( $m ) use ( &$avcf_css_comments ) {
            $token = '%%AVCF_CSSCOMMENT_' . count( $avcf_css_comments ) . '%%';
            $avcf_css_comments[] = $m[0];
            return $token;
        }, $css );

        // Remove HTML comments, including Gutenberg block delimiters (<!-- wp:... -->).
        $css = preg_replace( '/<!--.*?-->/s', '', $css );

        // Convert <br> and closing block tags to newlines before stripping tags.
        $css = preg_replace( '/<br\s*\/?>/i', "\n", $css );
        $css = preg_replace( '#</(p|div|pre|code)>#i', "\n", $css );

        // Strip any remaining HTML tags. CSS uses no "< ... >" constructs, so
        // combinators (>, +, ~) and attribute selectors are left intact.
        $css = preg_replace( '/<[^>]+>/', '', $css );

        // Decode entities the editor may have introduced (&gt; &amp; &nbsp; ...).
        $css = html_entity_decode( $css, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Smart quotes -> straight quotes.
        $css = preg_replace( '/[\x{2018}\x{2019}\x{201A}\x{201B}\x{2032}]/u', "'", $css );
        $css = preg_replace( '/[\x{201C}\x{201D}\x{201E}\x{201F}\x{2033}]/u', '"', $css );

        // Non-breaking spaces -> normal spaces.
        $css = str_replace( "\xC2\xA0", ' ', $css );

        // Collapse 3+ newlines to a single blank line, then trim.
        $css = preg_replace( "/\n{3,}/", "\n\n", $css );

        // Restore protected CSS comment blocks verbatim.
        if ( $avcf_css_comments ) {
            $tokens = [];
            foreach ( array_keys( $avcf_css_comments ) as $i ) {
                $tokens[] = '%%AVCF_CSSCOMMENT_' . $i . '%%';
            }
            $css = str_replace( $tokens, $avcf_css_comments, $css );
        }

        return trim( $css );
    }
}