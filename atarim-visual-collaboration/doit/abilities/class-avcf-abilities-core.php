<?php
/**
 * WordPress core / environment MCP abilities.
 *
 * Reports on the host environment: WordPress version and pending updates,
 * PHP version and WordPress support status, database server version, and
 * multisite configuration. Built so the AI layer can audit a site before
 * recommending major operations (upgrades, theme switches, plugin installs)
 * that depend on the runtime environment.
 *
 * Exposed abilities:
 *   atarim/get-core-status           WP + PHP + DB + multisite snapshot in one call.
 *   atarim/check-core-updates        Force a fresh WordPress.org update check.
 *   atarim/update-core               Update core to the latest (or a specific offered) version.
 *   atarim/reinstall-core            Re-install the current version to repair core files.
 *
 * Note: abilities are exposed to the MCP server automatically (dynamic
 * enumeration minus the avcf_mcp_blocked_abilities blacklist); no manual
 * registration list needs editing when adding abilities here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Core extends AVCF_Abilities_Base {

    /**
     * Register all core / environment abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {

        // ---- get-core-status ----
        wp_register_ability( 'atarim/get-core-status', [
            'label'               => 'Get Core Status',
            'description'         => 'Returns a snapshot of the WordPress core, PHP, and database environment plus pending core update info and multisite configuration. Use this to audit the site before recommending or attempting major operations like theme switches, large plugin installs, or PHP-dependent upgrades.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'wordpress' => [
                        'type'       => 'object',
                        'properties' => [
                            'version'                   => [ 'type' => 'string' ],
                            'latest_version'            => [ 'type' => 'string' ],
                            'update_available'          => [ 'type' => 'boolean' ],
                            'update_type'               => [ 'type' => 'string' ],
                            'automatic_updates_enabled' => [ 'type' => 'boolean' ],
                            'language'                  => [ 'type' => 'string' ],
                        ],
                    ],
                    'php' => [
                        'type'       => 'object',
                        'properties' => [
                            'version'             => [ 'type' => 'string' ],
                            'recommended_minimum' => [ 'type' => 'string' ],
                            'is_supported_by_wp'  => [ 'type' => 'boolean' ],
                            'memory_limit'        => [ 'type' => 'string' ],
                            'max_execution_time'  => [ 'type' => 'integer' ],
                        ],
                    ],
                    'database' => [
                        'type'       => 'object',
                        'properties' => [
                            'server_version' => [ 'type' => 'string' ],
                            'server_type'    => [ 'type' => 'string' ],
                            'charset'        => [ 'type' => 'string' ],
                            'collate'        => [ 'type' => 'string' ],
                        ],
                    ],
                    'multisite' => [
                        'type'       => 'object',
                        'properties' => [
                            'is_multisite' => [ 'type' => 'boolean' ],
                            'is_main_site' => [ 'type' => 'boolean' ],
                            'site_count'   => [ 'type' => 'integer' ],
                            'network_id'   => [ 'type' => 'integer' ],
                        ],
                    ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'wordpress', 'php', 'database', 'multisite' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_version, $wpdb;

                // --- WordPress core ---
                if ( ! function_exists( 'get_core_updates' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/update.php';
                }

                $core_updates    = get_core_updates();
                $latest_version  = $wp_version;
                $update_available = false;
                $update_type     = 'none';

                if ( is_array( $core_updates ) && ! empty( $core_updates ) ) {
                    foreach ( $core_updates as $update ) {
                        if ( isset( $update->response ) && $update->response === 'upgrade' && isset( $update->current ) ) {
                            $latest_version   = $update->current;
                            $update_available = true;
                            // Determine major vs minor vs patch.
                            $current_parts = explode( '.', $wp_version );
                            $latest_parts  = explode( '.', $latest_version );
                            if ( isset( $current_parts[0], $latest_parts[0] ) && $current_parts[0] !== $latest_parts[0] ) {
                                $update_type = 'major';
                            } elseif ( isset( $current_parts[1], $latest_parts[1] ) && ( ! isset( $current_parts[1] ) || ! isset( $latest_parts[1] ) || $current_parts[1] !== $latest_parts[1] ) ) {
                                $update_type = 'minor';
                            } else {
                                $update_type = 'patch';
                            }
                            break;
                        }
                    }
                }

                // WordPress auto-update preferences. WP exposes these via constants and filters.
                $auto_updates_enabled = ! defined( 'AUTOMATIC_UPDATER_DISABLED' ) || ! AUTOMATIC_UPDATER_DISABLED;
                // Core minor updates default-on in modern WP; respect explicit user opt-out.
                if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
                    $auto_updates_enabled = (bool) WP_AUTO_UPDATE_CORE;
                }

                // --- PHP ---
                // WordPress's recommended PHP minimum, surfaced in admin notices.
                // Current as of WP 6.x: 7.4 absolute minimum, 8.1+ recommended.
                // We expose WP's hard-floor (RECOMMENDED_PHP_VERSION) when defined.
                $php_recommended = '';
                if ( defined( 'WP_PHP_MIN_VERSION' ) ) {
                    $php_recommended = (string) WP_PHP_MIN_VERSION;
                } elseif ( function_exists( 'wp_check_php_version' ) ) {
                    // wp_check_php_version() returns a structure with recommended_version
                    $check = wp_check_php_version();
                    if ( is_array( $check ) && isset( $check['recommended_version'] ) ) {
                        $php_recommended = (string) $check['recommended_version'];
                    }
                }
                if ( $php_recommended === '' ) {
                    // Fallback to the value WP has used as the floor for several years.
                    $php_recommended = '7.4';
                }

                $php_supported = version_compare( PHP_VERSION, $php_recommended, '>=' );

                // --- Database ---
                $db_version = '';
                $db_type    = '';
                if ( $wpdb && method_exists( $wpdb, 'db_server_info' ) ) {
                    $db_version = (string) $wpdb->db_version();
                    $raw_info   = (string) $wpdb->db_server_info();
                    $db_type    = ( stripos( $raw_info, 'mariadb' ) !== false ) ? 'MariaDB' : 'MySQL';
                } elseif ( $wpdb ) {
                    $db_version = (string) $wpdb->db_version();
                    $db_type    = 'MySQL';
                }

                // --- Multisite ---
                $is_multisite = is_multisite();
                $multisite    = [
                    'is_multisite' => $is_multisite,
                    'is_main_site' => $is_multisite ? is_main_site() : true,
                    'site_count'   => $is_multisite ? (int) get_blog_count() : 1,
                    'network_id'   => $is_multisite ? (int) get_current_network_id() : 0,
                ];

                $message = sprintf(
                    'WordPress %s on PHP %s, %s %s. %s',
                    $wp_version,
                    PHP_VERSION,
                    $db_type !== '' ? $db_type : 'database',
                    $db_version,
                    $update_available
                        ? sprintf( 'Core update available: %s (%s).', $latest_version, $update_type )
                        : 'Core is up to date.'
                );

                return [
                    'wordpress' => [
                        'version'                   => $wp_version,
                        'latest_version'            => $latest_version,
                        'update_available'          => $update_available,
                        'update_type'               => $update_type,
                        'automatic_updates_enabled' => (bool) $auto_updates_enabled,
                        'language'                  => (string) get_locale(),
                    ],
                    'php' => [
                        'version'             => PHP_VERSION,
                        'recommended_minimum' => $php_recommended,
                        'is_supported_by_wp'  => $php_supported,
                        'memory_limit'        => (string) ini_get( 'memory_limit' ),
                        'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
                    ],
                    'database' => [
                        'server_version' => $db_version,
                        'server_type'    => $db_type,
                        'charset'        => isset( $wpdb->charset ) ? (string) $wpdb->charset : '',
                        'collate'        => isset( $wpdb->collate ) ? (string) $wpdb->collate : '',
                    ],
                    'multisite' => $multisite,
                    'message'   => $message,
                ];
            },
            'permission_callback' => function() {
                // Reading environment info is moderately sensitive (PHP version, server type can
                // help an attacker fingerprint the host). Gate on update_core which is held by
                // administrators only, matching WP's own dashboard widget visibility.
                return current_user_can( 'update_core' );
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

        // ---- check-core-updates ----
        wp_register_ability( 'atarim/check-core-updates', [
            'label'               => 'Check For Core Updates',
            'description'         => 'Forces a fresh check with WordPress.org for core updates, bypassing the cached result (which can be up to ~12 hours stale), and reports whether an update is available. Use this to get an authoritative, up-to-the-minute answer before deciding whether to run update-core.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'current_version'  => [ 'type' => 'string' ],
                    'latest_version'   => [ 'type' => 'string' ],
                    'update_available' => [ 'type' => 'boolean' ],
                    'update_type'      => [ 'type' => 'string' ],
                    'checked_at'       => [ 'type' => 'string' ],
                    'message'          => [ 'type' => 'string' ],
                ],
                'required' => [ 'current_version', 'latest_version', 'update_available', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_version;

                if ( ! function_exists( 'get_core_updates' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/update.php';
                }

                // Force a fresh remote check (bypasses the update_core transient).
                wp_version_check( [], true );

                $updates          = get_core_updates();
                $current_version  = $wp_version;
                $latest_version   = $wp_version;
                $update_available = false;
                $update_type      = 'none';

                if ( is_array( $updates ) && ! empty( $updates ) ) {
                    foreach ( $updates as $update ) {
                        if ( isset( $update->response ) && $update->response === 'upgrade' && isset( $update->current ) ) {
                            $latest_version   = (string) $update->current;
                            $update_available = true;
                            $update_type      = $this->avcf_core_update_type( $current_version, $latest_version );
                            break;
                        }
                    }
                }

                return [
                    'current_version'  => $current_version,
                    'latest_version'   => $latest_version,
                    'update_available' => $update_available,
                    'update_type'      => $update_type,
                    'checked_at'       => gmdate( 'c' ),
                    'message'          => $update_available
                        ? sprintf( 'Core update available: %s -> %s (%s).', $current_version, $latest_version, $update_type )
                        : 'WordPress core is up to date.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'update_core' );
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

        // ---- update-core ----
        wp_register_ability( 'atarim/update-core', [
            'label'               => 'Update WordPress Core',
            'description'         => 'Updates WordPress core to the latest available update, or to a specific version that WordPress.org is currently offering for this site. Only offered versions are accepted — arbitrary version strings and downgrades are rejected. Runs the database upgrade routine afterward so the site is not left needing a manual DB update. High impact: on constrained hosts the download/extract can exceed max_execution_time (the update may still complete server-side). Requires direct filesystem access (no FTP/SSH credential prompts are possible here).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'version' => [
                        'type'        => 'string',
                        'description' => 'Optional. Pin to a specific version WordPress is currently offering as an update (e.g. "6.5.2"). If omitted, updates to the latest offered update. Rejected if the version is not an offered update.',
                    ],
                    'locale' => [
                        'type'        => 'string',
                        'description' => 'Optional WordPress locale for the update package (e.g. "en_US"). Defaults to the site locale.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'      => [ 'type' => 'boolean' ],
                    'updated'      => [ 'type' => 'boolean' ],
                    'from_version' => [ 'type' => 'string' ],
                    'to_version'   => [ 'type' => 'string' ],
                    'update_type'  => [ 'type' => 'string' ],
                    'db_upgraded'  => [ 'type' => 'boolean' ],
                    'message'      => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'updated', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_version;

                $pre = $this->avcf_core_update_preflight();
                if ( $pre !== null ) {
                    return [ 'success' => false, 'updated' => false, 'message' => $pre ];
                }

                $locale = ( isset( $input['locale'] ) && $input['locale'] !== '' ) ? (string) $input['locale'] : get_locale();
                $from_version = $wp_version;

                // Act on current data.
                wp_version_check( [], true );
                $updates = get_core_updates();
                if ( ! is_array( $updates ) || empty( $updates ) ) {
                    return [
                        'success'      => true,
                        'updated'      => false,
                        'from_version' => $from_version,
                        'to_version'   => $from_version,
                        'update_type'  => 'none',
                        'db_upgraded'  => false,
                        'message'      => 'No core updates are available; WordPress is up to date.',
                    ];
                }

                $want_version = isset( $input['version'] ) ? trim( (string) $input['version'] ) : '';
                $target       = null;
                foreach ( $updates as $update ) {
                    if ( ! isset( $update->response ) || $update->response !== 'upgrade' || ! isset( $update->current ) ) {
                        continue;
                    }
                    if ( $want_version !== '' ) {
                        if ( (string) $update->current === $want_version ) {
                            $target = $update;
                            break;
                        }
                    } else {
                        $target = $update;
                        break;
                    }
                }

                if ( $target === null ) {
                    if ( $want_version !== '' ) {
                        return [
                            'success'      => false,
                            'updated'      => false,
                            'from_version' => $from_version,
                            'to_version'   => $from_version,
                            'message'      => sprintf( 'Version "%s" is not currently offered as a core update for this site. Use check-core-updates to see what is available.', $want_version ),
                        ];
                    }
                    return [
                        'success'      => true,
                        'updated'      => false,
                        'from_version' => $from_version,
                        'to_version'   => $from_version,
                        'update_type'  => 'none',
                        'db_upgraded'  => false,
                        'message'      => 'No core upgrade offer found; WordPress is up to date.',
                    ];
                }

                $to_version  = (string) $target->current;
                $update_type = $this->avcf_core_update_type( $from_version, $to_version );

                // Prefer the requested locale's package if a different one is available.
                if ( $locale !== '' && isset( $target->locale ) && $target->locale !== $locale ) {
                    $located = find_core_update( $to_version, $locale );
                    if ( $located ) {
                        $target = $located;
                    }
                }

                $skin     = new \WP_Ajax_Upgrader_Skin();
                $upgrader = new \Core_Upgrader( $skin );
                $result   = $upgrader->upgrade( $target );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'      => false,
                        'updated'      => false,
                        'from_version' => $from_version,
                        'to_version'   => $to_version,
                        'update_type'  => $update_type,
                        'db_upgraded'  => false,
                        'message'      => 'Core update failed: ' . $result->get_error_message(),
                    ];
                }
                if ( $result === false ) {
                    return [
                        'success'      => false,
                        'updated'      => false,
                        'from_version' => $from_version,
                        'to_version'   => $to_version,
                        'update_type'  => $update_type,
                        'db_upgraded'  => false,
                        'message'      => 'Core update did not complete (the upgrader returned no result).',
                    ];
                }

                // Run the DB upgrade routine so the site is not left "needs database update".
                $db_upgraded = false;
                if ( ! function_exists( 'wp_upgrade' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                }
                if ( function_exists( 'wp_upgrade' ) ) {
                    wp_upgrade();
                    $db_upgraded = true;
                }

                return [
                    'success'      => true,
                    'updated'      => true,
                    'from_version' => $from_version,
                    'to_version'   => $to_version,
                    'update_type'  => $update_type,
                    'db_upgraded'  => $db_upgraded,
                    'message'      => sprintf( 'WordPress updated from %s to %s (%s).', $from_version, $to_version, $update_type ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'update_core' );
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

        // ---- reinstall-core ----
        wp_register_ability( 'atarim/reinstall-core', [
            'label'               => 'Reinstall WordPress Core',
            'description'         => 'Re-installs the currently running WordPress version to repair corrupted or modified core files (equivalent to the dashboard "Re-install now" button). Does not change the version. Requires direct filesystem access.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'locale' => [
                        'type'        => 'string',
                        'description' => 'Optional WordPress locale for the package (e.g. "en_US"). Defaults to the site locale.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'reinstalled' => [ 'type' => 'boolean' ],
                    'version'     => [ 'type' => 'string' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'reinstalled', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                global $wp_version;

                $pre = $this->avcf_core_update_preflight();
                if ( $pre !== null ) {
                    return [ 'success' => false, 'reinstalled' => false, 'version' => $wp_version, 'message' => $pre ];
                }

                $locale = ( isset( $input['locale'] ) && $input['locale'] !== '' ) ? (string) $input['locale'] : get_locale();

                // Refresh, then locate the package for the CURRENT version (reinstall).
                wp_version_check( [], true );
                $update = find_core_update( $wp_version, $locale );
                if ( ! $update ) {
                    return [
                        'success'     => false,
                        'reinstalled' => false,
                        'version'     => $wp_version,
                        'message'     => sprintf( 'No reinstall package is available for the current version (%s, locale %s).', $wp_version, $locale ),
                    ];
                }

                $skin     = new \WP_Ajax_Upgrader_Skin();
                $upgrader = new \Core_Upgrader( $skin );
                $result   = $upgrader->upgrade( $update );

                if ( is_wp_error( $result ) ) {
                    return [
                        'success'     => false,
                        'reinstalled' => false,
                        'version'     => $wp_version,
                        'message'     => 'Core reinstall failed: ' . $result->get_error_message(),
                    ];
                }
                if ( $result === false ) {
                    return [
                        'success'     => false,
                        'reinstalled' => false,
                        'version'     => $wp_version,
                        'message'     => 'Core reinstall did not complete (the upgrader returned no result).',
                    ];
                }

                return [
                    'success'     => true,
                    'reinstalled' => true,
                    'version'     => $wp_version,
                    'message'     => sprintf( 'WordPress %s core files re-installed successfully.', $wp_version ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'update_core' );
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
    }

    /**
     * Shared safety preflight for core file operations (update / reinstall).
     * Loads the upgrader includes and blocks when the environment cannot safely
     * perform a core file operation. Returns null when OK, or an error string.
     */
    private function avcf_core_update_preflight() {
        if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
            return 'File modifications are disabled on this site (DISALLOW_FILE_MODS), so core file operations are unavailable.';
        }

        if ( ! function_exists( 'get_filesystem_method' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( function_exists( 'get_filesystem_method' ) && get_filesystem_method() !== 'direct' ) {
            return 'The filesystem is not directly writable (FTP/SSH credentials would be required, which cannot be provided here). Core file operations are unavailable on this host configuration.';
        }

        if ( ! function_exists( 'get_core_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        if ( ! class_exists( '\Core_Upgrader' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        // Give the download/extract more headroom where the host permits it.
        @set_time_limit( 300 );

        return null;
    }

    /**
     * Classify a version delta as major / minor / patch.
     */
    private function avcf_core_update_type( $from, $to ) {
        $f  = explode( '.', (string) $from );
        $t  = explode( '.', (string) $to );
        $f0 = isset( $f[0] ) ? $f[0] : '';
        $t0 = isset( $t[0] ) ? $t[0] : '';
        $f1 = isset( $f[1] ) ? $f[1] : '';
        $t1 = isset( $t[1] ) ? $t[1] : '';
        if ( $f0 !== $t0 ) {
            return 'major';
        }
        if ( $f1 !== $t1 ) {
            return 'minor';
        }
        return 'patch';
    }
}