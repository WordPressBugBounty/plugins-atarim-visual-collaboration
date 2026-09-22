<?php

if ( ! defined('ABSPATH') ) {
    exit;
}

add_action('rest_api_init', function () {

    register_rest_route('atarim/v1', '/status', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $connected = get_option('avc_collab_active', 'no') === 'yes';
            $bound     = get_option('avc_license', '') === 'valid'
                && get_option('avc_site_id', '') !== '';

            return [
                'installed'    => true,
                'connected'    => $connected,
                'bound'        => $bound,
                'version'      => defined('AVCF_VERSION') ? AVCF_VERSION : null,
                'settings_url' => admin_url('options-general.php?page=atarim-visual-collaboration'),
                'site_url'     => site_url(),
            ];
        },
    ]);

    register_rest_route('atarim/v1', '/disconnect', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $request ) {
            $site_id = sanitize_text_field( (string) $request->get_param('site_id') );
            $secret  = (string) $request->get_param('secret_token');

            $stored_site_id = (string) get_option('avc_site_id', '');
            $stored_secret  = (string) get_option('avc_atarim_secret_token', '');

            $ok = $stored_site_id !== '' && $stored_secret !== ''
                && $site_id !== '' && $secret !== ''
                && hash_equals( $stored_site_id, $site_id )
                && hash_equals( $stored_secret, $secret );

            if ( ! $ok ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Authentication failed.' ], 403 );
            }

            update_option( 'avc_collab_active', 'no' );

            return new WP_REST_Response( [ 'success' => true, 'message' => 'Collaboration disabled.' ], 200 );
        },
    ]);
});
