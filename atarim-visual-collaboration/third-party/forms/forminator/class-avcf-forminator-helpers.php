<?php
/**
 * Forminator — shared helpers for the standalone Forminator ability cluster.
 *
 * Uses Forminator_API for forms/entries (the form model exposes ->fields and
 * ->settings; entries expose ->meta_data and ->is_spam) and direct $wpdb on
 * frmt_form_entry for stats/counts (is_spam column flags spam). Notifications
 * live in the form settings under 'notifications' or 'email-notifications'.
 * Built on Forminator's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Forminator_Helpers {

    const PLUGIN_SLUG  = 'forminator';
    const PLUGIN_LABEL = 'Forminator';

    public static function entry_table() { global $wpdb; return $wpdb->prefix . 'frmt_form_entry'; }

    public static function settings_array( $form ) {
        if ( is_object( $form ) && property_exists( $form, 'settings' ) && is_array( $form->settings ) ) { return $form->settings; }
        return [];
    }

    public static function extract_id( $form ) {
        if ( is_object( $form ) && property_exists( $form, 'id' ) ) { return (int) $form->id; }
        if ( is_array( $form ) && isset( $form['id'] ) ) { return (int) $form['id']; }
        return 0;
    }

    public static function extract_title( $form ) {
        $s = self::settings_array( $form );
        if ( isset( $s['formName'] ) ) { return (string) $s['formName']; }
        if ( isset( $s['form-name'] ) ) { return (string) $s['form-name']; }
        if ( is_object( $form ) && property_exists( $form, 'name' ) ) { return (string) $form->name; }
        return '';
    }

    public static function extract_status( $form ) {
        if ( is_object( $form ) && property_exists( $form, 'status' ) ) { return $form->status === 'publish' ? 'active' : (string) $form->status; }
        return 'active';
    }

    public static function extract_created( $form ) {
        if ( is_object( $form ) && property_exists( $form, 'raw' ) && isset( $form->raw->post_date ) ) { return (string) $form->raw->post_date; }
        return null;
    }

    public static function map_form_summary( $form ) {
        $form_id = self::extract_id( $form );
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => self::extract_title( $form ),
            'status'          => self::extract_status( $form ),
            'entries_total'   => $form_id > 0 ? self::count_entries( $form_id, false ) : 0,
            'last_submission' => $form_id > 0 ? self::last_submission_at( $form_id ) : null,
            'created_at'      => self::extract_created( $form ),
        ];
    }

    public static function map_fields( $form ) {
        $fields = [];
        $raw_fields = is_object( $form ) && property_exists( $form, 'fields' ) ? $form->fields : [];
        if ( ! is_array( $raw_fields ) ) { return $fields; }
        foreach ( $raw_fields as $f ) {
            $slug = is_object( $f ) && property_exists( $f, 'slug' ) ? (string) $f->slug : ( is_array( $f ) && isset( $f['slug'] ) ? (string) $f['slug'] : '' );
            $type = is_object( $f ) && property_exists( $f, 'type' ) ? (string) $f->type : ( is_array( $f ) && isset( $f['type'] ) ? (string) $f['type'] : 'unknown' );
            $fs = ( is_object( $f ) && property_exists( $f, 'raw' ) && is_array( $f->raw ) ) ? $f->raw : ( is_array( $f ) ? $f : [] );
            $options = [];
            if ( isset( $fs['options'] ) && is_array( $fs['options'] ) ) {
                foreach ( $fs['options'] as $opt ) { if ( isset( $opt['label'] ) ) { $options[] = (string) $opt['label']; } }
            }
            $fields[] = [
                'id'       => $slug,
                'type'     => $type,
                'label'    => isset( $fs['field_label'] ) ? (string) $fs['field_label'] : $slug,
                'required' => ! empty( $fs['required'] ),
                'options'  => $options,
            ];
        }
        return $fields;
    }

    /** Returns [ key, list ] where key is the settings key in use, list is the notification rows. */
    public static function notifications_block( $settings ) {
        $key = isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ? 'notifications'
             : ( isset( $settings['email-notifications'] ) && is_array( $settings['email-notifications'] ) ? 'email-notifications' : null );
        return [ $key, $key === null ? [] : $settings[ $key ] ];
    }

    public static function map_notifications( $settings ) {
        $list = self::notifications_block( $settings );
        $out = [];
        foreach ( (array) $list[1] as $idx => $n ) {
            if ( ! is_array( $n ) ) { continue; }
            $out[] = [
                'id'         => (string) ( isset( $n['slug'] ) ? $n['slug'] : $idx ),
                'name'       => isset( $n['label'] ) ? (string) $n['label'] : ( 'Notification ' . $idx ),
                'recipients' => self::parse_recipients( isset( $n['recipients'] ) ? (string) $n['recipients'] : ( isset( $n['email-recipients'] ) ? (string) $n['email-recipients'] : '' ) ),
                'subject'    => isset( $n['email-subject'] ) ? (string) $n['email-subject'] : ( isset( $n['subject'] ) ? (string) $n['subject'] : '' ),
                'enabled'    => true,
            ];
        }
        return $out;
    }

    public static function map_form_full( $form, $include_raw = false ) {
        $form_id = self::extract_id( $form );
        $settings = self::settings_array( $form );
        $fields = self::map_fields( $form );
        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => self::extract_title( $form ),
            'status'        => self::extract_status( $form ),
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => self::map_notifications( $settings ),
            'created_at'    => self::extract_created( $form ),
            'shortcode'     => sprintf( '[forminator_form id="%d"]', $form_id ),
            'raw'           => $include_raw ? $settings : null,
        ];
    }

    public static function build_label_map( $form ) {
        $map = [];
        if ( is_object( $form ) && property_exists( $form, 'fields' ) && is_array( $form->fields ) ) {
            foreach ( $form->fields as $f ) {
                $slug = is_object( $f ) && property_exists( $f, 'slug' ) ? (string) $f->slug : '';
                if ( $slug === '' ) { continue; }
                $fs = ( is_object( $f ) && property_exists( $f, 'raw' ) && is_array( $f->raw ) ) ? $f->raw : [];
                $map[ $slug ] = isset( $fs['field_label'] ) ? (string) $fs['field_label'] : $slug;
            }
        }
        return $map;
    }

    public static function entry_is_spam( $entry ) {
        return is_object( $entry ) && property_exists( $entry, 'is_spam' ) ? (bool) $entry->is_spam : false;
    }

    public static function normalize_entry( $raw_entry, $form_id, $label_map, $include_raw = true ) {
        $entry_id = is_object( $raw_entry ) && property_exists( $raw_entry, 'entry_id' ) ? (int) $raw_entry->entry_id : 0;
        $form_id  = (int) $form_id;
        $fields = [];
        $meta = is_object( $raw_entry ) && property_exists( $raw_entry, 'meta_data' ) && is_array( $raw_entry->meta_data ) ? $raw_entry->meta_data : [];
        foreach ( $meta as $slug => $m ) {
            $fields[] = [ 'id' => (string) $slug, 'label' => isset( $label_map[ $slug ] ) ? $label_map[ $slug ] : (string) $slug, 'type' => '', 'value' => isset( $m['value'] ) ? $m['value'] : '' ];
        }
        return [
            'id'           => $entry_id,
            'plugin'       => self::PLUGIN_SLUG,
            'form_id'      => $form_id,
            'submitted_at' => is_object( $raw_entry ) && property_exists( $raw_entry, 'date_created_sql' ) ? (string) $raw_entry->date_created_sql : '',
            'source_url'   => null,
            'ip_address'   => null,
            'user_id'      => 0,
            'status'       => self::entry_is_spam( $raw_entry ) ? 'spam' : 'published',
            'fields'       => $fields,
            'raw'          => $include_raw ? $raw_entry : null,
        ];
    }

    public static function count_entries( $form_id, $include_spam ) {
        global $wpdb;
        $table = self::entry_table();
        $where = $include_spam ? 'form_id = %d' : 'form_id = %d AND is_spam = 0';
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", (int) $form_id ) );
    }

    public static function last_submission_at( $form_id ) {
        global $wpdb;
        $table = self::entry_table();
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(date_created) FROM `{$table}` WHERE form_id = %d", (int) $form_id ) );
        return $row ? (string) $row : null;
    }

    public static function stats( $form_id, $args = [] ) {
        global $wpdb;
        $table = self::entry_table();
        $where = [ 'form_id = %d', 'is_spam = 0' ]; $params = [ (int) $form_id ];
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'date_created >= %s'; $params[] = (string) $args['date_from']; }
        if ( ! empty( $args['date_to'] ) )   { $where[] = 'date_created <= %s'; $params[] = (string) $args['date_to']; }
        $where_sql = implode( ' AND ', $where );
        $by_day = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(date_created) as day, COUNT(*) as count FROM `{$table}` WHERE {$where_sql} GROUP BY DATE(date_created) ORDER BY day ASC", $params ), ARRAY_A );
        $ext = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(date_created) as first_date, MAX(date_created) as last_date, COUNT(*) as total FROM `{$table}` WHERE {$where_sql}", $params ), ARRAY_A );
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
