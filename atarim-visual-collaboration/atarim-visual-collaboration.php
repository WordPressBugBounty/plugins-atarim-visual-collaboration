<?php
/*
 * Plugin Name: Atarim - Visual Feedback, Review & AI Collaboration
 * Description: Make collecting feedback on WordPress sites MUCH faster and easier, with the visual collaboration tool used on over 120,000 websites worldwide.
 * Version: 4.3.5
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

require_once(plugin_dir_path(__FILE__) . 'includes/class-define-constant.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-functions.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-ajax-functions.php');

if(is_admin()) {
    require_once(AVCF_PLUGIN_DIR . 'admin/class-avcf-settings.php');
    require_once(AVCF_PLUGIN_DIR . 'admin/class-user-meta.php');
}

// Load text domain for translations
function avcf_load_textdomain() {
    load_plugin_textdomain(AVCF_PLUGIN_NAME, false, dirname(AVCF_PLUGIN_BASE) . '/languages');
}
add_action('plugins_loaded', 'avcf_load_textdomain');

require_once(plugin_dir_path(__FILE__) . 'includes/inject-script.php');
