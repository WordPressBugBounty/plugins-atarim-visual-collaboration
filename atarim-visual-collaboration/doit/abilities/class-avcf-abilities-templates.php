<?php
/**
 * Block-theme templates and template-part MCP abilities (Full Site Editing).
 *
 * In a block theme the site-wide chrome — the header, footer, and the layout
 * of the home / single / archive / 404 screens — lives in block TEMPLATES and
 * TEMPLATE PARTS, not in posts, classic menus, or widgets. These are file-based
 * in the active theme until a user customises one, at which point WordPress
 * stores an overriding row in the wp_template / wp_template_part post types.
 *
 * The generic content abilities cannot reach these: list-post-types only
 * surfaces public types, and list-content returns nothing for wp_template
 * because file-based templates are not database rows. This category exposes
 * them explicitly so the AI can read and edit the parts of a site that define
 * its overall design.
 *
 * A single set of tools handles both template types via a `type` discriminator
 * ("wp_template" | "wp_template_part"), mirroring how core's Site Editor treats
 * them. Writes upsert an overriding post attached to the active theme's
 * wp_theme term (validated against WordPress core template resolution); revert
 * deletes that override so the theme file takes over again.
 *
 * Exposed abilities:
 *   atarim/get-site-editor-overview  Discovery: is-block-theme, template/part/pattern/navigation counts.
 *   atarim/list-templates            All templates or parts with source (theme/custom) + area.
 *   atarim/get-template              One template's block markup + top-level block summary.
 *   atarim/update-template           Upsert a template's block content (overrides the theme file).
 *   atarim/revert-template           Delete a customisation so the theme file is used again.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Templates extends AVCF_Abilities_Base {

    private const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];

    public function register() {
        $this->register_overview();
        $this->register_list();
        $this->register_get();
        $this->register_update();
        $this->register_revert();
    }

    private function register_overview() {
        wp_register_ability( 'atarim/get-site-editor-overview', [
            'label'               => 'Get Site Editor Overview',
            'description'         => 'Discovery entry point for the Full Site Editing layer that the generic content tools cannot see. Reports whether the active theme is a block theme, the active theme name, and counts + identifiers for block templates (home/single/archive/404/etc.), template parts (header/footer/sidebar), registered block patterns and their categories, wp_navigation menus, and whether global styles have been customised. Call this first when building or restyling a block-theme site to learn what design surfaces exist, then use list-templates / list-patterns / get-global-styles / list-navigation to go deeper.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'                => [ 'type' => 'boolean' ],
                    'is_block_theme'         => [ 'type' => 'boolean' ],
                    'active_theme'           => [ 'type' => 'string' ],
                    'templates'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'template_parts'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'pattern_count'          => [ 'type' => 'integer' ],
                    'pattern_categories'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'navigation_menu_count'  => [ 'type' => 'integer' ],
                    'global_styles_customised' => [ 'type' => 'boolean' ],
                    'message'                => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $is_block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
                $theme    = wp_get_theme();

                $templates = $this->avcf_template_slugs( 'wp_template' );
                $parts     = $this->avcf_template_slugs( 'wp_template_part' );

                $pattern_categories = [];
                if ( class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
                    foreach ( WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered() as $cat ) {
                        $pattern_categories[] = (string) $cat['name'];
                    }
                }
                $pattern_count = class_exists( 'WP_Block_Patterns_Registry' )
                    ? count( WP_Block_Patterns_Registry::get_instance()->get_all_registered() )
                    : 0;

                $nav_count = (int) wp_count_posts( 'wp_navigation' )->publish;
                $gs_custom = $this->avcf_global_styles_customised();

                return [
                    'success'                  => true,
                    'is_block_theme'           => $is_block,
                    'active_theme'             => (string) $theme->get( 'Name' ),
                    'templates'                => $templates,
                    'template_parts'           => $parts,
                    'pattern_count'            => $pattern_count,
                    'pattern_categories'       => $pattern_categories,
                    'navigation_menu_count'    => $nav_count,
                    'global_styles_customised' => $gs_custom,
                    'message'                  => $is_block
                        ? sprintf( '%s (block theme): %d templates, %d parts, %d patterns, %d navigation menu(s).', $theme->get( 'Name' ), count( $templates ), count( $parts ), $pattern_count, $nav_count )
                        : sprintf( '%s is a classic theme — templates/global-styles are limited; patterns and classic menus/widgets apply instead.', $theme->get( 'Name' ) ),
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

    private function register_list() {
        wp_register_ability( 'atarim/list-templates', [
            'label'               => 'List Templates',
            'description'         => 'Returns block-theme templates or template parts. type controls which: "wp_template" (default) for full-page templates (home, index, single, page, archive, search, 404, and custom page-* templates), or "wp_template_part" for reusable regions (header, footer, sidebar). Each item includes id ("stylesheet//slug", used by get/update/revert-template), slug, title, description, source ("theme" = still the unmodified theme file, "custom" = customised/overridden in the database, "plugin"), and for parts the area ("header"/"footer"/"uncategorized"). To edit the site header or footer, find the matching template part here, then get-template + update-template.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'enum'        => self::TEMPLATE_TYPES,
                        'default'     => 'wp_template',
                        'description' => 'Which set to list. "wp_template" = page templates; "wp_template_part" = header/footer/sidebar regions.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'type'      => [ 'type' => 'string' ],
                    'total'     => [ 'type' => 'integer' ],
                    'templates' => [ 'type' => 'array' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $type = $this->avcf_resolve_type( $input );
                if ( $type === null ) {
                    return [ 'success' => false, 'message' => 'type must be "wp_template" or "wp_template_part".' ];
                }

                $templates = [];
                foreach ( get_block_templates( [], $type ) as $tpl ) {
                    $entry = [
                        'id'          => (string) $tpl->id,
                        'slug'        => (string) $tpl->slug,
                        'title'       => (string) $tpl->title,
                        'description' => (string) $tpl->description,
                        'source'      => (string) $tpl->source,
                    ];
                    if ( $type === 'wp_template_part' ) {
                        $entry['area'] = isset( $tpl->area ) ? (string) $tpl->area : 'uncategorized';
                    }
                    $templates[] = $entry;
                }

                return [
                    'success'   => true,
                    'type'      => $type,
                    'total'     => count( $templates ),
                    'templates' => $templates,
                    'message'   => sprintf( '%d %s(s) available.', count( $templates ), $type === 'wp_template_part' ? 'template part' : 'template' ),
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

    private function register_get() {
        wp_register_ability( 'atarim/get-template', [
            'label'               => 'Get Template',
            'description'         => 'Returns one template or template part in full, including its raw Gutenberg block markup (content) and a top-level block summary (the ordered list of block names it is composed of). Identify it by id ("stylesheet//slug" from list-templates) OR by slug + type. is_custom is true when the template has been overridden in the database, false when it is still the pristine theme file. Read this before update-template so you can edit the existing markup rather than replace it blindly.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id'   => [ 'type' => 'string', 'description' => 'Full template id "stylesheet//slug". Pass id OR slug+type.' ],
                    'slug' => [ 'type' => 'string', 'description' => 'Template slug, e.g. "home" or "header". Requires type.' ],
                    'type' => [ 'type' => 'string', 'enum' => self::TEMPLATE_TYPES, 'default' => 'wp_template' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'template' => [ 'type' => 'object' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                list( $tpl, $type, $error ) = $this->avcf_locate_template( $input );
                if ( $error !== null ) {
                    return [ 'success' => false, 'message' => $error ];
                }

                $content = (string) $tpl->content;
                $summary = [];
                if ( function_exists( 'parse_blocks' ) ) {
                    foreach ( parse_blocks( $content ) as $block ) {
                        if ( ! empty( $block['blockName'] ) ) {
                            $summary[] = (string) $block['blockName'];
                        }
                    }
                }

                $template = [
                    'id'           => (string) $tpl->id,
                    'slug'         => (string) $tpl->slug,
                    'type'         => $type,
                    'title'        => (string) $tpl->title,
                    'description'  => (string) $tpl->description,
                    'source'       => (string) $tpl->source,
                    'is_custom'    => ( (string) $tpl->source === 'custom' ),
                    'content'      => $content,
                    'block_summary'=> $summary,
                ];
                if ( $type === 'wp_template_part' ) {
                    $template['area'] = isset( $tpl->area ) ? (string) $tpl->area : 'uncategorized';
                }

                return [
                    'success'  => true,
                    'template' => $template,
                    'message'  => sprintf( 'Template "%s" (%s) has %d top-level block(s).', $tpl->slug, $tpl->source, count( $summary ) ),
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
        wp_register_ability( 'atarim/update-template', [
            'label'               => 'Update Template',
            'description'         => 'Creates or replaces a template / template part, overriding the theme file with a database customisation. This is how you edit the site-wide header and footer (type=wp_template_part) or the layout of the home/page/single screens (type=wp_template). Identify by slug + type (id is also accepted). content is the FULL new Gutenberg block markup for the template — it REPLACES the previous content, so pass the complete markup (use get-template first to start from the current markup, or compose from patterns via list-patterns/get-pattern). content_format: "blocks" (default) stores markup as-is; "auto" wraps plain text in paragraph blocks. For a brand-new template part, also pass area ("header"/"footer"/"uncategorized"). Use revert-template to undo. Malformed block markup can visually break the site — validate structure before writing.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'slug'           => [ 'type' => 'string', 'description' => 'Template slug, e.g. "home" or "header". Pass slug+type OR id.' ],
                    'type'           => [ 'type' => 'string', 'enum' => self::TEMPLATE_TYPES, 'default' => 'wp_template' ],
                    'id'             => [ 'type' => 'string', 'description' => 'Full "stylesheet//slug" id (alternative to slug+type).' ],
                    'content'        => [ 'type' => 'string', 'description' => 'Full replacement Gutenberg block markup.' ],
                    'content_format' => [ 'type' => 'string', 'enum' => [ 'blocks', 'auto', 'raw' ], 'default' => 'blocks' ],
                    'title'          => [ 'type' => 'string', 'description' => 'Optional title. Defaults to the existing/derived title.' ],
                    'description'    => [ 'type' => 'string', 'description' => 'Optional description (stored on the template).' ],
                    'area'           => [ 'type' => 'string', 'description' => 'Template parts only: "header", "footer", or "uncategorized". Defaults to the existing area or "uncategorized".' ],
                ],
                'required' => [ 'content' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'string' ],
                    'wp_id'   => [ 'type' => 'integer' ],
                    'created' => [ 'type' => 'boolean' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $type = $this->avcf_resolve_type( $input );
                $slug = $this->avcf_resolve_slug( $input );
                if ( $type === null || $slug === '' ) {
                    return [ 'success' => false, 'message' => 'Provide slug + type, or a full id.' ];
                }
                if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) || $input['content'] === '' ) {
                    return [ 'success' => false, 'message' => 'content is required.' ];
                }

                $format  = isset( $input['content_format'] ) ? (string) $input['content_format'] : 'blocks';
                $content = $this->avcf_prepare_content_body( (string) $input['content'], $format );
                $area    = isset( $input['area'] ) ? sanitize_key( (string) $input['area'] ) : null;
                $title   = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null;
                $desc    = isset( $input['description'] ) ? sanitize_text_field( (string) $input['description'] ) : null;

                list( $wp_id, $created, $err ) = $this->avcf_upsert_template( $type, $slug, $content, $title, $desc, $area );
                if ( $err !== null ) {
                    return [ 'success' => false, 'message' => $err ];
                }

                return [
                    'success' => true,
                    'id'      => get_stylesheet() . '//' . $slug,
                    'wp_id'   => (int) $wp_id,
                    'created' => (bool) $created,
                    'message' => sprintf( '%s "%s" %s.', $type === 'wp_template_part' ? 'Template part' : 'Template', $slug, $created ? 'created' : 'updated' ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
            ],
        ] );
    }

    private function register_revert() {
        wp_register_ability( 'atarim/revert-template', [
            'label'               => 'Revert Template',
            'description'         => 'Removes a template / template-part customisation and restores the original theme file. Identify by slug + type or id. Only affects templates whose source is "custom"; a template still served from the theme file is left untouched and reported as such. This deletes the overriding database row (irreversible), it does not delete the theme file. Use this to undo an update-template that went wrong.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'slug' => [ 'type' => 'string' ],
                    'type' => [ 'type' => 'string', 'enum' => self::TEMPLATE_TYPES, 'default' => 'wp_template' ],
                    'id'   => [ 'type' => 'string' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'reverted' => [ 'type' => 'boolean' ],
                    'source'   => [ 'type' => 'string' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                list( $tpl, $type, $error ) = $this->avcf_locate_template( $input );
                if ( $error !== null ) {
                    return [ 'success' => false, 'message' => $error ];
                }
                if ( (string) $tpl->source !== 'custom' || empty( $tpl->wp_id ) ) {
                    return [
                        'success'  => true,
                        'reverted' => false,
                        'source'   => (string) $tpl->source,
                        'message'  => sprintf( 'Template "%s" is served from the theme file (source: %s) — nothing to revert.', $tpl->slug, $tpl->source ),
                    ];
                }

                $deleted = wp_delete_post( (int) $tpl->wp_id, true );
                if ( ! $deleted ) {
                    return [ 'success' => false, 'reverted' => false, 'message' => sprintf( 'Failed to delete customisation for "%s".', $tpl->slug ) ];
                }

                return [
                    'success'  => true,
                    'reverted' => true,
                    'source'   => 'theme',
                    'message'  => sprintf( 'Template "%s" reverted to the theme file.', $tpl->slug ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_theme_options' );
            },
            'meta' => [
                'mcp'         => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
            ],
        ] );
    }

    /**
     * Resolve the template type from input, defaulting to wp_template.
     *
     * @param array $input
     * @return string|null  Null when an explicit invalid type was passed.
     */
    private function avcf_resolve_type( $input ) {
        if ( ! empty( $input['id'] ) && empty( $input['type'] ) ) {
            return 'wp_template';
        }
        $type = isset( $input['type'] ) ? (string) $input['type'] : 'wp_template';
        return in_array( $type, self::TEMPLATE_TYPES, true ) ? $type : null;
    }

    /**
     * Resolve the template slug from either a full id or an explicit slug.
     *
     * @param array $input
     * @return string
     */
    private function avcf_resolve_slug( $input ) {
        if ( ! empty( $input['id'] ) && strpos( (string) $input['id'], '//' ) !== false ) {
            $parts = explode( '//', (string) $input['id'], 2 );
            return sanitize_title( $parts[1] );
        }
        return isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
    }

    /**
     * Locate a template object from input (id, or slug + type).
     *
     * @param array $input
     * @return array{0:?object,1:string,2:?string}  [template, type, error]
     */
    private function avcf_locate_template( $input ) {
        $type = $this->avcf_resolve_type( $input );
        if ( $type === null ) {
            return [ null, '', 'type must be "wp_template" or "wp_template_part".' ];
        }
        $slug = $this->avcf_resolve_slug( $input );
        if ( $slug === '' ) {
            return [ null, $type, 'Provide slug + type, or a full id.' ];
        }
        $tpl = get_block_template( get_stylesheet() . '//' . $slug, $type );
        if ( ! $tpl ) {
            return [ null, $type, sprintf( 'No %s found with slug "%s".', $type, $slug ) ];
        }
        return [ $tpl, $type, null ];
    }

    /**
     * Insert or update the database override for a block template.
     *
     * @param string      $type
     * @param string      $slug
     * @param string      $content
     * @param string|null $title
     * @param string|null $description
     * @param string|null $area
     * @return array{0:int,1:bool,2:?string}  [wp_id, created, error]
     */
    private function avcf_upsert_template( $type, $slug, $content, $title, $description, $area ) {
        $stylesheet = get_stylesheet();
        $existing   = get_block_template( $stylesheet . '//' . $slug, $type );

        $postarr = [
            'post_type'    => $type,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_content' => $content,
            'post_title'   => $title !== null && $title !== '' ? $title : ( $existing ? (string) $existing->title : $slug ),
        ];
        if ( $description !== null ) {
            $postarr['post_excerpt'] = $description;
        }

        $is_update = $existing && ! empty( $existing->wp_id );
        if ( $is_update ) {
            $postarr['ID'] = (int) $existing->wp_id;
            $wp_id         = wp_update_post( $postarr, true );
        } else {
            $wp_id = wp_insert_post( $postarr, true );
        }

        if ( is_wp_error( $wp_id ) ) {
            return [ 0, false, 'Save failed: ' . $wp_id->get_error_message() ];
        }

        wp_set_object_terms( (int) $wp_id, $stylesheet, 'wp_theme' );
        if ( $type === 'wp_template_part' ) {
            $use_area = $area ?: ( $existing && ! empty( $existing->area ) ? (string) $existing->area : 'uncategorized' );
            wp_set_object_terms( (int) $wp_id, $use_area, 'wp_template_part_area' );
        }

        return [ (int) $wp_id, ! $is_update, null ];
    }

    /**
     * Collect the slugs of all templates of a given type.
     *
     * @param string $type
     * @return array<int,string>
     */
    private function avcf_template_slugs( $type ) {
        $slugs = [];
        foreach ( get_block_templates( [], $type ) as $tpl ) {
            $slugs[] = (string) $tpl->slug;
        }
        sort( $slugs );
        return $slugs;
    }

    /**
     * Whether user global styles carry any real customisation beyond the
     * version marker and the isGlobalStylesUserThemeJSON flag.
     *
     * @return bool
     */
    private function avcf_global_styles_customised() {
        if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
            return false;
        }
        $gid = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if ( ! $gid ) {
            return false;
        }
        $data = json_decode( (string) get_post( $gid )->post_content, true );
        if ( ! is_array( $data ) ) {
            return false;
        }
        return ! empty( $data['styles'] ) || ! empty( $data['settings'] );
    }
}
