<?php
/**
 * Contact Form 7 — power MCP abilities (standalone cluster, -pro file).
 *
 * Config mutation for CF7 forms via the WPCF7_ContactForm model
 * (set_properties + save): create, delete, the [form] template, the mail /
 * mail_2 templates, the messages, and the additional-settings block. CF7 stores
 * no submissions, so there are no entry writers here — the entry side is the
 * separate Flamingo cluster. create-form is experimental; delete is
 * confirm-gated. CF7-cap permissions with a manage_options fallback. Built on
 * CF7's model; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_CF7_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_CF7_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_CF7_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_cf7_is_available() ) {
            return;
        }
        $this->register_create_form();
        $this->register_delete_form();
        $this->register_update_form_template();
        $this->register_save_mail_template();
        $this->register_update_messages();
        $this->register_update_additional_settings();
    }

    /* ------------------------------ shared ----------------------------- */

    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function can_edit() { return current_user_can( 'wpcf7_edit_contact_forms' ) || current_user_can( 'manage_options' ); }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-create-form', [
            'label' => 'Create Contact Form 7 Form', 'category' => 'atarim',
            'description' => 'Create a new CF7 form from the default template. EXPERIMENTAL. Provide title; optionally a [form] template markup and a mail recipient to seed. Returns the new form_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string', 'minLength' => 1 ], 'form_template' => [ 'type' => 'string' ], 'mail_recipient' => [ 'type' => 'string' ] ], 'required' => [ 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'WPCF7_ContactForm' ) || ! method_exists( 'WPCF7_ContactForm', 'get_template' ) ) { return [ 'success' => false, 'message' => 'WPCF7_ContactForm::get_template unavailable.' ]; }
                $title = sanitize_text_field( $input['title'] );
                if ( $title === '' ) { return [ 'success' => false, 'message' => 'A title is required.' ]; }
                $form = WPCF7_ContactForm::get_template( [ 'title' => $title ] );
                if ( ! $form || ! method_exists( $form, 'save' ) ) { return [ 'success' => false, 'message' => 'Template instance not creatable in this version.' ]; }
                if ( method_exists( $form, 'set_title' ) ) { $form->set_title( $title ); }
                $props = [];
                if ( isset( $input['form_template'] ) && $input['form_template'] !== '' ) { $props['form'] = (string) $input['form_template']; }
                if ( isset( $input['mail_recipient'] ) && $input['mail_recipient'] !== '' && method_exists( $form, 'prop' ) ) {
                    $mail = $form->prop( 'mail' );
                    if ( ! is_array( $mail ) ) { $mail = []; }
                    $mail['recipient'] = sanitize_email( $input['mail_recipient'] );
                    $props['mail'] = $mail;
                }
                if ( ! empty( $props ) && method_exists( $form, 'set_properties' ) ) { $form->set_properties( $props ); }
                $form->save();
                $new_id = method_exists( $form, 'id' ) ? (int) $form->id() : 0;
                if ( ! $new_id ) { return [ 'success' => false, 'message' => 'Create failed.' ]; }
                return [ 'success' => true, 'form_id' => $new_id, 'message' => sprintf( 'Form %d created (experimental — verify in the CF7 editor).', $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-form ------------------------- */

    private function register_delete_form() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-delete-form', [
            'label' => 'Delete Contact Form 7 Form', 'category' => 'atarim',
            'description' => 'Permanently delete a CF7 form. DESTRUCTIVE — dry run unless confirm:true. (Any stored Flamingo submissions are not removed.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $post = get_post( $form_id );
                if ( ! $post || $post->post_type !== 'wpcf7_contact_form' ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this form.' ]; }
                $cf7 = AVCF_CF7_Helpers::get_cf7( $form_id );
                if ( $cf7 && method_exists( $cf7, 'delete' ) ) { $cf7->delete(); }
                else { $r = wp_delete_post( $form_id, true ); if ( ! $r ) { return [ 'success' => false, 'message' => 'Delete failed.' ]; } }
                return [ 'success' => true, 'message' => sprintf( 'Form %d deleted.', $form_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------ update-form-template --------------------- */

    private function register_update_form_template() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-update-form-template', [
            'label' => 'Update CF7 Form Template', 'category' => 'atarim',
            'description' => 'Replace the [form] template markup (CF7 form-tags) for a form. The full markup is replaced with what you provide.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'template' => [ 'type' => 'string' ] ], 'required' => [ 'form_id', 'template' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'set_properties' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $cf7->set_properties( [ 'form' => (string) $input['template'] ] );
                if ( method_exists( $cf7, 'save' ) ) { $cf7->save(); }
                return [ 'success' => true, 'message' => 'Form template updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ save-mail-template ----------------------- */

    private function register_save_mail_template() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-save-mail-template', [
            'label' => 'Save CF7 Mail Template', 'category' => 'atarim',
            'description' => 'Update the mail or mail_2 template. which:"mail"|"mail_2". Settable: recipient, sender, subject, body, additional_headers, use_html, active (mail_2 only). Only provided keys change. Enabling mail_2 sets active:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'which' => [ 'type' => 'string', 'enum' => [ 'mail', 'mail_2' ] ], 'recipient' => [ 'type' => 'string' ], 'sender' => [ 'type' => 'string' ], 'subject' => [ 'type' => 'string' ], 'body' => [ 'type' => 'string' ], 'additional_headers' => [ 'type' => 'string' ], 'use_html' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ] ], 'required' => [ 'form_id', 'which' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $which = (string) $input['which'];
                $mail = $cf7->prop( $which );
                if ( ! is_array( $mail ) ) { $mail = []; }
                foreach ( [ 'recipient', 'sender', 'subject', 'body', 'additional_headers' ] as $k ) {
                    if ( isset( $input[ $k ] ) ) { $mail[ $k ] = (string) $input[ $k ]; }
                }
                if ( isset( $input['use_html'] ) ) { $mail['use_html'] = ! empty( $input['use_html'] ) ? 1 : 0; }
                if ( $which === 'mail_2' && isset( $input['active'] ) ) { $mail['active'] = ! empty( $input['active'] ); }
                $cf7->set_properties( [ $which => $mail ] );
                if ( method_exists( $cf7, 'save' ) ) { $cf7->save(); }
                return [ 'success' => true, 'message' => sprintf( '%s template updated.', $which ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-messages --------------------- */

    private function register_update_messages() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-update-messages', [
            'label' => 'Update CF7 Messages', 'category' => 'atarim',
            'description' => 'Patch a form\'s status/validation messages. messages is a map of message_key => text (e.g. mail_sent_ok, validation_error, spam). Only provided keys change; others are preserved.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'messages' => [ 'type' => 'object' ] ], 'required' => [ 'form_id', 'messages' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'prop' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $messages = $cf7->prop( 'messages' );
                if ( ! is_array( $messages ) ) { $messages = []; }
                foreach ( (array) $input['messages'] as $k => $v ) { $messages[ (string) $k ] = (string) $v; }
                $cf7->set_properties( [ 'messages' => $messages ] );
                if ( method_exists( $cf7, 'save' ) ) { $cf7->save(); }
                return [ 'success' => true, 'message' => 'Messages updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------- update-additional-settings -------------------- */

    private function register_update_additional_settings() {
        $self = $this;
        wp_register_ability( 'atarim/cf7-update-additional-settings', [
            'label' => 'Update CF7 Additional Settings', 'category' => 'atarim',
            'description' => 'Replace the "Additional Settings" text block (raw lines such as demo_mode: on, skip_mail: on, flamingo_email: "[your-email]"). The whole block is replaced with what you provide.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'additional_settings' => [ 'type' => 'string' ] ], 'required' => [ 'form_id', 'additional_settings' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $cf7 = AVCF_CF7_Helpers::get_cf7( (int) $input['form_id'] );
                if ( ! $cf7 || ! method_exists( $cf7, 'set_properties' ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $cf7->set_properties( [ 'additional_settings' => (string) $input['additional_settings'] ] );
                if ( method_exists( $cf7, 'save' ) ) { $cf7->save(); }
                return [ 'success' => true, 'message' => 'Additional settings updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
