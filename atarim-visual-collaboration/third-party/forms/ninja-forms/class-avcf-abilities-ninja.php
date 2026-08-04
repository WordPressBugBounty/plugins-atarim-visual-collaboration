<?php
/**
 * Ninja Forms — core MCP abilities (standalone cluster).
 *
 * The common forms surface specialised to Ninja Forms and namespaced
 * atarim/ninja-*. Forms/fields/actions/subs go through the Ninja_Forms() model
 * API; submission counts/stats use $wpdb over posts/postmeta. Ninja has no
 * native spam flag — it uses the trash status as the spam-equivalent.
 * Ninja-specific power (form delete, field/action reads, submission delete,
 * notification toggle/save) lives in the -pro file. Permission baseline is
 * manage_options. Built on Ninja's API; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Ninja extends AVCF_Abilities_Base {

    /** @var AVCF_Ninja_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Ninja_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_ninja_is_available() ) {
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

    public function forms_table() { global $wpdb; return $wpdb->prefix . 'nf3_forms'; }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-list-forms', [
            'label' => 'List Ninja Forms', 'category' => 'atarim',
            'description' => 'List Ninja Forms: { id, title, status, entries_total, last_submission, created_at }. Optional search (title), limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $table = $self->forms_table();
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
                if ( $search !== '' ) {
                    $like = '%' . $wpdb->esc_like( $search ) . '%';
                    $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE title LIKE %s", $like ) );
                    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, created_at FROM `{$table}` WHERE title LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $like, $limit, $offset ) );
                } else {
                    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
                    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, created_at FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
                }
                $out = [];
                foreach ( (array) $rows as $r ) { $out[] = AVCF_Ninja_Helpers::map_form_summary( $r ); }
                return [ 'success' => true, 'forms' => $out, 'total' => $total, 'message' => sprintf( '%d form(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-get-form', [
            'label' => 'Get Ninja Form', 'category' => 'atarim',
            'description' => 'Full Ninja form definition: fields (id, key, type, label, required, options), email-action notifications, shortcode. include_raw:true adds the form settings.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_raw' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = AVCF_Ninja_Helpers::map_form_full( (int) $input['form_id'], ! empty( $input['include_raw'] ) );
                if ( $form === null ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                return [ 'success' => true, 'form' => $form, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-entries -------------------------- */

    private function register_list_entries() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-list-entries', [
            'label' => 'List Ninja Entries', 'category' => 'atarim',
            'description' => 'List submissions for a form (newest first), normalized to { id, submitted_at, status, fields[] } with labels resolved. include_spam includes trashed submissions. Filters: date_from, date_to. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $include_spam = ! empty( $input['include_spam'] );
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $ids = AVCF_Ninja_Helpers::sub_ids( $form_id, $include_spam, $input, $limit, $offset );
                $total = AVCF_Ninja_Helpers::count_submissions( $form_id, $include_spam, $input );
                $label_map = AVCF_Ninja_Helpers::build_label_map( $form_id );
                $out = [];
                foreach ( (array) $ids as $sid ) {
                    $sub = Ninja_Forms()->form( $form_id )->sub( (int) $sid )->get();
                    $out[] = AVCF_Ninja_Helpers::normalize_sub( $sub, $form_id, $label_map, (int) $sid, false );
                }
                return [ 'success' => true, 'entries' => $out, 'total' => $total, 'message' => sprintf( '%d entr(y/ies).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-entry ---------------------------- */

    private function register_get_entry() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-get-entry', [
            'label' => 'Get Ninja Entry', 'category' => 'atarim',
            'description' => 'Full normalized submission by id (the owning form is resolved from the _form_id meta), with field values and labels resolved.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $entry_id = (int) $input['entry_id'];
                $post = get_post( $entry_id );
                if ( ! $post || $post->post_type !== 'nf_sub' ) { return [ 'success' => false, 'message' => 'Submission not found.' ]; }
                $form_id = (int) get_post_meta( $entry_id, '_form_id', true );
                if ( ! $form_id ) { return [ 'success' => false, 'message' => 'Submission has no linked form.' ]; }
                $sub = Ninja_Forms()->form( $form_id )->sub( $entry_id )->get();
                return [ 'success' => true, 'entry' => AVCF_Ninja_Helpers::normalize_sub( $sub, $form_id, AVCF_Ninja_Helpers::build_label_map( $form_id ), $entry_id, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-form-stats ------------------------- */

    private function register_get_form_stats() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-get-form-stats', [
            'label' => 'Get Ninja Form Stats', 'category' => 'atarim',
            'description' => 'Submission stats (published, non-trashed): total, first/last submission, per-day counts. Optional date window.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                return [ 'success' => true, 'stats' => AVCF_Ninja_Helpers::stats( (int) $input['form_id'], $input ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-form-spam-stats ---------------------- */

    private function register_get_form_spam_stats() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-get-form-spam-stats', [
            'label' => 'Get Ninja Form Spam Stats', 'category' => 'atarim',
            'description' => 'Trashed (spam-equivalent) vs published submission counts and rate. Ninja has no native spam flag; trashed submissions are treated as spam.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'spam' => [ 'type' => 'integer' ], 'active' => [ 'type' => 'integer' ], 'spam_rate' => [ 'type' => 'number' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $all = AVCF_Ninja_Helpers::count_submissions( $form_id, true );
                $active = AVCF_Ninja_Helpers::count_submissions( $form_id, false );
                $spam = max( 0, $all - $active );
                return [ 'success' => true, 'spam' => $spam, 'active' => $active, 'spam_rate' => $all > 0 ? round( $spam / $all, 4 ) : 0, 'message' => sprintf( '%d trashed / %d active.', $spam, $active ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- find-silent-forms ----------------------- */

    private function register_find_silent_forms() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-find-silent-forms', [
            'label' => 'Find Silent Ninja Forms', 'category' => 'atarim',
            'description' => 'Forms with no submission in the last N days (default 30). Returns each with last_submission and days_silent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_forms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                global $wpdb;
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $table = $self->forms_table();
                $rows = $wpdb->get_results( "SELECT id, title FROM `{$table}` ORDER BY id DESC LIMIT 500" );
                $silent = [];
                foreach ( (array) $rows as $r ) {
                    $fid = (int) $r->id;
                    $last = AVCF_Ninja_Helpers::last_submission_at( $fid );
                    $last_ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $last_ts === false ) { $last_ts = 0; }
                    if ( $last_ts < $cutoff ) {
                        $silent[] = [ 'id' => $fid, 'title' => (string) $r->title, 'last_submission' => $last, 'days_silent' => $last_ts > 0 ? (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS ) : null ];
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
        wp_register_ability( 'atarim/ninja-export-entries', [
            'label' => 'Export Ninja Entries', 'category' => 'atarim',
            'description' => 'Export a form\'s submissions as CSV text. Capped via limit (default 1000). Excludes trashed unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $include_spam = ! empty( $input['include_spam'] );
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000;
                $ids = AVCF_Ninja_Helpers::sub_ids( $form_id, $include_spam, [], $limit, 0 );
                $label_map = AVCF_Ninja_Helpers::build_label_map( $form_id );
                $header = [ 'Entry ID', 'Submitted' ]; $header_set = false; $body = [];
                foreach ( (array) $ids as $sid ) {
                    $sub = Ninja_Forms()->form( $form_id )->sub( (int) $sid )->get();
                    $n = AVCF_Ninja_Helpers::normalize_sub( $sub, $form_id, $label_map, (int) $sid, false );
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
        wp_register_ability( 'atarim/ninja-mark-entry-spam', [
            'label' => 'Mark Ninja Entry Spam', 'category' => 'atarim',
            'description' => 'Trash a submission (Ninja\'s spam-equivalent) or restore it (is_spam:false → publish).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $entry_id = (int) $input['entry_id'];
                $post = get_post( $entry_id );
                if ( ! $post || $post->post_type !== 'nf_sub' ) { return [ 'success' => false, 'message' => 'Submission not found.' ]; }
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                $r = wp_update_post( [ 'ID' => $entry_id, 'post_status' => $is_spam ? 'trash' : 'publish' ], true );
                if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
                return [ 'success' => true, 'message' => $is_spam ? 'Submission trashed (spam-equivalent).' : 'Submission restored.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ update-notifications ---------------------- */

    private function register_update_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/ninja-update-notifications', [
            'label' => 'Update Ninja Notification Recipients', 'category' => 'atarim',
            'description' => 'Update recipient email(s) of one or more email actions. updates: [{ notification_id (action id), recipients: [emails] }]. Emails sanitized; non-email or missing actions skipped and reported.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                $actions = Ninja_Forms()->form( $form_id )->get_actions();
                if ( empty( $actions ) ) { return [ 'success' => false, 'message' => 'No actions configured on this form.' ]; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    $action = isset( $actions[ $nid ] ) ? $actions[ $nid ] : null;
                    if ( ! $action ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Action not found.' ]; continue; }
                    $settings = method_exists( $action, 'get_settings' ) ? $action->get_settings() : [];
                    if ( ! isset( $settings['type'] ) || $settings['type'] !== 'email' ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Not an email action.' ]; continue; }
                    $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                    if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; continue; }
                    if ( method_exists( $action, 'update_setting' ) ) { $action->update_setting( 'to', implode( ',', $clean ) ); }
                    if ( method_exists( $action, 'save' ) ) { $action->save(); }
                    $updated[] = $nid;
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
        wp_register_ability( 'atarim/ninja-duplicate-form', [
            'label' => 'Duplicate Ninja Form', 'category' => 'atarim',
            'description' => 'Clone a form via NF_Database_Models_Form::duplicate. Optional new_title.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'NF_Database_Models_Form' ) || ! method_exists( 'NF_Database_Models_Form', 'duplicate' ) ) { return [ 'success' => false, 'message' => 'Ninja duplicate method unavailable.' ]; }
                $new_id = NF_Database_Models_Form::duplicate( (int) $input['form_id'] );
                if ( ! $new_id ) { return [ 'success' => false, 'message' => 'Duplicate failed.' ]; }
                if ( isset( $input['new_title'] ) && $input['new_title'] !== '' ) {
                    $nf = Ninja_Forms()->form( $new_id )->get();
                    if ( $nf && method_exists( $nf, 'update_setting' ) ) { $nf->update_setting( 'title', sanitize_text_field( $input['new_title'] ) ); if ( method_exists( $nf, 'save' ) ) { $nf->save(); } }
                }
                return [ 'success' => true, 'new_form_id' => (int) $new_id, 'message' => sprintf( 'Duplicated as form %d.', (int) $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_manage(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
