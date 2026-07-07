<?php
/**
 * Gravity Forms — core MCP abilities (standalone cluster).
 *
 * The common forms surface (list/get forms, list/get entries, stats, spam,
 * silent forms, export, mark-spam, notifications, duplicate), specialised to
 * Gravity and namespaced atarim/gravity-*. GF-specific power abilities (form &
 * entry CRUD, notes, notifications/confirmations/feeds detail) live in the -pro
 * file. Permissions use Gravity's own capabilities with a manage_options
 * fallback. Built on GFAPI; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Gravity extends AVCF_Abilities_Base {

    /** @var AVCF_Gravity_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Gravity_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_gravity_is_available() ) {
            return;
        }
        $this->register_list_forms();
        $this->register_get_form();
        $this->register_list_entries();
        $this->register_get_entry();
        $this->register_get_form_stats();
        $this->register_get_form_spam_stats();
        $this->register_find_silent_forms();
        $this->register_export_entries();
        $this->register_mark_entry_spam();
        $this->register_update_notifications();
        $this->register_duplicate_form();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }

    public function can_view_forms() { return current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'gravityforms_view_entries' ) || current_user_can( 'manage_options' ); }
    public function can_view_entries() { return current_user_can( 'gravityforms_view_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'gravityforms_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_forms() { return current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'manage_options' ); }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-list-forms', [
            'label' => 'List Gravity Forms', 'category' => 'atarim',
            'description' => 'List Gravity Forms: { id, title, status, entries_total, last_submission, created_at }. Optional search (title), limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $all = GFAPI::get_forms( true, false );
                if ( ! is_array( $all ) ) { $all = []; }
                $search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
                if ( $search !== '' ) {
                    $needle = strtolower( $search );
                    $all = array_values( array_filter( $all, function( $f ) use ( $needle ) { return isset( $f['title'] ) && strpos( strtolower( (string) $f['title'] ), $needle ) !== false; } ) );
                }
                $total = count( $all );
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $out = [];
                foreach ( array_slice( $all, $offset, $limit ) as $f ) { $out[] = AVCF_Gravity_Helpers::map_form_summary( $f ); }
                return [ 'success' => true, 'forms' => $out, 'total' => $total, 'message' => sprintf( '%d form(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-get-form', [
            'label' => 'Get Gravity Form', 'category' => 'atarim',
            'description' => 'Full Gravity form definition: fields (id, type, label, required, options, has_conditional), notifications, confirmations, shortcode. include_raw:true adds the raw GF form array.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_raw' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'form' => AVCF_Gravity_Helpers::map_form_full( $form, ! empty( $input['include_raw'] ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-entries -------------------------- */

    private function register_list_entries() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-list-entries', [
            'label' => 'List Gravity Entries', 'category' => 'atarim',
            'description' => 'List entries for a form (newest first), normalized to { id, submitted_at, status, payment_status, fields[] }. Filters: date_from, date_to (Y-m-d), include_spam. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $search = empty( $input['include_spam'] ) ? [ 'status' => 'active' ] : [];
                if ( ! empty( $input['date_from'] ) ) { $search['start_date'] = (string) $input['date_from']; }
                if ( ! empty( $input['date_to'] ) ) { $search['end_date'] = (string) $input['date_to']; }
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $raw = GFAPI::get_entries( $form_id, $search, [ 'key' => 'date_created', 'direction' => 'DESC' ], [ 'offset' => $offset, 'page_size' => $limit ] );
                if ( is_wp_error( $raw ) ) { return [ 'success' => false, 'message' => $raw->get_error_message() ]; }
                $total = GFAPI::count_entries( $form_id, $search );
                $form = GFAPI::get_form( $form_id );
                $out = [];
                foreach ( (array) $raw as $r ) { $out[] = AVCF_Gravity_Helpers::normalize_entry( $r, $form_id, $form, false ); }
                return [ 'success' => true, 'entries' => $out, 'total' => (int) $total, 'message' => sprintf( '%d entr(y/ies).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-entry ---------------------------- */

    private function register_get_entry() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-get-entry', [
            'label' => 'Get Gravity Entry', 'category' => 'atarim',
            'description' => 'Full normalized entry by id, including all field values and the raw GF entry array.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $entry = GFAPI::get_entry( (int) $input['entry_id'] );
                if ( is_wp_error( $entry ) || ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $form_id = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
                $form = $form_id > 0 ? GFAPI::get_form( $form_id ) : null;
                return [ 'success' => true, 'entry' => AVCF_Gravity_Helpers::normalize_entry( $entry, $form_id, $form, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-form-stats ------------------------- */

    private function register_get_form_stats() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-get-form-stats', [
            'label' => 'Get Gravity Form Stats', 'category' => 'atarim',
            'description' => 'Submission stats for a form: total, first/last submission dates, and per-day counts. Optional date_from/date_to window.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! GFAPI::get_form( (int) $input['form_id'] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'stats' => AVCF_Gravity_Helpers::stats( (int) $input['form_id'], $input ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-form-spam-stats ---------------------- */

    private function register_get_form_spam_stats() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-get-form-spam-stats', [
            'label' => 'Get Gravity Form Spam Stats', 'category' => 'atarim',
            'description' => 'Spam vs active entry counts for a form, plus the spam rate.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'spam' => [ 'type' => 'integer' ], 'active' => [ 'type' => 'integer' ], 'spam_rate' => [ 'type' => 'number' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                if ( ! GFAPI::get_form( $form_id ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $spam = (int) GFAPI::count_entries( $form_id, [ 'status' => 'spam' ] );
                $active = (int) GFAPI::count_entries( $form_id, [ 'status' => 'active' ] );
                $denom = $spam + $active;
                return [ 'success' => true, 'spam' => $spam, 'active' => $active, 'spam_rate' => $denom > 0 ? round( $spam / $denom, 4 ) : 0, 'message' => sprintf( '%d spam / %d active.', $spam, $active ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- find-silent-forms ----------------------- */

    private function register_find_silent_forms() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-find-silent-forms', [
            'label' => 'Find Silent Gravity Forms', 'category' => 'atarim',
            'description' => 'Forms with no submission in the last N days (default 30) — useful for spotting broken or abandoned forms. Returns each form with its last_submission and days_silent (null last_submission = never received an entry).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_forms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $all = GFAPI::get_forms( true, false );
                if ( ! is_array( $all ) ) { $all = []; }
                $silent = [];
                foreach ( $all as $f ) {
                    $fid = isset( $f['id'] ) ? (int) $f['id'] : 0;
                    if ( $fid <= 0 ) { continue; }
                    $last = AVCF_Gravity_Helpers::last_submission_at( $fid );
                    $last_ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $last_ts === false ) { $last_ts = 0; }
                    if ( $last_ts < $cutoff ) {
                        $silent[] = [ 'id' => $fid, 'title' => isset( $f['title'] ) ? (string) $f['title'] : '', 'last_submission' => $last, 'days_silent' => $last_ts > 0 ? (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS ) : null ];
                    }
                }
                return [ 'success' => true, 'silent_forms' => $silent, 'message' => sprintf( '%d silent form(s) over %d days.', count( $silent ), $days ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- export-entries ------------------------- */

    private function register_export_entries() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-export-entries', [
            'label' => 'Export Gravity Entries', 'category' => 'atarim',
            'description' => 'Export a form\'s entries as CSV text (header row from field labels). Capped via limit (default 1000). Excludes spam/trash unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $form = GFAPI::get_form( $form_id );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $search = empty( $input['include_spam'] ) ? [ 'status' => 'active' ] : [];
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000;
                $raw = GFAPI::get_entries( $form_id, $search, [ 'key' => 'date_created', 'direction' => 'DESC' ], [ 'offset' => 0, 'page_size' => $limit ] );
                if ( is_wp_error( $raw ) ) { return [ 'success' => false, 'message' => $raw->get_error_message() ]; }
                $labels = [ 'Entry ID', 'Submitted' ];
                $field_ids = [];
                if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
                    foreach ( $form['fields'] as $f ) {
                        $fid = is_object( $f ) ? (string) $f->id : ( isset( $f['id'] ) ? (string) $f['id'] : '' );
                        $lbl = is_object( $f ) ? (string) $f->label : ( isset( $f['label'] ) ? (string) $f['label'] : '' );
                        if ( $fid === '' ) { continue; }
                        $field_ids[] = $fid; $labels[] = $lbl !== '' ? $lbl : ( 'Field ' . $fid );
                    }
                }
                $lines = [ self::csv_row( $labels ) ];
                foreach ( (array) $raw as $e ) {
                    $row = [ isset( $e['id'] ) ? (string) $e['id'] : '', isset( $e['date_created'] ) ? (string) $e['date_created'] : '' ];
                    foreach ( $field_ids as $fid ) { $row[] = isset( $e[ $fid ] ) ? (string) $e[ $fid ] : ''; }
                    $lines[] = self::csv_row( $row );
                }
                return [ 'success' => true, 'csv' => implode( "\n", $lines ), 'rows' => count( $lines ) - 1, 'message' => sprintf( '%d row(s) exported.', count( $lines ) - 1 ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    private static function csv_row( $cells ) {
        $out = [];
        foreach ( (array) $cells as $c ) { $out[] = '"' . str_replace( '"', '""', (string) $c ) . '"'; }
        return implode( ',', $out );
    }

    /* -------------------------- mark-entry-spam ------------------------ */

    private function register_mark_entry_spam() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-mark-entry-spam', [
            'label' => 'Mark Gravity Entry Spam', 'category' => 'atarim',
            'description' => 'Flag an entry as spam (or clear it with is_spam:false).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $entry = GFAPI::get_entry( (int) $input['entry_id'] );
                if ( is_wp_error( $entry ) || ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                $entry['status'] = $is_spam ? 'spam' : 'active';
                $r = GFAPI::update_entry( $entry );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => 'Update failed: ' . $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => $is_spam ? 'Entry marked as spam.' : 'Entry unmarked from spam.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ update-notifications ---------------------- */

    private function register_update_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-update-notifications', [
            'label' => 'Update Gravity Notification Recipients', 'category' => 'atarim',
            'description' => 'Update the recipient email(s) of one or more of a form\'s notifications. updates: [{ notification_id, recipients: [emails] }]. Emails are sanitized; invalid/empty sets are skipped and reported.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    if ( ! isset( $form['notifications'][ $nid ] ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Not found on this form.' ]; continue; }
                    $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                    if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; continue; }
                    $form['notifications'][ $nid ]['to'] = implode( ',', $clean );
                    $form['notifications'][ $nid ]['toType'] = 'email';
                    $updated[] = $nid;
                }
                if ( ! empty( $updated ) ) {
                    $r = GFAPI::update_form( $form );
                    if ( is_wp_error( $r ) ) { return [ 'success' => false, 'updated' => [], 'skipped' => $skipped, 'message' => 'Update failed: ' . $r->get_error_message() ]; }
                }
                return [ 'success' => ! empty( $updated ), 'updated' => $updated, 'skipped' => $skipped, 'message' => sprintf( '%d updated, %d skipped.', count( $updated ), count( $skipped ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- duplicate-form ------------------------- */

    private function register_duplicate_form() {
        $self = $this;
        wp_register_ability( 'atarim/gravity-duplicate-form', [
            'label' => 'Duplicate Gravity Form', 'category' => 'atarim',
            'description' => 'Clone a form (fields, notifications, confirmations, settings) into a new form. Optional new_title (defaults to "<title> (Copy)").',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $original = GFAPI::get_form( (int) $input['form_id'] );
                if ( ! $original ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $new = $original; unset( $new['id'] );
                $new['title'] = ( isset( $input['new_title'] ) && $input['new_title'] !== '' ) ? sanitize_text_field( $input['new_title'] ) : ( isset( $original['title'] ) ? $original['title'] . ' (Copy)' : 'Form Copy' );
                $new_id = GFAPI::add_form( $new );
                if ( is_wp_error( $new_id ) ) { return [ 'success' => false, 'message' => 'Duplicate failed: ' . $new_id->get_error_message() ]; }
                return [ 'success' => true, 'new_form_id' => (int) $new_id, 'message' => sprintf( 'Duplicated as form %d.', (int) $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
