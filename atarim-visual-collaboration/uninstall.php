<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// remove options when plugin uninstalled.
delete_option( 'wpf_decr_checksum' );
delete_option( 'wpf_decr_key' );
delete_option( 'wpf_license_expires' );
delete_option( 'wpf_license' );
delete_option( 'wpf_license_key' );
delete_option( 'wpf_check_license_date' );
