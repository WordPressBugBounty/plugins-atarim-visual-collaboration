<?php
/**
 * Formidable Forms — shared helpers for the standalone Formidable ability cluster.
 *
 * Uses Formidable's stable PHP API for forms/fields/notifications/entries
 * (FrmForm, FrmField, FrmFormAction, FrmEntry) and direct $wpdb on frm_items
 * for stats/counts. Entry status lives in the is_draft column: 0 = published,
 * 1 = draft, 2 = spam. Entries are stored by Formidable core (free), so reads
 * are not Pro-gated. Built on Formidable's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Formidable_Helpers {

    const PLUGIN_SLUG  = 'formidable';
    const PLUGIN_LABEL = 'Formidable Forms';

    public static function items_table() { global $wpdb; return $wpdb->prefix . 'frm_items'; }

    public static function map_form_summary( $f ) {
        $form_id = isset( $f->id ) ? (int) $f->id : 0;
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => isset( $f->name ) ? (string) $f->name : '',
            'status'          => isset( $f->status ) ? (string) $f->status : 'published',
            'entries_total'   => $form_id > 0 ? self::count_entries( $form_id, false ) : 0,
            'last_submission' => $form_id > 0 ? self::last_submission_at( $form_id ) : null,
            'created_at'      => isset( $f->created_at ) ? (string) $f->created_at : null,
        ];
    }

    public static function map_fields( $form_id ) {
        $fields = [];
        if ( ! class_exists( 'FrmField' ) ) { return $fields; }
        $form_fields = FrmField::get_all_for_form( (int) $form_id );
        foreach ( (array) $form_fields as $field ) {
            if ( ! isset( $field->id ) ) { continue; }
            $type = isset( $field->type ) ? (string) $field->type : 'unknown';
            if ( in_array( $type, [ 'end_divider', 'divider', 'break', 'captcha', 'html' ], true ) ) { continue; }
            $options = [];
            if ( isset( $field->options ) ) {
                $choices = is_string( $field->options ) ? maybe_unserialize( $field->options ) : $field->options;
                if ( is_array( $choices ) ) {
                    foreach ( $choices as $choice ) {
                        if ( is_array( $choice ) && isset( $choice['label'] ) ) { $options[] = (string) $choice['label']; }
                        elseif ( is_string( $choice ) ) { $options[] = $choice; }
                    }
                }
            }
            $fields[] = [
                'id'       => (string) $field->id,
                'key'      => isset( $field->field_key ) ? (string) $field->field_key : '',
                'type'     => $type,
                'label'    => isset( $field->name ) ? (string) $field->name : '',
                'required' => ! empty( $field->required ),
                'options'  => $options,
            ];
        }
        return $fields;
    }

    public static function map_notifications( $form_id ) {
        $notifications = [];
        if ( class_exists( 'FrmFormAction' ) && method_exists( 'FrmFormAction', 'get_action_for_form' ) ) {
            $actions = FrmFormAction::get_action_for_form( (int) $form_id, 'email' );
            if ( is_array( $actions ) ) {
                foreach ( $actions as $action ) {
                    if ( ! is_object( $action ) ) { continue; }
                    $aid = isset( $action->ID ) ? (string) $action->ID : ( isset( $action->id ) ? (string) $action->id : '' );
                    $settings = isset( $action->post_content ) && is_array( $action->post_content ) ? $action->post_content : [];
                    $notifications[] = [
                        'id'         => $aid,
                        'name'       => isset( $action->post_title ) ? (string) $action->post_title : ( 'Email ' . $aid ),
                        'recipients' => self::parse_recipients( isset( $settings['email_to'] ) ? (string) $settings['email_to'] : '' ),
                        'subject'    => isset( $settings['email_subject'] ) ? (string) $settings['email_subject'] : '',
                        'enabled'    => ! ( isset( $action->post_status ) && $action->post_status === 'draft' ),
                    ];
                }
            }
        }
        return $notifications;
    }

    public static function map_form_full( $form, $include_raw = false ) {
        $form_id = (int) $form->id;
        $fields = self::map_fields( $form_id );
        $options = isset( $form->options ) && is_array( $form->options ) ? $form->options : ( is_string( $form->options ) ? maybe_unserialize( $form->options ) : [] );
        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => isset( $form->name ) ? (string) $form->name : '',
            'status'        => isset( $form->status ) ? (string) $form->status : 'published',
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => self::map_notifications( $form_id ),
            'created_at'    => isset( $form->created_at ) ? (string) $form->created_at : null,
            'shortcode'     => sprintf( '[formidable id=%d]', $form_id ),
            'raw'           => $include_raw ? $options : null,
        ];
    }

    public static function build_label_map( $fields ) {
        $map = [];
        foreach ( (array) $fields as $f ) {
            if ( isset( $f['id'] ) ) { $map[ (string) $f['id'] ] = isset( $f['label'] ) ? (string) $f['label'] : (string) $f['id']; }
        }
        return $map;
    }

    public static function normalize_entry( $raw_entry, $form_id, $label_map, $include_raw = true ) {
        $entry_id = isset( $raw_entry->id ) ? (int) $raw_entry->id : 0;
        $form_id  = (int) $form_id;
        $fields = [];
        $metas = isset( $raw_entry->metas ) && is_array( $raw_entry->metas ) ? $raw_entry->metas : [];
        foreach ( $metas as $field_id => $value ) {
            $fields[] = [ 'id' => (string) $field_id, 'label' => isset( $label_map[ (string) $field_id ] ) ? $label_map[ (string) $field_id ] : (string) $field_id, 'type' => '', 'value' => $value ];
        }
        $is_draft = isset( $raw_entry->is_draft ) ? (int) $raw_entry->is_draft : 0;
        $status = $is_draft === 2 ? 'spam' : ( $is_draft === 1 ? 'draft' : 'published' );
        return [
            'id'           => $entry_id,
            'plugin'       => self::PLUGIN_SLUG,
            'form_id'      => $form_id,
            'submitted_at' => isset( $raw_entry->created_at ) ? (string) $raw_entry->created_at : '',
            'source_url'   => isset( $raw_entry->source_url ) ? (string) $raw_entry->source_url : null,
            'ip_address'   => isset( $raw_entry->ip ) ? (string) $raw_entry->ip : null,
            'user_id'      => isset( $raw_entry->user_id ) ? (int) $raw_entry->user_id : 0,
            'item_key'     => isset( $raw_entry->item_key ) ? (string) $raw_entry->item_key : '',
            'status'       => $status,
            'fields'       => $fields,
            'raw'          => $include_raw ? $raw_entry : null,
        ];
    }

    public static function count_entries( $form_id, $include_spam ) {
        global $wpdb;
        $table = self::items_table();
        $where = $include_spam ? 'form_id = %d' : 'form_id = %d AND (is_draft != 2 OR is_draft IS NULL)';
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", (int) $form_id ) );
    }

    public static function last_submission_at( $form_id ) {
        global $wpdb;
        $table = self::items_table();
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM `{$table}` WHERE form_id = %d", (int) $form_id ) );
        return $row ? (string) $row : null;
    }

    public static function stats( $form_id, $args = [] ) {
        global $wpdb;
        $table = self::items_table();
        $where = [ 'form_id = %d', '(is_draft != 2 OR is_draft IS NULL)' ]; $params = [ (int) $form_id ];
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
