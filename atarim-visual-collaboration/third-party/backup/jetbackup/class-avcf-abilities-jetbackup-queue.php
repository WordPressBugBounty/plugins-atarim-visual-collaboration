<?php
/**
 * JetBackup — Queue, Downloads & Alerts ability cluster.
 *
 * Namespaced atarim/jetbackup-* over JetBackup's queue/download/alert AJAX Calls:
 *   list queue items, abort / start-over / clear-completed queue items,
 *   list / delete downloads, list / clear alerts.
 *
 * (get-queue-item lives in the restore cluster since that's its primary use.)
 *
 * Gated by manage_options; delete-download requires confirm:true. Built on
 * JetBackup 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Queue extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_list_queue_items();
        $this->register_abort_queue_item();
        $this->register_start_over_queue_item();
        $this->register_clear_completed_queue_items();
        $this->register_list_downloads();
        $this->register_delete_download();
        $this->register_list_alerts();
        $this->register_clear_alerts();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $d = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ];
    }
    public function can() {
        return AVCF_JetBackup_Helpers::can_manage();
    }
    private function out_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
                'message' => [ 'type' => 'string' ],
                'data'    => [ 'type' => 'object' ],
            ],
            'required'   => [ 'success', 'message' ],
        ];
    }
    private function pagination_schema() {
        return [
            'type'                 => 'object',
            'properties'           => [
                'skip'  => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
            ],
            'additionalProperties' => false,
        ];
    }
    private function id_schema( $desc ) {
        return [
            'type'                 => 'object',
            'properties'           => [ 'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => $desc ] ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
    }
    private function no_input_schema() {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }
    private function missing_id( $what ) {
        return [ 'success' => false, 'message' => sprintf( 'Missing id (%s).', $what ), 'data' => [] ];
    }

    /* -------------------------- list-queue-items ------------------------ */

    private function register_list_queue_items() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-queue-items', [
            'label'       => 'List JetBackup Queue Items',
            'category'    => 'atarim',
            'description' => 'List JetBackup queue items — running and recent backup/restore/download/etc. tasks (paginated). Optional skip, limit (1-100, default 50).',
            'input_schema'  => $this->pagination_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'ListQueueItems', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- abort-queue-item ------------------------ */

    private function register_abort_queue_item() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-abort-queue-item', [
            'label'       => 'Abort JetBackup Queue Item',
            'category'    => 'atarim',
            'description' => 'Abort (cancel) a running or pending queue item by id.',
            'input_schema'  => $this->id_schema( 'Queue item id to abort.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'queue item id to abort' ); }
                return AVCF_JetBackup_Helpers::invoke( 'AbortQueueItem', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ start-over-queue-item --------------------- */

    private function register_start_over_queue_item() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-start-over-queue-item', [
            'label'       => 'Restart JetBackup Queue Item',
            'category'    => 'atarim',
            'description' => 'Restart a queue item from the beginning by id (e.g. retry a failed task).',
            'input_schema'  => $this->id_schema( 'Queue item id to restart.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'queue item id to restart' ); }
                return AVCF_JetBackup_Helpers::invoke( 'StartOverQueueItem', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------- clear-completed-queue-items ------------------- */

    private function register_clear_completed_queue_items() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-clear-completed-queue-items', [
            'label'       => 'Clear Completed JetBackup Queue Items',
            'category'    => 'atarim',
            'description' => 'Clear all completed items from the JetBackup queue.',
            'input_schema'  => $this->no_input_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'ClearCompletedQueueItems', [] );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- list-downloads ------------------------- */

    private function register_list_downloads() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-downloads', [
            'label'       => 'List JetBackup Downloads',
            'category'    => 'atarim',
            'description' => 'List prepared backup downloads (from jetbackup-download-backup), paginated. Optional skip, limit (1-100, default 50).',
            'input_schema'  => $this->pagination_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'ListDownloads', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- delete-download ------------------------ */

    private function register_delete_download() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-delete-download', [
            'label'       => 'Delete JetBackup Download',
            'category'    => 'atarim',
            'description' => 'Delete a prepared download by id. Destructive: requires confirm:true.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Download id to delete.' ],
                    'confirm' => [ 'type' => 'boolean', 'description' => 'Must be true to proceed.' ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'download id to delete' ); }
                if ( ! AVCF_JetBackup_Helpers::confirmed( $input ) ) { return AVCF_JetBackup_Helpers::confirm_required_response( 'delete download' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DeleteDownload', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- list-alerts --------------------------- */

    private function register_list_alerts() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-alerts', [
            'label'       => 'List JetBackup Alerts',
            'category'    => 'atarim',
            'description' => 'List JetBackup alerts/notifications (paginated). Optional skip, limit (1-100, default 50).',
            'input_schema'  => $this->pagination_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'ListAlerts', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- clear-alerts -------------------------- */

    private function register_clear_alerts() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-clear-alerts', [
            'label'       => 'Clear JetBackup Alerts',
            'category'    => 'atarim',
            'description' => 'Clear all JetBackup alerts.',
            'input_schema'  => $this->no_input_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'ClearAlerts', [] );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
