<?php

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Preview extends AVCF_Abilities_Base {

    public function register() {

        wp_register_ability( 'atarim/create-preview-link', [
            'label'               => 'Create Preview Link',
            'description'         => 'Returns a signed, time-limited URL that renders a single UNPUBLISHED post/page for a logged-out viewer (for AI design review / screenshots). Only works for unpublished statuses (draft, pending, future, private); refuses already-published posts, which are public anyway. The link is signed with a WordPress nonce scoped to the post and expires on its own (~24h). No user-facing setup is involved.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'The ID of the unpublished post/page to generate a preview link for.',
                    ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'id'          => [ 'type' => 'integer' ],
                    'status'      => [ 'type' => 'string' ],
                    'preview_url' => [ 'type' => 'string' ],
                    'expires_in'  => [ 'type' => 'integer' ],
                    'message'     => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
                $post = $id ? get_post( $id ) : null;

                if ( ! $post ) {
                    return [ 'success' => false, 'message' => sprintf( 'Post %d not found.', $id ) ];
                }

                if ( ! current_user_can( 'edit_post', $id ) ) {
                    return [ 'success' => false, 'message' => 'You do not have permission to create a preview link for this post.' ];
                }

                // Only unpublished content needs (or may have) a preview link. A
                // published post is already public, and trashed/auto-draft/attachment
                // statuses are not previewable. No publish hook is needed to revoke:
                // links are refused once a post is published, and existing links
                // expire on their own.
                $blocked = [ 'publish', 'trash', 'auto-draft', 'inherit' ];
                if ( in_array( $post->post_status, $blocked, true ) ) {
                    $message = ( 'publish' === $post->post_status )
                        ? 'Post is already published (publicly viewable) - no preview link is needed.'
                        : sprintf( 'Cannot create a preview link for a post with status "%s".', $post->post_status );
                    return [ 'success' => false, 'message' => $message ];
                }

                $nonce = self::create_nonce( $id );
                $url   = self::build_preview_url( $post, $nonce );
                $ttl   = self::nonce_ttl();

                return [
                    'success'     => true,
                    'id'          => $id,
                    'status'      => $post->post_status,
                    'preview_url' => $url,
                    'expires_in'  => $ttl,
                    'message'     => sprintf( 'Preview link created for post %d (valid up to ~%d hours).', $id, (int) round( $ttl / HOUR_IN_SECONDS ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Signed, time-limited preview tokens (WordPress-nonce based, no stored
    // state). The token is derived with wp_hash( ..., 'nonce' ), so it is signed
    // with the site's own secret keys - never the Atarim secret - cannot be
    // forged, and is scoped to the specific post ID. The tick mechanism gives it
    // a self-expiring ~12-24h lifetime (filter: atarim_preview_link_ttl).
    // -----------------------------------------------------------------------

    private static function nonce_ttl() {
        return max( HOUR_IN_SECONDS, (int) apply_filters( 'atarim_preview_link_ttl', DAY_IN_SECONDS ) );
    }

    private static function nonce_tick() {
        $life = self::nonce_ttl();
        return ceil( time() / ( $life / 2 ) );
    }

    private static function create_nonce( $post_id ) {
        $i = self::nonce_tick();
        return substr( wp_hash( $i . 'avcf_preview_' . (int) $post_id, 'nonce' ), -12, 10 );
    }

    private static function verify_nonce( $nonce, $post_id ) {
        $nonce = (string) $nonce;
        $i     = self::nonce_tick();
        // Current tick, then the previous one (covers the full lifetime window).
        if ( hash_equals( substr( wp_hash( $i . 'avcf_preview_' . (int) $post_id, 'nonce' ), -12, 10 ), $nonce ) ) {
            return true;
        }
        if ( hash_equals( substr( wp_hash( ( $i - 1 ) . 'avcf_preview_' . (int) $post_id, 'nonce' ), -12, 10 ), $nonce ) ) {
            return true;
        }
        return false;
    }

    private static function build_preview_url( $post, $nonce ) {
        if ( 'page' === $post->post_type ) {
            $args = [ 'page_id' => $post->ID ];
        } elseif ( 'post' === $post->post_type ) {
            $args = [ 'p' => $post->ID ];
        } else {
            $args = [ 'p' => $post->ID, 'post_type' => $post->post_type ];
        }
        $args['preview']   = 'true';
        $args['avcf_ppp']  = $nonce;

        return add_query_arg( $args, home_url( '/' ) );
    }

    // -----------------------------------------------------------------------
    // Front-end serving: let a logged-out request render the unpublished post
    // when the signed token verifies. Modeled on the Public Post Preview plugin's
    // proven approach. Registered on every request via boot() (see the cluster
    // loader); the handlers bail immediately on non-preview requests, so they are
    // effectively free otherwise.
    // -----------------------------------------------------------------------

    public static function boot() {
        add_filter( 'query_vars', [ __CLASS__, 'add_query_var' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'show_public_preview' ] );
    }

    public static function add_query_var( $qv ) {
        $qv[] = 'avcf_ppp';
        return $qv;
    }

    public static function show_public_preview( $query ) {
        if (
            $query->is_main_query() &&
            $query->is_preview() &&
            $query->is_singular() &&
            $query->get( 'avcf_ppp' )
        ) {
            // Never cache (edge caches such as Cloudflare/Rocket.net would
            // otherwise serve a stale/empty preview) and never index a draft.
            if ( ! headers_sent() ) {
                nocache_headers();
                header( 'X-Robots-Tag: noindex' );
            }
            add_filter( 'wp_robots', 'wp_robots_no_robots' );
            add_filter( 'posts_results', [ __CLASS__, 'set_post_to_publish' ], 10, 2 );
        }
    }

    public static function set_post_to_publish( $posts, $query ) {
        // Run once per request.
        remove_filter( 'posts_results', [ __CLASS__, 'set_post_to_publish' ], 10 );

        if ( empty( $posts ) ) {
            return $posts;
        }

        $post_id = (int) $posts[0]->ID;
        $nonce   = (string) $query->get( 'avcf_ppp' );

        if ( self::verify_nonce( $nonce, $post_id ) && 'publish' !== $posts[0]->post_status ) {
            // Render the unpublished post for this request only.
            $posts[0]->post_status = 'publish';

            add_filter( 'comments_open', '__return_false' );
            add_filter( 'pings_open', '__return_false' );

            do_action( 'avcf_show_public_preview', $post_id );
        }

        return $posts;
    }
}
