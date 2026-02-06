<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AVC_Constants {

    public function __construct() {
        // Define constants
        $this->avc_define_constant();
    }

    public function avc_define_constant() {
        define( 'AVC_VERSION', '4.3.2' );
        define( 'AVC_SITE_URL', site_url() );
        define( 'AVC_HOME_URL', home_url() );
        define( 'AVC_MAIN_SITE_URL', 'https://atarim.io' );
        define( 'AVC_APP_SITE_URL', 'https://app.atarim.io' );
        define( 'AVC_CRM_API', 'https://api.atarim.io/' );
        define( 'AVC_LEARN_SITE_URL', 'https://academy.atarim.io' );
    }
}

new AVC_Constants();

