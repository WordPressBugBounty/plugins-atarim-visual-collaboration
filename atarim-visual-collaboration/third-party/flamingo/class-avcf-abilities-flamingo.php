<?php
/**
 * Flamingo — core MCP abilities (standalone cluster).
 *
 * Reads Flamingo's stored submissions (inbound messages) by channel, and offers
 * a CF7-aware convenience: any ability that takes a channel also accepts a CF7
 * form_id, resolved to the form's Flamingo channel via its _flamingo meta
 * (id-based, rename-safe) with slug/title fallback. Flamingo is form-agnostic,
 * so these work for any source that writes to Flamingo, not only CF7.
 * Permissions use Flamingo's capabilities with a manage_options fallback. Built
 * on Flamingo's storage model; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Flamingo extends AVCF_Abilities_Base {

    /** @var AVCF_Flamingo_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Flamingo_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_flamingo_is_available() ) {
            return;
        }
        $this->register_list_channels();
        $this->register_list_inbound();
        $this->register_get_inbound();
        $this->register_get_channel_stats();
        $this->register_export_inbound();
        $this->register_find_silent_channels();
        $this->register_mark_spam();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }

    public function can_read() { return current_user_can( 'flamingo_edit_inbound_messages' ) || current_user_can( 'flamingo_edit_inbound_message' ) || current_user_can( 'manage_options' ); }
    public function can_spam() { return current_user_can( 'flamingo_spam_inbound_message' ) || current_user_can( 'flamingo_edit_inbound_messages' ) || current_user_can( 'manage_options' ); }

    private function channel_props() {
        return [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'channel' => [ 'type' => [ 'string', 'integer' ] ] ];
    }

    /* --------------------------- list-channels ------------------------- */

    private function register_list_channels() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-list-channels', [
            'label' => 'List Flamingo Channels', 'category' => 'atarim',
            'description' => 'List Flamingo inbound channels with counts: { id, slug, name, total, spam, last_submission }. Each CF7 form maps to a channel (channel name = form title, slug = form slug). Use a channel id/slug/name (or a CF7 form_id) with the other flamingo abilities.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'include_counts' => [ 'type' => 'boolean', 'default' => true ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'channels' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $channels = AVCF_Flamingo_Helpers::list_channels( ! isset( $input['include_counts'] ) || ! empty( $input['include_counts'] ) );
                return [ 'success' => true, 'channels' => $channels, 'message' => sprintf( '%d channel(s).', count( $channels ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- list-inbound ------------------------- */

    private function register_list_inbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-list-inbound', [
            'label' => 'List Flamingo Inbound Messages', 'category' => 'atarim',
            'description' => 'List stored submissions (newest first), normalized to { id, submitted_at, subject, from, status, channel, fields[] }. Scope with form_id (CF7) or channel (id/slug/name); omit both for all channels. include_spam includes spam-flagged messages. Filters: date_from, date_to. Paged via limit/offset. Note: subject/from can be blank on CF7 forms that renamed the default your-name/your-email/your-subject fields.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $this->channel_props(), [ 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ], 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ] ), 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entries' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'channel_resolved_via' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $r = AVCF_Flamingo_Helpers::resolve_channel( $input );
                $term_id = $r['term_id'];
                if ( ( ! empty( $input['form_id'] ) || isset( $input['channel'] ) ) && ! $term_id ) {
                    return [ 'success' => false, 'message' => 'Could not resolve that form/channel to a Flamingo channel (no submissions stored yet, or the form has never been submitted).' ];
                }
                $include_spam = ! empty( $input['include_spam'] );
                $limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
                $offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
                $ids = AVCF_Flamingo_Helpers::inbound_ids( $term_id, $include_spam, $input, $limit, $offset );
                $total = AVCF_Flamingo_Helpers::count_inbound( $term_id, $include_spam, $input );
                $out = [];
                foreach ( $ids as $pid ) { $p = get_post( $pid ); if ( $p ) { $out[] = AVCF_Flamingo_Helpers::normalize_inbound( $p, false ); } }
                return [ 'success' => true, 'entries' => $out, 'total' => $total, 'channel_resolved_via' => $r['via'], 'message' => sprintf( '%d message(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-inbound ------------------------- */

    private function register_get_inbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-get-inbound', [
            'label' => 'Get Flamingo Inbound Message', 'category' => 'atarim',
            'description' => 'Full normalized inbound message by id, including all stored fields and the raw meta.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'message_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'message_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'entry' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $p = get_post( (int) $input['message_id'] );
                if ( ! $p || $p->post_type !== AVCF_Flamingo_Helpers::INBOUND_PT ) { return [ 'success' => false, 'message' => 'Inbound message not found.' ]; }
                return [ 'success' => true, 'entry' => AVCF_Flamingo_Helpers::normalize_inbound( $p, true ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- get-channel-stats ----------------------- */

    private function register_get_channel_stats() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-get-channel-stats', [
            'label' => 'Get Flamingo Channel Stats', 'category' => 'atarim',
            'description' => 'Submission stats for a channel (or a CF7 form_id): total, spam_total, last_date, per-day counts. Optional date window.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $this->channel_props(), [ 'date_from' => [ 'type' => 'string' ], 'date_to' => [ 'type' => 'string' ] ] ), 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'stats' => [ 'type' => 'object' ], 'channel_resolved_via' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $r = AVCF_Flamingo_Helpers::resolve_channel( $input );
                if ( ! $r['term_id'] ) { return [ 'success' => false, 'message' => 'Provide a resolvable form_id or channel (id/slug/name).' ]; }
                return [ 'success' => true, 'stats' => AVCF_Flamingo_Helpers::stats( $r['term_id'], $input ), 'channel_resolved_via' => $r['via'], 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- export-inbound ------------------------ */

    private function register_export_inbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-export-inbound', [
            'label' => 'Export Flamingo Inbound Messages', 'category' => 'atarim',
            'description' => 'Export a channel\'s (or CF7 form\'s) stored submissions as CSV text. Capped via limit (default 1000). Excludes spam unless include_spam:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => array_merge( $this->channel_props(), [ 'include_spam' => [ 'type' => 'boolean', 'default' => false ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ] ] ), 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'csv' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $r = AVCF_Flamingo_Helpers::resolve_channel( $input );
                if ( ! $r['term_id'] ) { return [ 'success' => false, 'message' => 'Provide a resolvable form_id or channel (id/slug/name).' ]; }
                $include_spam = ! empty( $input['include_spam'] );
                $limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 1000;
                $ids = AVCF_Flamingo_Helpers::inbound_ids( $r['term_id'], $include_spam, [], $limit, 0 );
                $header = [ 'Message ID', 'Submitted', 'Subject', 'From' ]; $header_set = false; $body = [];
                foreach ( $ids as $pid ) {
                    $p = get_post( $pid ); if ( ! $p ) { continue; }
                    $n = AVCF_Flamingo_Helpers::normalize_inbound( $p, false );
                    $line = [ (string) $n['id'], (string) $n['submitted_at'], (string) $n['subject'], (string) $n['from'] ];
                    foreach ( $n['fields'] as $fld ) {
                        if ( ! $header_set ) { $header[] = $fld['label']; }
                        $line[] = is_array( $fld['value'] ) ? implode( ' | ', array_map( 'strval', $fld['value'] ) ) : (string) $fld['value'];
                    }
                    $header_set = true; $body[] = $line;
                }
                $lines = [ AVCF_Flamingo_Helpers::csv_row( $header ) ];
                foreach ( $body as $b ) { $lines[] = AVCF_Flamingo_Helpers::csv_row( $b ); }
                return [ 'success' => true, 'csv' => implode( "\n", $lines ), 'rows' => count( $body ), 'message' => sprintf( '%d row(s) exported.', count( $body ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------- find-silent-channels ---------------------- */

    private function register_find_silent_channels() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-find-silent-channels', [
            'label' => 'Find Silent Flamingo Channels', 'category' => 'atarim',
            'description' => 'Channels (forms) with no submission in the last N days (default 30). Returns each with last_submission and days_silent.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'silence_days' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 30 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'silent_channels' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $days = isset( $input['silence_days'] ) ? max( 1, (int) $input['silence_days'] ) : 30;
                $cutoff = time() - ( $days * DAY_IN_SECONDS );
                $silent = [];
                foreach ( AVCF_Flamingo_Helpers::list_channels( false ) as $ch ) {
                    $last = AVCF_Flamingo_Helpers::last_at( (int) $ch['id'] );
                    $ts = $last ? strtotime( $last . ' UTC' ) : 0;
                    if ( $ts === false ) { $ts = 0; }
                    if ( $ts < $cutoff ) {
                        $silent[] = [ 'id' => (int) $ch['id'], 'slug' => $ch['slug'], 'name' => $ch['name'], 'last_submission' => $last, 'days_silent' => $ts > 0 ? (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) : null ];
                    }
                }
                return [ 'success' => true, 'silent_channels' => $silent, 'message' => sprintf( '%d silent channel(s) over %d days.', count( $silent ), $days ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_read(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------------ mark-spam -------------------------- */

    private function register_mark_spam() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-mark-spam', [
            'label' => 'Mark Flamingo Message Spam', 'category' => 'atarim',
            'description' => 'Flag an inbound message as spam, or clear the flag (is_spam:false). Updates Flamingo\'s _spam meta.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'message_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'is_spam' => [ 'type' => 'boolean', 'default' => true ] ], 'required' => [ 'message_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $p = get_post( (int) $input['message_id'] );
                if ( ! $p || $p->post_type !== AVCF_Flamingo_Helpers::INBOUND_PT ) { return [ 'success' => false, 'message' => 'Inbound message not found.' ]; }
                $is_spam = ! isset( $input['is_spam'] ) || ! empty( $input['is_spam'] );
                if ( $is_spam ) { update_post_meta( (int) $input['message_id'], '_spam', 'spam' ); }
                else { delete_post_meta( (int) $input['message_id'], '_spam' ); }
                return [ 'success' => true, 'message' => $is_spam ? 'Message flagged as spam.' : 'Spam flag cleared.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_spam(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
