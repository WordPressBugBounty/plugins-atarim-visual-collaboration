<?php
/*
 * Plugin Name: Visual Feedback, Review & AI Collaboration Tool For WordPress - Atarim
 * Description: Make collecting feedback on WordPress sites MUCH faster and easier, with the visual collaboration tool used on over 120,000 websites worldwide.
 * Version: 4.3.2
 * Requires at least: 5.0
 * Require PHP: 7.4
 * Author: Atarim
 * Author URI: https://atarim.io/
 * License: GPL 3.0 or later
 * Update URI: https://wordpress.org/plugins/atarim-visual-collaboration/
 * Text Domain: atarim-visual-collaboration
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('AVC_PLUGIN_NAME', trim(dirname(plugin_basename(__FILE__)), '/'));
define('AVC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AVC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AVC_PLUGIN_BASE', plugin_basename(__FILE__));

require_once(plugin_dir_path(__FILE__) . 'includes/class-define-constant.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-functions.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-ajax-functions.php');

if(is_admin()) {
    require_once(AVC_PLUGIN_DIR . 'admin/class-avc-settings.php');
    require_once(AVC_PLUGIN_DIR . 'admin/class-user-meta.php');
}

// Load text domain for translations
function avc_load_textdomain() {
    load_plugin_textdomain(AVC_PLUGIN_NAME, false, dirname(AVC_PLUGIN_BASE) . '/languages');
}
add_action('plugins_loaded', 'avc_load_textdomain');

require_once(plugin_dir_path(__FILE__) . 'includes/inject-script.php');
