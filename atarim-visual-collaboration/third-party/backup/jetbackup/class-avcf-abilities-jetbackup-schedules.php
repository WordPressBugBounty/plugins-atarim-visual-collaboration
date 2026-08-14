<?php
/**
 * JetBackup — Schedules ability cluster ("back up every hour/day/week").
 *
 * Namespaced atarim/jetbackup-* over JetBackup's schedule AJAX Calls:
 *   list/get schedules, save (create/edit), delete.
 *
 * ManageSchedule is isset-gated, so save-schedule is safe for partial edits.
 * Omit id (or 0) to create; pass a real id to edit.
 *
 * Gated by manage_options; delete requires confirm:true. Built on JetBackup
 * 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Schedules extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_list_schedules();
        $this->register_get_schedule();
        $this->register_save_schedule();
        $this->register_delete_schedule();
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

    /* --------------------------- list-schedules ------------------------- */

    private function register_list_schedules() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-schedules', [
            'label'       => 'List JetBackup Schedules',
            'category'    => 'atarim',
            'description' => 'List configured JetBackup backup schedules (paginated). Optional skip, limit (1-100, default 50).',
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
                return AVCF_JetBackup_Helpers::invoke( 'ListSchedules', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- get-schedule -------------------------- */

    private function register_get_schedule() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-schedule', [
            'label'       => 'Get JetBackup Schedule',
            'category'    => 'atarim',
            'description' => 'Get a single JetBackup schedule by id.',
            'input_schema'  => $this->id_schema( 'Schedule id.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'schedule id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'GetSchedule', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- save-schedule ------------------------- */

    private function register_save_schedule() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-save-schedule', [
            'label'       => 'Save JetBackup Schedule',
            'category'    => 'atarim',
            'description' => 'Create or update a backup schedule. Omit id (or 0) to create; pass a real id to edit. Only fields provided are changed. '
                           . 'type: 1=Hourly, 2=Daily, 3=Weekly, 4=Monthly, 6=After Job Done. '
                           . 'intervals depends on type — Hourly: one of 1,2,3,4,6,8,12 (every N hours); '
                           . 'Daily: array of weekday numbers 1-7; Monthly: one of 1,7,14,21,28 (day of month); Weekly: 1. '
                           . 'backup_id attaches the schedule to that backup job.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'        => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Schedule id to edit; omit or 0 to create.' ],
                    'name'      => [ 'type' => 'string' ],
                    'type'      => [ 'type' => 'integer', 'enum' => [ 1, 2, 3, 4, 6 ], 'description' => '1 Hourly, 2 Daily, 3 Weekly, 4 Monthly, 6 After Job Done.' ],
                    'intervals' => [ 'oneOf' => [ [ 'type' => 'integer' ], [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ], 'description' => 'Interval for the type (see description).' ],
                    'backup_id' => [ 'type' => 'integer', 'description' => 'Backup job id this schedule runs.' ],
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
                if ( array_key_exists( 'name', $input ) ) {
                    $payload['name'] = (string) $input['name'];
                }
                if ( array_key_exists( 'type', $input ) ) {
                    $payload['type'] = (int) $input['type'];
                }
                if ( array_key_exists( 'intervals', $input ) ) {
                    $iv = $input['intervals'];
                    $payload['intervals'] = is_array( $iv ) ? array_values( array_map( 'intval', $iv ) ) : (int) $iv;
                }
                if ( array_key_exists( 'backup_id', $input ) ) {
                    $payload['backup_id'] = (int) $input['backup_id'];
                }

                if ( empty( $payload ) ) {
                    return [ 'success' => false, 'message' => 'No schedule fields provided.', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( 'ManageSchedule', $payload );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- delete-schedule ------------------------ */

    private function register_delete_schedule() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-delete-schedule', [
            'label'       => 'Delete JetBackup Schedule',
            'category'    => 'atarim',
            'description' => 'Delete a backup schedule by id. Destructive: requires confirm:true.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Schedule id to delete.' ],
                    'confirm' => [ 'type' => 'boolean', 'description' => 'Must be true to proceed.' ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'schedule id to delete' ); }
                if ( ! AVCF_JetBackup_Helpers::confirmed( $input ) ) { return AVCF_JetBackup_Helpers::confirm_required_response( 'delete schedule' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DeleteSchedule', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }
}
