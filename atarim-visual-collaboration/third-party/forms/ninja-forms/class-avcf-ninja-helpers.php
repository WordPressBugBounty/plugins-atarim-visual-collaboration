<?php
/**
 * Ninja Forms — shared helpers for the standalone Ninja ability cluster.
 *
 * Uses the Ninja_Forms()->form() model API for forms/fields/actions/subs and
 * direct $wpdb over the posts/postmeta tables for submission counts and stats
 * (submissions are 'nf_sub' posts keyed by the _form_id meta; Ninja uses the
 * trash status as its spam-equivalent). Notifications are "actions" of type
 * email. Built on Ninja's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Ninja_Helpers {

    const PLUGIN_SLUG  = 'ninja';
    const PLUGIN_LABEL = 'Ninja Forms';

    public static function map_form_summary( $row ) {
        $form_id = (int) $row->id;
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => (string) $row->title,
            'status'          => 'active',
            'entries_total'   => self::count_submissions( $form_id, false ),
            'last_submission' => self::last_submission_at( $form_id ),
            'created_at'      => isset( $row->created_at ) ? (string) $row->created_at : null,
        ];
    }

    public static function map_fields( $form_id ) {
        $fields = [];
        $models = Ninja_Forms()->form( (int) $form_id )->get_fields();
        foreach ( (array) $models as $fid => $fm ) {
            if ( ! is_object( $fm ) || ! method_exists( $fm, 'get_settings' ) ) { continue; }
            $s = $fm->get_settings();
            $type = isset( $s['type'] ) ? (string) $s['type'] : 'unknown';
            if ( in_array( $type, [ 'submit', 'hr', 'html', 'recaptcha' ], true ) ) { continue; }
            $options = [];
            if ( isset( $s['options'] ) && is_array( $s['options'] ) ) {
                foreach ( $s['options'] as $opt ) { if ( isset( $opt['label'] ) ) { $options[] = (string) $opt['label']; } }
            }
            $fields[] = [
                'id'       => (string) $fid,
                'key'      => isset( $s['key'] ) ? (string) $s['key'] : '',
                'type'     => $type,
                'label'    => isset( $s['label'] ) ? (string) $s['label'] : '',
                'required' => ! empty( $s['required'] ),
                'options'  => $options,
            ];
        }
        return $fields;
    }

    public static function build_label_map( $form_id ) {
        $map = [];
        $models = Ninja_Forms()->form( (int) $form_id )->get_fields();
        foreach ( (array) $models as $fid => $fm ) {
            if ( ! is_object( $fm ) || ! method_exists( $fm, 'get_settings' ) ) { continue; }
            $s = $fm->get_settings();
            $map[ (string) $fid ] = isset( $s['label'] ) ? (string) $s['label'] : (string) $fid;
        }
        return $map;
    }

    public static function map_actions( $form_id, $email_only = true ) {
        $out = [];
        $actions = Ninja_Forms()->form( (int) $form_id )->get_actions();
        foreach ( (array) $actions as $aid => $action ) {
            if ( ! is_object( $action ) || ! method_exists( $action, 'get_settings' ) ) { continue; }
            $s = $action->get_settings();
            $type = isset( $s['type'] ) ? (string) $s['type'] : '';
            if ( $email_only && $type !== 'email' ) { continue; }
            $out[] = [
                'id'         => (string) $aid,
                'type'       => $type,
                'name'       => isset( $s['label'] ) ? (string) $s['label'] : ( 'Action ' . $aid ),
                'recipients' => self::parse_recipients( isset( $s['to'] ) ? (string) $s['to'] : '' ),
                'subject'    => isset( $s['email_subject'] ) ? (string) $s['email_subject'] : '',
                'enabled'    => ! isset( $s['active'] ) || ! empty( $s['active'] ),
            ];
        }
        return $out;
    }

    public static function map_form_full( $form_id, $include_raw = false ) {
        $form_id = (int) $form_id;
        $model = Ninja_Forms()->form( $form_id )->get();
        if ( ! $model || ! method_exists( $model, 'get_setting' ) ) { return null; }
        $title = (string) $model->get_setting( 'title' );
        if ( $title === '' && method_exists( $model, 'get_id' ) && ! $model->get_id() ) { return null; }
        $fields = self::map_fields( $form_id );
        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => $title,
            'status'        => 'active',
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => self::map_actions( $form_id, true ),
            'shortcode'     => sprintf( '[ninja_form id=%d]', $form_id ),
            'raw'           => $include_raw && method_exists( $model, 'get_settings' ) ? $model->get_settings() : null,
        ];
    }

    public static function normalize_sub( $sub, $form_id, $label_map, $sub_id = 0, $include_raw = true ) {
        $sub_id = (int) $sub_id;
        if ( ! $sub_id && is_object( $sub ) && method_exists( $sub, 'get_id' ) ) { $sub_id = (int) $sub->get_id(); }
        $form_id = (int) $form_id;
        $fields = [];
        if ( is_object( $sub ) && method_exists( $sub, 'get_field_values' ) ) {
            foreach ( (array) $sub->get_field_values() as $fid => $value ) {
                $fields[] = [ 'id' => (string) $fid, 'label' => isset( $label_map[ (string) $fid ] ) ? $label_map[ (string) $fid ] : (string) $fid, 'type' => '', 'value' => $value ];
            }
        }
        $post = $sub_id > 0 ? get_post( $sub_id ) : null;
        return [
            'id'           => $sub_id,
            'plugin'       => self::PLUGIN_SLUG,
            'form_id'      => $form_id,
            'submitted_at' => $post ? (string) $post->post_date : '',
            'source_url'   => null,
            'ip_address'   => null,
            'user_id'      => $post ? (int) $post->post_author : 0,
            'status'       => ( $post && $post->post_status === 'trash' ) ? 'spam' : 'published',
            'fields'       => $fields,
            'raw'          => $include_raw ? $sub : null,
        ];
    }

    /** Submission post ids for a form, newest first. */
    public static function sub_ids( $form_id, $include_spam, $args = [], $limit = 50, $offset = 0 ) {
        global $wpdb;
        $status_sql = $include_spam ? "p.post_status IN ('publish','trash')" : "p.post_status = 'publish'";
        $where = "p.post_type = 'nf_sub' AND {$status_sql} AND pm.meta_key = '_form_id' AND pm.meta_value = %s";
        $params = [ (string) $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where .= ' AND p.post_date >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where .= ' AND p.post_date <= %s'; $params[] = (string) $args['date_to']; }
        $sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE {$where} ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
        return $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $params, [ (int) $limit, (int) $offset ] ) ) );
    }

    public static function count_submissions( $form_id, $include_spam, $args = [] ) {
        global $wpdb;
        $status_sql = $include_spam ? "p.post_status IN ('publish','trash')" : "p.post_status = 'publish'";
        $where = "p.post_type = 'nf_sub' AND {$status_sql} AND pm.meta_key = '_form_id' AND pm.meta_value = %s";
        $params = [ (string) $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where .= ' AND p.post_date >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where .= ' AND p.post_date <= %s'; $params[] = (string) $args['date_to']; }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE {$where}", $params ) );
    }

    public static function last_submission_at( $form_id ) {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(p.post_date) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'nf_sub' AND p.post_status = 'publish' AND pm.meta_key = '_form_id' AND pm.meta_value = %s", [ (string) $form_id ] ) );
        return $row ? (string) $row : null;
    }

    public static function stats( $form_id, $args = [] ) {
        global $wpdb;
        $where = "p.post_type = 'nf_sub' AND p.post_status = 'publish' AND pm.meta_key = '_form_id' AND pm.meta_value = %s";
        $params = [ (string) $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where .= ' AND p.post_date >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where .= ' AND p.post_date <= %s'; $params[] = (string) $args['date_to']; }
        $join = "FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE {$where}";
        $by_day = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(p.post_date) as day, COUNT(p.ID) as count {$join} GROUP BY DATE(p.post_date) ORDER BY day ASC", $params ), ARRAY_A );
        $ext = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(p.post_date) as first_date, MAX(p.post_date) as last_date, COUNT(p.ID) as total {$join}", $params ), ARRAY_A );
        return [
            'total'      => isset( $ext['total'] ) ? (int) $ext['total'] : 0,
            'first_date' => isset( $ext['first_date'] ) && $ext['first_date'] ? (string) $ext['first_date'] : null,
            'last_date'  => isset( $ext['last_date'] ) && $ext['last_date'] ? (string) $ext['last_date'] : null,
            'by_day'     => is_array( $by_day ) ? $by_day : [],
        ];
    }

    public static function parse_recipients( $str ) {
        if ( (string) $str === '' ) { return []; }
        $out = [];
        foreach ( (array) preg_split( '/[,\n;]+/', (string) $str ) as $part ) {
            $clean = trim( (string) $part );
            if ( $clean !== '' ) { $out[] = $clean; }
        }
        return $out;
    }
}
