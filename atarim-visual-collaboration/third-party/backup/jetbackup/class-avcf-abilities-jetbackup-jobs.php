<?php
/**
 * JetBackup — Backup Jobs ability cluster.
 *
 * Namespaced atarim/jetbackup-* over JetBackup's backup-job AJAX Calls:
 *   list/get jobs, save (create/edit), delete, duplicate, toggle enabled.
 *
 * ManageBackupJob applies only the fields present in the request (isset-gated),
 * so save-backup-job is safe for partial edits: pass only what you want changed.
 * Omit id (or pass 0) to create; pass a real id to edit.
 *
 * Gated by manage_options; delete requires confirm:true. Built on JetBackup
 * 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Jobs extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_list_backup_jobs();
        $this->register_get_backup_job();
        $this->register_save_backup_job();
        $this->register_delete_backup_job();
        $this->register_duplicate_backup_job();
        $this->register_toggle_backup_job();
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

    /* ------------------------- list-backup-jobs ------------------------- */

    private function register_list_backup_jobs() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-backup-jobs', [
            'label'       => 'List JetBackup Backup Jobs',
            'category'    => 'atarim',
            'description' => 'List configured JetBackup backup jobs (paginated). Optional skip, limit (1-100, default 50).',
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
                return AVCF_JetBackup_Helpers::invoke( 'ListBackupJobs', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-backup-job -------------------------- */

    private function register_get_backup_job() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-backup-job', [
            'label'       => 'Get JetBackup Backup Job',
            'category'    => 'atarim',
            'description' => 'Get a single JetBackup backup job by id (returns its full configuration — useful to inspect valid values before editing with jetbackup-save-backup-job).',
            'input_schema'  => $this->id_schema( 'Backup job id.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup job id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'GetBackupJob', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- save-backup-job ------------------------- */

    private function register_save_backup_job() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-save-backup-job', [
            'label'       => 'Save JetBackup Backup Job',
            'category'    => 'atarim',
            'description' => 'Create or update a JetBackup backup job. Omit id (or 0) to create; pass a real id to edit. Only the fields you provide are changed. For complex edits, first read the job with jetbackup-get-backup-job and mirror its shape. destinations and schedules are arrays of ids; type / backup_contains use JetBackup\'s own numeric values (see an existing job).',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'                 => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Job id to edit; omit or 0 to create.' ],
                    'name'               => [ 'type' => 'string' ],
                    'type'               => [ 'type' => 'integer', 'description' => 'JetBackup backup type value.' ],
                    'backup_contains'    => [ 'type' => 'integer', 'description' => 'What the backup contains (files/database/both) — JetBackup value.' ],
                    'destinations'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Destination ids.' ],
                    'schedules'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Schedule ids attached to this job.' ],
                    'excludes'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'File/folder paths to exclude.' ],
                    'database_excludes'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Database tables to exclude.' ],
                    'is_files_excluded'  => [ 'type' => 'boolean' ],
                    'is_tables_excluded' => [ 'type' => 'boolean' ],
                    'job_monitor'        => [ 'type' => 'boolean', 'description' => 'Enable job monitoring/alerts.' ],
                    'schedule_time'      => [ 'type' => 'integer', 'description' => 'Scheduled time value (as shown by get-backup-job).' ],
                    'enabled'            => [ 'type' => 'boolean' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input   = (array) $input;
                $payload = [];

                if ( ! empty( $input['id'] ) ) {
                    $payload[ AVCF_JetBackup_Helpers::ID_FIELD ] = (int) $input['id'];
                }
                if ( array_key_exists( 'name', $input ) )               { $payload['name'] = (string) $input['name']; }
                if ( array_key_exists( 'type', $input ) )               { $payload['type'] = (int) $input['type']; }
                if ( array_key_exists( 'backup_contains', $input ) )    { $payload['backup_contains'] = (int) $input['backup_contains']; }
                if ( array_key_exists( 'destinations', $input ) )       { $payload['destinations'] = array_values( array_map( 'intval', (array) $input['destinations'] ) ); }
                if ( array_key_exists( 'schedules', $input ) )          { $payload['schedules'] = array_values( array_map( 'intval', (array) $input['schedules'] ) ); }
                if ( array_key_exists( 'excludes', $input ) )           { $payload['excludes'] = array_values( array_map( 'strval', (array) $input['excludes'] ) ); }
                if ( array_key_exists( 'database_excludes', $input ) )  { $payload['database_excludes'] = array_values( array_map( 'strval', (array) $input['database_excludes'] ) ); }
                if ( array_key_exists( 'is_files_excluded', $input ) )  { $payload['is_files_excluded'] = filter_var( $input['is_files_excluded'], FILTER_VALIDATE_BOOLEAN ); }
                if ( array_key_exists( 'is_tables_excluded', $input ) ) { $payload['is_tables_excluded'] = filter_var( $input['is_tables_excluded'], FILTER_VALIDATE_BOOLEAN ); }
                if ( array_key_exists( 'job_monitor', $input ) )        { $payload['job_monitor'] = filter_var( $input['job_monitor'], FILTER_VALIDATE_BOOLEAN ); }
                if ( array_key_exists( 'schedule_time', $input ) )      { $payload['schedule_time'] = (int) $input['schedule_time']; }
                if ( array_key_exists( 'enabled', $input ) )            { $payload['enabled'] = filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN ); }

                if ( empty( $payload ) ) {
                    return [ 'success' => false, 'message' => 'No backup-job fields provided.', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( 'ManageBackupJob', $payload );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- delete-backup-job ------------------------ */

    private function register_delete_backup_job() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-delete-backup-job', [
            'label'       => 'Delete JetBackup Backup Job',
            'category'    => 'atarim',
            'description' => 'Delete a backup job by id. Destructive: requires confirm:true. (Does not delete existing snapshots created by the job.)',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Backup job id to delete.' ],
                    'confirm' => [ 'type' => 'boolean', 'description' => 'Must be true to proceed.' ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup job id to delete' ); }
                if ( ! AVCF_JetBackup_Helpers::confirmed( $input ) ) { return AVCF_JetBackup_Helpers::confirm_required_response( 'delete backup job' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DeleteBackupJob', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------ duplicate-backup-job ---------------------- */

    private function register_duplicate_backup_job() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-duplicate-backup-job', [
            'label'       => 'Duplicate JetBackup Backup Job',
            'category'    => 'atarim',
            'description' => 'Duplicate an existing backup job by id (creates a copy you can then edit).',
            'input_schema'  => $this->id_schema( 'Backup job id to duplicate.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup job id to duplicate' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DuplicateBackupJob', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- toggle-backup-job ------------------------ */

    private function register_toggle_backup_job() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-toggle-backup-job', [
            'label'       => 'Toggle JetBackup Backup Job',
            'category'    => 'atarim',
            'description' => 'Toggle a backup job\'s enabled state (flips enabled <-> disabled) and returns the new state. A disabled job will not run on its schedule.',
            'input_schema'  => $this->id_schema( 'Backup job id to enable/disable.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'backup job id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'EnableBackupJob', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
