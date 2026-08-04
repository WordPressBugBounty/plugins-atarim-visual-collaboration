<?php
/**
 * Global styles (theme.json user overrides) MCP abilities.
 *
 * Global styles are the single biggest lever for making a block-theme site
 * look bespoke rather than default: the colour palette, typography, spacing,
 * and per-element/per-block style rules. They are stored as a user-layer
 * theme.json document in the wp_global_styles custom post type — invisible to
 * the generic content and settings tools.
 *
 * get-global-styles reports both the PRESETS the active theme defines (the
 * palette slugs, font families, font sizes and spacing sizes the AI may
 * reference) and the CURRENT user overrides on top of them. update-global-styles
 * deep-merges a partial theme.json-shaped payload into the existing overrides,
 * sanitises it through WP_Theme_JSON (the same validation core's Site Editor
 * uses), and saves it — so a caller can set brand colours or fonts without
 * having to send the whole document.
 *
 * Colour/font/spacing values may be given as preset references
 * ("var:preset|color|accent-1") or literals ("#0d1b2a", "1.25rem"); preset
 * references are the portable form and are normalised to CSS custom properties
 * on save.
 *
 * Exposed abilities:
 *   atarim/get-global-styles     Theme presets + current user overrides.
 *   atarim/update-global-styles  Deep-merge a partial theme.json into user overrides.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Global_Styles extends AVCF_Abilities_Base {

    public function register() {
        $this->register_get();
        $this->register_update();
    }

    private function register_get() {
        wp_register_ability( 'atarim/get-global-styles', [
            'label'               => 'Get Global Styles',
            'description'         => 'Returns the site\'s global design tokens. presets are what the active theme defines and what update-global-styles can reference by slug: palette (colour slug + value + name), gradients, font_families (slug + name), font_sizes (slug + size) and spacing_sizes. user_styles and user_settings are the current theme.json overrides layered on top (empty objects when the theme defaults are untouched). Read this before update-global-styles so you use real preset slugs (e.g. reference the accent colour as "var:preset|color|accent-1") and know what is already set.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'       => [ 'type' => 'boolean' ],
                    'is_block_theme'=> [ 'type' => 'boolean' ],
                    'presets'       => [ 'type' => 'object' ],
                    'user_styles'   => [ 'type' => 'object' ],
                    'user_settings' => [ 'type' => 'object' ],
                    'message'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
                    return [ 'success' => false, 'message' => 'This WordPress version does not support global styles (theme.json).' ];
                }

                $settings = WP_Theme_JSON_Resolver::get_theme_data()->get_settings();
                $presets  = [
                    'palette'       => $this->avcf_map_preset( $settings, [ 'color', 'palette' ], [ 'slug', 'color', 'name' ] ),
                    'gradients'     => $this->avcf_map_preset( $settings, [ 'color', 'gradients' ], [ 'slug', 'gradient', 'name' ] ),
                    'font_families' => $this->avcf_map_preset( $settings, [ 'typography', 'fontFamilies' ], [ 'slug', 'name' ] ),
                    'font_sizes'    => $this->avcf_map_preset( $settings, [ 'typography', 'fontSizes' ], [ 'slug', 'size', 'name' ] ),
                    'spacing_sizes' => $this->avcf_map_preset( $settings, [ 'spacing', 'spacingSizes' ], [ 'slug', 'size', 'name' ] ),
                ];

                $user = $this->avcf_read_user_global_styles();

                return [
                    'success'       => true,
                    'is_block_theme'=> function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
                    'presets'       => $presets,
                    'user_styles'   => isset( $user['styles'] ) && is_array( $user['styles'] ) ? $user['styles'] : (object) [],
                    'user_settings' => isset( $user['settings'] ) && is_array( $user['settings'] ) ? $user['settings'] : (object) [],
                    'message'       => sprintf( '%d palette colour(s), %d font family(ies) available.', count( $presets['palette'] ), count( $presets['font_families'] ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    private function register_update() {
        wp_register_ability( 'atarim/update-global-styles', [
            'label'               => 'Update Global Styles',
            'description'         => 'Deep-merges a partial theme.json into the site\'s user global styles — the site-wide colours, typography, and spacing. Pass styles (theme.json "styles" shape: e.g. {"color":{"background":"var:preset|color|base","text":"var:preset|color|contrast"},"elements":{"button":{"color":{"background":"var:preset|color|accent-1"}}},"typography":{"fontFamily":"var:preset|font-family|manrope"}}) and/or settings (theme.json "settings" shape, e.g. custom palette definitions). Only the keys you pass are changed; everything else is preserved (deep merge, not replace). Values may be preset references ("var:preset|color|<slug>" — get valid slugs from get-global-styles) or literals ("#0d1b2a"). Pass null as a value to REMOVE that key from the overrides (e.g. {"elements":{"button":{"color":{"background":null}}}} drops a previously-set button colour). Pass reset:true to clear ALL user overrides and fall back to the theme defaults (the global-styles equivalent of revert-template) — reset ignores styles/settings. The payload is sanitised through WordPress\'s theme.json validator; unknown or unsafe keys are dropped. Affects every page site-wide.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'styles'   => [ 'type' => 'object', 'description' => 'Partial theme.json "styles" object to merge. Use null values to remove keys.' ],
                    'settings' => [ 'type' => 'object', 'description' => 'Partial theme.json "settings" object to merge. Use null values to remove keys.' ],
                    'reset'    => [ 'type' => 'boolean', 'description' => 'When true, clears ALL user overrides (styles and settings) and restores the theme defaults. Ignores styles/settings.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'       => [ 'type' => 'boolean' ],
                    'user_styles'   => [ 'type' => 'object' ],
                    'user_settings' => [ 'type' => 'object' ],
                    'message'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! class_exists( 'WP_Theme_JSON' ) ) {
                    return [ 'success' => false, 'message' => 'This WordPress version does not support global styles (theme.json).' ];
                }

                $reset        = ! empty( $input['reset'] );
                $has_styles   = isset( $input['styles'] ) && is_array( $input['styles'] );
                $has_settings = isset( $input['settings'] ) && is_array( $input['settings'] );
                if ( ! $reset && ! $has_styles && ! $has_settings ) {
                    return [ 'success' => false, 'message' => 'Provide styles and/or settings to merge, or reset:true.' ];
                }

                $gid = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
                if ( ! $gid ) {
                    return [ 'success' => false, 'message' => 'Could not resolve the user global styles record.' ];
                }

                $data = $this->avcf_read_user_global_styles();
                $version = isset( $data['version'] ) ? (int) $data['version'] : 2;

                if ( $reset ) {
                    $empty = [ 'version' => $version, 'isGlobalStylesUserThemeJSON' => true ];
                    $saved = wp_update_post( [ 'ID' => (int) $gid, 'post_content' => wp_json_encode( $empty ) ], true );
                    if ( is_wp_error( $saved ) ) {
                        return [ 'success' => false, 'message' => 'Reset failed: ' . $saved->get_error_message() ];
                    }
                    WP_Theme_JSON_Resolver::clean_cached_data();
                    return [
                        'success'       => true,
                        'user_styles'   => (object) [],
                        'user_settings' => (object) [],
                        'message'       => 'Global styles reset to theme defaults.',
                    ];
                }

                if ( $has_styles ) {
                    $data['styles'] = $this->avcf_deep_merge( isset( $data['styles'] ) ? $data['styles'] : [], $input['styles'] );
                }
                if ( $has_settings ) {
                    $data['settings'] = $this->avcf_deep_merge( isset( $data['settings'] ) ? $data['settings'] : [], $input['settings'] );
                }

                $config = [ 'version' => $version ];
                if ( ! empty( $data['styles'] ) ) {
                    $config['styles'] = $data['styles'];
                }
                if ( ! empty( $data['settings'] ) ) {
                    $config['settings'] = $data['settings'];
                }

                $sanitised = ( new WP_Theme_JSON( $config, 'custom' ) )->get_raw_data();
                $sanitised['isGlobalStylesUserThemeJSON'] = true;
                if ( empty( $sanitised['version'] ) ) {
                    $sanitised['version'] = $version;
                }

                $saved = wp_update_post( [ 'ID' => (int) $gid, 'post_content' => wp_json_encode( $sanitised ) ], true );
                if ( is_wp_error( $saved ) ) {
                    return [ 'success' => false, 'message' => 'Save failed: ' . $saved->get_error_message() ];
                }

                WP_Theme_JSON_Resolver::clean_cached_data();

                return [
                    'success'       => true,
                    'user_styles'   => isset( $sanitised['styles'] ) ? $sanitised['styles'] : (object) [],
                    'user_settings' => isset( $sanitised['settings'] ) ? $sanitised['settings'] : (object) [],
                    'message'       => 'Global styles updated site-wide.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }

    /**
     * Read and decode the user global styles theme.json document.
     *
     * @return array
     */
    private function avcf_read_user_global_styles() {
        $gid = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if ( ! $gid ) {
            return [];
        }
        $data = json_decode( (string) get_post( $gid )->post_content, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Extract a preset group from a settings array into a lean list, keeping
     * only the requested keys from each entry.
     *
     * @param array $settings
     * @param array $path  Path into the settings array, e.g. ['color','palette'].
     * @param array $keys  Keys to keep from each preset entry.
     * @return array<int,array>
     */
    private function avcf_map_preset( $settings, $path, $keys ) {
        $node = $settings;
        foreach ( $path as $segment ) {
            if ( ! is_array( $node ) || ! isset( $node[ $segment ] ) ) {
                return [];
            }
            $node = $node[ $segment ];
        }

        // Presets can be grouped by origin (theme/default/custom) or be a flat list.
        if ( isset( $node['theme'] ) || isset( $node['default'] ) || isset( $node['custom'] ) ) {
            $flat = [];
            foreach ( [ 'theme', 'custom', 'default' ] as $origin ) {
                if ( ! empty( $node[ $origin ] ) && is_array( $node[ $origin ] ) ) {
                    $flat = array_merge( $flat, $node[ $origin ] );
                }
            }
            $node = $flat;
        }

        $out = [];
        foreach ( (array) $node as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $picked = [];
            foreach ( $keys as $k ) {
                if ( isset( $entry[ $k ] ) ) {
                    $picked[ $k ] = $entry[ $k ];
                }
            }
            if ( ! empty( $picked ) ) {
                $out[] = $picked;
            }
        }
        return $out;
    }

    /**
     * Recursively merge $overrides into $base. Associative arrays merge by key;
     * scalars and lists overwrite. Used to layer a partial theme.json onto the
     * existing user document without clobbering untouched branches.
     *
     * @param array $base
     * @param array $overrides
     * @return array
     */
    private function avcf_deep_merge( $base, $overrides ) {
        foreach ( $overrides as $key => $value ) {
            if ( $value === null ) {
                unset( $base[ $key ] );
                continue;
            }
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && $this->avcf_is_assoc( $value ) ) {
                $merged = $this->avcf_deep_merge( $base[ $key ], $value );
                if ( $merged === [] ) {
                    unset( $base[ $key ] );
                } else {
                    $base[ $key ] = $merged;
                }
                continue;
            }
            $base[ $key ] = $value;
        }
        return $base;
    }

    /**
     * Whether an array is associative (string keys) rather than a list.
     *
     * @param array $arr
     * @return bool
     */
    private function avcf_is_assoc( $arr ) {
        if ( $arr === [] ) {
            return false;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }
}
