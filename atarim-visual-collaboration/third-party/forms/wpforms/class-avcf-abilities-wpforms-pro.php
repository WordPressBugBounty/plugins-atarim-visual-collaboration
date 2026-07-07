<?php
/**
 * WPForms — power MCP abilities (standalone cluster, -pro file).
 *
 * WPForms-specific depth beyond the common surface: form CRUD, entry
 * delete/star/read + notes, full notification & confirmation management, and a
 * read of connected marketing/CRM providers. Entry writers require WPForms Pro.
 * Writers use WPForms' own capabilities with a manage_options fallback; deletes
 * are confirm-gated. Built on WPForms; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_WPForms_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_WPForms_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WPForms_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wpforms_is_available() ) {
            return;
        }
        $this->register_create_form();
        $this->register_update_form();
        $this->register_delete_form();
        $this->register_delete_entry();
        $this->register_star_entry();
        $this->register_mark_entry_read();
        $this->register_list_entry_notes();
        $this->register_add_entry_note();
        $this->register_list_notifications();
        $this->register_save_notification();
        $this->register_list_confirmations();
        $this->register_save_confirmation();
        $this->register_list_providers();
    }

    /* ------------------------------ shared ----------------------------- */

    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function is_pro() { return $this->detector->avcf_wpforms_is_pro(); }
    public function can_create_forms() { return current_user_can( 'wpforms_create_forms' ) || current_user_can( 'wpforms_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_edit_forms() { return current_user_can( 'wpforms_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_delete_forms() { return current_user_can( 'wpforms_delete_forms' ) || current_user_can( 'manage_options' ); }
    public function can_view_entries() { return current_user_can( 'wpforms_view_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'wpforms_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function can_delete_entries() { return current_user_can( 'wpforms_delete_entries' ) || current_user_can( 'manage_options' ); }
    public function lite_block() { return [ 'success' => false, 'message' => AVCF_WPForms_Helpers::LITE_NOTE ]; }

    /** Load decoded form_data + the post, or null. */
    public function load_form( $form_id ) {
        $form = wpforms()->form->get( (int) $form_id, [ 'content_only' => false ] );
        if ( ! $form ) { return null; }
        $data = wpforms_decode( $form->post_content );
        if ( ! is_array( $data ) ) { $data = []; }
        return [ 'post' => $form, 'data' => $data ];
    }
    public function save_form_data( $form_id, $data ) {
        return wp_update_post( [ 'ID' => (int) $form_id, 'post_content' => wpforms_encode( $data ) ], true );
    }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-create-form', [
            'label' => 'Create WPForm', 'category' => 'atarim',
            'description' => 'Create a new WPForms form. Provide a title and an optional simple fields array (each: { type, label, required?, choices?[] }) — field ids are auto-assigned. Returns the new form_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string', 'minLength' => 1 ], 'fields' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ], 'required' => [ 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $title = sanitize_text_field( $input['title'] );
                if ( $title === '' ) { return [ 'success' => false, 'message' => 'A title is required.' ]; }
                if ( ! method_exists( wpforms()->form, 'add' ) ) { return [ 'success' => false, 'message' => 'WPForms form->add() unavailable.' ]; }
                $form_id = wpforms()->form->add( $title );
                if ( is_wp_error( $form_id ) || ! $form_id ) { return [ 'success' => false, 'message' => 'Create failed.' ]; }
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) && $input['fields'] ) {
                    $loaded = $self->load_form( $form_id );
                    if ( $loaded ) {
                        $data = $loaded['data'];
                        if ( ! isset( $data['fields'] ) || ! is_array( $data['fields'] ) ) { $data['fields'] = []; }
                        $i = 1;
                        foreach ( $input['fields'] as $f ) {
                            if ( ! is_array( $f ) ) { continue; }
                            $fid = isset( $f['id'] ) ? (int) $f['id'] : $i;
                            $field = [ 'id' => $fid, 'type' => isset( $f['type'] ) ? (string) $f['type'] : 'text', 'label' => isset( $f['label'] ) ? (string) $f['label'] : ( 'Field ' . $fid ), 'required' => ! empty( $f['required'] ) ? '1' : '' ];
                            if ( isset( $f['choices'] ) && is_array( $f['choices'] ) ) {
                                $field['choices'] = []; $ci = 1;
                                foreach ( $f['choices'] as $c ) { $txt = is_array( $c ) ? ( isset( $c['label'] ) ? $c['label'] : '' ) : (string) $c; $field['choices'][ $ci ] = [ 'label' => (string) $txt ]; $ci++; }
                            }
                            $data['fields'][ $fid ] = $field; $i++;
                        }
                        $self->save_form_data( $form_id, $data );
                    }
                }
                return [ 'success' => true, 'form_id' => (int) $form_id, 'message' => sprintf( 'Form %d created.', (int) $form_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_create_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-form ------------------------- */

    private function register_update_form() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-update-form', [
            'label' => 'Update WPForm', 'category' => 'atarim',
            'description' => 'Update a form: title, and/or a settings patch (merged into form_data["settings"]), and/or a full replacement fields map (keyed by field id). Only provided keys change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'title' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'fields' => [ 'type' => 'object' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $loaded = $self->load_form( (int) $input['form_id'] );
                if ( $loaded === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $data = $loaded['data'];
                $post_update = [ 'ID' => (int) $input['form_id'] ];
                if ( isset( $input['title'] ) ) {
                    $t = sanitize_text_field( $input['title'] );
                    $data['settings']['form_title'] = $t;
                    $post_update['post_title'] = $t;
                }
                if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
                    if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) { $data['settings'] = []; }
                    $data['settings'] = array_merge( $data['settings'], $input['settings'] );
                }
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) { $data['fields'] = $input['fields']; }
                $post_update['post_content'] = wpforms_encode( $data );
                $r = wp_update_post( $post_update, true );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => 'Form updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-form ------------------------- */

    private function register_delete_form() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-delete-form', [
            'label' => 'Delete WPForm', 'category' => 'atarim',
            'description' => 'Permanently delete a form (and its entries). DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $form_id = (int) $input['form_id'];
                if ( ! wpforms()->form->get( $form_id, [ 'content_only' => false ] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this form and its entries.' ]; }
                $ok = false;
                if ( method_exists( wpforms()->form, 'delete' ) ) { $ok = (bool) wpforms()->form->delete( [ $form_id ] ); }
                else { $ok = (bool) wp_delete_post( $form_id, true ); }
                return $ok ? [ 'success' => true, 'message' => sprintf( 'Form %d deleted.', $form_id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_forms(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- delete-entry ------------------------ */

    private function register_delete_entry() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-delete-entry', [
            'label' => 'Delete WPForms Entry', 'category' => 'atarim',
            'description' => 'Permanently delete an entry. WPForms Pro only. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block(); }
                $entry_id = (int) $input['entry_id'];
                if ( ! wpforms()->entry->get( $entry_id ) ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this entry.' ]; }
                $ok = (bool) wpforms()->entry->delete( $entry_id );
                return $ok ? [ 'success' => true, 'message' => sprintf( 'Entry %d deleted.', $entry_id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_entries(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------------ star-entry ------------------------- */

    private function register_star_entry() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-star-entry', [
            'label' => 'Star WPForms Entry', 'category' => 'atarim',
            'description' => 'Star or unstar an entry (starred:true/false). WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'starred' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block(); }
                $entry_id = (int) $input['entry_id'];
                if ( ! wpforms()->entry->get( $entry_id ) ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $val = ! isset( $input['starred'] ) || ! empty( $input['starred'] ) ? 1 : 0;
                $r = wpforms()->entry->update( $entry_id, [ 'starred' => $val ], '', 'edit', [ 'cap' => 'edit_entries_form_single' ] );
                return $r ? [ 'success' => true, 'message' => $val ? 'Entry starred.' : 'Entry unstarred.' ] : [ 'success' => false, 'message' => 'Update failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- mark-entry-read ------------------------ */

    private function register_mark_entry_read() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-mark-entry-read', [
            'label' => 'Mark WPForms Entry Read', 'category' => 'atarim',
            'description' => 'Mark an entry read or unread (read:true/false). WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'read' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block(); }
                $entry_id = (int) $input['entry_id'];
                if ( ! wpforms()->entry->get( $entry_id ) ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $val = ! isset( $input['read'] ) || ! empty( $input['read'] ) ? 1 : 0;
                $r = wpforms()->entry->update( $entry_id, [ 'viewed' => $val ], '', 'edit', [ 'cap' => 'edit_entries_form_single' ] );
                return $r ? [ 'success' => true, 'message' => $val ? 'Entry marked read.' : 'Entry marked unread.' ] : [ 'success' => false, 'message' => 'Update failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- list-entry-notes ----------------------- */

    private function register_list_entry_notes() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-list-entry-notes', [
            'label' => 'List WPForms Entry Notes', 'category' => 'atarim',
            'description' => 'List admin notes on an entry: { id, data, date, user_id }. WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block(); }
                if ( ! isset( wpforms()->entry_meta ) || ! method_exists( wpforms()->entry_meta, 'get_meta' ) ) { return [ 'success' => false, 'message' => 'WPForms entry-meta API unavailable.' ]; }
                $raw = wpforms()->entry_meta->get_meta( [ 'entry_id' => (int) $input['entry_id'], 'type' => 'note', 'number' => 200 ] );
                $out = [];
                foreach ( (array) $raw as $n ) { $out[] = [ 'id' => isset( $n->id ) ? (int) $n->id : 0, 'data' => isset( $n->data ) ? (string) $n->data : '', 'date' => isset( $n->date ) ? (string) $n->date : '', 'user_id' => isset( $n->user_id ) ? (int) $n->user_id : 0 ]; }
                return [ 'success' => true, 'notes' => $out, 'message' => sprintf( '%d note(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- add-entry-note ------------------------ */

    private function register_add_entry_note() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-add-entry-note', [
            'label' => 'Add WPForms Entry Note', 'category' => 'atarim',
            'description' => 'Attach an admin note to an entry (attributed to the current user). WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'note' => [ 'type' => 'string', 'minLength' => 1 ] ], 'required' => [ 'entry_id', 'note' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block(); }
                if ( ! isset( wpforms()->entry_meta ) || ! method_exists( wpforms()->entry_meta, 'add' ) ) { return [ 'success' => false, 'message' => 'WPForms entry-meta API unavailable.' ]; }
                $entry = wpforms()->entry->get( (int) $input['entry_id'] );
                if ( ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $r = wpforms()->entry_meta->add( [ 'entry_id' => (int) $input['entry_id'], 'form_id' => isset( $entry->form_id ) ? (int) $entry->form_id : 0, 'user_id' => get_current_user_id(), 'type' => 'note', 'data' => wp_kses_post( $input['note'] ) ], 'entry_meta' );
                return $r ? [ 'success' => true, 'message' => 'Note added.' ] : [ 'success' => false, 'message' => 'Add note failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- list-notifications ---------------------- */

    private function register_list_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-list-notifications', [
            'label' => 'List WPForms Notifications', 'category' => 'atarim',
            'description' => 'List a form\'s notifications in full: { id, name, email, subject, message, enabled }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notifications' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $loaded = $self->load_form( (int) $input['form_id'] );
                if ( $loaded === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $set = isset( $loaded['data']['settings']['notifications'] ) && is_array( $loaded['data']['settings']['notifications'] ) ? $loaded['data']['settings']['notifications'] : [];
                $out = [];
                foreach ( $set as $nid => $n ) {
                    if ( ! is_array( $n ) ) { continue; }
                    $out[] = [ 'id' => (string) $nid, 'name' => isset( $n['notification_name'] ) ? (string) $n['notification_name'] : '', 'email' => isset( $n['email'] ) ? (string) $n['email'] : '', 'subject' => isset( $n['subject'] ) ? (string) $n['subject'] : '', 'message' => isset( $n['message'] ) ? (string) $n['message'] : '', 'enabled' => ! isset( $n['enable'] ) || ! empty( $n['enable'] ) ];
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
        wp_register_ability( 'atarim/wpforms-save-notification', [
            'label' => 'Create/Update WPForms Notification', 'category' => 'atarim',
            'description' => 'Create or update a notification on a form. Omit notification_id to create (a numeric id is generated). Settable: name, email, subject, message, enabled. Returns the notification_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'notification_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ], 'subject' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ], 'enabled' => [ 'type' => 'boolean' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notification_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $loaded = $self->load_form( (int) $input['form_id'] );
                if ( $loaded === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $data = $loaded['data'];
                if ( ! isset( $data['settings']['notifications'] ) || ! is_array( $data['settings']['notifications'] ) ) { $data['settings']['notifications'] = []; }
                $nid = isset( $input['notification_id'] ) && $input['notification_id'] !== '' ? (string) $input['notification_id'] : (string) ( count( $data['settings']['notifications'] ) + 1 );
                $n = isset( $data['settings']['notifications'][ $nid ] ) && is_array( $data['settings']['notifications'][ $nid ] ) ? $data['settings']['notifications'][ $nid ] : [];
                if ( isset( $input['name'] ) ) { $n['notification_name'] = sanitize_text_field( $input['name'] ); }
                if ( isset( $input['email'] ) ) { $n['email'] = (string) $input['email']; }
                if ( isset( $input['subject'] ) ) { $n['subject'] = (string) $input['subject']; }
                if ( isset( $input['message'] ) ) { $n['message'] = (string) $input['message']; }
                if ( isset( $input['enabled'] ) ) { $n['enable'] = ! empty( $input['enabled'] ) ? '1' : '0'; }
                $data['settings']['notifications'][ $nid ] = $n;
                $r = $self->save_form_data( (int) $input['form_id'], $data );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'notification_id' => $nid, 'message' => 'Notification saved.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- list-confirmations ---------------------- */

    private function register_list_confirmations() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-list-confirmations', [
            'label' => 'List WPForms Confirmations', 'category' => 'atarim',
            'description' => 'List a form\'s confirmations: { id, name, type (message|page|redirect), message, page, redirect }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'confirmations' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $loaded = $self->load_form( (int) $input['form_id'] );
                if ( $loaded === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $set = isset( $loaded['data']['settings']['confirmations'] ) && is_array( $loaded['data']['settings']['confirmations'] ) ? $loaded['data']['settings']['confirmations'] : [];
                $out = [];
                foreach ( $set as $cid => $c ) {
                    if ( ! is_array( $c ) ) { continue; }
                    $out[] = [ 'id' => (string) $cid, 'name' => isset( $c['name'] ) ? (string) $c['name'] : '', 'type' => isset( $c['type'] ) ? (string) $c['type'] : 'message', 'message' => isset( $c['message'] ) ? (string) $c['message'] : '', 'page' => isset( $c['page'] ) ? $c['page'] : null, 'redirect' => isset( $c['redirect'] ) ? (string) $c['redirect'] : '' ];
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
        wp_register_ability( 'atarim/wpforms-save-confirmation', [
            'label' => 'Create/Update WPForms Confirmation', 'category' => 'atarim',
            'description' => 'Create or update a confirmation. Omit confirmation_id to create. Settable: name, type (message|page|redirect), message, page (page id), redirect (url). Returns the confirmation_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirmation_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'enum' => [ 'message', 'page', 'redirect' ] ], 'message' => [ 'type' => 'string' ], 'page' => [ 'type' => 'integer' ], 'redirect' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'confirmation_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $loaded = $self->load_form( (int) $input['form_id'] );
                if ( $loaded === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $data = $loaded['data'];
                if ( ! isset( $data['settings']['confirmations'] ) || ! is_array( $data['settings']['confirmations'] ) ) { $data['settings']['confirmations'] = []; }
                $cid = isset( $input['confirmation_id'] ) && $input['confirmation_id'] !== '' ? (string) $input['confirmation_id'] : (string) ( count( $data['settings']['confirmations'] ) + 1 );
                $c = isset( $data['settings']['confirmations'][ $cid ] ) && is_array( $data['settings']['confirmations'][ $cid ] ) ? $data['settings']['confirmations'][ $cid ] : [];
                if ( isset( $input['name'] ) ) { $c['name'] = sanitize_text_field( $input['name'] ); }
                if ( isset( $input['type'] ) ) { $c['type'] = (string) $input['type']; }
                if ( isset( $input['message'] ) ) { $c['message'] = (string) $input['message']; }
                if ( isset( $input['page'] ) ) { $c['page'] = (int) $input['page']; }
                if ( isset( $input['redirect'] ) ) { $c['redirect'] = esc_url_raw( $input['redirect'] ); }
                $data['settings']['confirmations'][ $cid ] = $c;
                $r = $self->save_form_data( (int) $input['form_id'], $data );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'confirmation_id' => $cid, 'message' => 'Confirmation saved.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- providers --------------------------- */

    private function register_list_providers() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-list-providers', [
            'label' => 'List WPForms Form Providers', 'category' => 'atarim',
            'description' => 'List connected marketing/CRM provider integrations on a form (e.g. mailchimp, sendinblue, zapier): { provider, connections: [{ id, name }] }, read from the form\'s providers config.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'providers' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $loaded = $self->load_form( (int) $input['form_id'] );
                if ( $loaded === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $providers = isset( $loaded['data']['providers'] ) && is_array( $loaded['data']['providers'] ) ? $loaded['data']['providers'] : [];
                $out = [];
                foreach ( $providers as $slug => $conns ) {
                    $connections = [];
                    if ( is_array( $conns ) ) {
                        foreach ( $conns as $conn_id => $conn ) { $connections[] = [ 'id' => (string) $conn_id, 'name' => is_array( $conn ) && isset( $conn['connection_name'] ) ? (string) $conn['connection_name'] : '' ]; }
                    }
                    $out[] = [ 'provider' => (string) $slug, 'connections' => $connections ];
                }
                return [ 'success' => true, 'providers' => $out, 'message' => sprintf( '%d provider(s) connected.', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
