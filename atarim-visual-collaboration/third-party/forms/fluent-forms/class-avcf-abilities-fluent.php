<?php
/**
 * Fluent Forms — core MCP abilities (standalone cluster).
 *
 * The common forms surface specialised to Fluent Forms and namespaced
 * atarim/fluent-*. Fluent stores submissions in all editions, so entry
 * abilities are not Pro-gated. Fluent-specific power abilities (form CRUD,
 * submission status workflow, entry notes, notifications/confirmation settings,
 * and integration-feed reads) live in the -pro file. Permissions use Fluent's
 * capabilities with a manage_options fallback. Direct $wpdb on Fluent's schema;
 * not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Fluent extends AVCF_Abilities_Base {

    /** @var AVCF_Fluent_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Fluent_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_fluent_is_available() ) {
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

    public function can_view_forms() { return current_user_can( 'fluentform_forms_manager' ) || current_user_can( 'fluentform_view_dashboard' ) || current_user_can( 'manage_options' ); }
    public function can_view_entries() { return current_user_can( 'fluentform_entries_viewer' ) || current_user_can( 'fluentform_view_dashboard' ) || current_user_can( 'manage_options' ); }
    public function can_edit_forms() { return current_user_can( 'fluentform_forms_manager' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'fluentform_entries_viewer' ) || current_user_can( 'manage_options' ); }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-list-forms', [
            'label' => 'List Fluent Forms', 'category' => 'atarim',
            'description' => 'List Fluent Forms: { id, title, status, entries_total, last_submission, created_at }. Optional search, status filter (published|unpublished, default all), limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'enum' => [ 'published', 'unpublished' ] ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $table = AVCF_Fluent_Helpers::forms_table();
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $where = [ '1=1' ]; $params = [];
                if ( isset( $input['status'] ) && $input['status'] !== '' ) { $where[] = 'status = %s'; $params[] = (string) $input['status']; }
                if ( isset( $input['search'] ) && $input['search'] !== '' ) { $where[] = 'title LIKE %s'; $params[] = '%' . $wpdb->esc_like( (string) $input['search'] ) . '%'; }
                $where_sql = implode( ' AND ', $where );
                $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $params ) : "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}" );
                $sql = "SELECT id, title, status, created_at, updated_at FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
                $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $limit, $offset ] ) ) );
                $out = [];
                foreach ( (array) $rows as $r ) { $out[] = AVCF_Fluent_Helpers::map_form_summary( $r ); }
                return [ 'success' => true, 'forms' => $out, 'total' => $total, 'message' => sprintf( '%d form(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-get-form', [
            'label' => 'Get Fluent Form', 'category' => 'atarim',
            'description' => 'Full Fluent form definition: flattened fields (id=field name, type, label, required, options), notifications, shortcode. include_raw:true adds the raw form row.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_raw' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $table = AVCF_Fluent_Helpers::forms_table();
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $input['form_id'] ) );
                if ( ! $row ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'form' => AVCF_Fluent_Helpers::map_form_full( $row, ! empty( $input['include_raw'] ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-entries -------------------------- */

    private function register_list_entries() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-list-entries', [
            'label' => 'List Fluent Entries', 'category' => 'atarim',
            'description' => 'List submissions for a form (newest first), normalized to { id, submitted_at, status, fields[] } with field labels resolved. Filters: date_from, date_to, include_spam. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $form_id = (int) $input['form_id'];
                $subs = AVCF_Fluent_Helpers::subs_table();
                $forms = AVCF_Fluent_Helpers::forms_table();
                $where = [ 'form_id = %d' ]; $params = [ $form_id ];
                if ( empty( $input['include_spam'] ) ) { $where[] = 'status != %s'; $params[] = 'spam'; }
                if ( ! empty( $input['date_from'] ) ) { $where[] = 'created_at >= %s'; $params[] = (string) $input['date_from']; }
                if ( ! empty( $input['date_to'] ) )   { $where[] = 'created_at <= %s'; $params[] = (string) $input['date_to']; }
                $where_sql = implode( ' AND ', $where );
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$subs}` WHERE {$where_sql}", $params ) );
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$subs}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) ) );
                $form_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$forms}` WHERE id = %d", $form_id ) );
                $label_map = $form_row ? AVCF_Fluent_Helpers::build_label_map( AVCF_Fluent_Helpers::fields_from_row( $form_row ) ) : [];
                $out = [];
                foreach ( (array) $rows as $r ) { $out[] = AVCF_Fluent_Helpers::normalize_entry( $r, $form_id, $label_map, false ); }
                return [ 'success' => true, 'entries' => $out, 'total' => $total, 'message' => sprintf( '%d entr(y/ies).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-entry ---------------------------- */

    private function register_get_entry() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-get-entry', [
            'label' => 'Get Fluent Entry', 'category' => 'atarim',
            'description' => 'Full normalized submission by id, with field labels resolved and the raw row included.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $subs = AVCF_Fluent_Helpers::subs_table();
                $forms = AVCF_Fluent_Helpers::forms_table();
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$subs}` WHERE id = %d", (int) $input['entry_id'] ) );
                if ( ! $row ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $form_id = (int) $row->form_id;
                $form_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$forms}` WHERE id = %d", $form_id ) );
                $label_map = $form_row ? AVCF_Fluent_Helpers::build_label_map( AVCF_Fluent_Helpers::fields_from_row( $form_row ) ) : [];
                return [ 'success' => true, 'entry' => AVCF_Fluent_Helpers::normalize_entry( $row, $form_id, $label_map, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-form-stats ------------------------- */

    private function register_get_form_stats() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-get-form-stats', [
            'label' => 'Get Fluent Form Stats', 'category' => 'atarim',
            'description' => 'Submission stats (non-spam): total, first/last submission, per-day counts. Optional date window.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                return [ 'success' => true, 'stats' => AVCF_Fluent_Helpers::stats( (int) $input['form_id'], $input ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-form-spam-stats ---------------------- */

    private function register_get_form_spam_stats() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-get-form-spam-stats', [
            'label' => 'Get Fluent Form Spam Stats', 'category' => 'atarim',
            'description' => 'Spam vs non-spam submission counts and spam rate for a form.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'spam' => [ 'type' => 'integer' ], 'active' => [ 'type' => 'integer' ], 'spam_rate' => [ 'type' => 'number' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $all = AVCF_Fluent_Helpers::count_entries( $form_id, true );
                $active = AVCF_Fluent_Helpers::count_entries( $form_id, false );
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
        wp_register_ability( 'atarim/fluent-find-silent-forms', [
            'label' => 'Find Silent Fluent Forms', 'category' => 'atarim',
            'description' => 'Forms with no submission in the last N days (default 30). Returns each with last_submission and days_silent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_forms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $table = AVCF_Fluent_Helpers::forms_table();
                $rows = $wpdb->get_results( "SELECT id, title FROM `{$table}` ORDER BY id DESC LIMIT 500" );
                $silent = [];
                foreach ( (array) $rows as $r ) {
                    $fid = (int) $r->id;
                    $last = AVCF_Fluent_Helpers::last_submission_at( $fid );
                    $last_ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $last_ts === false ) { $last_ts = 0; }
                    if ( $last_ts < $cutoff ) {
                        $silent[] = [ 'id' => $fid, 'title' => (string) $r->title, 'last_submission' => $last, 'days_silent' => $last_ts > 0 ? (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS ) : null ];
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
        wp_register_ability( 'atarim/fluent-export-entries', [
            'label' => 'Export Fluent Entries', 'category' => 'atarim',
            'description' => 'Export a form\'s submissions as CSV text. Capped via limit (default 1000). Excludes spam unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $form_id = (int) $input['form_id'];
                $subs = AVCF_Fluent_Helpers::subs_table();
                $forms = AVCF_Fluent_Helpers::forms_table();
                $where = [ 'form_id = %d' ]; $params = [ $form_id ];
                if ( empty( $input['include_spam'] ) ) { $where[] = 'status != %s'; $params[] = 'spam'; }
                $where_sql = implode( ' AND ', $where );
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000;
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$subs}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d", array_merge( $params, [ $limit ] ) ) );
                $form_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$forms}` WHERE id = %d", $form_id ) );
                $label_map = $form_row ? AVCF_Fluent_Helpers::build_label_map( AVCF_Fluent_Helpers::fields_from_row( $form_row ) ) : [];
                $header = [ 'Entry ID', 'Submitted' ]; $header_set = false; $body = [];
                foreach ( (array) $rows as $r ) {
                    $n = AVCF_Fluent_Helpers::normalize_entry( $r, $form_id, $label_map, false );
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
        wp_register_ability( 'atarim/fluent-mark-entry-spam', [
            'label' => 'Mark Fluent Entry Spam', 'category' => 'atarim',
            'description' => 'Flag a submission as spam (or clear with is_spam:false, restoring it to unread).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $table = AVCF_Fluent_Helpers::subs_table();
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                $r = $wpdb->update( $table, [ 'status' => $is_spam ? 'spam' : 'unread' ], [ 'id' => (int) $input['entry_id'] ], [ '%s' ], [ '%d' ] );
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
        wp_register_ability( 'atarim/fluent-update-notifications', [
            'label' => 'Update Fluent Notification Recipients', 'category' => 'atarim',
            'description' => 'Update recipient email(s) of one or more of a form\'s notifications. updates: [{ notification_id, recipients: [emails] }]. Emails sanitized; invalid sets skipped and reported.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $meta_table = AVCF_Fluent_Helpers::meta_table();
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, value FROM `{$meta_table}` WHERE form_id = %d AND meta_key = %s LIMIT 1", (int) $input['form_id'], 'notifications' ) );
                if ( ! $row ) { return [ 'success' => false, 'message' => 'No notifications configured on this form.' ]; }
                $notifs = json_decode( $row->value, true );
                if ( ! is_array( $notifs ) ) { return [ 'success' => false, 'message' => 'Notifications data is corrupt.' ]; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    if ( ! isset( $notifs[ $nid ] ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Not found on this form.' ]; continue; }
                    $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                    if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; continue; }
                    $email_str = implode( ',', $clean );
                    if ( isset( $notifs[ $nid ]['sendTo'] ) && is_array( $notifs[ $nid ]['sendTo'] ) ) { $notifs[ $nid ]['sendTo']['email'] = $email_str; }
                    else { $notifs[ $nid ]['email'] = $email_str; }
                    $updated[] = $nid;
                }
                if ( ! empty( $updated ) ) { $wpdb->update( $meta_table, [ 'value' => wp_json_encode( $notifs ) ], [ 'id' => (int) $row->id ], [ '%s' ], [ '%d' ] ); }
                return [ 'success' => ! empty( $updated ), 'updated' => $updated, 'skipped' => $skipped, 'message' => sprintf( '%d updated, %d skipped.', count( $updated ), count( $skipped ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- duplicate-form ------------------------- */

    private function register_duplicate_form() {
        $self = $this;
        wp_register_ability( 'atarim/fluent-duplicate-form', [
            'label' => 'Duplicate Fluent Form', 'category' => 'atarim',
            'description' => 'Clone a form (row + all form_meta, including notifications). Optional new_title (defaults to "<title> (Copy)").',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                global $wpdb;
                $form_id = (int) $input['form_id'];
                $table = AVCF_Fluent_Helpers::forms_table();
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $form_id ), ARRAY_A );
                if ( ! $row ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $title = isset( $input['new_title'] ) && $input['new_title'] !== '' ? sanitize_text_field( $input['new_title'] ) : ( (string) $row['title'] . ' (Copy)' );
                $new = $row; unset( $new['id'] );
                $new['title'] = $title; $new['created_at'] = current_time( 'mysql' ); $new['updated_at'] = current_time( 'mysql' );
                if ( ! $wpdb->insert( $table, $new ) ) { return [ 'success' => false, 'message' => 'Duplicate failed.' ]; }
                $new_id = (int) $wpdb->insert_id;
                $meta_table = AVCF_Fluent_Helpers::meta_table();
                $metas = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, value FROM `{$meta_table}` WHERE form_id = %d", $form_id ), ARRAY_A );
                foreach ( (array) $metas as $m ) { $wpdb->insert( $meta_table, [ 'form_id' => $new_id, 'meta_key' => $m['meta_key'], 'value' => $m['value'] ] ); }
                return [ 'success' => true, 'new_form_id' => $new_id, 'message' => sprintf( 'Duplicated as form %d.', $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
