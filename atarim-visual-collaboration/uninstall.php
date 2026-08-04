<?php
/**
 * Atarim uninstall routine.
 *
 * Runs only when the plugin is deleted from WordPress. It removes all plugin
 * data ONLY if the user explicitly chose "Delete all data" in the delete modal
 * (stored in the avc_remove_data_on_uninstall option). If that option is not
 * '1' (the default, including bulk/WP-CLI deletes), nothing is touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! defined( 'AVCF_UNINSTALL_PREF_OPTION' ) ) {
    define( 'AVCF_UNINSTALL_PREF_OPTION', 'avc_remove_data_on_uninstall' );
}

/**
 * Wipe every option, user meta key and transient the plugin owns for the
 * current site context.
 */
function avcf_uninstall_cleanup_site() {
    global $wpdb;

    $options = array(
        // Current (avc_*) plugin options.
        'avc_license',
        'avc_site_id',
        'avc_atarim_secret_token',
        'avc_initial_setup_complete',
        'avc_collab_active',
        'avc_website_developer',
        'avc_selected_role',
        'avc_enable_doit',
        'avc_collab_logo',
        'avc_collab_favicon',
        'avc_collab_color',
        'avc_plugin_activation_redirect',
        // Legacy (wpf_*) options from earlier plugin versions.
        'wpf_initial_setup_complete',
        'wpf_site_id',
        'wpf_selcted_role',
        'wpf_token',
        'wpf_login',
        'wpf_username',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // User meta written across all users.
    $user_meta_keys = array(
        'avc_user_type',
        'avc_consent_status',
    );

    foreach ( $user_meta_keys as $meta_key ) {
        delete_metadata( 'user', 0, $meta_key, '', true );
    }

    // Site-visibility transients use a dynamic suffix (md5 of the site id),
    // so clear them by prefix directly from the options table.
    $like = $wpdb->esc_like( '_transient_avc_site_visibility_' ) . '%';
    $like_timeout = $wpdb->esc_like( '_transient_timeout_avc_site_visibility_' ) . '%';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $like_timeout
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery

    // Finally remove the preference flag itself.
    delete_option( AVCF_UNINSTALL_PREF_OPTION );
}

$avcf_should_remove = get_option( AVCF_UNINSTALL_PREF_OPTION );

if ( '1' !== $avcf_should_remove ) {
    // User chose to keep data (or deleted without choosing). Leave everything.
    return;
}

if ( is_multisite() ) {
    $site_ids = get_sites(
        array(
            'fields' => 'ids',
            'number' => 0,
        )
    );

    foreach ( $site_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        avcf_uninstall_cleanup_site();
        restore_current_blog();
    }
} else {
    avcf_uninstall_cleanup_site();
}
