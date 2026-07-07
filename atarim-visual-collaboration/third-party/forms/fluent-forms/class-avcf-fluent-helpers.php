<?php
/**
 * Fluent Forms — shared helpers for the standalone Fluent ability cluster.
 *
 * Fluent stores everything in its own tables, accessed here via direct $wpdb
 * (the proven path from the old adapter): fluentform_forms (form_fields JSON),
 * fluentform_submissions (response JSON), fluentform_form_meta (notifications
 * and other settings as JSON value rows). Field definitions are nested —
 * containers wrap children under 'fields', grids under 'columns'[].'fields' —
 * so extraction recurses. Raw-$wpdb on a third-party schema is version-fragile;
 * not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Fluent_Helpers {

    const PLUGIN_SLUG  = 'fluent';
    const PLUGIN_LABEL = 'Fluent Forms';

    public static function forms_table()   { global $wpdb; return $wpdb->prefix . 'fluentform_forms'; }
    public static function subs_table()    { global $wpdb; return $wpdb->prefix . 'fluentform_submissions'; }
    public static function meta_table()    { global $wpdb; return $wpdb->prefix . 'fluentform_form_meta'; }

    public static function map_form_summary( $row ) {
        $form_id = (int) $row->id;
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => (string) $row->title,
            'status'          => (string) $row->status === 'published' ? 'active' : (string) $row->status,
            'entries_total'   => self::count_entries( $form_id, false ),
            'last_submission' => self::last_submission_at( $form_id ),
            'created_at'      => isset( $row->created_at ) ? (string) $row->created_at : null,
        ];
    }

    /** Recursively walk Fluent's nested field structure into a flat list. */
    public static function extract_field( $f, &$fields ) {
        if ( ! is_array( $f ) ) { return; }
        $element = isset( $f['element'] ) ? (string) $f['element'] : '';
        if ( in_array( $element, [ 'submit_button', 'section_break', 'recaptcha', 'hcaptcha', 'turnstile' ], true ) ) { return; }

        if ( isset( $f['fields'] ) && is_array( $f['fields'] ) ) {
            foreach ( $f['fields'] as $child ) { self::extract_field( $child, $fields ); }
            return;
        }
        if ( isset( $f['columns'] ) && is_array( $f['columns'] ) ) {
            foreach ( $f['columns'] as $col ) {
                if ( isset( $col['fields'] ) && is_array( $col['fields'] ) ) {
                    foreach ( $col['fields'] as $child ) { self::extract_field( $child, $fields ); }
                }
            }
            return;
        }

        $attrs    = isset( $f['attributes'] ) && is_array( $f['attributes'] ) ? $f['attributes'] : [];
        $settings = isset( $f['settings'] ) && is_array( $f['settings'] ) ? $f['settings'] : [];
        $name = isset( $attrs['name'] ) ? (string) $attrs['name'] : '';
        if ( $name === '' ) { return; }

        $options = [];
        if ( isset( $settings['advanced_options'] ) && is_array( $settings['advanced_options'] ) ) {
            foreach ( $settings['advanced_options'] as $opt ) { if ( isset( $opt['label'] ) ) { $options[] = (string) $opt['label']; } }
        }

        $fields[] = [
            'id'       => $name,
            'type'     => $element,
            'label'    => isset( $settings['label'] ) ? (string) $settings['label'] : $name,
            'required' => isset( $settings['validation_rules']['required']['value'] ) && ! empty( $settings['validation_rules']['required']['value'] ),
            'options'  => $options,
        ];
    }

    public static function fields_from_row( $row ) {
        $form_fields = isset( $row->form_fields ) ? $row->form_fields : '{}';
        $decoded = json_decode( $form_fields, true );
        $fields = [];
        if ( is_array( $decoded ) && isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
            foreach ( $decoded['fields'] as $f ) { self::extract_field( $f, $fields ); }
        }
        return $fields;
    }

    public static function build_label_map( $fields ) {
        $map = [];
        foreach ( (array) $fields as $f ) {
            if ( isset( $f['id'] ) ) { $map[ (string) $f['id'] ] = isset( $f['label'] ) ? (string) $f['label'] : (string) $f['id']; }
        }
        return $map;
    }

    /** Returns the raw decoded notifications map (nid => notif array), or []. */
    public static function read_notifications_raw( $form_id ) {
        global $wpdb;
        $meta_table = self::meta_table();
        $val = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM `{$meta_table}` WHERE form_id = %d AND meta_key = %s LIMIT 1", (int) $form_id, 'notifications' ) );
        if ( ! $val ) { return []; }
        $decoded = json_decode( $val, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public static function map_notifications( $raw ) {
        $out = [];
        foreach ( (array) $raw as $nid => $n ) {
            if ( ! is_array( $n ) ) { continue; }
            $email = isset( $n['sendTo']['email'] ) ? (string) $n['sendTo']['email'] : ( isset( $n['email'] ) ? (string) $n['email'] : '' );
            $out[] = [
                'id'         => (string) $nid,
                'name'       => isset( $n['name'] ) ? (string) $n['name'] : ( 'Notification ' . $nid ),
                'recipients' => self::parse_recipients( $email ),
                'subject'    => isset( $n['subject'] ) ? (string) $n['subject'] : '',
                'enabled'    => ! isset( $n['enabled'] ) || ! empty( $n['enabled'] ),
            ];
        }
        return $out;
    }

    public static function map_form_full( $row, $include_raw = false ) {
        $form_id = (int) $row->id;
        $fields = self::fields_from_row( $row );
        $notifications = self::map_notifications( self::read_notifications_raw( $form_id ) );
        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => (string) $row->title,
            'status'        => (string) $row->status === 'published' ? 'active' : (string) $row->status,
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => $notifications,
            'created_at'    => isset( $row->created_at ) ? (string) $row->created_at : null,
            'modified_at'   => isset( $row->updated_at ) ? (string) $row->updated_at : null,
            'shortcode'     => sprintf( '[fluentform id="%d"]', $form_id ),
            'raw'           => $include_raw ? $row : null,
        ];
    }

    public static function normalize_entry( $row, $form_id, $label_map, $include_raw = true ) {
        $entry_id = isset( $row->id ) ? (int) $row->id : 0;
        $form_id  = (int) $form_id;
        $response = isset( $row->response ) ? $row->response : '';
        $decoded  = is_string( $response ) ? json_decode( $response, true ) : ( is_array( $response ) ? $response : [] );

        $fields = [];
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $fid => $value ) {
                $fields[] = [ 'id' => (string) $fid, 'label' => isset( $label_map[ (string) $fid ] ) ? $label_map[ (string) $fid ] : (string) $fid, 'type' => '', 'value' => $value ];
            }
        }

        $status_raw = isset( $row->status ) ? (string) $row->status : 'unread';
        $norm = $status_raw === 'spam' ? 'spam' : ( $status_raw === 'trashed' ? 'trash' : 'published' );

        return [
            'id'           => $entry_id,
            'plugin'       => self::PLUGIN_SLUG,
            'form_id'      => $form_id,
            'submitted_at' => isset( $row->created_at ) ? (string) $row->created_at : '',
            'source_url'   => isset( $row->source_url ) ? (string) $row->source_url : null,
            'ip_address'   => isset( $row->ip ) ? (string) $row->ip : null,
            'user_id'      => isset( $row->user_id ) ? (int) $row->user_id : 0,
            'status'       => $norm,
            'fields'       => $fields,
            'raw'          => $include_raw ? $row : null,
        ];
    }

    public static function count_entries( $form_id, $include_spam ) {
        global $wpdb;
        $table = self::subs_table();
        $where = $include_spam ? 'form_id = %d' : "form_id = %d AND status != 'spam'";
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", (int) $form_id ) );
    }

    public static function last_submission_at( $form_id ) {
        global $wpdb;
        $table = self::subs_table();
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM `{$table}` WHERE form_id = %d", (int) $form_id ) );
        return $row ? (string) $row : null;
    }

    public static function stats( $form_id, $args = [] ) {
        global $wpdb;
        $table = self::subs_table();
        $where = [ 'form_id = %d', "status != 'spam'" ]; $params = [ (int) $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'created_at >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where[] = 'created_at <= %s'; $params[] = (string) $args['date_to']; }
        $where_sql = implode( ' AND ', $where );
        $by_day = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) as day, COUNT(*) as count FROM `{$table}` WHERE {$where_sql} GROUP BY DATE(created_at) ORDER BY day ASC", $params ), ARRAY_A );
        $ext = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(created_at) as first_date, MAX(created_at) as last_date, COUNT(*) as total FROM `{$table}` WHERE {$where_sql}", $params ), ARRAY_A );
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
