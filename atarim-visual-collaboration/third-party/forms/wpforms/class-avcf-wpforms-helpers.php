<?php
/**
 * WPForms — shared helpers for the standalone WPForms ability cluster.
 *
 * Lifts the proven access logic out of the old unified adapter so the cluster is
 * fully self-contained. Forms are the `wpforms` CPT with their definition stored
 * as JSON in post_content (wpforms_decode / wpforms_encode); notifications and
 * confirmations live under form_data['settings']. Entries are Pro-only
 * (wpforms()->entry + {prefix}wpforms_entries table); Lite stores none.
 *
 * Built on WPForms' public helpers/API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WPForms_Helpers {

    const PLUGIN_SLUG  = 'wpforms';
    const PLUGIN_LABEL = 'WPForms';
    const LITE_NOTE    = 'WPForms Lite does not store form entries — upgrade to WPForms Pro for entry storage and reporting.';

    public static function map_form_summary( $post, $is_pro ) {
        $form_id = (int) $post->ID;
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => (string) $post->post_title,
            'status'          => $post->post_status === 'publish' ? 'active' : (string) $post->post_status,
            'entries_total'   => $is_pro ? self::count_entries( $form_id, false ) : null,
            'last_submission' => $is_pro ? self::last_submission_at( $form_id ) : null,
            'created_at'      => (string) $post->post_date,
        ];
    }

    public static function map_field( $fid, $f ) {
        $options = [];
        if ( isset( $f['choices'] ) && is_array( $f['choices'] ) ) {
            foreach ( $f['choices'] as $choice ) {
                if ( isset( $choice['label'] ) ) { $options[] = (string) $choice['label']; }
            }
        }
        return [
            'id'        => (string) $fid,
            'type'      => isset( $f['type'] ) ? (string) $f['type'] : 'unknown',
            'label'     => isset( $f['label'] ) ? (string) $f['label'] : '',
            'required'  => ! empty( $f['required'] ),
            'options'   => $options,
        ];
    }

    public static function map_form_full( $post, $form_data, $include_raw = false ) {
        if ( ! is_array( $form_data ) ) { $form_data = []; }
        $form_id = (int) $post->ID;

        $fields = [];
        if ( isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ) {
            foreach ( $form_data['fields'] as $fid => $f ) {
                if ( is_array( $f ) ) { $fields[] = self::map_field( $fid, $f ); }
            }
        }

        $notifications = [];
        $nset = isset( $form_data['settings']['notifications'] ) && is_array( $form_data['settings']['notifications'] ) ? $form_data['settings']['notifications'] : [];
        foreach ( $nset as $nid => $n ) {
            if ( ! is_array( $n ) ) { continue; }
            $notifications[] = [
                'id'         => (string) $nid,
                'name'       => isset( $n['notification_name'] ) ? (string) $n['notification_name'] : ( 'Notification ' . $nid ),
                'recipients' => self::parse_recipients( isset( $n['email'] ) ? (string) $n['email'] : '' ),
                'subject'    => isset( $n['subject'] ) ? (string) $n['subject'] : '',
                'enabled'    => ! isset( $n['enable'] ) || ! empty( $n['enable'] ),
            ];
        }

        $confirmations = [];
        $cset = isset( $form_data['settings']['confirmations'] ) && is_array( $form_data['settings']['confirmations'] ) ? $form_data['settings']['confirmations'] : [];
        foreach ( $cset as $cid => $c ) {
            if ( ! is_array( $c ) ) { continue; }
            $confirmations[] = [
                'id'   => (string) $cid,
                'name' => isset( $c['name'] ) ? (string) $c['name'] : '',
                'type' => isset( $c['type'] ) ? (string) $c['type'] : 'message',
            ];
        }

        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : (string) $post->post_title,
            'status'        => $post->post_status === 'publish' ? 'active' : (string) $post->post_status,
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => $notifications,
            'confirmations' => $confirmations,
            'created_at'    => (string) $post->post_date,
            'modified_at'   => (string) $post->post_modified,
            'shortcode'     => sprintf( '[wpforms id="%d"]', $form_id ),
            'raw'           => $include_raw ? $form_data : null,
        ];
    }

    public static function normalize_entry( $raw_entry, $form_id, $include_raw = true ) {
        $entry_id = isset( $raw_entry->entry_id ) ? (int) $raw_entry->entry_id : 0;
        $form_id  = (int) $form_id;

        $fields = [];
        $raw_fields = isset( $raw_entry->fields ) ? $raw_entry->fields : '';
        if ( is_string( $raw_fields ) && $raw_fields !== '' ) {
            $decoded = wpforms_decode( $raw_fields );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $fid => $field ) {
                    if ( ! is_array( $field ) ) { continue; }
                    $fields[] = [ 'id' => (string) $fid, 'label' => isset( $field['name'] ) ? (string) $field['name'] : '', 'type' => isset( $field['type'] ) ? (string) $field['type'] : '', 'value' => isset( $field['value'] ) ? $field['value'] : '' ];
                }
            }
        }

        $status = isset( $raw_entry->status ) ? (string) $raw_entry->status : '';
        $norm = $status === 'spam' ? 'spam' : ( $status === 'trash' ? 'trash' : 'published' );

        return [
            'id'           => $entry_id,
            'plugin'       => self::PLUGIN_SLUG,
            'form_id'      => $form_id,
            'submitted_at' => isset( $raw_entry->date ) ? (string) $raw_entry->date : '',
            'source_url'   => isset( $raw_entry->referer ) ? (string) $raw_entry->referer : null,
            'ip_address'   => isset( $raw_entry->ip_address ) ? (string) $raw_entry->ip_address : null,
            'user_id'      => isset( $raw_entry->user_id ) ? (int) $raw_entry->user_id : 0,
            'starred'      => isset( $raw_entry->starred ) ? (bool) $raw_entry->starred : false,
            'viewed'       => isset( $raw_entry->viewed ) ? (bool) $raw_entry->viewed : false,
            'status'       => $norm,
            'fields'       => $fields,
            'raw'          => $include_raw ? $raw_entry : null,
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

    public static function count_entries( $form_id, $include_spam, $args = [] ) {
        global $wpdb;
        $form_id = (int) $form_id;
        $table = $wpdb->prefix . 'wpforms_entries';
        $where = [ 'form_id = %d' ]; $params = [ $form_id ];
        if ( ! $include_spam ) { $where[] = "status != 'spam'"; }
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'date >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where[] = 'date <= %s'; $params[] = (string) $args['date_to']; }
        $where_sql = implode( ' AND ', $where );
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $params ) );
        return $count === null ? 0 : (int) $count;
    }

    public static function last_submission_at( $form_id ) {
        global $wpdb;
        $form_id = (int) $form_id;
        $table = $wpdb->prefix . 'wpforms_entries';
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(date) FROM `{$table}` WHERE form_id = %d", $form_id ) );
        return $row ? (string) $row : null;
    }

    public static function stats( $form_id, $args = [] ) {
        global $wpdb;
        $form_id = (int) $form_id;
        $table = $wpdb->prefix . 'wpforms_entries';
        $where = [ 'form_id = %d' ]; $params = [ $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'date >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where[] = 'date <= %s'; $params[] = (string) $args['date_to']; }
        $where_sql = implode( ' AND ', $where );
        $by_day = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(date) AS day, COUNT(*) AS count FROM `{$table}` WHERE {$where_sql} GROUP BY DATE(date) ORDER BY day ASC", $params ), 'ARRAY_A' );
        $ext = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(date) AS first_date, MAX(date) AS last_date, COUNT(*) AS total FROM `{$table}` WHERE {$where_sql}", $params ), 'ARRAY_A' );
        return [
            'total'      => $ext && isset( $ext['total'] ) ? (int) $ext['total'] : 0,
            'first_date' => $ext && isset( $ext['first_date'] ) ? $ext['first_date'] : null,
            'last_date'  => $ext && isset( $ext['last_date'] ) ? $ext['last_date'] : null,
            'by_day'     => is_array( $by_day ) ? $by_day : [],
        ];
    }
}
