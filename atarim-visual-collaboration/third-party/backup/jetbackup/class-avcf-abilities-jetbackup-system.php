<?php
/**
 * JetBackup — System ability cluster.
 *
 * Read-only introspection: dashboard summary, system info (with the same path
 * redaction JetBackup applies), task logs, database-table listings (for
 * selective backup/restore), and destination file browsing.
 *
 * Gated by manage_options. Built on JetBackup 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_System extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_get_dashboard();
        $this->register_get_system_info();
        $this->register_get_log();
        $this->register_list_database_tables();
        $this->register_get_database_tables();
        $this->register_file_manager();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
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
    private function no_input_schema() {
        return [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ];
    }

    /* ---------------------------- get-dashboard ------------------------- */

    private function register_get_dashboard() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-dashboard', [
            'label'       => 'Get JetBackup Dashboard',
            'category'    => 'atarim',
            'description' => 'Get the JetBackup dashboard summary (recent backups, next runs, status overview).',
            'input_schema'  => $this->no_input_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'GetDashboard', [] );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------------- get-system-info ------------------------ */

    private function register_get_system_info() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-system-info', [
            'label'       => 'Get JetBackup System Info',
            'category'    => 'atarim',
            'description' => 'Get JetBackup system information (PHP/environment/paths). Sensitive path fields are redacted, matching JetBackup\'s own behaviour.',
            'input_schema'  => $this->no_input_schema(),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $result = AVCF_JetBackup_Helpers::invoke( 'GetSystemInfo', [] );
                if ( ! empty( $result['success'] ) && ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
                    $result['data'] = AVCF_JetBackup_Helpers::redact_system_info( $result['data'] );
                }
                return $result;
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------------ get-log ----------------------------- */

    private function register_get_log() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-log', [
            'label'       => 'Get JetBackup Log',
            'category'    => 'atarim',
            'description' => 'Get the log for a queue item (backup/restore/etc.) by queue_item_id. Set content:true to include the log body.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'queue_item_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Queue item id whose log to fetch.' ],
                    'content'       => [ 'type' => 'boolean', 'description' => 'Include the full log content (default false).' ],
                ],
                'required'             => [ 'queue_item_id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['queue_item_id'] ) ) {
                    return [ 'success' => false, 'message' => 'Missing queue_item_id.', 'data' => [] ];
                }
                $data = [ 'queue_item_id' => (int) $input['queue_item_id'] ];
                if ( array_key_exists( 'content', $input ) ) {
                    $data['content'] = filter_var( $input['content'], FILTER_VALIDATE_BOOLEAN );
                }
                return AVCF_JetBackup_Helpers::invoke( 'GetLog', $data );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------- list-database-tables ----------------------- */

    private function register_list_database_tables() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-database-tables', [
            'label'       => 'List JetBackup Database Tables',
            'category'    => 'atarim',
            'description' => 'List the site\'s database tables (useful for building database exclude lists on a backup job). Optional skip, limit (1-100, default 50).',
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
                return AVCF_JetBackup_Helpers::invoke( 'ListDatabaseTables', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------ get-database-tables ----------------------- */

    private function register_get_database_tables() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-database-tables', [
            'label'       => 'Get JetBackup Backup Database Tables',
            'category'    => 'atarim',
            'description' => 'Get the database tables contained in a specific backup snapshot by id (useful for selective database restore — pass these to jetbackup-restore-backup as tables).',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [ 'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Backup snapshot id.' ] ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) {
                    return [ 'success' => false, 'message' => 'Missing id (backup snapshot id).', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( 'GetDatabaseTables', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- file-manager -------------------------- */

    private function register_file_manager() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-file-manager', [
            'label'       => 'Browse JetBackup Files',
            'category'    => 'atarim',
            'description' => 'Browse files/folders (e.g. on a destination or within a backup) to build include/exclude paths for backup or restore. Provide destination_id and/or a location path; id optionally scopes to a backup snapshot.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'destination_id' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Destination id to browse.' ],
                    'location'       => [ 'type' => 'string', 'description' => 'Path/location to list.' ],
                    'id'             => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Optional backup snapshot id to scope browsing.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                $data  = [];
                if ( array_key_exists( 'destination_id', $input ) ) {
                    $data['destination_id'] = (int) $input['destination_id'];
                }
                if ( array_key_exists( 'location', $input ) ) {
                    $data['location'] = (string) $input['location'];
                }
                if ( ! empty( $input['id'] ) ) {
                    $data[ AVCF_JetBackup_Helpers::ID_FIELD ] = (int) $input['id'];
                }
                return AVCF_JetBackup_Helpers::invoke( 'FileManager', $data );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}
