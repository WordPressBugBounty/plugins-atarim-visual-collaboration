<?php
/**
 * JetBackup — Restore ability cluster.
 *
 * The highest-stakes JetBackup surface:
 *   - jetbackup-restore-backup    initiate a restore of an existing snapshot
 *   - jetbackup-get-queue-item    read restore progress AND the standalone
 *                                 restore_url (the "recover even if the site is
 *                                 broken" link) once it is generated
 *   - jetbackup-get-restore-settings / jetbackup-manage-restore-settings
 *
 * Restore is by snapshot id only. JetBackup's file-upload / import-by-path
 * branches need a multipart upload, which isn't expressible as an MCP ability,
 * so they're intentionally not exposed here.
 *
 * The raw restoreOptions bitmask is hidden behind friendly enums
 * (entire/include/exclude/skip for files and database) that we compile into the
 * bitmask server-side — safer for an agent than hand-building a bitmask.
 *
 * Gated by manage_options; restore requires confirm:true. Built on JetBackup
 * 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Restore extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_restore_backup();
        $this->register_get_queue_item();
        $this->register_get_restore_settings();
        $this->register_manage_restore_settings();
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

    /**
     * Compile the friendly files/database enums into JetBackup's restoreOptions
     * bitmask. Defaults to a full restore (entire files + entire database).
     */
    private function compute_restore_options( $files, $db ) {
        $map_files = [
            'entire'  => AVCF_JetBackup_Helpers::R_FILES_ENTIRE,
            'include' => AVCF_JetBackup_Helpers::R_FILES_INCLUDE,
            'exclude' => AVCF_JetBackup_Helpers::R_FILES_EXCLUDE,
            'skip'    => AVCF_JetBackup_Helpers::R_FILES_SKIP,
        ];
        $map_db = [
            'entire'  => AVCF_JetBackup_Helpers::R_DB_ENTIRE,
            'include' => AVCF_JetBackup_Helpers::R_DB_INCLUDE,
            'exclude' => AVCF_JetBackup_Helpers::R_DB_EXCLUDE,
            'skip'    => AVCF_JetBackup_Helpers::R_DB_SKIP,
        ];
        $f = isset( $map_files[ $files ] ) ? $map_files[ $files ] : AVCF_JetBackup_Helpers::R_FILES_ENTIRE;
        $d = isset( $map_db[ $db ] ) ? $map_db[ $db ] : AVCF_JetBackup_Helpers::R_DB_ENTIRE;
        return $f | $d;
    }

    /* ---------------------------- restore-backup ------------------------ */

    private function register_restore_backup() {
        $self = $this;
        $output_schema = $this->out_schema();
        $output_schema['properties']['queue_item_id'] = [
            'type' => 'integer',
            'description' => 'The queue item id of the restore just started — poll jetbackup-get-queue-item with this to track progress and read restore_url once available.',
        ];

        wp_register_ability( 'atarim/jetbackup-restore-backup', [
            'label'       => 'Restore JetBackup Backup',
            'category'    => 'atarim',
            'description' => 'Initiate a restore of an existing backup snapshot (by id, or by snapshot_name from a completed backup). DESTRUCTIVE: this overwrites the live site with the backup\'s contents; requires confirm:true. Only "account" backups are restorable. restore_files / restore_database choose entire|include|exclude|skip (default entire = full restore); pass folders / tables when using include or exclude. After queuing, poll jetbackup-get-queue-item to read progress and the standalone restore_url (usable to complete the restore even if the site later breaks).',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'               => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Backup snapshot id to restore. Provide this OR snapshot_name.' ],
                    'snapshot_name'    => [ 'type' => 'string', 'description' => 'Snapshot name to restore (alternative to id) — e.g. the snapshot_name returned by a completed run-backup queue item. Resolved to the snapshot id automatically.' ],
                    'restore_files'    => [ 'type' => 'string', 'enum' => [ 'entire', 'include', 'exclude', 'skip' ], 'default' => 'entire', 'description' => 'How to restore files.' ],
                    'restore_database' => [ 'type' => 'string', 'enum' => [ 'entire', 'include', 'exclude', 'skip' ], 'default' => 'entire', 'description' => 'How to restore the database.' ],
                    'folders'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Home-dir folders/files to include or exclude (when restore_files is include/exclude).' ],
                    'tables'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Database tables to include or exclude (when restore_database is include/exclude).' ],
                    'restore_options'  => [ 'type' => 'integer', 'description' => 'Advanced: raw JetBackup restoreOptions bitmask. Overrides restore_files/restore_database if provided.' ],
                    'mixed_sites'      => [ 'type' => 'boolean', 'description' => 'Multisite: allow mixed-site restore.' ],
                    'confirm'          => [ 'type' => 'boolean', 'description' => 'Must be true to proceed with the restore.' ],
                ],
                // Deliberately no 'required': this ability takes id OR snapshot_name,
                // as its description says and its callback implements. Naming id here
                // made the framework refuse a snapshot_name-only call before the
                // callback could resolve it, which is how every caller that follows
                // the documentation was rejected. The callback already answers the
                // neither-supplied case, and more usefully than the schema does.
                'additionalProperties' => false,
            ],
            'output_schema' => $output_schema,
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $input = (array) $input;
                $snapshot_id = ! empty( $input['id'] ) ? (int) $input['id'] : 0;
                if ( ! $snapshot_id && ! empty( $input['snapshot_name'] ) ) {
                    $snapshot_id = (int) AVCF_JetBackup_Helpers::resolve_snapshot_id_by_name( $input['snapshot_name'] );
                    if ( ! $snapshot_id ) {
                        return [ 'success' => false, 'message' => sprintf( 'No backup snapshot found with name "%s". Use jetbackup-list-backups to find the snapshot id.', (string) $input['snapshot_name'] ), 'data' => [] ];
                    }
                }
                if ( ! $snapshot_id ) {
                    return [ 'success' => false, 'message' => 'Provide either id (snapshot id) or snapshot_name (e.g. from a completed run-backup, or jetbackup-list-backups).', 'data' => [] ];
                }
                if ( ! AVCF_JetBackup_Helpers::confirmed( $input ) ) {
                    return AVCF_JetBackup_Helpers::confirm_required_response( 'restore backup — overwrites the live site' );
                }

                $files = isset( $input['restore_files'] ) ? strtolower( (string) $input['restore_files'] ) : 'entire';
                $db    = isset( $input['restore_database'] ) ? strtolower( (string) $input['restore_database'] ) : 'entire';
                $opts  = isset( $input['restore_options'] ) ? (int) $input['restore_options'] : $self->compute_restore_options( $files, $db );

                $payload = [
                    AVCF_JetBackup_Helpers::ID_FIELD   => $snapshot_id,
                    AVCF_JetBackup_Helpers::TYPE_FIELD => AVCF_JetBackup_Helpers::QUEUE_RESTORE,
                    'restoreOptions'                   => $opts,
                ];

                if ( in_array( $files, [ 'include', 'exclude' ], true ) && ! empty( $input['folders'] ) ) {
                    $payload['folderList'] = array_values( array_map( 'strval', (array) $input['folders'] ) );
                }
                if ( in_array( $db, [ 'include', 'exclude' ], true ) && ! empty( $input['tables'] ) ) {
                    $payload['selectedTables'] = array_values( array_map( 'strval', (array) $input['tables'] ) );
                }
                if ( isset( $input['mixed_sites'] ) ) {
                    $payload['mixedSites'] = filter_var( $input['mixed_sites'], FILTER_VALIDATE_BOOLEAN );
                }

                return AVCF_JetBackup_Helpers::surface_queue_id(
                    AVCF_JetBackup_Helpers::invoke( 'AddToQueue', $payload ),
                    AVCF_JetBackup_Helpers::QUEUE_RESTORE
                );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* --------------------------- get-queue-item ------------------------- */

    private function register_get_queue_item() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-queue-item', [
            'label'       => 'Get JetBackup Queue Item',
            'category'    => 'atarim',
            'description' => 'Get a single JetBackup queue item by id, including status and progress. For a completed BACKUP item, data includes snapshot_name (the snapshot it produced) — pass that to jetbackup-restore-backup. For a RESTORE item, data includes restore_url — the standalone recovery link — once the item reaches the waiting-for-restore stage.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [ 'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Queue item id.' ] ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) {
                    return [ 'success' => false, 'message' => 'Missing id (queue item id).', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( 'GetQueueItem', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ----------------------- get-restore-settings ----------------------- */

    private function register_get_restore_settings() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-restore-settings', [
            'label'       => 'Get JetBackup Restore Settings',
            'category'    => 'atarim',
            'description' => 'Get JetBackup restore settings (compatibility check, allow cross-domain, alternate path, wp-content only).',
            'input_schema'  => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::invoke( 'GetSettingsRestore', [] );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* --------------------- manage-restore-settings ---------------------- */

    private function register_manage_restore_settings() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-manage-restore-settings', [
            'label'       => 'Manage JetBackup Restore Settings',
            'category'    => 'atarim',
            'description' => 'Update JetBackup restore settings. Only the fields you pass are changed. All are booleans: compatibility_check, allow_cross_domain, alternate_path, wp_content_only.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'compatibility_check' => [ 'type' => 'boolean', 'description' => 'Run compatibility checks before restore.' ],
                    'allow_cross_domain'  => [ 'type' => 'boolean', 'description' => 'Allow restoring a backup taken on a different domain.' ],
                    'alternate_path'      => [ 'type' => 'boolean', 'description' => 'Use an alternate wp-config.php path during restore.' ],
                    'wp_content_only'     => [ 'type' => 'boolean', 'description' => 'Restore wp-content only.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                $map = [
                    'compatibility_check' => 'RESTORE_COMPATIBILITY_CHECK',
                    'allow_cross_domain'  => 'RESTORE_ALLOW_CROSS_DOMAIN',
                    'alternate_path'      => 'RESTORE_ALTERNATE_PATH',
                    'wp_content_only'     => 'RESTORE_WP_CONTENT_ONLY',
                ];
                $payload = [];
                foreach ( $map as $friendly => $key ) {
                    if ( array_key_exists( $friendly, $input ) ) {
                        $payload[ $key ] = filter_var( $input[ $friendly ], FILTER_VALIDATE_BOOLEAN );
                    }
                }
                if ( empty( $payload ) ) {
                    return [ 'success' => false, 'message' => 'No restore settings provided to change.', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( 'ManageSettingsRestore', $payload );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}