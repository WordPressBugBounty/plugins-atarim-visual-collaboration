<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class AVCF_Constants {

    public function __construct() {
        // Define constants
        $this->avcf_define_constant();
    }

    public function avcf_define_constant() {
        define( 'AVCF_VERSION', '4.3.3' );
        define( 'AVCF_SITE_URL', site_url() );
        define( 'AVCF_HOME_URL', home_url() );
        define( 'AVCF_MAIN_SITE_URL', 'https://atarim.io' );
        define( 'AVCF_APP_SITE_URL', 'https://app.atarim.io' );
        define( 'AVCF_CRM_API', 'https://api.atarim.io/' );
        define( 'AVCF_LEARN_SITE_URL', 'https://academy.atarim.io' );
    }
}

new AVCF_Constants();

