<?php
/**
 * Theme FILE editing MCP abilities.
 *
 * Distinct from class-avcf-abilities-themes.php, which manages theme PACKAGES
 * (install / activate / update / delete). This cluster edits the *contents* of
 * editable text files inside a theme, via a stage-to-S3 round trip:
 *
 *   list-theme-files   → enumerate editable text files in the active theme
 *                        (and optionally its parent).
 *   get-theme-file     → read one file, push it to S3 via the Atarim presigned
 *                        upload endpoint, return the S3 key for the app to edit.
 *   replace-theme-file → write new content from any public http(s) URL (fetched
 *                        server-side, SSRF-checked) or base64 data, lint it,
 *                        back up the current on-disk file, then overwrite.
 *   list-theme-backups → enumerate backups (the "pick" side of undo).
 *   restore-theme-backup → restore the most recent (or a chosen) backup.
 *   clear-theme-backups  → remove backups (all / by date / range / by name),
 *                          preview-by-default, confirm to delete.
 *
 * Safety model:
 *   - All file reads/writes are realpath-contained to the target theme dir and
 *     restricted to an editable text-extension allow-list. No path can escape
 *     the theme (no ../../wp-config.php), and binaries are never touched.
 *   - Before any overwrite the current file is copied to a timestamped backup.
 *   - PHP content is parse-checked (token_get_all + TOKEN_PARSE) before writing,
 *     so a syntax error can never white-screen the site; JSON is validity-checked;
 *     other text types are backed up and written without a syntax gate.
 *   - Backups live OUTSIDE the theme in wp-content/uploads/atarim-backup/theme/,
 *     mirroring <stylesheet>/<relative-path> so they survive theme switches and
 *     never pollute file listings. The generic atarim-backup/ root is reserved
 *     so a future plugin-file editor can reuse the same plumbing under
 *     atarim-backup/plugin/.
 *   - clear-theme-backups only ever operates inside atarim-backup/theme/; there
 *     is no code path by which it can touch a live theme file.
 *
 * Note: ability names registered here must also be added to the $tools array in
 * doit/class-avcf-mcp.php::avcf_mcp_setup_server() to be exposed by the server.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Theme_Files extends AVCF_Abilities_Base {

    /**
     * The backup domain segment under the generic atarim-backup/ root.
     * Reserved as a constant so a future plugin-file editor uses 'plugin'
     * against the same backup/restore/clear plumbing.
     */
    const BACKUP_DOMAIN = 'theme';

    /** @var AVCF_Functions */
    private $function;

    public function __construct() {
        $this->function = new AVCF_Functions();
    }

    /**
     * Register all theme-file editing abilities.
     * Called from AVCF_MCP::avcf_mcp_register_abilities() on wp_abilities_api_init.
     */
    public function register() {

        // ---- list-theme-files ----
        wp_register_ability( 'atarim/list-theme-files', [
            'label'               => 'List Theme Files',
            'description'         => 'Lists editable text files (php, css, js, html, json, txt, md, po/pot) in a theme. Defaults to the active theme (the child theme if one is active). For a child theme, pass include_parent=true to also list the parent theme\'s files recursively. Each entry reports which theme it belongs to, so identically named files (e.g. functions.php in both child and parent) are never confused. Binary assets (images, fonts) are intentionally excluded.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug of the theme to list. Omit for the active theme.',
                    ],
                    'include_parent' => [
                        'type'        => 'boolean',
                        'description' => 'When the target is a child theme, also list the parent theme recursively.',
                        'default'     => false,
                    ],
                    'extensions' => [
                        'type'        => 'array',
                        'items'       => [ 'type' => 'string' ],
                        'description' => 'Optional filter to narrow which extensions are returned. Can only narrow within the editable allow-list; values outside it are ignored.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total' => [ 'type' => 'integer' ],
                    'files' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'theme'     => [ 'type' => 'string' ],
                                'role'      => [ 'type' => 'string' ],
                                'path'      => [ 'type' => 'string' ],
                                'extension' => [ 'type' => 'string' ],
                                'size'      => [ 'type' => 'integer' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'files' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $resolved = $this->avcf_resolve_theme( isset( $input['theme'] ) ? $input['theme'] : '' );
                if ( ! empty( $resolved['error'] ) ) {
                    return [ 'total' => 0, 'files' => [], 'error' => $resolved['error'] ];
                }

                $include_parent = ! empty( $input['include_parent'] );
                $exts           = $this->avcf_effective_extensions( isset( $input['extensions'] ) ? $input['extensions'] : [] );

                $targets = [ [ 'stylesheet' => $resolved['stylesheet'], 'role' => $resolved['role'] ] ];
                if ( $include_parent && ! empty( $resolved['parent'] ) ) {
                    $targets[] = [ 'stylesheet' => $resolved['parent'], 'role' => 'parent' ];
                }

                $files = [];
                foreach ( $targets as $t ) {
                    $dir = $this->avcf_theme_dir( $t['stylesheet'] );
                    if ( ! $dir ) {
                        continue;
                    }
                    foreach ( $this->avcf_scan_files( $dir, $exts ) as $rel => $size ) {
                        $files[] = [
                            'theme'     => $t['stylesheet'],
                            'role'      => $t['role'],
                            'path'      => $rel,
                            'extension' => strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ),
                            'size'      => (int) $size,
                        ];
                    }
                }

                return [
                    'total' => count( $files ),
                    'files' => $files,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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

        // ---- get-theme-file ----
        wp_register_ability( 'atarim/get-theme-file', [
            'label'               => 'Get Theme File',
            'description'         => 'Reads one editable theme file and stages it for editing: it uploads the current file contents to Atarim S3 via the presigned upload endpoint and returns the resulting S3 key. The app fetches that key, edits the content, and passes the edited S3 URL to replace-theme-file. The S3 staging hop is an Atarim-app convenience, not a requirement — replace-theme-file accepts any public URL or base64 content, so callers hosting content elsewhere can skip this tool. Defaults to the active theme; pass theme to target a specific one (e.g. the parent).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Theme-relative file path, exactly as returned by list-theme-files (e.g. "functions.php" or "inc/template-tags.php").',
                        'minLength'   => 1,
                    ],
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug. Omit for the active theme.',
                    ],
                ],
                'required'             => [ 'path' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'theme'   => [ 'type' => 'string' ],
                    'path'    => [ 'type' => 'string' ],
                    'key'     => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $resolved = $this->avcf_resolve_theme( isset( $input['theme'] ) ? $input['theme'] : '' );
                if ( ! empty( $resolved['error'] ) ) {
                    return [ 'success' => false, 'message' => $resolved['error'] ];
                }
                $stylesheet = $resolved['stylesheet'];

                $loc = $this->avcf_locate_editable( $stylesheet, isset( $input['path'] ) ? $input['path'] : '' );
                if ( ! empty( $loc['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'message' => $loc['error'] ];
                }

                $fs = $this->avcf_fs();
                if ( ! $fs || ! $fs->exists( $loc['abs'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'File not found on disk.' ];
                }
                $contents = $fs->get_contents( $loc['abs'] );
                if ( $contents === false ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'Could not read file.' ];
                }

                $upload = $this->avcf_presigned_upload( basename( $loc['rel'] ), $contents );
                if ( ! empty( $upload['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => $upload['error'] ];
                }

                return [
                    'success' => true,
                    'theme'   => $stylesheet,
                    'path'    => $loc['rel'],
                    'key'     => $upload['key'],
                    'message' => sprintf( 'Staged "%s" to S3.', $loc['rel'] ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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

        // ---- replace-theme-file ----
        wp_register_ability( 'atarim/replace-theme-file', [
            'label'               => 'Replace Theme File',
            'description'         => 'Replaces an existing theme file with content downloaded from any publicly reachable http(s) URL (fetched server-side; the URL does not need to be S3). Optionally accepts base64 content directly via source/source_data, mirroring upload-media. The current on-disk file is first copied to a timestamped backup; PHP is parse-checked and JSON validity-checked before writing, so invalid PHP is rejected. Targets an existing file only. SSRF protections apply: private IPs, cloud-metadata endpoints, and non-http(s) schemes are blocked.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Theme-relative path of the existing file to replace.',
                        'minLength'   => 1,
                    ],
                    'source' => [
                        'type'        => 'string',
                        'enum'        => [ 'url', 'base64' ],
                        'default'     => 'url',
                        'description' => 'How to source the new content. "url": download from source_url (http/https, fetched server-side, SSRF-protected). "base64": decode source_data.',
                    ],
                    'source_url' => [
                        'type'        => 'string',
                        'description' => 'When source is "url": the http or https URL to download the new file content from. Does not need to be S3. Required for url source. Private IPs and cloud metadata endpoints are blocked.',
                    ],
                    'source_data' => [
                        'type'        => 'string',
                        'description' => 'When source is "base64": base64-encoded file contents (the data itself, NOT a data: URL prefix). Required for base64 source.',
                    ],
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug. Omit for the active theme.',
                    ],
                ],
                'required'             => [ 'path' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'theme'   => [ 'type' => 'string' ],
                    'path'    => [ 'type' => 'string' ],
                    'backup'  => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $resolved = $this->avcf_resolve_theme( isset( $input['theme'] ) ? $input['theme'] : '' );
                if ( ! empty( $resolved['error'] ) ) {
                    return [ 'success' => false, 'message' => $resolved['error'] ];
                }
                $stylesheet = $resolved['stylesheet'];

                $loc = $this->avcf_locate_editable( $stylesheet, isset( $input['path'] ) ? $input['path'] : '' );
                if ( ! empty( $loc['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'message' => $loc['error'] ];
                }

                $fs = $this->avcf_fs();
                if ( ! $fs || ! $fs->exists( $loc['abs'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'File does not exist; replace targets existing files only.' ];
                }

                $source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : 'url';
                if ( ! in_array( $source, [ 'url', 'base64' ], true ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'source must be "url" or "base64".' ];
                }

                $fetched = $this->avcf_fetch_source( $source, $input );
                if ( ! empty( $fetched['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => $fetched['error'] ];
                }
                $new_content = $fetched['body'];

                $ext  = strtolower( pathinfo( $loc['rel'], PATHINFO_EXTENSION ) );
                $lint = $this->avcf_lint( $new_content, $ext );
                if ( $lint !== null ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'Rejected, file unchanged: ' . $lint ];
                }

                $backup = $this->avcf_make_backup( $stylesheet, $loc['rel'], $loc['abs'] );
                if ( ! empty( $backup['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'Backup failed, file unchanged: ' . $backup['error'] ];
                }

                if ( ! $fs->put_contents( $loc['abs'], $new_content, FS_CHMOD_FILE ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'backup' => $backup['backup'], 'message' => 'Write failed (filesystem permissions). A backup of the original was saved.' ];
                }

                return [
                    'success' => true,
                    'theme'   => $stylesheet,
                    'path'    => $loc['rel'],
                    'backup'  => $backup['backup'],
                    'message' => sprintf( 'Replaced "%s". Previous version backed up.', $loc['rel'] ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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

        // ---- list-theme-backups ----
        wp_register_ability( 'atarim/list-theme-backups', [
            'label'               => 'List Theme Backups',
            'description'         => 'Lists saved theme-file backups, newest first, for undo. Each entry gives the theme, the original file path, the backup timestamp, and the backup reference used by restore-theme-backup. Optionally filter by theme and/or by original file path.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Filter to one theme (stylesheet slug).',
                    ],
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Filter to one original file path (e.g. "functions.php").',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'   => [ 'type' => 'integer' ],
                    'backups' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'theme'      => [ 'type' => 'string' ],
                                'path'       => [ 'type' => 'string' ],
                                'timestamp'  => [ 'type' => 'string' ],
                                'backup_ref' => [ 'type' => 'string' ],
                                'size'       => [ 'type' => 'integer' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'backups' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $theme_filter = isset( $input['theme'] ) ? sanitize_key( $input['theme'] ) : '';
                $path_filter  = isset( $input['path'] ) ? $this->avcf_clean_rel( $input['path'] ) : '';

                $backups = $this->avcf_collect_backups( $theme_filter, $path_filter, '', '', '' );

                // Newest first.
                usort( $backups, function( $a, $b ) {
                    return strcmp( $b['timestamp'], $a['timestamp'] );
                } );

                $out = [];
                foreach ( $backups as $b ) {
                    $out[] = [
                        'theme'      => $b['theme'],
                        'path'       => $b['path'],
                        'timestamp'  => $b['timestamp'],
                        'backup_ref' => $b['backup_ref'],
                        'size'       => (int) $b['size'],
                    ];
                }

                return [ 'total' => count( $out ), 'backups' => $out ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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

        // ---- restore-theme-backup ----
        wp_register_ability( 'atarim/restore-theme-backup', [
            'label'               => 'Restore Theme Backup',
            'description'         => 'Restores a theme file from backup. By default restores the most recent backup of the given file; pass a timestamp (or an exact backup_ref from list-theme-backups) to restore a specific version. The current on-disk file is itself backed up first, so a restore can also be undone. PHP is re-parse-checked before writing.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Original theme-relative file path to restore (e.g. "functions.php").',
                        'minLength'   => 1,
                    ],
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug. Omit for the active theme.',
                    ],
                    'timestamp' => [
                        'type'        => 'string',
                        'description' => 'Backup timestamp (YYYYMMDD-HHMMSS) to restore. Omit to restore the most recent backup.',
                    ],
                    'backup_ref' => [
                        'type'        => 'string',
                        'description' => 'Exact backup reference from list-theme-backups. Takes precedence over timestamp/path if given.',
                    ],
                ],
                'required'             => [ 'path' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'       => [ 'type' => 'boolean' ],
                    'theme'         => [ 'type' => 'string' ],
                    'path'          => [ 'type' => 'string' ],
                    'restored_from' => [ 'type' => 'string' ],
                    'backup'        => [ 'type' => 'string' ],
                    'message'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $resolved = $this->avcf_resolve_theme( isset( $input['theme'] ) ? $input['theme'] : '' );
                if ( ! empty( $resolved['error'] ) ) {
                    return [ 'success' => false, 'message' => $resolved['error'] ];
                }
                $stylesheet = $resolved['stylesheet'];

                $rel = $this->avcf_clean_rel( isset( $input['path'] ) ? $input['path'] : '' );
                if ( $rel === '' ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'message' => 'path is required.' ];
                }

                $ref       = isset( $input['backup_ref'] ) ? (string) $input['backup_ref'] : '';
                $timestamp = isset( $input['timestamp'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $input['timestamp'] ) : '';

                // Find candidate backups for this theme + original path.
                $candidates = $this->avcf_collect_backups( $stylesheet, $rel, '', '', '' );
                if ( empty( $candidates ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'No backups found for this file.' ];
                }
                usort( $candidates, function( $a, $b ) {
                    return strcmp( $b['timestamp'], $a['timestamp'] );
                } );

                $chosen = null;
                if ( $ref !== '' ) {
                    foreach ( $candidates as $c ) {
                        if ( $c['backup_ref'] === $ref ) { $chosen = $c; break; }
                    }
                } elseif ( $timestamp !== '' ) {
                    foreach ( $candidates as $c ) {
                        if ( $c['timestamp'] === $timestamp ) { $chosen = $c; break; }
                    }
                } else {
                    $chosen = $candidates[0]; // most recent
                }

                if ( $chosen === null ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'No backup matched the requested timestamp/backup_ref.' ];
                }

                $fs = $this->avcf_fs();
                if ( ! $fs ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Filesystem unavailable.' ];
                }
                $backup_abs = $this->avcf_backups_root() . '/' . $chosen['backup_ref'];
                if ( ! $fs->exists( $backup_abs ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Backup file no longer exists on disk.' ];
                }
                $content = $fs->get_contents( $backup_abs );
                if ( $content === false ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Could not read backup file.' ];
                }

                // Resolve destination (must be a valid editable path within the theme).
                $loc = $this->avcf_locate_editable( $stylesheet, $rel );
                if ( ! empty( $loc['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => $loc['error'] ];
                }

                $ext  = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
                $lint = $this->avcf_lint( $content, $ext );
                if ( $lint !== null ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Backup failed validation, nothing changed: ' . $lint ];
                }

                // Back up the current file first (so the restore is itself reversible),
                // but only if there is a current file to preserve.
                $current_backup = '';
                if ( $fs->exists( $loc['abs'] ) ) {
                    $cb = $this->avcf_make_backup( $stylesheet, $rel, $loc['abs'] );
                    if ( ! empty( $cb['error'] ) ) {
                        return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Could not back up current file before restore: ' . $cb['error'] ];
                    }
                    $current_backup = $cb['backup'];
                }

                if ( ! $fs->put_contents( $loc['abs'], $content, FS_CHMOD_FILE ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Write failed (filesystem permissions).' ];
                }

                return [
                    'success'       => true,
                    'theme'         => $stylesheet,
                    'path'          => $rel,
                    'restored_from' => $chosen['timestamp'],
                    'backup'        => $current_backup,
                    'message'       => sprintf( 'Restored "%s" from backup %s.', $rel, $chosen['timestamp'] ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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

        // ---- clear-theme-backups ----
        wp_register_ability( 'atarim/clear-theme-backups', [
            'label'               => 'Clear Theme Backups',
            'description'         => 'Deletes theme-file BACKUPS only (never live theme files). Filters: theme, name (original file path), date (YYYY-MM-DD), and date_from/date_to range; filters combine. PREVIEW BY DEFAULT — without confirm=true it deletes nothing and returns what WOULD be removed (count and per-theme breakdown); show that to the user and re-call with confirm=true to actually delete. Disambiguation: when deleting by name without a theme and that filename exists under more than one theme, the call returns the candidate themes and deletes nothing — ask the user which theme, then re-call with theme set. Deleting "all" (no filters) removes every theme backup across all themes, so always surface the preview before confirming.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Limit to one theme (stylesheet slug). Required when deleting by name and the name is ambiguous across themes.',
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Original file path to clear backups for (e.g. "functions.php"). Clears all timestamped versions of that file.',
                    ],
                    'date' => [
                        'type'        => 'string',
                        'description' => 'Clear backups from a single day (YYYY-MM-DD).',
                    ],
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Start of a date range, inclusive (YYYY-MM-DD).',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'End of a date range, inclusive (YYYY-MM-DD).',
                    ],
                    'confirm' => [
                        'type'        => 'boolean',
                        'description' => 'Must be true to actually delete. Omitted/false returns a preview only.',
                        'default'     => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'status'        => [ 'type' => 'string' ],
                    'deleted'       => [ 'type' => 'integer' ],
                    'matched'       => [ 'type' => 'integer' ],
                    'by_theme'      => [ 'type' => 'object' ],
                    'needs_theme'   => [ 'type' => 'boolean' ],
                    'candidate_themes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message'       => [ 'type' => 'string' ],
                ],
                'required' => [ 'status', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $theme = isset( $input['theme'] ) ? sanitize_key( $input['theme'] ) : '';
                $name  = isset( $input['name'] ) ? $this->avcf_clean_rel( $input['name'] ) : '';
                $date  = isset( $input['date'] ) ? $this->avcf_clean_date( $input['date'] ) : '';
                $from  = isset( $input['date_from'] ) ? $this->avcf_clean_date( $input['date_from'] ) : '';
                $to    = isset( $input['date_to'] ) ? $this->avcf_clean_date( $input['date_to'] ) : '';
                $confirm = ! empty( $input['confirm'] );

                // Ambiguity guard: by name, no theme, present under multiple themes.
                if ( $name !== '' && $theme === '' ) {
                    $name_matches = $this->avcf_collect_backups( '', $name, '', '', '' );
                    $themes_seen  = [];
                    foreach ( $name_matches as $m ) {
                        $themes_seen[ $m['theme'] ] = true;
                    }
                    if ( count( $themes_seen ) > 1 ) {
                        return [
                            'status'           => 'needs_theme',
                            'deleted'          => 0,
                            'matched'          => count( $name_matches ),
                            'needs_theme'      => true,
                            'candidate_themes' => array_keys( $themes_seen ),
                            'message'          => sprintf(
                                '"%s" has backups under multiple themes (%s). Re-call with "theme" set to the one you mean.',
                                $name,
                                implode( ', ', array_keys( $themes_seen ) )
                            ),
                        ];
                    }
                }

                $matches = $this->avcf_collect_backups( $theme, $name, $date, $from, $to );

                if ( empty( $matches ) ) {
                    return [
                        'status'  => 'empty',
                        'deleted' => 0,
                        'matched' => 0,
                        'message' => 'Nothing matched; no backups deleted.',
                    ];
                }

                $by_theme = [];
                foreach ( $matches as $m ) {
                    $by_theme[ $m['theme'] ] = ( isset( $by_theme[ $m['theme'] ] ) ? $by_theme[ $m['theme'] ] : 0 ) + 1;
                }

                if ( ! $confirm ) {
                    $parts = [];
                    foreach ( $by_theme as $t => $n ) {
                        $parts[] = sprintf( '%d in %s', $n, $t );
                    }
                    return [
                        'status'   => 'preview',
                        'deleted'  => 0,
                        'matched'  => count( $matches ),
                        'by_theme' => $by_theme,
                        'message'  => sprintf(
                            'You are about to delete %d backup file(s): %s. Re-call with confirm=true to proceed.',
                            count( $matches ),
                            implode( ', ', $parts )
                        ),
                    ];
                }

                $fs = $this->avcf_fs();
                if ( ! $fs ) {
                    return [ 'status' => 'error', 'deleted' => 0, 'matched' => count( $matches ), 'message' => 'Filesystem unavailable.' ];
                }
                $root    = $this->avcf_backups_root();
                $deleted = 0;
                foreach ( $matches as $m ) {
                    $abs = $root . '/' . $m['backup_ref'];
                    // Defence in depth: never act outside the backups root.
                    if ( strpos( $abs, $root . '/' ) !== 0 ) {
                        continue;
                    }
                    if ( $fs->exists( $abs ) && $fs->delete( $abs, false, 'f' ) ) {
                        $deleted++;
                    }
                }

                return [
                    'status'   => 'deleted',
                    'deleted'  => $deleted,
                    'matched'  => count( $matches ),
                    'by_theme' => $by_theme,
                    'message'  => sprintf( 'Deleted %d of %d matched backup file(s).', $deleted, count( $matches ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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

        // ---- duplicate-theme-file ----
        wp_register_ability( 'atarim/duplicate-theme-file', [
            'label'               => 'Duplicate Theme File',
            'description'         => 'Creates a copy of an existing theme file in the same directory as the original, named "<name>-bkpN.<ext>" (bkp1, bkp2, ... using the next free number). This is a standalone duplicate action, independent of the automatic edit backups that live under uploads. Restricted to the editable text-file allow-list (binary files are not copied) and blocked when file editing is disabled (DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS). Defaults to the active theme. Returns the new theme-relative path and filename.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'path'  => [
                        'type'        => 'string',
                        'description' => 'Theme-relative path of the existing file to duplicate.',
                        'minLength'   => 1,
                    ],
                    'theme' => [
                        'type'        => 'string',
                        'description' => 'Stylesheet slug. Omit for the active theme.',
                    ],
                ],
                'required'             => [ 'path' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'theme'    => [ 'type' => 'string' ],
                    'source'   => [ 'type' => 'string' ],
                    'path'     => [ 'type' => 'string' ],
                    'filename' => [ 'type' => 'string' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                // Blocked when file editing/mods are disabled.
                if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
                    return [ 'success' => false, 'message' => 'Theme file editing is disabled on this site (DISALLOW_FILE_EDIT).' ];
                }

                $resolved = $this->avcf_resolve_theme( isset( $input['theme'] ) ? $input['theme'] : '' );
                if ( ! empty( $resolved['error'] ) ) {
                    return [ 'success' => false, 'message' => $resolved['error'] ];
                }
                $stylesheet = $resolved['stylesheet'];

                $loc = $this->avcf_locate_editable( $stylesheet, isset( $input['path'] ) ? $input['path'] : '' );
                if ( ! empty( $loc['error'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'message' => $loc['error'] ];
                }

                $fs = $this->avcf_fs();
                if ( ! $fs || ! $fs->exists( $loc['abs'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'File does not exist; nothing to duplicate.' ];
                }
                if ( $fs->is_dir( $loc['abs'] ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $loc['rel'], 'message' => 'Path is a directory; only files can be duplicated.' ];
                }

                $theme_dir = $this->avcf_theme_dir( $stylesheet );
                if ( $theme_dir === '' ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'message' => 'Theme directory not found.' ];
                }

                // Split source relative path into dir + base + ext.
                $rel        = $loc['rel'];
                $dir_part   = trim( str_replace( '\\', '/', dirname( $rel ) ), '/' );
                $dir_part   = ( $dir_part === '' || $dir_part === '.' ) ? '' : $dir_part;
                $filename   = basename( $rel );
                $ext        = pathinfo( $filename, PATHINFO_EXTENSION );
                $base       = ( '' !== $ext ) ? substr( $filename, 0, - ( strlen( $ext ) + 1 ) ) : $filename;
                $ext_suffix = ( '' !== $ext ) ? '.' . $ext : '';

                // Find the next free "-bkpN" sibling in the same directory.
                $new_rel  = '';
                $new_abs  = '';
                $new_name = '';
                for ( $n = 1; $n <= 1000; $n++ ) {
                    $candidate_name = $base . '-bkp' . $n . $ext_suffix;
                    $candidate_rel  = ( '' !== $dir_part ) ? $dir_part . '/' . $candidate_name : $candidate_name;
                    $candidate_abs  = $this->avcf_resolve_within( $theme_dir, $candidate_rel );
                    if ( false === $candidate_abs ) {
                        return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Computed duplicate path escapes the theme directory and was rejected.' ];
                    }
                    if ( ! $fs->exists( $candidate_abs ) ) {
                        $new_rel  = $candidate_rel;
                        $new_abs  = $candidate_abs;
                        $new_name = $candidate_name;
                        break;
                    }
                }
                if ( '' === $new_rel ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Could not find a free -bkpN name (too many duplicates).' ];
                }

                $contents = $fs->get_contents( $loc['abs'] );
                if ( false === $contents ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Could not read the source file.' ];
                }

                if ( ! $fs->put_contents( $new_abs, $contents, FS_CHMOD_FILE ) ) {
                    return [ 'success' => false, 'theme' => $stylesheet, 'path' => $rel, 'message' => 'Write failed (filesystem permissions); duplicate not created.' ];
                }

                return [
                    'success'  => true,
                    'theme'    => $stylesheet,
                    'source'   => $rel,
                    'path'     => $new_rel,
                    'filename' => $new_name,
                    'message'  => sprintf( 'Duplicated "%s" to "%s".', $rel, $new_rel ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_themes' );
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
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * The editable text-file extension allow-list. This is the hard security
     * boundary: only these types are ever listed, staged, or written. Binaries
     * (images, fonts) are never touched. Filterable for site-specific needs.
     *
     * @return string[]
     */
    private function avcf_allowed_extensions() {
        $default = [ 'php', 'css', 'js', 'html', 'json', 'txt', 'md', 'po', 'pot' ];
        $exts    = apply_filters( 'avcf_theme_file_extensions', $default );
        $out     = [];
        foreach ( (array) $exts as $e ) {
            $e = strtolower( trim( (string) $e, ". \t\n" ) );
            if ( $e !== '' ) {
                $out[ $e ] = true;
            }
        }
        return array_keys( $out );
    }

    /**
     * Effective extensions for a listing: the requested set narrowed to the
     * allow-list. An empty/absent request means "all allowed".
     *
     * @param mixed $requested
     * @return string[]
     */
    private function avcf_effective_extensions( $requested ) {
        $allowed = $this->avcf_allowed_extensions();
        if ( ! is_array( $requested ) || empty( $requested ) ) {
            return $allowed;
        }
        $req = [];
        foreach ( $requested as $e ) {
            $req[] = strtolower( trim( (string) $e, ". \t\n" ) );
        }
        $narrowed = array_values( array_intersect( $allowed, $req ) );
        return empty( $narrowed ) ? $allowed : $narrowed;
    }

    /**
     * Resolve which theme to operate on.
     *
     * @param string $requested  Stylesheet slug, or '' for the active theme.
     * @return array{stylesheet?:string,parent?:string,role?:string,error?:string}
     */
    private function avcf_resolve_theme( $requested ) {
        $requested = sanitize_key( (string) $requested );

        if ( $requested === '' ) {
            $stylesheet = get_stylesheet();
        } else {
            $stylesheet = $requested;
        }

        $theme = wp_get_theme( $stylesheet );
        if ( ! $theme->exists() ) {
            return [ 'error' => sprintf( 'Theme "%s" is not installed.', $stylesheet ) ];
        }

        $parent = ( $theme->parent() !== false ) ? $theme->parent()->get_stylesheet() : '';
        $role   = ( $parent !== '' ) ? 'child' : 'theme';

        return [
            'stylesheet' => $stylesheet,
            'parent'     => $parent,
            'role'       => $role,
        ];
    }

    /**
     * Absolute, real path to a theme directory, or '' if it can't be resolved.
     *
     * @param string $stylesheet
     * @return string
     */
    private function avcf_theme_dir( $stylesheet ) {
        $stylesheet = sanitize_key( (string) $stylesheet );
        if ( $stylesheet === '' ) {
            return '';
        }
        $root = get_theme_root( $stylesheet );
        $dir  = realpath( $root . '/' . $stylesheet );
        if ( $dir === false || ! is_dir( $dir ) ) {
            return '';
        }
        return rtrim( $dir, '/' );
    }

    /**
     * Resolve and validate an editable file path inside a theme.
     *
     * @param string $stylesheet
     * @param string $rel
     * @return array{abs?:string,rel?:string,error?:string}
     */
    private function avcf_locate_editable( $stylesheet, $rel ) {
        $dir = $this->avcf_theme_dir( $stylesheet );
        if ( $dir === '' ) {
            return [ 'error' => sprintf( 'Theme "%s" directory not found.', $stylesheet ) ];
        }

        $clean = $this->avcf_clean_rel( $rel );
        if ( $clean === '' ) {
            return [ 'error' => 'A valid file path is required.' ];
        }

        $ext = strtolower( pathinfo( $clean, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $this->avcf_allowed_extensions(), true ) ) {
            return [ 'error' => sprintf( 'File type ".%s" is not editable.', $ext ) ];
        }

        $abs = $this->avcf_resolve_within( $dir, $clean );
        if ( $abs === false ) {
            return [ 'error' => 'Path escapes the theme directory and was rejected.' ];
        }

        return [ 'abs' => $abs, 'rel' => $clean ];
    }

    /**
     * Normalise a caller-supplied relative path: strip backslashes, leading
     * slashes, "." and ".." segments resolved, reject null bytes. Returns a
     * clean forward-slash relative path or ''.
     *
     * @param mixed $rel
     * @return string
     */
    private function avcf_clean_rel( $rel ) {
        $rel = str_replace( '\\', '/', (string) $rel );
        if ( strpos( $rel, "\0" ) !== false ) {
            return '';
        }
        $parts = [];
        foreach ( explode( '/', $rel ) as $seg ) {
            $seg = trim( $seg );
            if ( $seg === '' || $seg === '.' ) {
                continue;
            }
            if ( $seg === '..' ) {
                array_pop( $parts );
                continue;
            }
            $parts[] = $seg;
        }
        return implode( '/', $parts );
    }

    /**
     * Join a clean relative path onto a base directory, guaranteeing the
     * result stays within the base. Does not require the path to exist.
     *
     * @param string $base
     * @param string $rel  Already cleaned via avcf_clean_rel().
     * @return string|false
     */
    private function avcf_resolve_within( $base, $rel ) {
        $base = rtrim( $base, '/' );
        $rel  = $this->avcf_clean_rel( $rel );
        if ( $rel === '' ) {
            return false;
        }
        $abs = $base . '/' . $rel;
        if ( strpos( $abs, $base . '/' ) !== 0 ) {
            return false;
        }
        return $abs;
    }

    /**
     * Recursively scan a theme directory for files matching the allowed
     * extensions. Returns a map of relative-path => size.
     *
     * @param string   $dir
     * @param string[] $exts
     * @return array<string,int>
     */
    private function avcf_scan_files( $dir, $exts ) {
        $out = [];
        if ( ! is_dir( $dir ) ) {
            return $out;
        }
        $exts = array_flip( array_map( 'strtolower', $exts ) );

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } catch ( \Throwable $e ) {
            return $out;
        }

        $prefix = rtrim( $dir, '/' ) . '/';
        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $ext = strtolower( $file->getExtension() );
            if ( ! isset( $exts[ $ext ] ) ) {
                continue;
            }
            $path = $file->getPathname();
            if ( strpos( $path, $prefix ) !== 0 ) {
                continue;
            }
            $rel = substr( $path, strlen( $prefix ) );
            $out[ $rel ] = $file->getSize();
        }

        ksort( $out );
        return $out;
    }

    /**
     * Initialise and return the WP_Filesystem instance, or null on failure.
     *
     * @return WP_Filesystem_Base|null
     */
    private function avcf_fs() {
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if ( ! WP_Filesystem() ) {
                return null;
            }
        }
        return $wp_filesystem ? $wp_filesystem : null;
    }

    /**
     * Type-aware syntax gate. Returns null when content is acceptable to write,
     * or an error string when it should be rejected.
     *
     *   php  → token_get_all(..., TOKEN_PARSE) throws on a syntax error without
     *          executing the code; this is what prevents a fatal/white-screen.
     *   json → json_decode validity (e.g. guards a malformed theme.json).
     *   else → no syntax gate (css/js/html degrade rather than fatal).
     *
     * @param string $content
     * @param string $ext
     * @return string|null
     */
    private function avcf_lint( $content, $ext ) {
        $ext = strtolower( $ext );

        if ( $ext === 'php' ) {
            if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
                return null; // tokenizer unavailable — skip rather than block.
            }
            try {
                token_get_all( $content, TOKEN_PARSE );
            } catch ( \ParseError $e ) {
                return 'PHP syntax error: ' . $e->getMessage();
            } catch ( \CompileError $e ) {
                return 'PHP compile error: ' . $e->getMessage();
            } catch ( \Throwable $e ) {
                return 'PHP parse error: ' . $e->getMessage();
            }
            return null;
        }

        if ( $ext === 'json' ) {
            json_decode( $content );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return 'Invalid JSON: ' . json_last_error_msg();
            }
            return null;
        }

        return null;
    }

    /**
     * Absolute path to the theme-backups root:
     * wp-content/uploads/atarim-backup/theme
     *
     * @return string
     */
    private function avcf_backups_root() {
        $up = wp_upload_dir();
        return rtrim( $up['basedir'], '/' ) . '/atarim-backup/' . self::BACKUP_DOMAIN;
    }

    /**
     * Copy the current on-disk file to a timestamped backup that mirrors the
     * source structure: <root>/<stylesheet>/<relative-dir>/<stem>-<ts>.<ext>
     *
     * @param string $stylesheet
     * @param string $rel       Theme-relative path of the file being backed up.
     * @param string $abs_file  Absolute path of the file being backed up.
     * @return array{backup?:string,error?:string}
     */
    private function avcf_make_backup( $stylesheet, $rel, $abs_file ) {
        $fs = $this->avcf_fs();
        if ( ! $fs ) {
            return [ 'error' => 'Filesystem unavailable.' ];
        }
        if ( ! $fs->exists( $abs_file ) ) {
            return [ 'error' => 'Original file not found.' ];
        }

        $contents = $fs->get_contents( $abs_file );
        if ( $contents === false ) {
            return [ 'error' => 'Could not read original file.' ];
        }

        $ext  = pathinfo( $rel, PATHINFO_EXTENSION );
        $stem = pathinfo( $rel, PATHINFO_FILENAME );
        $dir  = pathinfo( $rel, PATHINFO_DIRNAME );
        $dir  = ( $dir === '.' || $dir === '' ) ? '' : $dir;

        $ts   = gmdate( 'Ymd-His' );
        $name = $stem . '-' . $ts . ( $ext !== '' ? '.' . $ext : '' );

        $rel_dir   = sanitize_key( $stylesheet ) . ( $dir !== '' ? '/' . $dir : '' );
        $root      = $this->avcf_backups_root();
        $target_dir = $root . '/' . $rel_dir;

        if ( ! $fs->is_dir( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
        }

        $backup_ref = $rel_dir . '/' . $name;
        $target     = $root . '/' . $backup_ref;

        if ( ! $fs->put_contents( $target, $contents, FS_CHMOD_FILE ) ) {
            return [ 'error' => 'Could not write backup file.' ];
        }

        return [ 'backup' => $backup_ref ];
    }

    /**
     * Enumerate backups under the theme-backups root, parsing each filename
     * back to its original theme + path + timestamp, with optional filters.
     *
     * @param string $theme_filter  Stylesheet slug, or '' for any.
     * @param string $path_filter   Original relative path, or '' for any.
     * @param string $date          YYYY-MM-DD single day, or ''.
     * @param string $from          YYYY-MM-DD range start, or ''.
     * @param string $to            YYYY-MM-DD range end, or ''.
     * @return array<int,array{theme:string,path:string,timestamp:string,backup_ref:string,size:int}>
     */
    private function avcf_collect_backups( $theme_filter, $path_filter, $date, $from, $to ) {
        $root = $this->avcf_backups_root();
        $out  = [];
        if ( ! is_dir( $root ) ) {
            return $out;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } catch ( \Throwable $e ) {
            return $out;
        }

        $prefix = rtrim( $root, '/' ) . '/';
        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $abs = $file->getPathname();
            if ( strpos( $abs, $prefix ) !== 0 ) {
                continue;
            }
            $backup_ref = substr( $abs, strlen( $prefix ) ); // <stylesheet>/<dir>/<stem>-<ts>.<ext>

            $segments = explode( '/', $backup_ref );
            if ( count( $segments ) < 1 ) {
                continue;
            }
            $theme    = array_shift( $segments );
            $filename = array_pop( $segments );
            $sub_dir  = implode( '/', $segments );

            $parsed = $this->avcf_parse_backup_name( $filename );
            if ( $parsed === null ) {
                continue;
            }

            $orig_rel = ( $sub_dir !== '' ? $sub_dir . '/' : '' ) . $parsed['name'];

            if ( $theme_filter !== '' && $theme !== $theme_filter ) {
                continue;
            }
            if ( $path_filter !== '' && $orig_rel !== $path_filter ) {
                continue;
            }

            $day = substr( $parsed['timestamp'], 0, 4 ) . '-' . substr( $parsed['timestamp'], 4, 2 ) . '-' . substr( $parsed['timestamp'], 6, 2 );
            if ( $date !== '' && $day !== $date ) {
                continue;
            }
            if ( $from !== '' && $day < $from ) {
                continue;
            }
            if ( $to !== '' && $day > $to ) {
                continue;
            }

            $out[] = [
                'theme'      => $theme,
                'path'       => $orig_rel,
                'timestamp'  => $parsed['timestamp'],
                'backup_ref' => $backup_ref,
                'size'       => (int) $file->getSize(),
            ];
        }

        return $out;
    }

    /**
     * Parse a backup filename "<stem>-YYYYMMDD-HHMMSS.<ext>" back to the
     * original "<stem>.<ext>" and its timestamp. Tolerates hyphenated stems
     * because the trailing timestamp is fixed-width. Returns null if the name
     * doesn't carry a recognisable timestamp.
     *
     * @param string $filename
     * @return array{name:string,timestamp:string}|null
     */
    private function avcf_parse_backup_name( $filename ) {
        // With extension: stem-YYYYMMDD-HHMMSS.ext
        if ( preg_match( '/^(.+)-(\d{8}-\d{6})\.([A-Za-z0-9]+)$/', $filename, $m ) ) {
            return [ 'name' => $m[1] . '.' . $m[3], 'timestamp' => $m[2] ];
        }
        // Without extension: stem-YYYYMMDD-HHMMSS
        if ( preg_match( '/^(.+)-(\d{8}-\d{6})$/', $filename, $m ) ) {
            return [ 'name' => $m[1], 'timestamp' => $m[2] ];
        }
        return null;
    }

    /**
     * Sanitise a YYYY-MM-DD date filter to itself, or '' if malformed.
     *
     * @param mixed $date
     * @return string
     */
    private function avcf_clean_date( $date ) {
        $date = trim( (string) $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }

    /**
     * Request a presigned upload from the Atarim endpoint and PUT the contents
     * to the returned signed URL.
     *
     * The PUT MUST send `x-amz-acl: public-read` because the signed URL signs
     * host;x-amz-acl — without it S3 rejects the upload with a signature error.
     *
     * Auth: currently called without a token (per the agreed "keep as is for
     * now" decision). To add auth later, pass the token as the 4th argument to
     * avcf_make_api_call() — this is the only line that needs to change.
     *
     * @param string $filename
     * @param string $contents
     * @return array{key?:string,error?:string}
     */
    private function avcf_presigned_upload( $filename, $contents ) {
        $resp = $this->function->avcf_make_api_call(
            AVCF_CRM_API . 'v1/uploads/presigned',
            [ 'files' => [ [ 'name' => $filename, 'visibility' => 'public' ] ] ],
            '',
            '',
            'POST'
        );

        $code = isset( $resp['status_code'] ) ? (int) $resp['status_code'] : 0;
        if ( $code < 200 || $code >= 300 ) {
            return [ 'error' => 'Presigned request failed (HTTP ' . $code . ').' ];
        }

        $data    = isset( $resp['data'] ) ? $resp['data'] : [];
        $extract = $this->avcf_extract_presigned( $data );
        if ( $extract['url'] === '' || $extract['key'] === '' ) {
            return [ 'error' => 'Could not read signed URL / key from presigned response.' ];
        }

        $put = wp_remote_request( $extract['url'], [
            'method'  => 'PUT',
            'headers' => [ 'x-amz-acl' => 'public-read' ],
            'body'    => $contents,
            'timeout' => 60,
        ] );

        if ( is_wp_error( $put ) ) {
            return [ 'error' => 'S3 upload failed: ' . $put->get_error_message() ];
        }
        $put_code = (int) wp_remote_retrieve_response_code( $put );
        if ( $put_code < 200 || $put_code >= 300 ) {
            return [ 'error' => 'S3 upload rejected (HTTP ' . $put_code . ').' ];
        }

        return [ 'key' => $extract['key'] ];
    }

    /**
     * Defensively extract the signed URL and S3 key from the presigned
     * response, probing common key aliases and an optional "files" wrapper.
     *
     * NOTE: this is the one spot to confirm against a real response from the
     * endpoint — adjust the alias lists if the actual field names differ.
     *
     * @param mixed $data
     * @return array{url:string,key:string}
     */
    private function avcf_extract_presigned( $data ) {
        $entry = $data;
        if ( is_array( $data ) ) {
            if ( isset( $data['result'][0] ) && is_array( $data['result'][0] ) ) {
                $entry = $data['result'][0];
            } elseif ( isset( $data['files'][0] ) && is_array( $data['files'][0] ) ) {
                $entry = $data['files'][0];
            } elseif ( isset( $data['data']['result'][0] ) && is_array( $data['data']['result'][0] ) ) {
                $entry = $data['data']['result'][0];
            } elseif ( isset( $data['data']['files'][0] ) && is_array( $data['data']['files'][0] ) ) {
                $entry = $data['data']['files'][0];
            } elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
                $entry = $data['data'];
            }
        }

        $url = '';
        $key = '';
        if ( is_array( $entry ) ) {
            foreach ( [ 'url', 'signed_url', 'presigned_url', 'upload_url', 'signedUrl', 'presignedUrl' ] as $k ) {
                if ( ! empty( $entry[ $k ] ) ) {
                    $url = (string) $entry[ $k ];
                    break;
                }
            }
            foreach ( [ 'key', 's3_key', 'path', 'object_key', 's3Key', 'objectKey' ] as $k ) {
                if ( ! empty( $entry[ $k ] ) ) {
                    $key = (string) $entry[ $k ];
                    break;
                }
            }
        }

        return [ 'url' => $url, 'key' => $key ];
    }

    /**
     * Download the body of a URL (the edited file content from S3).
     *
     * @param string $url
     * @return array{body?:string,error?:string}
     */
    /**
     * Resolve the new file content from either a URL (server-side fetch, SSRF-checked)
     * or base64 data — mirrors upload-media's source/source_url/source_data contract.
     *
     * @param string $source 'url' | 'base64'
     * @param array  $input
     * @return array{ body?: string, error?: string }
     */
    private function avcf_fetch_source( $source, $input ) {
        if ( $source === 'url' ) {
            $url = isset( $input['source_url'] ) ? esc_url_raw( trim( (string) $input['source_url'] ) ) : '';
            if ( $url === '' ) {
                return [ 'error' => 'source_url is required when source is "url".' ];
            }
            $ssrf = $this->avcf_check_url_safety( $url );
            if ( $ssrf !== null ) {
                return [ 'error' => $ssrf ];
            }
            return $this->avcf_download( $url );
        }

        if ( $source === 'base64' ) {
            $data = isset( $input['source_data'] ) ? (string) $input['source_data'] : '';
            if ( $data === '' ) {
                return [ 'error' => 'source_data is required when source is "base64".' ];
            }
            // Strip a data: URL prefix defensively if the caller included one.
            if ( strpos( $data, 'data:' ) === 0 ) {
                $comma = strpos( $data, ',' );
                if ( $comma !== false ) {
                    $data = substr( $data, $comma + 1 );
                }
            }
            $decoded = base64_decode( $data, true );
            if ( $decoded === false ) {
                return [ 'error' => 'source_data is not valid base64.' ];
            }
            return [ 'body' => $decoded ];
        }

        return [ 'error' => sprintf( 'Unknown source "%s".', $source ) ];
    }

    /**
     * Validate a URL for safe outbound fetching (SSRF protection). Mirrors the
     * media cluster's check. Returns null when safe; an error string otherwise.
     * Blocks non-http(s) schemes, localhost, and hosts resolving to private,
     * loopback, or link-local ranges (incl. the 169.254.169.254 metadata IP).
     *
     * @param string $url
     * @return string|null
     */
    private function avcf_check_url_safety( $url ) {
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return 'Invalid URL — could not parse scheme and host.';
        }

        $scheme = strtolower( $parsed['scheme'] );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            return sprintf( 'URL scheme "%s" is not allowed — only http and https are supported.', $scheme );
        }

        $host = strtolower( $parsed['host'] );
        if ( in_array( $host, [ 'localhost', 'localhost.localdomain' ], true ) ) {
            return 'Hostname "localhost" is not allowed.';
        }

        $ips = @gethostbynamel( $host );
        if ( ! is_array( $ips ) ) {
            if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
                $ips = [ $host ];
            } else {
                return sprintf( 'Could not resolve host "%s".', $host );
            }
        }

        foreach ( $ips as $ip ) {
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return sprintf( 'URL host resolves to a blocked address (%s — private, loopback, or link-local range).', $ip );
            }
            if ( $ip === '169.254.169.254' ) {
                return 'URL host resolves to a cloud metadata endpoint (169.254.169.254) — blocked.';
            }
        }

        return null;
    }

    private function avcf_download( $url ) {
        // redirection => 0: do not follow redirects, so a public URL cannot 3xx to an internal host after the SSRF check.
        $resp = wp_remote_get( $url, [ 'timeout' => 60, 'redirection' => 0 ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'error' => 'Download failed: ' . $resp->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return [ 'error' => 'Download rejected (HTTP ' . $code . ').' ];
        }
        return [ 'body' => wp_remote_retrieve_body( $resp ) ];
    }
}