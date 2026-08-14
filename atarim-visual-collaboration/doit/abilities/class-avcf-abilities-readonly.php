<?php
/**
 * Read-only floor abilities: filesystem read + read-only SQL.
 *
 * These grant an agent READ access to the WordPress install's files and
 * database. They are powerful (information disclosure risk), so:
 *   - Plugin-side they are guarded by manage_options, the secret protections
 *     below, ABSPATH containment and size/row caps. Opt-in / consent for
 *     invoking them is handled on the AGENT side (the MCP client prompts the
 *     operator), so they are not additionally blocklisted plugin-side.
 *   - Secrets are protected: the FS tool refuses wp-config.php / .env / key
 *     material; the SQL tool redacts credential columns (user_pass, etc.).
 *   - Reads are contained to ABSPATH (no traversal) and size-capped.
 *   - SQL is restricted to a single read-only statement.
 *
 * @package Atarim
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_ReadOnly {

    /** Max bytes read-file will return inline. Larger files return metadata only. */
    const MAX_READ_BYTES = 1048576; // 1 MB

    /** Max rows run-select-query will return. */
    const MAX_ROWS = 500;

    public function register() {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        $this->register_read_file();
        $this->register_list_files();
        $this->register_run_select_query();
    }

    /* --------------------------------------------------------------------- */
    /* Abilities                                                             */
    /* --------------------------------------------------------------------- */

    private function register_read_file() {
        $self = $this;
        wp_register_ability( 'atarim/read-file', [
            'label'        => 'Read File (read-only floor)',
            'description'  => 'Read a single file from the WordPress installation for inspection. Paths are relative to the WordPress root (ABSPATH) and are realpath-contained — no traversal outside the install. Secret files (wp-config.php, .env, *.key/*.pem and similar credential material) are refused. Text is returned inline (UTF-8); binary is returned base64-encoded with is_binary=true. Files larger than 1 MB return metadata only (size + a note), not content. Read-only.',
            'category'     => 'atarim',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'path' => [ 'type' => 'string', 'description' => 'File path relative to the WordPress root, e.g. "wp-content/themes/foo/style.css".' ],
                ],
                'required'             => [ 'path' ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'path'           => [ 'type' => 'string' ],
                    'bytes'          => [ 'type' => 'integer' ],
                    'is_binary'      => [ 'type' => 'boolean' ],
                    'truncated'      => [ 'type' => 'boolean' ],
                    'sha1'           => [ 'type' => 'string' ],
                    'content'        => [ 'type' => 'string' ],
                    'content_base64' => [ 'type' => 'string' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $rel = isset( $input['path'] ) ? (string) $input['path'] : '';
                $loc = $self->resolve_within_abspath( $rel );
                if ( isset( $loc['error'] ) ) {
                    return [ 'success' => false, 'message' => $loc['error'] ];
                }
                $abs = $loc['abs'];
                if ( is_dir( $abs ) ) {
                    return [ 'success' => false, 'path' => $loc['rel'], 'message' => 'That path is a directory. Use list-files to list its contents.' ];
                }
                if ( $self->is_secret_file( $abs ) ) {
                    return [ 'success' => false, 'path' => $loc['rel'], 'message' => 'Refused: this file may contain credentials or secrets and is not readable through this tool.' ];
                }
                $size = @filesize( $abs );
                if ( false === $size ) {
                    return [ 'success' => false, 'path' => $loc['rel'], 'message' => 'Could not stat the file.' ];
                }
                if ( $size > $self::MAX_READ_BYTES ) {
                    return [
                        'success'   => true,
                        'path'      => $loc['rel'],
                        'bytes'     => (int) $size,
                        'truncated' => true,
                        'message'   => sprintf( 'File is %d bytes, larger than the %d-byte inline limit; content not returned. Read a smaller file or a specific part another way.', (int) $size, $self::MAX_READ_BYTES ),
                    ];
                }
                $content = @file_get_contents( $abs );
                if ( false === $content ) {
                    return [ 'success' => false, 'path' => $loc['rel'], 'message' => 'Could not read the file (permissions?).' ];
                }
                $is_binary = ( strpos( $content, "\0" ) !== false ) || ! mb_check_encoding( $content, 'UTF-8' );
                $out = [
                    'success'   => true,
                    'path'      => $loc['rel'],
                    'bytes'     => strlen( $content ),
                    'is_binary' => $is_binary,
                    'truncated' => false,
                    'sha1'      => sha1( $content ),
                ];
                if ( $is_binary ) {
                    $out['content_base64'] = base64_encode( $content );
                    $out['message']        = 'Binary file returned base64-encoded.';
                } else {
                    $out['content'] = $content;
                    $out['message'] = sprintf( 'Read %d bytes from "%s".', strlen( $content ), $loc['rel'] );
                }
                return $out;
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $self->ro_meta(),
        ] );
    }

    private function register_list_files() {
        $self = $this;
        wp_register_ability( 'atarim/list-files', [
            'label'        => 'List Files (read-only floor)',
            'description'  => 'List the immediate contents of a directory within the WordPress installation. Path is relative to the WordPress root (ABSPATH), realpath-contained (no traversal), and defaults to the root. Each entry reports name, type (file|dir), size, and is_secret (true for files read-file will refuse, e.g. wp-config.php). Non-recursive — drill down one directory at a time. Read-only.',
            'category'     => 'atarim',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'path' => [ 'type' => 'string', 'description' => 'Directory relative to the WordPress root. Omit for the root.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'path'    => [ 'type' => 'string' ],
                    'entries' => [ 'type' => 'array' ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $rel = isset( $input['path'] ) ? (string) $input['path'] : '';
                $loc = $self->resolve_within_abspath( $rel === '' ? '.' : $rel );
                if ( isset( $loc['error'] ) ) {
                    return [ 'success' => false, 'message' => $loc['error'] ];
                }
                $abs = $loc['abs'];
                if ( ! is_dir( $abs ) ) {
                    return [ 'success' => false, 'path' => $loc['rel'], 'message' => 'That path is not a directory. Use read-file for files.' ];
                }
                $items = @scandir( $abs );
                if ( false === $items ) {
                    return [ 'success' => false, 'path' => $loc['rel'], 'message' => 'Could not read the directory.' ];
                }
                $entries = [];
                foreach ( $items as $name ) {
                    if ( '.' === $name || '..' === $name ) {
                        continue;
                    }
                    $child = $abs . '/' . $name;
                    $is_dir = is_dir( $child );
                    $entries[] = [
                        'name'      => $name,
                        'type'      => $is_dir ? 'dir' : 'file',
                        'size'      => $is_dir ? null : (int) @filesize( $child ),
                        'is_secret' => $is_dir ? false : $self->is_secret_file( $child ),
                    ];
                }
                return [
                    'success' => true,
                    'path'    => $loc['rel'],
                    'entries' => $entries,
                    'message' => sprintf( '%d entr%s in "%s".', count( $entries ), ( 1 === count( $entries ) ? 'y' : 'ies' ), $loc['rel'] ),
                ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $self->ro_meta(),
        ] );
    }

    private function register_run_select_query() {
        $self = $this;
        wp_register_ability( 'atarim/run-select-query', [
            'label'        => 'Run Read-only SQL Query (read-only floor)',
            'description'  => 'Run a SINGLE read-only SQL statement against the WordPress database and return the rows. Only one statement is allowed and it must begin with SELECT, SHOW, DESCRIBE, EXPLAIN or WITH; stacked statements, INTO OUTFILE/DUMPFILE and LOAD_FILE are rejected. Credential columns (user_pass, user_activation_key) and auth meta (session_tokens, application passwords) are redacted in the results. At most 500 rows are returned (truncated=true if more). NOTE: read-only is enforced by statement validation, which is defense-in-depth, not a hard database guarantee.',
            'category'     => 'atarim',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => [ 'type' => 'string', 'description' => 'A single read-only SQL statement (SELECT/SHOW/DESCRIBE/EXPLAIN/WITH). Use the site table prefix as-is (e.g. wp_posts).' ],
                ],
                'required'             => [ 'query' ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'columns'   => [ 'type' => 'array' ],
                    'rows'      => [ 'type' => 'array' ],
                    'row_count' => [ 'type' => 'integer' ],
                    'truncated' => [ 'type' => 'boolean' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
                $guard = $self->validate_select( $query );
                if ( isset( $guard['error'] ) ) {
                    return [ 'success' => false, 'message' => $guard['error'] ];
                }
                $sql = $guard['sql'];

                $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
                if ( $wpdb->last_error ) {
                    return [ 'success' => false, 'message' => 'SQL error: ' . $wpdb->last_error ];
                }
                if ( ! is_array( $rows ) ) {
                    $rows = [];
                }
                $truncated = false;
                if ( count( $rows ) > $self::MAX_ROWS ) {
                    $rows      = array_slice( $rows, 0, $self::MAX_ROWS );
                    $truncated = true;
                }
                $rows    = $self->redact_rows( $rows );
                $columns = ! empty( $rows ) ? array_keys( $rows[0] ) : [];

                return [
                    'success'   => true,
                    'columns'   => $columns,
                    'rows'      => $rows,
                    'row_count' => count( $rows ),
                    'truncated' => $truncated,
                    'message'   => sprintf( '%d row(s) returned%s.', count( $rows ), $truncated ? sprintf( ' (capped at %d)', $self::MAX_ROWS ) : '' ),
                ];
            },
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'meta' => $self->ro_meta(),
        ] );
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                               */
    /* --------------------------------------------------------------------- */

    /** Read-only ability meta. */
    public function ro_meta() {
        return [
            'mcp'         => [ 'public' => true, 'type' => 'tool' ],
            'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ];
    }

    /**
     * Resolve an ABSPATH-relative path to a real, contained absolute path.
     * Returns [ 'abs' => ..., 'rel' => ... ] or [ 'error' => ... ].
     */
    public function resolve_within_abspath( $rel ) {
        $root = realpath( ABSPATH );
        if ( false === $root ) {
            return [ 'error' => 'Could not resolve the WordPress root.' ];
        }
        $root = rtrim( str_replace( '\\', '/', $root ), '/' );
        $rel  = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
        $rel  = preg_replace( '#/+#', '/', $rel );

        $candidate = ( '' === $rel || '.' === $rel ) ? $root : $root . '/' . $rel;
        $real      = realpath( $candidate );
        if ( false === $real ) {
            return [ 'error' => 'Path not found within the WordPress installation.' ];
        }
        $real = str_replace( '\\', '/', $real );
        if ( $real !== $root && strpos( $real, $root . '/' ) !== 0 ) {
            return [ 'error' => 'Refused: path resolves outside the WordPress installation.' ];
        }
        $rel_out = ( $real === $root ) ? '.' : ltrim( substr( $real, strlen( $root ) ), '/' );
        return [ 'abs' => $real, 'rel' => $rel_out ];
    }

    /** Whether a file is credential/secret material that must not be read. */
    public function is_secret_file( $abs ) {
        $base = strtolower( basename( $abs ) );
        $ext  = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );

        $secret_names = [
            'wp-config.php', 'wp-config-local.php', 'wp-config-sample.php',
            '.htpasswd', 'auth.json', '.netrc', 'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519',
        ];
        if ( in_array( $base, $secret_names, true ) ) {
            return true;
        }
        if ( in_array( $ext, [ 'key', 'pem', 'p12', 'pfx', 'crt', 'cer', 'ppk', 'keystore', 'jks' ], true ) ) {
            return true;
        }
        // .env and its variants (.env.local, .env.production, etc.)
        if ( 0 === strpos( $base, '.env' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Validate a single read-only statement. Returns [ 'sql' => ... ] or
     * [ 'error' => ... ]. A single statement beginning with a read verb cannot
     * mutate data; we additionally reject stacked statements and file access.
     */
    public function validate_select( $query ) {
        $query = trim( (string) $query );
        if ( '' === $query ) {
            return [ 'error' => 'query is required.' ];
        }
        // Strip a single trailing semicolon; anything after one is a stacked statement.
        $query = rtrim( $query );
        if ( ';' === substr( $query, -1 ) ) {
            $query = rtrim( substr( $query, 0, -1 ) );
        }
        if ( preg_match( '/;\s*\S/', $query ) ) {
            return [ 'error' => 'Only a single statement is allowed (stacked statements are rejected).' ];
        }
        if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $query ) ) {
            return [ 'error' => 'Only read-only statements are allowed: begin with SELECT, SHOW, DESCRIBE, EXPLAIN or WITH.' ];
        }
        if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $query ) || preg_match( '/\bLOAD_FILE\s*\(/i', $query ) ) {
            return [ 'error' => 'File access via SQL (INTO OUTFILE/DUMPFILE, LOAD_FILE) is not allowed.' ];
        }
        return [ 'sql' => $query ];
    }

    /** Redact credential columns / auth meta in query results. */
    public function redact_rows( $rows ) {
        $secret_cols = [ 'user_pass', 'user_activation_key' ];
        $secret_meta = [ 'session_tokens', '_application_passwords' ];
        foreach ( $rows as &$row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            foreach ( $row as $col => $val ) {
                if ( in_array( strtolower( (string) $col ), $secret_cols, true ) ) {
                    $row[ $col ] = '[REDACTED]';
                }
            }
            // usermeta-shaped rows: redact auth meta values by key.
            if ( isset( $row['meta_key'], $row['meta_value'] ) && in_array( $row['meta_key'], $secret_meta, true ) ) {
                $row['meta_value'] = '[REDACTED]';
            }
        }
        unset( $row );
        return $rows;
    }
}