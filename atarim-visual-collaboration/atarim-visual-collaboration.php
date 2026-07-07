<?php
/*
 * Plugin Name: Atarim - Visual Feedback, Review & AI Collaboration
 * Description: Make collecting feedback on WordPress sites MUCH faster and easier, with the visual collaboration tool used on over 120,000 websites worldwide.
 * Version: 5.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Atarim
 * Author URI: https://atarim.io/
 * License: GPL 3.0 or later
 * Text Domain: atarim-visual-collaboration
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('AVCF_PLUGIN_NAME', trim(dirname(plugin_basename(__FILE__)), '/'));
define('AVCF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AVCF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AVCF_PLUGIN_BASE', plugin_basename(__FILE__));

// Load Jetpack autoloader for MCP Adapter and dependencies
$avcf_autoloader = AVCF_PLUGIN_DIR . 'vendor/autoload_packages.php';
if ( file_exists( $avcf_autoloader ) ) {
    require_once $avcf_autoloader;
}

require_once(plugin_dir_path(__FILE__) . 'includes/class-define-constant.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-functions.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-ajax-functions.php');
require_once(plugin_dir_path(__FILE__) . 'includes/do-it.php');

if(is_admin()) {
    require_once(AVCF_PLUGIN_DIR . 'admin/class-avcf-settings.php');
    require_once(AVCF_PLUGIN_DIR . 'admin/class-avcf-offboarding.php');
    require_once(AVCF_PLUGIN_DIR . 'admin/class-user-meta.php');
    require_once(AVCF_PLUGIN_DIR . 'admin/class-avcf-upgrade-notice.php');
    new AVCF_Upgrade_Notice();
}

// Load text domain for translations
function avcf_load_textdomain() {
    load_plugin_textdomain(AVCF_PLUGIN_NAME, false, dirname(AVCF_PLUGIN_BASE) . '/languages');
}
add_action('plugins_loaded', 'avcf_load_textdomain');

require_once(plugin_dir_path(__FILE__) . 'includes/inject-script.php');
require_once( AVCF_PLUGIN_DIR . 'doit/class-avcf-mcp-auth.php' );

// Diagnostic endpoint — loads unconditionally so the Atarim backend can
// query site health even when MCP itself is unavailable. This is the
// whole point: when MCP doesn't work, this is how the dashboard tells
// the user why.
require_once( AVCF_PLUGIN_DIR . 'doit/class-avcf-diagnostic.php' );
new AVCF_Diagnostic();

// Load every third-party / cluster class and bootstrap the MCP layer.
// All cluster require_once lines live in the loader, so this file stays stable
// as clusters are added or removed.
require_once( AVCF_PLUGIN_DIR . 'doit/avcf-cluster-loader.php' );
