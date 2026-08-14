<?php
/**
 * JetBackup — Backups (snapshots) ability cluster.
 *
 * Namespaced atarim/jetbackup-* over JetBackup's snapshot/queue AJAX Calls:
 *   list/get backups, run a backup, delete, lock-toggle, edit notes, and the
 *   AddToQueue-backed actions (download, download-log, export, extract, reindex).
 *
 * All calls route through AVCF_JetBackup_Helpers::invoke() and are gated by
 * manage_options; delete requires confirm:true. Built on JetBackup 3.1.23.x;
 * not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Backups extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_list_backups();
        $this->register_get_backup();
        $this->register_run_backup();
        $this->register_delete_backup();
        $this->register_toggle_backup_lock();
        $this->register_edit_backup_notes();
        $this->register_download_backup();
        $this->register_download_backup_log();
        $this->register_export_backup();
        $this->register_extract_backup();
        $this->register_reindex_backups();
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

    /** Common output shape: a passthrough of JetBackup's normalised response. */
    private function out_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
                'message' => [ 'type' => 'string' ],
                'data'    => [ 'type' => 'object' ],
                'queue_item_id' => [ 'type' => 'integer', 'description' => 'For actions that start a task (e.g. run-backup): the queue item id of the task just started — poll jetbackup-get-queue-item with this to track it.' ],
            ],
            'required'   => [ 'success', 'message' ],
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
    private function missing_id( $what ) {
        return [ 'success' => false, 'message' => sprintf( 'Missing id (%s).', $what ), 'data' => [] ];
    }

    /* ---------------------------- list-backups -------------------------- */

    private function register_list_backups() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-backups', [
            'label'       => 'List JetBackup Backups',
            'category'    => 'atarim',
            'description' => 'List available JetBackup backup snapshots (paginated). Optional skip, limit (1-100, default 50). Returns JetBackup\'s snapshot list in data.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'skip'  => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                    'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'ListBackups', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- get-backup --------------------------- */

    private function register_get_backup() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-backup', [
            'label'       => 'Get JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Get a single JetBackup backup snapshot by id.',
            'input_schema'  => $this->id_schema( 'Backup snapshot id.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'GetBackup', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------------- run-backup --------------------------- */

    private function register_run_backup() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-run-backup', [
            'label'       => 'Run JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Queue a backup for a backup job ("Run now"). id is the backup-job id (see jetbackup-list-backup-jobs). Returns queue_item_id (the id of the backup task just started) — poll jetbackup-get-queue-item with it for progress; when it completes, that item\'s data includes snapshot_name (the snapshot produced), which you can pass to jetbackup-restore-backup.',
            'input_schema'  => $this->id_schema( 'Backup job id to run.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup job id to run' ); }
                $result = AVCF_JetBackup_Helpers::invoke( 'AddToQueue', AVCF_JetBackup_Helpers::id_payload( $input, [ AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_BACKUP ] ) );
                return AVCF_JetBackup_Helpers::surface_queue_id( $result );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- delete-backup -------------------------- */

    private function register_delete_backup() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-delete-backup', [
            'label'       => 'Delete JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Permanently delete a backup snapshot by id. Destructive: requires confirm:true.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Backup snapshot id to delete.' ],
                    'confirm' => [ 'type' => 'boolean', 'description' => 'Must be true to proceed with deletion.' ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id to delete' ); }
                if ( ! AVCF_JetBackup_Helpers::confirmed( $input ) ) { return AVCF_JetBackup_Helpers::confirm_required_response( 'delete backup snapshot' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DeleteSnapshot', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------- toggle-backup-lock ----------------------- */

    private function register_toggle_backup_lock() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-toggle-backup-lock', [
            'label'       => 'Toggle JetBackup Backup Lock',
            'category'    => 'atarim',
            'description' => 'Toggle the lock state of a backup snapshot (locked backups are protected from retention cleanup). Flips locked <-> unlocked and returns the new state.',
            'input_schema'  => $this->id_schema( 'Backup snapshot id to lock/unlock.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'LockSnapshot', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- edit-backup-notes ------------------------ */

    private function register_edit_backup_notes() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-edit-backup-notes', [
            'label'       => 'Edit JetBackup Backup Notes',
            'category'    => 'atarim',
            'description' => 'Set the notes on a backup snapshot.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'    => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Backup snapshot id.' ],
                    'notes' => [ 'type' => 'string', 'description' => 'Notes text to store on the snapshot.' ],
                ],
                'required'             => [ 'id', 'notes' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id' ); }
                $notes = isset( $input['notes'] ) ? (string) $input['notes'] : '';
                return AVCF_JetBackup_Helpers::invoke( 'EditBackupNotes', AVCF_JetBackup_Helpers::id_payload( $input, [ 'notes' => $notes ] ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- download-backup ------------------------- */

    private function register_download_backup() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-download-backup', [
            'label'       => 'Download JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Queue a downloadable copy of a backup snapshot (appears under jetbackup-list-downloads when ready). id is the backup snapshot id.',
            'input_schema'  => $this->id_schema( 'Backup snapshot id to prepare for download.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'AddToQueue', AVCF_JetBackup_Helpers::id_payload( $input, [ AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_DOWNLOAD ] ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ download-backup-log ----------------------- */

    private function register_download_backup_log() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-download-backup-log', [
            'label'       => 'Download JetBackup Backup Log',
            'category'    => 'atarim',
            'description' => 'Queue a download of a backup snapshot\'s log. id is the backup snapshot id.',
            'input_schema'  => $this->id_schema( 'Backup snapshot id whose log to download.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'AddToQueue', AVCF_JetBackup_Helpers::id_payload( $input, [ AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_DOWNLOAD_LOG ] ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- export-backup -------------------------- */

    private function register_export_backup() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-export-backup', [
            'label'       => 'Export JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Queue an export of a backup snapshot to a hosting control panel (cPanel / DirectAdmin). id is the backup snapshot id; panel_type selects the target panel. Not supported for legacy backups.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'         => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Backup snapshot id to export.' ],
                    'panel_type' => [ 'type' => 'integer', 'description' => 'Target control-panel type id.' ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id to export' ); }
                $panel = isset( $input['panel_type'] ) ? (int) $input['panel_type'] : 0;
                return AVCF_JetBackup_Helpers::invoke( 'AddToQueue', AVCF_JetBackup_Helpers::id_payload( $input, [ AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_EXPORT, 'panel_type' => $panel ] ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- extract-backup ------------------------- */

    private function register_extract_backup() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-extract-backup', [
            'label'       => 'Extract JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Queue extraction of a backup snapshot. id is the backup snapshot id.',
            'input_schema'  => $this->id_schema( 'Backup snapshot id to extract.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup snapshot id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'AddToQueue', AVCF_JetBackup_Helpers::id_payload( $input, [ AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_EXTRACT ] ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* -------------------------- reindex-backups ------------------------- */

    private function register_reindex_backups() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-reindex-backups', [
            'label'       => 'Reindex JetBackup Destination',
            'category'    => 'atarim',
            'description' => 'Queue a re-scan (reindex) of a destination so JetBackup rediscovers its snapshots. id is the destination id (see jetbackup-list-destinations).',
            'input_schema'  => $this->id_schema( 'Destination id to reindex.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'destination id to reindex' ); }
                return AVCF_JetBackup_Helpers::invoke( 'AddToQueue', AVCF_JetBackup_Helpers::id_payload( $input, [ AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_REINDEX ] ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}