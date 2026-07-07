<?php
/**
 * Ninja Forms — power MCP abilities (standalone cluster, -pro file).
 *
 * Ninja-specific depth via the Ninja_Forms() model API: form create/update/
 * delete, field list/create/update/delete, submission delete + bulk-delete,
 * action listing/toggling, email-notification save, and a best-effort form JSON
 * export. Ninja's model methods vary across versions, so every model call is
 * guarded with method_exists; create-form and create-field are experimental;
 * deletes are confirm-gated. Permission baseline is manage_options. Built on
 * Ninja's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Ninja_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Ninja_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Ninja_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_ninja_is_available() ) {
            return;
        }
        $this->register_create_form();
        $this->register_update_form();
        $this->register_delete_form();
        $this->register_list_fields();
        $this->register_create_field();
        $this->register_update_field();
        $this->register_delete_field();
        $this->register_delete_entry();
        $this->register_bulk_delete_entries();
        $this->register_list_actions();
        $this->register_toggle_action();
        $this->register_save_notification();
        $this->register_export_form();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function can_manage() { return current_user_can( 'manage_options' ); }

    public function form_model( $form_id ) {
        $m = Ninja_Forms()->form( (int) $form_id )->get();
        if ( ! $m || ! method_exists( $m, 'get_setting' ) ) { return null; }
        if ( method_exists( $m, 'get_id' ) && ! $m->get_id() && (string) $m->get_setting( 'title' ) === '' ) { return null; }
        return $m;
    }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-create-form', [
            'label' => 'Create Ninja Form', 'category' => 'atarim',
            'description' => 'Create a new (empty) Ninja form. EXPERIMENTAL. Provide title. Add fields afterward with ninja-create-field. Returns the new form_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string', 'minLength' => 1 ] ], 'required' => [ 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $title = sanitize_text_field( $input['title'] );
                if ( $title === '' ) { return [ 'success' => false, 'message' => 'A title is required.' ]; }
                $form = Ninja_Forms()->form()->get();
                if ( ! $form || ! method_exists( $form, 'update_setting' ) || ! method_exists( $form, 'save' ) ) { return [ 'success' => false, 'message' => 'Ninja form model not creatable in this version.' ]; }
                $form->update_setting( 'title', $title );
                $form->save();
                $new_id = method_exists( $form, 'get_id' ) ? (int) $form->get_id() : 0;
                if ( ! $new_id ) { return [ 'success' => false, 'message' => 'Create failed.' ]; }
                return [ 'success' => true, 'form_id' => $new_id, 'message' => sprintf( 'Form %d created (experimental — verify in the Ninja editor).', $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-form ------------------------- */

    private function register_update_form() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-update-form', [
            'label' => 'Update Ninja Form', 'category' => 'atarim',
            'description' => 'Update a form\'s title and/or arbitrary form-level settings (settings is a map of setting_key => value). Only provided keys change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'title' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $form = $self->form_model( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! method_exists( $form, 'update_setting' ) || ! method_exists( $form, 'save' ) ) { return [ 'success' => false, 'message' => 'Form not editable in this version.' ]; }
                if ( isset( $input['title'] ) ) { $form->update_setting( 'title', sanitize_text_field( $input['title'] ) ); }
                if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) { foreach ( $input['settings'] as $k => $v ) { $form->update_setting( (string) $k, $v ); } }
                $form->save();
                return [ 'success' => true, 'message' => 'Form updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-form ------------------------- */

    private function register_delete_form() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-delete-form', [
            'label' => 'Delete Ninja Form', 'category' => 'atarim',
            'description' => 'Permanently delete a form and its fields/actions. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $form = $self->form_model( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $count = AVCF_Ninja_Helpers::count_submissions( (int) $input['form_id'], true );
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to delete this form (it has %d submission(s), which remain as orphaned nf_sub posts).', $count ) ]; }
                $factory = Ninja_Forms()->form( (int) $input['form_id'] );
                if ( method_exists( $factory, 'delete' ) ) { $factory->delete(); }
                elseif ( method_exists( $form, 'delete' ) ) { $form->delete(); }
                else { return [ 'success' => false, 'message' => 'No delete method available in this version.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Form %d deleted.', (int) $input['form_id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- list-fields ------------------------- */

    private function register_list_fields() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-list-fields', [
            'label' => 'List Ninja Fields', 'category' => 'atarim',
            'description' => 'Detailed field list for a form: { id, key, type, label, required, settings } (full settings included).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'fields' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $models = Ninja_Forms()->form( (int) $input['form_id'] )->get_fields();
                $out = [];
                foreach ( (array) $models as $fid => $fm ) {
                    if ( ! is_object( $fm ) || ! method_exists( $fm, 'get_settings' ) ) { continue; }
                    $s = $fm->get_settings();
                    $out[] = [ 'id' => (string) $fid, 'key' => isset( $s['key'] ) ? (string) $s['key'] : '', 'type' => isset( $s['type'] ) ? (string) $s['type'] : '', 'label' => isset( $s['label'] ) ? (string) $s['label'] : '', 'required' => ! empty( $s['required'] ), 'settings' => $s ];
                }
                return [ 'success' => true, 'fields' => $out, 'message' => sprintf( '%d field(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- create-field ------------------------ */

    private function register_create_field() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-create-field', [
            'label' => 'Create Ninja Field', 'category' => 'atarim',
            'description' => 'Add a field to a form. EXPERIMENTAL. Provide type (e.g. textbox, email, textarea, listselect, checkbox), label, required?, key?. Returns the new field_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'type' => [ 'type' => 'string', 'minLength' => 1 ], 'label' => [ 'type' => 'string', 'minLength' => 1 ], 'required' => [ 'type' => 'boolean', 'default' => false ], 'key' => [ 'type' => 'string' ] ], 'required' => [ 'form_id', 'type', 'label' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'field_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->form_model( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $factory = Ninja_Forms()->form( (int) $input['form_id'] );
                if ( ! method_exists( $factory, 'field' ) ) { return [ 'success' => false, 'message' => 'Field creation unavailable in this version.' ]; }
                $field = $factory->field()->get();
                if ( ! $field || ! method_exists( $field, 'update_setting' ) || ! method_exists( $field, 'save' ) ) { return [ 'success' => false, 'message' => 'Field model not creatable in this version.' ]; }
                $key = isset( $input['key'] ) && $input['key'] !== '' ? sanitize_key( $input['key'] ) : ( sanitize_key( $input['label'] ) . '_' . wp_rand( 100, 999 ) );
                $field->update_setting( 'type', (string) $input['type'] );
                $field->update_setting( 'label', sanitize_text_field( $input['label'] ) );
                $field->update_setting( 'key', $key );
                $field->update_setting( 'required', ! empty( $input['required'] ) ? 1 : 0 );
                if ( method_exists( $field, 'update_setting' ) ) { $field->update_setting( 'parent_id', (int) $input['form_id'] ); }
                $field->save();
                $fid = method_exists( $field, 'get_id' ) ? (int) $field->get_id() : 0;
                if ( ! $fid ) { return [ 'success' => false, 'message' => 'Create failed.' ]; }
                return [ 'success' => true, 'field_id' => $fid, 'message' => sprintf( 'Field %d created (experimental — verify in the Ninja editor).', $fid ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-field ------------------------ */

    private function register_update_field() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-update-field', [
            'label' => 'Update Ninja Field', 'category' => 'atarim',
            'description' => 'Patch a field\'s settings (settings is a map of setting_key => value, e.g. label, required, placeholder). Only provided keys change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'field_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'form_id', 'field_id', 'settings' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $fields = Ninja_Forms()->form( (int) $input['form_id'] )->get_fields();
                $fid = (string) $input['field_id'];
                if ( ! isset( $fields[ $fid ] ) ) { return [ 'success' => false, 'message' => 'Field not found on this form.' ]; }
                $field = $fields[ $fid ];
                if ( ! method_exists( $field, 'update_setting' ) || ! method_exists( $field, 'save' ) ) { return [ 'success' => false, 'message' => 'Field not editable in this version.' ]; }
                foreach ( (array) $input['settings'] as $k => $v ) { $field->update_setting( (string) $k, $v ); }
                $field->save();
                return [ 'success' => true, 'message' => 'Field updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-field ------------------------ */

    private function register_delete_field() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-delete-field', [
            'label' => 'Delete Ninja Field', 'category' => 'atarim',
            'description' => 'Delete a field from a form. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'field_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id', 'field_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $fields = Ninja_Forms()->form( (int) $input['form_id'] )->get_fields();
                $fid = (string) $input['field_id'];
                if ( ! isset( $fields[ $fid ] ) ) { return [ 'success' => false, 'message' => 'Field not found on this form.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to delete this field and its stored values.' ]; }
                $field = $fields[ $fid ];
                if ( ! method_exists( $field, 'delete' ) ) { return [ 'success' => false, 'message' => 'Field delete unavailable in this version.' ]; }
                $field->delete();
                return [ 'success' => true, 'message' => sprintf( 'Field %d deleted.', (int) $input['field_id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- delete-entry ------------------------ */

    private function register_delete_entry() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-delete-entry', [
            'label' => 'Delete Ninja Entry', 'category' => 'atarim',
            'description' => 'Permanently delete a submission (nf_sub post). DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $entry_id = (int) $input['entry_id'];
                $post = get_post( $entry_id );
                if ( ! $post || $post->post_type !== 'nf_sub' ) { return [ 'success' => false, 'message' => 'Submission not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this submission.' ]; }
                $r = wp_delete_post( $entry_id, true );
                return $r ? [ 'success' => true, 'message' => sprintf( 'Submission %d deleted.', $entry_id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* -------------------------- bulk-delete-entries -------------------- */

    private function register_bulk_delete_entries() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-bulk-delete-entries', [
            'label' => 'Bulk Delete Ninja Entries', 'category' => 'atarim',
            'description' => 'Permanently delete multiple submissions. Provide entry_ids[]. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'minItems' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'entry_ids' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $ids = array_values( array_filter( array_map( 'intval', (array) $input['entry_ids'] ) ) );
                if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'No valid entry ids.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to permanently delete %d submission(s).', count( $ids ) ) ]; }
                $deleted = 0;
                foreach ( $ids as $id ) {
                    $post = get_post( $id );
                    if ( $post && $post->post_type === 'nf_sub' && wp_delete_post( $id, true ) ) { $deleted++; }
                }
                return [ 'success' => true, 'deleted' => $deleted, 'message' => sprintf( '%d submission(s) deleted.', $deleted ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- list-actions ------------------------ */

    private function register_list_actions() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-list-actions', [
            'label' => 'List Ninja Actions', 'category' => 'atarim',
            'description' => 'List all actions on a form (not just email): { id, type, label, active }. Actions include email, store-submission, redirect, webhooks, integrations, etc.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'actions' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $out = [];
                foreach ( (array) Ninja_Forms()->form( (int) $input['form_id'] )->get_actions() as $aid => $a ) {
                    if ( ! is_object( $a ) || ! method_exists( $a, 'get_settings' ) ) { continue; }
                    $s = $a->get_settings();
                    $out[] = [ 'id' => (string) $aid, 'type' => isset( $s['type'] ) ? (string) $s['type'] : '', 'label' => isset( $s['label'] ) ? (string) $s['label'] : '', 'active' => ! isset( $s['active'] ) || ! empty( $s['active'] ) ];
                }
                return [ 'success' => true, 'actions' => $out, 'message' => sprintf( '%d action(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- toggle-action ----------------------- */

    private function register_toggle_action() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-toggle-action', [
            'label' => 'Toggle Ninja Action', 'category' => 'atarim',
            'description' => 'Enable or disable an action (active:true/false).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'action_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'active' => [ 'type' => 'boolean' ] ], 'required' => [ 'form_id', 'action_id', 'active' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $actions = Ninja_Forms()->form( (int) $input['form_id'] )->get_actions();
                $aid = (string) $input['action_id'];
                if ( ! isset( $actions[ $aid ] ) ) { return [ 'success' => false, 'message' => 'Action not found.' ]; }
                $action = $actions[ $aid ];
                if ( ! method_exists( $action, 'update_setting' ) || ! method_exists( $action, 'save' ) ) { return [ 'success' => false, 'message' => 'Action not editable in this version.' ]; }
                $action->update_setting( 'active', ! empty( $input['active'] ) ? 1 : 0 );
                $action->save();
                return [ 'success' => true, 'message' => ! empty( $input['active'] ) ? 'Action enabled.' : 'Action disabled.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- save-notification ---------------------- */

    private function register_save_notification() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-save-notification', [
            'label' => 'Create/Update Ninja Notification', 'category' => 'atarim',
            'description' => 'Create or update an email action. Omit action_id to create a new email action. Settable: label, to (recipients), email_subject, email_message. Returns the action_id. Creation is best-effort.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'action_id' => [ 'type' => 'integer' ], 'label' => [ 'type' => 'string' ], 'to' => [ 'type' => 'string' ], 'email_subject' => [ 'type' => 'string' ], 'email_message' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'action_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->form_model( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $factory = Ninja_Forms()->form( (int) $input['form_id'] );
                $action = null;
                if ( isset( $input['action_id'] ) && (int) $input['action_id'] > 0 ) {
                    $actions = $factory->get_actions();
                    $aid = (string) $input['action_id'];
                    if ( ! isset( $actions[ $aid ] ) ) { return [ 'success' => false, 'message' => 'Action not found.' ]; }
                    $action = $actions[ $aid ];
                } else {
                    if ( ! method_exists( $factory, 'action' ) ) { return [ 'success' => false, 'message' => 'Action creation unavailable in this version.' ]; }
                    $action = $factory->action()->get();
                    if ( $action && method_exists( $action, 'update_setting' ) ) { $action->update_setting( 'type', 'email' ); $action->update_setting( 'parent_id', (int) $input['form_id'] ); }
                }
                if ( ! $action || ! method_exists( $action, 'update_setting' ) || ! method_exists( $action, 'save' ) ) { return [ 'success' => false, 'message' => 'Action model not editable in this version.' ]; }
                if ( isset( $input['label'] ) ) { $action->update_setting( 'label', sanitize_text_field( $input['label'] ) ); }
                if ( isset( $input['to'] ) ) { $action->update_setting( 'to', (string) $input['to'] ); }
                if ( isset( $input['email_subject'] ) ) { $action->update_setting( 'email_subject', (string) $input['email_subject'] ); }
                if ( isset( $input['email_message'] ) ) { $action->update_setting( 'email_message', (string) $input['email_message'] ); }
                $action->save();
                $aid = method_exists( $action, 'get_id' ) ? (int) $action->get_id() : 0;
                return [ 'success' => true, 'action_id' => $aid, 'message' => 'Notification saved (verify in the Ninja editor).' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- export-form ------------------------- */

    private function register_export_form() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-export-form', [
            'label' => 'Export Ninja Form', 'category' => 'atarim',
            'description' => 'Export a form definition as a Ninja JSON structure (for backup/migration). Best-effort: uses NF_Database_Models_Form::export when available, otherwise the form settings.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'export' => [], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $form_id = (int) $input['form_id'];
                if ( ! $self->form_model( $form_id ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( class_exists( 'NF_Database_Models_Form' ) && method_exists( 'NF_Database_Models_Form', 'export' ) ) {
                    $export = NF_Database_Models_Form::export( $form_id, true );
                    if ( $export ) { return [ 'success' => true, 'export' => $export, 'message' => 'OK.' ]; }
                }
                $model = Ninja_Forms()->form( $form_id )->get();
                $settings = ( $model && method_exists( $model, 'get_settings' ) ) ? $model->get_settings() : [];
                return [ 'success' => true, 'export' => [ 'settings' => $settings, 'fields' => AVCF_Ninja_Helpers::map_fields( $form_id ) ], 'message' => 'Exported form settings + fields (native export unavailable).' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
