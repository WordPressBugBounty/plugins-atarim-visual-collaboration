<?php
/**
 * Fluent Forms — power MCP abilities (standalone cluster, -pro file).
 *
 * Fluent-specific depth: form CRUD, submission status/favourite/delete workflow,
 * a generic form_meta read/write (Fluent keeps notifications, confirmations and
 * integration feeds as JSON rows in fluentform_form_meta), typed notification
 * helpers, confirmation read, and an integration-feed listing. Writers use
 * Fluent's capabilities with a manage_options fallback; deletes are
 * confirm-gated. create-form is experimental (raw insert of Fluent's JSON
 * schema). Direct $wpdb; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Fluent_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Fluent_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Fluent_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_fluent_is_available() ) {
            return;
        }
        $this->register_create_form();
        $this->register_update_form();
        $this->register_delete_form();
        $this->register_delete_entry();
        $this->register_set_entry_status();
        $this->register_favorite_entry();
        $this->register_list_form_meta();
        $this->register_get_form_meta();
        $this->register_save_form_meta();
        $this->register_list_notifications();
        $this->register_save_notification();
        $this->register_get_confirmation();
        $this->register_list_integration_feeds();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function can_edit_forms() { return current_user_can( 'fluentform_forms_manager' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'fluentform_entries_viewer' ) || current_user_can( 'manage_options' ); }
    public function can_settings() { return current_user_can( 'fluentform_settings_manager' ) || current_user_can( 'fluentform_forms_manager' ) || current_user_can( 'manage_options' ); }

    public function form_exists( $form_id ) {
        global $wpdb; $t = AVCF_Fluent_Helpers::forms_table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE id = %d", (int) $form_id ) ) > 0;
    }
    /** Decoded JSON for a meta_key, or null. */
    public function get_meta( $form_id, $key ) {
        global $wpdb; $t = AVCF_Fluent_Helpers::meta_table();
        $val = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM `{$t}` WHERE form_id = %d AND meta_key = %s LIMIT 1", (int) $form_id, (string) $key ) );
        if ( $val === null ) { return null; }
        $decoded = json_decode( $val, true );
        return $decoded === null ? $val : $decoded;
    }
    public function set_meta( $form_id, $key, $value ) {
        global $wpdb; $t = AVCF_Fluent_Helpers::meta_table();
        $json = is_string( $value ) ? $value : wp_json_encode( $value );
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE form_id = %d AND meta_key = %s LIMIT 1", (int) $form_id, (string) $key ) );
        if ( $id ) { return $wpdb->update( $t, [ 'value' => $json ], [ 'id' => (int) $id ], [ '%s' ], [ '%d' ] ); }
        return $wpdb->insert( $t, [ 'form_id' => (int) $form_id, 'meta_key' => (string) $key, 'value' => $json ] );
    }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-create-form', [
            'label' => 'Create Fluent Form', 'category' => 'atarim',
            'description' => 'Create a new Fluent form. EXPERIMENTAL: builds a minimal valid form_fields JSON from a simple fields array (each: { type (e.g. input_text, input_email, textarea, select), label, name?, required?, options?[] }) plus a submit button. Returns the new form_id. Complex field types should be refined in the Fluent editor afterwards.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string', 'minLength' => 1 ], 'fields' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ], 'status' => [ 'type' => 'string', 'enum' => [ 'published', 'unpublished' ], 'default' => 'published' ] ], 'required' => [ 'title' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $title = sanitize_text_field( $input['title'] );
                if ( $title === '' ) { return [ 'success' => false, 'message' => 'A title is required.' ]; }
                $fields = [];
                $defs = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : [];
                $i = 1;
                foreach ( $defs as $f ) {
                    if ( ! is_array( $f ) ) { continue; }
                    $element = isset( $f['type'] ) ? (string) $f['type'] : 'input_text';
                    $name = isset( $f['name'] ) && $f['name'] !== '' ? sanitize_key( $f['name'] ) : ( 'field_' . $i );
                    $label = isset( $f['label'] ) ? (string) $f['label'] : ( 'Field ' . $i );
                    $settings = [ 'label' => $label, 'validation_rules' => [ 'required' => [ 'value' => ! empty( $f['required'] ), 'message' => 'This field is required.' ] ] ];
                    if ( isset( $f['options'] ) && is_array( $f['options'] ) ) {
                        $settings['advanced_options'] = [];
                        foreach ( $f['options'] as $opt ) { $txt = is_array( $opt ) ? ( isset( $opt['label'] ) ? $opt['label'] : '' ) : (string) $opt; $settings['advanced_options'][] = [ 'label' => (string) $txt, 'value' => sanitize_title( (string) $txt ) ]; }
                    }
                    $fields[] = [ 'index' => $i - 1, 'element' => $element, 'attributes' => [ 'type' => 'text', 'name' => $name ], 'settings' => $settings, 'editor_options' => [ 'title' => $label ] ];
                    $i++;
                }
                $form_fields = wp_json_encode( [ 'fields' => $fields, 'submitButton' => [ 'uniqElKey' => 'el_' . time(), 'element' => 'button', 'attributes' => [ 'type' => 'submit', 'class' => '' ], 'settings' => [ 'button_style' => 'default', 'align' => 'left', 'button_size' => 'md', 'color' => '#ffffff', 'background_color' => '#409EFF', 'button_ui' => [ 'type' => 'default', 'text' => 'Submit' ] ] ] ] );
                $now = current_time( 'mysql' );
                $ok = $wpdb->insert( AVCF_Fluent_Helpers::forms_table(), [ 'title' => $title, 'form_fields' => $form_fields, 'status' => isset( $input['status'] ) ? (string) $input['status'] : 'published', 'has_payment' => 0, 'type' => 'form', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ] );
                if ( ! $ok ) { return [ 'success' => false, 'message' => 'Create failed (schema mismatch possible).' ]; }
                return [ 'success' => true, 'form_id' => (int) $wpdb->insert_id, 'message' => sprintf( 'Form %d created (experimental — verify in the Fluent editor).', (int) $wpdb->insert_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-form ------------------------- */

    private function register_update_form() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-update-form', [
            'label' => 'Update Fluent Form', 'category' => 'atarim',
            'description' => 'Update a form\'s title and/or status, and/or replace its form_fields with a full structure object (must contain "fields"). Only provided keys change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'title' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'enum' => [ 'published', 'unpublished' ] ], 'form_fields' => [ 'type' => 'object' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $form_id = (int) $input['form_id'];
                if ( ! $self->form_exists( $form_id ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $data = [ 'updated_at' => current_time( 'mysql' ) ]; $fmt = [ '%s' ];
                if ( isset( $input['title'] ) ) { $data['title'] = sanitize_text_field( $input['title'] ); $fmt[] = '%s'; }
                if ( isset( $input['status'] ) ) { $data['status'] = (string) $input['status']; $fmt[] = '%s'; }
                if ( isset( $input['form_fields'] ) && is_array( $input['form_fields'] ) ) {
                    if ( ! isset( $input['form_fields']['fields'] ) ) { return [ 'success' => false, 'message' => 'form_fields must contain a "fields" array.' ]; }
                    $data['form_fields'] = wp_json_encode( $input['form_fields'] ); $fmt[] = '%s';
                }
                $r = $wpdb->update( AVCF_Fluent_Helpers::forms_table(), $data, [ 'id' => $form_id ], $fmt, [ '%d' ] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => 'Form updated.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-form ------------------------- */

    private function register_delete_form() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-delete-form', [
            'label' => 'Delete Fluent Form', 'category' => 'atarim',
            'description' => 'Permanently delete a form, its form_meta, and all its submissions (+ submission_meta). DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $form_id = (int) $input['form_id'];
                if ( ! $self->form_exists( $form_id ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $sub_count = AVCF_Fluent_Helpers::count_entries( $form_id, true );
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to delete this form, its settings, and %d submission(s).', $sub_count ) ]; }
                $subs = AVCF_Fluent_Helpers::subs_table();
                $sub_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$subs}` WHERE form_id = %d", $form_id ) );
                if ( $sub_ids ) {
                    $smeta = $wpdb->prefix . 'fluentform_submission_meta';
                    $in = implode( ',', array_map( 'intval', $sub_ids ) );
                    $wpdb->query( "DELETE FROM `{$smeta}` WHERE response_id IN ({$in})" );
                }
                $wpdb->delete( $subs, [ 'form_id' => $form_id ], [ '%d' ] );
                $wpdb->delete( AVCF_Fluent_Helpers::meta_table(), [ 'form_id' => $form_id ], [ '%d' ] );
                $wpdb->delete( AVCF_Fluent_Helpers::forms_table(), [ 'id' => $form_id ], [ '%d' ] );
                return [ 'success' => true, 'message' => sprintf( 'Form %d and %d submission(s) deleted.', $form_id, count( (array) $sub_ids ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- delete-entry ------------------------ */

    private function register_delete_entry() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-delete-entry', [
            'label' => 'Delete Fluent Entry', 'category' => 'atarim',
            'description' => 'Permanently delete a submission and its meta. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $entry_id = (int) $input['entry_id'];
                $subs = AVCF_Fluent_Helpers::subs_table();
                if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$subs}` WHERE id = %d", $entry_id ) ) === 0 ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this submission.' ]; }
                $smeta = $wpdb->prefix . 'fluentform_submission_meta';
                $wpdb->delete( $smeta, [ 'response_id' => $entry_id ], [ '%d' ] );
                $wpdb->delete( $subs, [ 'id' => $entry_id ], [ '%d' ] );
                return [ 'success' => true, 'message' => sprintf( 'Entry %d deleted.', $entry_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- set-entry-status ---------------------- */

    private function register_set_entry_status() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-set-entry-status', [
            'label' => 'Set Fluent Entry Status', 'category' => 'atarim',
            'description' => 'Set a submission\'s status: unread, read, spam, or trashed.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'status' => [ 'type' => 'string', 'enum' => [ 'unread', 'read', 'spam', 'trashed' ] ] ], 'required' => [ 'entry_id', 'status' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $r = $wpdb->update( AVCF_Fluent_Helpers::subs_table(), [ 'status' => (string) $input['status'] ], [ 'id' => (int) $input['entry_id'] ], [ '%s' ], [ '%d' ] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Entry status set to %s.', (string) $input['status'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- favorite-entry ----------------------- */

    private function register_favorite_entry() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-favorite-entry', [
            'label' => 'Favorite Fluent Entry', 'category' => 'atarim',
            'description' => 'Mark a submission as favourite (or clear with favorite:false).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'favorite' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $val = ! isset( $input['favorite'] ) || ! empty( $input['favorite'] ) ? 1 : 0;
                $r = $wpdb->update( AVCF_Fluent_Helpers::subs_table(), [ 'is_favourite' => $val ], [ 'id' => (int) $input['entry_id'] ], [ '%d' ], [ '%d' ] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => $val ? 'Entry favourited.' : 'Entry unfavourited.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- list-form-meta ----------------------- */

    private function register_list_form_meta() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-list-form-meta', [
            'label' => 'List Fluent Form Meta Keys', 'category' => 'atarim',
            'description' => 'List the form_meta keys stored on a form (e.g. notifications, formSettings, *_feeds), with value sizes. Use fluent-get-form-meta to read one.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'meta_keys' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb; $t = AVCF_Fluent_Helpers::meta_table();
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, meta_key, LENGTH(value) AS len FROM `{$t}` WHERE form_id = %d ORDER BY meta_key ASC", (int) $input['form_id'] ) );
                $out = [];
                foreach ( (array) $rows as $r ) { $out[] = [ 'id' => (int) $r->id, 'meta_key' => (string) $r->meta_key, 'value_length' => (int) $r->len ]; }
                return [ 'success' => true, 'meta_keys' => $out, 'message' => sprintf( '%d meta key(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form-meta ----------------------- */

    private function register_get_form_meta() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-get-form-meta', [
            'label' => 'Get Fluent Form Meta', 'category' => 'atarim',
            'description' => 'Read and JSON-decode a single form_meta value by meta_key (e.g. notifications, formSettings).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'meta_key' => [ 'type' => 'string', 'minLength' => 1 ] ], 'required' => [ 'form_id', 'meta_key' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'value' => [], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $val = $self->get_meta( (int) $input['form_id'], (string) $input['meta_key'] );
                if ( $val === null ) { return [ 'success' => false, 'message' => 'Meta key not found on this form.' ]; }
                return [ 'success' => true, 'value' => $val, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- save-form-meta ----------------------- */

    private function register_save_form_meta() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-save-form-meta', [
            'label' => 'Save Fluent Form Meta', 'category' => 'atarim',
            'description' => 'Create or update a form_meta key with a JSON value (object/array). Powerful generic settings writer — use deliberately. Returns nothing beyond success.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'meta_key' => [ 'type' => 'string', 'minLength' => 1 ], 'value' => [] ], 'required' => [ 'form_id', 'meta_key', 'value' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->form_exists( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $r = $self->set_meta( (int) $input['form_id'], (string) $input['meta_key'], $input['value'] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Save failed.' ]; }
                return [ 'success' => true, 'message' => sprintf( 'Meta "%s" saved.', (string) $input['meta_key'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- list-notifications ---------------------- */

    private function register_list_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-list-notifications', [
            'label' => 'List Fluent Notifications', 'category' => 'atarim',
            'description' => 'List a form\'s email notifications in full: { id, name, sendTo, subject, message, enabled }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notifications' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $raw = AVCF_Fluent_Helpers::read_notifications_raw( (int) $input['form_id'] );
                $out = [];
                foreach ( (array) $raw as $nid => $n ) {
                    if ( ! is_array( $n ) ) { continue; }
                    $out[] = [ 'id' => (string) $nid, 'name' => isset( $n['name'] ) ? (string) $n['name'] : '', 'sendTo' => isset( $n['sendTo'] ) ? $n['sendTo'] : ( isset( $n['email'] ) ? [ 'email' => (string) $n['email'] ] : null ), 'subject' => isset( $n['subject'] ) ? (string) $n['subject'] : '', 'message' => isset( $n['message'] ) ? (string) $n['message'] : '', 'enabled' => ! isset( $n['enabled'] ) || ! empty( $n['enabled'] ) ];
                }
                return [ 'success' => true, 'notifications' => $out, 'message' => sprintf( '%d notification(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- save-notification ---------------------- */

    private function register_save_notification() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-save-notification', [
            'label' => 'Create/Update Fluent Notification', 'category' => 'atarim',
            'description' => 'Create or update an email notification. Omit notification_id to create (a numeric id is generated). Settable: name, email (recipient), subject, message, enabled. Returns the notification_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'notification_id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ], 'subject' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ], 'enabled' => [ 'type' => 'boolean' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notification_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->form_exists( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $notifs = AVCF_Fluent_Helpers::read_notifications_raw( (int) $input['form_id'] );
                if ( ! is_array( $notifs ) ) { $notifs = []; }
                $nid = isset( $input['notification_id'] ) && $input['notification_id'] !== '' ? (string) $input['notification_id'] : (string) ( count( $notifs ) + 1 );
                $n = isset( $notifs[ $nid ] ) && is_array( $notifs[ $nid ] ) ? $notifs[ $nid ] : [];
                if ( isset( $input['name'] ) ) { $n['name'] = sanitize_text_field( $input['name'] ); }
                if ( isset( $input['subject'] ) ) { $n['subject'] = (string) $input['subject']; }
                if ( isset( $input['message'] ) ) { $n['message'] = (string) $input['message']; }
                if ( isset( $input['enabled'] ) ) { $n['enabled'] = ! empty( $input['enabled'] ); }
                if ( isset( $input['email'] ) ) {
                    if ( isset( $n['sendTo'] ) && is_array( $n['sendTo'] ) ) { $n['sendTo']['email'] = (string) $input['email']; }
                    else { $n['sendTo'] = [ 'type' => 'email', 'email' => (string) $input['email'] ]; }
                }
                $notifs[ $nid ] = $n;
                $r = $self->set_meta( (int) $input['form_id'], 'notifications', $notifs );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Save failed.' ]; }
                return [ 'success' => true, 'notification_id' => $nid, 'message' => 'Notification saved.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- get-confirmation ---------------------- */

    private function register_get_confirmation() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-get-confirmation', [
            'label' => 'Get Fluent Confirmation', 'category' => 'atarim',
            'description' => 'Read a form\'s confirmation/redirect settings from the formSettings meta (messageToShow, redirectTo, customPage/customUrl when set).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'confirmation' => [], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $settings = $self->get_meta( (int) $input['form_id'], 'formSettings' );
                if ( ! is_array( $settings ) ) { return [ 'success' => false, 'message' => 'No formSettings meta on this form.' ]; }
                $conf = isset( $settings['confirmation'] ) ? $settings['confirmation'] : null;
                return [ 'success' => true, 'confirmation' => $conf, 'message' => $conf === null ? 'No confirmation block found in formSettings.' : 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------- list-integration-feeds -------------------- */

    private function register_list_integration_feeds() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-list-integration-feeds', [
            'label' => 'List Fluent Integration Feeds', 'category' => 'atarim',
            'description' => 'Best-effort listing of configured CRM/marketing/payment integration feeds on a form (e.g. mailchimp, slack, webhook), read from form_meta keys that look like feeds. Read-only; returns { meta_key, name, enabled } per feed where determinable.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'feeds' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb; $t = AVCF_Fluent_Helpers::meta_table();
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, value FROM `{$t}` WHERE form_id = %d", (int) $input['form_id'] ) );
                $skip = [ 'notifications', 'formSettings', '_total_views', 'template_name', 'advancedValidationSettings', '_primary_email_field' ];
                $feeds = [];
                foreach ( (array) $rows as $r ) {
                    $key = (string) $r->meta_key;
                    if ( in_array( $key, $skip, true ) ) { continue; }
                    $decoded = json_decode( $r->value, true );
                    $looks_feed = ( strpos( $key, 'feed' ) !== false ) || ( is_array( $decoded ) && ( isset( $decoded['integration_name'] ) || isset( $decoded['list_id'] ) || isset( $decoded['feedStatus'] ) || isset( $decoded['enabled'] ) ) );
                    if ( ! $looks_feed ) { continue; }
                    $name = is_array( $decoded ) && isset( $decoded['name'] ) ? (string) $decoded['name'] : $key;
                    $enabled = is_array( $decoded ) ? ( isset( $decoded['enabled'] ) ? (bool) $decoded['enabled'] : ( isset( $decoded['feedStatus'] ) ? (bool) $decoded['feedStatus'] : true ) ) : true;
                    $feeds[] = [ 'meta_key' => $key, 'name' => $name, 'enabled' => $enabled ];
                }
                return [ 'success' => true, 'feeds' => $feeds, 'message' => sprintf( '%d integration feed(s) detected (best-effort).', count( $feeds ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_settings(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
