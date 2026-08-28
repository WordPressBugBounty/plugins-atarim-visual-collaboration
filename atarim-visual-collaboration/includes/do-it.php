<?php

if ( ! defined('ABSPATH') ) exit;

/**
 * Whether Do It is enabled for this site. Gates every Do It surface: the inline
 * config, the block identity markers, and the REST routes.
 */
function atarim_doit_enabled() {
    return ! empty( get_option('avc_enable_doit', false) );
}

/**
 * Whether this request carries a valid Atarim server-to-server token. Lets the
 * backend drive Do It with no WordPress session, the same auth the MCP endpoint
 * and the WP Activity Log routes use.
 */
function atarim_doit_token_request() {
    if ( ! class_exists('AVCF_MCP_Auth') ) return false;
    $auth = new AVCF_MCP_Auth();
    return (bool) $auth->avcf_mcp_validate_request();
}

/**
 * Whether the current user may edit the given post through the cookie-authenticated
 * Do It routes. Only the write credentials are gated on this; targeting data is
 * public, because a task may be left by any visitor.
 */
function atarim_doit_user_can_edit($post_id) {
    if ( ! is_user_logged_in() ) return false;
    $post_id = (int) $post_id;
    return $post_id ? current_user_can('edit_post', $post_id) : current_user_can('edit_posts');
}

/**
 * Whether it is worth emitting Do It's targeting data on this request.
 *
 * The inline config and the block-identity attributes are read by exactly one
 * consumer: the Atarim collaboration script. When that script is not being emitted
 * — most anonymous traffic on a site whose collaboration is restricted — the
 * attributes are never read, and injecting them costs a WP_HTML_Tag_Processor pass
 * over every rendered block for nothing. Measured at +43% block-render time on a
 * 4,200-block page, so it is worth asking before doing the work.
 *
 * Falls back to "yes" if the injector is unavailable, so a load-order change
 * degrades to the previous behaviour rather than silently disabling Do It.
 */
function atarim_doit_markers_wanted() {
    if ( ! atarim_doit_enabled() ) return false;

    if ( ! function_exists('atarim_collab_script_will_load') ) return true;

    return atarim_collab_script_will_load();
}

add_action('wp_enqueue_scripts', function () {

    if ( ! is_singular() ) return;
    if ( ! atarim_doit_markers_wanted() ) return;

    $post_id = get_queried_object_id();
    if ( ! $post_id ) return;

    $page_builder = atarim_detect_page_builder($post_id);
    $wrapper_hint = atarim_detect_wrapper_selector_by_theme(); // '' if unknown

    $handle = 'atarim-do-it';
    wp_register_script($handle, '', [], '0.3.1', false);
    wp_enqueue_script($handle);

    // Targeting data is public. Anyone may leave a task, and the task has to
    // record what it points at; postId is not a secret, WordPress already exposes
    // it as the postid-N body class and the ?p=N shortlink.
    $atarim_inline_data = [
        'postId'      => (int) $post_id,
        'pageBuilder' => $page_builder,
        'wrapperHint' => $wrapper_hint,
    ];

    // Write credentials stay gated on the edit capability.
    if ( atarim_doit_user_can_edit($post_id) ) {
        $atarim_inline_data['apiGet']         = esc_url_raw(rest_url('atarim/v1/content/get'));
        $atarim_inline_data['apiSave']        = esc_url_raw(rest_url('atarim/v1/content/save'));
        $atarim_inline_data['apiMediaImport'] = esc_url_raw(rest_url('atarim/v1/media/import'));
        $atarim_inline_data['nonce']          = wp_create_nonce('wp_rest');
    }

    $atarim_inline_data = apply_filters('atarim_inline_data', $atarim_inline_data, $post_id);
    wp_localize_script($handle, 'ATARIM_INLINE', $atarim_inline_data);

    /* This is how to use filter
    add_filter('atarim_inline_data', function ($data, $post_id) {
        if (empty($data['wrapperHint'])) {
            $data['wrapperHint'] = '.site-main .entry-content';
        }
        return $data;
    }, 10, 2);*/
});

/* ===========================
 * Block identity injection
 * Marks every rendered block with data-atarim-block-id (the durable
 * metadata.avcBlockId), plus data-atarim-block-name / -anchor-index as a
 * fallback for blocks that have not been stamped yet.
 *
 * Public on purpose: a task may be left by any visitor, and the task has to
 * record which block it points at. Only the write credentials are capability
 * gated (see the enqueue above).
 * =========================== */
add_action('template_redirect', function () {

    if ( ! is_singular() ) return;
    if ( ! atarim_doit_markers_wanted() ) return;

    $post_id = get_queried_object_id();
    if ( ! $post_id ) return;

    if ( atarim_detect_page_builder($post_id) !== 'block' ) return;

    atarim_ensure_post_stamped($post_id);

    $GLOBALS['atarim_block_counters'] = [];
    $GLOBALS['atarim_post_block_index'] = atarim_build_post_block_index($post_id);

    add_filter('render_block', 'atarim_inject_block_identity', 10, 2);
});

/**
 * Pre-order index of every block in the queried post's content, keyed by avcBlockId.
 *
 * Two divergences made the render-time counter unusable as a target, and this map
 * closes both:
 *
 *  - Scope. The counter increments for every block on the PAGE, so on a block theme
 *    the header and footer template parts consume indices that
 *    atarim_collect_matching_blocks — which walks post_content alone — never sees.
 *    A block theme's post-content group came out as index 4 in the DOM and 0 in
 *    storage, so Do It could not address it at all.
 *  - Order. render_block fires inner-to-outer, so nested blocks were counted in
 *    completion order while post_content is walked pre-order.
 *
 * Building the map from post_content means the emitted index is the one the lookup
 * side actually uses, and blocks outside post_content are simply absent — which is
 * correct, because they are not editable through the content routes.
 *
 * @return array<string, array{index:int, path:string, name:string}>
 */
function atarim_build_post_block_index($post_id) {

    if ( ! class_exists('AVCF_Gutenberg_Helpers') ) return [];

    $post = get_post($post_id);
    if ( ! $post ) return [];

    // Cached against the content hash: this is a full parse_blocks walk on every
    // front-end render, and it measured 14ms on a 4,200-block page. The key changes
    // whenever the content does, so the cache cannot go stale.
    $hash = md5($post->post_content);
    $cache_key = 'avc_block_index_' . $post_id;
    $cached = get_transient($cache_key);

    if (is_array($cached) && ($cached['hash'] ?? null) === $hash) {
        return $cached['map'];
    }

    $map = [];
    $counters = [];
    atarim_walk_post_blocks(parse_blocks($post->post_content), $map, $counters);

    set_transient($cache_key, ['hash' => $hash, 'map' => $map], DAY_IN_SECONDS);

    return $map;
}

/**
 * @param  array<string, array{index:int, path:string, name:string}>  $map
 * @param  array<string, int>  $counters
 */
function atarim_walk_post_blocks(array $blocks, array &$map, array &$counters, string $parent_path = ''): void {

    foreach ($blocks as $i => $block) {
        if (!is_array($block) || empty($block['blockName'])) continue;

        $name = (string) $block['blockName'];
        $path = $parent_path === '' ? (string) $i : $parent_path . '.' . $i;

        if (!isset($counters[$name])) { $counters[$name] = 0; }
        $index = $counters[$name]++;

        $id = AVCF_Gutenberg_Helpers::extract_id($block['attrs'] ?? []);
        if ($id !== '') {
            $map[$id] = ['index' => $index, 'path' => $path, 'name' => $name];
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            atarim_walk_post_blocks($block['innerBlocks'], $map, $counters, $path);
        }
    }
}

/**
 * Give every block in the post a durable metadata.avcBlockId, once.
 *
 * anchor_index cannot be the primary target: it counts render order across the
 * WHOLE page, so on a block theme the header and footer template parts consume
 * indices that atarim_collect_matching_blocks (which walks post_content alone)
 * never sees, and render_block fires inner-to-outer so nested blocks are counted
 * in completion order rather than document order. A stored id sidesteps both, and
 * survives the block being moved, reordered, or edited in Gutenberg.
 *
 * Costs one write per post, ever. The stamp hash is compared first so the common
 * path is a single meta read with no block parsing.
 */
function atarim_ensure_post_stamped($post_id) {

    // The helpers only load when the Abilities API + MCP Adapter are present
    // (see avcf-cluster-loader.php). Without them there is no id to stamp and
    // targeting falls back to anchor_index.
    if ( ! class_exists('AVCF_Gutenberg_Helpers') ) return;

    $post = get_post($post_id);
    if ( ! $post || ! AVCF_Gutenberg_Helpers::is_block_based($post->post_content) ) return;

    if ( get_post_meta($post_id, '_avc_stamp_hash', true) === md5($post->post_content) ) return;

    // A concurrent render must not stamp the same post twice: the loser would
    // reassign fresh ids and orphan any task captured against the winner's.
    $lock = 'avc_stamp_lock_' . $post_id;
    if ( get_transient($lock) ) return;
    set_transient($lock, 1, 30);

    $blocks = AVCF_Gutenberg_Helpers::parse_raw($post->post_content);
    $blocks = atarim_dedupe_block_ids(AVCF_Gutenberg_Helpers::raw_stamp_ids($blocks));
    $stamped = AVCF_Gutenberg_Helpers::serialize_raw($blocks);

    if ($stamped !== $post->post_content) {
        // Slash-safe: post_content round-trips through wp_unslash on save.
        wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($stamped)]);
        clean_post_cache($post_id);
        atarim_refresh_queried_post_content($post_id, $stamped);
    }

    update_post_meta($post_id, '_avc_stamp_hash', md5($stamped));
    delete_transient($lock);
}

/**
 * Give a fresh id to any block repeating an id already seen in this post.
 *
 * Duplicating a block in Gutenberg copies its attributes verbatim, avcBlockId
 * included, and raw_stamp_ids only fills blocks that have NO id — so a duplicate
 * keeps the original's. Lookups return the first match, meaning two tasks pinned
 * to the two copies would both edit the first one.
 *
 * The first occurrence in document order keeps the id, so a task captured before
 * the duplication still resolves to the block it was pinned to.
 *
 * @param  array<int, mixed>  $blocks
 * @return array<int, mixed>
 */
function atarim_dedupe_block_ids(array $blocks): array {
    $seen = [];

    return atarim_dedupe_block_ids_walk($blocks, $seen);
}

/**
 * @param  array<int, mixed>  $blocks
 * @param  array<string, bool>  $seen
 * @return array<int, mixed>
 */
function atarim_dedupe_block_ids_walk(array $blocks, array &$seen): array {
    foreach ($blocks as $i => $block) {
        if (!is_array($block) || empty($block['blockName'])) continue;

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $id = AVCF_Gutenberg_Helpers::extract_id($attrs);

        if ($id !== '') {
            if (isset($seen[$id])) {
                $blocks[$i]['attrs'] = AVCF_Gutenberg_Helpers::set_id($attrs, wp_generate_uuid4());
            } else {
                $seen[$id] = true;
            }
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            $blocks[$i]['innerBlocks'] = atarim_dedupe_block_ids_walk($block['innerBlocks'], $seen);
        }
    }

    return $blocks;
}

/**
 * Push freshly stamped content into the already-built main query.
 *
 * The main query runs before template_redirect, so without this the request that
 * triggers the first stamp renders the pre-stamp content and emits no block ids —
 * and a task created on that pageview would fall back to anchor_index, the very
 * mode the ids exist to replace.
 */
function atarim_refresh_queried_post_content($post_id, $content) {

    global $wp_query;

    if (isset($GLOBALS['post']) && (int) $GLOBALS['post']->ID === (int) $post_id) {
        $GLOBALS['post']->post_content = $content;
    }

    if ($wp_query instanceof WP_Query) {
        if ($wp_query->post && (int) $wp_query->post->ID === (int) $post_id) {
            $wp_query->post->post_content = $content;
        }
        foreach ($wp_query->posts as $queried) {
            if (is_object($queried) && (int) $queried->ID === (int) $post_id) {
                $queried->post_content = $content;
            }
        }
    }
}

function atarim_inject_block_identity(string $block_content, array $block): string {

    if (empty($block['blockName'])) return $block_content;
    if (trim($block_content) === '') return $block_content;

    $block_name = $block['blockName'];
    $block_id   = class_exists('AVCF_Gutenberg_Helpers')
        ? AVCF_Gutenberg_Helpers::extract_id($block['attrs'] ?? [])
        : '';

    $post_index = $GLOBALS['atarim_post_block_index'] ?? [];

    // A block carrying an id that the post-content map knows: emit the index the
    // lookup side computes, and nothing else needs to be guessed.
    if ($block_id !== '' && isset($post_index[$block_id])) {
        $anchor_index = $post_index[$block_id]['index'];
    } elseif ($post_index !== []) {
        // The map is populated but this block is not in it, so it belongs to a
        // template part, pattern or query loop rather than the post's own content.
        // Those cannot be edited through the content routes, so marking them would
        // only invite a target that can never resolve.
        return $block_content;
    } else {
        // No map (helpers unavailable, so nothing is stamped): fall back to the
        // render-order counter. Wrong on block themes, but it is all there is.
        if (!isset($GLOBALS['atarim_block_counters'][$block_name])) {
            $GLOBALS['atarim_block_counters'][$block_name] = 0;
        }
        $anchor_index = $GLOBALS['atarim_block_counters'][$block_name];
        $GLOBALS['atarim_block_counters'][$block_name]++;
    }

    if (class_exists('WP_HTML_Tag_Processor')) {
        $tags = new WP_HTML_Tag_Processor($block_content);
        if ($tags->next_tag()) {
            $tags->set_attribute('data-atarim-block-name', $block_name);
            $tags->set_attribute('data-atarim-anchor-index', (string) $anchor_index);
            if ($block_id !== '') {
                $tags->set_attribute('data-atarim-block-id', $block_id);
            }
            return $tags->get_updated_html();
        }
        return $block_content;
    }

    // Fallback for older WP versions
    $attrs = sprintf(
        ' data-atarim-block-name="%s" data-atarim-anchor-index="%d"',
        esc_attr($block_name),
        $anchor_index
    );
    if ($block_id !== '') {
        $attrs .= sprintf(' data-atarim-block-id="%s"', esc_attr($block_id));
    }

    return preg_replace(
        '/^(\s*<[a-zA-Z][a-zA-Z0-9]*)\b/',
        '$1' . $attrs,
        $block_content,
        1
    );
}

/* ===========================
 * Post-title identity marker (for editors only)
 * Adds a bare data-atarim-post-title attribute to the element that renders the
 * dynamic post title — the core/post-title block (Gutenberg) and the
 * theme-post-title widget (Elementor) — so the frontend can recognise the
 * title with certainty and route edits to the post_title save path. No value is
 * needed: the frontend already has the post ID via ATARIM_INLINE.postId
 * (get_queried_object_id()).
 * =========================== */
add_action('template_redirect', function () {

    if ( ! is_singular() ) return;
    if ( ! atarim_doit_markers_wanted() ) return;
    if ( ! get_queried_object_id() ) return;

    // Gutenberg: core/post-title block.
    add_filter('render_block_core/post-title', 'atarim_mark_post_title_block', 10, 3);

    // Elementor: theme-post-title widget.
    add_filter('elementor/widget/render_content', 'atarim_mark_post_title_elementor', 10, 2);
});

/**
 * Add a bare data-atarim-post-title attribute to the root tag of the rendered
 * core/post-title block. Skips title blocks that render a DIFFERENT post inside
 * a query loop (only the queried post's own title is marked).
 */
function atarim_mark_post_title_block($block_content, $block = [], $instance = null) {
    if (trim((string) $block_content) === '') return $block_content;

    if ($instance instanceof WP_Block && isset($instance->context['postId'])) {
        if ((int) $instance->context['postId'] !== (int) get_queried_object_id()) {
            return $block_content;
        }
    }

    return atarim_add_post_title_attr($block_content);
}

/**
 * Add a bare data-atarim-post-title attribute to the title tag inside Elementor's
 * theme-post-title widget. Skips loop items that render a different post.
 */
function atarim_mark_post_title_elementor($content, $widget) {
    if (! is_object($widget) || ! method_exists($widget, 'get_name')) return $content;
    if ($widget->get_name() !== 'theme-post-title') return $content;
    if (trim((string) $content) === '') return $content;

    // In an Elementor loop the global post is swapped per item; only mark the
    // queried post's own title.
    $current = get_the_ID();
    if ($current && (int) $current !== (int) get_queried_object_id()) {
        return $content;
    }

    return atarim_add_post_title_attr($content);
}

/**
 * Set a bare data-atarim-post-title attribute on the first tag of $html.
 */
function atarim_add_post_title_attr(string $html): string {
    if (class_exists('WP_HTML_Tag_Processor')) {
        $tags = new WP_HTML_Tag_Processor($html);
        if ($tags->next_tag()) {
            $tags->set_attribute('data-atarim-post-title', true); // boolean true => bare attribute
            return $tags->get_updated_html();
        }
        return $html;
    }

    // Fallback for older WP: inject a bare attribute into the first tag.
    return preg_replace('/^(\s*<[a-zA-Z][a-zA-Z0-9]*)\b/', '$1 data-atarim-post-title', $html, 1);
}

add_action('rest_api_init', function () {

    $permission_callback = function( WP_REST_Request $request ) {
        if ( ! atarim_doit_enabled() ) {
            return new WP_Error(
                'avc_doit_disabled',
                __( 'Do It via Atarim AI is disabled for this site. Enable it from the Atarim plugin settings to allow execution.', 'atarim-visual-collaboration' ),
                [ 'status' => 403 ]
            );
        }

        // Server-to-server: Atarim's backend runs Do It without a WordPress
        // session, authenticating with the shared secret from site connection.
        if ( atarim_doit_token_request() ) return true;

        $post_id = absint($request->get_param('postId'));
        if ($post_id) return current_user_can('edit_post', $post_id);
        return current_user_can('edit_posts');
    };

    register_rest_route('atarim/v1', '/content/get', [
        'methods'  => 'POST',
        'callback' => 'atarim_inline_get_handler',
        'permission_callback' => $permission_callback,
    ]);

    register_rest_route('atarim/v1', '/content/save', [
        'methods'  => 'POST',
        'callback' => 'atarim_inline_save_handler',
        'permission_callback' => $permission_callback,
    ]);

    // Media import: push external file URLs (e.g. task/comment attachments) into
    // the media library, unattached. Own permission — needs upload_files, not the
    // post-scoped edit_post the content routes use.
    $media_permission_callback = function ( WP_REST_Request $request ) {
        if ( ! atarim_doit_enabled() ) {
            return new WP_Error(
                'avc_doit_disabled',
                __( 'Do It via Atarim AI is disabled for this site. Enable it from the Atarim plugin settings to allow execution.', 'atarim-visual-collaboration' ),
                [ 'status' => 403 ]
            );
        }
        if ( atarim_doit_token_request() ) return true;
        return current_user_can( 'upload_files' );
    };

    register_rest_route('atarim/v1', '/media/import', [
        'methods'  => 'POST',
        'callback' => 'atarim_inline_media_import_handler',
        'permission_callback' => $media_permission_callback,
    ]);

    // Undo: restore the revision a Do It save created. Revision-based rather than
    // replaying the stored original, so it is exact and cannot resurrect content
    // that changed for unrelated reasons after the Do It landed.
    register_rest_route('atarim/v1', '/doit/undo', [
        'methods'  => 'POST',
        'callback' => 'atarim_doit_undo_handler',
        'permission_callback' => $permission_callback,
    ]);

    // ---------------------------------------------------------------------
    // Core connection probe — NOT a DoIt route. Public and intentionally
    // ungated: the Atarim app calls it cross-origin and unauthenticated,
    // before any connection/token exists, to decide "Connect" vs "Install".
    // It deliberately does NOT use $permission_callback (the avc_enable_doit
    // gate) above. Lives here only because this file already registers the
    // atarim/v1 namespace; move to its own home if more core routes appear.
    // ---------------------------------------------------------------------
    register_rest_route('atarim/v1', '/status', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $connected = get_option('avc_collab_active', 'no') === 'yes';
            return [
                'installed'    => true,
                'connected'    => $connected,
                'version'      => defined('AVCF_VERSION') ? AVCF_VERSION : null,
                'settings_url' => admin_url('options-general.php?page=atarim-visual-collaboration'),
                'site_url'     => site_url(),
            ];
        },
    ]);
});

/* ===========================
 * Builder + wrapper detection
 * =========================== */

/**
 * Which builder owns this post's content.
 *
 * Order matters. Every meta-stored builder is checked before the block and classic
 * fallbacks, because those builders often leave rendered HTML or shortcodes behind
 * in post_content — and the classic branch would then happily rewrite a copy that
 * the builder regenerates and overwrites, losing the edit silently.
 */
function atarim_detect_page_builder(int $post_id): string {

    foreach (atarim_doit_builders() as $slug => $builder) {
        if (($builder['detect'])($post_id)) return $slug;
    }

    $post = get_post($post_id);
    if ($post && isset($post->post_content) && has_blocks($post->post_content)) return 'block';

    if ($post && trim(wp_strip_all_tags($post->post_content)) !== '' && preg_match('/<[a-z][a-z0-9]*\b[^>]*>/i', $post->post_content)) {
        return 'classic';
    }

    return '';
}

/**
 * Meta-stored builders Do It can address, in detection order.
 *
 * Each entry declares where its content lives and how to find one element inside
 * it. `node` is whatever the builder puts in the rendered DOM to identify an
 * element — Beaver Builder's data-node, Elementor's data-id — so the frontend can
 * capture it and the backend can address it later with no browser.
 *
 * `durable` records whether that identifier survives the element being moved or
 * reordered. Where it is false, a Do It captured earlier can drift onto the wrong
 * element, exactly as Gutenberg's anchor_index did before block ids.
 *
 * @return array<string, array{meta_key:string, dom_attr:string, durable:bool, detect:callable}>
 */
function atarim_doit_builders(): array {
    return [
        'elementor' => [
            'meta_key' => '_elementor_data',
            'dom_attr' => 'data-id',
            'durable'  => true,
            'detect'   => function ($post_id) {
                $d = get_post_meta($post_id, '_elementor_data', true);
                return (is_string($d) && trim($d) !== '') || (is_array($d) && !empty($d));
            },
        ],
        'beaver' => [
            'meta_key' => '_fl_builder_data',
            'dom_attr' => 'data-node',
            'durable'  => true,
            'detect'   => function ($post_id) {
                if (empty(get_post_meta($post_id, '_fl_builder_enabled', true))) return false;
                $d = get_post_meta($post_id, '_fl_builder_data', true);
                return is_array($d) && !empty($d);
            },
        ],
        'siteorigin' => [
            'meta_key' => 'panels_data',
            'dom_attr' => 'id',
            'durable'  => false,
            'detect'   => function ($post_id) {
                $d = get_post_meta($post_id, 'panels_data', true);
                return is_array($d) && !empty($d['widgets']);
            },
        ],

        // Detected in order to REFUSE. Both compile their editor state down into
        // post_content, so that copy is derived, not source: an edit written there
        // survives until the next time someone opens the builder and saves, at
        // which point it is regenerated and the change vanishes with no error.
        // Without these entries both fall through to the classic branch — which
        // would happily edit the derived copy — so detecting them is what prevents
        // the silent loss.
        'vc' => [
            'meta_key' => 'vcv-pageContent',
            'dom_attr' => 'data-vcv-element',
            'durable'  => true,
            'derived'  => true,
            'detect'   => function ($post_id) {
                return get_post_meta($post_id, 'vcv-pageContent', true) !== '';
            },
        ],
        'brizy' => [
            'meta_key' => 'brizy',
            'dom_attr' => 'data-uid',
            'durable'  => true,
            'derived'  => true,
            'detect'   => function ($post_id) {
                return get_post_meta($post_id, 'brizy-post-hash', true) !== ''
                    || get_post_meta($post_id, 'brizy', true) !== '';
            },
        ],
    ];
}

/**
 * Builders whose post_content is a compiled artifact rather than the source.
 * Editing it appears to work and is then discarded on the builder's next save, so
 * Do It refuses rather than writing.
 */
function atarim_doit_builder_is_derived(string $page_builder): bool {
    $builders = atarim_doit_builders();

    return ! empty($builders[$page_builder]['derived']);
}

function atarim_detect_wrapper_selector_by_theme(): string {

    $theme = wp_get_theme();
    $template = strtolower((string) $theme->get_template());
    $stylesheet = strtolower((string) $theme->get_stylesheet());
    $slug = $template ?: $stylesheet;

    $map = [
        'hello-elementor' => '.page-content',
        'oceanwp'         => '.entry.clr',
        // Expand later...
    ];

    return $map[$slug] ?? '';
}


/**
 * Save one element of a meta-stored builder (Beaver Builder, SiteOrigin).
 *
 * Shares the Elementor contract: `content` is the replacement node as JSON, the
 * node id rides in `widgetId`, and the pre-write meta is snapshotted so undo has
 * something to restore — these builders create no WordPress revision.
 */
function atarim_doit_save_meta_builder(WP_REST_Request $request, int $post_id, string $page_builder) {

    $builders = atarim_doit_builders();
    $meta_key = $builders[$page_builder]['meta_key'] ?? '';
    $node_id  = sanitize_text_field((string) $request->get_param('widgetId'));
    $content  = $request->get_param('content');

    if ($node_id === '') {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing widgetId.'], 400);
    }
    if (!is_array($content) && !is_object($content)) {
        return new WP_REST_Response(['status'=>false,'message'=>'content must be a JSON object for this builder.'], 400);
    }

    $before = get_post_meta($post_id, $meta_key, true);
    $undo_token = atarim_doit_store_undo_snapshot($post_id, $page_builder, $before, $meta_key);

    $result = $page_builder === 'beaver'
        ? atarim_beaver_replace_node($post_id, $node_id, $content)
        : atarim_siteorigin_replace_widget($post_id, $node_id, (array) $content);

    if (!$result['ok']) {
        return new WP_REST_Response(['status'=>false,'message'=>$result['message']], 404);
    }

    atarim_doit_after_content_save($post_id, $page_builder);

    $stored = get_post_meta($post_id, $meta_key, true);
    $receipt = atarim_inline_save_receipt(
        wp_json_encode($stored),
        wp_json_encode($stored),
        wp_json_encode($before),
        null
    );
    $receipt['revisionId'] = $undo_token;
    $receipt['undoKind'] = 'snapshot';

    return new WP_REST_Response(array_merge(
        ['status'=>true, 'target'=>$page_builder, 'widgetId'=>$node_id],
        $receipt
    ), 200);
}

/* ===========================
 * Beaver Builder
 * =========================== */

/**
 * Beaver Builder keys _fl_builder_data by node id and renders that same id as
 * data-node, so an element captured from the DOM is addressable forever — no
 * stamping needed, and immune to the reordering that breaks positional schemes.
 *
 * @return object|null
 */
function atarim_beaver_find_node(int $post_id, string $node_id) {
    $data = get_post_meta($post_id, '_fl_builder_data', true);
    if (!is_array($data) || !isset($data[$node_id])) return null;
    return $data[$node_id];
}

/**
 * @return array{ok:bool, message:string}
 */
function atarim_beaver_replace_node(int $post_id, string $node_id, $node): array {
    $data = get_post_meta($post_id, '_fl_builder_data', true);
    if (!is_array($data) || !isset($data[$node_id])) {
        return ['ok' => false, 'message' => 'Node not found; nothing saved.'];
    }

    $existing = $data[$node_id];

    // Beaver Builder reads node properties as objects all the way down
    // ($node->settings->size), and a JSON body decodes to nested arrays. A
    // shallow (object) cast leaves settings as an array, which BB then cannot
    // read — so round-trip through JSON to convert every level.
    $incoming = json_decode(wp_json_encode($node));

    if (!is_object($incoming) || (string) ($incoming->node ?? '') !== $node_id) {
        return ['ok' => false, 'message' => 'content.node must match the requested node id.'];
    }

    // Type and parentage describe where the node sits in the tree; letting a
    // content edit change them would silently restructure the layout.
    $incoming->type = $existing->type ?? ($incoming->type ?? '');
    $incoming->parent = $existing->parent ?? ($incoming->parent ?? null);
    $incoming->position = $existing->position ?? ($incoming->position ?? 0);

    $data[$node_id] = $incoming;
    update_post_meta($post_id, '_fl_builder_data', $data);

    return ['ok' => true, 'message' => 'Saved.'];
}

/* ===========================
 * SiteOrigin Page Builder
 * =========================== */

/**
 * SiteOrigin has no per-widget identity: panels_data['widgets'] is a flat list and
 * the rendered id is panel-{post}-{grid}-{cell}-{i}, derived from position. The
 * only addressing available is that position, so a Do It captured before a
 * reorder can land on the wrong widget — the same failure block ids fixed for
 * Gutenberg. Callers get `durable => false` from atarim_doit_builders() and should
 * re-read before writing.
 *
 * @return array{index:int, widget:array}|null
 */
function atarim_siteorigin_find_widget(int $post_id, string $node_id): ?array {
    $data = get_post_meta($post_id, 'panels_data', true);
    if (!is_array($data) || empty($data['widgets'])) return null;

    // Accept both the raw list index and the rendered DOM id.
    if (preg_match('/^(?:panel-)?(?:\d+-)?(\d+)-(\d+)-(\d+)$/', $node_id, $m)) {
        [$grid, $cell, $within] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        foreach ($data['widgets'] as $i => $widget) {
            $info = $widget['panels_info'] ?? $widget['info'] ?? [];
            if ((int) ($info['grid'] ?? -1) === $grid
                && (int) ($info['cell'] ?? -1) === $cell
                && (int) ($info['id'] ?? -1) === $within) {
                return ['index' => $i, 'widget' => $widget];
            }
        }
        return null;
    }

    $index = (int) $node_id;
    if (!isset($data['widgets'][$index])) return null;

    return ['index' => $index, 'widget' => $data['widgets'][$index]];
}

/**
 * @return array{ok:bool, message:string}
 */
function atarim_siteorigin_replace_widget(int $post_id, string $node_id, $widget): array {
    $data = get_post_meta($post_id, 'panels_data', true);
    $found = atarim_siteorigin_find_widget($post_id, $node_id);

    if ($found === null || !is_array($data)) {
        return ['ok' => false, 'message' => 'Widget not found; nothing saved.'];
    }
    if (!is_array($widget)) {
        return ['ok' => false, 'message' => 'SiteOrigin content must be a JSON object.'];
    }

    // panels_info carries the grid/cell placement; preserve it so an edit cannot
    // move the widget into a different cell.
    $widget['panels_info'] = $found['widget']['panels_info'] ?? ($widget['panels_info'] ?? []);

    $data['widgets'][$found['index']] = $widget;
    update_post_meta($post_id, 'panels_data', $data);

    return ['ok' => true, 'message' => 'Saved.'];
}

/* ===========================
 * REST: UNDO
 * =========================== */

/**
 * Restore the revision a Do It save created, reverting the post to its state
 * immediately before that write.
 */
function atarim_doit_undo_handler(WP_REST_Request $request) {

    $post_id = absint($request->get_param('postId'));
    $revision_id = absint($request->get_param('revisionId'));

    if (!$post_id || !$revision_id) {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing postId or revisionId.'], 400);
    }

    // Meta-stored builders (Elementor and friends) have no WordPress revision, so
    // their undo restores the snapshot taken before the write instead.
    $snapshot = atarim_doit_restore_undo_snapshot($post_id, $revision_id);
    if ($snapshot['restored']) {
        return new WP_REST_Response([
            'status'     => true,
            'target'     => 'undo',
            'undoKind'   => 'snapshot',
            'postId'     => $post_id,
            'revisionId' => $revision_id,
            'changed'    => true,
        ], 200);
    }

    $revision = wp_get_post_revision($revision_id);
    if (!$revision) {
        return new WP_REST_Response(['status'=>false,'message'=>'Revision not found.'], 404);
    }
    if ((int) $revision->post_parent !== $post_id) {
        return new WP_REST_Response(['status'=>false,'message'=>'Revision does not belong to this post.'], 400);
    }

    $before = (string) get_post_field('post_content', $post_id);

    $restored = wp_restore_post_revision($revision_id);
    if ($restored === null || is_wp_error($restored)) {
        return new WP_REST_Response([
            'status'=>false,
            'message'=>'Failed to restore revision' . (is_wp_error($restored) ? ': ' . $restored->get_error_message() : '.'),
        ], 500);
    }

    clean_post_cache($post_id);

    $stored = (string) get_post_field('post_content', $post_id);

    return new WP_REST_Response([
        'status'      => true,
        'target'      => 'undo',
        'postId'      => $post_id,
        'revisionId'  => $revision_id,
        'changed'     => sha1($before) !== sha1($stored),
        'storedSha1'  => sha1($stored),
    ], 200);
}

/* ===========================
 * REST: GET
 * =========================== */

function atarim_inline_get_handler(WP_REST_Request $request) {

    $post_id = absint($request->get_param('postId'));
    $page_builder = sanitize_text_field((string) $request->get_param('pageBuilder'));

    if (!$post_id) {
        return new WP_REST_Response(['status' => false, 'message' => 'Missing postId.'], 400);
    }

    // Post-title target: independent of the page builder (the title is the
    // queried post's post_title, wherever/however the theme renders it).
    if ($request->get_param('target') === 'post_title') {
        $post = get_post($post_id);
        if (!$post) return new WP_REST_Response(['status'=>false,'message'=>'Post not found.'], 404);

        return new WP_REST_Response([
            'status' => true,
            'target' => 'post_title',
            'postId' => $post_id,
            'title'  => $post->post_title,
            'slug'   => $post->post_name,
        ], 200);
    }

    if (atarim_doit_builder_is_derived($page_builder)) {
        return new WP_REST_Response([
            'status' => false,
            'message' => sprintf(
                'This page uses %s, which regenerates the page from its own stored copy. An edit made here would be discarded the next time the page is saved in the builder, so Do It will not write to it.',
                $page_builder === 'vc' ? 'Visual Composer' : 'Brizy'
            ),
        ], 400);
    }

    if (!in_array($page_builder, ['elementor', 'beaver', 'siteorigin', 'block', 'classic'], true)) {
        return new WP_REST_Response([
            'status' => false,
            'message' => 'This page builder is not supported yet.',
        ], 400);
    }

    $post = get_post($post_id);
    if (!$post) return new WP_REST_Response(['status'=>false,'message'=>'Post not found.'], 404);

    if ($page_builder === 'beaver') {
        $node_id = sanitize_text_field((string) $request->get_param('widgetId'));
        if (!$node_id) return new WP_REST_Response(['status'=>false,'message'=>'Missing widgetId (Beaver Builder data-node).'], 400);

        $node = atarim_beaver_find_node($post_id, $node_id);
        if ($node === null) return new WP_REST_Response(['status'=>false,'message'=>'Node not found.'], 404);

        return new WP_REST_Response([
            'status' => true,
            'pageBuilder' => 'beaver',
            'postId' => $post_id,
            'widgetId' => $node_id,
            'durable' => true,
            'content' => $node,
        ], 200);
    }

    if ($page_builder === 'siteorigin') {
        $node_id = sanitize_text_field((string) $request->get_param('widgetId'));
        if ($node_id === '') return new WP_REST_Response(['status'=>false,'message'=>'Missing widgetId (SiteOrigin panel id or index).'], 400);

        $found = atarim_siteorigin_find_widget($post_id, $node_id);
        if ($found === null) return new WP_REST_Response(['status'=>false,'message'=>'Widget not found.'], 404);

        return new WP_REST_Response([
            'status' => true,
            'pageBuilder' => 'siteorigin',
            'postId' => $post_id,
            'widgetId' => $node_id,
            'durable' => false,
            'content' => $found['widget'],
        ], 200);
    }

    if ($page_builder === 'elementor') {
        $widget_id = sanitize_text_field((string) $request->get_param('widgetId'));
        if (!$widget_id) return new WP_REST_Response(['status'=>false,'message'=>'Missing widgetId.'], 400);

        $elementor_data = atarim_elementor_get_document_data_array($post_id);
        if (!is_array($elementor_data)) return new WP_REST_Response(['status'=>false,'message'=>'No valid _elementor_data found.'], 404);

        $widget = atarim_elementor_find_element_by_id($elementor_data, $widget_id);
        if (!is_array($widget)) return new WP_REST_Response(['status'=>false,'message'=>'Widget not found.'], 404);

        return new WP_REST_Response([
            'status' => true,
            'pageBuilder' => 'elementor',
            'postId' => $post_id,
            'widgetId' => $widget_id,
            'content' => $widget,
        ], 200);
    }

    if ($page_builder === 'block') {

        $block_id = sanitize_text_field((string) $request->get_param('blockId'));
        $block_name = sanitize_text_field((string) $request->get_param('blockName'));
        $anchor_index = (int) $request->get_param('anchorIndex');
        $snippet = (string) $request->get_param('snippet');

        if (trim($block_id) === '' && trim($block_name) === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing blockId or blockName.'], 400);
        }

        $match = atarim_find_gutenberg_block(
            $post->post_content,
            $block_id,
            $block_name,
            $anchor_index,
            $snippet
        );

        if (!$match) {
            return new WP_REST_Response([
                'status'=>false,
                'message'=>'Could not find matching Gutenberg block.',
            ], 404);
        }

        return new WP_REST_Response([
            'status'      => true,
            'pageBuilder' => 'block',
            'postId'      => $post_id,
            'blockId'     => $match['blockId'],
            'blockName'   => $block_name,
            'anchorIndex' => $anchor_index,
            'matchedBy'   => $match['matchedBy'],      // id | anchor_index | snippet
            'snippet'     => $snippet,
            'blockPath'   => $match['blockPath'],      // nested like "3.0.1"
            'content'     => $match['serializedBlock'], // raw serialized block string
        ], 200);
    }

    // Classic
    $path_string = (string) $request->get_param('path');
    $tag = strtolower((string) $request->get_param('tag'));
    $snippet = (string) $request->get_param('snippet');

    if (trim($path_string) === '' || trim($tag) === '' || trim($snippet) === '') {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing path, tag, or snippet.'], 400);
    }

    $steps = atarim_parse_compact_path($path_string);
    $found = atarim_classic_find_node_outer_html($post->post_content, $steps, $tag, $snippet);

    if (!$found) {
        return new WP_REST_Response(['status'=>false,'message'=>'Could not find matching HTML element in classic content.'], 404);
    }

    return new WP_REST_Response([
        'status' => true,
        'pageBuilder' => 'classic',
        'postId' => $post_id,
        'path' => $path_string,
        'tag' => $tag,
        'snippet' => $snippet,
        'content' => $found,
    ], 200);
}

/* ===========================
 * REST: SAVE
 * =========================== */

/**
 * Build a write-receipt for an inline save, measured from the RE-READ stored
 * state (never echoed back), so a caller can confirm a write actually took
 * effect without trusting a bare success and without re-fetching the whole
 * document. Addresses the "success but nothing changed" class: verified compares
 * what we intended to store against what is actually stored now, byte-for-byte —
 * so a silent transform/kses/no-op shows up as verified:false.
 *
 * @param string      $intended    The exact string we tried to store.
 * @param string      $stored      The string actually stored now (re-read).
 * @param string|null $before      The stored string before the write (for changed).
 * @param int|null    $revision_id Latest revision id, if the write created one.
 * @return array
 */
/**
 * Revision id holding the post's CURRENT content, captured before a Do It write
 * so undo has something to restore.
 *
 * wp_update_post creates its revision from the post as it now is — i.e. the NEW
 * content — so the revision that exists after a save is the wrong end of the
 * edit. Snapshotting first is what makes the returned id restorable. When
 * wp_save_post_revision dedupes (the latest revision already matches current
 * content) that existing revision is itself the pre-write state, so fall back to it.
 */
/**
 * Meta slot holding pre-write snapshots for builders whose content lives in post
 * meta, where WordPress creates no revision to restore.
 */
const ATARIM_DOIT_UNDO_META = '_avc_doit_undo';

const ATARIM_DOIT_UNDO_KEEP = 10;

/**
 * Store the pre-write state of a meta-stored builder and return a token that
 * atarim_doit_undo_handler can restore.
 *
 * Block and classic saves get a real WordPress revision; Elementor and the other
 * meta-stored builders get nothing, so before this there was no undo for them at
 * all. Tokens are integers so callers can store them in the same column as a
 * revision id.
 *
 * @return int
 */
function atarim_doit_store_undo_snapshot($post_id, $type, $data, $meta_key = null) {

    $snapshots = get_post_meta($post_id, ATARIM_DOIT_UNDO_META, true);
    if (!is_array($snapshots)) { $snapshots = []; }

    $token = empty($snapshots) ? 1 : (max(array_keys($snapshots)) + 1);

    $snapshots[$token] = [
        'type' => (string) $type,
        'meta_key' => $meta_key,
        'data' => $data,
        'time' => time(),
    ];

    if (count($snapshots) > ATARIM_DOIT_UNDO_KEEP) {
        $snapshots = array_slice($snapshots, -ATARIM_DOIT_UNDO_KEEP, null, true);
    }

    update_post_meta($post_id, ATARIM_DOIT_UNDO_META, $snapshots);

    return (int) $token;
}

/**
 * Restore a snapshot stored by atarim_doit_store_undo_snapshot.
 *
 * @return array{restored:bool, message:string}
 */
function atarim_doit_restore_undo_snapshot($post_id, $token) {

    $snapshots = get_post_meta($post_id, ATARIM_DOIT_UNDO_META, true);
    if (!is_array($snapshots) || !isset($snapshots[$token])) {
        return ['restored' => false, 'message' => 'No snapshot with that id for this post.'];
    }

    $snapshot = $snapshots[$token];
    $meta_key = $snapshot['meta_key'] ?? null;

    if (!is_string($meta_key) || $meta_key === '') {
        return ['restored' => false, 'message' => 'Snapshot is missing its target meta key.'];
    }

    update_post_meta($post_id, $meta_key, wp_slash($snapshot['data']));
    atarim_doit_after_content_save($post_id, (string) ($snapshot['type'] ?? ''));

    unset($snapshots[$token]);
    update_post_meta($post_id, ATARIM_DOIT_UNDO_META, $snapshots);

    return ['restored' => true, 'message' => 'Snapshot restored.'];
}

/**
 * Invalidate what a page builder cached for this post after Do It rewrites it.
 *
 * Only the Elementor branch used to do this, so a Divi or WPBakery page — both of
 * which are detected as classic, because their shortcodes live in post_content —
 * would keep serving its previously rendered output after a successful write.
 */
function atarim_doit_after_content_save($post_id, $page_builder = '') {

    clean_post_cache($post_id);

    // Elementor caches rendered element output, page assets and generated CSS per
    // post. This lives here rather than in the save branch so that undo — which
    // rewrites the same meta — invalidates them too; without it a restore updates
    // the data but keeps serving the superseded render.
    delete_post_meta($post_id, '_elementor_element_cache');
    delete_post_meta($post_id, '_elementor_page_assets');
    if ( class_exists('\Elementor\Core\Files\CSS\Post') ) {
        try { ( new \Elementor\Core\Files\CSS\Post($post_id) )->delete(); } catch (Throwable $e) {}
    }

    // Divi keeps generated static CSS/JS per post.
    if (class_exists('\ET_Core_PageResource') && method_exists('\ET_Core_PageResource', 'remove_static_resources')) {
        try { \ET_Core_PageResource::remove_static_resources($post_id, 'all'); } catch (Throwable $e) {}
    }

    // WPBakery regenerates its per-post custom CSS from the shortcodes.
    if (function_exists('vc_modules_manager')) {
        delete_post_meta($post_id, '_wpb_shortcodes_custom_css');
    }

    // Full-page caches, where the plugin exposes a per-URL purge.
    $url = get_permalink($post_id);
    if ($url) {
        if (function_exists('rocket_clean_files')) { rocket_clean_files($url); }
        if (function_exists('w3tc_flush_url')) { w3tc_flush_url($url); }
        if (function_exists('wpsc_delete_url_cache')) { wpsc_delete_url_cache($url); }
    }

    do_action('atarim_doit_content_saved', $post_id, $page_builder);
}

function atarim_doit_snapshot_revision($post_id) {

    $revision_id = wp_save_post_revision($post_id);
    if ($revision_id) return (int) $revision_id;

    $revs = wp_get_post_revisions($post_id, ['numberposts' => 1, 'fields' => 'ids']);
    return $revs ? (int) reset($revs) : null;
}

function atarim_inline_save_receipt( $intended, $stored, $before = null, $revision_id = null ) {
    $intended = is_string( $intended ) ? $intended : (string) wp_json_encode( $intended );
    $stored   = is_string( $stored )   ? $stored   : (string) wp_json_encode( $stored );

    $receipt = [
        'verified'    => ( sha1( $intended ) === sha1( $stored ) ),
        'storedBytes' => strlen( $stored ),
        'storedSha1'  => sha1( $stored ),
    ];

    if ( $before !== null ) {
        $before = is_string( $before ) ? $before : (string) wp_json_encode( $before );
        $receipt['changed'] = ( sha1( $before ) !== sha1( $stored ) );
    }
    if ( $revision_id !== null ) {
        $receipt['revisionId'] = (int) $revision_id;
    }

    return $receipt;
}

function atarim_inline_save_handler(WP_REST_Request $request) {

    $post_id = absint($request->get_param('postId'));
    $page_builder = sanitize_text_field((string) $request->get_param('pageBuilder'));

    if (!$post_id) {
        return new WP_REST_Response(['status' => false, 'message' => 'Missing postId.'], 400);
    }

    // Post-title target: update post_title and (optionally) the slug, independent
    // of the page builder.
    if ($request->get_param('target') === 'post_title') {
        $post = get_post($post_id);
        if (!$post) return new WP_REST_Response(['status'=>false,'message'=>'Post not found.'], 404);

        $new_title = trim((string) $request->get_param('title'));
        if ($new_title === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing title to save.'], 400);
        }

        $old_title = $post->post_title;
        $old_slug  = $post->post_name;

        $update = [
            'ID'         => $post_id,
            'post_title' => $new_title, // wp_update_post sanitises
        ];

        // Slug is optional: only touched when updateSlug is truthy. When on with
        // no explicit slug, regenerate a unique slug from the new title.
        $update_slug = filter_var($request->get_param('updateSlug'), FILTER_VALIDATE_BOOLEAN);
        $new_slug    = $old_slug;
        if ($update_slug) {
            $explicit = sanitize_title((string) $request->get_param('slug'));
            $desired  = $explicit !== '' ? $explicit : sanitize_title($new_title);
            if ($desired === '') { $desired = $old_slug; }
            $new_slug = wp_unique_post_slug($desired, $post_id, $post->post_status, $post->post_type, $post->post_parent);
            $update['post_name'] = $new_slug;
        }

        $result = wp_update_post(wp_slash($update), true);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['status'=>false,'message'=>'Failed to update title: ' . $result->get_error_message()], 500);
        }

        $saved      = get_post($post_id);
        $final_slug = $saved ? $saved->post_name : $new_slug;

        return new WP_REST_Response([
            'status'      => true,
            'target'      => 'post_title',
            'postId'      => $post_id,
            'oldTitle'    => $old_title,
            'title'       => $saved ? $saved->post_title : $new_title,
            'slugUpdated' => ($final_slug !== $old_slug),
            'oldSlug'     => $old_slug,
            'slug'        => $final_slug,
        ], 200);
    }

    if (atarim_doit_builder_is_derived($page_builder)) {
        return new WP_REST_Response([
            'status' => false,
            'message' => sprintf(
                'This page uses %s, which regenerates the page from its own stored copy. An edit made here would be discarded the next time the page is saved in the builder, so Do It will not write to it.',
                $page_builder === 'vc' ? 'Visual Composer' : 'Brizy'
            ),
        ], 400);
    }

    if (!in_array($page_builder, ['elementor', 'beaver', 'siteorigin', 'block', 'classic'], true)) {
        return new WP_REST_Response([
            'status' => false,
            'message' => 'This page builder is not supported yet.',
        ], 400);
    }

    $post = get_post($post_id);
    if (!$post) return new WP_REST_Response(['status'=>false,'message'=>'Post not found.'], 404);

    if ($page_builder === 'beaver' || $page_builder === 'siteorigin') {
        return atarim_doit_save_meta_builder($request, $post_id, $page_builder);
    }

    if ($page_builder === 'elementor') {
        $widget_id = sanitize_text_field((string) $request->get_param('widgetId'));
        $widget = $request->get_param('content');

        if (!$widget_id) return new WP_REST_Response(['status'=>false,'message'=>'Missing widgetId.'], 400);
        if (!is_array($widget)) return new WP_REST_Response(['status'=>false,'message'=>'Elementor content must be a JSON object.'], 400);

        if (empty($widget['id']) || (string)$widget['id'] !== (string)$widget_id) {
            return new WP_REST_Response(['status'=>false,'message'=>'content.id must match widgetId.'], 400);
        }

        $elementor_data = atarim_elementor_get_document_data_array($post_id);
        if (!is_array($elementor_data)) return new WP_REST_Response(['status'=>false,'message'=>'No valid _elementor_data found.'], 404);

        $replaced = false;
        $updated_data = atarim_elementor_replace_element_by_id($elementor_data, $widget_id, $widget, $replaced);
        if (!$replaced) return new WP_REST_Response(['status'=>false,'message'=>'Widget not found; nothing saved.'], 404);

        $intended_json = wp_json_encode($updated_data);

        // Elementor writes post meta, so WordPress creates no revision. Snapshot
        // the previous data ourselves or this edit would have no undo at all.
        $before_json = get_post_meta($post_id, '_elementor_data', true);
        if ( ! is_string($before_json) ) { $before_json = (string) wp_json_encode($before_json); }
        $undo_token = atarim_doit_store_undo_snapshot($post_id, 'elementor', $before_json, '_elementor_data');

        update_post_meta($post_id, '_elementor_data', wp_slash($intended_json));

        atarim_doit_after_content_save($post_id, 'elementor');

        $stored_json = get_post_meta($post_id, '_elementor_data', true);
        if ( ! is_string($stored_json) ) { $stored_json = (string) wp_json_encode($stored_json); }
        $receipt = atarim_inline_save_receipt( $intended_json, $stored_json, wp_json_encode($elementor_data) );
        $receipt['revisionId'] = $undo_token;
        $receipt['undoKind'] = 'snapshot';

        return new WP_REST_Response(array_merge(['status'=>true, 'target'=>'elementor', 'widgetId'=>$widget_id], $receipt), 200);
    }

    $content = (string) $request->get_param('content');
    $element_html = (string) $request->get_param('elementHtml');

    if (trim($content) === '' && trim($element_html) === '') {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing content to save.'], 400);
    }

    if ($page_builder === 'block') {
        $block_path = (string) $request->get_param('blockPath');
        $expected_block_name = sanitize_text_field((string) $request->get_param('blockName'));
        $block_id = sanitize_text_field((string) $request->get_param('blockId'));

        // blockId resolves to a path HERE, inside the write request, rather than
        // being carried over from an earlier GET. That closes the read-to-write
        // window in which another save could shift every index.
        if (trim($block_id) !== '') {
            $resolved = atarim_find_gutenberg_block($post->post_content, $block_id, $expected_block_name, -1, '');
            if ($resolved === null) {
                return new WP_REST_Response([
                    'status'=>false,
                    'message'=>'No block with that blockId; it may have been deleted.',
                ], 404);
            }
            $block_path = $resolved['blockPath'];
            $parsed_existing = parse_blocks($resolved['serializedBlock']);
            if (trim($expected_block_name) === '' && isset($parsed_existing[0]['blockName'])) {
                $expected_block_name = (string) $parsed_existing[0]['blockName'];
            }
        }

        if (trim($block_path) === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing blockPath or blockId.'], 400);
        }
        if (trim($expected_block_name) === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing blockName.'], 400);
        }

        // elementHtml carries just the edited element; splice it into the canonical
        // block here so the caller does not have to reimplement block-aware
        // splicing outside WordPress.
        if (trim($content) === '') {
            $spliced = atarim_splice_element_into_block($post->post_content, $block_path, $element_html);
            if ($spliced === null) {
                return new WP_REST_Response([
                    'status'=>false,
                    'message'=>'Cannot splice elementHtml into this block (it has inner blocks). Send full block markup as content instead.',
                ], 400);
            }
            $content = $spliced;
        }

        // Validate: new content parses as a single block of the expected type
        $parsed_new = parse_blocks($content);
        if (!is_array($parsed_new) || empty($parsed_new) || !is_array($parsed_new[0])) {
            return new WP_REST_Response([
                'status'=>false,
                'message'=>'Content is not a valid block.',
            ], 400);
        }
        if (($parsed_new[0]['blockName'] ?? '') !== $expected_block_name) {
            return new WP_REST_Response([
                'status'=>false,
                'message'=>'Content block type does not match expected blockName.',
            ], 400);
        }

        // Validate: existing block at blockPath is the expected type (stale-edit guard)
        $existing_blocks = parse_blocks($post->post_content);
        $path_parts = array_values(array_filter(
            explode('.', trim($block_path)),
            static function($v) { return $v !== ''; }
        ));
        $existing_block = atarim_get_block_ref_by_path($existing_blocks, $path_parts);
        if (!is_array($existing_block) || ($existing_block['blockName'] ?? '') !== $expected_block_name) {
            return new WP_REST_Response([
                'status'=>false,
                'message'=>'Block at path no longer matches expected type. The post may have been edited elsewhere; please refresh.',
            ], 409);
        }

        $updated = atarim_replace_gutenberg_block_by_nested_path($post->post_content, $block_path, $content);
        if ($updated === null) {
            return new WP_REST_Response([
                'status'=>false,
                'message'=>'Could not replace Gutenberg block (path not found or invalid replacement).',
            ], 404);
        }

        $undo_revision = atarim_doit_snapshot_revision($post_id);

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $updated,
        ]);

        atarim_doit_after_content_save($post_id, 'block');

        $stored_content = (string) get_post_field('post_content', $post_id);
        $receipt = atarim_inline_save_receipt( $updated, $stored_content, $post->post_content, $undo_revision );
        $receipt['undoKind'] = 'revision';

        return new WP_REST_Response(array_merge(['status'=>true, 'target'=>'block'], $receipt), 200);
    }

    // Classic save
    $path_string = (string) $request->get_param('path');
    $tag = strtolower((string) $request->get_param('tag'));
    $snippet = (string) $request->get_param('snippet');

    if (trim($path_string) === '' || trim($tag) === '' || trim($snippet) === '') {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing path/tag/snippet for classic save.'], 400);
    }

    $steps = atarim_parse_compact_path($path_string);

    $new_post_content = atarim_classic_replace_node_outer_html($post->post_content, $steps, $tag, $snippet, $content);
    if ($new_post_content === null) {
        return new WP_REST_Response(['status'=>false,'message'=>'Could not find matching element to replace in classic content.'], 404);
    }

    $undo_revision = atarim_doit_snapshot_revision($post_id);

    wp_update_post([
        'ID' => $post_id,
        'post_content' => $new_post_content,
    ]);

    atarim_doit_after_content_save($post_id, 'classic');

    $stored_content = (string) get_post_field('post_content', $post_id);
    $receipt = atarim_inline_save_receipt( $new_post_content, $stored_content, $post->post_content, $undo_revision );
    $receipt['undoKind'] = 'revision';

    return new WP_REST_Response(array_merge(['status'=>true, 'target'=>'classic'], $receipt), 200);
}

/* ===========================
 * Shared helpers: compact path (classic only)
 * =========================== */

function atarim_parse_compact_path(string $path): array {
    $path = trim($path);
    if ($path === '') return [];

    $parts = array_map('trim', explode('>', $path));
    $steps = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;

        if (preg_match('/^([a-z0-9]+)(?:\((\d+)\))?$/i', $part, $m)) {
            $steps[] = [
                'tag'   => strtoupper($m[1]),
                'index' => isset($m[2]) ? (int) $m[2] : 0,
            ];
        }
    }

    return $steps;
}

/* ===========================
 * Elementor helpers
 * =========================== */

function atarim_elementor_get_document_data_array(int $post_id): ?array {
    $raw = get_post_meta($post_id, '_elementor_data', true);
    if (empty($raw)) return null;

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    return is_array($raw) ? $raw : null;
}

function atarim_elementor_find_element_by_id(array $nodes, string $target_id): ?array {
    foreach ($nodes as $node) {
        if (!is_array($node)) continue;

        if (isset($node['id']) && (string)$node['id'] === (string)$target_id) {
            return $node;
        }

        if (isset($node['elements']) && is_array($node['elements'])) {
            $found = atarim_elementor_find_element_by_id($node['elements'], $target_id);
            if ($found !== null) return $found;
        }
    }
    return null;
}

function atarim_elementor_replace_element_by_id(array $nodes, string $target_id, array $replacement_node, bool &$replaced): array {
    foreach ($nodes as $index => $node) {
        if (!is_array($node)) continue;

        if (isset($node['id']) && (string)$node['id'] === (string)$target_id) {
            $nodes[$index] = $replacement_node;
            $replaced = true;
            return $nodes;
        }

        if (isset($node['elements']) && is_array($node['elements'])) {
            $nodes[$index]['elements'] = atarim_elementor_replace_element_by_id(
                $node['elements'],
                $target_id,
                $replacement_node,
                $replaced
            );
            if ($replaced) return $nodes;
        }
    }
    return $nodes;
}

/* ===========================
 * Gutenberg helpers (blockName + anchorIndex + nested path replace)
 * =========================== */

function atarim_normalize_text(string $text): string {
    $text = wp_strip_all_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return strtolower(trim($text));
}

function atarim_collect_matching_blocks(array $blocks, string $block_name, array &$out, string $parent_path = ''): void {
    foreach ($blocks as $i => $block) {
        if (!is_array($block)) continue;

        $current_block_name = (string)($block['blockName'] ?? '');
        $path = ($parent_path === '') ? (string)$i : ($parent_path . '.' . $i);

        if ($current_block_name === $block_name) {
            $out[] = ['path' => $path, 'block' => $block];
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            atarim_collect_matching_blocks($block['innerBlocks'], $block_name, $out, $path);
        }
    }
}

/**
 * Resolve a Gutenberg block, preferring durable identity over position.
 *
 * 1. blockId (metadata.avcBlockId) — survives reordering, insertion, deletion and
 *    Gutenberg edits. The only mode that is correct on a block theme, where the
 *    render-order anchor_index counts header/footer template blocks that
 *    post_content does not contain.
 * 2. anchor_index + snippet — the legacy path, kept for unstamped blocks.
 * 3. snippet search — when the index misses, look for the one block of this name
 *    whose rendered text contains the snippet. Without this a page edit turns
 *    every stale task into a hard failure.
 *
 * @return array{blockPath:string,serializedBlock:string,blockId:string,matchedBy:string}|null
 */
function atarim_find_gutenberg_block(string $post_content, string $block_id, string $block_name, int $anchor_index, string $snippet): ?array {

    if (trim($block_id) !== '' && class_exists('AVCF_Gutenberg_Helpers')) {
        $blocks = AVCF_Gutenberg_Helpers::parse_raw($post_content);
        $hit = AVCF_Gutenberg_Helpers::raw_find_by_id($blocks, $block_id);
        if ($hit !== null) {
            return [
                // raw_find_by_id builds slash paths ("6/0"); the save path splits
                // on dots. Both index the same parse_blocks arrays.
                'blockPath'       => str_replace('/', '.', $hit['path']),
                'serializedBlock' => serialize_block($hit['block']),
                'blockId'         => $block_id,
                'matchedBy'       => 'id',
            ];
        }
    }

    if (trim($block_name) === '') return null;

    if ($anchor_index >= 0) {
        $match = atarim_find_gutenberg_block_by_anchor_index($post_content, $block_name, $anchor_index, $snippet);
        if ($match !== null) {
            $match['matchedBy'] = 'anchor_index';
            return $match;
        }
    }

    return atarim_find_gutenberg_block_by_snippet($post_content, $block_name, $snippet);
}

/**
 * Last-resort lookup: the single block of this name whose rendered text contains
 * the snippet. Ambiguous matches are refused — writing to the wrong block is far
 * worse than failing.
 *
 * @return array{blockPath:string,serializedBlock:string,blockId:string,matchedBy:string}|null
 */
function atarim_find_gutenberg_block_by_snippet(string $post_content, string $block_name, string $snippet): ?array {

    $snippet_norm = atarim_normalize_text($snippet);
    if ($snippet_norm === '') return null;

    $matches = [];
    atarim_collect_matching_blocks(parse_blocks($post_content), $block_name, $matches);

    $hits = [];
    foreach ($matches as $m) {
        try { $rendered = render_block($m['block']); } catch (Throwable $e) { $rendered = serialize_block($m['block']); }
        if (str_contains(atarim_normalize_text($rendered), $snippet_norm)) {
            $hits[] = $m;
        }
    }

    if (count($hits) !== 1) return null;

    $block = $hits[0]['block'];
    return [
        'blockPath'       => $hits[0]['path'],
        'serializedBlock' => serialize_block($block),
        'blockId'         => class_exists('AVCF_Gutenberg_Helpers')
            ? AVCF_Gutenberg_Helpers::extract_id($block['attrs'] ?? [])
            : '',
        'matchedBy'       => 'snippet',
    ];
}

function atarim_find_gutenberg_block_by_anchor_index(string $post_content, string $block_name, int $anchor_index, string $snippet): ?array {
    $blocks = parse_blocks($post_content);
    if (!is_array($blocks)) return null;

    $matches = [];
    atarim_collect_matching_blocks($blocks, $block_name, $matches);

    if (!isset($matches[$anchor_index])) return null;

    $picked = $matches[$anchor_index]['block'];
    $picked_path = $matches[$anchor_index]['path'];

    $snippet_norm = atarim_normalize_text($snippet);

    if ($snippet_norm !== '') {
        $rendered = '';
        try { $rendered = render_block($picked); } catch (Throwable $e) { $rendered = serialize_block($picked); }

        if (!str_contains(atarim_normalize_text($rendered), $snippet_norm)) {
            return null;
        }
    }

    return [
        'blockPath' => $picked_path,
        'serializedBlock' => serialize_block($picked),
        'blockId' => class_exists('AVCF_Gutenberg_Helpers')
            ? AVCF_Gutenberg_Helpers::extract_id($picked['attrs'] ?? [])
            : '',
    ];
}

function atarim_get_block_ref_by_path(array &$blocks, array $path_parts) {
    $ref = &$blocks;
    foreach ($path_parts as $part_index => $part) {
        $idx = (int)$part;
        if (!isset($ref[$idx]) || !is_array($ref[$idx])) return null;

        if ($part_index === count($path_parts) - 1) {
            return $ref[$idx];
        }

        if (!isset($ref[$idx]['innerBlocks']) || !is_array($ref[$idx]['innerBlocks'])) return null;
        $ref = &$ref[$idx]['innerBlocks'];
    }
    return null;
}

/**
 * Rebuild a leaf block's serialized markup with $element_html as its body,
 * preserving the block name and every attribute (so the comment JSON and the
 * inline HTML stay in sync).
 *
 * Leaf blocks only: a block with innerBlocks has structure that cannot be
 * inferred from a single element, and the caller must send full block markup.
 */
function atarim_splice_element_into_block(string $post_content, string $block_path, string $element_html): ?string {

    if (trim($element_html) === '') return null;

    $blocks = parse_blocks($post_content);
    $parts = array_values(array_filter(
        explode('.', trim($block_path)),
        static function($v) { return $v !== ''; }
    ));
    if (empty($parts)) return null;

    $block = atarim_get_block_ref_by_path($blocks, $parts);
    if (!is_array($block) || empty($block['blockName'])) return null;
    if (!empty($block['innerBlocks'])) return null;

    $block['innerHTML'] = $element_html;
    $block['innerContent'] = [$element_html];

    return serialize_block($block);
}

function atarim_replace_gutenberg_block_by_nested_path(string $post_content, string $block_path, string $new_serialized_block): ?string {

    $blocks = parse_blocks($post_content);
    if (!is_array($blocks)) return null;

    $replacement_blocks = parse_blocks($new_serialized_block);
    if (!is_array($replacement_blocks) || empty($replacement_blocks) || !is_array($replacement_blocks[0])) {
        return null;
    }
    $replacement_block = $replacement_blocks[0];

    $parts = array_filter(explode('.', trim($block_path)), static function($v) { return $v !== ''; });
    if (empty($parts)) return null;

    $ref = &$blocks;

    for ($i = 0; $i < count($parts) - 1; $i++) {
        $idx = (int)$parts[$i];
        if (!isset($ref[$idx]) || !is_array($ref[$idx])) return null;

        if (!isset($ref[$idx]['innerBlocks']) || !is_array($ref[$idx]['innerBlocks'])) {
            return null;
        }

        $ref = &$ref[$idx]['innerBlocks'];
    }

    $target_index = (int)$parts[count($parts) - 1];
    if (!isset($ref[$target_index]) || !is_array($ref[$target_index])) return null;

    $ref[$target_index] = $replacement_block;

    return serialize_blocks($blocks);
}

/* ===========================
 * Classic helpers (DOM path)
 * =========================== */

function atarim_dom_load_fragment(string $html, string $wrap_id): array {
    $dom = new DOMDocument();
    $encoded = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
        : $html;

    libxml_use_internal_errors(true);
    $dom->loadHTML('<div id="'.$wrap_id.'">'.$encoded.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $wrap = $dom->getElementById($wrap_id);
    return [$dom, $wrap];
}

function atarim_dom_inner_html(DOMDocument $dom, DOMElement $wrap): string {
    $out = '';
    foreach ($wrap->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function atarim_dom_outer_html(DOMDocument $dom, DOMNode $node): string {
    return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function atarim_dom_children_by_tag(DOMNode $node, string $tag_upper): array {
    $children = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE && strtoupper($child->nodeName) === $tag_upper) {
            $children[] = $child;
        }
    }
    return $children;
}

function atarim_classic_find_node_outer_html(string $post_content, array $steps, string $tag_lower, string $snippet): ?string {
    [$dom, $wrap] = atarim_dom_load_fragment($post_content, '__wrap__');
    if (!$wrap) return null;

    $current = $wrap;

    foreach ($steps as $step) {
        $children = atarim_dom_children_by_tag($current, $step['tag']);
        $index = (int) $step['index'];
        if (!isset($children[$index])) return null;
        $current = $children[$index];
    }

    if (strtolower($current->nodeName) !== strtolower($tag_lower)) return null;

    $node_text = atarim_normalize_text($current->textContent ?? '');
    $snippet_norm = atarim_normalize_text($snippet);
    if ($snippet_norm === '' || !str_contains($node_text, $snippet_norm)) return null;

    return atarim_dom_outer_html($dom, $current);
}

function atarim_classic_replace_node_outer_html(string $post_content, array $steps, string $tag_lower, string $snippet, string $replacement_html): ?string {
    [$dom, $wrap] = atarim_dom_load_fragment($post_content, '__wrap__');
    if (!$wrap) return null;

    $current = $wrap;

    foreach ($steps as $step) {
        $children = atarim_dom_children_by_tag($current, $step['tag']);
        $index = (int) $step['index'];
        if (!isset($children[$index])) return null;
        $current = $children[$index];
    }

    if (strtolower($current->nodeName) !== strtolower($tag_lower)) return null;

    $node_text = atarim_normalize_text($current->textContent ?? '');
    $snippet_norm = atarim_normalize_text($snippet);
    if ($snippet_norm === '' || !str_contains($node_text, $snippet_norm)) return null;

    [$tmp_dom, $tmp_wrap] = atarim_dom_load_fragment($replacement_html, '__frag__');
    if (!$tmp_wrap) return null;

    $parent = $current->parentNode;
    if (!$parent) return null;

    foreach (iterator_to_array($tmp_wrap->childNodes) as $child) {
        $parent->insertBefore($dom->importNode($child, true), $current);
    }

    $parent->removeChild($current);

    return atarim_dom_inner_html($dom, $wrap);
}

/* ===========================
 * REST: MEDIA IMPORT
 * Push external file URLs (e.g. task/comment attachments) into the media
 * library, unattached. Batch, with per-item error isolation so one bad file
 * does not fail the rest.
 * =========================== */
function atarim_inline_media_import_handler(WP_REST_Request $request) {

    $items = $request->get_param('items');

    // Be lenient: accept a bare url string, or a single { url } / { base64 } object.
    if ( is_string($items) ) {
        $items = [ [ 'url' => $items ] ];
    } elseif ( is_array($items) && ( isset($items['url']) || isset($items['base64']) ) ) {
        $items = [ $items ];
    }

    if ( ! is_array($items) || empty($items) ) {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing items: expected a non-empty array of { url | base64 }.'], 400);
    }

    if ( ! function_exists('media_handle_sideload') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $results = [];

    foreach ( $items as $item ) {
        if ( ! is_array($item) ) {
            $results[] = [ 'source' => '', 'success' => false, 'error' => 'Invalid item (expected an object with url or base64).' ];
            continue;
        }

        $has_url = isset($item['url'])    && trim( (string) $item['url'] )    !== '';
        $has_b64 = isset($item['base64']) && trim( (string) $item['base64'] ) !== '';

        if ( ! $has_url && ! $has_b64 ) {
            $results[] = [ 'source' => '', 'success' => false, 'error' => 'Each item needs a url or base64.' ];
            continue;
        }
        if ( $has_url && $has_b64 ) {
            $results[] = [ 'source' => '', 'success' => false, 'error' => 'Provide only one of url or base64 per item.' ];
            continue;
        }

        $source_ref = $has_url ? esc_url_raw( trim( (string) $item['url'] ) ) : '(base64)';
        $filename   = isset($item['filename']) ? sanitize_file_name( (string) $item['filename'] ) : '';

        // Resolve the file to a temp path from whichever source was supplied.
        if ( $has_url ) {
            $url = esc_url_raw( trim( (string) $item['url'] ) );

            // SSRF guard on the host.
            $ssrf = atarim_media_import_check_url_safety($url);
            if ( $ssrf !== null ) {
                $results[] = [ 'source' => $url, 'success' => false, 'error' => $ssrf ];
                continue;
            }

            // Fetch server-side, no redirects, with a size guard.
            $fetched = atarim_media_import_fetch($url);
            if ( ! empty($fetched['error']) ) {
                $results[] = [ 'source' => $url, 'success' => false, 'error' => $fetched['error'] ];
                continue;
            }
            $tmp = $fetched['tmp'];

            // Filename: explicit, else basename of the URL path.
            if ( $filename === '' ) {
                $path = wp_parse_url($url, PHP_URL_PATH);
                $filename = $path ? sanitize_file_name( basename($path) ) : '';
            }
        } else {
            // base64: a filename is required (we need an extension to validate type).
            if ( $filename === '' ) {
                $results[] = [ 'source' => $source_ref, 'success' => false, 'error' => 'filename is required for base64 items.' ];
                continue;
            }

            $decoded = atarim_media_import_decode_base64( (string) $item['base64'] );
            if ( ! empty($decoded['error']) ) {
                $results[] = [ 'source' => $source_ref, 'success' => false, 'error' => $decoded['error'] ];
                continue;
            }
            $tmp = $decoded['tmp'];
        }

        if ( $filename === '' ) {
            $filename = 'attachment';
        }

        // Validate the type against the site's allowed MIME types.
        $filetype = wp_check_filetype_and_ext($tmp, $filename);
        if ( empty($filetype['type']) ) {
            @unlink($tmp);
            $results[] = [ 'source' => $source_ref, 'success' => false, 'error' => 'File type is not allowed on this site.' ];
            continue;
        }
        if ( ! empty($filetype['proper_filename']) ) {
            $filename = $filetype['proper_filename'];
        }

        $file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];

        // Sideload into the library, unattached (post_id 0).
        $attachment_id = media_handle_sideload($file_array, 0);

        if ( is_wp_error($attachment_id) ) {
            @unlink($tmp); // media_handle_sideload usually cleans up, but be safe.
            $results[] = [ 'source' => $source_ref, 'success' => false, 'error' => 'Import failed: ' . $attachment_id->get_error_message() ];
            continue;
        }

        // Optional alt text / title.
        if ( ! empty($item['alt']) ) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $item['alt']));
        }
        if ( ! empty($item['title']) ) {
            wp_update_post([ 'ID' => $attachment_id, 'post_title' => sanitize_text_field((string) $item['title']) ]);
        }

        $results[] = [
            'source'       => $source_ref,
            'success'      => true,
            'attachmentId' => (int) $attachment_id,
            'mediaUrl'     => wp_get_attachment_url($attachment_id),
            'mimeType'     => get_post_mime_type($attachment_id),
            'filename'     => $filename,
        ];
    }

    return new WP_REST_Response([ 'status' => true, 'results' => $results ], 200);
}

/**
 * Fetch a URL to a temp file without following redirects, with a size guard.
 * Returns [ 'tmp' => path ] or [ 'error' => message ].
 */
function atarim_media_import_fetch(string $url) {
    if ( ! function_exists('wp_tempnam') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $resp = wp_remote_get($url, [ 'timeout' => 300, 'redirection' => 0 ]);
    if ( is_wp_error($resp) ) {
        return [ 'error' => 'Download failed: ' . $resp->get_error_message() ];
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    if ( $code < 200 || $code >= 300 ) {
        return [ 'error' => sprintf('Download rejected (HTTP %d).', $code) ];
    }

    $body = wp_remote_retrieve_body($resp);
    if ( $body === '' ) {
        return [ 'error' => 'Downloaded file is empty.' ];
    }

    $max = wp_max_upload_size();
    if ( $max > 0 && strlen($body) > $max ) {
        return [ 'error' => sprintf('File exceeds the maximum upload size (%s).', size_format($max)) ];
    }

    $tmp = wp_tempnam($url);
    if ( ! $tmp ) {
        return [ 'error' => 'Could not create a temporary file.' ];
    }
    if ( false === file_put_contents($tmp, $body) ) {
        @unlink($tmp);
        return [ 'error' => 'Could not write the downloaded file.' ];
    }

    return [ 'tmp' => $tmp ];
}

/**
 * Decode a base64 payload (optionally a data: URI) to a temp file, with a size
 * guard. Returns [ 'tmp' => path ] or [ 'error' => message ].
 */
function atarim_media_import_decode_base64( $b64 ) {
    if ( ! function_exists('wp_tempnam') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $b64 = (string) $b64;
    // Strip a data: URI prefix if present (e.g. "data:image/png;base64,....").
    if ( stripos( $b64, 'base64,' ) !== false ) {
        $b64 = substr( $b64, stripos( $b64, 'base64,' ) + 7 );
    }
    $b64 = trim( $b64 );

    $decoded = base64_decode( $b64, true );
    if ( $decoded === false ) {
        return [ 'error' => 'Invalid base64 data.' ];
    }
    if ( $decoded === '' ) {
        return [ 'error' => 'Decoded file is empty.' ];
    }

    $max = wp_max_upload_size();
    if ( $max > 0 && strlen( $decoded ) > $max ) {
        return [ 'error' => sprintf( 'File exceeds the maximum upload size (%s).', size_format( $max ) ) ];
    }

    $tmp = wp_tempnam();
    if ( ! $tmp ) {
        return [ 'error' => 'Could not create a temporary file.' ];
    }
    if ( false === file_put_contents( $tmp, $decoded ) ) {
        @unlink( $tmp );
        return [ 'error' => 'Could not write the decoded file.' ];
    }

    return [ 'tmp' => $tmp ];
}

/**
 * SSRF guard for outbound fetches. Mirrors the media cluster's check: blocks
 * non-http(s) schemes, localhost, and hosts resolving to private/loopback/
 * link-local ranges (incl. the 169.254.169.254 metadata IP). Returns null when
 * safe, or an error string.
 */
function atarim_media_import_check_url_safety($url) {
    $parsed = wp_parse_url($url);
    if ( ! is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host']) ) {
        return 'Invalid URL — could not parse scheme and host.';
    }

    $scheme = strtolower($parsed['scheme']);
    if ( $scheme !== 'http' && $scheme !== 'https' ) {
        return sprintf('URL scheme "%s" is not allowed — only http and https are supported.', $scheme);
    }

    $host = strtolower($parsed['host']);
    if ( in_array($host, [ 'localhost', 'localhost.localdomain' ], true) ) {
        return 'Hostname "localhost" is not allowed.';
    }

    $ips = @gethostbynamel($host);
    if ( ! is_array($ips) ) {
        if ( filter_var($host, FILTER_VALIDATE_IP) ) {
            $ips = [ $host ];
        } else {
            return sprintf('Could not resolve host "%s".', $host);
        }
    }

    foreach ( $ips as $ip ) {
        if ( ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ) {
            return sprintf('URL host resolves to a blocked address (%s — private, loopback, or link-local range).', $ip);
        }
        if ( $ip === '169.254.169.254' ) {
            return 'URL host resolves to a cloud metadata endpoint (169.254.169.254) — blocked.';
        }
    }

    return null;
}