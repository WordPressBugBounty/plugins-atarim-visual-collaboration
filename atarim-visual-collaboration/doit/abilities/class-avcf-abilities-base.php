<?php
/**
 * Base class for Atarim MCP ability category classes.
 *
 * Every ability-category class (content, taxonomies, users, plugins, etc.)
 * extends this class. The orchestrator (AVCF_MCP) instantiates each subclass
 * and calls register() to register all abilities in that category with the
 * WordPress Abilities API.
 *
 * Shared helpers used by multiple ability categories (e.g. post body
 * preparation, date normalization, capability check patterns) live here so
 * they have a single home.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

abstract class AVCF_Abilities_Base {

    /**
     * Register all abilities in this category.
     *
     * Called by AVCF_MCP::avcf_mcp_register_abilities() on the
     * wp_abilities_api_init hook. Each subclass implements this to register
     * its abilities via wp_register_ability().
     */
    abstract public function register();

    /**
     * Prepare a post body string for storage in post_content.
     *
     * Handles three formats:
     *   - "raw"    — store the string exactly as provided. No processing.
     *   - "blocks" — caller asserts the string is already valid block markup.
     *                Pass through unchanged.
     *   - "auto"   — (default) detect block delimiters. If a "<!-- wp:" marker
     *                is present anywhere in the string, treat as blocks and
     *                pass through. Otherwise, treat as plain text / HTML and
     *                wrap each blank-line-separated chunk as a wp:paragraph
     *                block so the result stays editable in the block editor.
     *                Single newlines inside a chunk are preserved as <br>
     *                soft line breaks.
     *
     * @param string $content
     * @param string $format  One of "auto", "raw", "blocks".
     * @return string
     */
    protected function avcf_prepare_content_body( $content, $format = 'auto' ) {
        if ( ! is_string( $content ) || $content === '' ) {
            return '';
        }

        $format = in_array( $format, [ 'auto', 'raw', 'blocks' ], true ) ? $format : 'auto';

        if ( $format === 'raw' || $format === 'blocks' ) {
            return $content;
        }

        // auto: if block delimiters are already present, trust the caller.
        if ( strpos( $content, '<!-- wp:' ) !== false ) {
            return $content;
        }

        // No block markup — split on blank lines and wrap each chunk as a
        // wp:paragraph block. Treats Markdown-style double newlines as paragraph
        // breaks; single newlines inside a chunk become <br> soft line breaks.
        $normalized = str_replace( [ "\r\n", "\r" ], "\n", $content );
        $chunks     = preg_split( '/\n{2,}/', trim( $normalized ) );

        if ( empty( $chunks ) ) {
            return '';
        }

        $blocks = [];
        foreach ( $chunks as $chunk ) {
            $chunk = trim( $chunk );
            if ( $chunk === '' ) {
                continue;
            }
            $html     = str_replace( "\n", "<br>", $chunk );
            $blocks[] = "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
        }

        return implode( "\n\n", $blocks );
    }

    /**
     * Validate and normalise a date string for post_date / post_date_gmt.
     *
     * Accepts ISO 8601 (2026-05-22T14:30:00) or any strtotime()-parseable
     * string. The parsed timestamp is treated as site-local time and GMT is
     * derived from it via get_gmt_from_date().
     *
     * Returns an array of three elements:
     *   [0] string|null  local datetime in MySQL format (Y-m-d H:i:s), or null on failure
     *   [1] string|null  GMT datetime in MySQL format, or null on failure
     *   [2] string|null  error message, or null on success
     *
     * @param string $date
     * @return array{0:?string,1:?string,2:?string}
     */
    protected function avcf_normalize_post_date( $date ) {
        if ( ! is_string( $date ) || trim( $date ) === '' ) {
            return [ null, null, 'date must be a non-empty string.' ];
        }

        $ts = strtotime( $date );
        if ( $ts === false ) {
            return [
                null,
                null,
                sprintf( 'Could not parse date "%s". Use ISO 8601 (e.g. 2026-05-22T14:30:00).', $date ),
            ];
        }

        // strtotime() in WordPress parses in UTC (WP sets default_timezone to UTC).
        // Treat the parsed timestamp as a site-local datetime (matches what a user
        // means when they type "2026-05-22 14:30") and derive GMT from it.
        $local = date( 'Y-m-d H:i:s', $ts );
        $gmt   = get_gmt_from_date( $local );

        return [ $local, $gmt, null ];
    }

    /**
     * Validate that an attachment ID exists and represents an image.
     *
     * Used when setting a featured image via _thumbnail_id meta. WordPress
     * does not validate the ID on its own — it will happily store a meta
     * value pointing to a non-existent or non-image attachment, and the
     * featured image simply won't render. For an AI action layer we want
     * explicit failure so the caller can correct the input rather than
     * silently producing a post with no featured image.
     *
     * @param int $attachment_id
     * @return string|null  Error message on failure, or null on success.
     */
    protected function avcf_validate_attachment_id( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return 'featured_media must be a positive integer attachment ID.';
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
            return sprintf( 'Attachment %d does not exist.', $attachment_id );
        }

        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return sprintf( 'Attachment %d is not an image (mime type: %s).', $attachment_id, get_post_mime_type( $attachment_id ) );
        }

        return null;
    }
}
