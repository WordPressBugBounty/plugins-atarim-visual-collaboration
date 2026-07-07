<?php
/**
 * Formidable Forms — power MCP abilities (standalone cluster, -pro file).
 *
 * Formidable-specific depth via its stable API: form CRUD (FrmForm), field
 * read/create/delete (FrmField), entry create/update/delete/status (FrmEntry
 * + frm_items), email-action notification management (frm_form_actions CPT),
 * and a Pro-gated Views listing (frm_display). create-field and create-entry
 * are experimental (Formidable's field/meta shapes are intricate); deletes are
 * confirm-gated. Permissions use Formidable's capabilities with a manage_options
 * fallback. Built on Formidable's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Formidable_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Formidable_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Formidable_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_formidable_is_available() ) {
            return;
        }
        $this->register_create_form();
        $this->register_update_form();
        $this->register_delete_form();
        $this->register_list_fields();
        $this->register_create_field();
        $this->register_delete_field();
        $this->register_create_entry();
        $this->register_update_entry();
        $this->register_delete_entry();
        $this->register_set_entry_status();
        $this->register_list_notifications();
        $this->register_save_notification();
        $this->register_list_views();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function is_pro() { return $this->detector->avcf_formidable_is_pro(); }
    public function can_edit_forms() { return current_user_can( 'frm_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_delete_forms() { return current_user_can( 'frm_delete_forms' ) || current_user_can( 'frm_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'frm_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function can_delete_entries() { return current_user_can( 'frm_delete_entries' ) || current_user_can( 'frm_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function form_exists( $form_id ) { return class_exists( 'FrmForm' ) && (bool) FrmForm::getOne( (int) $form_id ); }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-create-form', [
            'label' => 'Create Formidable Form', 'category' => 'atarim',
            'description' => 'Create a new (empty) Formidable form via FrmForm::create. Provide name and optional description. Add fields afterward with formidable-create-field. Returns the new form_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string', 'minLength' => 1 ], 'description' => [ 'type' => 'string' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'FrmForm', 'create' ) ) { return [ 'success' => false, 'message' => 'FrmForm::create unavailable.' ]; }
                $name = sanitize_text_field( $input['name'] );
                if ( $name === '' ) { return [ 'success' => false, 'message' => 'A name is required.' ]; }
                $id = FrmForm::create( [ 'name' => $name, 'description' => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : '', 'status' => 'published' ] );
                if ( ! $id ) { return [ 'success' => false, 'message' => 'Create failed.' ]; }
                return [ 'success' => true, 'form_id' => (int) $id, 'message' => sprintf( 'Form %d created.', (int) $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-form ------------------------- */

    private function register_update_form() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-update-form', [
            'label' => 'Update Formidable Form', 'category' => 'atarim',
            'description' => 'Update a form\'s name, description, and/or status, plus an optional options patch (merged into the form options array). Only provided keys change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'name' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'enum' => [ 'published', 'draft', 'trash' ] ], 'options' => [ 'type' => 'object' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! method_exists( 'FrmForm', 'update' ) ) { return [ 'success' => false, 'message' => 'FrmForm::update unavailable.' ]; }
                $form = FrmForm::getOne( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $values = [];
                if ( isset( $input['name'] ) ) { $values['name'] = sanitize_text_field( $input['name'] ); }
                if ( isset( $input['description'] ) ) { $values['description'] = wp_kses_post( $input['description'] ); }
                if ( isset( $input['status'] ) ) { $values['status'] = (string) $input['status']; }
                if ( isset( $input['options'] ) && is_array( $input['options'] ) ) {
                    $current = isset( $form->options ) && is_array( $form->options ) ? $form->options : ( is_string( $form->options ) ? maybe_unserialize( $form->options ) : [] );
                    if ( ! is_array( $current ) ) { $current = []; }
                    $values['options'] = array_merge( $current, $input['options'] );
                }
                if ( empty( $values ) ) { return [ 'success' => false, 'message' => 'Nothing to update.' ]; }
                FrmForm::update( (int) $input['form_id'], $values );
                return [ 'success' => true, 'message' => 'Form updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-form ------------------------- */

    private function register_delete_form() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-delete-form', [
            'label' => 'Delete Formidable Form', 'category' => 'atarim',
            'description' => 'Permanently delete a form, its fields and entries via FrmForm::destroy. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->form_exists( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! method_exists( 'FrmForm', 'destroy' ) ) { return [ 'success' => false, 'message' => 'FrmForm::destroy unavailable.' ]; }
                $count = AVCF_Formidable_Helpers::count_entries( (int) $input['form_id'], true );
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to delete this form, its fields, and %d entr(y/ies).', $count ) ]; }
                $r = FrmForm::destroy( (int) $input['form_id'] );
                return $r ? [ 'success' => true, 'message' => sprintf( 'Form %d deleted.', (int) $input['form_id'] ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_forms(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- list-fields ------------------------- */

    private function register_list_fields() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-list-fields', [
            'label' => 'List Formidable Fields', 'category' => 'atarim',
            'description' => 'Detailed field list for a form: { id, key, type, label, required, field_order, options, field_options }. Richer than formidable-get-form.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'fields' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'FrmField' ) ) { return [ 'success' => false, 'message' => 'FrmField API unavailable.' ]; }
                $raw = FrmField::get_all_for_form( (int) $input['form_id'] );
                $out = [];
                foreach ( (array) $raw as $f ) {
                    if ( ! isset( $f->id ) ) { continue; }
                    $fopts = isset( $f->field_options ) && is_array( $f->field_options ) ? $f->field_options : ( is_string( $f->field_options ) ? maybe_unserialize( $f->field_options ) : [] );
                    $choices = isset( $f->options ) ? ( is_string( $f->options ) ? maybe_unserialize( $f->options ) : $f->options ) : [];
                    $out[] = [ 'id' => (int) $f->id, 'key' => isset( $f->field_key ) ? (string) $f->field_key : '', 'type' => isset( $f->type ) ? (string) $f->type : '', 'label' => isset( $f->name ) ? (string) $f->name : '', 'required' => ! empty( $f->required ), 'field_order' => isset( $f->field_order ) ? (int) $f->field_order : 0, 'options' => is_array( $choices ) ? $choices : [], 'field_options' => is_array( $fopts ) ? $fopts : [] ];
                }
                return [ 'success' => true, 'fields' => $out, 'message' => sprintf( '%d field(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- create-field ------------------------ */

    private function register_create_field() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-create-field', [
            'label' => 'Create Formidable Field', 'category' => 'atarim',
            'description' => 'Add a field to a form via FrmField::create. EXPERIMENTAL. Provide type (e.g. text, textarea, email, select, checkbox, radio), label (name), required?, and options?[] for choice fields. Returns the new field_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'type' => [ 'type' => 'string', 'minLength' => 1 ], 'label' => [ 'type' => 'string', 'minLength' => 1 ], 'required' => [ 'type' => 'boolean', 'default' => false ], 'options' => [ 'type' => 'array' ] ], 'required' => [ 'form_id', 'type', 'label' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! class_exists( 'FrmField' ) || ! method_exists( 'FrmField', 'create' ) ) { return [ 'success' => false, 'message' => 'FrmField::create unavailable.' ]; }
                if ( ! $self->form_exists( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $existing = FrmField::get_all_for_form( (int) $input['form_id'] );
                $order = is_array( $existing ) ? count( $existing ) : 0;
                $values = [ 'form_id' => (int) $input['form_id'], 'type' => (string) $input['type'], 'name' => sanitize_text_field( $input['label'] ), 'field_order' => $order, 'required' => ! empty( $input['required'] ) ? 1 : 0 ];
                if ( isset( $input['options'] ) && is_array( $input['options'] ) && $input['options'] ) {
                    $opts = [];
                    foreach ( $input['options'] as $o ) { $txt = is_array( $o ) ? ( isset( $o['label'] ) ? $o['label'] : '' ) : (string) $o; $opts[] = (string) $txt; }
                    $values['options'] = $opts;
                }
                $field_id = FrmField::create( $values );
                if ( ! $field_id ) { return [ 'success' => false, 'message' => 'Create failed (field schema may need adjusting in the editor).' ]; }
                return [ 'success' => true, 'field_id' => (int) $field_id, 'message' => sprintf( 'Field %d created (experimental — verify in the Formidable editor).', (int) $field_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-field ------------------------ */

    private function register_delete_field() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-delete-field', [
            'label' => 'Delete Formidable Field', 'category' => 'atarim',
            'description' => 'Delete a field via FrmField::destroy. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'field_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'field_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'FrmField' ) || ! method_exists( 'FrmField', 'destroy' ) ) { return [ 'success' => false, 'message' => 'FrmField::destroy unavailable.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to delete this field and its stored values.' ]; }
                FrmField::destroy( (int) $input['field_id'] );
                return [ 'success' => true, 'message' => sprintf( 'Field %d deleted.', (int) $input['field_id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- create-entry ------------------------ */

    private function register_create_entry() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-create-entry', [
            'label' => 'Create Formidable Entry', 'category' => 'atarim',
            'description' => 'Create an entry via FrmEntry::create. EXPERIMENTAL. values is a map of field_id => value. Returns the new entry_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'form_id', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! class_exists( 'FrmEntry' ) || ! method_exists( 'FrmEntry', 'create' ) ) { return [ 'success' => false, 'message' => 'FrmEntry::create unavailable.' ]; }
                if ( ! $self->form_exists( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $item_meta = [];
                foreach ( (array) $input['values'] as $fid => $val ) { $item_meta[ (int) $fid ] = $val; }
                $entry_id = FrmEntry::create( [ 'form_id' => (int) $input['form_id'], 'item_meta' => $item_meta ] );
                if ( ! $entry_id ) { return [ 'success' => false, 'message' => 'Create failed (check field ids/values).' ]; }
                return [ 'success' => true, 'entry_id' => (int) $entry_id, 'message' => sprintf( 'Entry %d created.', (int) $entry_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-entry ------------------------ */

    private function register_update_entry() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-update-entry', [
            'label' => 'Update Formidable Entry', 'category' => 'atarim',
            'description' => 'Update an entry\'s field values via FrmEntry::update. values is a map of field_id => new value (only listed fields change).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'entry_id', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'FrmEntry' ) || ! method_exists( 'FrmEntry', 'update' ) ) { return [ 'success' => false, 'message' => 'FrmEntry::update unavailable.' ]; }
                $entry = FrmEntry::getOne( (int) $input['entry_id'] );
                if ( ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $item_meta = [];
                foreach ( (array) $input['values'] as $fid => $val ) { $item_meta[ (int) $fid ] = $val; }
                $r = FrmEntry::update( (int) $input['entry_id'], [ 'form_id' => (int) $entry->form_id, 'item_meta' => $item_meta ] );
                if ( ! $r ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => 'Entry updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-entry ------------------------ */

    private function register_delete_entry() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-delete-entry', [
            'label' => 'Delete Formidable Entry', 'category' => 'atarim',
            'description' => 'Permanently delete an entry via FrmEntry::destroy. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'FrmEntry' ) || ! method_exists( 'FrmEntry', 'destroy' ) ) { return [ 'success' => false, 'message' => 'FrmEntry::destroy unavailable.' ]; }
                if ( ! FrmEntry::getOne( (int) $input['entry_id'] ) ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this entry.' ]; }
                FrmEntry::destroy( (int) $input['entry_id'] );
                return [ 'success' => true, 'message' => sprintf( 'Entry %d deleted.', (int) $input['entry_id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_entries(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-entry-status ---------------------- */

    private function register_set_entry_status() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-set-entry-status', [
            'label' => 'Set Formidable Entry Status', 'category' => 'atarim',
            'description' => 'Set an entry\'s status: published (is_draft=0), draft (1), or spam (2).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'status' => [ 'type' => 'string', 'enum' => [ 'published', 'draft', 'spam' ] ] ], 'required' => [ 'entry_id', 'status' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $map = [ 'published' => 0, 'draft' => 1, 'spam' => 2 ];
                $val = isset( $map[ (string) $input['status'] ] ) ? $map[ (string) $input['status'] ] : 0;
                $r = $wpdb->update( AVCF_Formidable_Helpers::items_table(), [ 'is_draft' => $val ], [ 'id' => (int) $input['entry_id'] ], [ '%d' ], [ '%d' ] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Entry status set to %s.', (string) $input['status'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- list-notifications ---------------------- */

    private function register_list_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-list-notifications', [
            'label' => 'List Formidable Notifications', 'category' => 'atarim',
            'description' => 'List a form\'s email-action notifications in full: { id, name, email_to, email_subject, email_message, enabled }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notifications' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'FrmFormAction' ) ) { return [ 'success' => false, 'message' => 'FrmFormAction unavailable.' ]; }
                $actions = FrmFormAction::get_action_for_form( (int) $input['form_id'], 'email' );
                if ( ! is_array( $actions ) ) { $actions = []; }
                $out = [];
                foreach ( $actions as $a ) {
                    if ( ! is_object( $a ) ) { continue; }
                    $aid = isset( $a->ID ) ? (string) $a->ID : ( isset( $a->id ) ? (string) $a->id : '' );
                    $s = isset( $a->post_content ) && is_array( $a->post_content ) ? $a->post_content : [];
                    $out[] = [ 'id' => $aid, 'name' => isset( $a->post_title ) ? (string) $a->post_title : '', 'email_to' => isset( $s['email_to'] ) ? (string) $s['email_to'] : '', 'email_subject' => isset( $s['email_subject'] ) ? (string) $s['email_subject'] : '', 'email_message' => isset( $s['email_message'] ) ? (string) $s['email_message'] : '', 'enabled' => ! ( isset( $a->post_status ) && $a->post_status === 'draft' ) ];
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
        wp_register_ability( 'atarim/formidable-save-notification', [
            'label' => 'Create/Update Formidable Notification', 'category' => 'atarim',
            'description' => 'Create or update an email-action notification (stored as a frm_form_actions post). Omit notification_id to create a new email action. Settable: name, email_to, email_subject, email_message, enabled. Returns the notification_id. Creation is best-effort.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'notification_id' => [ 'type' => 'integer' ], 'name' => [ 'type' => 'string' ], 'email_to' => [ 'type' => 'string' ], 'email_subject' => [ 'type' => 'string' ], 'email_message' => [ 'type' => 'string' ], 'enabled' => [ 'type' => 'boolean' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notification_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->form_exists( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $is_update = isset( $input['notification_id'] ) && (int) $input['notification_id'] > 0;
                if ( $is_update ) {
                    $post_id = (int) $input['notification_id'];
                    $post = get_post( $post_id );
                    if ( ! $post || $post->post_type !== 'frm_form_actions' ) { return [ 'success' => false, 'message' => 'Notification action not found.' ]; }
                    $settings = json_decode( $post->post_content, true );
                    if ( ! is_array( $settings ) ) { $settings = []; }
                } else {
                    $settings = [ 'email_to' => '[admin_email]', 'event' => [ 'create' ] ];
                    $post_id = 0;
                }
                if ( isset( $input['email_to'] ) ) { $settings['email_to'] = (string) $input['email_to']; }
                if ( isset( $input['email_subject'] ) ) { $settings['email_subject'] = (string) $input['email_subject']; }
                if ( isset( $input['email_message'] ) ) { $settings['email_message'] = (string) $input['email_message']; }
                $post_status = isset( $input['enabled'] ) && empty( $input['enabled'] ) ? 'draft' : 'publish';
                $title = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : 'Email Notification';
                $postarr = [ 'post_type' => 'frm_form_actions', 'post_title' => $title, 'post_excerpt' => 'email', 'menu_order' => (int) $input['form_id'], 'post_status' => $post_status, 'post_content' => wp_slash( wp_json_encode( $settings ) ) ];
                if ( $is_update ) { $postarr['ID'] = $post_id; $rid = wp_update_post( $postarr, true ); }
                else { $rid = wp_insert_post( $postarr, true ); }
                if ( is_wp_error( $rid ) || ! $rid ) { return [ 'success' => false, 'message' => is_wp_error( $rid ) ? $rid->get_error_message() : 'Save failed.' ]; }
                return [ 'success' => true, 'notification_id' => (int) $rid, 'message' => $is_update ? 'Notification updated.' : 'Notification created (verify in the Formidable editor).' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------------- views ----------------------------- */

    private function register_list_views() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-list-views', [
            'label' => 'List Formidable Views', 'category' => 'atarim',
            'description' => 'List Formidable Views (frm_display CPT): { id, title, status, form_id }. Requires Formidable Pro (Views).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'views' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return [ 'success' => false, 'message' => 'Formidable Views require Formidable Pro.' ]; }
                if ( ! post_type_exists( 'frm_display' ) ) { return [ 'success' => false, 'message' => 'Views post type (frm_display) not registered.' ]; }
                $posts = get_posts( [ 'post_type' => 'frm_display', 'post_status' => 'any', 'numberposts' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 100 ] );
                $want_form = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
                $out = [];
                foreach ( (array) $posts as $p ) {
                    $linked = (int) get_post_meta( $p->ID, 'frm_form_id', true );
                    if ( $want_form > 0 && $linked !== $want_form ) { continue; }
                    $out[] = [ 'id' => (int) $p->ID, 'title' => (string) $p->post_title, 'status' => (string) $p->post_status, 'form_id' => $linked ];
                }
                return [ 'success' => true, 'views' => $out, 'message' => sprintf( '%d view(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
