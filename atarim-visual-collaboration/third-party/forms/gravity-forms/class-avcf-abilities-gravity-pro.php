<?php
/**
 * Gravity Forms — power MCP abilities (standalone cluster, -pro file).
 *
 * GF-specific depth beyond the common surface: form CRUD, entry CRUD, entry
 * notes, full notification & confirmation management, and add-on feeds
 * (payments / CRM / Zapier etc.). Writers use Gravity's own capabilities with a
 * manage_options fallback; deletes are confirm-gated. Version-dependent GF
 * methods are guarded and degrade to a typed error rather than fatally.
 *
 * Built on GFAPI / GFFormsModel; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Gravity_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Gravity_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Gravity_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_gravity_is_available() ) {
            return;
        }
        // Forms CRUD
        $this->register_create_form();
        $this->register_update_form();
        $this->register_delete_form();
        // Entries CRUD
        $this->register_create_entry();
        $this->register_update_entry();
        $this->register_delete_entry();
        // Entry notes
        $this->register_list_entry_notes();
        $this->register_add_entry_note();
        // Notifications
        $this->register_list_notifications();
        $this->register_save_notification();
        // Confirmations
        $this->register_list_confirmations();
        $this->register_save_confirmation();
        // Feeds
        $this->register_list_feeds();
        $this->register_get_feed();
        $this->register_toggle_feed();
    }

    /* ------------------------------ shared ----------------------------- */

    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function can_create_forms() { return current_user_can( 'gravityforms_create_form' ) || current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_edit_forms() { return current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_delete_forms() { return current_user_can( 'gravityforms_delete_forms' ) || current_user_can( 'manage_options' ); }
    public function can_view_entries() { return current_user_can( 'gravityforms_view_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'gravityforms_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function can_delete_entries() { return current_user_can( 'gravityforms_delete_entries' ) || current_user_can( 'manage_options' ); }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-create-form', [
            'label' => 'Create Gravity Form', 'category' => 'atarim',
            'description' => 'Create a new Gravity form. Provide either a full GF form definition (definition) OR a title plus a simple fields array (each: { type, label, required?, choices?[] }) — field ids are auto-assigned when omitted. Returns the new form_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'title' => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'fields' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'definition' => [ 'type' => 'object', 'description' => 'Full GF form array (overrides title/fields).' ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( isset( $input['definition'] ) && is_array( $input['definition'] ) ) {
                    $form = $input['definition'];
                } else {
                    $title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
                    if ( $title === '' ) { return [ 'success' => false, 'message' => 'Provide a title (or a full definition).' ]; }
                    $fields = []; $i = 1;
                    foreach ( ( isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : [] ) as $f ) {
                        if ( ! is_array( $f ) ) { continue; }
                        $field = [ 'id' => isset( $f['id'] ) ? (int) $f['id'] : $i, 'type' => isset( $f['type'] ) ? (string) $f['type'] : 'text', 'label' => isset( $f['label'] ) ? (string) $f['label'] : ( 'Field ' . $i ), 'isRequired' => ! empty( $f['required'] ) ];
                        if ( isset( $f['choices'] ) && is_array( $f['choices'] ) ) {
                            $field['choices'] = [];
                            foreach ( $f['choices'] as $c ) { $txt = is_array( $c ) ? ( isset( $c['text'] ) ? $c['text'] : '' ) : (string) $c; $field['choices'][] = [ 'text' => (string) $txt, 'value' => (string) $txt ]; }
                        }
                        $fields[] = $field; $i++;
                    }
                    $form = [ 'title' => $title, 'description' => isset( $input['description'] ) ? (string) $input['description'] : '', 'fields' => $fields ];
                }
                $id = GFAPI::add_form( $form );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => 'Create failed: ' . $id->get_error_message() ]; }
                return [ 'success' => true, 'form_id' => (int) $id, 'message' => sprintf( 'Form %d created.', (int) $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_create_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-form ------------------------- */

    private function register_update_form() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-update-form', [
            'label' => 'Update Gravity Form', 'category' => 'atarim',
            'description' => 'Update a form\'s top-level properties (title, description, is_active) and/or replace its fields array. Only provided keys change; everything else is preserved.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'title' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ], 'is_active' => [ 'type' => 'boolean' ],
                'fields' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Full replacement fields array (GF field definitions).' ],
            ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( isset( $input['title'] ) ) { $form['title'] = sanitize_text_field( $input['title'] ); }
                if ( isset( $input['description'] ) ) { $form['description'] = (string) $input['description']; }
                if ( isset( $input['is_active'] ) ) { $form['is_active'] = ! empty( $input['is_active'] ) ? 1 : 0; }
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) { $form['fields'] = $input['fields']; }
                $r = GFAPI::update_form( $form );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Update failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Form updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-form ------------------------- */

    private function register_delete_form() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-delete-form', [
            'label' => 'Delete Gravity Form', 'category' => 'atarim',
            'description' => 'Permanently delete a form and all its entries. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                if ( ! GFAPI::get_form( $form_id ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this form and its entries.' ]; }
                $r = GFAPI::delete_form( $form_id );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Delete failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => sprintf( 'Form %d deleted.', $form_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_forms(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- create-entry ------------------------- */

    private function register_create_entry() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-create-entry', [
            'label' => 'Create Gravity Entry', 'category' => 'atarim',
            'description' => 'Add an entry to a form. values is a map of field id => value (e.g. {"1":"Jane","3":"jane@x.com"}); multi-input fields use dotted ids like "5.3". Returns the new entry_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'form_id', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                if ( ! GFAPI::get_form( $form_id ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! is_array( $input['values'] ) ) { return [ 'success' => false, 'message' => 'values must be an object.' ]; }
                $entry = [ 'form_id' => $form_id ];
                foreach ( $input['values'] as $k => $v ) { $entry[ (string) $k ] = $v; }
                $id = GFAPI::add_entry( $entry );
                if ( is_wp_error( $id ) ) { return [ 'success' => false, 'message' => 'Create failed: ' . $id->get_error_message() ]; }
                return [ 'success' => true, 'entry_id' => (int) $id, 'message' => sprintf( 'Entry %d created.', (int) $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- update-entry ------------------------- */

    private function register_update_entry() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-update-entry', [
            'label' => 'Update Gravity Entry', 'category' => 'atarim',
            'description' => 'Update field values on an existing entry. values is a map of field id => new value (merged into the entry). Only provided fields change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'entry_id', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $entry = GFAPI::get_entry( (int) $input['entry_id'] );
                if ( is_wp_error( $entry ) || ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                if ( ! is_array( $input['values'] ) ) { return [ 'success' => false, 'message' => 'values must be an object.' ]; }
                foreach ( $input['values'] as $k => $v ) { $entry[ (string) $k ] = $v; }
                $r = GFAPI::update_entry( $entry );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Update failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Entry updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- delete-entry ------------------------- */

    private function register_delete_entry() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-delete-entry', [
            'label' => 'Delete Gravity Entry', 'category' => 'atarim',
            'description' => 'Permanently delete an entry. DESTRUCTIVE — dry run unless confirm:true. (To mark spam instead, use gravity-mark-entry-spam.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $entry_id = (int) $input['entry_id'];
                $entry = GFAPI::get_entry( $entry_id );
                if ( is_wp_error( $entry ) || ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this entry.' ]; }
                $r = GFAPI::delete_entry( $entry_id );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Delete failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => sprintf( 'Entry %d deleted.', $entry_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_entries(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* -------------------------- list-entry-notes ----------------------- */

    private function register_list_entry_notes() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-list-entry-notes', [
            'label' => 'List Gravity Entry Notes', 'category' => 'atarim',
            'description' => 'List the admin notes attached to an entry: { id, user_name, date_created, value, note_type }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'get_lead_notes' ) ) { return [ 'success' => false, 'message' => 'Gravity notes API unavailable in this version.' ]; }
                $raw = GFFormsModel::get_lead_notes( (int) $input['entry_id'] );
                $out = [];
                foreach ( (array) $raw as $n ) {
                    $out[] = [ 'id' => isset( $n->id ) ? (int) $n->id : 0, 'user_name' => isset( $n->user_name ) ? (string) $n->user_name : '', 'date_created' => isset( $n->date_created ) ? (string) $n->date_created : '', 'value' => isset( $n->value ) ? (string) $n->value : '', 'note_type' => isset( $n->note_type ) ? (string) $n->note_type : '' ];
                }
                return [ 'success' => true, 'notes' => $out, 'message' => sprintf( '%d note(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- add-entry-note ------------------------ */

    private function register_add_entry_note() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-add-entry-note', [
            'label' => 'Add Gravity Entry Note', 'category' => 'atarim',
            'description' => 'Attach an admin note to an entry (attributed to the current user).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'note' => [ 'type' => 'string', 'minLength' => 1 ] ], 'required' => [ 'entry_id', 'note' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) ) { return [ 'success' => false, 'message' => 'Gravity notes API unavailable in this version.' ]; }
                $entry = GFAPI::get_entry( (int) $input['entry_id'] );
                if ( is_wp_error( $entry ) || ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $user = wp_get_current_user();
                GFFormsModel::add_note( (int) $input['entry_id'], (int) $user->ID, $user->display_name ? $user->display_name : 'system', (string) $input['note'], 'user' );
                return [ 'success' => true, 'message' => 'Note added.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- list-notifications ---------------------- */

    private function register_list_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-list-notifications', [
            'label' => 'List Gravity Notifications', 'category' => 'atarim',
            'description' => 'List a form\'s notifications in full: { id, name, event, to_type, to, subject, message, enabled, conditional_logic }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notifications' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $out = [];
                foreach ( ( isset( $form['notifications'] ) && is_array( $form['notifications'] ) ? $form['notifications'] : [] ) as $nid => $n ) {
                    if ( ! is_array( $n ) ) { continue; }
                    $out[] = [ 'id' => (string) $nid, 'name' => isset( $n['name'] ) ? (string) $n['name'] : '', 'event' => isset( $n['event'] ) ? (string) $n['event'] : 'form_submission', 'to_type' => isset( $n['toType'] ) ? (string) $n['toType'] : '', 'to' => isset( $n['to'] ) ? (string) $n['to'] : '', 'subject' => isset( $n['subject'] ) ? (string) $n['subject'] : '', 'message' => isset( $n['message'] ) ? (string) $n['message'] : '', 'enabled' => ! isset( $n['isActive'] ) || ! empty( $n['isActive'] ), 'conditional_logic' => isset( $n['conditionalLogic'] ) ? $n['conditionalLogic'] : null ];
                }
                return [ 'success' => true, 'notifications' => $out, 'message' => sprintf( '%d notification(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- save-notification ---------------------- */

    private function register_save_notification() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-save-notification', [
            'label' => 'Create/Update Gravity Notification', 'category' => 'atarim',
            'description' => 'Create or update a notification on a form. Omit notification_id to create (a new id is generated). Settable: name, event, to_type (email|field|routing), to, subject, message, enabled. Returns the notification_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'notification_id' => [ 'type' => 'string' ],
                'name' => [ 'type' => 'string' ], 'event' => [ 'type' => 'string' ], 'to_type' => [ 'type' => 'string' ], 'to' => [ 'type' => 'string' ],
                'subject' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ], 'enabled' => [ 'type' => 'boolean' ],
            ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notification_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! isset( $form['notifications'] ) || ! is_array( $form['notifications'] ) ) { $form['notifications'] = []; }
                $nid = isset( $input['notification_id'] ) && $input['notification_id'] !== '' ? (string) $input['notification_id'] : ( function_exists( 'uniqid' ) ? uniqid() : (string) wp_rand( 100000, 999999 ) );
                $existing = isset( $form['notifications'][ $nid ] ) && is_array( $form['notifications'][ $nid ] ) ? $form['notifications'][ $nid ] : [ 'id' => $nid, 'event' => 'form_submission', 'name' => 'Notification', 'toType' => 'email' ];
                if ( isset( $input['name'] ) ) { $existing['name'] = sanitize_text_field( $input['name'] ); }
                if ( isset( $input['event'] ) ) { $existing['event'] = (string) $input['event']; }
                if ( isset( $input['to_type'] ) ) { $existing['toType'] = (string) $input['to_type']; }
                if ( isset( $input['to'] ) ) { $existing['to'] = (string) $input['to']; }
                if ( isset( $input['subject'] ) ) { $existing['subject'] = (string) $input['subject']; }
                if ( isset( $input['message'] ) ) { $existing['message'] = (string) $input['message']; }
                if ( isset( $input['enabled'] ) ) { $existing['isActive'] = ! empty( $input['enabled'] ); }
                $existing['id'] = $nid;
                $form['notifications'][ $nid ] = $existing;
                $r = GFAPI::update_form( $form );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Save failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'notification_id' => $nid, 'message' => 'Notification saved.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- list-confirmations ---------------------- */

    private function register_list_confirmations() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-list-confirmations', [
            'label' => 'List Gravity Confirmations', 'category' => 'atarim',
            'description' => 'List a form\'s confirmations: { id, name, type (message|page|redirect), is_default, message, pageId, url }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'confirmations' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $out = [];
                foreach ( ( isset( $form['confirmations'] ) && is_array( $form['confirmations'] ) ? $form['confirmations'] : [] ) as $cid => $c ) {
                    if ( ! is_array( $c ) ) { continue; }
                    $out[] = [ 'id' => (string) $cid, 'name' => isset( $c['name'] ) ? (string) $c['name'] : '', 'type' => isset( $c['type'] ) ? (string) $c['type'] : 'message', 'is_default' => ! empty( $c['isDefault'] ), 'message' => isset( $c['message'] ) ? (string) $c['message'] : '', 'pageId' => isset( $c['pageId'] ) ? $c['pageId'] : null, 'url' => isset( $c['url'] ) ? (string) $c['url'] : '' ];
                }
                return [ 'success' => true, 'confirmations' => $out, 'message' => sprintf( '%d confirmation(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- save-confirmation ---------------------- */

    private function register_save_confirmation() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-save-confirmation', [
            'label' => 'Create/Update Gravity Confirmation', 'category' => 'atarim',
            'description' => 'Create or update a confirmation. Omit confirmation_id to create. Settable: name, type (message|page|redirect), message, page_id, url. Returns the confirmation_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirmation_id' => [ 'type' => 'string' ],
                'name' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'enum' => [ 'message', 'page', 'redirect' ] ],
                'message' => [ 'type' => 'string' ], 'page_id' => [ 'type' => 'integer' ], 'url' => [ 'type' => 'string' ],
            ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'confirmation_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! isset( $form['confirmations'] ) || ! is_array( $form['confirmations'] ) ) { $form['confirmations'] = []; }
                $cid = isset( $input['confirmation_id'] ) && $input['confirmation_id'] !== '' ? (string) $input['confirmation_id'] : ( function_exists( 'uniqid' ) ? uniqid() : (string) wp_rand( 100000, 999999 ) );
                $c = isset( $form['confirmations'][ $cid ] ) && is_array( $form['confirmations'][ $cid ] ) ? $form['confirmations'][ $cid ] : [ 'id' => $cid, 'name' => 'Confirmation', 'type' => 'message', 'isDefault' => false ];
                if ( isset( $input['name'] ) ) { $c['name'] = sanitize_text_field( $input['name'] ); }
                if ( isset( $input['type'] ) ) { $c['type'] = (string) $input['type']; }
                if ( isset( $input['message'] ) ) { $c['message'] = (string) $input['message']; }
                if ( isset( $input['page_id'] ) ) { $c['pageId'] = (int) $input['page_id']; }
                if ( isset( $input['url'] ) ) { $c['url'] = esc_url_raw( $input['url'] ); }
                $c['id'] = $cid;
                $form['confirmations'][ $cid ] = $c;
                $r = GFAPI::update_form( $form );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Save failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'confirmation_id' => $cid, 'message' => 'Confirmation saved.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------------ feeds ------------------------------ */

    private function register_list_feeds() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-list-feeds', [
            'label' => 'List Gravity Feeds', 'category' => 'atarim',
            'description' => 'List add-on feeds attached to a form (payments, CRM, Zapier, etc.): { id, addon_slug, is_active, meta }. Optional addon_slug filter.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'addon_slug' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'feeds' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'GFAPI', 'get_feeds' ) ) { return [ 'success' => false, 'message' => 'Gravity feeds API unavailable.' ]; }
                $slug = isset( $input['addon_slug'] ) && $input['addon_slug'] !== '' ? (string) $input['addon_slug'] : null;
                $feeds = GFAPI::get_feeds( null, (int) $input['form_id'], $slug );
                if ( is_wp_error( $feeds ) ) { return [ 'success' => true, 'feeds' => [], 'message' => 'No feeds on this form.' ]; }
                $out = [];
                foreach ( (array) $feeds as $f ) { $out[] = [ 'id' => isset( $f['id'] ) ? (int) $f['id'] : 0, 'addon_slug' => isset( $f['addon_slug'] ) ? (string) $f['addon_slug'] : '', 'is_active' => ! empty( $f['is_active'] ), 'meta' => isset( $f['meta'] ) ? $f['meta'] : [] ]; }
                return [ 'success' => true, 'feeds' => $out, 'message' => sprintf( '%d feed(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_get_feed() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-get-feed', [
            'label' => 'Get Gravity Feed', 'category' => 'atarim',
            'description' => 'Full config for one feed by id (addon_slug, is_active, full meta).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'feed_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'feed_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'feed' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'GFAPI', 'get_feeds' ) ) { return [ 'success' => false, 'message' => 'Gravity feeds API unavailable.' ]; }
                $feeds = GFAPI::get_feeds( [ (int) $input['feed_id'] ] );
                if ( is_wp_error( $feeds ) || empty( $feeds ) ) { return [ 'success' => false, 'message' => 'Feed not found.' ]; }
                $f = $feeds[0];
                return [ 'success' => true, 'feed' => [ 'id' => isset( $f['id'] ) ? (int) $f['id'] : 0, 'form_id' => isset( $f['form_id'] ) ? (int) $f['form_id'] : 0, 'addon_slug' => isset( $f['addon_slug'] ) ? (string) $f['addon_slug'] : '', 'is_active' => ! empty( $f['is_active'] ), 'meta' => isset( $f['meta'] ) ? $f['meta'] : [] ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_toggle_feed() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-toggle-feed', [
            'label' => 'Toggle Gravity Feed', 'category' => 'atarim',
            'description' => 'Activate or deactivate an add-on feed by id (is_active:true/false).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'feed_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_active' => [ 'type' => 'boolean' ] ], 'required' => [ 'feed_id', 'is_active' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'GFAPI', 'update_feed_property' ) ) { return [ 'success' => false, 'message' => 'Gravity feed-toggle API unavailable in this version.' ]; }
                $r = GFAPI::update_feed_property( (int) $input['feed_id'], 'is_active', ! empty( $input['is_active'] ) ? 1 : 0 );
                if ( is_wp_error( $r ) || $r === false ) { return [ 'success' => false, 'message' => 'Toggle failed (feed not found?).' ]; }
                return [ 'success' => true, 'message' => ! empty( $input['is_active'] ) ? 'Feed activated.' : 'Feed deactivated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
