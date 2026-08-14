<?php
/**
 * Elementor — MCP abilities (orchestrator + core tree editing).
 *
 * Agent-driven editing of Elementor's _elementor_data element tree. This is a
 * separate surface from any human inline-editing layer: it reads the tree,
 * exposes widget/style control schemas, and mutates the tree (add / edit /
 * move / delete element, or replace the whole tree) through the shared
 * AVCF_Elementor_Helpers round-trip, which persists via Elementor's Document
 * API where possible and regenerates CSS.
 *
 * Scope: the stable, load-bearing tree operations. Advanced Pro surfaces
 * (atomic widgets, global classes, global styles v3, dynamic tags,
 * interactions, variables) are intentionally out of this first cluster.
 *
 * Exposed abilities (atarim/elementor-*):
 *   check-setup, get-content, get-widget-schema, get-style-schema,
 *   add-element, edit-element, move-element, delete-element, set-content
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Elementor extends AVCF_Abilities_Base {

    /** @var AVCF_Elementor_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Elementor_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_elementor_is_available() ) {
            return;
        }
        $this->register_check_setup();
        $this->register_get_content();
        $this->register_schemas();
        $this->register_add_element();
        $this->register_edit_element();
        $this->register_move_element();
        $this->register_delete_element();
        $this->register_set_content();
        $this->register_clear_cache();
        $this->register_import_template();
    }

    /* ----------------------------- helpers ----------------------------- */

    private function can_edit( $post_id ) {
        return current_user_can( 'edit_post', (int) $post_id ) || current_user_can( 'edit_posts' );
    }
    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }

    /** Validate a post is Elementor-built; returns error array or null. */
    private function require_elementor_post( $post_id ) {
        if ( $post_id <= 0 || ! get_post( $post_id ) ) {
            return [ 'success' => false, 'message' => 'A valid post_id is required.' ];
        }
        if ( ! AVCF_Elementor_Helpers::is_elementor_post( $post_id ) ) {
            return [ 'success' => false, 'message' => sprintf( 'Post %d is not built with Elementor (no builder data). Open it in Elementor once, or use set-content to initialise it.', $post_id ) ];
        }
        return null;
    }

    /** Shape an Elementor control for schema output. */
    private function shape_control( $name, $control ) {
        $c = is_array( $control ) ? $control : [];
        $out = [
            'name'  => (string) $name,
            'type'  => isset( $c['type'] ) ? (string) $c['type'] : '',
            'label' => isset( $c['label'] ) ? (string) $c['label'] : '',
            'tab'   => isset( $c['tab'] ) ? (string) $c['tab'] : '',
        ];
        if ( isset( $c['default'] ) )    { $out['default'] = $c['default']; }
        if ( isset( $c['options'] ) )    { $out['options'] = $c['options']; }
        if ( isset( $c['description'] ) ){ $out['description'] = (string) $c['description']; }
        return $out;
    }

    /* --------------------------- check-setup --------------------------- */

    private function register_check_setup() {
        $detector = $this->detector;
        wp_register_ability( 'atarim/elementor-check-setup', [
            'label'        => 'Check Elementor Setup',
            'description'  => 'Call first before other Elementor abilities. Reports whether Elementor is active, its version, and whether Elementor Pro is present.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ], 'version' => [ 'type' => 'string' ], 'pro' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'active', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $detector ) {
                return [ 'success' => true, 'active' => $detector->avcf_elementor_is_available(), 'version' => $detector->avcf_elementor_version(), 'pro' => $detector->avcf_elementor_is_pro(), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- clear-cache --------------------------- */

    private function register_clear_cache() {
        wp_register_ability( 'atarim/elementor-clear-cache', [
            'label'        => 'Clear Elementor Cache',
            'description'  => 'Clear Elementor\'s cached render (element cache + page assets) and generated CSS for a post, and by default regenerate that post\'s CSS immediately so the front end reflects recent edits. Omit post_id to clear ALL Elementor cache site-wide (regenerates lazily on next view). Use this after writing Elementor data through a path that does not auto-clear (e.g. a raw _elementor_data meta write); Elementor caches rendered output separately, so without this the old HTML/CSS keeps being served and a successful edit looks like it did nothing. When regenerating per-post, it also returns the produced CSS size, status and hash (and the CSS itself if return_css is set) so you can validate what was generated — e.g. detect CSS that came out empty or truncated.',
            'category'     => 'atarim',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post to clear + regenerate. Omit to clear all Elementor cache site-wide.' ],
                    'regenerate' => [ 'type' => 'boolean', 'default' => true, 'description' => 'Rebuild the post CSS immediately (per-post only). Default true.' ],
                    'return_css' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Also return the regenerated CSS content (per-post only) so you can inspect/validate it — e.g. confirm styles for later sections are present, not truncated. Off by default to keep the response small; the size/status/hash are always returned regardless.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'scope'       => [ 'type' => 'string' ],
                    'post_id'     => [ 'type' => 'integer' ],
                    'regenerated' => [ 'type' => 'boolean' ],
                    'css_bytes'   => [ 'type' => 'integer' ],
                    'css_sha1'    => [ 'type' => 'string' ],
                    'css_status'  => [ 'type' => 'string' ],
                    'css_empty'   => [ 'type' => 'boolean' ],
                    'css'         => [ 'type' => 'string' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback' => function( $input = [] ) {
                $post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                $regenerate = ! array_key_exists( 'regenerate', $input ) || filter_var( $input['regenerate'], FILTER_VALIDATE_BOOLEAN );

                if ( $post_id > 0 ) {
                    if ( ! get_post( $post_id ) ) {
                        return [ 'success' => false, 'scope' => 'post', 'message' => sprintf( 'Post %d not found.', $post_id ) ];
                    }
                    AVCF_Elementor_Helpers::clear_css_cache( $post_id );
                    clean_post_cache( $post_id );

                    $return_css = ! empty( $input['return_css'] ) && filter_var( $input['return_css'], FILTER_VALIDATE_BOOLEAN );

                    $out = [ 'success' => true, 'scope' => 'post', 'post_id' => $post_id ];
                    if ( ! $regenerate ) {
                        $out['regenerated'] = false;
                        $out['message']     = sprintf( 'Cleared Elementor cache for post %d (CSS not regenerated; it will rebuild on next view).', $post_id );
                        return $out;
                    }

                    $report = AVCF_Elementor_Helpers::regenerate_post_css( $post_id );
                    $out['regenerated'] = ! empty( $report['regenerated'] );
                    if ( empty( $report['regenerated'] ) ) {
                        $out['message'] = sprintf( 'Cleared Elementor cache for post %d, but CSS regeneration failed%s.', $post_id, isset( $report['reason'] ) ? ': ' . $report['reason'] : '' );
                        return $out;
                    }

                    // Report what was produced so the caller can validate it
                    // (detect empty/truncated CSS, e.g. styles dropping past a
                    // section and falling back to kit defaults).
                    $out['css_bytes']  = (int) $report['css_bytes'];
                    $out['css_sha1']   = (string) $report['css_sha1'];
                    $out['css_status'] = (string) $report['css_status'];
                    $out['css_empty']  = (bool) $report['css_empty'];
                    if ( $return_css ) {
                        $out['css'] = (string) $report['content'];
                    }
                    $msg = sprintf( 'Cleared Elementor cache for post %d and regenerated its CSS (%d bytes, status: %s).', $post_id, (int) $report['css_bytes'], (string) $report['css_status'] );
                    if ( ! empty( $report['css_empty'] ) ) {
                        $msg .= ' WARNING: the regenerated CSS is empty. If this post has styled elements, this indicates a generation problem rather than a cache issue (e.g. a host file-write/size limit) — verify on another host or via WP-CLI.';
                    }
                    $out['message'] = $msg;
                    return $out;
                }

                $ok = AVCF_Elementor_Helpers::purge_all_css();
                return [
                    'success'     => $ok,
                    'scope'       => 'site',
                    'regenerated' => false,
                    'message'     => $ok
                        ? 'Cleared all Elementor cache site-wide. CSS regenerates on next view.'
                        : 'Could not access the Elementor files manager to clear site-wide cache.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- import-template --------------------------- */

    private function register_import_template() {
        wp_register_ability( 'atarim/elementor-import-template', [
            'label'        => 'Import Elementor Template',
            'description'  => 'Import an Elementor template into the site template library from its EXPORT JSON (the format produced by Elementor\'s Template > Export, i.e. an object with content/type/title/version — NOT a raw _elementor_data element array). Provide the JSON in content (a string), content_base64 (base64 of the file bytes — use this for a handed-over file, and the only way to pass a binary .zip of multiple templates), or a url to fetch it from. Uses Elementor\'s own importer, so version migration, template type, and page settings are handled. Returns the new template_id(s); insert one into a page afterwards, or read it with elementor-get-content. Does not change any existing page.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'content'        => [ 'type' => 'string', 'description' => 'The template export JSON (as a string).' ],
                'content_base64' => [ 'type' => 'string', 'description' => 'Base64 of the file bytes (JSON or a .zip). Use this to import a handed-over/exported file, especially a binary .zip. Used if content is omitted.' ],
                'url'            => [ 'type' => 'string', 'description' => 'URL to fetch the template .json (or .zip) from. Used if content and content_base64 are omitted.' ],
                'import_mode' => [ 'type' => 'string', 'enum' => [ 'match_site', 'inline' ], 'description' => 'Elementor import mode. Default match_site.' ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [
                'success'   => [ 'type' => 'boolean' ],
                'templates' => [ 'type' => 'array' ],
                'message'   => [ 'type' => 'string' ],
            ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( '\Elementor\Plugin' ) ) { return [ 'success' => false, 'message' => 'Elementor is not active.' ]; }
                $tm = \Elementor\Plugin::$instance->templates_manager;
                if ( ! is_object( $tm ) || ! method_exists( $tm, 'get_source' ) ) { return [ 'success' => false, 'message' => 'Elementor template library is unavailable.' ]; }

                // Resolve the payload: inline content, else base64 bytes, else fetch from url.
                $content = isset( $input['content'] ) ? (string) $input['content'] : '';
                $is_zip  = false;
                if ( $content === '' && ! empty( $input['content_base64'] ) ) {
                    $decoded = base64_decode( (string) $input['content_base64'], true );
                    if ( false === $decoded || '' === $decoded ) { return [ 'success' => false, 'message' => 'content_base64 is not valid base64.' ]; }
                    $content = $decoded;
                    // A .zip starts with the "PK" local-file-header magic bytes.
                    $is_zip  = ( substr( $content, 0, 2 ) === 'PK' );
                }
                if ( $content === '' && ! empty( $input['url'] ) ) {
                    $url  = esc_url_raw( (string) $input['url'] );
                    $resp = wp_remote_get( $url, [ 'timeout' => 20 ] );
                    if ( is_wp_error( $resp ) ) { return [ 'success' => false, 'message' => 'Could not fetch url: ' . $resp->get_error_message() ]; }
                    if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) { return [ 'success' => false, 'message' => 'Fetch failed (HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . ').' ]; }
                    $content = (string) wp_remote_retrieve_body( $resp );
                    $is_zip  = ( 'zip' === strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) );
                }
                if ( $content === '' ) { return [ 'success' => false, 'message' => 'Provide the template export JSON in content, base64 bytes in content_base64, or a url to fetch it from.' ]; }

                // Sanity-check JSON payloads (skip for zip).
                if ( ! $is_zip ) {
                    $probe = json_decode( $content, true );
                    if ( ! is_array( $probe ) || ! isset( $probe['content'] ) ) {
                        return [ 'success' => false, 'message' => 'This does not look like an Elementor template export (expected an object with a "content" key). Pass the exported .json, not a raw _elementor_data array.' ];
                    }
                }

                // Write to a temp file for Elementor's file-based importer.
                $ext = $is_zip ? 'zip' : 'json';
                $tmp = wp_tempnam( 'atarim-elementor-import.' . $ext );
                if ( ! $tmp ) { return [ 'success' => false, 'message' => 'Could not create a temp file for import.' ]; }
                if ( false === file_put_contents( $tmp, $content ) ) { @unlink( $tmp ); return [ 'success' => false, 'message' => 'Could not write the temp import file.' ]; }

                try {
                    $source = $tm->get_source( 'local' );
                    if ( ! is_object( $source ) ) { @unlink( $tmp ); return [ 'success' => false, 'message' => 'Elementor local template source unavailable.' ]; }
                    $mode   = ( isset( $input['import_mode'] ) && in_array( $input['import_mode'], [ 'match_site', 'inline' ], true ) ) ? $input['import_mode'] : 'match_site';
                    $result = $source->import_template( 'atarim-elementor-import.' . $ext, $tmp, $mode );
                } catch ( \Throwable $e ) {
                    @unlink( $tmp );
                    return [ 'success' => false, 'message' => 'Import failed: ' . $e->getMessage() ];
                }
                @unlink( $tmp );

                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'message' => 'Import failed: ' . $result->get_error_message() ];
                }

                // Normalize: importer returns one item or a list of items.
                $items = ( isset( $result[0] ) && is_array( $result[0] ) ) ? $result : [ $result ];
                $templates = [];
                foreach ( $items as $it ) {
                    if ( ! is_array( $it ) ) { continue; }
                    $templates[] = [
                        'template_id' => isset( $it['template_id'] ) ? (int) $it['template_id'] : ( isset( $it['id'] ) ? (int) $it['id'] : 0 ),
                        'title'       => isset( $it['title'] ) ? (string) $it['title'] : '',
                        'type'        => isset( $it['type'] ) ? (string) $it['type'] : '',
                    ];
                }
                if ( empty( $templates ) ) { return [ 'success' => false, 'message' => 'Import returned no templates.' ]; }

                return [
                    'success'   => true,
                    'templates' => $templates,
                    'message'   => sprintf( 'Imported %d template(s) into the library: %s.', count( $templates ), implode( ', ', array_map( function( $t ) { return sprintf( '#%d "%s"', $t['template_id'], $t['title'] ); }, $templates ) ) ),
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- get-content --------------------------- */

    private function register_get_content() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-get-content', [
            'label'        => 'Get Elementor Content',
            'description'  => 'Read a post\'s Elementor structure. By default returns a depth-limited structural tree (each node: id, elType, widgetType, child_count) — ideal for locating the element id you want to edit. Pass element_id to get that one element\'s full data (including settings) and its subtree. Pass include_settings:true to get the whole tree with settings (can be large).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id'       => [ 'type' => 'string', 'description' => 'Return the full data for just this element (and its subtree).' ],
                'include_settings' => [ 'type' => 'boolean', 'description' => 'Return the entire raw tree with settings. Defaults to false (structural summary).', 'default' => false ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'tree' => [ 'type' => 'array' ], 'element' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                $err = $self->require_elementor_post( $post_id );
                if ( $err ) { return $err; }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                if ( ! empty( $input['element_id'] ) ) {
                    $el = AVCF_Elementor_Helpers::find( $tree, (string) $input['element_id'] );
                    if ( $el === null ) {
                        return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found on post %d.', $input['element_id'], $post_id ) ];
                    }
                    return [ 'success' => true, 'post_id' => $post_id, 'element' => $el, 'message' => 'OK.' ];
                }
                if ( ! empty( $input['include_settings'] ) ) {
                    return [ 'success' => true, 'post_id' => $post_id, 'tree' => $tree, 'message' => 'OK (full tree).' ];
                }
                return [ 'success' => true, 'post_id' => $post_id, 'tree' => AVCF_Elementor_Helpers::summarize( $tree ), 'message' => 'OK (structural summary).' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- schemas ------------------------------ */

    private function register_schemas() {
        $self = $this;

        wp_register_ability( 'atarim/elementor-get-widget-schema', [
            'label'        => 'Get Elementor Widget Schema',
            'description'  => 'List available Elementor widgets, or get the control schema for one widget. Omit widget to list all registered widgets (name, title). Pass widget (e.g. "heading", "button", "image") to get its controls: name, type, label, tab (content/style/advanced), default, options. Use this to know which settings keys add-element / edit-element accept.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'widget' => [ 'type' => 'string', 'description' => 'Widget name. Omit to list all widgets.' ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'widgets' => [ 'type' => 'array' ], 'widget' => [ 'type' => 'string' ], 'controls' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $plugin = \Elementor\Plugin::$instance;
                $wm = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
                if ( ! is_object( $wm ) ) {
                    return [ 'success' => false, 'message' => 'Elementor widgets manager unavailable.' ];
                }
                $name = isset( $input['widget'] ) ? (string) $input['widget'] : '';
                try {
                    if ( $name === '' ) {
                        $types = $wm->get_widget_types();
                        $list  = [];
                        foreach ( (array) $types as $key => $widget ) {
                            $list[] = [
                                'name'  => is_object( $widget ) && method_exists( $widget, 'get_name' ) ? $widget->get_name() : (string) $key,
                                'title' => is_object( $widget ) && method_exists( $widget, 'get_title' ) ? $widget->get_title() : (string) $key,
                            ];
                        }
                        return [ 'success' => true, 'widgets' => $list, 'message' => sprintf( '%d widgets.', count( $list ) ) ];
                    }
                    $widget = $wm->get_widget_types( $name );
                    if ( ! $widget ) {
                        return [ 'success' => false, 'message' => sprintf( 'Widget "%s" not found.', $name ) ];
                    }
                    $controls = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : [];
                    $out = [];
                    foreach ( $controls as $cname => $control ) {
                        $out[] = $self->shape_control( $cname, $control );
                    }
                    return [ 'success' => true, 'widget' => $name, 'controls' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read widget schema: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/elementor-get-style-schema', [
            'label'        => 'Get Elementor Style Schema',
            'description'  => 'Get the style-tab controls for a widget (typography, colors, spacing, borders, etc.) — the subset of a widget\'s controls whose tab is "style". Use alongside get-widget-schema (which covers content controls) when you need to set a widget\'s appearance.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'widget' => [ 'type' => 'string', 'description' => 'Widget name (e.g. "heading").' ] ], 'required' => [ 'widget' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'widget' => [ 'type' => 'string' ], 'controls' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $plugin = \Elementor\Plugin::$instance;
                $wm = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
                if ( ! is_object( $wm ) ) {
                    return [ 'success' => false, 'message' => 'Elementor widgets manager unavailable.' ];
                }
                $name = isset( $input['widget'] ) ? (string) $input['widget'] : '';
                if ( $name === '' ) { return [ 'success' => false, 'message' => 'widget is required.' ]; }
                try {
                    $widget = $wm->get_widget_types( $name );
                    if ( ! $widget ) { return [ 'success' => false, 'message' => sprintf( 'Widget "%s" not found.', $name ) ]; }
                    $controls = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : [];
                    $out = [];
                    foreach ( $controls as $cname => $control ) {
                        if ( isset( $control['tab'] ) && $control['tab'] === 'style' ) {
                            $out[] = $self->shape_control( $cname, $control );
                        }
                    }
                    return [ 'success' => true, 'widget' => $name, 'controls' => $out, 'message' => 'OK.' ];
                } catch ( \Throwable $e ) {
                    return [ 'success' => false, 'message' => 'Failed to read style schema: ' . $e->getMessage() ];
                }
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- add-element --------------------------- */

    private function register_add_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-add-element', [
            'label'        => 'Add Elementor Element',
            'description'  => 'Insert a new element into a post\'s Elementor tree. elType is section, column, container, or widget; when widget, widget_type is required (e.g. "heading"). parent_id is the element to nest under (omit for top level); index is the position among siblings (omit to append). settings is a key/value map matching the widget/element controls (see get-widget-schema). Returns the new element id.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                'elType'      => [ 'type' => 'string', 'enum' => [ 'section', 'column', 'container', 'widget' ] ],
                'widget_type' => [ 'type' => 'string', 'description' => 'Required when elType is "widget".' ],
                'parent_id'   => [ 'type' => 'string', 'description' => 'Element to nest under. Omit for top level.' ],
                'index'       => [ 'type' => 'integer', 'description' => 'Position among siblings. Omit to append.', 'minimum' => 0 ],
                'settings'    => [ 'type' => 'object', 'description' => 'Control values for the element.' ],
            ], 'required' => [ 'post_id', 'elType' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'element_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $elType = isset( $input['elType'] ) ? (string) $input['elType'] : '';
                if ( ! in_array( $elType, [ 'section', 'column', 'container', 'widget' ], true ) ) {
                    return [ 'success' => false, 'message' => 'elType must be section, column, container, or widget.' ];
                }
                if ( $elType === 'widget' && empty( $input['widget_type'] ) ) {
                    return [ 'success' => false, 'message' => 'widget_type is required when elType is "widget".' ];
                }
                $node = [
                    'id'       => AVCF_Elementor_Helpers::generate_id(),
                    'elType'   => $elType,
                    'settings' => isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : (object) [],
                    'elements' => [],
                ];
                if ( $elType === 'widget' ) {
                    $node['widgetType'] = (string) $input['widget_type'];
                }
                $tree  = AVCF_Elementor_Helpers::read_tree( $post_id );
                $index = isset( $input['index'] ) ? (int) $input['index'] : null;
                list( $tree, $inserted ) = AVCF_Elementor_Helpers::insert( $tree, isset( $input['parent_id'] ) ? (string) $input['parent_id'] : null, $node, $index );
                if ( ! $inserted ) {
                    return [ 'success' => false, 'message' => sprintf( 'parent_id "%s" not found.', isset( $input['parent_id'] ) ? $input['parent_id'] : '' ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'element_id' => $node['id'], 'message' => sprintf( 'Added %s element.', $elType === 'widget' ? $node['widgetType'] : $elType ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- edit-element -------------------------- */

    private function register_edit_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-edit-element', [
            'label'        => 'Edit Elementor Element',
            'description'  => 'Update an element\'s settings and/or styles by id. settings and styles are each shallow-merged into the element\'s existing values (top-level keys you send overwrite, others are kept). Provide at least one of settings or styles — pass styles alone to restyle without touching content. For Elementor v4 atomic elements, styles is the atomic styles object and settings uses the atomic prop shape; read the element first with get-content. The save auto-detects atomic elements and writes them safely (a plain Document save would strip them). For v3 widgets, get control names with get-widget-schema.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id' => [ 'type' => 'string' ],
                'settings'   => [ 'type' => 'object', 'description' => 'Settings to merge into the element. Optional if styles is given.' ],
                'styles'     => [ 'type' => 'object', 'description' => 'Styles to merge into the element (Elementor v4 atomic styles object). Optional if settings is given.' ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'unknown_settings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
                if ( $element_id === '' ) { return [ 'success' => false, 'message' => 'element_id is required.' ]; }
                $settings_patch = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                $styles_patch   = isset( $input['styles'] ) && is_array( $input['styles'] ) ? $input['styles'] : [];
                if ( empty( $settings_patch ) && empty( $styles_patch ) ) {
                    return [ 'success' => false, 'message' => 'Provide a non-empty settings and/or styles object.' ];
                }
                $tree  = AVCF_Elementor_Helpers::read_tree( $post_id );
                $found = false;
                $node_type = '';
                $tree  = AVCF_Elementor_Helpers::map_edit( $tree, $element_id, function( $node ) use ( $settings_patch, $styles_patch, &$node_type ) {
                    $node_type = ( isset( $node['widgetType'] ) && $node['widgetType'] !== '' ) ? (string) $node['widgetType'] : ( isset( $node['elType'] ) ? (string) $node['elType'] : '' );
                    if ( ! empty( $settings_patch ) ) {
                        $current = ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) ? $node['settings'] : [];
                        $node['settings'] = array_merge( $current, $settings_patch );
                    }
                    if ( ! empty( $styles_patch ) ) {
                        $current_styles = ( isset( $node['styles'] ) && is_array( $node['styles'] ) ) ? $node['styles'] : [];
                        $node['styles'] = array_merge( $current_styles, $styles_patch );
                    }
                    return $node;
                }, $found );
                if ( ! $found ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                $updated = [];
                if ( ! empty( $settings_patch ) ) { $updated[] = 'settings'; }
                if ( ! empty( $styles_patch ) )   { $updated[] = 'styles'; }

                // Tier-2 validation (non-blocking): flag settings keys the element
                // type does not define — those are silently ignored on render, so
                // surfacing them turns a silent no-op into a visible warning.
                $result = [ 'success' => true, 'updated' => $updated ];
                $message = sprintf( 'Updated element "%s" (%s).', $element_id, implode( ' + ', $updated ) );
                if ( ! empty( $settings_patch ) && $node_type !== '' ) {
                    $valid = AVCF_Elementor_Helpers::valid_setting_keys( $node_type );
                    if ( 'unknown' !== $valid['mode'] && ! empty( $valid['keys'] ) ) {
                        $unknown = array_values( array_filter(
                            array_diff( array_keys( $settings_patch ), $valid['keys'] ),
                            function( $k ) { return '__dynamic__' !== $k; }
                        ) );
                        if ( ! empty( $unknown ) ) {
                            $result['unknown_settings'] = $unknown;
                            $message .= sprintf( ' WARNING: %d settings key(s) are not defined by "%s" and are likely ignored: %s. Check names with %s.', count( $unknown ), $node_type, implode( ', ', $unknown ), ( 'atomic' === $valid['mode'] ? 'elementor-get-atomic-schema' : 'elementor-get-widget-schema' ) );
                        }
                    }
                }
                $result['message'] = $message;
                return $result;
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- move-element -------------------------- */

    private function register_move_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-move-element', [
            'label'        => 'Move Elementor Element',
            'description'  => 'Relocate an element (and its subtree) to a new parent and/or position. new_parent_id omitted moves it to the top level; index sets the position among the destination\'s children (omit to append).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id'    => [ 'type' => 'string' ],
                'new_parent_id' => [ 'type' => 'string', 'description' => 'Destination parent. Omit for top level.' ],
                'index'         => [ 'type' => 'integer', 'minimum' => 0 ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
                if ( $element_id === '' ) { return [ 'success' => false, 'message' => 'element_id is required.' ]; }
                $new_parent = isset( $input['new_parent_id'] ) ? (string) $input['new_parent_id'] : null;
                if ( $new_parent !== null && $new_parent === $element_id ) {
                    return [ 'success' => false, 'message' => 'An element cannot be moved into itself.' ];
                }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                list( $tree, $removed ) = AVCF_Elementor_Helpers::remove( $tree, $element_id );
                if ( $removed === null ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                $index = isset( $input['index'] ) ? (int) $input['index'] : null;
                list( $tree, $inserted ) = AVCF_Elementor_Helpers::insert( $tree, $new_parent, $removed, $index );
                if ( ! $inserted ) {
                    return [ 'success' => false, 'message' => sprintf( 'new_parent_id "%s" not found (element was not moved).', $new_parent ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Moved element "%s".', $element_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- delete-element ------------------------- */

    private function register_delete_element() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-delete-element', [
            'label'        => 'Delete Elementor Element',
            'description'  => 'Remove an element (and everything nested inside it) from a post\'s Elementor tree by id. Dry run unless confirm:true.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
                'element_id' => [ 'type' => 'string' ],
                'confirm'    => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'element_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                $element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
                if ( $element_id === '' ) { return [ 'success' => false, 'message' => 'element_id is required.' ]; }
                $tree = AVCF_Elementor_Helpers::read_tree( $post_id );
                if ( AVCF_Elementor_Helpers::find( $tree, $element_id ) === null ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                if ( empty( $input['confirm'] ) ) {
                    return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete element "%s" and its children. Re-call with confirm:true.', $element_id ) ];
                }
                list( $tree, $removed ) = AVCF_Elementor_Helpers::remove( $tree, $element_id );
                if ( $removed === null ) {
                    return [ 'success' => false, 'message' => sprintf( 'Element "%s" not found.', $element_id ) ];
                }
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'deleted' => true, 'message' => sprintf( 'Deleted element "%s".', $element_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-content --------------------------- */

    private function register_set_content() {
        $self = $this;
        wp_register_ability( 'atarim/elementor-set-content', [
            'label'        => 'Set Elementor Content',
            'description'  => 'Replace a post\'s ENTIRE Elementor tree with the supplied elements array (the same shape get-content returns with include_settings:true). This is a full overwrite — use it to initialise an Elementor page or rebuild it wholesale; for targeted changes prefer add/edit/move/delete-element. Element ids are preserved as given (or generated for nodes missing one).',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'elements' => [ 'type' => 'array', 'description' => 'Full element tree to store.' ],
            ], 'required' => [ 'post_id', 'elements' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id <= 0 || ! get_post( $post_id ) ) { return [ 'success' => false, 'message' => 'A valid post_id is required.' ]; }
                if ( ! $self->can_edit( $post_id ) ) { return [ 'success' => false, 'message' => 'You do not have permission to edit this post.' ]; }
                if ( ! isset( $input['elements'] ) || ! is_array( $input['elements'] ) ) {
                    return [ 'success' => false, 'message' => 'elements must be an array.' ];
                }
                $tree = $self->ensure_ids( $input['elements'] );
                if ( ! AVCF_Elementor_Helpers::write_tree( $post_id, $tree ) ) {
                    return [ 'success' => false, 'message' => 'Failed to save the Elementor tree.' ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Replaced Elementor content on post %d.', $post_id ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /** Recursively ensure every node has an id and an elements array. */
    private function ensure_ids( $elements ) {
        $out = [];
        foreach ( (array) $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            if ( empty( $el['id'] ) ) {
                $el['id'] = AVCF_Elementor_Helpers::generate_id();
            }
            if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
                $el['elements'] = [];
            } else {
                $el['elements'] = $this->ensure_ids( $el['elements'] );
            }
            $out[] = $el;
        }
        return $out;
    }
}