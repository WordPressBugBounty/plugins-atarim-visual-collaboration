<?php
/**
 * Forminator — core MCP abilities (standalone cluster).
 *
 * The common forms surface specialised to Forminator and namespaced
 * atarim/forminator-*. Forms/entries go through Forminator_API; stats/spam use
 * $wpdb on frmt_form_entry. Forminator-specific power (form create/delete,
 * entry delete, notification management, polls/quizzes listing) lives in the
 * -pro file. Forminator gates admin on manage_options by default, so that is
 * the permission baseline. Built on Forminator's API; not runtime-tested.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Forminator extends AVCF_Abilities_Base {

    /** @var AVCF_Forminator_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Forminator_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_forminator_is_available() ) {
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

    public function can_manage() { return current_user_can( 'manage_options' ); }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-list-forms', [
            'label' => 'List Forminator Forms', 'category' => 'atarim',
            'description' => 'List Forminator forms: { id, title, status, entries_total, last_submission, created_at }. Optional search (name), limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $all = Forminator_API::get_forms( null, 1, -1 );
                if ( ! is_array( $all ) ) { $all = []; }
                $search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
                if ( $search !== '' ) {
                    $needle = strtolower( $search );
                    $all = array_values( array_filter( $all, function( $f ) use ( $needle ) { return strpos( strtolower( AVCF_Forminator_Helpers::extract_title( $f ) ), $needle ) !== false; } ) );
                }
                $total = count( $all );
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $page = array_slice( $all, $offset, $limit );
                $out = [];
                foreach ( $page as $f ) { if ( AVCF_Forminator_Helpers::extract_id( $f ) > 0 ) { $out[] = AVCF_Forminator_Helpers::map_form_summary( $f ); } }
                return [ 'success' => true, 'forms' => $out, 'total' => $total, 'message' => sprintf( '%d form(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-get-form', [
            'label' => 'Get Forminator Form', 'category' => 'atarim',
            'description' => 'Full Forminator form definition: fields (id=slug, type, label, required, options), email notifications, shortcode. include_raw:true adds the form settings array.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_raw' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $form || is_wp_error( $form ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'form' => AVCF_Forminator_Helpers::map_form_full( $form, ! empty( $input['include_raw'] ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-entries -------------------------- */

    private function register_list_entries() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-list-entries', [
            'label' => 'List Forminator Entries', 'category' => 'atarim',
            'description' => 'List entries for a form (newest first), normalized to { id, submitted_at, status, fields[] } with labels resolved. Filters: date_from, date_to, include_spam. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $include_spam = ! empty( $input['include_spam'] );
                $raw = Forminator_API::get_entries( $form_id, [ 'limit' => $limit, 'offset' => $offset ] );
                if ( is_wp_error( $raw ) ) { return [ 'success' => false, 'message' => $raw->get_error_message() ]; }
                $form = Forminator_API::get_form( $form_id );
                $label_map = AVCF_Forminator_Helpers::build_label_map( $form );
                $out = []; $skipped = 0;
                foreach ( (array) $raw as $e ) {
                    if ( AVCF_Forminator_Helpers::entry_is_spam( $e ) && ! $include_spam ) { $skipped++; continue; }
                    $at = is_object( $e ) && property_exists( $e, 'date_created_sql' ) ? (string) $e->date_created_sql : '';
                    if ( ! empty( $input['date_from'] ) && $at && strcmp( $at, (string) $input['date_from'] ) < 0 ) { continue; }
                    if ( ! empty( $input['date_to'] ) && $at && strcmp( $at, (string) $input['date_to'] ) > 0 ) { continue; }
                    $out[] = AVCF_Forminator_Helpers::normalize_entry( $e, $form_id, $label_map, false );
                }
                $total = AVCF_Forminator_Helpers::count_entries( $form_id, $include_spam );
                return [ 'success' => true, 'entries' => $out, 'total' => $total, 'message' => sprintf( '%d entr(y/ies).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-entry ---------------------------- */

    private function register_get_entry() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-get-entry', [
            'label' => 'Get Forminator Entry', 'category' => 'atarim',
            'description' => 'Full normalized entry by id (the owning form is resolved automatically), with field values, labels resolved, and the raw entry.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $entry_id = (int) $input['entry_id'];
                $table = AVCF_Forminator_Helpers::entry_table();
                $form_id = $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM `{$table}` WHERE entry_id = %d", $entry_id ) );
                if ( ! $form_id ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $entry = Forminator_API::get_entry( (int) $form_id, $entry_id );
                if ( ! $entry || is_wp_error( $entry ) ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $form = Forminator_API::get_form( (int) $form_id );
                return [ 'success' => true, 'entry' => AVCF_Forminator_Helpers::normalize_entry( $entry, (int) $form_id, AVCF_Forminator_Helpers::build_label_map( $form ), true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-form-stats ------------------------- */

    private function register_get_form_stats() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-get-form-stats', [
            'label' => 'Get Forminator Form Stats', 'category' => 'atarim',
            'description' => 'Submission stats (non-spam): total, first/last submission, per-day counts. Optional date window.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                return [ 'success' => true, 'stats' => AVCF_Forminator_Helpers::stats( (int) $input['form_id'], $input ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-form-spam-stats ---------------------- */

    private function register_get_form_spam_stats() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-get-form-spam-stats', [
            'label' => 'Get Forminator Form Spam Stats', 'category' => 'atarim',
            'description' => 'Spam vs non-spam entry counts and spam rate (is_spam column).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'spam' => [ 'type' => 'integer' ], 'active' => [ 'type' => 'integer' ], 'spam_rate' => [ 'type' => 'number' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $all = AVCF_Forminator_Helpers::count_entries( $form_id, true );
                $active = AVCF_Forminator_Helpers::count_entries( $form_id, false );
                $spam = max( 0, $all - $active );
                return [ 'success' => true, 'spam' => $spam, 'active' => $active, 'spam_rate' => $all > 0 ? round( $spam / $all, 4 ) : 0, 'message' => sprintf( '%d spam / %d active.', $spam, $active ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- find-silent-forms ----------------------- */

    private function register_find_silent_forms() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-find-silent-forms', [
            'label' => 'Find Silent Forminator Forms', 'category' => 'atarim',
            'description' => 'Forms with no submission in the last N days (default 30). Returns each with last_submission and days_silent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_forms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $all = Forminator_API::get_forms( null, 1, -1 );
                if ( ! is_array( $all ) ) { $all = []; }
                $silent = [];
                foreach ( $all as $f ) {
                    $fid = AVCF_Forminator_Helpers::extract_id( $f );
                    if ( $fid <= 0 ) { continue; }
                    $last = AVCF_Forminator_Helpers::last_submission_at( $fid );
                    $last_ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $last_ts === false ) { $last_ts = 0; }
                    if ( $last_ts < $cutoff ) {
                        $silent[] = [ 'id' => $fid, 'title' => AVCF_Forminator_Helpers::extract_title( $f ), 'last_submission' => $last, 'days_silent' => $last_ts > 0 ? (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS ) : null ];
                    }
                }
                return [ 'success' => true, 'silent_forms' => $silent, 'message' => sprintf( '%d silent form(s) over %d days.', count( $silent ), $days ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- export-entries ------------------------- */

    private function register_export_entries() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-export-entries', [
            'label' => 'Export Forminator Entries', 'category' => 'atarim',
            'description' => 'Export a form\'s entries as CSV text. Capped via limit (default 1000). Excludes spam unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000;
                $raw = Forminator_API::get_entries( $form_id, [ 'limit' => $limit, 'offset' => 0 ] );
                if ( is_wp_error( $raw ) ) { return [ 'success' => false, 'message' => $raw->get_error_message() ]; }
                $form = Forminator_API::get_form( $form_id );
                $label_map = AVCF_Forminator_Helpers::build_label_map( $form );
                $header = [ 'Entry ID', 'Submitted' ]; $header_set = false; $body = [];
                foreach ( (array) $raw as $e ) {
                    if ( AVCF_Forminator_Helpers::entry_is_spam( $e ) && empty( $input['include_spam'] ) ) { continue; }
                    $n = AVCF_Forminator_Helpers::normalize_entry( $e, $form_id, $label_map, false );
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
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
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
        wp_register_ability( 'atarim/forminator-mark-entry-spam', [
            'label' => 'Mark Forminator Entry Spam', 'category' => 'atarim',
            'description' => 'Flag an entry as spam (is_spam=1) or clear it (is_spam=0).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                $r = $wpdb->update( AVCF_Forminator_Helpers::entry_table(), [ 'is_spam' => $is_spam ? 1 : 0 ], [ 'entry_id' => (int) $input['entry_id'] ], [ '%d' ], [ '%d' ] );
                if ( $r === false ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => $is_spam ? 'Entry marked as spam.' : 'Entry unmarked from spam.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ update-notifications ---------------------- */

    private function register_update_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-update-notifications', [
            'label' => 'Update Forminator Notification Recipients', 'category' => 'atarim',
            'description' => 'Update recipient email(s) of one or more of a form\'s email notifications. updates: [{ notification_id (slug or index), recipients: [emails] }]. Emails sanitized; invalid sets skipped and reported. Saved via the form model.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $form || is_wp_error( $form ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $settings = AVCF_Forminator_Helpers::settings_array( $form );
                $block = AVCF_Forminator_Helpers::notifications_block( $settings );
                $key = $block[0];
                if ( $key === null ) { return [ 'success' => false, 'message' => 'No notifications configured on this form.' ]; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    $matched = false;
                    foreach ( $settings[ $key ] as $idx => $n ) {
                        $cid = isset( $n['slug'] ) ? (string) $n['slug'] : (string) $idx;
                        if ( $cid !== $nid ) { continue; }
                        $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                        if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; $matched = true; break; }
                        $rec = implode( ',', $clean );
                        if ( isset( $n['recipients'] ) ) { $settings[ $key ][ $idx ]['recipients'] = $rec; }
                        else { $settings[ $key ][ $idx ]['email-recipients'] = $rec; }
                        $updated[] = $nid; $matched = true; break;
                    }
                    if ( ! $matched ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Not found on this form.' ]; }
                }
                if ( ! empty( $updated ) && is_object( $form ) && property_exists( $form, 'settings' ) ) {
                    $form->settings = $settings;
                    if ( method_exists( $form, 'save' ) ) { $form->save(); }
                }
                return [ 'success' => ! empty( $updated ), 'updated' => $updated, 'skipped' => $skipped, 'message' => sprintf( '%d updated, %d skipped.', count( $updated ), count( $skipped ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- duplicate-form ------------------------- */

    private function register_duplicate_form() {
        $self = $this;
        wp_register_ability( 'atarim/forminator-duplicate-form', [
            'label' => 'Duplicate Forminator Form', 'category' => 'atarim',
            'description' => 'Clone a form via Forminator_API::add_form (copies fields + settings). Optional new_title (defaults to "<title> (Copy)").',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $orig = Forminator_API::get_form( (int) $input['form_id'] );
                if ( ! $orig || is_wp_error( $orig ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! method_exists( 'Forminator_API', 'add_form' ) ) { return [ 'success' => false, 'message' => 'Forminator_API::add_form unavailable.' ]; }
                $title = isset( $input['new_title'] ) && $input['new_title'] !== '' ? sanitize_text_field( $input['new_title'] ) : ( AVCF_Forminator_Helpers::extract_title( $orig ) . ' (Copy)' );
                $settings = AVCF_Forminator_Helpers::settings_array( $orig );
                $settings['formName'] = $title;
                $fields = property_exists( $orig, 'fields' ) ? $orig->fields : [];
                $new_id = Forminator_API::add_form( $title, 'custom-forms', $fields, $settings );
                if ( is_wp_error( $new_id ) || ! $new_id ) { return [ 'success' => false, 'message' => is_wp_error( $new_id ) ? $new_id->get_error_message() : 'Duplicate failed.' ]; }
                return [ 'success' => true, 'new_form_id' => (int) $new_id, 'message' => sprintf( 'Duplicated as form %d.', (int) $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
