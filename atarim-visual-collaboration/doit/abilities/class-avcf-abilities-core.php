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
    }
}
