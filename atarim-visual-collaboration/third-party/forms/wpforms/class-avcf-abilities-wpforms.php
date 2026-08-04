<?php
/**
 * WPForms — core MCP abilities (standalone cluster).
 *
 * The common forms surface specialised to WPForms and namespaced
 * atarim/wpforms-*. Entry-dependent abilities require WPForms Pro (Lite stores
 * no entries) and return a clear note when run on Lite. WPForms-specific power
 * abilities (form CRUD, entry star/read/notes/delete, notifications &
 * confirmations, providers) live in the -pro file. Permissions use WPForms'
 * own capabilities with a manage_options fallback. Built on WPForms; not
 * runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_WPForms extends AVCF_Abilities_Base {

    /** @var AVCF_WPForms_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WPForms_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wpforms_is_available() ) {
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

    public function is_pro() { return $this->detector->avcf_wpforms_is_pro(); }
    public function can_view_forms() { return current_user_can( 'wpforms_edit_forms' ) || current_user_can( 'wpforms_view_entries' ) || current_user_can( 'manage_options' ); }
    public function can_view_entries() { return current_user_can( 'wpforms_view_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_entries() { return current_user_can( 'wpforms_edit_entries' ) || current_user_can( 'manage_options' ); }
    public function can_edit_forms() { return current_user_can( 'wpforms_edit_forms' ) || current_user_can( 'manage_options' ); }

    private function lite_block() { return [ 'success' => false, 'message' => AVCF_WPForms_Helpers::LITE_NOTE ]; }

    /* ---------------------------- list-forms --------------------------- */

    private function register_list_forms() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-list-forms', [
            'label' => 'List WPForms', 'category' => 'atarim',
            'description' => 'List WPForms forms: { id, title, status, entries_total, last_submission, created_at }. entries_total/last_submission are null on WPForms Lite (no entry storage). Optional search, limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'forms' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $qa = [ 'post_type' => 'wpforms', 'post_status' => 'any', 'posts_per_page' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50, 'offset' => isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0, 'orderby' => 'date', 'order' => 'DESC' ];
                if ( isset( $input['search'] ) && $input['search'] !== '' ) { $qa['s'] = (string) $input['search']; }
                $q = new WP_Query( $qa );
                $is_pro = $self->is_pro();
                $out = [];
                foreach ( $q->posts as $post ) { $out[] = AVCF_WPForms_Helpers::map_form_summary( $post, $is_pro ); }
                return [ 'success' => true, 'forms' => $out, 'total' => (int) $q->found_posts, 'message' => sprintf( '%d form(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-form ---------------------------- */

    private function register_get_form() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-get-form', [
            'label' => 'Get WPForm', 'category' => 'atarim',
            'description' => 'Full WPForms form definition: fields (id, type, label, required, options), notifications, confirmations, shortcode. include_raw:true adds the decoded form_data array.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_raw' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'form' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = wpforms()->form->get( (int) $input['form_id'], [ 'content_only' => false ] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $form_data = wpforms_decode( $form->post_content );
                return [ 'success' => true, 'form' => AVCF_WPForms_Helpers::map_form_full( $form, $form_data, ! empty( $input['include_raw'] ) ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_forms(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- list-entries -------------------------- */

    private function register_list_entries() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-list-entries', [
            'label' => 'List WPForms Entries', 'category' => 'atarim',
            'description' => 'List entries for a form (newest first), normalized to { id, submitted_at, status, starred, viewed, fields[] }. WPForms Pro only. Filters: date_from, date_to, include_spam. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                $form_id = (int) $input['form_id'];
                $include_spam = ! empty( $input['include_spam'] );
                $qa = [ 'form_id' => $form_id, 'number' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50, 'offset' => isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0 ];
                if ( ! $include_spam ) { $qa['status'] = ''; }
                if ( ! empty( $input['date_from'] ) ) { $qa['date_after'] = (string) $input['date_from']; }
                if ( ! empty( $input['date_to'] ) ) { $qa['date_before'] = (string) $input['date_to']; }
                $raw = wpforms()->entry->get_entries( $qa );
                $out = [];
                foreach ( (array) $raw as $r ) { $out[] = AVCF_WPForms_Helpers::normalize_entry( $r, $form_id, false ); }
                return [ 'success' => true, 'entries' => $out, 'total' => AVCF_WPForms_Helpers::count_entries( $form_id, $include_spam, $input ), 'message' => sprintf( '%d entr(y/ies).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-entry ---------------------------- */

    private function register_get_entry() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-get-entry', [
            'label' => 'Get WPForms Entry', 'category' => 'atarim',
            'description' => 'Full normalized entry by id, including all field values and the raw entry. WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                $entry = wpforms()->entry->get( (int) $input['entry_id'] );
                if ( ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $form_id = isset( $entry->form_id ) ? (int) $entry->form_id : 0;
                return [ 'success' => true, 'entry' => AVCF_WPForms_Helpers::normalize_entry( $entry, $form_id, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-form-stats ------------------------- */

    private function register_get_form_stats() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-get-form-stats', [
            'label' => 'Get WPForms Form Stats', 'category' => 'atarim',
            'description' => 'Submission stats: total, first/last submission, per-day counts. WPForms Pro only. Optional date window.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                return [ 'success' => true, 'stats' => AVCF_WPForms_Helpers::stats( (int) $input['form_id'], $input ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_view_entries(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-form-spam-stats ---------------------- */

    private function register_get_form_spam_stats() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-get-form-spam-stats', [
            'label' => 'Get WPForms Form Spam Stats', 'category' => 'atarim',
            'description' => 'Spam vs non-spam entry counts and spam rate for a form. WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'spam' => [ 'type' => 'integer' ], 'active' => [ 'type' => 'integer' ], 'spam_rate' => [ 'type' => 'number' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                $form_id = (int) $input['form_id'];
                $all = AVCF_WPForms_Helpers::count_entries( $form_id, true );
                $active = AVCF_WPForms_Helpers::count_entries( $form_id, false );
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
        wp_register_ability( 'atarim/wpforms-find-silent-forms', [
            'label' => 'Find Silent WPForms', 'category' => 'atarim',
            'description' => 'Forms with no submission in the last N days (default 30). WPForms Pro only (needs entry data). Returns each with last_submission and days_silent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_forms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $q = new WP_Query( [ 'post_type' => 'wpforms', 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids' ] );
                $silent = [];
                foreach ( $q->posts as $fid ) {
                    $fid = (int) $fid;
                    $last = AVCF_WPForms_Helpers::last_submission_at( $fid );
                    $last_ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $last_ts === false ) { $last_ts = 0; }
                    if ( $last_ts < $cutoff ) {
                        $silent[] = [ 'id' => $fid, 'title' => get_the_title( $fid ), 'last_submission' => $last, 'days_silent' => $last_ts > 0 ? (int) floor( ( time() - $last_ts ) / DAY_IN_SECONDS ) : null ];
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
        wp_register_ability( 'atarim/wpforms-export-entries', [
            'label' => 'Export WPForms Entries', 'category' => 'atarim',
            'description' => 'Export a form\'s entries as CSV text. WPForms Pro only. Capped via limit (default 1000). Excludes spam unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                $form_id = (int) $input['form_id'];
                $qa = [ 'form_id' => $form_id, 'number' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000, 'offset' => 0 ];
                if ( empty( $input['include_spam'] ) ) { $qa['status'] = ''; }
                $raw = wpforms()->entry->get_entries( $qa );
                $rows = []; $header_set = false; $header = [ 'Entry ID', 'Submitted' ];
                $body = [];
                foreach ( (array) $raw as $e ) {
                    $n = AVCF_WPForms_Helpers::normalize_entry( $e, $form_id, false );
                    $line = [ (string) $n['id'], (string) $n['submitted_at'] ];
                    foreach ( $n['fields'] as $fld ) {
                        if ( ! $header_set ) { $header[] = $fld['label'] !== '' ? $fld['label'] : ( 'Field ' . $fld['id'] ); }
                        $val = is_array( $fld['value'] ) ? implode( ' | ', $fld['value'] ) : (string) $fld['value'];
                        $line[] = $val;
                    }
                    $header_set = true;
                    $body[] = $line;
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
        wp_register_ability( 'atarim/wpforms-mark-entry-spam', [
            'label' => 'Mark WPForms Entry Spam', 'category' => 'atarim',
            'description' => 'Flag an entry as spam (or clear with is_spam:false). WPForms Pro only.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'entry_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                if ( ! $self->is_pro() ) { return $self->lite_block_public(); }
                $entry_id = (int) $input['entry_id'];
                $entry = wpforms()->entry->get( $entry_id );
                if ( ! $entry ) { return [ 'success' => false, 'message' => 'Entry not found.' ]; }
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                $r = wpforms()->entry->update( $entry_id, [ 'status' => $is_spam ? 'spam' : '' ], '', 'edit', [ 'cap' => 'edit_entries_form_single' ] );
                if ( ! $r ) { return [ 'success' => false, 'message' => 'Update failed.' ]; }
                return [ 'success' => true, 'message' => $is_spam ? 'Entry marked as spam.' : 'Entry unmarked from spam.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_entries(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ update-notifications ---------------------- */

    private function register_update_notifications() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-update-notifications', [
            'label' => 'Update WPForms Notification Recipients', 'category' => 'atarim',
            'description' => 'Update recipient email(s) of one or more of a form\'s notifications. updates: [{ notification_id, recipients: [emails] }]. Emails sanitized; invalid sets skipped and reported.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'updates' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1 ] ], 'required' => [ 'form_id', 'updates' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'updated' => [ 'type' => 'array' ], 'skipped' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form = wpforms()->form->get( (int) $input['form_id'], [ 'content_only' => false ] );
                if ( ! $form ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                $form_data = wpforms_decode( $form->post_content );
                if ( ! is_array( $form_data ) ) { $form_data = []; }
                $updated = []; $skipped = [];
                foreach ( (array) $input['updates'] as $u ) {
                    $nid = isset( $u['notification_id'] ) ? (string) $u['notification_id'] : '';
                    if ( ! isset( $form_data['settings']['notifications'][ $nid ] ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'Not found on this form.' ]; continue; }
                    $clean = array_filter( array_map( 'sanitize_email', isset( $u['recipients'] ) ? (array) $u['recipients'] : [] ) );
                    if ( empty( $clean ) ) { $skipped[] = [ 'notification_id' => $nid, 'reason' => 'No valid recipient emails.' ]; continue; }
                    $form_data['settings']['notifications'][ $nid ]['email'] = implode( ',', $clean );
                    $updated[] = $nid;
                }
                if ( ! empty( $updated ) ) { wp_update_post( [ 'ID' => (int) $input['form_id'], 'post_content' => wpforms_encode( $form_data ) ] ); }
                return [ 'success' => ! empty( $updated ), 'updated' => $updated, 'skipped' => $skipped, 'message' => sprintf( '%d updated, %d skipped.', count( $updated ), count( $skipped ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- duplicate-form ------------------------- */

    private function register_duplicate_form() {
        $self = $this;
        wp_register_ability( 'atarim/wpforms-duplicate-form', [
            'label' => 'Duplicate WPForm', 'category' => 'atarim',
            'description' => 'Clone a form via WPForms\' built-in duplicate. Optional new_title.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'new_title' => [ 'type' => 'string' ] ], 'required' => [ 'form_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'new_form_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $form_id = (int) $input['form_id'];
                if ( ! wpforms()->form->get( $form_id, [ 'content_only' => false ] ) ) { return [ 'success' => false, 'message' => 'Form not found.' ]; }
                if ( ! method_exists( wpforms()->form, 'duplicate' ) ) { return [ 'success' => false, 'message' => 'WPForms duplicate method not available.' ]; }
                $new_id = wpforms()->form->duplicate( $form_id );
                if ( is_wp_error( $new_id ) || ! $new_id ) { return [ 'success' => false, 'message' => 'Duplicate failed.' ]; }
                if ( isset( $input['new_title'] ) && $input['new_title'] !== '' ) {
                    $nf = wpforms()->form->get( $new_id, [ 'content_only' => false ] );
                    if ( $nf ) {
                        $nd = wpforms_decode( $nf->post_content );
                        if ( is_array( $nd ) ) { $nd['settings']['form_title'] = sanitize_text_field( $input['new_title'] ); wp_update_post( [ 'ID' => $new_id, 'post_title' => sanitize_text_field( $input['new_title'] ), 'post_content' => wpforms_encode( $nd ) ] ); }
                    }
                }
                return [ 'success' => true, 'new_form_id' => (int) $new_id, 'message' => sprintf( 'Duplicated as form %d.', (int) $new_id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit_forms(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /** public wrapper so closures can emit the Lite note */
    public function lite_block_public() { return $this->lite_block(); }
}
