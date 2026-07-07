<?php
/**
 * Contact Form 7 — shared helpers for the standalone CF7 ability cluster.
 *
 * CF7 config is read/written through the WPCF7_ContactForm model (prop /
 * set_properties / save); fields are parsed from the [form] template markup;
 * notifications are the "mail" and "mail_2" templates. CF7 stores no
 * submissions — the entry side lives in the separate Flamingo cluster. Built on
 * CF7's model; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_CF7_Helpers {

    const PLUGIN_SLUG  = 'cf7';
    const PLUGIN_LABEL = 'Contact Form 7';

    public static function get_cf7( $form_id ) {
        return function_exists( 'wpcf7_contact_form' ) ? wpcf7_contact_form( (int) $form_id ) : null;
    }

    public static function parse_fields( $template ) {
        $fields = [];
        if ( (string) $template === '' ) { return $fields; }
        if ( preg_match_all( '/\[([a-z][a-z0-9_]*\*?)\s+([a-zA-Z0-9_\-]+)/', (string) $template, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $type = (string) $m[1];
                $required = ( substr( $type, -1 ) === '*' );
                if ( $required ) { $type = substr( $type, 0, -1 ); }
                if ( in_array( $type, [ 'submit', 'response' ], true ) ) { continue; }
                $fields[] = [ 'id' => (string) $m[2], 'type' => $type, 'label' => (string) $m[2], 'required' => $required, 'options' => [] ];
            }
        }
        return $fields;
    }

    public static function map_mail( $mail, $key ) {
        if ( ! is_array( $mail ) ) { return null; }
        return [
            'id'          => $key,
            'name'        => $key === 'mail' ? 'Mail' : 'Mail (2)',
            'recipient'   => isset( $mail['recipient'] ) ? (string) $mail['recipient'] : '',
            'recipients'  => self::parse_recipients( isset( $mail['recipient'] ) ? (string) $mail['recipient'] : '' ),
            'sender'      => isset( $mail['sender'] ) ? (string) $mail['sender'] : '',
            'subject'     => isset( $mail['subject'] ) ? (string) $mail['subject'] : '',
            'body'        => isset( $mail['body'] ) ? (string) $mail['body'] : '',
            'additional_headers' => isset( $mail['additional_headers'] ) ? (string) $mail['additional_headers'] : '',
            'use_html'    => ! empty( $mail['use_html'] ),
            'active'      => $key === 'mail' ? true : ! empty( $mail['active'] ),
        ];
    }

    public static function map_form_summary( $post ) {
        $form_id = (int) $post->ID;
        return [
            'id'              => $form_id,
            'plugin'          => self::PLUGIN_SLUG,
            'title'           => (string) $post->post_title,
            'status'          => $post->post_status === 'publish' ? 'active' : (string) $post->post_status,
            'entries_total'   => null,
            'last_submission' => null,
            'created_at'      => (string) $post->post_date,
        ];
    }

    public static function map_form_full( $post, $cf7 ) {
        $form_id = (int) $post->ID;
        $fields = [];
        $notifications = [];
        if ( $cf7 && method_exists( $cf7, 'prop' ) ) {
            $fields = self::parse_fields( (string) $cf7->prop( 'form' ) );
            foreach ( [ 'mail', 'mail_2' ] as $key ) {
                $m = self::map_mail( $cf7->prop( $key ), $key );
                if ( $m && $m['recipient'] !== '' ) {
                    $notifications[] = [ 'id' => $key, 'name' => $m['name'], 'recipients' => $m['recipients'], 'subject' => $m['subject'], 'enabled' => $m['active'] ];
                }
            }
        }
        return [
            'id'            => $form_id,
            'plugin'        => self::PLUGIN_SLUG,
            'title'         => (string) $post->post_title,
            'status'        => $post->post_status === 'publish' ? 'active' : (string) $post->post_status,
            'field_count'   => count( $fields ),
            'fields'        => $fields,
            'notifications' => $notifications,
            'created_at'    => (string) $post->post_date,
            'modified_at'   => (string) $post->post_modified,
            'shortcode'     => sprintf( '[contact-form-7 id="%d" title="%s"]', $form_id, esc_attr( $post->post_title ) ),
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
