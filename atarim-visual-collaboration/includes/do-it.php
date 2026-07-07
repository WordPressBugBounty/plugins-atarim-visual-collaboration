<?php

if ( ! defined('ABSPATH') ) exit;

add_action('wp_enqueue_scripts', function () {

    if ( ! is_singular() ) return;
    if ( ! is_user_logged_in() ) return;
    if ( ! current_user_can('edit_posts') ) return;

    $post_id = get_queried_object_id();
    if ( ! $post_id ) return;

    $page_builder = atarim_detect_page_builder($post_id);
    $wrapper_hint = atarim_detect_wrapper_selector_by_theme(); // '' if unknown

    $handle = 'atarim-do-it';
    wp_register_script($handle, '', [], '0.3.1', false);
    wp_enqueue_script($handle);

    $atarim_inline_data = [
        'postId'      => (int) $post_id,
        'pageBuilder' => $page_builder,
        'wrapperHint' => $wrapper_hint,
        'apiGet'      => esc_url_raw(rest_url('atarim/v1/content/get')),
        'apiSave'     => esc_url_raw(rest_url('atarim/v1/content/save')),
        'nonce'       => wp_create_nonce('wp_rest'),
    ];

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
 * Block identity injection (for editors only)
 * Marks every rendered block with data-atarim-block-name
 * and data-atarim-anchor-index so the frontend can identify
 * blocks reliably without DOM heuristics.
 * =========================== */
add_action('template_redirect', function () {

    if ( ! is_singular() ) return;
    if ( ! is_user_logged_in() ) return;
    if ( ! current_user_can('edit_posts') ) return;

    $post_id = get_queried_object_id();
    if ( ! $post_id ) return;

    if ( atarim_detect_page_builder($post_id) !== 'block' ) return;

    $GLOBALS['atarim_block_counters'] = [];

    add_filter('render_block', 'atarim_inject_block_identity', 10, 2);
});

function atarim_inject_block_identity(string $block_content, array $block): string {

    if (empty($block['blockName'])) return $block_content;
    if (trim($block_content) === '') return $block_content;

    $block_name = $block['blockName'];

    if (!isset($GLOBALS['atarim_block_counters'][$block_name])) {
        $GLOBALS['atarim_block_counters'][$block_name] = 0;
    }
    $anchor_index = $GLOBALS['atarim_block_counters'][$block_name];
    $GLOBALS['atarim_block_counters'][$block_name]++;

    if (class_exists('WP_HTML_Tag_Processor')) {
        $tags = new WP_HTML_Tag_Processor($block_content);
        if ($tags->next_tag()) {
            $tags->set_attribute('data-atarim-block-name', $block_name);
            $tags->set_attribute('data-atarim-anchor-index', (string) $anchor_index);
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

    return preg_replace(
        '/^(\s*<[a-zA-Z][a-zA-Z0-9]*)\b/',
        '$1' . $attrs,
        $block_content,
        1
    );
}

add_action('rest_api_init', function () {

    $permission_callback = function( WP_REST_Request $request ) {
        if ( empty( get_option( 'avc_enable_doit', false ) ) ) {
            return new WP_Error(
                'avc_doit_disabled',
                __( 'Do It via Atarim AI is disabled for this site. Enable it from the Atarim plugin settings to allow execution.', 'atarim-visual-collaboration' ),
                [ 'status' => 403 ]
            );
        }

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

function atarim_detect_page_builder(int $post_id): string {

    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if (is_string($elementor_data) && trim($elementor_data) !== '') return 'elementor';
    if (is_array($elementor_data) && !empty($elementor_data)) return 'elementor';

    $post = get_post($post_id);
    if ($post && isset($post->post_content) && has_blocks($post->post_content)) return 'block';

    if ($post && trim(wp_strip_all_tags($post->post_content)) !== '' && preg_match('/<[a-z][a-z0-9]*\b[^>]*>/i', $post->post_content)) {
        return 'classic';
    }

    return '';
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

/* ===========================
 * REST: GET
 * =========================== */

function atarim_inline_get_handler(WP_REST_Request $request) {

    $post_id = absint($request->get_param('postId'));
    $page_builder = sanitize_text_field((string) $request->get_param('pageBuilder'));

    if (!$post_id) {
        return new WP_REST_Response(['status' => false, 'message' => 'Missing postId.'], 400);
    }

    if (!in_array($page_builder, ['elementor', 'block', 'classic'], true)) {
        return new WP_REST_Response([
            'status' => false,
            'message' => 'This page builder is not supported yet.',
        ], 400);
    }

    $post = get_post($post_id);
    if (!$post) return new WP_REST_Response(['status'=>false,'message'=>'Post not found.'], 404);

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

        $block_name = sanitize_text_field((string) $request->get_param('blockName'));
        $anchor_index = (int) $request->get_param('anchorIndex');
        $snippet = (string) $request->get_param('snippet');

        if (trim($block_name) === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing blockName.'], 400);
        }
        if ($anchor_index < 0) {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing/invalid anchorIndex.'], 400);
        }

        $match = atarim_find_gutenberg_block_by_anchor_index(
            $post->post_content,
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
            'blockName'   => $block_name,
            'anchorIndex' => $anchor_index,
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

function atarim_inline_save_handler(WP_REST_Request $request) {

    $post_id = absint($request->get_param('postId'));
    $page_builder = sanitize_text_field((string) $request->get_param('pageBuilder'));

    if (!$post_id) {
        return new WP_REST_Response(['status' => false, 'message' => 'Missing postId.'], 400);
    }

    if (!in_array($page_builder, ['elementor', 'block', 'classic'], true)) {
        return new WP_REST_Response([
            'status' => false,
            'message' => 'This page builder is not supported yet.',
        ], 400);
    }

    $post = get_post($post_id);
    if (!$post) return new WP_REST_Response(['status'=>false,'message'=>'Post not found.'], 404);

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

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($updated_data)));

        delete_post_meta($post_id, '_elementor_element_cache');
        delete_post_meta($post_id, '_elementor_page_assets');

        if ( class_exists('\Elementor\Core\Files\CSS\Post') ) {
            try { ( new \Elementor\Core\Files\CSS\Post($post_id) )->delete(); } catch (Throwable $e) {}
        }

        clean_post_cache($post_id);

        return new WP_REST_Response(['status'=>true], 200);
    }

    $content = (string) $request->get_param('content');
    if (trim($content) === '') {
        return new WP_REST_Response(['status'=>false,'message'=>'Missing content to save.'], 400);
    }

    if ($page_builder === 'block') {
        $block_path = (string) $request->get_param('blockPath');
        $expected_block_name = sanitize_text_field((string) $request->get_param('blockName'));

        if (trim($block_path) === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing blockPath.'], 400);
        }
        if (trim($expected_block_name) === '') {
            return new WP_REST_Response(['status'=>false,'message'=>'Missing blockName.'], 400);
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

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $updated,
        ]);

        clean_post_cache($post_id);

        return new WP_REST_Response(['status'=>true], 200);
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

    wp_update_post([
        'ID' => $post_id,
        'post_content' => $new_post_content,
    ]);

    clean_post_cache($post_id);

    return new WP_REST_Response(['status'=>true], 200);
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