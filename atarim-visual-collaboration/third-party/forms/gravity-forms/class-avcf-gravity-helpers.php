<?php
/**
 * Gravity Forms — shared helpers for the standalone GF ability cluster.
 *
 * Lifts the proven GFAPI mapping/stats logic out of the old unified adapter so
 * the GF cluster is fully self-contained (no shared forms base). Public API used:
 *   GFAPI::get_forms / get_form / get_entries / get_entry / count_entries
 *   GFAPI::update_entry / update_form / add_form / add_entry / delete_*  (writers in -pro)
 *   {prefix}gf_entry table for stats / last-submission.
 *
 * Built on Gravity's public API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Gravity_Helpers {

    const PLUGIN_SLUG  = 'gravity';
    const PLUGIN_LABEL = 'Gravity Forms';

    public static function map_form_summary( $f ) {
        $form_id = isset( $f['id'] ) ? (int) $f['id'] : 0;
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => isset( $f['title'] ) ? (string) $f['title'] : '',
            'status'          => empty( $f['is_active'] ) ? 'inactive' : 'active',
            'entries_total'   => $form_id > 0 ? (int) GFAPI::count_entries( $form_id ) : 0,
            'last_submission' => $form_id > 0 ? self::last_submission_at( $form_id ) : null,
            'created_at'      => isset( $f['date_created'] ) ? (string) $f['date_created'] : null,
        ];
    }

    public static function map_field( $f ) {
        $fid      = is_object( $f ) ? (string) $f->id : ( isset( $f['id'] ) ? (string) $f['id'] : '' );
        $type     = is_object( $f ) ? (string) $f->type : ( isset( $f['type'] ) ? (string) $f['type'] : 'unknown' );
        $label    = is_object( $f ) ? (string) $f->label : ( isset( $f['label'] ) ? (string) $f['label'] : '' );
        $required = is_object( $f ) ? ! empty( $f->isRequired ) : ! empty( $f['isRequired'] );
        $admin    = is_object( $f ) ? ( property_exists( $f, 'adminLabel' ) ? (string) $f->adminLabel : '' ) : ( isset( $f['adminLabel'] ) ? (string) $f['adminLabel'] : '' );
        $choices  = is_object( $f ) ? ( property_exists( $f, 'choices' ) ? $f->choices : null ) : ( isset( $f['choices'] ) ? $f['choices'] : null );
        $options  = [];
        if ( is_array( $choices ) ) {
            foreach ( $choices as $choice ) {
                if ( isset( $choice['text'] ) ) { $options[] = (string) $choice['text']; }
            }
        }
        $cond = is_object( $f ) ? ( property_exists( $f, 'conditionalLogic' ) ? $f->conditionalLogic : null ) : ( isset( $f['conditionalLogic'] ) ? $f['conditionalLogic'] : null );
        return [
            'id'                => $fid,
            'type'              => $type,
            'label'             => $label,
            'admin_label'       => $admin,
            'required'          => (bool) $required,
            'options'           => $options,
            'has_conditional'   => ! empty( $cond ),
        ];
    }

    public static function map_form_full( $form, $include_raw = false ) {
        if ( ! is_array( $form ) ) { return null; }
        $form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

        $fields = [];
        if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $f ) { $fields[] = self::map_field( $f ); }
        }

        $notifications = [];
        if ( isset( $form['notifications'] ) && is_array( $form['notifications'] ) ) {
            foreach ( $form['notifications'] as $nid => $n ) {
                if ( ! is_array( $n ) ) { continue; }
                $notifications[] = [
                    'id'         => (string) $nid,
                    'name'       => isset( $n['name'] ) ? (string) $n['name'] : ( 'Notification ' . $nid ),
                    'event'      => isset( $n['event'] ) ? (string) $n['event'] : 'form_submission',
                    'to_type'    => isset( $n['toType'] ) ? (string) $n['toType'] : '',
                    'recipients' => self::parse_recipients( isset( $n['to'] ) ? (string) $n['to'] : '' ),
                    'subject'    => isset( $n['subject'] ) ? (string) $n['subject'] : '',
                    'enabled'    => ! isset( $n['isActive'] ) || ! empty( $n['isActive'] ),
                ];
            }
        }

        $confirmations = [];
        if ( isset( $form['confirmations'] ) && is_array( $form['confirmations'] ) ) {
            foreach ( $form['confirmations'] as $cid => $c ) {
                if ( ! is_array( $c ) ) { continue; }
                $confirmations[] = [
                    'id'      => (string) $cid,
                    'name'    => isset( $c['name'] ) ? (string) $c['name'] : '',
                    'type'    => isset( $c['type'] ) ? (string) $c['type'] : 'message',
                    'is_default' => ! empty( $c['isDefault'] ),
                ];
            }
        }

        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => isset( $form['title'] ) ? (string) $form['title'] : '',
            'description'   => isset( $form['description'] ) ? (string) $form['description'] : '',
            'status'        => empty( $form['is_active'] ) ? 'inactive' : 'active',
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => $notifications,
            'confirmations' => $confirmations,
            'created_at'    => isset( $form['date_created'] ) ? (string) $form['date_created'] : null,
            'shortcode'     => sprintf( '[gravityform id="%d" title="false" description="false"]', $form_id ),
            'raw'           => $include_raw ? $form : null,
        ];
    }

    public static function normalize_entry( $raw_entry, $form_id, $form = null, $include_raw = true ) {
        $entry_id = isset( $raw_entry['id'] ) ? (int) $raw_entry['id'] : 0;
        $form_id  = (int) $form_id;

        $field_meta = [];
        if ( is_array( $form ) && isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $f ) {
                $fid = is_object( $f ) ? (string) $f->id : ( isset( $f['id'] ) ? (string) $f['id'] : '' );
                if ( $fid === '' ) { continue; }
                $field_meta[ $fid ] = [
                    'type'  => is_object( $f ) ? (string) $f->type : ( isset( $f['type'] ) ? (string) $f['type'] : '' ),
                    'label' => is_object( $f ) ? (string) $f->label : ( isset( $f['label'] ) ? (string) $f['label'] : '' ),
                ];
            }
        }

        $reserved = [
            'id','form_id','date_created','date_updated','is_starred','is_read',
            'ip','source_url','post_id','currency','payment_status','payment_date',
            'payment_amount','payment_method','transaction_id','is_fulfilled',
            'created_by','transaction_type','user_agent','status',
        ];
        $fields = [];
        foreach ( (array) $raw_entry as $key => $value ) {
            if ( in_array( $key, $reserved, true ) ) { continue; }
            if ( ! is_numeric( $key ) && ! preg_match( '/^\d+\.\d+$/', (string) $key ) ) { continue; }
            $major = (string) (int) $key;
            $meta  = isset( $field_meta[ $major ] ) ? $field_meta[ $major ] : [ 'type' => '', 'label' => '' ];
            $fields[] = [ 'id' => (string) $key, 'label' => $meta['label'], 'type' => $meta['type'], 'value' => $value ];
        }

        $status = isset( $raw_entry['status'] ) ? (string) $raw_entry['status'] : 'active';
        $norm   = $status === 'spam' ? 'spam' : ( $status === 'trash' ? 'trash' : 'published' );

        return [
            'id'           => $entry_id,
            'plugin'       => self::PLUGIN_SLUG,
            'form_id'      => $form_id,
            'submitted_at' => isset( $raw_entry['date_created'] ) ? (string) $raw_entry['date_created'] : '',
            'source_url'   => isset( $raw_entry['source_url'] ) ? (string) $raw_entry['source_url'] : null,
            'ip_address'   => isset( $raw_entry['ip'] ) ? (string) $raw_entry['ip'] : null,
            'user_id'      => isset( $raw_entry['created_by'] ) ? (int) $raw_entry['created_by'] : 0,
            'payment_status'=> isset( $raw_entry['payment_status'] ) ? (string) $raw_entry['payment_status'] : null,
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

    public static function last_submission_at( $form_id ) {
        global $wpdb;
        $form_id = (int) $form_id;
        $table = $wpdb->prefix . 'gf_entry';
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(date_created) FROM `{$table}` WHERE form_id = %d", $form_id ) );
        return $row ? (string) $row : null;
    }

    /** Per-day counts + extremes for a form, optional date window. */
    public static function stats( $form_id, $args = [] ) {
        global $wpdb;
        $form_id = (int) $form_id;
        $table = $wpdb->prefix . 'gf_entry';
        $where = [ 'form_id = %d' ]; $params = [ $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'date_created >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where[] = 'date_created <= %s'; $params[] = (string) $args['date_to']; }
        $where_sql = implode( ' AND ', $where );
        $by_day = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(date_created) AS day, COUNT(*) AS count FROM `{$table}` WHERE {$where_sql} GROUP BY DATE(date_created) ORDER BY day ASC", $params ), 'ARRAY_A' );
        $ext    = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(date_created) AS first_date, MAX(date_created) AS last_date, COUNT(*) AS total FROM `{$table}` WHERE {$where_sql}", $params ), 'ARRAY_A' );
        return [
            'total'      => $ext && isset( $ext['total'] ) ? (int) $ext['total'] : 0,
            'first_date' => $ext && isset( $ext['first_date'] ) ? $ext['first_date'] : null,
            'last_date'  => $ext && isset( $ext['last_date'] ) ? $ext['last_date'] : null,
            'by_day'     => is_array( $by_day ) ? $by_day : [],
        ];
    }
}
