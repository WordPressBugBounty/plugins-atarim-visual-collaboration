<?php
/**
 * MCP abilities for WP Activity Log integration.
 *
 * Registers Atarim/WPAL abilities with the Abilities API so the DoIt AI
 * layer can query activity log stats over MCP. Consumes AVCF_WPAL_Stats so
 * the data layer is shared with the REST surface in class-avcf-wpal-rest.php.
 *
 * Exposed abilities:
 *   atarim/wpal-status               Active? edition?
 *   atarim/wpal-yesterday-stats      Yesterday's counts.
 *   atarim/wpal-yesterday-links      Premium drill-down links.
 *
 * Note: this class only declares the abilities. The names below must also be
 * added to the $tools array in doit/class-avcf-mcp.php::avcf_mcp_setup_server()
 * so the MCP server actually exposes them.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

class AVCF_Abilities_WPAL extends AVCF_Abilities_Base {

    /**
     * @var AVCF_WPAL_Stats
     */
    private $stats;

    /**
     * @var AVCF_WPAL_Detector
     */
    private $detector;

    public function __construct() {
        $this->stats    = new AVCF_WPAL_Stats();
        $this->detector = new AVCF_WPAL_Detector();
    }

    /**
     * Register all WPAL-related abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     * Only instantiated by the orchestrator if AVCF_WPAL_Detector reports the
     * plugin as available.
     */
    public function register() {
        $this->avcf_wpal_register_status_ability();
        $this->avcf_wpal_register_stats_ability();
        $this->avcf_wpal_register_links_ability();
    }

    /**
     * Ability: atarim/wpal-status
     * Quick health check: is WPAL installed, which edition is active.
     */
    private function avcf_wpal_register_status_ability() {
        $detector = $this->detector;

        wp_register_ability( 'atarim/wpal-status', array(
            'label'               => 'WP Activity Log Status',
            'description'         => 'Returns whether WP Activity Log is active on this site and which edition (free or premium) is installed.',
            'category'            => 'atarim',
            'input_schema'        => array(
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'available' => array(
                        'type'        => 'boolean',
                        'description' => 'True when WP Activity Log is installed and active.',
                    ),
                    'edition'   => array(
                        'type'        => 'string',
                        'description' => 'Edition: "premium", "free", or "none".',
                        'enum'        => array( 'premium', 'free', 'none' ),
                    ),
                ),
                'required'   => array( 'available', 'edition' ),
            ),
            'execute_callback'    => function() use ( $detector ) {
                return array(
                    'available' => $detector->avcf_wpal_is_available(),
                    'edition'   => $detector->avcf_wpal_edition(),
                );
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta'                => array(
                'mcp'         => array( 'public' => true, 'type' => 'tool' ),
                'annotations' => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ) );
    }

    /**
     * Ability: atarim/wpal-yesterday-stats
     * Returns yesterday's event counts: total, logins, plugin/theme changes.
     */
    private function avcf_wpal_register_stats_ability() {
        $stats = $this->stats;

        wp_register_ability( 'atarim/wpal-yesterday-stats', array(
            'label'               => 'WP Activity Log Yesterday Stats',
            'description'         => 'Returns yesterday\'s activity log counts from WP Activity Log: total events, login events, and plugin/theme change events. The window is the previous calendar day (00:00 to 23:59), not a rolling 24 hours.',
            'category'            => 'atarim',
            'input_schema'        => array(
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'available' => array( 'type' => 'boolean' ),
                    'edition'   => array(
                        'type' => 'string',
                        'enum' => array( 'premium', 'free', 'none' ),
                    ),
                    'window'    => array(
                        'type'        => 'string',
                        'description' => 'Always "yesterday" with current WPAL semantics.',
                    ),
                    'counts'    => array(
                        'type'       => 'object',
                        'properties' => array(
                            'total'   => array(
                                'type'        => 'integer',
                                'description' => 'Total activity log events recorded yesterday.',
                            ),
                            'logins'  => array(
                                'type'        => 'integer',
                                'description' => 'Login events (WPAL IDs 1000 and 1005) recorded yesterday.',
                            ),
                            'changes' => array(
                                'type'        => 'integer',
                                'description' => 'Plugin and theme change events recorded yesterday.',
                            ),
                        ),
                        'required'   => array( 'total', 'logins', 'changes' ),
                    ),
                ),
                'required'   => array( 'available', 'edition', 'window', 'counts' ),
            ),
            'execute_callback'    => function() use ( $stats ) {
                return $stats->avcf_wpal_get_yesterday_counts();
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta'                => array(
                'mcp'         => array( 'public' => true, 'type' => 'tool' ),
                'annotations' => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ) );
    }

    /**
     * Ability: atarim/wpal-yesterday-links
     * Returns the premium drill-down URLs for each category.
     */
    private function avcf_wpal_register_links_ability() {
        $stats = $this->stats;

        wp_register_ability( 'atarim/wpal-yesterday-links', array(
            'label'               => 'WP Activity Log Yesterday Links',
            'description'         => 'Returns filtered activity log viewer URLs for yesterday by category. Premium-only feature in WP Activity Log; on the free edition the per-category links are empty and a generic fallback_url to the activity log viewer is provided.',
            'category'            => 'atarim',
            'input_schema'        => array(
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'available'    => array( 'type' => 'boolean' ),
                    'edition'      => array(
                        'type' => 'string',
                        'enum' => array( 'premium', 'free', 'none' ),
                    ),
                    'premium'      => array( 'type' => 'boolean' ),
                    'links'        => array(
                        'type'       => 'object',
                        'properties' => array(
                            'total'   => array( 'type' => 'string' ),
                            'logins'  => array( 'type' => 'string' ),
                            'changes' => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'total', 'logins', 'changes' ),
                    ),
                    'fallback_url' => array(
                        'type'        => 'string',
                        'description' => 'Generic activity log viewer URL when premium per-category links are unavailable.',
                    ),
                ),
                'required'   => array( 'available', 'edition', 'premium', 'links', 'fallback_url' ),
            ),
            'execute_callback'    => function() use ( $stats ) {
                return $stats->avcf_wpal_get_yesterday_links();
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta'                => array(
                'mcp'         => array( 'public' => true, 'type' => 'tool' ),
                'annotations' => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ) );
    }
}
