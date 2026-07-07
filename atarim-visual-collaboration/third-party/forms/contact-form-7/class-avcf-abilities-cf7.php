<?php
/**
 * Contact Form 7 — core MCP abilities (standalone cluster).
 *
 * CF7's native surface is config-only (it stores no submissions), so the core
 * is shaped to CF7's reality: form list/get, the [form] template, the mail /
 * mail_2 notification templates, the messages, additional settings, plus the
 * two writes from the common surface (notification recipients, duplicate).
 * Config mutation and a Flamingo-backed entry bridge live in the -pro file.
 * Permissions use CF7's capabilities with a manage_options fallback. Built on
 * CF7's model; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_CF7 extends AVCF_Abilities_Base {

    /** @var AVCF_CF7_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_CF7_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_cf7_is_available() ) {
            return;
        }
        $this->register_list_forms();
        $this->register_get_form();
        $this->register_get_form_template();
        $this->register_list_mail_templates();
        $this->register_get_messages();
        $this->register_get_additional_settings();
        $this->register_update_notifications();
        $this->register_duplicate_form();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }

    public function can_read() { return current_user_can( 'wpcf7_read_contact_forms' ) || current_user_can( 'wpcf7_edit_contact_forms' ) || current_user_can( 'manage_options' ); }
    public function can_edit() { return current_user_can( 'wpcf7_edit_contact_forms' ) || current_user_can( 'manage_options' ); }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-list-forms', [
            'label' => 'List Contact Form 7 Forms', 'category' => 'atarim',
            'description' => 'List CF7 forms: { id, title, status, entries_total, last_submission, created_at }. CF7 stores no entries itself, so entries_total/last_submission are always null here — use the Flamingo abilities (atarim/flamingo-*) for submission data, passing this form_id. Optional search, limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $qa = [ 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'posts_per_page' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50, 'offset' => isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0, 'orderby' => 'date', 'order' => 'DESC' ];
                if ( isset( $input['search'] ) && $input['search'] !== '' ) { $qa['s'] = (string) $input['search']; }
                $q = new WP_Query( $qa );
                $out = [];
                foreach ( $q->posts as $post ) { $out[] = AVCF_CF7_Helpers::map_form_summary( $post ); }
                return [ 'success' => true, 'forms' => $out, 'total' => (int) $q->found_posts, 'message' => sprintf( '%d form(s). For submission data, use the atarim/flamingo-* abilities.', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-get-form', [
            'label' => 'Get Contact Form 7 Form', 'category' => 'atarim',
            'description' => 'CF7 form overview: fields parsed from the form template (id, type, required), mail / mail_2 notification summaries, and shortcode.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $post = get_post( (int) $input['form_id'] );
                if ( ! $post || $post->post_type !== 'wpcf7_contact_form' ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'form' => AVCF_CF7_Helpers::map_form_full( $post, AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- get-form-template ----------------------- */

    private function register_get_form_template() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-get-form-template', [
            'label' => 'Get CF7 Form Template', 'category' => 'atarim',
            'description' => 'The raw [form] template markup (CF7 form-tags) for a form.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'template' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'template' => (string) $cf7->prop( 'form' ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ list-mail-templates ---------------------- */

    private function register_list_mail_templates() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-list-mail-templates', [
            'label' => 'List CF7 Mail Templates', 'category' => 'atarim',
            'description' => 'The full mail and mail_2 templates: recipient, sender, subject, body, additional_headers, use_html, active.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'mail_templates' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $out = [];
                foreach ( [ 'mail', 'mail_2' ] as $key ) {
                    $m = AVCF_CF7_Helpers::map_mail( $cf7->prop( $key ), $key );
                    if ( $m ) { $out[] = $m; }
                }
                return [ 'success' => true, 'mail_templates' => $out, 'message' => sprintf( '%d mail template(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-messages ------------------------ */

    private function register_get_messages() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-get-messages', [
            'label' => 'Get CF7 Messages', 'category' => 'atarim',
            'description' => 'The form\'s validation/status messages (mail sent, validation errors, spam, etc.) as a key => text map.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'messages' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $messages = $cf7->prop( 'messages' );
                return [ 'success' => true, 'messages' => is_array( $messages ) ? $messages : [], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------- get-additional-settings -------------------- */

    private function register_get_additional_settings() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-get-additional-settings', [
            'label' => 'Get CF7 Additional Settings', 'category' => 'atarim',
            'description' => 'The "Additional Settings" text block of a form (raw lines, e.g. demo_mode, skip_mail, on_sent_ok).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'additional_settings' => [ 'type' => 'string' ], 'lines' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $text = (string) $cf7->prop( 'additional_settings' );
                $lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
                return [ 'success' => true, 'additional_settings' => $text, 'lines' => $lines, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ update-notifications --------------------- */

    private function register_update_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-update-notifications', [
            'label' => 'Update CF7 Notification Recipients', 'category' => 'atarim',
            'description' => 'Update the recipient of the mail and/or mail_2 templates. updates: [{ notification_id: "mail"|"mail_2", recipients: [emails] }]. Emails sanitized; unknown/unconfigured templates skipped.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    if ( ! in_array( $nid, [ 'mail', 'mail_2' ], true ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'CF7 supports "mail" and "mail_2" only.' ]; continue; }
                    $mail = $cf7->prop( $nid );
                    if ( ! is_array( $mail ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Not configured on this form.' ]; continue; }
                    $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                    if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; continue; }
                    $mail['recipient'] = implode( ', ', $clean );
                    $cf7->set_properties( [ $nid => $mail ] );
                    $updated[] = $nid;
                }
                if ( ! empty( $updated ) && method_exists( $cf7, 'save' ) ) { $cf7->save(); }
                return [ 'success' => ! empty( $updated ), 'updated' => $updated, 'skipped' => $skipped, 'message' => sprintf( '%d updated, %d skipped.', count( $updated ), count( $skipped ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- duplicate-form ------------------------- */

    private function register_duplicate_form() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-duplicate-form', [
            'label' => 'Duplicate CF7 Form', 'category' => 'atarim',
            'description' => 'Clone a form via the CF7 model copy(). Optional new_title (defaults to "<title> (Copy)").',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $orig = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $orig ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! method_exists( $orig, 'copy' ) ) { return [ 'success' => false, 'message' => 'CF7 copy() unavailable in this version.' ]; }
                $title = isset( $input['new_title'] ) && $input['new_title'] !== '' ? sanitize_text_field( $input['new_title'] ) : ( (string) $orig->title() . ' (Copy)' );
                $copy = $orig->copy();
                if ( $copy && method_exists( $copy, 'set_title' ) ) { $copy->set_title( $title ); }
                if ( ! $copy || ! method_exists( $copy, 'save' ) ) { return [ 'success' => false, 'message' => 'Duplicate failed.' ]; }
                $copy->save();
                return [ 'success' => true, 'new_form_id' => (int) $copy->id(), 'message' => sprintf( 'Duplicated as "%s".', $title ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
