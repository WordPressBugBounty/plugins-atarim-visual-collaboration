<?php
/**
 * Media library MCP abilities.
 *
 * Registers Atarim/* abilities for working with media attachments —
 * listing/searching, reading individual items with full metadata,
 * updating attachment fields, bulk alt-text editing, safe deletion with
 * in-use detection, uploading/replacing files, and core sub-size
 * regeneration.
 *
 * Third-party image OPTIMIZER integration (ShortPixel / Smush / EWWW /
 * Imagify, i.e. driving an installed compressor) is intentionally NOT in
 * this cluster — that belongs in a third-party/{plugin}/ integration
 * module. Core, plugin-free sub-size regeneration (with an optional
 * quality re-encode of the derivatives) DOES live here, as regenerate-image.
 *
 * Exposed abilities:
 *   atarim/list-media             Media with filters (mime, missing_alt, attached, search, date range).
 *   atarim/get-media              Single attachment with dimensions, on-demand file size.
 *   atarim/update-media           Update alt_text, title, caption, description.
 *   atarim/bulk-update-alt-text   Array of {id, alt_text} tuples with per-id results.
 *   atarim/delete-media           Single attachment delete with in-use safety guard.
 *   atarim/bulk-delete-media      Bulk delete with dry_run default and confirm_in_use guard.
 *   atarim/upload-media           Sideload a file into the media library.
 *   atarim/replace-media-file     Replace an attachment's underlying file.
 *   atarim/replace-media-in-content  Swap one attachment for another across content.
 *   atarim/regenerate-image       Regenerate sub-sizes (single or batch ≤25), optional quality re-encode of derivatives; original untouched.
 *
 * Note: abilities are exposed automatically — class-avcf-mcp.php builds the
 * server tool list dynamically from wp_get_abilities(), including every
 * ability whose meta has mcp.public = true and mcp.type = 'tool'. No manual
 * $tools entry is required. (Exposure can still be suppressed via the
 * avcf_mcp_blocked_abilities blocklist.)
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Media extends AVCF_Abilities_Base {

    public function register() {

        // ---- list-media ----
        wp_register_ability( 'atarim/list-media', [
            'label'               => 'List Media',
            'description'         => 'Returns attachment items with filters: MIME type (image, image/jpeg, video, etc.), missing_alt (audit gap), attached/unattached, free-text search, upload date range, and pagination. By default the file_size field is NOT populated (reading from disk per item is slow on large libraries) — pass include_file_size: true to opt in. Missing files are flagged with file_missing: true.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'mime_type' => [
                        'type'        => 'string',
                        'description' => 'Filter by MIME type or prefix. "image" matches all images; "image/jpeg" matches only JPEGs; "video" all videos; "application/pdf" specific PDFs.',
                        'minLength'   => 1,
                    ],
                    'attached' => [
                        'type'        => 'string',
                        'description' => '"yes" returns only attachments linked to a post (post_parent > 0). "no" returns only unattached items. Omit for both.',
                        'enum'        => [ 'yes', 'no' ],
                    ],
                    'missing_alt' => [
                        'type'        => 'boolean',
                        'description' => 'When true, return only image attachments whose _wp_attachment_image_alt meta is empty or unset. Useful for accessibility audits. Ignored for non-image MIME types.',
                        'default'     => false,
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Search across title, file name, and alt text.',
                        'minLength'   => 1,
                    ],
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Items uploaded on or after this date. ISO 8601 or strtotime()-parseable.',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'Items uploaded on or before this date.',
                    ],
                    'author' => [
                        'type'        => 'integer',
                        'description' => 'Filter by uploader user ID.',
                        'minimum'     => 1,
                    ],
                    'orderby' => [
                        'type'        => 'string',
                        'enum'        => [ 'date', 'modified', 'title', 'ID' ],
                        'default'     => 'date',
                    ],
                    'order' => [
                        'type'        => 'string',
                        'enum'        => [ 'ASC', 'DESC' ],
                        'default'     => 'DESC',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max items. -1 for all (slow on big libraries). Defaults to 50.',
                        'default'     => 50,
                        'minimum'     => -1,
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Skip this many items (pagination).',
                        'default'     => 0,
                        'minimum'     => 0,
                    ],
                    'include_file_size' => [
                        'type'        => 'boolean',
                        'description' => 'Read each file size from disk. Adds one filesystem call per returned item. Defaults to false. Items whose file is missing on disk are flagged with file_missing: true.',
                        'default'     => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'    => [ 'type' => 'integer' ],
                    'returned' => [ 'type' => 'integer' ],
                    'offset'   => [ 'type' => 'integer' ],
                    'items'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'           => [ 'type' => 'integer' ],
                                'title'        => [ 'type' => 'string' ],
                                'slug'         => [ 'type' => 'string' ],
                                'mime_type'    => [ 'type' => 'string' ],
                                'url'          => [ 'type' => 'string' ],
                                'alt_text'     => [ 'type' => 'string' ],
                                'caption'      => [ 'type' => 'string' ],
                                'description'  => [ 'type' => 'string' ],
                                'attached_to'  => [ 'type' => 'integer' ],
                                'author'       => [ 'type' => 'integer' ],
                                'date'         => [ 'type' => 'string' ],
                                'modified'     => [ 'type' => 'string' ],
                                'width'        => [ 'type' => 'integer' ],
                                'height'       => [ 'type' => 'integer' ],
                                'file_size'    => [ 'type' => [ 'integer', 'null' ] ],
                                'file_missing' => [ 'type' => 'boolean' ],
                            ],
                        ],
                    ],
                ],
                'required' => [ 'total', 'returned', 'items' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

                $args = [
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => $limit,
                    'offset'         => $offset,
                    'orderby'        => isset( $input['orderby'] ) ? sanitize_key( $input['orderby'] ) : 'date',
                    'order'          => ( isset( $input['order'] ) && strtoupper( $input['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
                ];

                // MIME filter — WP_Query accepts a prefix like "image" or a full type.
                if ( ! empty( $input['mime_type'] ) ) {
                    $args['post_mime_type'] = (string) $input['mime_type'];
                }

                // Attached / unattached.
                if ( isset( $input['attached'] ) ) {
                    if ( $input['attached'] === 'no' ) {
                        $args['post_parent'] = 0;
                    } elseif ( $input['attached'] === 'yes' ) {
                        $args['post_parent__not_in'] = [ 0 ];
                    }
                }

                if ( isset( $input['author'] ) ) {
                    $args['author'] = (int) $input['author'];
                }

                if ( ! empty( $input['search'] ) ) {
                    $args['s'] = (string) $input['search'];
                }

                // Date range — WP_Query date_query.
                if ( ! empty( $input['date_from'] ) || ! empty( $input['date_to'] ) ) {
                    $date_query = [];
                    if ( ! empty( $input['date_from'] ) ) {
                        list( $after, , $err ) = $this->avcf_normalize_post_date( (string) $input['date_from'] );
                        if ( $err !== null ) {
                            return [ 'total' => 0, 'returned' => 0, 'offset' => $offset, 'items' => [], 'message' => 'date_from: ' . $err ];
                        }
                        $date_query['after'] = $after;
                    }
                    if ( ! empty( $input['date_to'] ) ) {
                        list( $before, , $err ) = $this->avcf_normalize_post_date( (string) $input['date_to'] );
                        if ( $err !== null ) {
                            return [ 'total' => 0, 'returned' => 0, 'offset' => $offset, 'items' => [], 'message' => 'date_to: ' . $err ];
                        }
                        $date_query['before'] = $before;
                    }
                    $date_query['inclusive'] = true;
                    $args['date_query']      = [ $date_query ];
                }

                // missing_alt — meta_query for empty/unset alt text. Only meaningful for images.
                $missing_alt = ! empty( $input['missing_alt'] );
                if ( $missing_alt ) {
                    $args['meta_query'] = [
                        'relation' => 'OR',
                        [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
                        [ 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ],
                    ];
                    // If the caller didn't already constrain to images, do it for them — alt text
                    // is meaningless on non-image attachments.
                    if ( empty( $args['post_mime_type'] ) ) {
                        $args['post_mime_type'] = 'image';
                    }
                }

                $include_file_size = ! empty( $input['include_file_size'] );

                $query = new \WP_Query( $args );
                $items = [];

                foreach ( $query->posts as $att ) {
                    $alt        = (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
                    $meta       = wp_get_attachment_metadata( $att->ID );
                    $width      = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
                    $height     = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
                    $url        = (string) wp_get_attachment_url( $att->ID );

                    $file_size    = null;
                    $file_missing = false;
                    if ( $include_file_size ) {
                        $path = get_attached_file( $att->ID );
                        if ( $path && file_exists( $path ) ) {
                            $file_size = (int) filesize( $path );
                        } else {
                            $file_missing = true;
                        }
                    }

                    $items[] = [
                        'id'           => (int) $att->ID,
                        'title'        => (string) $att->post_title,
                        'slug'         => (string) $att->post_name,
                        'mime_type'    => (string) $att->post_mime_type,
                        'url'          => $url,
                        'alt_text'     => $alt,
                        'caption'      => (string) $att->post_excerpt,
                        'description'  => (string) $att->post_content,
                        'attached_to'  => (int) $att->post_parent,
                        'author'       => (int) $att->post_author,
                        'date'         => (string) $att->post_date_gmt,
                        'modified'     => (string) $att->post_modified_gmt,
                        'width'        => $width,
                        'height'       => $height,
                        'file_size'    => $file_size,
                        'file_missing' => $file_missing,
                    ];
                }

                return [
                    'total'    => (int) $query->found_posts,
                    'returned' => count( $items ),
                    'offset'   => $offset,
                    'items'    => $items,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-media ----
        wp_register_ability( 'atarim/get-media', [
            'label'               => 'Get Media',
            'description'         => 'Returns full detail for a single attachment by ID: file URL, MIME type, alt text, caption, description, dimensions, file size (read from disk), and the post it is attached to (if any). Also reports usage signal: in_use_as_featured_media counts how many posts use this as their _thumbnail_id.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID.',
                        'minimum'     => 1,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'                 => [ 'type' => 'boolean' ],
                    'id'                      => [ 'type' => 'integer' ],
                    'title'                   => [ 'type' => 'string' ],
                    'slug'                    => [ 'type' => 'string' ],
                    'mime_type'               => [ 'type' => 'string' ],
                    'url'                     => [ 'type' => 'string' ],
                    'file_path'               => [ 'type' => 'string' ],
                    'alt_text'                => [ 'type' => 'string' ],
                    'caption'                 => [ 'type' => 'string' ],
                    'description'             => [ 'type' => 'string' ],
                    'attached_to'             => [ 'type' => 'integer' ],
                    'author'                  => [ 'type' => 'integer' ],
                    'date'                    => [ 'type' => 'string' ],
                    'modified'                => [ 'type' => 'string' ],
                    'width'                   => [ 'type' => 'integer' ],
                    'height'                  => [ 'type' => 'integer' ],
                    'file_size'               => [ 'type' => [ 'integer', 'null' ] ],
                    'file_missing'            => [ 'type' => 'boolean' ],
                    'in_use_as_featured_media' => [ 'type' => 'integer' ],
                    'sizes'                   => [ 'type' => 'object' ],
                    'message'                 => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }

                $att = get_post( $id );
                if ( ! $att || $att->post_type !== 'attachment' ) {
                    return [ 'success' => false, 'message' => sprintf( 'Attachment %d not found.', $id ) ];
                }

                $alt    = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
                $meta   = wp_get_attachment_metadata( $id );
                $width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
                $height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
                $url    = (string) wp_get_attachment_url( $id );
                $path   = (string) get_attached_file( $id );

                $file_size    = null;
                $file_missing = true;
                if ( $path && file_exists( $path ) ) {
                    $file_size    = (int) filesize( $path );
                    $file_missing = false;
                }

                // Featured-image usage count via _thumbnail_id meta lookup.
                global $wpdb;
                $featured_uses = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
                    (string) $id
                ) );

                // Intermediate sizes (thumbnail, medium, large, etc.).
                $sizes = [];
                if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                    foreach ( $meta['sizes'] as $size_name => $size_meta ) {
                        $sizes[ $size_name ] = [
                            'width'     => isset( $size_meta['width'] ) ? (int) $size_meta['width'] : 0,
                            'height'    => isset( $size_meta['height'] ) ? (int) $size_meta['height'] : 0,
                            'file'      => isset( $size_meta['file'] ) ? (string) $size_meta['file'] : '',
                            'mime_type' => isset( $size_meta['mime-type'] ) ? (string) $size_meta['mime-type'] : '',
                        ];
                    }
                }

                return [
                    'success'                  => true,
                    'id'                       => $id,
                    'title'                    => (string) $att->post_title,
                    'slug'                     => (string) $att->post_name,
                    'mime_type'                => (string) $att->post_mime_type,
                    'url'                      => $url,
                    'file_path'                => $path,
                    'alt_text'                 => $alt,
                    'caption'                  => (string) $att->post_excerpt,
                    'description'              => (string) $att->post_content,
                    'attached_to'              => (int) $att->post_parent,
                    'author'                   => (int) $att->post_author,
                    'date'                     => (string) $att->post_date_gmt,
                    'modified'                 => (string) $att->post_modified_gmt,
                    'width'                    => $width,
                    'height'                   => $height,
                    'file_size'                => $file_size,
                    'file_missing'             => $file_missing,
                    'in_use_as_featured_media' => $featured_uses,
                    'sizes'                    => $sizes,
                    'message'                  => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-media ----
        wp_register_ability( 'atarim/update-media', [
            'label'               => 'Update Media',
            'description'         => 'Updates fields on a single attachment. Only id is required; pass any subset of alt_text, title, caption, description. Omitted fields are left unchanged. Pass an empty string to clear a field. Alt text is stored in _wp_attachment_image_alt meta; title/caption/description are post fields.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID.',
                        'minimum'     => 1,
                    ],
                    'alt_text' => [
                        'type'        => 'string',
                        'description' => 'Alt text for accessibility. Empty string clears it.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Attachment title.',
                    ],
                    'caption' => [
                        'type'        => 'string',
                        'description' => 'Caption (stored as post_excerpt). Empty string clears it.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Description (stored as post_content). Empty string clears it.',
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'integer' ],
                    'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }

                $att = get_post( $id );
                if ( ! $att || $att->post_type !== 'attachment' ) {
                    return [ 'success' => false, 'id' => $id, 'message' => sprintf( 'Attachment %d not found.', $id ) ];
                }

                $updated  = [];
                $post_arr = [ 'ID' => $id ];

                if ( array_key_exists( 'alt_text', $input ) ) {
                    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
                    $updated[] = 'alt_text';
                }
                if ( array_key_exists( 'title', $input ) ) {
                    $post_arr['post_title'] = sanitize_text_field( (string) $input['title'] );
                    $updated[] = 'title';
                }
                if ( array_key_exists( 'caption', $input ) ) {
                    $post_arr['post_excerpt'] = sanitize_textarea_field( (string) $input['caption'] );
                    $updated[] = 'caption';
                }
                if ( array_key_exists( 'description', $input ) ) {
                    $post_arr['post_content'] = wp_kses_post( (string) $input['description'] );
                    $updated[] = 'description';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'id' => $id, 'message' => 'No fields provided to update.' ];
                }

                if ( count( $post_arr ) > 1 ) {
                    $res = wp_update_post( $post_arr, true );
                    if ( is_wp_error( $res ) ) {
                        return [ 'success' => false, 'id' => $id, 'message' => 'Update failed: ' . $res->get_error_message() ];
                    }
                }

                return [
                    'success' => true,
                    'id'      => $id,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated: %s.', implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- bulk-update-alt-text ----
        wp_register_ability( 'atarim/bulk-update-alt-text', [
            'label'               => 'Bulk Update Alt Text',
            'description'         => 'Updates alt text on many attachments in a single call. Accepts an array of {id, alt_text} tuples — each tuple updates one attachment. Per-id success tracking so partial failures (missing attachments, permission issues) don\'t mask the rest. Max 500 items per call. Use list-media with missing_alt: true to find candidates that need alt text.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'items' => [
                        'type'        => 'array',
                        'description' => 'Array of {id, alt_text} objects. Each updates one attachment.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'       => [ 'type' => 'integer', 'minimum' => 1 ],
                                'alt_text' => [ 'type' => 'string' ],
                            ],
                            'required'             => [ 'id', 'alt_text' ],
                            'additionalProperties' => false,
                        ],
                        'minItems'    => 1,
                        'maxItems'    => 500,
                    ],
                ],
                'required'             => [ 'items' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'attempted' => [ 'type' => 'integer' ],
                    'updated'   => [ 'type' => 'integer' ],
                    'failed'    => [ 'type' => 'integer' ],
                    'results'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'      => [ 'type' => 'integer' ],
                                'success' => [ 'type' => 'boolean' ],
                                'message' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'attempted', 'updated', 'failed', 'results', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];
                if ( empty( $items ) ) {
                    return [
                        'success'   => false,
                        'attempted' => 0,
                        'updated'   => 0,
                        'failed'    => 0,
                        'results'   => [],
                        'message'   => 'items is required and must be a non-empty array.',
                    ];
                }

                $results = [];
                $updated = 0;
                $failed  = 0;

                foreach ( $items as $row ) {
                    if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! array_key_exists( 'alt_text', $row ) ) {
                        $results[] = [ 'id' => 0, 'success' => false, 'message' => 'Invalid row — id and alt_text are required.' ];
                        $failed++;
                        continue;
                    }
                    $id = (int) $row['id'];
                    if ( $id <= 0 ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Invalid id.' ];
                        $failed++;
                        continue;
                    }
                    $att = get_post( $id );
                    if ( ! $att || $att->post_type !== 'attachment' ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Attachment not found.' ];
                        $failed++;
                        continue;
                    }
                    if ( ! current_user_can( 'edit_post', $id ) ) {
                        $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Permission denied.' ];
                        $failed++;
                        continue;
                    }

                    $alt = sanitize_text_field( (string) $row['alt_text'] );
                    update_post_meta( $id, '_wp_attachment_image_alt', $alt );

                    $results[] = [ 'id' => $id, 'success' => true, 'message' => 'OK.' ];
                    $updated++;
                }

                $attempted = count( $items );

                return [
                    'success'   => ( $failed === 0 ),
                    'attempted' => $attempted,
                    'updated'   => $updated,
                    'failed'    => $failed,
                    'results'   => $results,
                    'message'   => sprintf( '%d of %d updated, %d failed.', $updated, $attempted, $failed ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- delete-media ----
        wp_register_ability( 'atarim/delete-media', [
            'label'               => 'Delete Media',
            'description'         => 'Deletes a single attachment from the library and removes the underlying file(s) from disk. By default refuses if the attachment is referenced as a featured image on any post — pass confirm_in_use: true to delete anyway (the affected posts lose their featured image). Important limitation: in-use detection ONLY checks featured-image references. Attachments embedded directly in post content via URL, used by page builders, or referenced from custom meta are NOT detected as in-use. The AI / caller should treat confirm_in_use: true as "I have audited usage independently".',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID.',
                        'minimum'     => 1,
                    ],
                    'confirm_in_use' => [
                        'type'        => 'boolean',
                        'description' => 'Required to delete an attachment that is used as a featured image on at least one post.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'                  => [ 'type' => 'boolean' ],
                    'id'                       => [ 'type' => 'integer' ],
                    'in_use_as_featured_media' => [ 'type' => 'integer' ],
                    'message'                  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }
                $confirm = ! empty( $input['confirm_in_use'] );

                $att = get_post( $id );
                if ( ! $att || $att->post_type !== 'attachment' ) {
                    return [ 'success' => false, 'id' => $id, 'message' => sprintf( 'Attachment %d not found.', $id ) ];
                }
                if ( ! current_user_can( 'delete_post', $id ) ) {
                    return [ 'success' => false, 'id' => $id, 'message' => 'Permission denied.' ];
                }

                global $wpdb;
                $featured_uses = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
                    (string) $id
                ) );

                if ( $featured_uses > 0 && ! $confirm ) {
                    return [
                        'success'                  => false,
                        'id'                       => $id,
                        'in_use_as_featured_media' => $featured_uses,
                        'message'                  => sprintf(
                            'Attachment %d is the featured image on %d post(s). Pass confirm_in_use: true to delete anyway (those posts will lose their featured image). NOTE: in-use detection does NOT scan post content or custom meta — verify usage independently before confirming.',
                            $id,
                            $featured_uses
                        ),
                    ];
                }

                $result = wp_delete_attachment( $id, true );
                if ( $result === false || $result === null ) {
                    return [ 'success' => false, 'id' => $id, 'in_use_as_featured_media' => $featured_uses, 'message' => 'Delete failed: WordPress reported the operation did not complete.' ];
                }

                return [
                    'success'                  => true,
                    'id'                       => $id,
                    'in_use_as_featured_media' => $featured_uses,
                    'message'                  => $featured_uses > 0
                        ? sprintf( 'Attachment deleted; %d post(s) lost their featured image.', $featured_uses )
                        : 'Attachment deleted.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );

        // ---- bulk-delete-media ----
        wp_register_ability( 'atarim/bulk-delete-media', [
            'label'               => 'Bulk Delete Media',
            'description'         => 'Deletes many attachments in a single call. DRY-RUN BY DEFAULT — call with dry_run: false to actually delete. In dry-run mode returns the list of attachments that WOULD be deleted (and which are in use). Same featured-image safety guard as delete-media: attachments used as featured images are skipped unless confirm_in_use: true. Same caveat: in-use detection only checks featured-image references, not post content / meta / page builders.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'ids' => [
                        'type'        => 'array',
                        'description' => 'Attachment IDs to delete.',
                        'items'       => [ 'type' => 'integer', 'minimum' => 1 ],
                        'minItems'    => 1,
                        'maxItems'    => 500,
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'description' => 'When true (default), reports what WOULD be deleted without actually deleting. Pass false to actually delete.',
                        'default'     => true,
                    ],
                    'confirm_in_use' => [
                        'type'        => 'boolean',
                        'description' => 'When true, attachments that are featured images on existing posts are deleted anyway. Default false — those are skipped with a per-id message.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'ids' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'dry_run'   => [ 'type' => 'boolean' ],
                    'attempted' => [ 'type' => 'integer' ],
                    'deleted'   => [ 'type' => 'integer' ],
                    'skipped'   => [ 'type' => 'integer' ],
                    'failed'    => [ 'type' => 'integer' ],
                    'results'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'                       => [ 'type' => 'integer' ],
                                'action'                   => [ 'type' => 'string' ],
                                'in_use_as_featured_media' => [ 'type' => 'integer' ],
                                'message'                  => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'dry_run', 'attempted', 'deleted', 'skipped', 'failed', 'results', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $ids     = isset( $input['ids'] ) && is_array( $input['ids'] ) ? array_values( array_unique( array_map( 'intval', $input['ids'] ) ) ) : [];
                $dry_run = ! isset( $input['dry_run'] ) ? true : (bool) $input['dry_run'];
                $confirm = ! empty( $input['confirm_in_use'] );

                if ( empty( $ids ) ) {
                    return [
                        'success'   => false,
                        'dry_run'   => $dry_run,
                        'attempted' => 0,
                        'deleted'   => 0,
                        'skipped'   => 0,
                        'failed'    => 0,
                        'results'   => [],
                        'message'   => 'ids is required and must be a non-empty array.',
                    ];
                }

                global $wpdb;
                $results = [];
                $deleted = 0;
                $skipped = 0;
                $failed  = 0;

                foreach ( $ids as $id ) {
                    if ( $id <= 0 ) {
                        $results[] = [ 'id' => $id, 'action' => 'failed', 'in_use_as_featured_media' => 0, 'message' => 'Invalid id.' ];
                        $failed++;
                        continue;
                    }
                    $att = get_post( $id );
                    if ( ! $att || $att->post_type !== 'attachment' ) {
                        $results[] = [ 'id' => $id, 'action' => 'failed', 'in_use_as_featured_media' => 0, 'message' => 'Attachment not found.' ];
                        $failed++;
                        continue;
                    }
                    if ( ! current_user_can( 'delete_post', $id ) ) {
                        $results[] = [ 'id' => $id, 'action' => 'failed', 'in_use_as_featured_media' => 0, 'message' => 'Permission denied.' ];
                        $failed++;
                        continue;
                    }

                    $featured_uses = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
                        (string) $id
                    ) );

                    if ( $featured_uses > 0 && ! $confirm ) {
                        $results[] = [
                            'id'                       => $id,
                            'action'                   => 'skipped',
                            'in_use_as_featured_media' => $featured_uses,
                            'message'                  => sprintf( 'Used as featured image on %d post(s); pass confirm_in_use to delete.', $featured_uses ),
                        ];
                        $skipped++;
                        continue;
                    }

                    if ( $dry_run ) {
                        $results[] = [
                            'id'                       => $id,
                            'action'                   => 'would_delete',
                            'in_use_as_featured_media' => $featured_uses,
                            'message'                  => $featured_uses > 0
                                ? sprintf( 'Would delete; %d post(s) would lose their featured image.', $featured_uses )
                                : 'Would delete.',
                        ];
                        $deleted++;
                        continue;
                    }

                    $res = wp_delete_attachment( $id, true );
                    if ( $res === false || $res === null ) {
                        $results[] = [ 'id' => $id, 'action' => 'failed', 'in_use_as_featured_media' => $featured_uses, 'message' => 'Delete failed.' ];
                        $failed++;
                        continue;
                    }

                    $results[] = [
                        'id'                       => $id,
                        'action'                   => 'deleted',
                        'in_use_as_featured_media' => $featured_uses,
                        'message'                  => $featured_uses > 0
                            ? sprintf( 'Deleted; %d post(s) lost their featured image.', $featured_uses )
                            : 'Deleted.',
                    ];
                    $deleted++;
                }

                $attempted = count( $ids );

                return [
                    'success'   => ( $failed === 0 ),
                    'dry_run'   => $dry_run,
                    'attempted' => $attempted,
                    'deleted'   => $deleted,
                    'skipped'   => $skipped,
                    'failed'    => $failed,
                    'results'   => $results,
                    'message'   => $dry_run
                        ? sprintf( 'DRY RUN: %d would be deleted, %d skipped (in use), %d failed. Pass dry_run: false to actually delete.', $deleted, $skipped, $failed )
                        : sprintf( '%d deleted, %d skipped (in use), %d failed.', $deleted, $skipped, $failed ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );

        // ---- upload-media ----
        wp_register_ability( 'atarim/upload-media', [
            'label'               => 'Upload Media',
            'description'         => 'Adds a new attachment to the WordPress media library. Source can be either a URL (the server fetches it; SSRF protections apply — private IPs, AWS metadata, and non-http(s) schemes are blocked) or base64-encoded file data. The file goes through WordPress\'s standard upload pipeline including MIME validation against the current user\'s allowed list, virus-scan filters that other plugins may register, and intermediate-size generation for images. Returns the new attachment ID. Optional post_id to attach to a specific post; optional alt_text to set on upload (saves a round-trip).',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source' => [
                        'type'        => 'string',
                        'description' => 'How to source the file. "url": fetch from the source_url field (http/https only, SSRF-protected). "base64": decode the source_data field.',
                        'enum'        => [ 'url', 'base64' ],
                    ],
                    'source_url' => [
                        'type'        => 'string',
                        'description' => 'When source is "url": the http or https URL to download from. Required for url source. Private IPs and cloud metadata endpoints are blocked.',
                    ],
                    'source_data' => [
                        'type'        => 'string',
                        'description' => 'When source is "base64": base64-encoded file contents (the data itself, NOT a data URL prefix like "data:image/jpeg;base64,..."). Required for base64 source.',
                    ],
                    'filename' => [
                        'type'        => 'string',
                        'description' => 'Filename to store the upload as (including extension). For url source, defaults to the basename of the URL if omitted. For base64 source, this is required.',
                    ],
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Attach the new media to this post ID. Defaults to 0 (unattached).',
                        'minimum'     => 0,
                        'default'     => 0,
                    ],
                    'alt_text' => [
                        'type'        => 'string',
                        'description' => 'Alt text to set on the new attachment after upload. Optional — saves a follow-up update-media call.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Attachment title. Defaults to the filename minus extension.',
                    ],
                    'caption' => [
                        'type'        => 'string',
                        'description' => 'Caption (stored as post_excerpt).',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Long description (stored as post_content).',
                    ],
                ],
                'required'             => [ 'source' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'id'        => [ 'type' => 'integer' ],
                    'url'       => [ 'type' => 'string' ],
                    'filename'  => [ 'type' => 'string' ],
                    'mime_type' => [ 'type' => 'string' ],
                    'file_size' => [ 'type' => 'integer' ],
                    'width'     => [ 'type' => 'integer' ],
                    'height'    => [ 'type' => 'integer' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $source = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : '';
                if ( ! in_array( $source, [ 'url', 'base64' ], true ) ) {
                    return [ 'success' => false, 'message' => 'source must be "url" or "base64".' ];
                }

                // Fetch / decode the bytes via the shared helper.
                $fetched = $this->avcf_fetch_media_source( $source, $input );
                if ( isset( $fetched['error'] ) ) {
                    return [ 'success' => false, 'message' => $fetched['error'] ];
                }

                $tmp_file      = $fetched['tmp_file'];
                $filename      = $fetched['filename'];
                $mime_detected = $fetched['mime'];
                $file_size     = $fetched['size'];

                // MIME must be in WP's allowed list for the current user.
                $allowed = get_allowed_mime_types();
                if ( ! in_array( $mime_detected, $allowed, true ) ) {
                    @unlink( $tmp_file );
                    return [
                        'success' => false,
                        'message' => sprintf( 'MIME type "%s" is not allowed on this site for your user role.', $mime_detected ),
                    ];
                }

                // Size limit.
                $max = wp_max_upload_size();
                if ( $max > 0 && $file_size > $max ) {
                    @unlink( $tmp_file );
                    return [
                        'success' => false,
                        'message' => sprintf( 'File size %d bytes exceeds the upload limit of %d bytes.', $file_size, $max ),
                    ];
                }

                // Move into the uploads dir via WP's sideload pipeline.
                if ( ! function_exists( 'wp_handle_sideload' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                if ( ! function_exists( 'wp_read_image_metadata' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                }

                $file_array = [
                    'name'     => $filename,
                    'tmp_name' => $tmp_file,
                    'size'     => $file_size,
                ];

                $overrides = [ 'test_form' => false, 'test_size' => true ];
                $sideload  = wp_handle_sideload( $file_array, $overrides );

                if ( ! empty( $sideload['error'] ) ) {
                    @unlink( $tmp_file );
                    return [ 'success' => false, 'message' => 'Upload failed: ' . $sideload['error'] ];
                }

                // Insert the attachment record.
                $post_id_parent = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                if ( $post_id_parent > 0 && ! get_post( $post_id_parent ) ) {
                    @unlink( $sideload['file'] );
                    return [ 'success' => false, 'message' => sprintf( 'post_id %d does not exist.', $post_id_parent ) ];
                }

                $title = isset( $input['title'] )
                    ? sanitize_text_field( (string) $input['title'] )
                    : preg_replace( '/\\.[^.]+$/', '', $filename );

                $attachment = [
                    'post_mime_type' => $sideload['type'],
                    'post_title'     => $title,
                    'post_status'    => 'inherit',
                    'post_parent'    => $post_id_parent,
                    'post_content'   => isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '',
                    'post_excerpt'   => isset( $input['caption'] ) ? sanitize_textarea_field( (string) $input['caption'] ) : '',
                ];

                $attach_id = wp_insert_attachment( $attachment, $sideload['file'], $post_id_parent, true );
                if ( is_wp_error( $attach_id ) ) {
                    @unlink( $sideload['file'] );
                    return [ 'success' => false, 'message' => 'Attachment insert failed: ' . $attach_id->get_error_message() ];
                }

                // Generate intermediate sizes for images.
                $metadata = wp_generate_attachment_metadata( $attach_id, $sideload['file'] );
                wp_update_attachment_metadata( $attach_id, $metadata );

                // Set alt text if provided.
                if ( isset( $input['alt_text'] ) ) {
                    update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
                }

                $width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
                $height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

                return [
                    'success'   => true,
                    'id'        => (int) $attach_id,
                    'url'       => (string) wp_get_attachment_url( $attach_id ),
                    'filename'  => basename( $sideload['file'] ),
                    'mime_type' => $sideload['type'],
                    'file_size' => $file_size,
                    'width'     => $width,
                    'height'    => $height,
                    'message'   => sprintf( 'Uploaded as attachment %d.', $attach_id ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- replace-media-file ----
        wp_register_ability( 'atarim/replace-media-file', [
            'label'               => 'Replace Media File',
            'description'         => 'Replaces the underlying file of an existing attachment, keeping the SAME attachment ID. Useful when the same image needs a higher-resolution version, or a typo in a graphic needs fixing without breaking every URL/embed that references the old image. Preserves the filename by default so existing URLs continue working. MIME type changes (e.g. JPG → PNG) hard-fail unless confirm_mime_change: true. Regenerates intermediate sizes. Note: external CDN caches (Cloudflare, WP Rocket) may serve the old file until purged — run atarim/purge-all-caches (or atarim/purge-url-cache for the specific file) afterward.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID whose file will be replaced.',
                        'minimum'     => 1,
                    ],
                    'source' => [
                        'type'        => 'string',
                        'description' => 'How to source the new file. "url" or "base64". Same protections as upload-media.',
                        'enum'        => [ 'url', 'base64' ],
                    ],
                    'source_url' => [
                        'type'        => 'string',
                        'description' => 'When source is "url": http or https URL of the replacement file.',
                    ],
                    'source_data' => [
                        'type'        => 'string',
                        'description' => 'When source is "base64": base64-encoded file contents.',
                    ],
                    'rename_to' => [
                        'type'        => 'string',
                        'description' => 'Rename the file to this name (including extension). If omitted, the original filename is preserved — recommended so existing URLs / embeds keep working.',
                    ],
                    'confirm_mime_change' => [
                        'type'        => 'boolean',
                        'description' => 'Required when the new file has a different MIME type than the existing attachment. Without this flag the ability hard-fails on mismatch — replacing an image with a non-image is almost always a mistake.',
                        'default'     => false,
                    ],
                ],
                'required'             => [ 'id', 'source' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => [ 'type' => 'boolean' ],
                    'id'             => [ 'type' => 'integer' ],
                    'url'            => [ 'type' => 'string' ],
                    'old_filename'   => [ 'type' => 'string' ],
                    'new_filename'   => [ 'type' => 'string' ],
                    'old_mime_type'  => [ 'type' => 'string' ],
                    'new_mime_type'  => [ 'type' => 'string' ],
                    'new_file_size'  => [ 'type' => 'integer' ],
                    'width'          => [ 'type' => 'integer' ],
                    'height'         => [ 'type' => 'integer' ],
                    'message'        => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) {
                    return [ 'success' => false, 'message' => 'id is required and must be a positive integer.' ];
                }
                $att = get_post( $id );
                if ( ! $att || $att->post_type !== 'attachment' ) {
                    return [ 'success' => false, 'message' => sprintf( 'Attachment %d not found.', $id ) ];
                }
                if ( ! current_user_can( 'edit_post', $id ) ) {
                    return [ 'success' => false, 'message' => 'Permission denied.' ];
                }

                $source = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : '';
                if ( ! in_array( $source, [ 'url', 'base64' ], true ) ) {
                    return [ 'success' => false, 'message' => 'source must be "url" or "base64".' ];
                }

                $existing_path     = (string) get_attached_file( $id );
                $existing_filename = basename( $existing_path );
                $existing_mime     = (string) $att->post_mime_type;

                if ( $existing_path === '' || ! file_exists( $existing_path ) ) {
                    return [ 'success' => false, 'message' => sprintf( 'Attachment %d has no file on disk to replace.', $id ) ];
                }

                $fetched = $this->avcf_fetch_media_source( $source, $input );
                if ( isset( $fetched['error'] ) ) {
                    return [ 'success' => false, 'message' => $fetched['error'] ];
                }

                $tmp_file  = $fetched['tmp_file'];
                $new_mime  = $fetched['mime'];
                $file_size = $fetched['size'];

                // MIME must be in WP's allowed list.
                $allowed = get_allowed_mime_types();
                if ( ! in_array( $new_mime, $allowed, true ) ) {
                    @unlink( $tmp_file );
                    return [
                        'success' => false,
                        'message' => sprintf( 'MIME type "%s" is not allowed on this site for your user role.', $new_mime ),
                    ];
                }

                // Size limit.
                $max = wp_max_upload_size();
                if ( $max > 0 && $file_size > $max ) {
                    @unlink( $tmp_file );
                    return [
                        'success' => false,
                        'message' => sprintf( 'File size %d bytes exceeds the upload limit of %d bytes.', $file_size, $max ),
                    ];
                }

                // MIME mismatch guard.
                $confirm_mime = ! empty( $input['confirm_mime_change'] );
                if ( $new_mime !== $existing_mime && ! $confirm_mime ) {
                    @unlink( $tmp_file );
                    return [
                        'success'       => false,
                        'id'            => $id,
                        'old_mime_type' => $existing_mime,
                        'new_mime_type' => $new_mime,
                        'message'       => sprintf(
                            'New file MIME type "%s" differs from existing "%s". Pass confirm_mime_change: true to proceed.',
                            $new_mime,
                            $existing_mime
                        ),
                    ];
                }

                // Determine destination path. Default: preserve filename (so existing URLs / embeds still work).
                $dir          = dirname( $existing_path );
                $rename_to    = isset( $input['rename_to'] ) ? sanitize_file_name( (string) $input['rename_to'] ) : '';
                $new_filename = ( $rename_to !== '' ) ? $rename_to : $existing_filename;
                $new_path     = trailingslashit( $dir ) . $new_filename;

                // Delete existing intermediate-size files before regenerating, so stale sizes don't linger.
                $old_meta = wp_get_attachment_metadata( $id );
                if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) ) {
                    foreach ( $old_meta['sizes'] as $size_meta ) {
                        if ( ! empty( $size_meta['file'] ) ) {
                            $size_path = trailingslashit( $dir ) . $size_meta['file'];
                            if ( file_exists( $size_path ) ) {
                                @unlink( $size_path );
                            }
                        }
                    }
                }

                // Replace the file on disk. Delete old if renaming, otherwise overwrite.
                if ( $new_path !== $existing_path && file_exists( $existing_path ) ) {
                    @unlink( $existing_path );
                }
                if ( ! @copy( $tmp_file, $new_path ) ) {
                    @unlink( $tmp_file );
                    return [ 'success' => false, 'message' => sprintf( 'Could not write replacement file to %s.', $new_path ) ];
                }
                @unlink( $tmp_file );

                // Update attachment record: new MIME, new _wp_attached_file if path changed.
                $update_arr = [
                    'ID'             => $id,
                    'post_mime_type' => $new_mime,
                ];
                wp_update_post( $update_arr );

                // Update _wp_attached_file meta (relative path from uploads dir).
                $uploads = wp_upload_dir();
                $relative = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $new_path ), '/' );
                update_post_meta( $id, '_wp_attached_file', $relative );

                // Regenerate intermediate sizes.
                if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $new_metadata = wp_generate_attachment_metadata( $id, $new_path );
                wp_update_attachment_metadata( $id, $new_metadata );

                $width  = isset( $new_metadata['width'] ) ? (int) $new_metadata['width'] : 0;
                $height = isset( $new_metadata['height'] ) ? (int) $new_metadata['height'] : 0;

                return [
                    'success'        => true,
                    'id'             => $id,
                    'url'            => (string) wp_get_attachment_url( $id ),
                    'old_filename'   => $existing_filename,
                    'new_filename'   => $new_filename,
                    'old_mime_type'  => $existing_mime,
                    'new_mime_type'  => $new_mime,
                    'new_file_size'  => $file_size,
                    'width'          => $width,
                    'height'         => $height,
                    'message'        => $new_filename === $existing_filename
                        ? 'File replaced; filename preserved. External CDN caches may serve the old file until purged — run atarim/purge-all-caches.'
                        : sprintf( 'File replaced and renamed from "%s" to "%s". Existing URLs referencing the old filename will break.', $existing_filename, $new_filename ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );

        // ---- replace-media-in-content ----
        wp_register_ability( 'atarim/replace-media-in-content', [
            'label'               => 'Replace Media In Content',
            'description'         => 'Swaps references to one attachment for another inside a single post\'s body. Rewrites wp-image-{id} class attributes, direct URL references to the old image (including all intermediate sizes), and srcset entries. Both attachments must exist. Defaults to dry-run mode — returns the proposed new content and a replacement count without saving. Pass dry_run: false to write. Limitation: only post_content is rewritten. References inside custom fields, page builder data, or serialized post meta are NOT touched.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post / page / CPT ID whose body will be rewritten.',
                        'minimum'     => 1,
                    ],
                    'old_attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID to find references to.',
                        'minimum'     => 1,
                    ],
                    'new_attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'Attachment ID to replace with.',
                        'minimum'     => 1,
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'description' => 'When true (default), returns the proposed new content + replacement count without writing. Pass false to actually save.',
                        'default'     => true,
                    ],
                ],
                'required'             => [ 'post_id', 'old_attachment_id', 'new_attachment_id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'           => [ 'type' => 'boolean' ],
                    'dry_run'           => [ 'type' => 'boolean' ],
                    'post_id'           => [ 'type' => 'integer' ],
                    'replacements'      => [ 'type' => 'integer' ],
                    'class_rewrites'    => [ 'type' => 'integer' ],
                    'url_rewrites'      => [ 'type' => 'integer' ],
                    'new_content'       => [ 'type' => 'string' ],
                    'message'           => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'dry_run', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
                $old_id  = isset( $input['old_attachment_id'] ) ? (int) $input['old_attachment_id'] : 0;
                $new_id  = isset( $input['new_attachment_id'] ) ? (int) $input['new_attachment_id'] : 0;
                $dry_run = ! isset( $input['dry_run'] ) ? true : (bool) $input['dry_run'];

                if ( $post_id <= 0 || $old_id <= 0 || $new_id <= 0 ) {
                    return [ 'success' => false, 'dry_run' => $dry_run, 'message' => 'post_id, old_attachment_id, and new_attachment_id are all required and must be positive integers.' ];
                }
                if ( $old_id === $new_id ) {
                    return [ 'success' => false, 'dry_run' => $dry_run, 'message' => 'old_attachment_id and new_attachment_id must be different.' ];
                }

                $post = get_post( $post_id );
                if ( ! $post ) {
                    return [ 'success' => false, 'dry_run' => $dry_run, 'message' => sprintf( 'Post %d not found.', $post_id ) ];
                }
                $pt_obj = get_post_type_object( $post->post_type );
                if ( $pt_obj && ! current_user_can( $pt_obj->cap->edit_post, $post_id ) ) {
                    return [ 'success' => false, 'dry_run' => $dry_run, 'message' => sprintf( 'You do not have permission to edit this %s.', $post->post_type ) ];
                }

                $old_att = get_post( $old_id );
                $new_att = get_post( $new_id );
                if ( ! $old_att || $old_att->post_type !== 'attachment' ) {
                    return [ 'success' => false, 'dry_run' => $dry_run, 'message' => sprintf( 'old_attachment_id %d is not an attachment.', $old_id ) ];
                }
                if ( ! $new_att || $new_att->post_type !== 'attachment' ) {
                    return [ 'success' => false, 'dry_run' => $dry_run, 'message' => sprintf( 'new_attachment_id %d is not an attachment.', $new_id ) ];
                }

                $content = (string) $post->post_content;
                $original_content = $content;

                // 1) Rewrite wp-image-{old_id} → wp-image-{new_id}.
                $class_rewrites = 0;
                $content = preg_replace(
                    '/wp-image-' . $old_id . '\\b/',
                    'wp-image-' . $new_id,
                    $content,
                    -1,
                    $class_rewrites
                );

                // 2) Rewrite URL references. We collect all known URLs of the old attachment
                // (full + intermediate sizes) and replace each with the equivalent URL of the
                // new attachment. WordPress stores intermediate sizes in metadata.
                $url_rewrites = 0;

                $old_meta = wp_get_attachment_metadata( $old_id );
                $old_uploads_dir = wp_get_attachment_url( $old_id );
                $old_base_url    = $old_uploads_dir ? preg_replace( '#/[^/]+$#', '/', $old_uploads_dir ) : '';

                $new_full_url = (string) wp_get_attachment_url( $new_id );

                // Build URL map: old size URL → new full URL (for now; size-perfect swap is best-effort).
                $url_map = [];

                // Old "full" URL.
                if ( $old_uploads_dir ) {
                    $url_map[ $old_uploads_dir ] = $new_full_url;
                }

                // Old intermediate sizes.
                if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) && $old_base_url !== '' ) {
                    foreach ( $old_meta['sizes'] as $size_name => $size_meta ) {
                        if ( empty( $size_meta['file'] ) ) {
                            continue;
                        }
                        $old_size_url = $old_base_url . $size_meta['file'];
                        // Try to map to the same-named size on the new attachment if it exists; else fall back to new full.
                        $new_size_url = wp_get_attachment_image_url( $new_id, $size_name );
                        $url_map[ $old_size_url ] = $new_size_url ? $new_size_url : $new_full_url;
                    }
                }

                // Apply URL replacements. Longest URL first so size-suffixed URLs don't get partially
                // matched by the shorter base URL.
                uksort( $url_map, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

                foreach ( $url_map as $old_url => $new_url ) {
                    $count = 0;
                    $content = str_replace( $old_url, $new_url, $content, $count );
                    $url_rewrites += $count;
                }

                $total_replacements = $class_rewrites + $url_rewrites;

                if ( $total_replacements === 0 ) {
                    return [
                        'success'        => true,
                        'dry_run'        => $dry_run,
                        'post_id'        => $post_id,
                        'replacements'   => 0,
                        'class_rewrites' => 0,
                        'url_rewrites'   => 0,
                        'new_content'    => $original_content,
                        'message'        => sprintf( 'No references to attachment %d found in post %d.', $old_id, $post_id ),
                    ];
                }

                if ( $dry_run ) {
                    return [
                        'success'        => true,
                        'dry_run'        => true,
                        'post_id'        => $post_id,
                        'replacements'   => $total_replacements,
                        'class_rewrites' => $class_rewrites,
                        'url_rewrites'   => $url_rewrites,
                        'new_content'    => $content,
                        'message'        => sprintf( 'DRY RUN: %d total replacement(s) (%d class, %d URL) would be made in post %d. Pass dry_run: false to save.', $total_replacements, $class_rewrites, $url_rewrites, $post_id ),
                    ];
                }

                // Save.
                $result = wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ], true );
                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'dry_run' => false, 'message' => 'Save failed: ' . $result->get_error_message() ];
                }

                return [
                    'success'        => true,
                    'dry_run'        => false,
                    'post_id'        => $post_id,
                    'replacements'   => $total_replacements,
                    'class_rewrites' => $class_rewrites,
                    'url_rewrites'   => $url_rewrites,
                    'new_content'    => $content,
                    'message'        => sprintf( '%d replacement(s) saved in post %d (%d class, %d URL).', $total_replacements, $post_id, $class_rewrites, $url_rewrites ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ] );

        // ---- regenerate-image ----
        wp_register_ability( 'atarim/regenerate-image', [
            'label'               => 'Regenerate Image Sub-sizes',
            'description'         => 'Regenerate the sub-sizes (thumbnails / intermediate sizes) for one or more image attachments using WordPress core. Use after registering new image sizes, switching themes, or to shrink derivative files. Pass attachment_id for one, or attachment_ids for a batch (max 25 per call). Optional quality (1-100) re-encodes the GENERATED sub-sizes at that JPEG/WebP quality for a plugin-free size win; the ORIGINAL master file is never modified. Non-image attachments are skipped with a note. Each image is processed independently (per-image time/memory limits are raised best-effort) so one failure does not abort the rest of the batch, and images already done in a batch are saved even if a later one fails. However, a single very heavy image can still exceed a host hard PHP limit (PHP-FPM / web-server max execution time), which aborts the request — for heavy libraries process singly or use WP-CLI. To optimize an entire large library, work in small batches.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'attachment_id'  => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'A single attachment ID.' ],
                    'attachment_ids' => [ 'type' => 'array', 'maxItems' => 25, 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'description' => 'Several attachment IDs (max 25).' ],
                    'quality'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Re-encode the generated sub-sizes at this quality (1-100). The original is not touched. Omit to use the site default.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'           => [ 'type' => 'boolean' ],
                    'regenerated_count' => [ 'type' => 'integer' ],
                    'results'           => [ 'type' => 'array' ],
                    'message'           => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                // Collect target IDs from either input shape.
                $ids = [];
                if ( isset( $input['attachment_id'] ) ) {
                    $ids[] = (int) $input['attachment_id'];
                }
                if ( isset( $input['attachment_ids'] ) && is_array( $input['attachment_ids'] ) ) {
                    foreach ( $input['attachment_ids'] as $one ) { $ids[] = (int) $one; }
                }
                $ids = array_values( array_unique( array_filter( $ids, function( $v ) { return $v > 0; } ) ) );
                if ( empty( $ids ) ) {
                    return [ 'success' => false, 'message' => 'Provide attachment_id or attachment_ids.' ];
                }
                if ( count( $ids ) > 25 ) {
                    return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max 25 per call. Split into batches.', count( $ids ) ) ];
                }

                if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                    return [ 'success' => false, 'message' => 'Image metadata functions are unavailable.' ];
                }

                // Optional quality: applies ONLY to the sub-sizes generated below;
                // the original master is never re-encoded. Filters are removed after.
                $quality = isset( $input['quality'] ) ? (int) $input['quality'] : 0;
                $filter  = null;
                if ( $quality >= 1 && $quality <= 100 ) {
                    $filter = function() use ( $quality ) { return $quality; };
                    add_filter( 'wp_editor_set_quality', $filter, 9999 );
                    add_filter( 'jpeg_quality', $filter, 9999 );
                }

                // Image processing is memory-heavy; raise the ceiling (best-effort).
                wp_raise_memory_limit( 'image' );

                $results = [];
                $ok      = 0;
                foreach ( $ids as $id ) {
                    // Give EACH image its own time budget so a batch does not
                    // accumulate toward max_execution_time (that accumulation is
                    // why one heavy image could kill an entire batch). Best-effort:
                    // the host may disable set_time_limit or enforce a hard
                    // PHP-FPM / web-server limit, in which case a single very heavy
                    // image still needs WP-CLI or a raised server limit.
                    if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 0 ); // phpcs:ignore
                    }
                    if ( 'attachment' !== get_post_type( $id ) ) {
                        $results[] = [ 'id' => $id, 'regenerated' => false, 'message' => 'Not an attachment.' ];
                        continue;
                    }
                    if ( ! wp_attachment_is_image( $id ) ) {
                        $results[] = [ 'id' => $id, 'regenerated' => false, 'message' => 'Not an image attachment; skipped.' ];
                        continue;
                    }
                    $file = get_attached_file( $id );
                    if ( ! $file || ! file_exists( $file ) ) {
                        $results[] = [ 'id' => $id, 'regenerated' => false, 'message' => 'Original file missing on disk.' ];
                        continue;
                    }
                    // Per-image try/catch so a catchable fatal (e.g. an image
                    // library exception) on one image does not abort the whole
                    // batch. A true max_execution_time timeout is NOT catchable —
                    // it hard-kills the request — so heavy files may still need
                    // singly-processing or WP-CLI.
                    try {
                        $meta = wp_generate_attachment_metadata( $id, $file );
                        if ( is_wp_error( $meta ) ) {
                            $results[] = [ 'id' => $id, 'regenerated' => false, 'message' => 'Regeneration failed: ' . $meta->get_error_message() ];
                            continue;
                        }
                        if ( empty( $meta ) ) {
                            $results[] = [ 'id' => $id, 'regenerated' => false, 'message' => 'Regeneration produced no metadata (unsupported or unreadable image).' ];
                            continue;
                        }
                        wp_update_attachment_metadata( $id, $meta );
                        $sizes = ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) ? array_keys( $meta['sizes'] ) : [];
                        $ok++;
                        $results[] = [ 'id' => $id, 'regenerated' => true, 'sizes' => $sizes, 'message' => sprintf( '%d sub-size(s) generated.', count( $sizes ) ) ];
                    } catch ( \Throwable $e ) {
                        $results[] = [ 'id' => $id, 'regenerated' => false, 'message' => 'Failed: ' . $e->getMessage() ];
                    }
                }

                if ( $filter ) {
                    remove_filter( 'wp_editor_set_quality', $filter, 9999 );
                    remove_filter( 'jpeg_quality', $filter, 9999 );
                }

                return [
                    'success'           => true,
                    'regenerated_count' => $ok,
                    'results'           => $results,
                    'message'           => sprintf( 'Regenerated %d of %d image(s)%s.', $ok, count( $ids ), ( $quality >= 1 && $quality <= 100 ) ? sprintf( ' at quality %d (original untouched)', $quality ) : '' ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    /**
     * Fetch a media source — URL download or base64 decode — into a temp file.
     *
     * Returns one of:
     *   [ 'tmp_file' => path, 'filename' => name, 'mime' => mime, 'size' => bytes ]
     *   [ 'error' => message ]
     *
     * URL fetch enforces SSRF protections: only http/https schemes, blocks RFC1918
     * private ranges, loopback, link-local, and cloud metadata endpoints
     * (AWS 169.254.169.254 etc).
     *
     * @param string $source 'url' or 'base64'
     * @param array  $input  The ability call input
     * @return array
     */
    private function avcf_fetch_media_source( $source, $input ) {
        if ( $source === 'url' ) {
            $url = isset( $input['source_url'] ) ? esc_url_raw( (string) $input['source_url'] ) : '';
            if ( $url === '' ) {
                return [ 'error' => 'source_url is required when source is "url".' ];
            }

            $ssrf_err = $this->avcf_check_url_safety( $url );
            if ( $ssrf_err !== null ) {
                return [ 'error' => $ssrf_err ];
            }

            // Stream to a temp file. download_url uses WP HTTP API + handles redirects.
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = download_url( $url, 60 );
            if ( is_wp_error( $tmp ) ) {
                return [ 'error' => 'Download failed: ' . $tmp->get_error_message() ];
            }

            $filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
            if ( $filename === '' ) {
                $parsed   = wp_parse_url( $url );
                $path     = isset( $parsed['path'] ) ? $parsed['path'] : '';
                $filename = sanitize_file_name( basename( (string) $path ) );
                if ( $filename === '' ) {
                    $filename = 'upload-' . time();
                }
            }

            $size = (int) @filesize( $tmp );
            $mime = $this->avcf_detect_mime( $tmp, $filename );

            return [
                'tmp_file' => $tmp,
                'filename' => $filename,
                'mime'     => $mime,
                'size'     => $size,
            ];
        }

        if ( $source === 'base64' ) {
            $data = isset( $input['source_data'] ) ? (string) $input['source_data'] : '';
            if ( $data === '' ) {
                return [ 'error' => 'source_data is required when source is "base64".' ];
            }

            // Strip data: URL prefix defensively if caller forgot. We document that they
            // should send raw base64, but be lenient on input.
            if ( strpos( $data, 'data:' ) === 0 ) {
                $comma = strpos( $data, ',' );
                if ( $comma !== false ) {
                    $data = substr( $data, $comma + 1 );
                }
            }

            // strict mode false — be tolerant of whitespace / newlines in pasted base64
            $decoded = base64_decode( $data, true );
            if ( $decoded === false ) {
                return [ 'error' => 'source_data is not valid base64.' ];
            }

            $filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
            if ( $filename === '' ) {
                return [ 'error' => 'filename is required when source is "base64" (we need the extension to determine MIME).' ];
            }

            if ( ! function_exists( 'wp_tempnam' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = wp_tempnam( $filename );
            if ( ! $tmp ) {
                return [ 'error' => 'Could not create temporary file for upload.' ];
            }
            if ( file_put_contents( $tmp, $decoded ) === false ) {
                @unlink( $tmp );
                return [ 'error' => 'Could not write decoded data to temporary file.' ];
            }

            $size = strlen( $decoded );
            $mime = $this->avcf_detect_mime( $tmp, $filename );

            return [
                'tmp_file' => $tmp,
                'filename' => $filename,
                'mime'     => $mime,
                'size'     => $size,
            ];
        }

        return [ 'error' => sprintf( 'Unknown source "%s".', $source ) ];
    }

    /**
     * Validate a URL for safe outbound fetching (SSRF protection).
     *
     * Returns null on safe; a string error message otherwise. Blocks:
     *   - non-http/https schemes
     *   - empty hosts
     *   - private RFC1918 ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
     *   - loopback (127.0.0.0/8, ::1)
     *   - link-local (169.254.0.0/16) — includes AWS / GCP / Azure metadata endpoints
     *   - localhost-named hosts
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

        // Resolve to IPs and check each against private/loopback/link-local ranges.
        // gethostbynamel returns array of IPv4 addresses, or false on failure.
        $ips = @gethostbynamel( $host );
        // If it's already an IP literal, gethostbynamel may return false; check filter_var below.
        if ( ! is_array( $ips ) ) {
            // Maybe an IP literal directly.
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
            // FILTER_FLAG_NO_RES_RANGE covers link-local; just being explicit about the AWS metadata IP for clarity.
            if ( $ip === '169.254.169.254' ) {
                return 'URL host resolves to a cloud metadata endpoint (169.254.169.254) — blocked.';
            }
        }

        return null;
    }

    /**
     * Detect MIME type of a file. Prefers WP's wp_check_filetype_and_ext which
     * combines extension-based and finfo-based detection. Falls back to finfo
     * directly if needed.
     *
     * @param string $path Filesystem path to the file
     * @param string $filename Original filename (used by WP's extension check)
     * @return string MIME type, or empty string if undetectable
     */
    private function avcf_detect_mime( $path, $filename ) {
        if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $check = wp_check_filetype_and_ext( $path, $filename );
        if ( ! empty( $check['type'] ) ) {
            return (string) $check['type'];
        }
        // Fallback to finfo direct.
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $path );
                finfo_close( $finfo );
                if ( $mime ) {
                    return (string) $mime;
                }
            }
        }
        return '';
    }
}