<?php
/**
 * JetBackup — Settings ability cluster.
 *
 * A get + manage pair for each of JetBackup's nine settings areas (general,
 * automation, integrations, logging, maintenance, notifications, performance,
 * security, updates), plus send-test-email.
 *
 * Every ManageSettings* call is isset-gated, so manage-* takes a `settings`
 * object keyed by JetBackup's own field names and only the keys you include are
 * changed. Call the matching get-* first to see current values/keys.
 *
 * NOTE: manage-security-settings carries ABILITIES_ENABLED — JetBackup's own
 * "Abilities API" toggle. Because these wrappers call JetBackup's internals
 * directly (not through JetBackup's ability layer), this works even when that
 * toggle is currently off.
 *
 * Gated by manage_options. Built on JetBackup 3.1.23.x; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Abilities_JetBackup_Settings extends AVCF_Abilities_Base {

    /** @var AVCF_JetBackup_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_JetBackup_Detector();
    }

    /**
     * Settings areas: slug => [ GetClass, ManageClass, Label, documented field keys ].
     * Field keys are the request keys JetBackup reads (i.e. the constant VALUES —
     * note Performance uses PERFORMANCE_EXECUTION_TIME, Integrations uses the
     * lowercase "integrations" object).
     */
    private function areas() {
        return [
            'general' => [
                'GetSettingsGeneral', 'ManageSettingsGeneral', 'General',
                'TIMEZONE, JETBACKUP_INTEGRATION, ADMIN_TOP_MENU_INTEGRATION, DISPLAY_LOCAL_FREE_DISK_SPACE, COMMUNITY_LANGUAGES, MANUAL_BACKUPS_RETENTION, IMPORTED_BACKUPS_RETENTION, PHP_CLI_LOCATION, ALTERNATE_WP_CONFIG_LOCATION, MYSQL_DEFAULT_PORT',
            ],
            'automation' => [
                'GetSettingsAutomation', 'ManageSettingsAutomation', 'Automation',
                'CRONS, CRON_STATUS, WP_CRON, HEARTBEAT, HEARTBEAT_TTL',
            ],
            'integrations' => [
                'GetSettingsIntegrations', 'ManageSettingsIntegrations', 'Integrations',
                'integrations (object of booleans: Elementor, Supercache, Woocommerce, Autoptimize)',
            ],
            'logging' => [
                'GetSettingsLogging', 'ManageSettingsLogging', 'Logging',
                'DEBUG, LOG_ROTATE',
            ],
            'maintenance' => [
                'GetSettingsMaintenance', 'ManageSettingsMaintenance', 'Maintenance',
                'CONFIG_EXPORT_ROTATE, MAINTENANCE_DOWNLOAD_ITEMS_TTL, MAINTENANCE_DOWNLOAD_LIMIT, MAINTENANCE_QUEUE_ALERTS_TTL, MAINTENANCE_QUEUE_HOURS_TTL',
            ],
            'notifications' => [
                'GetSettingsNotifications', 'ManageSettingsNotifications', 'Notifications',
                'EMAILS, ALTERNATE_EMAIL, NOTIFICATION_LEVELS_FREQUENCY',
            ],
            'performance' => [
                'GetSettingsPerformance', 'ManageSettingsPerformance', 'Performance',
                'PERFORMANCE_EXECUTION_TIME, READ_CHUNK_SIZE, SQL_CLEANUP_REVISIONS, USE_DEFAULT_EXCLUDES, USE_DEFAULT_DB_EXCLUDES, EXCLUDE_NESTED_SITES, GZIP_COMPRESS_ARCHIVE, GZIP_COMPRESS_DB',
            ],
            'security' => [
                'GetSettingsSecurity', 'ManageSettingsSecurity', 'Security',
                'ABILITIES_ENABLED (JetBackup\'s Abilities API toggle), MFA_ENABLED, MFA_ALLOW_CLI, MFA_ALLOW_ABILITIES, DAILY_CHECKSUM_CHECK',
            ],
            'updates' => [
                'GetSettingsUpdates', 'ManageSettingsUpdates', 'Updates',
                'UPDATE_TIER (one of: release, rc, edge, alpha)',
            ],
        ];
    }

    public function register() {
        if ( ! $this->detector->avcf_jetbackup_is_available() ) {
            return;
        }
        foreach ( $this->areas() as $slug => $a ) {
            $this->register_get_settings( $slug, $a[0], $a[2], $a[3] );
            $this->register_manage_settings( $slug, $a[1], $a[2], $a[3] );
        }
        $this->register_send_test_email();
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

    /* --------------------------- get-*-settings ------------------------- */

    private function register_get_settings( $slug, $get_class, $label, $keys ) {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-get-' . $slug . '-settings', [
            'label'       => sprintf( 'Get JetBackup %s Settings', $label ),
            'category'    => 'atarim',
            'description' => sprintf( 'Get JetBackup %s settings. Fields: %s.', $label, $keys ),
            'input_schema'  => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) use ( $get_class ) {
                return AVCF_JetBackup_Helpers::invoke( $get_class, [] );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- manage-*-settings ----------------------- */

    private function register_manage_settings( $slug, $manage_class, $label, $keys ) {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-manage-' . $slug . '-settings', [
            'label'       => sprintf( 'Manage JetBackup %s Settings', $label ),
            'category'    => 'atarim',
            'description' => sprintf(
                'Update JetBackup %s settings. Pass a "settings" object keyed by JetBackup field names; only the keys you include are changed. Call jetbackup-get-%s-settings first to see current values. Fields: %s.',
                $label, $slug, $keys
            ),
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'settings' => [
                        'type'                 => 'object',
                        'description'          => sprintf( 'Map of JetBackup %s field names to values. Keys: %s.', $label, $keys ),
                        'additionalProperties' => true,
                    ],
                ],
                'required'             => [ 'settings' ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) use ( $manage_class ) {
                $input = (array) $input;
                $settings = [];
                if ( isset( $input['settings'] ) ) {
                    if ( is_array( $input['settings'] ) ) {
                        $settings = $input['settings'];
                    } elseif ( is_object( $input['settings'] ) ) {
                        $settings = (array) $input['settings'];
                    }
                }
                if ( empty( $settings ) ) {
                    return [ 'success' => false, 'message' => 'Provide a non-empty "settings" object.', 'data' => [] ];
                }
                return AVCF_JetBackup_Helpers::invoke( $manage_class, $settings );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* --------------------------- send-test-email ------------------------ */

    private function register_send_test_email() {
        $self = $this;
        wp_register_ability( 'atarim/jetbackup-send-test-email', [
            'label'       => 'Send JetBackup Test Email',
            'category'    => 'atarim',
            'description' => 'Send a JetBackup test notification email. Optional alternate_email overrides the recipient.',
            'input_schema'  => [
                'type'                 => 'object',
                'properties'           => [
                    'alternate_email' => [ 'type' => 'string', 'description' => 'Optional recipient email address.' ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => $this->out_schema(),
            'execute_callback' => function( $input = [] ) {
                $input = (array) $input;
                $data  = [];
                if ( ! empty( $input['alternate_email'] ) ) {
                    $data['alternate_email'] = (string) $input['alternate_email'];
                }
                return AVCF_JetBackup_Helpers::invoke( 'SendTestEmail', $data );
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}
