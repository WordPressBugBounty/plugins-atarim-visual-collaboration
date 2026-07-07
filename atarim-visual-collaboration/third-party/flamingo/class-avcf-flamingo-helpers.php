<?php
/**
 * Flamingo — shared helpers for the standalone Flamingo ability cluster.
 *
 * Reads Flamingo's inbound messages (flamingo_inbound posts) grouped by channel
 * (the flamingo_inbound_channel taxonomy). Resolves a "channel" argument that
 * may be a CF7 form_id, or a channel term id / slug / name:
 *   - form_id  → the form's _flamingo post meta carries the channel term id
 *                (id-based join, survives form renames); falls back to matching
 *                the form slug/title against the channel term.
 *   - channel  → term id (numeric), else slug, else name.
 * Inbound field/subject/from values come from post meta written by Flamingo.
 * Built on Flamingo's storage model; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Flamingo_Helpers {

    const PLUGIN_SLUG  = 'flamingo';
    const PLUGIN_LABEL = 'Flamingo';
    const CHANNEL_TAX  = 'flamingo_inbound_channel';
    const INBOUND_PT   = 'flamingo_inbound';
    const CONTACT_PT   = 'flamingo_contact';

    /**
     * Resolve a channel reference to a term id.
     *
     * @param array $input  May contain 'form_id' (CF7) and/or 'channel'
     *                      (term id, slug, or name).
     * @return array { term_id:int, via:string }  via in {meta,slug,title,id,slug-name,name,none}
     */
    public static function resolve_channel( $input ) {
        // CF7 form_id → channel term id (preferred: the form's _flamingo meta).
        if ( ! empty( $input['form_id'] ) ) {
            $form_id = (int) $input['form_id'];
            $meta = get_post_meta( $form_id, '_flamingo', true );
            if ( is_array( $meta ) && ! empty( $meta['channel'] ) ) {
                $term = get_term( (int) $meta['channel'], self::CHANNEL_TAX );
                if ( $term && ! is_wp_error( $term ) ) { return [ 'term_id' => (int) $term->term_id, 'via' => 'meta' ]; }
            }
            $post = get_post( $form_id );
            if ( $post && taxonomy_exists( self::CHANNEL_TAX ) ) {
                $by_slug = get_term_by( 'slug', $post->post_name, self::CHANNEL_TAX );
                if ( $by_slug && ! is_wp_error( $by_slug ) ) { return [ 'term_id' => (int) $by_slug->term_id, 'via' => 'slug' ]; }
                $by_name = get_term_by( 'name', $post->post_title, self::CHANNEL_TAX );
                if ( $by_name && ! is_wp_error( $by_name ) ) { return [ 'term_id' => (int) $by_name->term_id, 'via' => 'title' ]; }
            }
        }
        // Explicit channel: id, slug, or name.
        if ( isset( $input['channel'] ) && $input['channel'] !== '' && taxonomy_exists( self::CHANNEL_TAX ) ) {
            $ch = $input['channel'];
            if ( is_numeric( $ch ) ) {
                $term = get_term( (int) $ch, self::CHANNEL_TAX );
                if ( $term && ! is_wp_error( $term ) ) { return [ 'term_id' => (int) $term->term_id, 'via' => 'id' ]; }
            }
            $by_slug = get_term_by( 'slug', sanitize_title( (string) $ch ), self::CHANNEL_TAX );
            if ( $by_slug && ! is_wp_error( $by_slug ) ) { return [ 'term_id' => (int) $by_slug->term_id, 'via' => 'slug-name' ]; }
            $by_name = get_term_by( 'name', (string) $ch, self::CHANNEL_TAX );
            if ( $by_name && ! is_wp_error( $by_name ) ) { return [ 'term_id' => (int) $by_name->term_id, 'via' => 'name' ]; }
        }
        return [ 'term_id' => 0, 'via' => 'none' ];
    }

    public static function query_args( $term_id, $include_spam, $args = [], $limit = 50, $offset = 0 ) {
        $q = [ 'post_type' => self::INBOUND_PT, 'post_status' => 'any', 'posts_per_page' => (int) $limit, 'offset' => (int) $offset, 'orderby' => 'date', 'order' => 'DESC' ];
        if ( $term_id ) { $q['tax_query'] = [ [ 'taxonomy' => self::CHANNEL_TAX, 'field' => 'term_id', 'terms' => (int) $term_id ] ]; }
        if ( ! $include_spam ) { $q['meta_query'] = [ 'relation' => 'OR', [ 'key' => '_spam', 'compare' => 'NOT EXISTS' ], [ 'key' => '_spam', 'value' => 'spam', 'compare' => '!=' ] ]; }
        if ( ! empty( $args['date_from'] ) || ! empty( $args['date_to'] ) ) {
            $dq = [ 'inclusive' => true ];
            if ( ! empty( $args['date_from'] ) ) { $dq['after'] = (string) $args['date_from']; }
            if ( ! empty( $args['date_to'] ) ) { $dq['before'] = (string) $args['date_to']; }
            $q['date_query'] = [ $dq ];
        }
        return $q;
    }

    public static function count_inbound( $term_id, $include_spam, $args = [] ) {
        $q = self::query_args( $term_id, $include_spam, $args, 1, 0 );
        $q['fields'] = 'ids';
        $query = new WP_Query( $q );
        return (int) $query->found_posts;
    }

    public static function last_at( $term_id ) {
        $q = self::query_args( $term_id, true, [], 1, 0 );
        $q['fields'] = 'ids';
        $query = new WP_Query( $q );
        if ( empty( $query->posts ) ) { return null; }
        $p = get_post( (int) $query->posts[0] );
        return $p ? (string) $p->post_date : null;
    }

    public static function inbound_ids( $term_id, $include_spam, $args = [], $limit = 50, $offset = 0 ) {
        $q = self::query_args( $term_id, $include_spam, $args, $limit, $offset );
        $q['fields'] = 'ids';
        $query = new WP_Query( $q );
        return is_array( $query->posts ) ? array_map( 'intval', $query->posts ) : [];
    }

    public static function normalize_inbound( $post, $include_raw = true ) {
        $pid = (int) $post->ID;
        $fields_meta = get_post_meta( $pid, '_fields', true );
        if ( is_string( $fields_meta ) ) { $fields_meta = maybe_unserialize( $fields_meta ); }
        $fields = [];
        if ( is_array( $fields_meta ) ) {
            foreach ( $fields_meta as $name => $value ) { $fields[] = [ 'id' => (string) $name, 'label' => (string) $name, 'value' => $value ]; }
        }
        $channels = wp_get_object_terms( $pid, self::CHANNEL_TAX );
        $channel = ( is_array( $channels ) && ! empty( $channels ) && ! is_wp_error( $channels ) ) ? [ 'id' => (int) $channels[0]->term_id, 'slug' => (string) $channels[0]->slug, 'name' => (string) $channels[0]->name ] : null;
        return [
            'id'           => $pid,
            'plugin'       => self::PLUGIN_SLUG,
            'submitted_at' => (string) $post->post_date,
            'subject'      => (string) get_post_meta( $pid, '_subject', true ),
            'from'         => (string) get_post_meta( $pid, '_from', true ),
            'from_name'    => (string) get_post_meta( $pid, '_from_name', true ),
            'from_email'   => (string) get_post_meta( $pid, '_from_email', true ),
            'status'       => ( get_post_meta( $pid, '_spam', true ) === 'spam' ) ? 'spam' : 'published',
            'channel'      => $channel,
            'fields'       => $fields,
            'raw'          => $include_raw ? get_post_meta( $pid, '_meta', true ) : null,
        ];
    }

    public static function list_channels( $include_counts = true ) {
        if ( ! taxonomy_exists( self::CHANNEL_TAX ) ) { return []; }
        $terms = get_terms( [ 'taxonomy' => self::CHANNEL_TAX, 'hide_empty' => false ] );
        $out = [];
        if ( is_array( $terms ) ) {
            foreach ( $terms as $t ) {
                if ( is_wp_error( $t ) ) { continue; }
                $row = [ 'id' => (int) $t->term_id, 'slug' => (string) $t->slug, 'name' => (string) $t->name ];
                if ( $include_counts ) {
                    $row['total'] = self::count_inbound( (int) $t->term_id, false );
                    $row['spam']  = max( 0, self::count_inbound( (int) $t->term_id, true ) - $row['total'] );
                    $row['last_submission'] = self::last_at( (int) $t->term_id );
                }
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function stats( $term_id, $args = [] ) {
        $q = self::query_args( $term_id, false, $args, 500, 0 );
        $q['fields'] = 'ids';
        $query = new WP_Query( $q );
        $by_day = [];
        foreach ( (array) $query->posts as $pid ) {
            $p = get_post( (int) $pid );
            if ( ! $p ) { continue; }
            $day = substr( (string) $p->post_date, 0, 10 );
            if ( ! isset( $by_day[ $day ] ) ) { $by_day[ $day ] = 0; }
            $by_day[ $day ]++;
        }
        ksort( $by_day );
        $rows = [];
        foreach ( $by_day as $day => $count ) { $rows[] = [ 'day' => $day, 'count' => $count ]; }
        return [
            'total'      => self::count_inbound( $term_id, false, $args ),
            'spam_total' => max( 0, self::count_inbound( $term_id, true, $args ) - self::count_inbound( $term_id, false, $args ) ),
            'last_date'  => self::last_at( $term_id ),
            'by_day'     => $rows,
        ];
    }

    public static function csv_row( $cells ) {
        $out = [];
        foreach ( (array) $cells as $c ) { $out[] = '"' . str_replace( '"', '""', (string) $c ) . '"'; }
        return implode( ',', $out );
    }
}
