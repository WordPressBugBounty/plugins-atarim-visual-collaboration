<?php
/**
 * Plugin management MCP abilities.
 *
 * Registers Atarim/* abilities for installing, activating, updating, and
 * removing WordPress plugins via the AI action layer. Works with both free
 * WordPress.org plugins (via the WP_Repo) and paid third-party plugins that
 * register updates through their own update servers.
 *
 * Exposed abilities:
 *   atarim/list-plugins              All installed plugins + update info.
 *   atarim/install-plugin            Install a free plugin from WordPress.org.
 *   atarim/activate-plugin           Activate an installed plugin.
 *   atarim/update-plugin             Update an installed plugin to latest.
 *   atarim/deactivate-plugin         Deactivate an active plugin.
 *   atarim/delete-plugin             Permanently remove a plugin from disk.
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

class AVCF_Abilities_Plugins extends AVCF_Abilities_Base {

    /**
     * Register all plugin management abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {
        // Ensure plugin functions are available in non-admin contexts (MCP requests).
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // ---- list-plugins ----
        wp_register_ability( 'atarim/list-plugins', [
            'label'               => 'List Plugins',
            'description'         => 'Returns all installed WordPress plugins with their status, version, author, and update availability.',
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
                    'total'   => [ 'type' => 'integer' ],
                    'plugins' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'             => [ 'type' => 'string' ],
                                'plugin_file'      => [ 'type' => 'string' ],
                                'name'             => [ 'type' => 'string' ],
                                'version'          => [ 'type' => 'string' ],
                                'author'           => [ 'type' => 'string' ],
                                'description'      => [ 'type' => 'string' ],
                                'status'           => [ 'type' => 'string' ],
                                'network_active'   => [ 'type' => 'boolean' ],
                                'requires_wp'      => [ 'type' => 'string' ],
                                'requires_php'     => [ 'type' => 'string' ],
                                'update_available' => [ 'type' => 'boolean' ],
                                'new_version'      => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'plugins' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'get_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $status_filter = isset( $input['status'] ) ? $input['status'] : 'all';
                $all_plugins   = get_plugins();
                $updates       = get_site_transient( 'update_plugins' );
                $update_list   = ( $updates && ! empty( $updates->response ) ) ? $updates->response : [];

                $plugins = [];
                foreach ( $all_plugins as $plugin_file => $data ) {
                    $is_active         = is_plugin_active( $plugin_file );
                    $is_network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );
                    $current_status    = $is_active ? 'active' : 'inactive';

                    if ( $status_filter !== 'all' && $status_filter !== $current_status ) {
                        continue;
                    }

                    $slug = dirname( $plugin_file );
                    if ( $slug === '.' ) {
                        // Single-file plugin
                        $slug = basename( $plugin_file, '.php' );
                    }

                    $has_update  = isset( $update_list[ $plugin_file ] );
                    $new_version = $has_update ? $update_list[ $plugin_file ]->new_version : '';

                    $plugins[] = [
                        'slug'             => $slug,
                        'plugin_file'      => $plugin_file,
                        'name'             => isset( $data['Name'] ) ? $data['Name'] : '',
                        'version'          => isset( $data['Version'] ) ? $data['Version'] : '',
                        'author'           => isset( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '',
                        'description'      => isset( $data['Description'] ) ? wp_strip_all_tags( $data['Description'] ) : '',
                        'status'           => $current_status,
                        'network_active'   => $is_network_active,
                        'requires_wp'      => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
                        'requires_php'     => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
                        'update_available' => $has_update,
                        'new_version'      => $new_version,
                    ];
                }

                return [
                    'total'   => count( $plugins ),
                    'plugins' => $plugins,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'activate_plugins' );
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

        // ---- install-plugin (free plugins only, from wordpress.org) ----
        wp_register_ability( 'atarim/install-plugin', [
            'label'               => 'Install Plugin',
            'description'         => 'Installs a free plugin from the WordPress.org repository by its slug. Does not activate it.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'The WordPress.org plugin slug (e.g. "contact-form-7"). Must exist in the free repository.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'slug' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'slug'        => [ 'type' => 'string' ],
                    'plugin_file' => [ 'type' => 'string' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'slug', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $slug = isset( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';
                if ( empty( $slug ) ) {
                    return [
                        'success'     => false,
                        'slug'        => '',
                        'plugin_file' => '',
                        'message'     => 'Plugin slug is required.',
                    ];
                }

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                // Query wordpress.org for the plugin — confirms it's a free repo plugin
                // and gets the verified download_link (signed by w.org).
                $api = plugins_api( 'plugin_information', [
                    'slug'   => $slug,
                    'fields' => [ 'sections' => false ],
                ] );

                if ( is_wp_error( $api ) ) {
                    return [
                        'success'     => false,
                        'slug'        => $slug,
                        'plugin_file' => '',
                        'message'     => 'Plugin not found in WordPress.org repository: ' . $api->get_error_message(),
                    ];
                }

                if ( empty( $api->download_link ) ) {
                    return [
                        'success'     => false,
                        'slug'        => $slug,
                        'plugin_file' => '',
                        'message'     => 'No download link available — only free WordPress.org plugins are supported.',
                    ];
                }

                // Silent upgrader skin — no HTML output during MCP request.
                $skin     = new \WP_Ajax_Upgrader_Skin();
                $upgrader = new \Plugin_Upgrader( $skin );
                $result   = $upgrader->install( $api->download_link );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'     => false,
                        'slug'        => $slug,
                        'plugin_file' => '',
                        'message'     => 'Install failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === false ) {
                    $skin_errors = $skin->get_errors();
                    $err_msg     = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
                        ? $skin_errors->get_error_message()
                        : 'Unknown installer error (filesystem permissions or unavailable updates).';
                    return [
                        'success'     => false,
                        'slug'        => $slug,
                        'plugin_file' => '',
                        'message'     => 'Install failed: ' . $err_msg,
                    ];
                }

                $plugin_file = $upgrader->plugin_info();

                return [
                    'success'     => true,
                    'slug'        => $slug,
                    'plugin_file' => $plugin_file ? $plugin_file : '',
                    'message'     => 'Plugin installed successfully. Use activate_plugins capability separately to enable it.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'install_plugins' );
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

        // ---- activate-plugin ----
        wp_register_ability( 'atarim/activate-plugin', [
            'label'               => 'Activate Plugin',
            'description'         => 'Activates an installed (but inactive) plugin by its plugin file path. Use list-plugins to discover the plugin_file, or use the value returned by install-plugin.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'plugin_file' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path relative to the plugins directory (e.g. "akismet/akismet.php").',
                        'minLength'   => 1,
                    ],
                    'network_wide' => [
                        'type'        => 'boolean',
                        'description' => 'On multisite, activate network-wide instead of for the current site. Ignored on single-site installs.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'plugin_file' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'plugin_file'    => [ 'type' => 'string' ],
                    'network_active' => [ 'type' => 'boolean' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'plugin_file', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'activate_plugin' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $plugin_file = isset( $input['plugin_file'] ) ? $input['plugin_file'] : '';
                $plugin_file = ltrim( str_replace( [ '..', '\\' ], '', $plugin_file ), '/' );

                if ( empty( $plugin_file ) ) {
                    return [
                        'success'        => false,
                        'plugin_file'    => '',
                        'network_active' => false,
                        'message'        => 'plugin_file is required.',
                    ];
                }

                $network_wide = ! empty( $input['network_wide'] ) && is_multisite();

                $all_plugins = get_plugins();
                if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                    return [
                        'success'        => false,
                        'plugin_file'    => $plugin_file,
                        'network_active' => false,
                        'message'        => 'Plugin not installed.',
                    ];
                }

                // Hard-fail compatibility checks — mirrors the activate-theme behaviour.
                // WordPress core also performs these checks in newer versions, but doing
                // them here means we return a useful structured error to the AI caller
                // regardless of the WP version on the host site.
                $plugin_data  = $all_plugins[ $plugin_file ];
                $requires_wp  = isset( $plugin_data['RequiresWP'] ) ? (string) $plugin_data['RequiresWP'] : '';
                $requires_php = isset( $plugin_data['RequiresPHP'] ) ? (string) $plugin_data['RequiresPHP'] : '';

                if ( $requires_wp !== '' ) {
                    global $wp_version;
                    if ( version_compare( $wp_version, $requires_wp, '<' ) ) {
                        return [
                            'success'        => false,
                            'plugin_file'    => $plugin_file,
                            'network_active' => false,
                            'message'        => sprintf(
                                'Plugin requires WordPress %s; this site runs %s. Update WordPress before activating.',
                                $requires_wp,
                                $wp_version
                            ),
                        ];
                    }
                }

                if ( $requires_php !== '' ) {
                    if ( version_compare( PHP_VERSION, $requires_php, '<' ) ) {
                        return [
                            'success'        => false,
                            'plugin_file'    => $plugin_file,
                            'network_active' => false,
                            'message'        => sprintf(
                                'Plugin requires PHP %s; this site runs %s. Upgrade PHP before activating.',
                                $requires_php,
                                PHP_VERSION
                            ),
                        ];
                    }
                }

                if ( is_plugin_active( $plugin_file ) && ! $network_wide ) {
                    return [
                        'success'        => false,
                        'plugin_file'    => $plugin_file,
                        'network_active' => is_multisite() && is_plugin_active_for_network( $plugin_file ),
                        'message'        => 'Plugin is already active.',
                    ];
                }

                if ( $network_wide && is_plugin_active_for_network( $plugin_file ) ) {
                    return [
                        'success'        => false,
                        'plugin_file'    => $plugin_file,
                        'network_active' => true,
                        'message'        => 'Plugin is already network-active.',
                    ];
                }

                // activate_plugin() runs the plugin's activation hook and may produce
                // output if the plugin is buggy. Suppress to keep the MCP response clean.
                // Returns null on success, WP_Error on failure, or a WP_Error if the
                // plugin triggered a fatal error during activation.
                $silent = false;
                $result = activate_plugin( $plugin_file, '', $network_wide, $silent );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'        => false,
                        'plugin_file'    => $plugin_file,
                        'network_active' => false,
                        'message'        => 'Activation failed: ' . $result->get_error_message(),
                    ];
                }

                // Re-check; activate_plugin returns null on success but doesn't guarantee state.
                $now_active         = is_plugin_active( $plugin_file );
                $now_network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );

                return [
                    'success'        => $now_active,
                    'plugin_file'    => $plugin_file,
                    'network_active' => $now_network_active,
                    'message'        => $now_active ? 'Plugin activated.' : 'Activation completed but plugin is not active — check for activation errors.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'activate_plugins' );
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

        // ---- update-plugin ----
        wp_register_ability( 'atarim/update-plugin', [
            'label'               => 'Update Plugin',
            'description'         => 'Updates an installed plugin to the latest available version. Works with both free WordPress.org plugins and paid/third-party plugins that report updates through their own update server. Fails if no update is available.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'plugin_file' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path relative to the plugins directory (e.g. "akismet/akismet.php"). Use list-plugins to discover this value.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'plugin_file' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'         => [ 'type' => 'boolean' ],
                    'plugin_file'     => [ 'type' => 'string' ],
                    'previous_version' => [ 'type' => 'string' ],
                    'new_version'     => [ 'type' => 'string' ],
                    'was_active'      => [ 'type' => 'boolean', 'description' => 'Whether the plugin was active before the update.' ],
                    'is_active'       => [ 'type' => 'boolean', 'description' => 'Whether the plugin is active after the update. If was_active is true and this is false, the plugin was left switched off.' ],
                    'message'         => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'plugin_file', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'get_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                $plugin_file = isset( $input['plugin_file'] ) ? $input['plugin_file'] : '';
                $plugin_file = ltrim( str_replace( [ '..', '\\' ], '', $plugin_file ), '/' );

                if ( empty( $plugin_file ) ) {
                    return [
                        'success'          => false,
                        'plugin_file'      => '',
                        'previous_version' => '',
                        'new_version'      => '',
                        'message'          => 'plugin_file is required.',
                    ];
                }

                $all_plugins = get_plugins();
                if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                    return [
                        'success'          => false,
                        'plugin_file'      => $plugin_file,
                        'previous_version' => '',
                        'new_version'      => '',
                        'message'          => 'Plugin not installed.',
                    ];
                }

                $current_version = isset( $all_plugins[ $plugin_file ]['Version'] ) ? $all_plugins[ $plugin_file ]['Version'] : '';

                // WordPress can leave a plugin deactivated after an upgrade. Record the
                // state up front so it can be restored below, rather than reporting
                // success while the site quietly loses the plugin.
                $was_active         = is_plugin_active( $plugin_file );
                $was_network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );

                // Force a fresh update check so we don't act on stale transient data.
                // wp_update_plugins() makes a remote call to api.wordpress.org for free plugins
                // and triggers third-party update-checker hooks for paid plugins.
                wp_update_plugins();

                $updates     = get_site_transient( 'update_plugins' );
                $update_list = ( $updates && ! empty( $updates->response ) ) ? $updates->response : [];

                if ( ! isset( $update_list[ $plugin_file ] ) ) {
                    return [
                        'success'          => false,
                        'plugin_file'      => $plugin_file,
                        'previous_version' => $current_version,
                        'new_version'      => '',
                        'message'          => 'No update available for this plugin.',
                    ];
                }

                $new_version = isset( $update_list[ $plugin_file ]->new_version ) ? $update_list[ $plugin_file ]->new_version : '';

                // Filesystem credentials check — same pattern as delete-plugin.
                ob_start();
                $creds_ok = WP_Filesystem();
                ob_end_clean();

                if ( ! $creds_ok ) {
                    return [
                        'success'          => false,
                        'plugin_file'      => $plugin_file,
                        'previous_version' => $current_version,
                        'new_version'      => $new_version,
                        'message'          => 'Could not initialize filesystem — server may require FTP credentials.',
                    ];
                }

                // Silent upgrader skin — no HTML output during MCP request.
                $skin     = new \WP_Ajax_Upgrader_Skin();
                $upgrader = new \Plugin_Upgrader( $skin );

                // Plugin_Upgrader::upgrade() pulls the download URL from the update_plugins
                // transient — works identically for free and paid plugins as long as the
                // update was registered there.
                $result = $upgrader->upgrade( $plugin_file );

                // Restore the pre-upgrade activation state before reporting back.
                $reactivation_failed = false;
                if ( $was_active && ! is_wp_error( $result ) && $result !== false && ! is_plugin_active( $plugin_file ) ) {
                    $activation = activate_plugin( $plugin_file, '', $was_network_active, true );
                    $reactivation_failed = is_wp_error( $activation );
                }

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'          => false,
                        'plugin_file'      => $plugin_file,
                        'previous_version' => $current_version,
                        'new_version'      => $new_version,
                        'message'          => 'Update failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === false ) {
                    $skin_errors = $skin->get_errors();
                    $err_msg     = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
                        ? $skin_errors->get_error_message()
                        : 'Unknown upgrader error.';
                    return [
                        'success'          => false,
                        'plugin_file'      => $plugin_file,
                        'previous_version' => $current_version,
                        'new_version'      => $new_version,
                        'message'          => 'Update failed: ' . $err_msg,
                    ];
                }

                // Re-read plugin headers to confirm the actual installed version.
                $all_plugins_after = get_plugins();
                $installed_version = isset( $all_plugins_after[ $plugin_file ]['Version'] )
                    ? $all_plugins_after[ $plugin_file ]['Version']
                    : $new_version;

                $is_active_after = is_plugin_active( $plugin_file );

                return [
                    'success'          => true,
                    'plugin_file'      => $plugin_file,
                    'previous_version' => $current_version,
                    'new_version'      => $installed_version,
                    'was_active'       => $was_active,
                    'is_active'        => $is_active_after,
                    'message'          => $was_active && ! $is_active_after
                        ? sprintf(
                            'Plugin updated from %s to %s, but it was left DEACTIVATED and could not be reactivated automatically%s. Reactivate it before relying on the site.',
                            $current_version,
                            $installed_version,
                            $reactivation_failed ? '' : ' (state unexpectedly changed)'
                        )
                        : sprintf( 'Plugin updated from %s to %s.', $current_version, $installed_version ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'update_plugins' );
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

        // ---- deactivate-plugin ----
        wp_register_ability( 'atarim/deactivate-plugin', [
            'label'               => 'Deactivate Plugin',
            'description'         => 'Deactivates an installed plugin by its plugin file path (e.g. "akismet/akismet.php").',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'plugin_file' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path relative to the plugins directory (e.g. "akismet/akismet.php"). Use list-plugins to discover this value.',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'plugin_file' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'plugin_file' => [ 'type' => 'string' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'plugin_file', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'deactivate_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $plugin_file = isset( $input['plugin_file'] ) ? $input['plugin_file'] : '';
                // Light path normalization without losing the forward slash.
                $plugin_file = ltrim( str_replace( [ '..', '\\' ], '', $plugin_file ), '/' );

                if ( empty( $plugin_file ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => '',
                        'message'     => 'plugin_file is required.',
                    ];
                }

                $all_plugins = get_plugins();
                if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Plugin not installed.',
                    ];
                }

                if ( ! is_plugin_active( $plugin_file ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Plugin is already inactive.',
                    ];
                }

                // Guard against self-deactivation — would break the very request handling this call.
                if ( $plugin_file === AVCF_PLUGIN_BASE ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Cannot deactivate the Atarim plugin via MCP.',
                    ];
                }

                deactivate_plugins( $plugin_file );

                // deactivate_plugins() returns void; re-check.
                $still_active = is_plugin_active( $plugin_file );

                return [
                    'success'     => ! $still_active,
                    'plugin_file' => $plugin_file,
                    'message'     => $still_active ? 'Deactivation failed.' : 'Plugin deactivated.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'deactivate_plugins' );
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

        // ---- delete-plugin ----
        wp_register_ability( 'atarim/delete-plugin', [
            'label'               => 'Delete Plugin',
            'description'         => 'Permanently deletes an installed plugin from disk. Plugin must be deactivated first.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'plugin_file' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path relative to the plugins directory (e.g. "akismet/akismet.php").',
                        'minLength'   => 1,
                    ],
                ],
                'required'             => [ 'plugin_file' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'plugin_file' => [ 'type' => 'string' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'plugin_file', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'delete_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';

                $plugin_file = isset( $input['plugin_file'] ) ? $input['plugin_file'] : '';
                $plugin_file = ltrim( str_replace( [ '..', '\\' ], '', $plugin_file ), '/' );

                if ( empty( $plugin_file ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => '',
                        'message'     => 'plugin_file is required.',
                    ];
                }

                if ( $plugin_file === AVCF_PLUGIN_BASE ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Cannot delete the Atarim plugin via MCP.',
                    ];
                }

                $all_plugins = get_plugins();
                if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Plugin not installed.',
                    ];
                }

                if ( is_plugin_active( $plugin_file ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Plugin is currently active. Deactivate it before deleting.',
                    ];
                }

                // delete_plugins() needs filesystem credentials; request them silently.
                // On direct/ssh/ftpext methods with stored creds this works; otherwise it fails cleanly.
                ob_start();
                $creds_ok = WP_Filesystem();
                ob_end_clean();

                if ( ! $creds_ok ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Could not initialize filesystem — server may require FTP credentials.',
                    ];
                }

                $result = delete_plugins( [ $plugin_file ] );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Delete failed: ' . $result->get_error_message(),
                    ];
                }

                if ( $result === false || $result === null ) {
                    return [
                        'success'     => false,
                        'plugin_file' => $plugin_file,
                        'message'     => 'Delete failed (filesystem error).',
                    ];
                }

                return [
                    'success'     => true,
                    'plugin_file' => $plugin_file,
                    'message'     => 'Plugin deleted.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'delete_plugins' );
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
    }
}
