<?php
/**
 * JetBackup detector + MCP blocklist contribution.
 *
 * Eager-loaded. Reports whether JetBackup for WordPress is active, and — when it
 * is — contributes JetBackup's own native jetbackup/* abilities to the Atarim MCP
 * blocklist (avcf_mcp_blocked_abilities). That way the agent only ever sees our
 * unified atarim/jetbackup-* surface and never a duplicate, regardless of
 * JetBackup's own "Abilities" security toggle or the site's WordPress version.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_JetBackup_Detector {

    /**
     * Is JetBackup active and usable? Guard on its bootstrap constant plus a core
     * AJAX Call class we actually invoke, so our wrappers only register when the
     * internals we depend on are genuinely present.
     */
    public function avcf_jetbackup_is_available() {
        return defined( '__JETBACKUP__' )
            && class_exists( '\JetBackup\JetBackup' )
            && class_exists( '\JetBackup\Ajax\Calls\ListBackups' );
    }

    /**
     * JetBackup's own native abilities (registered via the WordPress Abilities API
     * in JetBackup\Wordpress\Abilities). We rebuild each of these under the
     * atarim/jetbackup-* namespace and block the originals to avoid duplicates.
     *
     * @return string[]
     */
    public static function native_ability_names() {
        return [
            'jetbackup/list-backups',
            'jetbackup/list-backup-jobs',
            'jetbackup/list-destinations',
            'jetbackup/list-schedules',
            'jetbackup/list-queue-items',
            'jetbackup/get-backup',
            'jetbackup/get-backup-job',
            'jetbackup/get-queue-item',
            'jetbackup/get-dashboard',
            'jetbackup/get-system-info',
        ];
    }

    /**
     * Filter callback for avcf_mcp_blocked_abilities: append JetBackup's natives to
     * the blocklist, but only when JetBackup is present (a no-op otherwise, so
     * sites without JetBackup are unaffected).
     *
     * @param array $blocked
     * @return array
     */
    public static function filter_blocklist( $blocked ) {
        $blocked = is_array( $blocked ) ? $blocked : [];

        $detector = new self();
        if ( $detector->avcf_jetbackup_is_available() ) {
            $blocked = array_values( array_unique( array_merge( $blocked, self::native_ability_names() ) ) );
        }

        return $blocked;
    }
}

// Contribute JetBackup's natives to the MCP blocklist when present (detection-conditional).
if ( function_exists( 'add_filter' ) ) {
    add_filter( 'avcf_mcp_blocked_abilities', [ 'AVCF_JetBackup_Detector', 'filter_blocklist' ] );
}
