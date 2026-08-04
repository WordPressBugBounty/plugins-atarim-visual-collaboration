<?php
/**
 * Cache / CDN purge MCP abilities.
 *
 * WordPress core has no universal cache- or CDN-purge API: object caches, page
 * cache plugins, and CDN/host edge layers each expose their own mechanism. This
 * category provides a GENERIC, best-effort purge that dispatches to every cache
 * layer it can detect on the site and honestly reports which ones actually
 * fired — rather than pretending a single call cleared everything.
 *
 * Detection is guarded (function_exists / class_exists / has_action) so nothing
 * here hard-depends on any cache plugin being installed. Every provider call is
 * wrapped in try/catch so one broken provider never aborts the rest.
 *
 * Extensibility: after the built-in providers run, the following actions fire so
 * site owners can purge custom or host-specific layers:
 *   do_action( 'atarim_purge_all_caches' )
 *   do_action( 'atarim_purge_url_cache', $url )
 *
 * Limitation: true CDN edge purge WITHOUT an installed integration plugin
 * (e.g. raw Cloudflare with no Cloudflare plugin) needs per-CDN API credentials
 * and is out of scope. This covers everything reachable through hooks/functions
 * already loaded on the site.
 *
 * Exposed abilities:
 *   atarim/purge-all-caches   Flush object cache + every detected page/CDN cache.
 *   atarim/purge-url-cache    Purge a single URL where the provider supports it.
 *   atarim/get-cache-status   Report which cache layers are detected (read-only).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Cache extends AVCF_Abilities_Base {

    /**
     * Register all cache abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {

        // ---- purge-all-caches ----
        wp_register_ability( 'atarim/purge-all-caches', [
            'label'               => 'Purge All Caches',
            'description'         => 'Best-effort purge of every cache layer detected on the site: the WordPress object cache plus popular page-cache and CDN/host plugins (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed, WP Fastest Cache, Cache Enabler, Autoptimize, SG Optimizer, Nginx Helper, WP Engine, Kinsta, Cloudways Breeze). Each provider is detected before it is called, and the response reports exactly which ones flushed, which were absent, and which errored. Note: purging a CDN edge with NO integration plugin installed (e.g. raw Cloudflare) needs API credentials and is NOT handled here. Also fires the atarim_purge_all_caches action so custom providers can hook in.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'purged'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'skipped'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'errors'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'provider' => [ 'type' => 'string' ],
                                'message'  => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'purged', 'skipped', 'errors', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $purged  = [];
                $skipped = [];
                $errors  = [];

                foreach ( $this->avcf_cache_providers() as $provider ) {
                    if ( ! $this->avcf_provider_detected( $provider ) ) {
                        $skipped[] = $provider['label'];
                        continue;
                    }
                    try {
                        call_user_func( $provider['purge_all'] );
                        $purged[] = $provider['label'];
                    } catch ( \Throwable $e ) {
                        $errors[] = [ 'provider' => $provider['label'], 'message' => $e->getMessage() ];
                    }
                }

                // Extensibility hook for custom / host-specific layers.
                do_action( 'atarim_purge_all_caches' );

                $success = empty( $errors );
                $message = sprintf(
                    'Purged %d cache layer(s): %s. Skipped %d (not present). %d error(s).',
                    count( $purged ),
                    $purged ? implode( ', ', $purged ) : 'none',
                    count( $skipped ),
                    count( $errors )
                );

                return [
                    'success'  => $success,
                    'purged'   => $purged,
                    'skipped'  => $skipped,
                    'errors'   => $errors,
                    'message'  => $message,
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

        // ---- purge-url-cache ----
        wp_register_ability( 'atarim/purge-url-cache', [
            'label'               => 'Purge URL Cache',
            'description'         => 'Best-effort purge of the cached copy of a single URL. Only providers that support per-URL purging are invoked (WP Rocket, W3 Total Cache, LiteSpeed, Cache Enabler); others are reported as unsupported. The object cache is not touched. Also fires the atarim_purge_url_cache action with the URL so custom providers can hook in. For a full site-wide flush use atarim/purge-all-caches instead.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'url' => [
                        'type'        => 'string',
                        'description' => 'Absolute URL whose cached copy should be purged, e.g. https://example.com/about/.',
                    ],
                ],
                'required'             => [ 'url' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'url'         => [ 'type' => 'string' ],
                    'purged'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'unsupported' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'errors'      => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'provider' => [ 'type' => 'string' ],
                                'message'  => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'url', 'purged', 'unsupported', 'errors', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $url = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';
                if ( $url === '' ) {
                    return [
                        'success'     => false,
                        'url'         => '',
                        'purged'      => [],
                        'unsupported' => [],
                        'errors'      => [],
                        'message'     => 'url must be a non-empty, valid absolute URL.',
                    ];
                }

                $purged      = [];
                $unsupported = [];
                $errors      = [];

                foreach ( $this->avcf_cache_providers() as $provider ) {
                    if ( empty( $provider['purge_url'] ) ) {
                        continue; // Provider has no per-URL mechanism at all — not reported.
                    }
                    if ( ! $this->avcf_provider_detected( $provider ) ) {
                        continue; // Not installed — silent; only report installed-but-unsupported below.
                    }
                    try {
                        call_user_func( $provider['purge_url'], $url );
                        $purged[] = $provider['label'];
                    } catch ( \Throwable $e ) {
                        $errors[] = [ 'provider' => $provider['label'], 'message' => $e->getMessage() ];
                    }
                }

                // Report detected page/CDN providers that can only flush site-wide.
                // The object cache is intentionally excluded — it is not a per-URL layer.
                foreach ( $this->avcf_cache_providers() as $provider ) {
                    if ( $provider['key'] === 'object_cache' || ! empty( $provider['purge_url'] ) ) {
                        continue;
                    }
                    if ( $this->avcf_provider_detected( $provider ) ) {
                        $unsupported[] = $provider['label'];
                    }
                }

                // Extensibility hook for custom / host-specific layers.
                do_action( 'atarim_purge_url_cache', $url );

                $success = empty( $errors );
                $message = sprintf(
                    'Purged URL from %d provider(s): %s. %d detected provider(s) support site-wide flush only. %d error(s).',
                    count( $purged ),
                    $purged ? implode( ', ', $purged ) : 'none',
                    count( $unsupported ),
                    count( $errors )
                );

                return [
                    'success'     => $success,
                    'url'         => $url,
                    'purged'      => $purged,
                    'unsupported' => $unsupported,
                    'errors'      => $errors,
                    'message'     => $message,
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

        // ---- get-cache-status ----
        wp_register_ability( 'atarim/get-cache-status', [
            'label'               => 'Get Cache Status',
            'description'         => 'Reports which cache layers are currently detected on the site (object cache, page-cache plugins, CDN/host integrations) without purging anything. Use this before purging to understand what a purge-all-caches call would affect. Also reports whether a persistent object cache backend is active.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'object_cache_persistent' => [ 'type' => 'boolean' ],
                    'detected'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'not_detected' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'supports_url_purge' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'    => [ 'type' => 'string' ],
                ],
                'required' => [ 'detected', 'not_detected', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $detected     = [];
                $not_detected = [];
                $url_purge    = [];

                foreach ( $this->avcf_cache_providers() as $provider ) {
                    if ( $provider['key'] === 'object_cache' ) {
                        continue; // Reported separately below.
                    }
                    if ( $this->avcf_provider_detected( $provider ) ) {
                        $detected[] = $provider['label'];
                        if ( ! empty( $provider['purge_url'] ) ) {
                            $url_purge[] = $provider['label'];
                        }
                    } else {
                        $not_detected[] = $provider['label'];
                    }
                }

                return [
                    'object_cache_persistent' => (bool) wp_using_ext_object_cache(),
                    'detected'                => $detected,
                    'not_detected'            => $not_detected,
                    'supports_url_purge'      => $url_purge,
                    'message'                 => sprintf(
                        '%d cache layer(s) detected: %s.',
                        count( $detected ),
                        $detected ? implode( ', ', $detected ) : 'none (object cache only)'
                    ),
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
    }

    /**
     * Whether a provider is present on this site.
     *
     * @param array $provider
     * @return bool
     */
    protected function avcf_provider_detected( $provider ) {
        try {
            return (bool) call_user_func( $provider['detect'] );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Best-effort cache provider dispatch table.
     *
     * Each entry:
     *   key       string   stable identifier
     *   label     string   human-readable name (used in reports)
     *   detect    callable ():bool          — is this provider present?
     *   purge_all callable ():void          — flush everything for this provider
     *   purge_url callable|null (string):void — purge one URL, or null if unsupported
     *
     * @return array<int,array>
     */
    protected function avcf_cache_providers() {
        return [
            // --- Object cache (WordPress core; always available) ---
            [
                'key'       => 'object_cache',
                'label'     => 'WordPress object cache',
                'detect'    => function() { return function_exists( 'wp_cache_flush' ); },
                'purge_all' => function() { wp_cache_flush(); },
                'purge_url' => null,
            ],

            // --- Page cache plugins ---
            [
                'key'       => 'wp_rocket',
                'label'     => 'WP Rocket',
                'detect'    => function() { return function_exists( 'rocket_clean_domain' ); },
                'purge_all' => function() { rocket_clean_domain(); },
                'purge_url' => function( $url ) {
                    if ( function_exists( 'rocket_clean_files' ) ) {
                        rocket_clean_files( $url );
                    }
                },
            ],
            [
                'key'       => 'w3tc',
                'label'     => 'W3 Total Cache',
                'detect'    => function() { return function_exists( 'w3tc_flush_all' ); },
                'purge_all' => function() { w3tc_flush_all(); },
                'purge_url' => function( $url ) {
                    if ( function_exists( 'w3tc_flush_url' ) ) {
                        w3tc_flush_url( $url );
                    }
                },
            ],
            [
                'key'       => 'wp_super_cache',
                'label'     => 'WP Super Cache',
                'detect'    => function() { return function_exists( 'wp_cache_clear_cache' ); },
                'purge_all' => function() { wp_cache_clear_cache(); },
                'purge_url' => null,
            ],
            [
                'key'       => 'litespeed',
                'label'     => 'LiteSpeed Cache',
                'detect'    => function() { return defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ); },
                'purge_all' => function() { do_action( 'litespeed_purge_all' ); },
                'purge_url' => function( $url ) { do_action( 'litespeed_purge_url', $url ); },
            ],
            [
                'key'       => 'wp_fastest_cache',
                'label'     => 'WP Fastest Cache',
                'detect'    => function() {
                    global $wp_fastest_cache;
                    return is_object( $wp_fastest_cache ) && method_exists( $wp_fastest_cache, 'deleteCache' );
                },
                'purge_all' => function() {
                    global $wp_fastest_cache;
                    $wp_fastest_cache->deleteCache( true );
                },
                'purge_url' => null,
            ],
            [
                'key'       => 'cache_enabler',
                'label'     => 'Cache Enabler',
                'detect'    => function() {
                    return class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' );
                },
                'purge_all' => function() { Cache_Enabler::clear_complete_cache(); },
                'purge_url' => function( $url ) {
                    if ( method_exists( 'Cache_Enabler', 'clear_page_cache_by_url' ) ) {
                        Cache_Enabler::clear_page_cache_by_url( $url );
                    }
                },
            ],
            [
                'key'       => 'autoptimize',
                'label'     => 'Autoptimize',
                'detect'    => function() {
                    return class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' );
                },
                'purge_all' => function() { autoptimizeCache::clearall(); },
                'purge_url' => null,
            ],

            // --- CDN / host edge integrations ---
            [
                'key'       => 'sg_optimizer',
                'label'     => 'SG Optimizer (SiteGround)',
                'detect'    => function() {
                    return function_exists( 'sg_cachepress_purge_cache' ) || has_action( 'siteground_optimizer_flush_cache' );
                },
                'purge_all' => function() {
                    if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
                        sg_cachepress_purge_cache();
                    } else {
                        do_action( 'siteground_optimizer_flush_cache' );
                    }
                },
                'purge_url' => null,
            ],
            [
                'key'       => 'nginx_helper',
                'label'     => 'Nginx Helper',
                'detect'    => function() { return has_action( 'rt_nginx_helper_purge_all' ); },
                'purge_all' => function() { do_action( 'rt_nginx_helper_purge_all' ); },
                'purge_url' => null,
            ],
            [
                'key'       => 'wp_engine',
                'label'     => 'WP Engine',
                'detect'    => function() {
                    return class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' );
                },
                'purge_all' => function() { WpeCommon::purge_varnish_cache(); },
                'purge_url' => null,
            ],
            [
                'key'       => 'kinsta',
                'label'     => 'Kinsta Cache',
                'detect'    => function() { return has_action( 'kinsta_cache_purge_all' ) || function_exists( 'kinsta_cache_purge' ); },
                'purge_all' => function() {
                    if ( has_action( 'kinsta_cache_purge_all' ) ) {
                        do_action( 'kinsta_cache_purge_all' );
                    } elseif ( function_exists( 'kinsta_cache_purge' ) ) {
                        kinsta_cache_purge();
                    }
                },
                'purge_url' => null,
            ],
            [
                'key'       => 'breeze',
                'label'     => 'Breeze (Cloudways)',
                'detect'    => function() { return class_exists( 'Breeze_PurgeCache' ) || has_action( 'breeze_clear_all_cache' ); },
                'purge_all' => function() {
                    if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
                        Breeze_PurgeCache::breeze_cache_flush();
                    } else {
                        do_action( 'breeze_clear_all_cache' );
                    }
                },
                'purge_url' => null,
            ],
        ];
    }
}
