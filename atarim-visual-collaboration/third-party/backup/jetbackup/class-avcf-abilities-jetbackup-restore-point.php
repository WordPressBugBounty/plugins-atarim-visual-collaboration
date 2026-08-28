<?php
/**
 * JetBackup — Restore point tracking.
 *
 * Two abilities that talk to Atarim rather than to JetBackup directly:
 *
 *   - jetbackup-get-restore-point   what safety net this site has, and what a
 *                                   rollback to it would actually recover
 *   - jetbackup-arm-restore-point   prepare one before making changes
 *
 * Arming does NOT modify the site. Atarim reuses the newest snapshot when it is
 * under 24h old (otherwise runs a fresh backup first), stages a restore, and
 * records the standalone recovery link when JetBackup produces it. That link is
 * held server-side and never returned here — it restores the site with no
 * further authentication.
 *
 * Gated by manage_options, like the rest of the cluster.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Restore_Point extends AVCF_Abilities_Base {

    const ENDPOINT = 'v1/jetbackup/restore-point';

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        $this->register_get_restore_point();
        $this->register_arm_restore_point();
    }

    /* ------------------------------ shared ----------------------------- */

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

    /* ------------------------ get-restore-point ------------------------ */

    private function register_get_restore_point() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-restore-point', [
            'label'       => 'Get JetBackup Restore Point',
            'category'    => 'atarim',
            'description' => 'Check whether this site has a usable restore point, and what a rollback to it would actually recover. Call this BEFORE editing the site, not after — it tells you whether a safety net exists while you can still do something about it. Returns exists, status, backup_type (full|files|database), backup_scope and age_hours; it never returns the recovery link itself. Coverage is containment, not equality: a full point covers file AND database changes, a files point covers only file changes, a database point covers only database changes. If backup_scope contains an exclude list and what you are about to change falls under one of those paths, the point does NOT cover your edit whatever backup_type says. status "in_progress" means another session is arming one right now, so the previous point is already gone and the site is unprotected — wait rather than arming a second one.',
            'input_schema'  => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                return AVCF_JetBackup_Helpers::atarim_call( AVCF_Abilities_JetBackup_Restore_Point::ENDPOINT, 'GET' );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ],
        ] );
    }

    /* ------------------------ arm-restore-point ------------------------ */

    private function register_arm_restore_point() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-arm-restore-point', [
            'label'       => 'Arm JetBackup Restore Point',
            'category'    => 'atarim',
            'description' => 'Prepare a restore point so the work you are about to do can be rolled back. This does NOT change the site — it stages a recovery link and stops. Call jetbackup-get-restore-point first: if a usable point already covers what you are about to change, do not arm another. Set backup_type to what a rollback must be able to recover: full (files and database), files, or database. Narrow it with include_paths, or carve out with exclude_paths / exclude_tables. CRITICAL: JetBackup holds only ONE restore point per site, so arming a new one destroys the existing one. Never replace a broader point with a narrower one — if the site already has a full point and you only need files, keep it and proceed. Arming database over a full point silently throws away the file safety net. Runs asynchronously and returns straight away with status "in_progress"; the point is not usable until it reads "ready", so poll jetbackup-get-restore-point if you need to confirm before changing anything. If the site\'s newest backup is under 24 hours old it is reused, otherwise a fresh backup runs first and this takes longer.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'backup_type'    => [ 'type' => 'string', 'enum' => [ 'full', 'files', 'database' ], 'description' => 'What a rollback must be able to recover.' ],
                    'include_paths'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Restrict a files restore to these home-directory paths. Anything outside them is not covered.' ],
                    'exclude_paths'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Carve these paths out of a files restore. Edits inside an excluded path are NOT covered by the resulting restore point.' ],
                    'exclude_tables' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Carve these database tables out of the restore.' ],
                ],
                'required'             => [ 'backup_type' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;

                if ( empty( $input['backup_type'] ) ) {
                    return [ 'success' => false, 'message' => 'Missing backup_type. Use full, files, or database.', 'data' => [] ];
                }

                $body = [ 'backup_type' => strtolower( (string) $input['backup_type'] ) ];

                foreach ( [ 'include_paths', 'exclude_paths', 'exclude_tables' ] as $key ) {
                    if ( ! empty( $input[ $key ] ) ) {
                        $body[ $key ] = array_values( array_map( 'strval', (array) $input[ $key ] ) );
                    }
                }

                return AVCF_JetBackup_Helpers::atarim_call( AVCF_Abilities_JetBackup_Restore_Point::ENDPOINT, 'POST', $body );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ] ],
        ] );
    }
}