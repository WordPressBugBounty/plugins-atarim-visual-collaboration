<?php
/**
 * Formidable Forms — core MCP abilities (standalone cluster).
 *
 * The common forms surface specialised to Formidable and namespaced
 * atarim/formidable-*. Forms/fields/notifications/entries go through
 * Formidable's PHP API; stats/spam use $wpdb on frm_items. Entries are stored
 * by Formidable core, so they are not Pro-gated. Formidable-specific power
 * (form CRUD, entry delete/status, notification action management, view-data
 * helpers) lives in the -pro file. Permissions use Formidable's capabilities
 * with a manage_options fallback. Built on Formidable's API; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Formidable extends AVCF_Abilities_Base {

    /** @var AVCF_Formidable_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Formidable_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_formidable_is_available() ) {
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

    public function can_view_forms() { return current_user_can( 'frm_edit_forms' ) || current_user_can( 'frm_view_forms' ) || current_user_can( 'manage_options' ); }
    public function can_view_entries() { return current_user_can( 'frm_view_entries' ) || current_user_can( 'frm_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_forms() { return current_user_can( 'frm_edit_forms' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'frm_edit_entries' ) || current_user_can( 'frm_delete_entries' ) || current_user_can( 'manage_options' ); }

    /** Resolve label map for a form (public so closures can call). */
    public function label_map_for( $form_id ) {
        if ( ! class_exists( 'FrmForm' ) ) { return []; }
        $form = FrmForm::getOne( (int) $form_id );
        if ( ! $form ) { return []; }
        return AVCF_Formidable_Helpers::build_label_map( AVCF_Formidable_Helpers::map_fields( (int) $form_id ) );
    }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-list-forms', [
            'label' => 'List Formidable Forms', 'category' => 'atarim',
            'description' => 'List Formidable forms: { id, title, status, entries_total, last_submission, created_at }. Optional search (name), limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $all = FrmForm::getAll( [ 'parent_form_id' => null, 'status' => 'published' ], 'name', '' );
                if ( ! is_array( $all ) ) { $all = []; }
                $search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
                if ( $search !== '' ) {
                    $needle = strtolower( $search );
                    $all = array_values( array_filter( $all, function( $f ) use ( $needle ) { return isset( $f->name ) && strpos( strtolower( (string) $f->name ), $needle ) !== false; } ) );
                }
                $total = count( $all );
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $page = array_slice( $all, $offset, $limit );
                $out = [];
                foreach ( $page as $f ) { $out[] = AVCF_Formidable_Helpers::map_form_summary( $f ); }
                return [ 'success' => true, 'forms' => $out, 'total' => $total, 'message' => sprintf( '%d form(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-get-form', [
            'label' => 'Get Formidable Form', 'category' => 'atarim',
            'description' => 'Full Formidable form definition: fields (id, key, type, label, required, options), email-action notifications, shortcode. include_raw:true adds the form options array.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_raw' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = FrmForm::getOne( (int) $input['form_id'] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'form' => AVCF_Formidable_Helpers::map_form_full( $form, ! empty( $input['include_raw'] ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-entries -------------------------- */

    private function register_list_entries() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-list-entries', [
            'label' => 'List Formidable Entries', 'category' => 'atarim',
            'description' => 'List entries for a form (newest first), normalized to { id, item_key, submitted_at, status, fields[] } with labels resolved. Filters: date_from, date_to, include_spam. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! class_exists( 'FrmEntry' ) ) { return [ 'success' => false, 'message' => 'FrmEntry API unavailable.' ]; }
                global $wpdb;
                $form_id = (int) $input['form_id'];
                $table = AVCF_Formidable_Helpers::items_table();
                $where = [ 'form_id = %d' ]; $params = [ $form_id ];
                if ( empty( $input['include_spam'] ) ) { $where[] = '(is_draft != 2 OR is_draft IS NULL)'; }
                if ( ! empty( $input['date_from'] ) ) { $where[] = 'created_at >= %s'; $params[] = (string) $input['date_from']; }
                if ( ! empty( $input['date_to'] ) )   { $where[] = 'created_at <= %s'; $params[] = (string) $input['date_to']; }
                $where_sql = implode( ' AND ', $where );
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $params ) );
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) ) );
                $label_map = $self->label_map_for( $form_id );
                $out = [];
                foreach ( (array) $ids as $eid ) {
                    $entry = FrmEntry::getOne( (int) $eid, true );
                    if ( $entry ) { $out[] = AVCF_Formidable_Helpers::normalize_entry( $entry, $form_id, $label_map, false ); }
                }
                return [ 'success' => true, 'entries' => $out, 'total' => $total, 'message' => sprintf( '%d entr(y/ies).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-entry ---------------------------- */

    private function register_get_entry() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-get-entry', [
            'label' => 'Get Formidable Entry', 'category' => 'atarim',
            'description' => 'Full normalized entry by id, with field values, labels resolved, and the raw entry included.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! class_exists( 'FrmEntry' ) ) { return [ 'success' => false, 'message' => 'FrmEntry API unavailable.' ]; }
                $entry = FrmEntry::getOne( (int) $input['entry_id'], true );
                if ( ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $form_id = isset( $entry->form_id ) ? (int) $entry->form_id : 0;
                return [ 'success' => true, 'entry' => AVCF_Formidable_Helpers::normalize_entry( $entry, $form_id, $self->label_map_for( $form_id ), true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-form-stats ------------------------- */

    private function register_get_form_stats() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-get-form-stats', [
            'label' => 'Get Formidable Form Stats', 'category' => 'atarim',
            'description' => 'Submission stats (non-spam): total, first/last submission, per-day counts. Optional date window.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                return [ 'success' => true, 'stats' => AVCF_Formidable_Helpers::stats( (int) $input['form_id'], $input ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-form-spam-stats ---------------------- */

    private function register_get_form_spam_stats() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-get-form-spam-stats', [
            'label' => 'Get Formidable Form Spam Stats', 'category' => 'atarim',
            'description' => 'Spam vs non-spam entry counts and spam rate (is_draft=2 is spam).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'spam' => [ 'type' => 'integer' ], 'active' => [ 'type' => 'integer' ], 'spam_rate' => [ 'type' => 'number' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $all = AVCF_Formidable_Helpers::count_entries( $form_id, true );
                $active = AVCF_Formidable_Helpers::count_entries( $form_id, false );
                $spam = max( 0, $all - $active );
                return [ 'success' => true, 'spam' => $spam, 'active' => $active, 'spam_rate' => $all > 0 ? round( $spam / $all, 4 ) : 0, 'message' => sprintf( '%d spam / %d active.', $spam, $active ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- find-silent-forms ----------------------- */

    private function register_find_silent_forms() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-find-silent-forms', [
            'label' => 'Find Silent Formidable Forms', 'category' => 'atarim',
            'description' => 'Forms with no submission in the last N days (default 30). Returns each with last_submission and days_silent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_forms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $all = FrmForm::getAll( [ 'parent_form_id' => null, 'status' => 'published' ], 'name', '' );
                if ( ! is_array( $all ) ) { $all = []; }
                $silent = [];
                foreach ( $all as $f ) {
                    $fid = isset( $f->id ) ? (int) $f->id : 0;
                    if ( $fid <= 0 ) { continue; }
                    $last = AVCF_Formidable_Helpers::last_submission_at( $fid );
                    $last_ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $last_ts === false ) { $last_ts = 0; }
                    if ( $last_ts < $cutoff ) {
                        $silent[] = [ 'id' => $fid, 'title' => isset( $f->name ) ? (string) $f->name : '', 'last_submission' => $last, 'days_silent' => $last_ts > 0 ? (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS ) : null ];
                    }
                }
                return [ 'success' => true, 'silent_forms' => $silent, 'message' => sprintf( '%d silent form(s) over %d days.', count( $silent ), $days ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- export-entries ------------------------- */

    private function register_export_entries() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-export-entries', [
            'label' => 'Export Formidable Entries', 'category' => 'atarim',
            'description' => 'Export a form\'s entries as CSV text. Capped via limit (default 1000). Excludes spam unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! class_exists( 'FrmEntry' ) ) { return [ 'success' => false, 'message' => 'FrmEntry API unavailable.' ]; }
                global $wpdb;
                $form_id = (int) $input['form_id'];
                $table = AVCF_Formidable_Helpers::items_table();
                $where = [ 'form_id = %d' ]; $params = [ $form_id ];
                if ( empty( $input['include_spam'] ) ) { $where[] = '(is_draft != 2 OR is_draft IS NULL)'; }
                $where_sql = implode( ' AND ', $where );
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000;
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d", array_merge( $params, [ $limit ] ) ) );
                $label_map = $self->label_map_for( $form_id );
                $header = [ 'Entry ID', 'Submitted' ]; $header_set = false; $body = [];
                foreach ( (array) $ids as $eid ) {
                    $entry = FrmEntry::getOne( (int) $eid, true );
                    if ( ! $entry ) { continue; }
                    $n = AVCF_Formidable_Helpers::normalize_entry( $entry, $form_id, $label_map, false );
                    $line = [ (string) $n['id'], (string) $n['submitted_at'] ];
                    foreach ( $n['fields'] as $fld ) {
                        if ( ! $header_set ) { $header[] = $fld['label'] !== '' ? $fld['label'] : $fld['id']; }
                        $line[] = is_array( $fld['value'] ) ? implode( ' | ', array_map( 'strval', $fld['value'] ) ) : (string) $fld['value'];
                    }
                    $header_set = true; $body[] = $line;
                }
                $lines = [ self::csv_row( $header ) ];
                foreach ( $body as $b ) { $lines[] = self::csv_row( $b ); }
                return [ 'success' => true, 'csv' => implode( "\n", $lines ), 'rows' => count( $body ), 'message' => sprintf( '%d row(s) exported.', count( $body ) ) ];
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
        wp_register_ability( 'atarim/formidable-mark-entry-spam', [
            'label' => 'Mark Formidable Entry Spam', 'category' => 'atarim',
            'description' => 'Flag an entry as spam (is_draft=2) or clear it (is_draft=0).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                $r = $wpdb->update( AVCF_Formidable_Helpers::items_table(), [ 'is_draft' => $is_spam ? 2 : 0 ], [ 'id' => (int) $input['entry_id'] ], [ '%d' ], [ '%d' ] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => $is_spam ? 'Entry marked as spam.' : 'Entry unmarked from spam.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ update-notifications ---------------------- */

    private function register_update_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/formidable-update-notifications', [
            'label' => 'Update Formidable Notification Recipients', 'category' => 'atarim',
            'description' => 'Update recipient email(s) of one or more of a form\'s email-action notifications. updates: [{ notification_id (action ID), recipients: [emails] }]. Emails sanitized; invalid sets skipped and reported.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'FrmFormAction' ) ) { return [ 'success' => false, 'message' => 'FrmFormAction class not available.' ]; }
                $actions = FrmFormAction::get_action_for_form( (int) $input['form_id'], 'email' );
                if ( ! is_array( $actions ) ) { $actions = []; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    $matched = null;
                    foreach ( $actions as $a ) { $aid = isset( $a->ID ) ? (string) $a->ID : ( isset( $a->id ) ? (string) $a->id : '' ); if ( $aid === $nid ) { $matched = $a; break; } }
                    if ( ! $matched ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Action id not found on this form.' ]; continue; }
                    $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                    if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; continue; }
                    $settings = isset( $matched->post_content ) && is_array( $matched->post_content ) ? $matched->post_content : [];
                    $settings['email_to'] = implode( ',', $clean );
                    wp_update_post( [ 'ID' => (int) ( isset( $matched->ID ) ? $matched->ID : $matched->id ), 'post_content' => wp_slash( wp_json_encode( $settings ) ) ] );
                    $updated[] = $nid;
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
        wp_register_ability( 'atarim/formidable-duplicate-form', [
            'label' => 'Duplicate Formidable Form', 'category' => 'atarim',
            'description' => 'Clone a form via FrmForm::duplicate. Optional new_title.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! method_exists( 'FrmForm', 'duplicate' ) ) { return [ 'success' => false, 'message' => 'FrmForm::duplicate not available.' ]; }
                $new_id = FrmForm::duplicate( (int) $input['form_id'], false, true );
                if ( ! $new_id ) { return [ 'success' => false, 'message' => 'Duplicate failed.' ]; }
                if ( isset( $input['new_title'] ) && $input['new_title'] !== '' && method_exists( 'FrmForm', 'update' ) ) {
                    FrmForm::update( (int) $new_id, [ 'name' => sanitize_text_field( $input['new_title'] ) ] );
                }
                return [ 'success' => true, 'new_form_id' => (int) $new_id, 'message' => sprintf( 'Duplicated as form %d.', (int) $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
