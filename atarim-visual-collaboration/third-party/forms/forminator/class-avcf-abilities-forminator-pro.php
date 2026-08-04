<?php
/**
 * Forminator — power MCP abilities (standalone cluster, -pro file).
 *
 * Forminator-specific depth via Forminator_API and the form model: form CRUD,
 * entry create/delete/bulk-delete, notification management (saved through the
 * model), and read access to the sibling Poll and Quiz modules. create-form and
 * create-entry are experimental (Forminator's field/data shapes are intricate);
 * deletes are confirm-gated; poll/quiz abilities degrade gracefully when those
 * API methods are absent. Permission baseline is manage_options (Forminator's
 * default admin gate). Built on Forminator's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Forminator_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Forminator_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Forminator_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_forminator_is_available() ) {
            return;
        }
        $this->register_create_form();
        $this->register_update_form();
        $this->register_delete_form();
        $this->register_create_entry();
        $this->register_delete_entry();
        $this->register_bulk_delete_entries();
        $this->register_list_notifications();
        $this->register_save_notification();
        $this->register_list_polls();
        $this->register_get_poll();
        $this->register_list_poll_entries();
        $this->register_list_quizzes();
        $this->register_get_quiz();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function can_manage() { return current_user_can( 'manage_options' ); }

    /* ----------------------------- create-form ------------------------- */

    private function register_create_form() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-create-form', [
            'label' => 'Create Forminator Form', 'category' => 'atarim',
            'description' => 'Create a new Forminator form via Forminator_API::add_form. EXPERIMENTAL. Provide name; fields/settings are optional raw structures. Returns the new form_id. Refine in the Forminator editor afterwards.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => [ 'type' => 'string', 'minLength' => 1 ], 'fields' => [ 'type' => 'array' ], 'settings' => [ 'type' => 'object' ] ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'add_form' ) ) { return [ 'success' => false, 'message' => 'Forminator_API::add_form unavailable.' ]; }
                $name = sanitize_text_field( $input['name'] );
                if ( $name === '' ) { return [ 'success' => false, 'message' => 'A name is required.' ]; }
                $fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : [];
                $settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
                $settings['formName'] = $name;
                $new_id = Forminator_API::add_form( $name, 'custom-forms', $fields, $settings );
                if ( is_wp_error( $new_id ) || ! $new_id ) { return [ 'success' => false, 'message' => is_wp_error( $new_id ) ? $new_id->get_error_message() : 'Create failed.' ]; }
                return [ 'success' => true, 'form_id' => (int) $new_id, 'message' => sprintf( 'Form %d created (experimental — verify in the Forminator editor).', (int) $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- update-form ------------------------- */

    private function register_update_form() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-update-form', [
            'label' => 'Update Forminator Form', 'category' => 'atarim',
            'description' => 'Update a form by mutating its model and saving: name (settings.formName), a settings patch (merged), and/or a full fields replacement. Only provided keys change.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'name' => [ 'type' => 'string' ], 'settings' => [ 'type' => 'object' ], 'fields' => [ 'type' => 'array' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $form = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $form || is_wp_error( $form ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! is_object( $form ) || ! property_exists( $form, 'settings' ) || ! method_exists( $form, 'save' ) ) { return [ 'success' => false, 'message' => 'Form model is not editable in this version.' ]; }
                $settings = is_array( $form->settings ) ? $form->settings : [];
                if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) { $settings = array_merge( $settings, $input['settings'] ); }
                if ( isset( $input['name'] ) ) { $settings['formName'] = sanitize_text_field( $input['name'] ); }
                $form->settings = $settings;
                if ( isset( $input['fields'] ) && is_array( $input['fields'] ) && property_exists( $form, 'fields' ) ) { $form->fields = $input['fields']; }
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
        wp_register_ability( 'atarim/forminator-delete-form', [
            'label' => 'Delete Forminator Form', 'category' => 'atarim',
            'description' => 'Permanently delete a form and its entries via Forminator_API::delete_form. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'delete_form' ) ) { return [ 'success' => false, 'message' => 'Forminator_API::delete_form unavailable.' ]; }
                $form = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $form || is_wp_error( $form ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $count = AVCF_Forminator_Helpers::count_entries( (int) $input['form_id'], true );
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to delete this form and %d entr(y/ies).', $count ) ]; }
                $r = Forminator_API::delete_form( (int) $input['form_id'] );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => sprintf( 'Form %d deleted.', (int) $input['form_id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- create-entry ------------------------ */

    private function register_create_entry() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-create-entry', [
            'label' => 'Create Forminator Entry', 'category' => 'atarim',
            'description' => 'Create an entry via Forminator_API::add_form_entry. EXPERIMENTAL. values is a map of field_slug => value. Returns the new entry_id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'values' => [ 'type' => 'object' ] ], 'required' => [ 'form_id', 'values' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'add_form_entry' ) ) { return [ 'success' => false, 'message' => 'Forminator_API::add_form_entry unavailable.' ]; }
                $entry_id = Forminator_API::add_form_entry( (int) $input['form_id'], (array) $input['values'] );
                if ( is_wp_error( $entry_id ) || ! $entry_id ) { return [ 'success' => false, 'message' => is_wp_error( $entry_id ) ? $entry_id->get_error_message() : 'Create failed (check field slugs/values).' ]; }
                return [ 'success' => true, 'entry_id' => (int) $entry_id, 'message' => sprintf( 'Entry %d created.', (int) $entry_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ----------------------------- delete-entry ------------------------ */

    private function register_delete_entry() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-delete-entry', [
            'label' => 'Delete Forminator Entry', 'category' => 'atarim',
            'description' => 'Permanently delete a single entry via Forminator_API::delete_form_entry. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id', 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'delete_form_entry' ) ) { return [ 'success' => false, 'message' => 'Forminator_API::delete_form_entry unavailable.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this entry.' ]; }
                $r = Forminator_API::delete_form_entry( (int) $input['form_id'], (int) $input['entry_id'] );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => sprintf( 'Entry %d deleted.', (int) $input['entry_id'] ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* -------------------------- bulk-delete-entries -------------------- */

    private function register_bulk_delete_entries() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-bulk-delete-entries', [
            'label' => 'Bulk Delete Forminator Entries', 'category' => 'atarim',
            'description' => 'Permanently delete multiple entries via Forminator_API::delete_form_entries. Provide entry_ids[]. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'entry_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'minItems' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id', 'entry_ids' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'delete_form_entries' ) ) { return [ 'success' => false, 'message' => 'Forminator_API::delete_form_entries unavailable.' ]; }
                $ids = array_values( array_filter( array_map( 'intval', (array) $input['entry_ids'] ) ) );
                if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'No valid entry ids.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to permanently delete %d entr(y/ies).', count( $ids ) ) ]; }
                $r = Forminator_API::delete_form_entries( (int) $input['form_id'], $ids );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => sprintf( '%d entr(y/ies) deleted.', count( $ids ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------- list-notifications ---------------------- */

    private function register_list_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-list-notifications', [
            'label' => 'List Forminator Notifications', 'category' => 'atarim',
            'description' => 'List a form\'s email notifications in full: { id, label, recipients, subject }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notifications' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $form || is_wp_error( $form ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'notifications' => AVCF_Forminator_Helpers::map_notifications( AVCF_Forminator_Helpers::settings_array( $form ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- save-notification ---------------------- */

    private function register_save_notification() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-save-notification', [
            'label' => 'Create/Update Forminator Notification', 'category' => 'atarim',
            'description' => 'Create or update an email notification (saved via the form model). Omit notification_id to append a new one. Settable: label, recipients (email), subject, message. Returns the notification_id. Best-effort.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'notification_id' => [ 'type' => 'string' ], 'label' => [ 'type' => 'string' ], 'recipients' => [ 'type' => 'string' ], 'subject' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'notification_id' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $form || is_wp_error( $form ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! is_object( $form ) || ! property_exists( $form, 'settings' ) || ! method_exists( $form, 'save' ) ) { return [ 'success' => false, 'message' => 'Form model is not editable in this version.' ]; }
                $settings = is_array( $form->settings ) ? $form->settings : [];
                $key = isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ? 'notifications' : 'notifications';
                if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) { $settings[ $key ] = []; }
                $nid = isset( $input['notification_id'] ) && $input['notification_id'] !== '' ? (string) $input['notification_id'] : '';
                $target_idx = null;
                if ( $nid !== '' ) {
                    foreach ( $settings[ $key ] as $idx => $n ) { $cid = isset( $n['slug'] ) ? (string) $n['slug'] : (string) $idx; if ( $cid === $nid ) { $target_idx = $idx; break; } }
                }
                if ( $target_idx === null ) {
                    $nid = $nid !== '' ? $nid : ( 'notification-' . ( count( $settings[ $key ] ) + 1 ) );
                    $settings[ $key ][] = [ 'slug' => $nid ];
                    $target_idx = count( $settings[ $key ] ) - 1;
                }
                if ( isset( $input['label'] ) ) { $settings[ $key ][ $target_idx ]['label'] = sanitize_text_field( $input['label'] ); }
                if ( isset( $input['recipients'] ) ) { $settings[ $key ][ $target_idx ]['recipients'] = (string) $input['recipients']; }
                if ( isset( $input['subject'] ) ) { $settings[ $key ][ $target_idx ]['email-subject'] = (string) $input['subject']; }
                if ( isset( $input['message'] ) ) { $settings[ $key ][ $target_idx ]['email-editor'] = (string) $input['message']; }
                $form->settings = $settings;
                $form->save();
                return [ 'success' => true, 'notification_id' => (string) $nid, 'message' => 'Notification saved (verify in the Forminator editor).' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------------- polls ----------------------------- */

    private function register_list_polls() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-list-polls', [
            'label' => 'List Forminator Polls', 'category' => 'atarim',
            'description' => 'List Forminator polls: { id, title, total_votes }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'polls' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'get_polls' ) ) { return [ 'success' => false, 'message' => 'Forminator polls API unavailable.' ]; }
                $all = Forminator_API::get_polls( null, 1, -1 );
                if ( ! is_array( $all ) ) { $all = []; }
                $out = [];
                foreach ( $all as $p ) {
                    $pid = AVCF_Forminator_Helpers::extract_id( $p );
                    if ( $pid <= 0 ) { continue; }
                    $out[] = [ 'id' => $pid, 'title' => AVCF_Forminator_Helpers::extract_title( $p ), 'total_votes' => AVCF_Forminator_Helpers::count_entries( $pid, true ) ];
                }
                return [ 'success' => true, 'polls' => $out, 'message' => sprintf( '%d poll(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_get_poll() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-get-poll', [
            'label' => 'Get Forminator Poll', 'category' => 'atarim',
            'description' => 'A poll\'s answer options plus total votes and a best-effort per-answer breakdown.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'poll_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'poll_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'poll' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'get_poll' ) ) { return [ 'success' => false, 'message' => 'Forminator polls API unavailable.' ]; }
                $poll = Forminator_API::get_poll( (int) $input['poll_id'] );
                if ( ! $poll || is_wp_error( $poll ) ) { return [ 'success' => false, 'message' => 'Poll not found.' ]; }
                $answers = [];
                $fields = is_object( $poll ) && property_exists( $poll, 'fields' ) ? $poll->fields : [];
                foreach ( (array) $fields as $f ) {
                    $fs = ( is_object( $f ) && property_exists( $f, 'raw' ) && is_array( $f->raw ) ) ? $f->raw : ( is_array( $f ) ? $f : [] );
                    $answers[] = [ 'slug' => is_object( $f ) && property_exists( $f, 'slug' ) ? (string) $f->slug : '', 'title' => isset( $fs['title'] ) ? (string) $fs['title'] : ( isset( $fs['field_label'] ) ? (string) $fs['field_label'] : '' ) ];
                }
                $breakdown = [];
                global $wpdb;
                $emeta = $wpdb->prefix . 'frmt_form_entry_meta';
                $etab  = AVCF_Forminator_Helpers::entry_table();
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS val, COUNT(*) AS cnt FROM `{$emeta}` m INNER JOIN `{$etab}` e ON e.entry_id = m.entry_id WHERE e.form_id = %d GROUP BY m.meta_value", (int) $input['poll_id'] ), ARRAY_A );
                foreach ( (array) $rows as $r ) { $breakdown[] = [ 'answer' => (string) $r['val'], 'votes' => (int) $r['cnt'] ]; }
                return [ 'success' => true, 'poll' => [ 'id' => (int) $input['poll_id'], 'title' => AVCF_Forminator_Helpers::extract_title( $poll ), 'answers' => $answers, 'total_votes' => AVCF_Forminator_Helpers::count_entries( (int) $input['poll_id'], true ), 'breakdown' => $breakdown ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_list_poll_entries() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-list-poll-entries', [
            'label' => 'List Forminator Poll Votes', 'category' => 'atarim',
            'description' => 'List individual votes for a poll (newest first), normalized like form entries. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'poll_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'poll_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'votes' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'get_entries' ) ) { return [ 'success' => false, 'message' => 'Forminator entries API unavailable.' ]; }
                $poll_id = (int) $input['poll_id'];
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $raw = Forminator_API::get_entries( $poll_id, [ 'limit' => $limit, 'offset' => $offset ] );
                if ( is_wp_error( $raw ) ) { return [ 'success' => false, 'message' => $raw->get_error_message() ]; }
                $out = [];
                foreach ( (array) $raw as $e ) { $out[] = AVCF_Forminator_Helpers::normalize_entry( $e, $poll_id, [], false ); }
                return [ 'success' => true, 'votes' => $out, 'total' => AVCF_Forminator_Helpers::count_entries( $poll_id, true ), 'message' => sprintf( '%d vote(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------------ quizzes ---------------------------- */

    private function register_list_quizzes() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-list-quizzes', [
            'label' => 'List Forminator Quizzes', 'category' => 'atarim',
            'description' => 'List Forminator quizzes: { id, title, total_submissions }.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'quizzes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'get_quizzes' ) ) { return [ 'success' => false, 'message' => 'Forminator quizzes API unavailable.' ]; }
                $all = Forminator_API::get_quizzes( null, 1, -1 );
                if ( ! is_array( $all ) ) { $all = []; }
                $out = [];
                foreach ( $all as $q ) {
                    $qid = AVCF_Forminator_Helpers::extract_id( $q );
                    if ( $qid <= 0 ) { continue; }
                    $out[] = [ 'id' => $qid, 'title' => AVCF_Forminator_Helpers::extract_title( $q ), 'total_submissions' => AVCF_Forminator_Helpers::count_entries( $qid, true ) ];
                }
                return [ 'success' => true, 'quizzes' => $out, 'message' => sprintf( '%d quiz(zes).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private function register_get_quiz() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-get-quiz', [
            'label' => 'Get Forminator Quiz', 'category' => 'atarim',
            'description' => 'A quiz\'s questions and answer options (best-effort from the quiz model fields), plus total submissions.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'quiz_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'quiz_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'quiz' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'Forminator_API', 'get_quiz' ) ) { return [ 'success' => false, 'message' => 'Forminator quizzes API unavailable.' ]; }
                $quiz = Forminator_API::get_quiz( (int) $input['quiz_id'] );
                if ( ! $quiz || is_wp_error( $quiz ) ) { return [ 'success' => false, 'message' => 'Quiz not found.' ]; }
                $questions = [];
                $fields = is_object( $quiz ) && property_exists( $quiz, 'questions' ) ? $quiz->questions : ( is_object( $quiz ) && property_exists( $quiz, 'fields' ) ? $quiz->fields : [] );
                foreach ( (array) $fields as $f ) {
                    $arr = is_array( $f ) ? $f : ( is_object( $f ) && property_exists( $f, 'raw' ) && is_array( $f->raw ) ? $f->raw : [] );
                    $answers = [];
                    if ( isset( $arr['answers'] ) && is_array( $arr['answers'] ) ) {
                        foreach ( $arr['answers'] as $a ) { $answers[] = is_array( $a ) && isset( $a['title'] ) ? (string) $a['title'] : ( is_string( $a ) ? $a : '' ); }
                    }
                    $questions[] = [ 'title' => isset( $arr['title'] ) ? (string) $arr['title'] : '', 'answers' => $answers ];
                }
                return [ 'success' => true, 'quiz' => [ 'id' => (int) $input['quiz_id'], 'title' => AVCF_Forminator_Helpers::extract_title( $quiz ), 'questions' => $questions, 'total_submissions' => AVCF_Forminator_Helpers::count_entries( (int) $input['quiz_id'], true ) ], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
