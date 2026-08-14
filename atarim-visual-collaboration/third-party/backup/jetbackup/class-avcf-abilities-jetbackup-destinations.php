<?php
/**
 * JetBackup — Destinations ability cluster.
 *
 * Namespaced atarim/jetbackup-* over JetBackup's destination AJAX Calls:
 *   list/get destinations, save (create/edit), delete, toggle enabled,
 *   validate (test connection), and toggle export-config membership.
 *
 * ManageDestination is isset-gated for most fields (so partial edits are safe
 * and omitted options/credentials are left untouched), BUT it applies
 * export_config unconditionally — so on edit we read and re-send the current
 * value unless the caller overrides it, to avoid silently clearing it. Saving a
 * destination also registers + connects it (a live connection test), and
 * non-local destination types require a valid JetBackup license.
 *
 * The options object is provider-specific (S3/FTP/SFTP/Google Drive/OneDrive/
 * Dropbox/Box/pCloud/JetBackup Storage/Local): read an existing destination with
 * jetbackup-get-destination to see the exact type + options shape before saving.
 *
 * Gated by manage_options; delete requires confirm:true. Built on JetBackup
 * 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Destinations extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_list_destinations();
        $this->register_get_destination();
        $this->register_save_destination();
        $this->register_delete_destination();
        $this->register_toggle_destination();
        $this->register_validate_destination();
        $this->register_toggle_destination_export_config();
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

    /* ------------------------- list-destinations ------------------------ */

    private function register_list_destinations() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-list-destinations', [
            'label'       => 'List JetBackup Destinations',
            'category'    => 'atarim',
            'description' => 'List configured JetBackup backup destinations (paginated). Optional skip, limit (1-100, default 50).',
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
                return AVCF_JetBackup_Helpers::invoke( 'ListDestinations', AVCF_JetBackup_Helpers::paginate( (array) $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- get-destination ------------------------- */

    private function register_get_destination() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-destination', [
            'label'       => 'Get JetBackup Destination',
            'category'    => 'atarim',
            'description' => 'Get a single JetBackup destination by id (shows its type and options shape — read this before editing with jetbackup-save-destination). Sensitive credential fields are masked by JetBackup.',
            'input_schema'  => $this->id_schema( 'Destination id.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'destination id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'GetDestination', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- save-destination ------------------------ */

    private function register_save_destination() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-save-destination', [
            'label'       => 'Save JetBackup Destination',
            'category'    => 'atarim',
            'description' => 'Create or update a backup destination. Omit id (or 0) to create; pass a real id to edit. Only fields provided are changed (omit options to leave credentials untouched). Saving performs a live connection test; non-local destination types require a valid JetBackup license. options is provider-specific — read an existing destination with jetbackup-get-destination for the correct type and options keys.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'            => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Destination id to edit; omit or 0 to create.' ],
                    'name'          => [ 'type' => 'string' ],
                    'type'          => [ 'type' => 'string', 'description' => 'Destination provider type (e.g. as shown by get-destination). Required when creating.' ],
                    'path'          => [ 'type' => 'string', 'description' => 'Remote path/prefix on the destination.' ],
                    'notes'         => [ 'type' => 'string' ],
                    'read_only'     => [ 'type' => 'boolean' ],
                    'chunk_size'    => [ 'type' => 'integer', 'description' => 'Upload chunk size.' ],
                    'free_disk'     => [ 'type' => 'integer', 'description' => 'Reserved free-disk threshold.' ],
                    'options'       => [ 'type' => 'object', 'description' => 'Provider-specific settings/credentials. Omit to leave unchanged on edit.' ],
                    'export_config' => [ 'type' => 'integer', 'description' => 'Advanced: config-export membership flag (normally toggled via jetbackup-toggle-destination-export-config).' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input   = (array) $input;
                $payload = [];
                $id      = ! empty( $input['id'] ) ? (int) $input['id'] : 0;

                if ( $id ) {
                    $payload[ AVCF_JetBackup_Helpers::ID_FIELD ] = $id;
                }
                if ( array_key_exists( 'name', $input ) )       { $payload['name'] = (string) $input['name']; }
                if ( array_key_exists( 'type', $input ) )       { $payload['type'] = (string) $input['type']; }
                if ( array_key_exists( 'path', $input ) )       { $payload['path'] = (string) $input['path']; }
                if ( array_key_exists( 'notes', $input ) )      { $payload['notes'] = (string) $input['notes']; }
                if ( array_key_exists( 'read_only', $input ) )  { $payload['read_only'] = filter_var( $input['read_only'], FILTER_VALIDATE_BOOLEAN ); }
                if ( array_key_exists( 'chunk_size', $input ) ) { $payload['chunk_size'] = (int) $input['chunk_size']; }
                if ( array_key_exists( 'free_disk', $input ) )  { $payload['free_disk'] = (int) $input['free_disk']; }
                if ( array_key_exists( 'options', $input ) && is_array( $input['options'] ) ) {
                    $payload['options'] = (object) $input['options'];
                } elseif ( array_key_exists( 'options', $input ) && is_object( $input['options'] ) ) {
                    $payload['options'] = $input['options'];
                }

                // export_config is applied unconditionally by ManageDestination; on edit,
                // preserve the current value unless the caller overrides it, so a routine
                // save doesn't silently clear it.
                if ( array_key_exists( 'export_config', $input ) ) {
                    $payload['export_config'] = (int) $input['export_config'];
                } elseif ( $id && class_exists( '\JetBackup\Destination\Destination' ) ) {
                    try {
                        $existing = new \JetBackup\Destination\Destination( $id );
                        if ( $existing->getId() ) {
                            $payload['export_config'] = (int) $existing->isExportConfig();
                        }
                    } catch ( \Throwable $e ) {
                        // Leave it out; JetBackup will default it.
                    }
                }

                if ( empty( $payload ) ) {
                    return [ 'success' => false, 'message' => 'No destination fields provided.', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( 'ManageDestination', $payload );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------- delete-destination ----------------------- */

    private function register_delete_destination() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-delete-destination', [
            'label'       => 'Delete JetBackup Destination',
            'category'    => 'atarim',
            'description' => 'Delete a backup destination by id. Destructive: requires confirm:true.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Destination id to delete.' ],
                    'confirm' => [ 'type' => 'boolean', 'description' => 'Must be true to proceed.' ],
                ],
                'required'             => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'destination id to delete' ); }
                if ( ! AVCF_JetBackup_Helpers::confirmed( $input ) ) { return AVCF_JetBackup_Helpers::confirm_required_response( 'delete destination' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DeleteDestination', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------- toggle-destination ----------------------- */

    private function register_toggle_destination() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-toggle-destination', [
            'label'       => 'Toggle JetBackup Destination',
            'category'    => 'atarim',
            'description' => 'Toggle a destination\'s enabled state (flips enabled <-> disabled) and returns the new state.',
            'input_schema'  => $this->id_schema( 'Destination id to enable/disable.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'destination id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'EnableDestination', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ------------------------ validate-destination ---------------------- */

    private function register_validate_destination() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-validate-destination', [
            'label'       => 'Validate JetBackup Destination',
            'category'    => 'atarim',
            'description' => 'Test connectivity to an existing saved destination by id.',
            'input_schema'  => $this->id_schema( 'Destination id to validate.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'destination id to validate' ); }
                return AVCF_JetBackup_Helpers::invoke( 'ValidateDestination', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------- toggle-destination-export-config ------------------ */

    private function register_toggle_destination_export_config() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-toggle-destination-export-config', [
            'label'       => 'Toggle JetBackup Destination Config Export',
            'category'    => 'atarim',
            'description' => 'Toggle whether a destination is included in the default configuration-backup job (flips on <-> off).',
            'input_schema'  => $this->id_schema( 'Destination id.' ),
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                if ( empty( $input['id'] ) ) { return $this->missing_id( 'destination id' ); }
                return AVCF_JetBackup_Helpers::invoke( 'DestinationSetExportConfig', AVCF_JetBackup_Helpers::id_payload( $input ) );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
