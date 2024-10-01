<?php
/*
 * Plugin Name: Atarim: Visual Website Collaboration, Feedback & Workflow Management
 * Description: Atarim Visual Collaboration makes it easy and efficient to collaborate on websites with your clients, internal team, contractors…anyone! It’s used by nearly 10,000 agencies and freelancers worldwide on over 120,000 websites.
 * Version: 4.0.6
 * Requires at least: 5.0
 * Require PHP: 7.4
 * Author: Atarim
 * Author URI: https://atarim.io/
 * License: GPL 3.0 or later
 * Update URI: https://wordpress.org/plugins/atarim-visual-collaboration/
 * Text Domain: atarim-visual-collaboration
 * Domain Path: /languages
 */

/**
 * If this file is called directly, abort.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}
if ( ! defined( 'WPF_PLUGIN_NAME' ) ) {
    define( 'WPF_PLUGIN_NAME', trim( dirname( plugin_basename( __FILE__ ) ), '/' ) );
}
if ( ! defined( 'WPF_PLUGIN_DIR' ) ) {
    define( 'WPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPF_PLUGIN_URL' ) ) {
    define( 'WPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPF_VERSION' ) ) {
    define( 'WPF_VERSION', '4.0.6' );
}

define( 'SCOPER_ALL_UPLOADS_EDITABLE ', true );

if ( ! defined( 'WPF_SITE_URL' ) ) {
    define( 'WPF_SITE_URL', site_url() );
}
if ( ! defined( 'WPF_HOME_URL' ) ) {
    define( 'WPF_HOME_URL', home_url() );
}

// site urls.
define( 'WPF_MAIN_SITE_URL', 'https://atarim.io' );
define( 'WPF_APP_SITE_URL', 'https://app.atarim.io' );
define( 'WPF_CRM_API', 'https://api.atarim.io/' );
define( 'WPF_LEARN_SITE_URL', 'https://academy.atarim.io' );

/*
 * Register hooks that are fired when the plugin is activated or deactivated.
 * When the plugin is deleted, the uninstall.php file is loaded.
 */
register_activation_hook( __FILE__, array( 'WP_Feedback', 'activate' ) );
/*
 * This function is used to redirect the users to the settings page on the activation of the plugin.
 *
 * @input String
 * @return Redirect
 */
function wpf_plugin_activation_redirect() {
    if ( get_option('wpf_plugin_do_activation_redirect', false ) ) {
        if( ! isset( $_GET['activate-multi'] ) ) {
            delete_option( 'wpf_plugin_do_activation_redirect' );
            $url = admin_url( 'admin.php?page=collaboration_page_settings' );
            wp_redirect( $url );
            exit;
        }
    }
}
add_action('admin_init', 'wpf_plugin_activation_redirect' );

register_deactivation_hook( __FILE__, array( 'WP_Feedback', 'deactivate' ) );

/**
 * Fired when the plugin is updated to insert unique token for guest collab link.
 * @author Pratap <email>
 * @version 3.17
 */
function wpf_plugins_update_completed( $upgrader_object, $options ) {
    // If an update has taken place and the updated type is plugins and the plugins element exists.
    if ( $options['action'] == 'update' && $options['type'] == 'plugin' && isset( $options['plugins'] ) ) {
        foreach( $options['plugins'] as $plugin ) {
            // Check to ensure it's our plugin
            if( $plugin == plugin_basename( __FILE__ ) ) {
                $object = new WP_Feedback();
                $object->call_guest_token();
                $object->remove_restrict_plugin();
            }
        }
    }
}
add_action( 'upgrader_process_complete', 'wpf_plugins_update_completed', 10, 2 );

function wpf_plugin_update_message( $plugin_data, $new_data ) {
    if( isset( $plugin_data['upgrade_notice'] ) ) {
        printf(
            '<div class="update-message">%s
            <br>
            Download it from <a href="plugin-install.php?tab=plugin-information&plugin=atarim-visual-collaboration&TB_iframe=true&width=600&height=550" >here</a>
            </div>',
            $plugin_data['upgrade_notice']
        );
    }
        
}
add_action( 'in_plugin_update_message-atarim-client-interface/wpfeedback.php', 'wpf_plugin_update_message', 10, 2 );


/**
 * Create the admin menu.
 * This function is used to register the admin menu for the Atarim.
 *
 * @input NULL
 * @return NULL
 */
function wp_feedback_admin_menu() {
    global $current_user;
    $wpf_powered_by = get_site_data_by_key( 'wpfeedback_powered_by' );

    $selected_roles = get_site_data_by_key( 'wpf_selcted_role' );
    $selected_roles = explode( ',', $selected_roles );

    $main_menu_id = 'collaboration_task_center';

    if ( array_intersect( $current_user->roles, $selected_roles ) || current_user_can( 'administrator' ) ) {
	    $wpf_user_type = wpf_user_type();

        $badge = '';
        if ( $wpf_powered_by == 'yes' ) {
            $wpf_main_menu_label = __( 'Collaborate', 'atarim-visual-collaboration' );
            $wpf_main_menu_icon = WPF_PLUGIN_URL . 'images/atarim-whitelabel.svg';
        } else {
            $wpf_main_menu_label = __( 'Collaborate', 'atarim-visual-collaboration' );
            $wpf_main_menu_icon = WPF_PLUGIN_URL . 'images/atarim_favicon_white.svg';
        }
        add_menu_page(
            __( $wpf_main_menu_label, 'atarim-visual-collaboration' ), __( $wpf_main_menu_label, 'atarim-visual-collaboration' ) . $badge, 'read', $main_menu_id, $main_menu_id, $wpf_main_menu_icon, 80
        );
        add_submenu_page(
            $main_menu_id, __( 'Tasks Center', 'atarim-visual-collaboration' ), __( 'Tasks Center', 'atarim-visual-collaboration' ), 'read', 'collaboration_task_center', 'collaboration_task_center'
        );
        if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) {
            add_submenu_page(
                $main_menu_id, __( 'Settings', 'atarim-visual-collaboration' ), __( 'Settings', 'atarim-visual-collaboration' ), 'read', 'collaboration_page_settings', 'collaboration_page_settings'
            );
        }
        if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) {
            add_submenu_page(
                $main_menu_id, __( 'Permissions', 'atarim-visual-collaboration' ), __( 'Permissions', 'atarim-visual-collaboration' ), 'read', 'collaboration_page_permissions', 'collaboration_page_permissions'
            );
        }
        if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) {
            add_submenu_page(
                $main_menu_id, __( 'Support', 'atarim-visual-collaboration' ), __( 'Support', 'atarim-visual-collaboration' ), 'read', 'https://atarim.io/help'
            );
        }
    }
}
add_action( 'admin_menu', 'wp_feedback_admin_menu' );

/*
 * This function is used to set the link for the "Settings" menu item.
 *
 * @input Array
 * @return Array
 */
function wpf_setting_action_links( $links ) {
    $links[] = '<a href="' . esc_url( get_admin_url( null, 'admin.php?page=collaboration_page_settings' ) ) . '">' . __( 'Settings', 'atarim-visual-collaboration' ) . '</a>';
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpf_setting_action_links' );

/*
 * This function is used used to include the page-settings template for the settings menu if the initial onboarding is already or include wpf_backend_initial_setup if not.
 *
 * @input NULL
 * @return NULL
 */
function collaboration_page_settings() {
    global $current_user;
    $initial_setup = get_site_data_by_key( 'wpf_initial_setup_complete' );
    if ( $initial_setup != 'yes' ) {
	    require_once( WPF_PLUGIN_DIR . 'inc/admin/wpf_backend_initial_setup.php' );
    } else {
	    require_once( WPF_PLUGIN_DIR . 'inc/admin/page-settings.php' );
    }
}

/*
 * This function is used used to include the page-settings template for the tasks menu.
 *
 * @input NULL
 * @return NULL
 */
function collaboration_task_center() {
    global $current_user;
    require_once( WPF_PLUGIN_DIR . 'inc/admin/task-center.php' );
}

/*
 * This function is used used to include the page-settings-permissions template for the Permissions menu.
 *
 * @input NULL
 * @return NULL
 */
function collaboration_page_permissions() {
    global $current_user;
    require_once( WPF_PLUGIN_DIR . 'inc/admin/page-settings-permissions.php' );
}

/*
 * Require admin functionality
 */
require_once( WPF_PLUGIN_DIR . 'inc/wpf_ajax_functions.php' );
require_once( WPF_PLUGIN_DIR . 'inc/wpf_function.php' );
require_once( WPF_PLUGIN_DIR . 'inc/wpf_email_notifications.php' );
require_once( WPF_PLUGIN_DIR . 'inc/wpf_admin_functions.php' );
require_once( WPF_PLUGIN_DIR . 'inc/admin/wpf_admin_function.php' );
require_once( WPF_PLUGIN_DIR . 'inc/wpf_api.php' );
require_once( WPF_PLUGIN_DIR . 'inc/wpf_class.php' );

// Create cookie for invited user to allow collaboration (share verison 2) by Pratap.
function session_for_invited_user() {
    if ( isset( $_GET['collab_token'] ) && $_GET['collab_token'] != '' ) {
        $token = $_GET['collab_token'];
        // Get user id based on token.
        $user_id = get_option( 'avc_guest_' . $token );
        // Check if id exist.
        if ( $user_id != false ) {
            // Get user object using id.
            $result = get_userdata( (int) $user_id );
            if ( $result != false ) { // If user exist
                $roles = $result->roles;
                // Store required user value in array.
                $user = array(
                    'fname' => $result->first_name,
                    'email' => $result->user_email,
                    'dname' => $result->display_name,
                    'role'  => $roles[0],
                    'id'    => $user_id
                );
                // Create share version 2 cookie if doesn't exist.
                if ( ! isset( $_COOKIE['wordpress_avc_allow_guest'] ) ) {
                    setcookie( 'wordpress_avc_allow_guest', wp_json_encode( $user ), time() + ( 86400 * 30 ), '/');
                    // Need to reload page because tool will load based on cookie.
                    header('Location: '.$_SERVER['PHP_SELF']);
                    die;
                }
            }
        } else { // If user does not exist.
            // Destroy share version 2 cookie if exist.
            if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) ) {
                unset($_COOKIE['wordpress_avc_allow_guest']);
                setcookie( 'wordpress_avc_allow_guest', '', time() - 3600, '/');
                header('Location: '.$_SERVER['PHP_SELF'] . '/?action=atarim&trigger=deletecookie');
                die;
            }
        }
    }
    // Destroy share version 2 cookie if user does not exist.
    if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) && $_COOKIE['wordpress_avc_allow_guest'] != '' ) {
        $user = json_decode( stripslashes( $_COOKIE['wordpress_avc_allow_guest'] ), true );
        if ( ! empty ( $user ) ) {
            $user_id = $user['id'];
            $result = get_userdata( (int) $user_id );
            if ( $result == false ) {
                if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) ) {
                    unset($_COOKIE['wordpress_avc_allow_guest']);
                    setcookie( 'wordpress_avc_allow_guest', '', time() - 3600, '/');
                    header('Location: '.$_SERVER['PHP_SELF'] . '/?action=atarim&trigger=deletecookie');
                    die;
                }
            }
        }
    }
    // Redirect once again after deleting cookie to remove action param.
    if ( isset( $_GET['trigger'] ) && $_GET['trigger'] == 'deletecookie' ) {
        header('Location: '.$_SERVER['PHP_SELF']);
        die;
    }

    if ( isset( $_GET['guest_token'] ) && $_GET['guest_token'] != '' ) {
        $token = get_option( 'wpf_guest_token' );
        if ( $token == $_GET['guest_token'] && ! is_user_logged_in() ) {
            if ( ! isset( $_COOKIE['wordpress_avc_guest'] ) ) {
                setcookie( 'wordpress_avc_guest', $_GET['guest_token'], time() + ( 86400 * 30 ), '/');
                header('Location: '.$_SERVER['PHP_SELF']);
                die;
            }
        }
    }
    if ( is_user_logged_in() ) {
        if ( isset( $_COOKIE['wordpress_avc_guest'] ) ) {
            unset($_COOKIE['wordpress_avc_guest']);
            setcookie( 'wordpress_avc_guest', '', time() - 3600, '/');
            header('Location: '.$_SERVER['PHP_SELF'] . '/?action=atarim&trigger=deletecookie');
            die;
        }
    }
}
add_action( 'init', 'session_for_invited_user' );

function new_license_activation() {
    
    /*New license activation*/
    if ( isset( $_GET['atarim_response'] ) ) {
        global $current_user;
        $user_id = $current_user->ID;

        // remove the %3D(it's 7 if decoded) from the query string parameter if present
        if ( strpos( $_GET['atarim_response'], '%3D' ) !== false ) {
            $atarim_response = substr( $_GET['atarim_response'], -1, 3 );
        } else {
            $atarim_response = $_GET['atarim_response'];
        }
        update_option( 'wpf_license', base64_decode( sanitize_text_field( $atarim_response ) ) );
        $wpf_license_key = '';
        if ( isset( $_GET['license_key'] ) ) {
            $wpf_license_key = base64_decode( sanitize_text_field( $_GET['license_key'] ) );
            $wpf_crypt_key = wpf_crypt_key( $wpf_license_key, 'e' );
            update_option( 'wpf_license_key', $wpf_crypt_key, 'no' );
        }        
        if ( isset( $_GET['expires'] ) ) {
            update_option( 'wpf_license_expires', base64_decode( sanitize_text_field( $_GET['expires'] ) ), 'no' );
        }
        if ( isset( $_GET['prod_id'] ) ) {
            update_option( 'wpf_prod_id', base64_decode( sanitize_text_field( $_GET['prod_id'] ) ), 'no' );
        }
        if ( isset( $_GET['payment_id'] ) ) {
            $decr = update_option( 'wpf_decr_key', base64_decode( sanitize_text_field( $_GET['payment_id'] ) ) );
        }
        if ( isset( $_GET['checksum'] ) ) {
            $checksu = update_option( 'wpf_decr_checksum', base64_decode( sanitize_text_field( $_GET['checksum'] ) ), 'no' );
        }
        update_option( 'wpf_site_id', base64_decode( sanitize_text_field( $_GET['wpf_site_id'] ) ), 'no' );
        update_user_meta( $user_id, 'wpf_user_type', 'advisor' );
        do_action( 'wpf_initial_sync', $wpf_license_key );
        syncUsers();
        update_option("wpf_initial_setup_complete", 'yes');

        // redirect user to front side after activation process is complete by Pratap on 21/09/2023.
        wp_safe_redirect( WPF_HOME_URL );
        exit();
    }
}
add_action( 'init', 'new_license_activation' );

/*
 * This function is used for add/update
 * user default site data
 */
function update_default_site_data() {
    $options = array();
    array_push( $options, ['name' => 'wpf_initial_setup_complete', 'value' => 'yes'] );
    array_push( $options, ['name' => 'enabled_wpfeedback', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_global_settings', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpfeedback_color', 'value' => '002157'] );
    array_push( $options, ['name' => 'wpf_selcted_role', 'value' => 'administrator'] );
    array_push( $options, ['name' => 'wpf_website_developer', 'value' => get_current_user_id()] );
    array_push( $options, ['name' => 'wpf_allow_guest', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_every_new_task', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_every_new_comment', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_every_new_complete', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_every_status_change', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_daily_report', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_weekly_report', 'value' => 'no'] );
    array_push( $options, ['name' => 'wpf_show_front_stikers', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_customisations_client', 'value' => 'Client (Website Owner)'] );
    array_push( $options, ['name' => 'wpf_customisations_webmaster', 'value' => 'Webmaster'] );
    array_push( $options, ['name' => 'wpf_customisations_others', 'value' => 'Others'] );
    array_push( $options, ['name' => 'wpf_from_email', 'value' => get_option( 'admin_email' )] );
    array_push( $options, ['name' => 'wpf_tab_permission_user_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_user_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_user_others', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_priority_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_priority_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_status_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_status_others', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_screenshot_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_screenshot_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_screenshot_others', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_information_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_information_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_information_others', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_delete_task_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_delete_task_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_auto_screenshot_task_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_auto_screenshot_task_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_auto_screenshot_task_others', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_auto_screenshot_task_guest', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_display_stickers_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_display_stickers_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_display_task_id_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_display_task_id_webmaster', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_display_task_id_others', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_display_task_id_guest', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_keyboard_shortcut_client', 'value' => 'yes'] );
    array_push( $options, ['name' => 'wpf_tab_permission_keyboard_shortcut_webmaster', 'value' => 'yes'] );

    if( ! empty( $options ) ) {
	    update_site_data( $options );
    }
}

/*
 * This function is used to detect if the page builder is initialized on the current running page and deregister the Atarim of found running.
 *
 * @input NULL
 * @return NULL
 */
function wpfeedback_add_stylesheet_frontend() {
    $wpf_check_page_builder_active = wpf_check_page_builder_active();
    if ( $wpf_check_page_builder_active == 0 ) {
        $enabled_wpfeedback = wpf_check_if_enable();
        $wpf_enabled        = get_site_data_by_key( 'enabled_wpfeedback' );
        $is_site_archived   = get_site_data_by_key( 'wpf_site_archived' );
        if ( $wpf_enabled == 'yes' && ( ! $is_site_archived ) ) {
            if ( ! is_user_logged_in() ) {
                /* Show the login modal only when 'wpf_login' is present => v2.0.9, v2.1.0 */
                if ( ( ! empty( $_GET['wpf_login'] ) ) ) {
                    wp_register_style( 'wpf_login_style', WPF_PLUGIN_URL . 'css/wpf-login.css', false, strtotime( "now" ) );
                    wp_enqueue_style( 'wpf_login_style' );
                }
            }

            /* Show the login modal only when 'wpf_login' is present => v2.0.9, v2.1.0 */
            if ( ( ! empty( $_GET['wpf_login'] ) ) ) {
                wp_register_script( 'wpf-ajax-login', WPF_PLUGIN_URL . 'js/wpf-ajax-login.js', array(), strtotime( "now" ), true );
                wp_enqueue_script( 'wpf-ajax-login' );
            }

            wp_localize_script( 'wpf-ajax-login', 'wpf_ajax_login_object',
                array(
                    'ajaxurl'            => admin_url( 'admin-ajax.php' ),
                    'wpf_reconnect_icon' => WPF_PLUGIN_URL . 'images/wpf_reconnect.png',
                    'redirecturl'        => WPF_HOME_URL,
                )
            );
        }
        if ( ( $enabled_wpfeedback == 1 && ! $is_site_archived ) ) {
            wp_register_style( 'wpf_wpf-icons', WPF_PLUGIN_URL . 'css/wpf-icons.css', false, strtotime( "now" ) );
            wp_enqueue_style( 'wpf_wpf-icons' );

            wp_register_style( 'wpf_wpf-common', WPF_PLUGIN_URL . 'css/wpf-common.css', false, strtotime( "now" ) );
            wp_enqueue_style( 'wpf_wpf-common' );

            wp_register_style( 'wpf_rt_style', WPF_PLUGIN_URL . 'css/quill.css', false, strtotime( "now" ) );
            wp_enqueue_style( 'wpf_rt_style' );

            wp_register_script( 'wpf_rt_script', WPF_PLUGIN_URL . 'js/quill.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_rt_script' );

            wp_register_script( 'wpf_jquery_script', WPF_PLUGIN_URL . 'js/atarimjs.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_jquery_script' );

            if ( $wpf_check_page_builder_active == 0 ) {

                wp_register_script( 'wpf_touch_mouse_script', WPF_PLUGIN_URL . 'js/atarim.ui.mouse.min.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_touch_mouse_script' );

                wp_register_script( 'wpf_touch_punch_script', WPF_PLUGIN_URL . 'js/jquery.ui.touch-punch.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_touch_punch_script' );

                wp_register_script( 'wpf_browser_info_script', WPF_PLUGIN_URL . 'js/wpf_browser_info.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_browser_info_script' );

                wp_enqueue_script( 'wpf_lottie_script', 'https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js', array(), strtotime( "now" ), true );

                wp_register_script( 'wpf_common_functions', WPF_PLUGIN_URL . 'js/wpf_common_functions.js', array(), strtotime( "now" ), true );
                wp_enqueue_script( 'wpf_common_functions' );

                wp_register_script( 'wpf_app_script', WPF_PLUGIN_URL . 'js/app.js', array(), strtotime( "now" ), true );
                wp_enqueue_script( 'wpf_app_script' );
                $wpf_user_type = wpf_user_type();
                $display_name  = '';
                $avatar_url    = '';
                if ( is_user_logged_in() ) {
                    $user          = wp_get_current_user();
                    $display_name  = $user->display_name;
                    $user_id       = get_current_user_id();
                    $avatar_url    = get_avatar_url( $user_id, array( 'size' => 42, 'default' => '404' ) );
                    $headers       = @get_headers( $avatar_url );
                    if ( ! empty( $headers ) ) {
                        if ( in_array( 'HTTP/1.1 404 Not Found', $headers ) ) {
                            $avatar_url = '';
                        }
                    } else {
                        $avatar_url = '';
                    }
                } else if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) && $_COOKIE['wordpress_avc_allow_guest'] != '' ) { // If used Share version 2 by Pratap.
                    $user         = json_decode( stripslashes( $_COOKIE['wordpress_avc_allow_guest'] ), true );
                    $display_name = $user['dname'];
                    $user_id      = $user['id'];
                    $avatar_url   = get_avatar_url( $user_id, array( 'size' => 42, 'default' => '404' ) );
                    $headers      = @get_headers( $avatar_url );
                    if ( ! empty( $headers ) ) {
                        if ( in_array( 'HTTP/1.1 404 Not Found', $headers ) ) {
                            $avatar_url = '';
                        }
                    } else {
                        $avatar_url = '';
                    }
                }

                wp_localize_script( 'wpf_app_script', 'logged_user', array( 'current_user' => $wpf_user_type, 'author_img' => $avatar_url, 'author' => $display_name, 'site_url' => WPF_HOME_URL, 'wpside' => 'frontend' ) );

                $theme  = wp_get_theme();
                $adjust = 'false';
                if ( is_user_logged_in() && is_admin() ) {
                    if ( 'GeneratePress' == $theme->name || 'GeneratePress Child' == $theme->name || 'Black Bros' == $theme->name || 'Ultra WEB-Baas' == $theme->name ) {
                        $adjust = 'true';
                    }
                }
                wp_localize_script( 'wpf_app_script', 'istheme', array( 'adjust' => $adjust, 'active_theme' => $theme->name ) );
            
                $feature = array();
                $edit    = is_feature_enabled( 'edit' );
                if ( ! $edit ) {
                    $feature[] = 'edit';
                }
                wp_localize_script( 'wpf_app_script', 'blocked', $feature );

                $upgrade_url = get_option( 'upgrade_url' );
                wp_localize_script( 'wpf_common_functions', 'upgrade_url', array( 'url' => $upgrade_url, 'plugin_url' => WPF_PLUGIN_URL ) );

                $wpf_get_user_type = esc_attr( wpf_user_type() );
                $wpf_new_task      = isset($_GET['wpf-task']) ? true : false;
                if( $wpf_new_task && ! get_option( 'wpf_app_auto_task' ) ) {
                    update_option( 'wpf_app_auto_task', true );
                    $wpf_app_auto_task = true;
                    $wpf_new_task      = true;
                } else {
                    $wpf_app_auto_task = false;
                    $wpf_new_task      = false;
                }
                $wpf_frontend_user = ( isset( $_GET['wpf-user-flow'] ) || isset( $_GET['wpf-existing-user-flow'] ) ) ? true : false;
                wp_localize_script( 'wpf_app_script', 'wpf_app_script_object', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'wpf_app_auto_task' => $wpf_app_auto_task, 'wpf_new_task' => $wpf_new_task, 'wpf_frontend_user' => $wpf_frontend_user ) );

                wp_register_script( 'wpf_html2canvas_script', WPF_PLUGIN_URL . 'js/html2canvas.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_html2canvas_script' );

                wp_register_script( 'wpf_popper_script', WPF_PLUGIN_URL . 'js/popper.min.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_popper_script' );

                wp_register_script( 'wpf_custompopover_script', WPF_PLUGIN_URL . 'js/custompopover.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_custompopover_script' );

                wp_register_script( 'wpf_selectoroverlay_script', WPF_PLUGIN_URL . 'js/selectoroverlay.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_selectoroverlay_script' );

                wp_register_script( 'wpf_xyposition_script', WPF_PLUGIN_URL . 'js/xyposition.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_xyposition_script' );

                wp_register_script( 'wpf_bootstrap_script', WPF_PLUGIN_URL . 'js/bootstrap.min.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_bootstrap_script' );
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'wpfeedback_add_stylesheet_frontend');

/**
 * 
 * Prevent collision with the WordPress jQuery
 */
function add_attributes_to_script( $tag, $handle, $src ) {
	if ( 'wpf_jquery_script' === $handle ) {
        if ( wp_script_is( 'jquery', 'enqueued' ) ) {
            $tag = '<script>var jQuery_WPF = jQuery;</script>';
        } else {
			return;
		}
	}
	return $tag;
}
add_filter( 'script_loader_tag', 'add_attributes_to_script', 10, 3 ); 


/*
 * This function is used to create the security nonce every time a user requests the Atarim.
 *
 * @input NULL
 * @return String
 */
function wpf_wp_create_nonce() {
    global $post;
    $wpf_allow_guest = get_site_data_by_key( 'wpf_allow_guest' );
    // Allow user if used Share version 2 by Pratap
    if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) ) {
        $wpf_allow_guest = 'yes';
    }
    if ( isset( $_COOKIE['wordpress_avc_guest'] ) ) {
        $wpf_allow_guest = 'yes';
    }
    if ( is_user_logged_in() || $wpf_allow_guest == 'yes' ) {
        $wpf_nonce = wp_create_nonce( 'wpfeedback-script-nonce' );
        return $wpf_nonce;
    }
}

/* ==========All Java script for Admin footer========= */
/*
 * This function is used to initial the Atarim and all related variables on the backend.
 *
 * @input NULL
 * @return NULL
 */
if ( isset( $_GET['page'] ) ) {
	add_action( 'admin_footer', 'wpf_backed_scripts' );
}
function wpf_backed_scripts() {
    global $wpdb, $post, $current_user;
    $author_id                = $current_user->ID;
    $wpf_user_type            = wpf_user_type();
    $currnet_user_information = wpf_get_current_user_information();
    $current_role             = $currnet_user_information['role'];
    $current_user_name        = $currnet_user_information['display_name'];
    $current_user_id          = $currnet_user_information['user_id'];
    $wpf_website_builder      = maybe_unserialize( get_site_data_by_key( 'wpf_website_developer' ) );
    $wpf_website_builder      = ! empty( $wpf_website_builder ) ? (array) $wpf_website_builder : array();
    if ( $current_user_name == 'Guest' ) {
        $wpf_website_client = get_site_data_by_key( 'wpf_website_client' );
        $wpf_current_role   = 'guest';
        if ( $wpf_website_client ) {
            $wpf_website_client_info = get_userdata( $wpf_website_client );
            if ( $wpf_website_client_info ) {
                if ( $wpf_website_client_info->display_name == '' ) {
                    $current_user_name = $wpf_website_client_info->user_nicename;
                } else {
                    $current_user_name = $wpf_website_client_info->display_name;
                }
            }
        }
    }
    $current_user_name       = addslashes( $current_user_name );
    $wpf_show_front_stikers  = get_site_data_by_key( 'wpf_show_front_stikers' );
    $unix_time_now           = time();
    $wpf_check_atarim_server = get_option( 'atarim_server_down_check' );

    if ( $unix_time_now > $wpf_check_atarim_server ) {
        update_option( 'atarim_server_down','false','no' );
    }

    $atarim_server_down = get_option( 'atarim_server_down' );
    $wpfb_users         = do_shortcode( '[wpf_user_list_front]' );
    $wpf_all_pages      = wpf_get_page_list();
    $ajax_url           = admin_url( 'admin-ajax.php' );
    $plugin_url         = WPF_PLUGIN_URL;
    $wpf_comment_time   = date( 'd-m-Y H:i', current_time( 'timestamp', 0 ) );
    $wpf_nonce          = wpf_wp_create_nonce();
    $sound_file         = esc_url( plugins_url( 'images/wpf-screenshot-sound.mp3', __FILE__ ) );
    $comment_count      = get_last_task_id();

    echo "<script>var wpf_nonce = '$wpf_nonce', wpf_comment_time = '$wpf_comment_time', wpf_all_pages = '$wpf_all_pages', current_role = '$current_role', wpf_current_role = '$wpf_user_type', current_user_name = '$current_user_name', current_user_id = '$current_user_id', wpf_website_builder = " . json_encode( $wpf_website_builder ) . ", wpfb_users = '$wpfb_users', ajaxurl = '$ajax_url', wpf_screenshot_sound = '$sound_file', plugin_url = '$plugin_url', comment_count = '$comment_count', wpf_show_front_stikers = '$wpf_show_front_stikers', atarim_server_down = '$atarim_server_down';</script>";

    if ( isset( $_REQUEST['page'] ) ) {
        if ( $_REQUEST['page'] == 'collaboration_task_center' ) {
            ?>
            <script type='text/javascript'>
                var plugin_url = '<?php echo $plugin_url ?>';
                var current_task    = 0;
                var current_user_id = "<?php echo $author_id; ?>";
                var wpf_user_type   = "<?php echo $wpf_user_type; ?>";
                var reloadd_task = true;
                var pagee_no = 2;

                function getParameterByName(name) {
                    name        = name.replace(/[\[]/, "\\\[").replace(/[\]]/, "\\\]");
                    var regexS  = "[\\?&]" + name + "=([^&#]*)";
                    var regex   = new RegExp( regexS );
                    var results = regex.exec( window.location.href );
                    if ( results == null ) {
                        return "";
                    } else {
                        return decodeURIComponent( results[1].replace(/\+/g, " ") );
                    }
                }

                /*
                 * wpf task filter code
                 */
                function wp_feedback_filter() {
                    reloadd_task        = false;
                    page_no             = 0;
                    var ajaxurl         = "<?php echo admin_url('admin-ajax.php'); ?>";
                    var task_types      = [];
                    var task_title      = jQuery('#wpf_tasks #wpf_search_title').val();
                    var task_types_meta = [];
                    jQuery.each(
                        jQuery("#wpf_filter_form input[name='task_types']:checked"), function () {
                            task_types.push( jQuery(this).val() );
                        }
                    );
                    var selected_task_types_values = task_types.join(",");
                    var is_internal                = 0;
                    jQuery.each(
                        jQuery("#wpf_filter_form input[name='task_types_meta']:checked"), function (index, element) {
                            if ( jQuery(element).attr('id') === 'wpf_task_type_internal' ) {
                                is_internal = 1;
                            } else {
                                task_types_meta.push(jQuery(this).val());
                            }
                        }
                    );
                    var selected_task_types_meta_values = task_types_meta.join(",");
                    var task_status                     = [];
                    jQuery.each(
                        jQuery("#wpf_filter_form input[name='task_status']:checked"), function () {
                            task_status.push(jQuery(this).val());
                        }
                    );
                    var selected_task_status_values = task_status.join(",");
                    var task_priority               = [];
                    jQuery.each(
                        jQuery("#wpf_filter_form input[name='task_priority']:checked"), function () {
                            task_priority.push(jQuery(this).val());
                        }
                    );
                    var selected_task_priority_values = task_priority.join(",");
                    var author_list                   = [];
                    jQuery.each(
                        jQuery("#wpf_filter_form input[name='author_list']:checked"), function () {
                            author_list.push(jQuery(this).val());
                        }
                    );
                    var selected_author_list_values      = author_list.join(",");
                    var wpf_display_all_taskmeta_tasktab = jQuery('#wpf_display_all_taskmeta_tasktab').prop("checked") ? 1 : 0;
                    jQuery.ajax({
                        method: "POST",
                        url: ajaxurl,
                        data: {
                            action: "wpfeedback_get_post_list_ajax",
                            wpf_nonce: wpf_nonce,
                            task_title: task_title,
                            task_types: selected_task_types_values, task_types_meta: selected_task_types_meta_values,
                            task_status: selected_task_status_values,
                            task_priority: selected_task_priority_values,
                            author_list: selected_author_list_values,
                            internal: is_internal
                        },
                        beforeSend: function () {
                            jQuery('.wpf_loader_admin').show();
                        },
                        success: function (data) {
                            //Comment
                            jQuery('#wpf_display_all_taskmeta_tasktab').prop('checked', false);
                            jQuery('.wpf_loader_admin').hide();
                            jQuery('.wpf_tasks_col .wpf_tasks-list').html(data);
                            if ( document.getElementById('wpf_task_bulk_tab').checked ) {
                                jQuery('.wpf_task_num_top').hide();
                                jQuery('#wpf_task_all_tab').removeClass('active');
                                jQuery('ul#all_wpf_list li .wpf_task_id').addClass('wpf_active');
                                jQuery('ul#all_wpf_list #wpf_bulk_select_task_checkbox').addClass('wpf_active');
                                jQuery('#wpf_bulk_select_task_checkbox').show();
                            }
                            if ( wpf_display_all_taskmeta_tasktab == 1 ) {
                                jQuery('ul#all_wpf_list li div.wpf_task_meta').addClass('wpf_active');
                                jQuery('#wpf_display_all_taskmeta_tasktab').prop("checked", true);
                            }
                        }
                    });
                }

                jQuery('.wpf_tasks-list').bind('scroll', function() {
                    if( jQuery(window).scrollTop() >= (jQuery('#all_wpf_list').offset().top + jQuery('#all_wpf_list').outerHeight() - window.innerHeight)) {
                        if ( reloadd_task == true &&  pagee_no > 0 ) {
                            load_task_center_all_tasks();
                            reloadd_task = false;
                        }
                    }
                });

                function load_task_center_all_tasks() {
                    jQuery.ajax({
                        method: "POST",
                        url: ajaxurl,
                        data: {
                            action: "wpfeedback_get_post_list_ajax",
                            wpf_nonce: wpf_nonce,
                            page_no: pagee_no,
                        },
                        beforeSend: function () {
                            jQuery('.wpf_loading').show();
                        },
                        success: function (data) {
                            jQuery('.wpf_loader_admin').hide();
                            if( data != '' ) {
                                jQuery('.wpf_tasks_col .wpf_tasks-list #all_wpf_list').append(data);
                                reloadd_task = true;
                                pagee_no = pagee_no + 1;
                            } else {
                                reloadd_task = false;
                                pageee_no = 0;
                                jQuery('.wpf_loading').hide();
                            }
                        }
                    });
                }

                var internal_icon_html='<span class="wpf_chevron_wrapper wpf_internal_task_wrapper"><img src="' + plugin_url + 'images/eye-off-white.svg" class="wpf-internal-img" alt="eye off white"></span>';
                function get_wpf_message_form( comment_post_ID, curren_user_id,is_internal ) {
                    let internal_button = '';
                    if ( wpf_current_role == 'advisor' ) {
                        if ( is_internal == '1' ) {
                            internal_class  = "wpf_is_internal";
                            internal_button = '<button class="wpf_mark_internal wpf_mark_internal_task_center '+internal_class+'" data-id="'+comment_post_ID+'"><img src="' + plugin_url + 'images/eye-off-white.svg" alt="eye off white" class="wpf-internal-img"><span class="wpf_tooltiptext unmark_internal_tooltip_text">'+ switch_to_normal +'</span><span class="wpf_tooltiptext new_internal_tooltip_text">'+ create_internal_task +'</span><span class="wpf_tooltiptext mark_internal_tooltip_text">'+ switch_to_internal +'</span></button>';
                        } else {
                            internal_class  = "";
                            internal_button = '<button class="wpf_mark_internal wpf_mark_internal_task_center '+internal_class+'" data-id="'+comment_post_ID+'"><img src="' + plugin_url + 'images/eye-off.svg" alt="eye off" class="wpf-internal-img"><span class="wpf_tooltiptext unmark_internal_tooltip_text">'+ switch_to_normal +'</span><span class="wpf_tooltiptext new_internal_tooltip_text">'+ create_internal_task +'</span><span class="wpf_tooltiptext mark_internal_tooltip_text">'+ switch_to_internal +'</span></button>';
                        }
                    }
                    let note_button = '';
                    if ( wpf_current_role == 'advisor' || wpf_current_role == 'council' ) {
                        note_button = '<button class="wpf_mark_note wpf_mark_note_task_center" onclick="send_chat_message(true)" data-id="'+comment_post_ID+'"><img src="' + plugin_url + 'images/note.svg" alt="note"><span class="wpf_tooltiptext note_tooltip_text">'+ add_note +'</span></button>';
                    }
                    var html = '<div id="wpf_chat_box"><form action="" method="post" id="wpf_form" class="comment-form" enctype="multipart/form-data"><p class="comment-form-comment"><div class="wpf-tc-editor"></div><textarea placeholder="' + wpf_comment_box_placeholder + '" id="wpf_comment" name="comment" maxlength="65525" required="required"></textarea><input type="hidden" name="comment_post_ID" value="' + comment_post_ID + '" id="comment_post_ID">  <input type="hidden" name="curren_user_id" value="' + curren_user_id + '" id="curren_user_id"><p class="form-submit chat_button"><input name="submit" type="button" id="send_chat" onclick="send_chat_message()" class="submit wpf_button submit" value="' + wpf_send_message_text + '">' + note_button + internal_button + '<a href="javascript:void(0)" class="wpf_upload_button wpf_button" onchange="wpf_upload_file_admin(' + comment_post_ID + ');"><input multiple type="file" name="wpf_uploadfile" id="wpf_uploadfile" data-elemid="' + comment_post_ID + '" class="wpf_uploadfile"><i class="gg-attachment"></i></a></p><p id="wpf_upload_error" class="wpf_hide">You are trying to upload an invalid filetype <br> Allowd File Types: JPG, PNG, GIF, PDF, DOC, DOCX and XLSX</p></form></div></div>';
                    return html;
                }
                function send_chat_message(note=false) {
                    jQuery("#get_masg_loader").show();
                    jQuery(".get_masg_loader").show();
                    var wpf_comment       = jQuery('#wpf_comment').val();
                    var post_id           = jQuery('#comment_post_ID').val();
                    var author_id         = "<?php echo $author_id; ?>";
                    var ajaxurl           = "<?php echo admin_url('admin-ajax.php'); ?>";
                    var note              = note;
                    var task_notify_users = [];
                    jQuery.each(
                        jQuery('#wpf_attributes_content input[name="author_list_task"]:checked'), function () {
                            task_notify_users.push(jQuery(this).val());
                        }
                    );
                    task_notify_users = task_notify_users.join(",");

                    if ( jQuery('#wpf_comment').val().trim().length > 0 ) {
                        jQuery.ajax({
                            method: "POST",
                            url: ajaxurl,
                            data: {
                                action: "insert_wpf_comment_func",
                                wpf_nonce: wpf_nonce,
                                post_id: post_id,
                                author_id: author_id,
                                task_notify_users: task_notify_users,
                                wpf_comment: wpf_comment,
                                note: note
                            },
                            beforeSend: function () {
                                jQuery('.wpf_loader_admin').show();
                            },
                            success: function (data) {
                                try {
                                    const responseData = JSON.parse(data);
                                    if ( responseData['limit'] === true ) {
                                        jQuery(".wpf_locked_modal_container").show();
                                        return;
                                    }
                                } catch(ex){}

                                jQuery('.wpf_loader_admin').hide();
                                jQuery("#wpf_not_found").remove();
                                jQuery("#tag_post").html('');
                                if ( jQuery('#wpf_message_list li').length == 0 ) {
                                    jQuery('ul#wpf_message_list').html(data);
                                } else {
                                    jQuery('ul#wpf_message_list li.chat_author:last').after(data);
                                }
                                jQuery("#wpf_comment").val("");
                                jQuery("#addcart_loader").fadeOut();
                                jQuery("#get_masg_loader").hide();
                                jQuery(".get_masg_loader").hide();
                                // empty Task center rich text editor by Pratap
                                jQuery_WPF('.ql-editor').html('');
                                jQuery('#wpf_message_content').animate({scrollTop: jQuery('#wpf_message_content').prop("scrollHeight")}, 2000);
                                if ( jQuery("#task_task_status_attr").val() == 'complete' ) {
                                    jQuery("#task_task_status_attr").val("open");
                                    var obj = document.getElementById("task_task_status_attr");
                                    task_status_changed(obj);
                                }
                            }
                        });
                    } else {
                        jQuery("#get_masg_loader").hide();
                        jQuery('ul#wpf_message_list').animate({scrollTop: jQuery("ul#wpf_message_list li").last().offset().top}, 1000);
                        jQuery("#wpf_comment").focus();
                        jQuery("#get_masg_loader").hide();
                    }
                }
                
                jQuery(document).on('click','.wpf_mark_note_task_center',function(e) {
                    e.preventDefault();
                });

                jQuery(document).on('click','.wpf_mark_internal_task_center',function(e) {
                    e.preventDefault();
                    let id=jQuery(this).data('id');
                    if( jQuery(this).hasClass('wpf_is_internal') ) {
                        mark_internal_task_center(id,'0');
                        jQuery(this).find('.wpf-internal-img').attr('src', plugin_url + 'images/eye-off.svg');
                    } else {
                        mark_internal_task_center(id,'1');
                        jQuery(this).find('.wpf-internal-img').attr('src', plugin_url + 'images/eye-off-white.svg');
                    }
                });
                function mark_internal_task_center( id,internal ) {
                    var task_info         = [];               
                    var task_notify_users = [];
                    var task_comment      = jQuery_WPF('#comment-'+id).val();
                    jQuery_WPF.each(
                        jQuery_WPF('input[name=author_list_'+id+']:checked'), function(){
                            task_notify_users.push(jQuery_WPF(this).val());
                        }
                    );                
                    task_info['task_id']  = id;
                    task_info['internal'] = internal;               
                    var task_info_obj     = jQuery_WPF.extend({}, task_info);       
                    var task_info_obj     = jQuery_WPF.extend({}, task_info);
                    jQuery_WPF.ajax({
                        method : "POST",
                        url : ajaxurl,
                        data : {
                            action: "wpfb_mark_as_internal",
                            wpf_nonce:wpf_nonce,
                            task_info:task_info_obj
                        },
                        beforeSend: function() {
                            jQuery_WPF('.wpf_loader_admin').show();
                        },
                        success : function(data) {
                            if ( internal == '1' ) {
                                jQuery_WPF('.wpf_mark_internal_task_center').addClass('wpf_is_internal');
                                jQuery_WPF('#wpf-task-'+id).addClass('wpfb-internal');
                                jQuery_WPF('#wpf-task-'+id).find('.wpf_task_num_top').append(internal_icon_html);
                            } else {
                                jQuery_WPF('.wpf_mark_internal_task_center').removeClass('wpf_is_internal');
                                jQuery_WPF('#wpf-task-'+id).removeClass('wpfb-internal');
                                jQuery_WPF('#wpf-task-'+id).find('.wpf_task_num_top').find('.wpf_chevron_wrapper').remove();
                            }
                            jQuery_WPF('.wpf_loader_admin').hide();
                        }
                    });
                }

                function task_status_changed( sel ) {
                var task_info         = [];
                var task_notify_users = [];
                jQuery.each(
                    jQuery('#wpf_attributes_content input[name="author_list_task"]:checked'), function () {
                        task_notify_users.push(jQuery(this).val());
                    }
                );

                let selected_priority          = jQuery('#task_task_priority_attr').val();
                task_notify_users              = task_notify_users.join(",");
                task_info['task_id']           = current_task;
                task_info['task_status']       = sel.value;
                task_info['task_notify_users'] = task_notify_users;
                var wpf_task_id                = jQuery('#wpf_task_details .wpf_task_num_top').text()
                var task_info_obj              = jQuery.extend({}, task_info);
                let sticker_permission         = wpf_tab_permission_display_stickers;
                let task_id_permission         = wpf_tab_permission_display_task_id;
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: "wpfb_set_task_status",
                        wpf_nonce: wpf_nonce,
                        task_info: task_info_obj
                    },
                    beforeSend: function () {
                    },
                    success: function (data) {
                        let display_span = '';
                        let custom_class = '';
                        if ( sticker_permission == 'yes' ) {
                            display_span = '<span class="' + selected_priority + '_custom"></span>';
                            custom_class = task_info['task_status'] + '_custom';
                        }
                        if ( task_info['task_status'] == "open" ) {
                            var news = "Open";
                        }
                        if ( task_info['task_status'] == "in-progress" ) {
                            var news = "In Progress";
                        }
                        if ( task_info['task_status'] == "pending-review" ) {
                            var news = "Pending Review";
                        }
                        if ( task_info['task_status'] == "complete" ) {
                            var news = "Complete";
                        }
                        if ( tss == "open" ) {
                            var olss = "Open";
                        }
                        if ( tss == "in-progress" ) {
                            var olss = "In Progress";
                        }
                        if ( tss == "pending-review" ) {
                            var olss = "Pending Review";
                        }
                        if ( tss == "complete" ) {
                            var olss="Complete";
                        }
                        author_img = plugin_url + 'images/bell.svg';
                        author_html = '<img src="' + author_img + '" alt="author"></img>';
                        jQuery("#wpf_message_list").append('<li class="  chat_author is_info  " title="1 sec ago"><div class="wpf-comment-container"><div class="wpf-author-img">' + author_html + '</div><div class="wpf-comment-wrapper"><level class="wpf-author"> <span>1 sec</span></level><div class="task_text">'+current_user_name+' marked as <span class="taskStatusMsg">'+news+'</span> from '+olss+'</div></div></div></li>');
                        jQuery("#wpf-task-" + current_task + " .wpf_task_label .task_status").removeClass().addClass("task_status wpf_" + sel.value);
                        tss = task_info['task_status'];
                        jQuery('#wpf-task-' + current_task).data('task_status', sel.value);
                        var view_id = jQuery(document).find("#wpf_"+current_task).attr("data-disp-id");
                        if ( sel.value == 'complete' ) {
                            jQuery('#all_wpf_list .post_' + current_task).addClass('complete');
                            let display_check_mark = '';
                            if ( task_id_permission == false ) {
                                display_check_mark = '<i class="gg-check"></i>';
                            } else {
                                display_check_mark = view_id
                            }

                            jQuery('#all_wpf_list .post_' + current_task + ' .wpf_task_num_top').html(display_check_mark);
                            jQuery('#wpf_task_details .wpf_task_num_top').html(display_span + view_id);
                            jQuery('#wpf_task_details .wpf_task_num_top').removeAttr('class').addClass('wpf_task_num_top ' + custom_class);
                            jQuery('#all_wpf_list .post_' + current_task + ' .wpf_chat_top .wpf_task_num_top').html(display_span + view_id);
                            jQuery('#all_wpf_list .post_' + current_task + ' .wpf_chat_top .wpf_task_num_top').removeAttr('class').addClass('wpf_task_num_top ' + custom_class);
                            jQuery('#all_wpf_list li.post_' + current_task).removeClass('open').removeClass('complete').removeClass('pending-review').removeClass('in-progress').addClass(task_info['task_status']).addClass('active').addClass('wpf_list').addClass(selected_priority);
                        } else {
                            jQuery('#all_wpf_list .post_' + current_task).removeClass('complete');
                            jQuery('#all_wpf_list .post_' + current_task + ' .wpf_task_num_top').html(view_id);
                            jQuery('#wpf_task_details .wpf_task_num_top').html(display_span + view_id);
                            jQuery('#wpf_task_details .wpf_task_num_top').removeAttr('class').addClass('wpf_task_num_top ' + custom_class);
                            jQuery('#all_wpf_list .post_' + current_task + ' .wpf_chat_top .wpf_task_num_top').html(display_span + view_id);
                            jQuery('#all_wpf_list .post_' + current_task + ' .wpf_chat_top .wpf_task_num_top').removeAttr('class').addClass('wpf_task_num_top ' + custom_class);
                            jQuery('#all_wpf_list li.post_' + current_task).removeClass('open').removeClass('complete').removeClass('pending-review').removeClass('in-progress').addClass(task_info['task_status']).addClass('active').addClass('wpf_list').addClass(selected_priority);
                        }
                    }
                });
            }

            function task_priority_changed( sel ) {
                var task_info              = [];
                var task_priority          = sel.value;
                task_info['task_id']       = current_task;
                task_info['task_priority'] = task_priority;
                var task_info_obj          = jQuery.extend({}, task_info);
                let sticker_permission     = wpf_tab_permission_display_stickers;
                jQuery.ajax({
                    method: "POST",
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    data: {
                        action: "wpfb_set_task_priority",
                        wpf_nonce: wpf_nonce,
                        task_info: task_info_obj
                    },
                    beforeSend: function () {
                    },
                    success: function (data) {
                        let custom_class = '';
                        if ( sticker_permission == 'yes' ) {
                            custom_class = sel.value + '_custom';
                        }
                        if ( task_priority == "low" ){
                            var news = "Low";
                        }
                        if ( task_priority == "medium" ) {
                            var news = "Medium";
                        }
                        if ( task_priority == "high" ) {
                            var news = "High";
                        }
                        if ( task_priority == "critical"){
                            var news = "Critical";
                        }
                        if ( prr == "low" ) {
                            var olss = "Low";
                        }
                        if ( prr == "medium" ) {
                            var olss = "Medium";
                        }
                        if ( prr == "high" ) {
                            var olss = "High";
                        }
                        if ( prr == "critical" ) {
                            var olss = "Critical";
                        }
                        author_img = plugin_url + 'images/bell.svg';
                        author_html = '<img src="' + author_img + '" alt="author"></img>';
                        jQuery("#wpf_message_list").append('<li class="  chat_author is_info  " title="1 sec ago"><div class="wpf-comment-container"><div class="wpf-author-img">' + author_html + '</div><div class="wpf-comment-wrapper"><level class="wpf-author"> <span>1 sec</span></level><div class="task_text">'+current_user_name+' marked as <span class="taskStatusMsg">'+news+'</span> from '+olss+'</div></div></div></li>');
                        prr = task_priority;
                        jQuery("#wpf-task-" + current_task + " .wpf_task_label .task_priority").removeClass().addClass("task_priority wpf_" + sel.value);
                        jQuery('#wpf-task-' + current_task).data('task_priority', sel.value);
                        jQuery('#all_wpf_list .post_' + current_task + ' .wpf_chat_top .wpf_task_num_top span').removeAttr('class').addClass(custom_class);
                        jQuery('#wpf_task_details .wpf_task_num_top span').removeAttr('class').addClass(custom_class);
                        jQuery('#all_wpf_list li.post_' + current_task).removeClass('low').removeClass('high').removeClass('critical').removeClass('medium').addClass(task_info['task_priority']).addClass('active').addClass('wpf_list');
                    }
                });
            }

            function update_notify_user(user_id) {
                var task_info         = [];
                var task_notify_users = [];
                jQuery.each(
                    jQuery('#wpf_attributes_content input[name="author_list_task"]:checked'), function () {
                    task_notify_users.push(jQuery(this).val());
                });
                task_notify_users              = task_notify_users.join(",");
                task_info['task_id']           = current_task;
                task_info['task_notify_users'] = task_notify_users;
                var task_info_obj              = jQuery.extend({}, task_info);

                jQuery.ajax({
                    method: "POST",
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    data: {
                        action: "wpfb_set_task_notify_users",
                        wpf_nonce: wpf_nonce,
                        task_info: task_info_obj
                    },
                    beforeSend: function () {
                    },
                    success: function (data) {
                        jQuery('#wpf-task-' + current_task).data('task_notify_users', task_notify_users);
                    }
                });
            }

            var tss;
            var prr;
            //get chat based on WPF post select
            function get_wpf_chat(obj, tg, author) {
                jQuery("#wpf_edit_title").show();
                jQuery("#wpf_task_tabs_container").show();
                jQuery("#wpf_edit_title_box").hide();
                jQuery("#wpf_title_val").val();
                var post_id = jQuery(obj).data("postid");
                var view_id = jQuery(obj).data("disp-id");

                if ( tg === undefined ) {
                    tg = false;
                }
                jQuery("ul#all_wpf_list li.wpf_list").removeClass('active');
                jQuery(obj).parent().addClass('active');

                var wpf_all_tags                      = [];
                var post_author_id                    = jQuery(obj).data('uid');
                var task_is_internal                  = jQuery('#wpf-task-'+post_id).hasClass('wpfb-internal');
                var post_task_type                    = jQuery(obj).data('task_type');
                var post_task_status                  = jQuery(obj).data('wpf_task_status');
                var post_task_no                      = jQuery(obj).data("task_no");
                var task_status                       = jQuery(obj).data("task_status");
                var task_page_url                     = jQuery(obj).data("task_page_url");
                var wpf_task_screenshot               = jQuery(obj).data("wpf_task_screenshot");
                var task_page_title                   = jQuery(obj).data("task_page_title");
                var task_config_author_name           = jQuery(obj).data("task_config_author_name");
                var task_author_name                  = jQuery(obj).data("task_author_name");
                let sticker_permission                = new_global_sticker_permission;
                let title_permission                  = new_global_task_id_permission;
                var task_config_author_res            = jQuery(obj).data("task_config_author_res");
                var task_config_author_browser        = jQuery(obj).data("task_config_author_browser");
                var task_config_author_browserversion = jQuery(obj).data("task_config_author_browserversion");
                var task_notify_users                 = jQuery(obj).data("task_notify_users");
                var task_priority                     = jQuery(obj).data("task_priority");
                var click                             = 'yes';
                var additional_info_html              = '<p><span class="wpf_task_ad_info_title">' + wpf_resolution + '</span> ' +''+ task_config_author_res + '</p><p><span class="wpf_task_ad_info_title">' + wpf_browser + '</span> ' + task_config_author_browser + ' ' + task_config_author_browserversion + '</p><p><span class="wpf_task_ad_info_title">' + wpf_user_name + '</span> ' + task_author_name + '</p><p><span class="wpf_task_ad_info_title">' + wpf_task_id + '</span> ' + post_id + '</p>';
                jQuery.ajax({
                    method: "POST",
                    url: ajaxurl,
                    data: {
                        action: "list_wpf_comment_func",
                        wpf_nonce: wpf_nonce,
                        post_id: post_id,
                        post_author_id: post_author_id,
                        click: click
                    },
                    beforeSend: function () {
                        jQuery('.wpf_loader_admin').show();
                    },
                    success: function (data) {
                        onload_wpfb_tasks = JSON.parse(data);
                        if ( onload_wpfb_tasks != null && onload_wpfb_tasks != "null" ) {
                            current_task = post_id;
                            wpf_tag_autocomplete(document.getElementById("wpf_tags"), wpf_all_tags);
                            jQuery('.wpf_loader_admin').hide();
                            jQuery("#wpf_not_found").remove();
                            jQuery("#get_masg_loader").hide();
                            let display_span = '';
                            let custom_class = '';
                            if ( sticker_permission == 'yes' ) {
                                display_span = '<span class="' + task_priority + '_custom"></span> ';
                                custom_class = task_status + '_custom';
                            }
                            let task_count = '';
                            if ( title_permission == 'yes' ) {
                                task_count = view_id;
                            } else {
                                task_count = '<i class="gg-check"></i>';
                            }
                            let task_label = '';
                            if ( task_status == 'complete' ) {
                                task_label = task_count;
                            } else {
                                task_label = view_id;
                            }                        
                            if ( author ) {
                                task_config_author_name_parts    = task_config_author_name.split(' ');
                                task_config_author_name_parts[1] = author;
                                task_config_author_name          = task_config_author_name_parts.join(' ');
                            }

                            jQuery("div#wpf_task_details .wpf_task_num_top").html(display_span + task_label);
                            jQuery('#wpf_task_details .wpf_task_num_top').removeClass('complete');
                            jQuery('#wpf_task_details .wpf_task_num_top').removeAttr('class').addClass('wpf_task_num_top ' + task_status + ' ' + custom_class);
                            jQuery("div#wpf_task_details .wpf_task_title_top").html(task_page_title);
                            jQuery("div#wpf_task_details .wpf_task_details_top").html(task_config_author_name);
                            jQuery("div#wpf_attributes_content #additional_information").html(additional_info_html);
                            if ( current_user_id == post_author_id || wpf_user_type == 'advisor' ) {
                                jQuery('#wpf_delete_task_container').html('<a href="javascript:void(0)" class="wpf_task_delete_btn"><i class="gg-trash"></i> ' + wpf_delete_ticket + '</a><p class="wpf_hide" id="wpf_task_delete">' + wpf_delete_conform_text2 + ' <a href="javascript:void(0);" class="wpf_task_delete" data-taskid=' + post_id + ' data-elemid=' + post_task_no + '>' + wpf_yes + '</a></p>');
                            } else {
                                jQuery('#wpf_delete_task_container').html('');
                            }
                            tss = task_status;
                            prr = task_priority;
                            jQuery("#task_task_status_attr").val(task_status);
                            jQuery("#task_task_priority_attr").val(task_priority);

                            var wpf_page_url = task_page_url;
                            if ( wpf_page_url && post_task_status == 'wpf_admin' ) {
                                var wpf_page_url_with_and = wpf_page_url.split('&')[1];
                                var wpf_page_url_question = wpf_page_url.split('?')[1];
                                if ( wpf_page_url_with_and ) {
                                    var saperater = '&';
                                }
                                if ( wpf_page_url_question ) {
                                    var saperater = '&';
                                } else {
                                    var saperater = '?';
                                }
                            } else {
                                var saperater = '?';
                            }
                            if ( wpf_task_screenshot == '' ) {
                                wpf_open_tab('wpf_message_content');
                            }
                            if ( post_task_type == 'general' ) {
                                jQuery("#wpfb_attr_task_page_link").attr("href", task_page_url + saperater + "wpf_general_taskid=" + post_id);
                            } else if ( post_task_type == 'email' ) {
                                jQuery("#wpfb_attr_task_page_link").attr("href", task_page_url + saperater + "wpf_general_taskid=" + post_id);                    
                            } else if ( post_task_type == 'graphics' ) {
                                wpf_open_tab('wpf_message_content');
                                jQuery("#wpfb_attr_task_page_link").attr("href", task_page_url + "&wpf_taskid=" + post_task_no);
                            } else {
                                jQuery("#wpfb_attr_task_page_link").attr("href", task_page_url + saperater + "wpf_taskid=" + post_task_no);
                            }
                            if ( typeof task_notify_users == 'string' ) {
                                var task_notify_users_arr = task_notify_users.split(',');
                            } else {
                                var task_notify_users_arr = [task_notify_users.toString()];
                            }
                            jQuery('#wpf_attributes_content input[name="author_list_task"]').each(function () {
                                jQuery(this).prop('checked', false);
                            });
                            jQuery('#wpf_attributes_content input[name="author_list_task"]').each(function () {
                                if ( jQuery.inArray(this.value, task_notify_users_arr) != '-1' ) {
                                    jQuery(this).prop('checked', true);
                                }
                            });

                            chat_form = get_wpf_message_form(post_id, post_author_id,task_is_internal);
                            jQuery('#wpf_message_form').html(chat_form);
                            
                            if ( onload_wpfb_tasks.data == 0 ) {
                                chat_form = get_wpf_message_form(post_id, post_author_id,task_is_internal);
                                jQuery('#wpf_message_form').html(chat_form);
                            } else {
                                var chat_form = get_wpf_message_form(post_id, post_author_id,task_is_internal);
                                jQuery('#wpf_message_form').html(chat_form);
                                // do not convert link to URl where AWS links are present
                                if ( onload_wpfb_tasks.data.search(/s3.us-east-2.amazonaws.com/) < 0 ) {
                                    onload_wpfb_tasks.data = onload_wpfb_tasks.data;
                                }
                                jQuery('ul#wpf_message_list').html(onload_wpfb_tasks.data);
                                jQuery('#wpf_task_screenshot').attr('src', wpf_task_screenshot);
                                jQuery('#wpf_task_screenshot_link').attr('href', wpf_task_screenshot);
                                jQuery('#all_tag_list').html(onload_wpfb_tasks.wpf_tags);
                            }
                            jQuery('#wpf_message_content').animate({scrollTop: jQuery('#wpf_message_content').prop("scrollHeight")}, 2000);
                            
                            // add rich text editor for Task center by Pratap
                            jQuery(document).find('.wpf-tc-editor, .wpf-editor').each(function() {
                                if ( ! jQuery_WPF(this).hasClass('activee') ) {
                                    var $this = jQuery_WPF(this);
                                    jQuery_WPF(this).addClass('activee');
                                    var quill = new Quill(this, {
                                        modules: {
                                            toolbar: [
                                                ['bold', 'italic', 'underline', 'strike'],
                                                [{ list: 'ordered' }, { list: 'bullet' }],
                                                ['link', 'code-block'],
                                            ]
                                        },
                                        placeholder: wpf_comment_box_placeholder,
                                        theme: 'bubble'   // Specify theme in configuration
                                    });
                                    quill.on('text-change', function(delta, oldDelta, source) {
                                        var isempty = isQuillEmpty( quill );
                                        if ( !isempty ) {
                                            $this.parent().find('textarea').val(quill.root.innerHTML);
                                        } else {
                                            $this.parent().find('textarea').val('');
                                        }
                                    });
                                }
                            });
                        }
                    }
                });
            }

            // Check if editor is empty before adding value to textarea.
            function  isQuillEmpty( quill ) {
                if ( ( quill.getContents()['ops'] || [] ).length !== 1) {
                    return false;
                }
                return quill.getText().trim().length === 0
            }
            </script>
            <?php
        }
    }
}

/*
 * This function is used to initial the Atarim and all related variables on the frontend.
 *
 * @input NULL
 * @return NULL
 */
function show_wpf_comment_button() {
    $wpf_active = wpf_check_if_enable();
    if ( $wpf_active == 1 || ( isset( $_GET['wpf_login'] ) && $_GET['wpf_login'] == 1 ) ) {
        global $wpdb, $wp_query, $post;
        $wpf_current_page_url     = "";
        $disable_for_admin        = 0;
        $currnet_user_information = wpf_get_current_user_information();
        $current_role             = $currnet_user_information['role'];
        $current_user_name        = $currnet_user_information['display_name'];
        $current_user_id          = $currnet_user_information['user_id'];
        $wpf_website_builder      = maybe_unserialize( get_site_data_by_key( 'wpf_website_developer' ) );
        $wpf_website_builder      = ! empty( $wpf_website_builder ) ? (array) $wpf_website_builder : array();
        if ( $current_user_name == 'Guest' ) {
            $wpf_website_client = get_site_data_by_key( 'wpf_website_client' );
            $wpf_current_role   = 'guest';
            if ( $wpf_website_client ) {
                $wpf_website_client_info = get_userdata( $wpf_website_client );
                if ( $wpf_website_client_info ) {
                    if ( $wpf_website_client_info->display_name == '' ) {
                        $current_user_name = $wpf_website_client_info->user_nicename;
                    } else {
                        $current_user_name = $wpf_website_client_info->display_name;
                    }
                }
            }
        } else {
            $wpf_current_role = wpf_user_type();
        }

        $current_user_name = addslashes( $current_user_name );
        $selected_roles    = get_site_data_by_key( 'wpf_selcted_role' );
        $selected_roles    = explode( ',', $selected_roles );
        if ( $wpf_current_role == 'advisor' ) {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_webmaster' );
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_webmaster' );
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_webmaster' );
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_webmaster' );
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_webmaster' );
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_webmaster' );
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_webmaster' );
            $wpf_tab_permission_display_stickers  = ( get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != 'no' ) ? 'yes' : 'no';
            $wpf_tab_permission_display_task_id   = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
            $wpf_tab_permission_keyboard_shortcut = ( get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_webmaster' ) != 'no' ) ? 'yes' : 'no';
        } else if ( $wpf_current_role == 'king' ) {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_client' );
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_client' );
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_client' );
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_client' );
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_client' );
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_client' );
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_client' );
            $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' );
            $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_client' );
            $wpf_tab_permission_display_task_id   = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
        } else if ( $wpf_current_role == 'council' ) {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_others' );
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_others' );
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_others' );
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_others' );
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_others' );
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_others' );
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_others' );
            $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' );
            $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_others' );
            $wpf_tab_permission_display_task_id   = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
        } else {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_guest' );
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_guest' );
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_guest' );
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_guest' );
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_guest' );
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_guest' );
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_guest' );
            $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_guest' );
            $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_guest' );
            $wpf_tab_permission_display_task_id   = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
        }

        $wpf_disable_for_admin = get_site_data_by_key( 'wpf_disable_for_admin' );
        if ( $wpf_disable_for_admin == 'yes' && $current_role == 'administrator' ) {
            $disable_for_admin = 1;
        } else {
            $disable_for_admin = 0;
        }

        $current_page_id = '';
        if ( is_admin() ) {
            $current_page_id = get_the_ID();
        }
        if ( $current_page_id == '' ) {
            if ( isset( $wp_query->post->ID ) ) {
                $current_page_id = $wp_query->post->ID;
            }
        }

        $current_page_title = addslashes( get_the_title( $current_page_id ) );
        $page_type          = "default";

        if ( class_exists( 'WooCommerce' ) ) {
            if ( is_category() ) {
                $page_type          = "archive";
                $category           = get_queried_object();
                $current_page_id    = $category->term_id;
                $current_page_url   = get_category_link( $current_page_id );
                $current_page_title = addslashes( get_cat_name( $current_page_id ) );
            } else if ( is_archive() && ( ! is_shop() ) && ( ! is_category() ) ) {
                if ( ! is_wp_error( get_term_link( get_query_var( 'term' ), get_query_var( 'taxonomy' ) ) ) ) {
                    $current_page_url = get_term_link( get_query_var( 'term' ), get_query_var( 'taxonomy' ) );
                } else {
                    $current_page_url = "";
                }
            } else if ( is_shop() ) {
                $current_page_url = get_permalink( wc_get_page_id( 'shop' ) );
            } else if ( is_home() ) {
                $current_page_url = get_permalink( get_option( 'page_for_posts' ) );
            } else {
                $current_page_url = get_permalink( $current_page_id );
            }
        } else {
            if ( is_category() ) {
                $page_type = "archive";
                $category = get_queried_object();
                $current_page_id = $category->term_id;
                $current_page_url = get_category_link( $current_page_id );
                $current_page_title = addslashes( get_cat_name( $current_page_id ) );
            } elseif ( is_post_type_archive() ) {
                $page_type = "archive";
                $category = get_queried_object();
                // Handle custom post type archive, no term_id available
                $post_type = get_post_type();
                $current_page_id = 9999999999; // No term_id for post type archive
                $current_page_url = get_post_type_archive_link( $post_type );
                $current_page_title = post_type_archive_title( '', false );
            } else if ( is_tag() ) { // tag archieve page
                $page_type          = "archive";
                $category           = get_queried_object();
                $current_page_id    = $category->term_id;
                $current_page_url   = get_category_link( $current_page_id );
                $current_page_title = $category->name;
            } else if ( is_tax() ) { // taxonomy archieve page
                $page_type          = "archive";
                $category           = get_queried_object();
                $current_page_id    = $category->term_id;
                $current_page_url   = get_category_link( $current_page_id );
                $current_page_title = $category->name;
            } else if ( is_home() ) {
                $current_page_url = get_permalink( get_option( 'page_for_posts' ) );
            } else if ( is_archive() && ( ! is_category() ) ) {
                $current_page_url = "";
            } else {
                $current_page_url = get_permalink( $current_page_id );
            }
        }
        //fallback if URL is not in the database
        $fallback_link = 0;
        if ( $current_page_id == '' || $current_page_id == 0 || $current_page_url == "" ) {
            $fallback_link    = 1;
            $current_page_id  = 0;
            $current_page_url = "";
        }
        $wpf_show_front_stikers  = get_site_data_by_key( 'wpf_show_front_stikers' );
        $unix_time_now           = time();
        $wpf_check_atarim_server = get_option( 'atarim_server_down_check' );
        if ( $unix_time_now > $wpf_check_atarim_server ) {
            update_option( 'atarim_server_down', 'false', 'no' );
        }
        $atarim_server_down            = get_option( 'atarim_server_down' );
        $wpfb_users                    = do_shortcode( '[wpf_user_list_front]' );
        $ajax_url                      = admin_url( 'admin-ajax.php' );
        $plugin_url                    = WPF_PLUGIN_URL;
        $sound_file                    = esc_url( plugins_url( 'images/wpf-screenshot-sound.mp3', __FILE__ ) );
        $wpf_tag_enter_img             = esc_url( plugins_url( 'images/enter.png', __FILE__ ) );
        $bubble_and_db_id              = get_last_task_id( true );
        $comment_count                 = $bubble_and_db_id['Dbid'];
        $bubble_comment_count          = $bubble_and_db_id['Bubbleid'];
        $wpf_check_page_builder_active = wpf_check_page_builder_active();

        /* =====Start filter sidebar HTML Structure==== */
        $is_site_archived                  = get_site_data_by_key( 'wpf_site_archived' );
        $backend_btn                       = '';
        $wpf_go_to_cloud_dashboard_btn_tab = '';
        if ( $current_user_id > 0 ) {
            if ( $wpf_current_role == 'advisor' ) {
                $wpf_go_to_cloud_dashboard_btn_tab = '<a href="' . WPF_APP_SITE_URL . '/login" target="_blank" class="wpf_filter_tab_btn cloud_dashboard_btn" title="' . __( "Atarim Dashboard", 'atarim-visual-collaboration' ) . '">'.get_wpf_icon().'</a>';
            }
            $sidebar_col = "wpf_col3";
            $backend_btn = ' <button class="wpf_tab_sidebar wpf_backend"  onclick="openWPFTab(\'wpf_backend\')" >' . __('Backend', 'atarim-visual-collaboration') . '</button>';
            $wpf_current_page_url = get_permalink() . '?wpf_login=1';
        } else {
            $sidebar_col = "wpf_col2";
        }

        $wpf_nonce     = wpf_wp_create_nonce();
        $wpf_admin_bar = 0;
        if ( is_admin_bar_showing() ) {
            $wpf_admin_bar = 1;
        }

        $restrict_plugin = get_option( 'restrict_plugin' );
        if ( $wpf_active == 1 && $wpf_check_page_builder_active == 0 && ( ! $is_site_archived ) ) {
            require_once( WPF_PLUGIN_DIR . 'inc/wpf_popup_string.php' );
            echo "<style>li#wp-admin-bar-wpfeedback_admin_bar {display: none !important;}</style>";
            if ( $current_page_id == 0 ) {
                echo "<script>var fallback_link_check = '$fallback_link', page_type = '$page_type', wpf_tag_enter_img = '$wpf_tag_enter_img', disable_for_admin = '$disable_for_admin', wpf_nonce = '$wpf_nonce', current_role = '$current_role', wpf_current_role = '$wpf_current_role', current_user_name = '$current_user_name', current_user_id = '$current_user_id', wpf_website_builder = " . json_encode( $wpf_website_builder ) . ", wpfb_users = '$wpfb_users', ajaxurl = '$ajax_url', current_page_url = window.location.href.split('?')[0], current_page_title = '$current_page_title', current_page_id = '$current_page_id', wpf_screenshot_sound = '$sound_file', plugin_url = '$plugin_url', comment_count = '$comment_count', bubble_comment_count = '$bubble_comment_count', wpf_show_front_stikers = '$wpf_show_front_stikers', wpf_tab_permission_user = '$wpf_tab_permission_user', wpf_tab_permission_priority = '$wpf_tab_permission_priority', wpf_tab_permission_status = '$wpf_tab_permission_status', wpf_tab_permission_screenshot = '$wpf_tab_permission_screenshot', wpf_tab_permission_information = '$wpf_tab_permission_information', wpf_tab_permission_delete_task = '$wpf_tab_permission_delete_task', wpf_tab_permission_auto_screenshot = '$wpf_tab_permission_auto_screenshot', wpf_admin_bar = '$wpf_admin_bar', wpf_tab_permission_display_stickers = '$wpf_tab_permission_display_stickers', wpf_tab_permission_display_task_id = '$wpf_tab_permission_display_task_id', wpf_tab_permission_keyboard_shortcut = '$wpf_tab_permission_keyboard_shortcut', restrict_plugin = '$restrict_plugin', atarim_server_down = '$atarim_server_down';</script>";
            } else {
                echo "<script>var fallback_link_check = '$fallback_link', page_type = '$page_type', wpf_tag_enter_img = '$wpf_tag_enter_img', disable_for_admin = '$disable_for_admin', wpf_nonce = '$wpf_nonce', current_role = '$current_role', wpf_current_role = '$wpf_current_role', current_user_name = '$current_user_name', current_user_id = '$current_user_id', wpf_website_builder = " . json_encode( $wpf_website_builder ) . ", wpfb_users = '$wpfb_users',  ajaxurl = '$ajax_url', current_page_url = '$current_page_url', current_page_title = '$current_page_title', wpf_current_screen = '', current_page_id = '$current_page_id', wpf_screenshot_sound = '$sound_file', plugin_url = '$plugin_url', comment_count = '$comment_count', bubble_comment_count = '$bubble_comment_count', wpf_show_front_stikers = '$wpf_show_front_stikers', wpf_tab_permission_user = '$wpf_tab_permission_user', wpf_tab_permission_priority = '$wpf_tab_permission_priority', wpf_tab_permission_status = '$wpf_tab_permission_status', wpf_tab_permission_screenshot = '$wpf_tab_permission_screenshot', wpf_tab_permission_information = '$wpf_tab_permission_information', wpf_tab_permission_delete_task = '$wpf_tab_permission_delete_task', wpf_tab_permission_auto_screenshot = '$wpf_tab_permission_auto_screenshot', wpf_admin_bar = $wpf_admin_bar, wpf_tab_permission_display_stickers = '$wpf_tab_permission_display_stickers', wpf_tab_permission_display_task_id = '$wpf_tab_permission_display_task_id', wpf_tab_permission_keyboard_shortcut = '$wpf_tab_permission_keyboard_shortcut', restrict_plugin = '$restrict_plugin', atarim_server_down = '$atarim_server_down';</script>";
            }
            $wpf_sidebar_closeicon = '<svg version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 357 357" enable-background="new 0 0 357 357" xml:space="preserve"><g><g id="close"><polygon fill="#F5325C" points="357,35.7 321.3,0 178.5,142.8 35.7,0 0,35.7 142.8,178.5 0,321.3 35.7,357 178.5,214.2 321.3,357 357,321.3 214.2,178.5 "/></g></g></svg>';
            if ( $disable_for_admin == 0 ) {
                $wpf_sidebar_style   = "opacity: 0; margin-right: -380px";
                $wpf_site_id         = get_option( 'wpf_site_id' );
                $bottom_style        = "";

                /* ================filter Tabs Content HTML================ */
                $wpf_task_status_filter_btn   = '<div id="wpf_filter_taskstatus" class=""><label class="wpf_filter_title">' . get_wpf_status_icon() . ' ' . __('Filter by Status:', 'atarim-visual-collaboration') . '</label>' . wp_feedback_get_texonomy_filter("task_status") . '</div>';
                $wpf_task_priority_filter_btn = '<div id="wpf_filter_taskpriority" class=""><label class="wpf_filter_title">' . get_wpf_priority_icon() . ' ' . __( "Filter by Priority:", 'atarim-visual-collaboration' ) . '</label>' . wp_feedback_get_texonomy_filter("task_priority") . '</div>';
                $wpf_sidebar_header = sidebar_header();
                $sidebar_tabs = sidebar_tabs();
                $sidebar_content = sidebar_content();
                $launcher = wpf_launcher();
                $bottom_bar_html = '';
                if ( is_feature_enabled( 'bottom_bar_enabled' ) && ! is_non_collab_screen() ) {
                    $bottom_bar_html = '<div id="wpf_already_comment" class="wpf_hide"><div class="wpf_notice_title">' . __( "Task already exist for this element.", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __("Write your message in the existing thread. <br>Here, we opened it for you.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= '<div id="pushed_to_media" class="wpf_hide"><div class="wpf_notice_title">' . __( "Pushed to Media Folder.", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __("The file was added to the website's media folder, you can now use it from the there.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= '<div id="wpf_reconnecting_task" class="wpf_hide" style="display: none;"><div class="wpf_notice_title">' . __( "Remapping task....", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __("Give it a few seconds. <br>Then, refresh the page to see the task in the new position.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= '<div id="wpf_reconnecting_enabled" class="wpf_hide" style="display: none;"><div class="wpf_notice_title">' . __( "Remap task", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __("Place the task anywhere on the page to pinpoint the location of the request.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= $launcher;
                    $bottom_bar_html .= '<div id="wpf_launcher" class="wpf_for_plugin_active_check" data-html2canvas-ignore="true" >
                                            <div class="wpf_sidebar_container">
                                                ' . $wpf_sidebar_header . $sidebar_tabs . '
                                                ' . $sidebar_content . '
                                            </div>' . generate_bottom_part_html() . '
                                        </div>';
                }

                echo $bottom_bar_html;
                $wpf_get_user_type = get_user_meta( $current_user_id, 'wpf_user_initial_setup', true );
                if ( $wpf_get_user_type == '' && $current_user_id && in_array( $current_role, $selected_roles ) ) {
                    $wpf_get_user_typpe = get_user_meta( $current_user_id, 'wpf_user_initial_setup', true );
                    $wpf_get_user_type  = esc_attr( wpf_user_type() );
                    $wpf_user_flow      = isset( $_GET['wpf-user-flow'] ) ? true : false;
                    if ( ! $wpf_get_user_type ) {
                        delete_option( 'wpf_app_user_flow' );
                    }
                    if ( isset( $_GET['wpf-user-flow'] ) && ! get_option( 'wpf_app_user_flow' ) ) {
                        update_option( 'wpf_app_user_flow', true );
                        $wpf_app_user_flow = true;
                        $wpf_user_flow     = true;
                    } else if ( isset( $_GET['wpf-existing-user-flow'] ) ) {
                        $wpf_app_user_flow = false;
                        $wpf_user_flow     = false;
                    } else {
                        $wpf_app_user_flow = true;
                        $wpf_user_flow     = true;
                    }
                }
                require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_general_task_modal.php' );
                require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_approve_page_modal.php' );
                require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_responsive_page_modal.php' );
                require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_restrictions_modal.php' );
            }
        }
        $wpf_enabled = get_site_data_by_key( 'enabled_wpfeedback' );

        if ( ! is_user_logged_in() && ( $wpf_enabled == 'yes' && ( ! $is_site_archived ) ) ) {
            require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_login_modal.php' );
        }
    }
}
add_action( 'wp_footer', 'show_wpf_comment_button' );

function wpf_check_permission() {
    $currnet_user_information = wpf_get_current_user_information();
    $current_role             = $currnet_user_information['role'];
    $current_user_name        = $currnet_user_information['display_name'];
    $current_user_id          = $currnet_user_information['user_id'];
    if ( $current_user_name == 'Guest' ) {
        $wpf_website_client = get_site_data_by_key( 'wpf_website_client' );
        $wpf_current_role   = 'guest';
        if ( $wpf_website_client ) {
            $wpf_website_client_info = get_userdata( $wpf_website_client );
            if ( $wpf_website_client_info ) {
                if ( $wpf_website_client_info->display_name == '' ) {
                    $current_user_name = $wpf_website_client_info->user_nicename;
                } else {
                    $current_user_name = $wpf_website_client_info->display_name;
                }
            }
	    }
    } else {
	    $wpf_current_role = wpf_user_type();
    }

    $current_user_name = addslashes($current_user_name);
    if ( $wpf_current_role == 'advisor' ) {
        $wpf_tab_permission_display_stickers = ( get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != 'no' ) ? 'yes' : 'no';
        $wpf_tab_permission_display_task_id  = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
    } else if ( $wpf_current_role == 'king' ) {
        $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' );
        $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' );
    } else if ( $wpf_current_role == 'council' ) {
        $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' );
        $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' );
    } else {
        $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_guest' );
        $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_guest' );
    }
}

function add_sticker_permission_to_head() {
    $currnet_user_information = wpf_get_current_user_information();
    $current_role             = $currnet_user_information['role'];
    $current_user_name        = $currnet_user_information['display_name'];
    $current_user_id          = $currnet_user_information['user_id'];
    if ( $current_user_name == 'Guest' ) {
        $wpf_website_client = get_site_data_by_key( 'wpf_website_client' );
        $wpf_current_role   = 'guest';
        if ( $wpf_website_client ) {
            $wpf_website_client_info = get_userdata( $wpf_website_client );
            if ( $wpf_website_client_info ) {
                if ( $wpf_website_client_info->display_name == '' ) {
                    $current_user_name = $wpf_website_client_info->user_nicename;
                } else {
                    $current_user_name = $wpf_website_client_info->display_name;
                }
            }
	    }
    } else {
	    $wpf_current_role = wpf_user_type();
    }
    $current_user_name = addslashes( $current_user_name );

    if ( $wpf_current_role == 'advisor' ) {
        $wpf_tab_permission_display_stickers = ( get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != 'no' ) ? 'yes' : 'no';
        $wpf_tab_permission_display_task_id  = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
    } elseif ( $wpf_current_role == 'king' ) {
        $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' );
        $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' );
    } elseif ( $wpf_current_role == 'council' ) {
	    $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' );
	    $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' );
    } else {
        $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_guest' );
        $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_guest' );
    }

    echo '<script>var new_global_sticker_permission = "' . $wpf_tab_permission_display_stickers . '", new_global_task_id_permission = "' . $wpf_tab_permission_display_task_id . '"</script>';
}
add_filter( 'admin_head', 'add_sticker_permission_to_head' );

/*
 * This function is used to detect if the page builder is active on the current running page.
 *
 * @input NULL
 * @return Boolean
 */
function wpf_check_page_builder_active() {
    $page_builder = 0;
    /* ========Check Divi editor Active======== */
    if ( isset( $_GET['et_fb'] ) || ( is_admin() && function_exists( 'et_pb_is_pagebuilder_used' ) && et_pb_is_pagebuilder_used() ) ) {
	$page_builder = 1;
    } else if ( isset( $_GET['page'] ) ) {
    	if ( $_GET['page'] == 'et_theme_builder' ) {
			$page_builder = 1;
		}
	} else if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) { /* ------Check wpbeaver editor Active------- */
	    $page_builder = 1;
    } else if ( isset( $_GET['brizy-edit'] ) || isset( $_GET['brizy-edit-iframe'] ) || isset( $_GET['brizy_post'] ) ) { /* ========Check brizy editor Active======== */
	    $page_builder = 1;
    } else if ( isset( $_GET['ct_builder'] ) || isset( $_GET['ct_template'] ) ) { /* =======Check oxygen editor Active======== */
	    $page_builder = 1;
    } else if ( isset( $_POST['cs_preview_state'] ) ) { /* =======Check Cornerstone editor Active======== */
	    $page_builder = 1;
    } else if ( isset( $_GET['vc_editable'] ) ) { /* ------Check Visual Composer Active======== */
	    $page_builder = 1;
    } else if ( isset( $_GET['action'] ) && $_GET['action'] == 'edit' && isset( $_GET['vcv-action'] ) && $_GET['vcv-action'] == 'frontend' ) {
        $page_builder = 1;
    } else if ( isset( $_GET['vcv-action'] ) && $_GET['vcv-action'] == 'frontend' ) {
        $page_builder = 1;
    } else if ( isset( $_GET['bricks'] ) ) { /* ------Check Bricks editor Active======== */
	    $page_builder = 1;
    } else if ( ! empty( $_GET ) && array_key_exists( 'is-editor-iframe', $_GET ) && $_GET['is-editor-iframe'] != '' ) { /* ------Check Generate Press editor Active======== */
        $page_builder = 1;
    } else if ( defined( 'ELEMENTOR_VERSION' ) ) { /* ------Check elementor editor Active======== */
        if ( \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
            $page_builder = 1;
        } else {
            $page_builder = 0;
        }
    } else if ( is_customize_preview() ) {
	    $page_builder = 1;
    } else {
	    $page_builder = 0;
    }
    //check if page is loaded inside iframe in visual composer editor
    if ( isset( $_SERVER['QUERY_STRING'] ) ) {
        if ( $_SERVER['QUERY_STRING'] != '' ) {
            $query_string = explode( '&', $_SERVER['QUERY_STRING'] );
            if ( in_array( 'vcv-editable=1', $query_string ) ) {
                $page_builder = 1;
            }
        }
    }
    // edit formidable form page
    if ( isset( $_GET['page'] ) && ( $_GET['page'] == 'formidable' || $_GET['page'] == 'formidable-styles' || $_GET['page'] == 'formidable-entries' || $_GET['page'] == 'formidable-views' ) ) {
        $page_builder = 1;
    }
    return $page_builder;
}


/**
 * Load the plugin text domain for translation.
 *
 */
function wpf_load_plugin_textdomain() {
    $wpf_active = wpf_check_if_enable();
    if ( $wpf_active == 1 ) {
        $domain = 'atarim-visual-collaboration';
        if ( is_user_logged_in() ) {
            $get_locale = get_user_locale( $user_id = 0 );
        } else {
            $get_locale = get_locale();
        }
        $locale = apply_filters( 'plugin_locale', $get_locale, $domain );
        load_textdomain( $domain, trailingslashit( WPF_PLUGIN_DIR . '/languages/' ) . $domain . '-' . $locale . '.mo' );
        load_plugin_textdomain( $domain, '', basename( plugin_dir_path( dirname( __FILE__ ) ) ) . '/languages/' );
    }
}
add_action( 'init', 'wpf_load_plugin_textdomain', 10 );

/**
 * Load the plugin brand color.
 *
 */
function wpf_load_brand_color() {
    $wpf_active = wpf_check_if_enable();
    if ( $wpf_active == 1 || is_admin() ) {
    ?>
        <style type="text/css">
            :root {
                --main-wpf-color: #<?php echo ( get_site_data_by_key( 'wpfeedback_color' ) != "" ) ? str_replace( '#', '', get_site_data_by_key( 'wpfeedback_color' ) ) : "6D5DF3"; ?>;
            }
        </style>
    <?php
    }
}
add_action( 'wp_footer', 'wpf_load_brand_color', 10 );
add_action( 'admin_footer', 'wpf_load_brand_color', 10 );


/*
 * function is used to get last task no
 */
function get_last_task_id( $returnBubbleId = false ) {
    $url                    = WPF_CRM_API . 'wp-api/site/taskCount';
    $sendarr                = array();
    $sendarr["wpf_site_id"] = get_option( 'wpf_site_id' );
    $sendtocloud            = wp_json_encode( $sendarr );
    $response               = wpf_send_remote_post( $url, $sendtocloud );
    $last_id                = 1;
    $bubble_id              = 1;
    if ( isset( $response['data'] ) ) {
        $last_id   = $response['data'] + 1;
        $bubble_id = $response['sitetaskid'] + 1;
    }
	if ( $returnBubbleId == true ) {
		$res             = array();
		$res['Dbid']     = $last_id;
		$res['Bubbleid'] = $bubble_id;
		return $res;
	}
    return $last_id;
}


/*
 * function is used to get site settings data
 * and stored in session
 */
function get_site_data() {
	$ret = 0;
    if ( ! is_user_logged_in() ) {
        if ( ! get_option( 'enabled_wpfeedback' ) == 'yes' ) {
            $ret = 1;
        } else {
            if ( ! get_option( 'wpf_allow_guest' ) == 'yes' ) {
                $ret = 1;
            } else {
                $ret = 0;
            }
        }
    }

    if ( $ret == 1 ) {
        return;
    }

    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { /* it's an Ajax call */
    } else if( get_option( 'wpf_license' ) != 'valid' ) {
	} else {
        $wpf_site_id = get_option('wpf_site_id');
        $args        = array(
            'wpf_site_id' => $wpf_site_id
        );
        $url         = WPF_CRM_API . 'get-site-data';
        $sendtocloud = wp_json_encode( $args );
        $res_data    = wpf_send_remote_post( $url, $sendtocloud );
        if ( isset( $res_data['status'] ) && $res_data['status'] == '200' && isset( $res_data['data'] ) ) {
            $site_data = $res_data['data'];
            if ( isset ( $site_data['wpfeedback_logo'] ) && strpos( $site_data['wpfeedback_logo'], 'api.atarim.io' ) > 0 ) {
                $site_data['wpfeedback_logo'] = esc_url( WPF_PLUGIN_URL . 'images/Atarim.svg' );
            }
            if ( isset ( $site_data['wpfeedback_favicon'] ) && strpos( $site_data['wpfeedback_favicon'], 'api.atarim.io' ) > 0 ) {
                $site_data['wpfeedback_favicon'] = esc_url( WPF_PLUGIN_URL . 'images/atarim_icon.svg' );
            }
            foreach ( $site_data as $key => $sdata ) {
                if ( ( $sdata == 0 || ! empty( $sdata ) ) && ( $key != 'wpf_license' ) ) {
                    update_option( $key, $sdata, 'no' );
                }
            }
        } else {
       }
    }
}


/*
 * function is used to get site notify user
 * and stored in session
 */
function get_notify_users() {
	$ret = 0;
    if ( ! is_user_logged_in() ) {
        if ( ! get_option( 'enabled_wpfeedback' ) == 'yes' ) {
            $ret = 1;
        } else {
            if ( ! get_option( 'wpf_allow_guest' ) == 'yes' ) {
                $ret = 1;
            } else {
                $ret = 0;
            }
        }
    }

    if ( $ret == 1 ) {
        return;
    }

    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { /* it's an Ajax call */
    } else if ( get_option( 'wpf_license' ) != 'valid' ) {
    } else {
        $wpf_site_id = get_option( 'wpf_site_id' );
        $args        = array(
            'wpf_site_id' => $wpf_site_id
        );

        $url         = WPF_CRM_API . 'wp-api/wpfuser/getNotifiedUsers';
        $sendtocloud = wp_json_encode( $args );
        $filterData  = wpf_send_remote_post( $url, $sendtocloud );

        if ( isset( $filterData['status'] ) && $filterData['status'] == '200' ) {
            $notify_users = $filterData['data'];
            if ( ! empty( $notify_users ) ) {
			    update_option( 'notify_users', $notify_users, "no" );
            } else {
                update_option( 'notify_users', '', "no" );
            }
        }else{
        }
    }
}

/**
 * function is used to get notify user, site data, filter data
 * combination of 3 CURL requests into one:-
 * get-wp-filter-data
 * get-site-data
 * wp-api/wpfuser/getNotifiedUsers
 */
function get_notif_sitedata_filterdata() {
	$ret = 0;
    if ( ! is_user_logged_in() ) {
        if ( ! get_option( 'enabled_wpfeedback' ) == 'yes' ) {
            $ret = 1;
        } else {
            if ( ! get_option( 'wpf_allow_guest' ) == 'yes' ) {
                $ret = 1;
            } else {
                $ret = 0;
            }
        }
    }

    if ( $ret == 1 ) {
        return;
    }

    if ( get_option( 'wpf_license' ) == 'valid' ) {
        $wpf_site_id = get_option( 'wpf_site_id' );
        $args        = array(
            'wpf_site_id' => $wpf_site_id
        );

        $url         = WPF_CRM_API . 'wp-api/site/get-meta-data';
        $sendtocloud = wp_json_encode( $args );
        $allData     = wpf_send_remote_post( $url, $sendtocloud );
        if ( isset( $allData['status'] ) && $allData['status'] == '200' ) {
            $notify_users    = $allData['data']['getNotifiedUsers']['data'];
            $res_data        = $allData['data']['get-site-data'];
            $fil_data        = $allData['data']['wp-filter-data'];
            $restrict_plugin = $allData['data']['limit'];
            $wpf_user_plan   = $allData['data']['plan'];

            if ( ! empty( $notify_users ) ) {
                update_option( 'notify_users', $notify_users, "no" );
            }
           
            if ( ! empty( $wpf_user_plan['upgrade_path'] ) ) {
                update_option( 'upgrade_url', $wpf_user_plan['upgrade_path'], "no" );
            }
            if ( ! empty( $fil_data['data'] ) ) {
                update_option( 'filter_data', $fil_data['data'], 'no' );
            }

			if ( isset( $res_data['status'] ) && $res_data['status'] == '200' && isset( $res_data['data'] ) ) {
				$site_data = $res_data['data'];
                /* ---- UPDATE BY SHAWN ON VERSION 2.0.9 ---- */
                foreach ( $site_data as $key => $sdata ) {
                    if ( ( $sdata == 0 || ! empty( $sdata ) ) && ( $key != 'wpf_license' ) ) {
                        update_option( $key, $sdata, 'no' );
                    }
                }

                // add the site archive settings
                if ( ! empty( $allData['site_archived'] ) && $allData['site_archived'] !== 0 ) {
                    update_option( 'wpf_site_archived', 0, 'no' );
                } else if ( ! empty( $allData['site_archived'] ) ) {
                    update_option( 'wpf_site_archived', 0, 'no' );
                }
			}
            // update the plan data
            update_option( 'wpf_user_plan', serialize( $wpf_user_plan ), 'no' );
        }
    }
}

/*
 * function is used to get site settings data by key
 */
function get_site_data_by_key( $key ) {
	$str = get_option( $key );
    return $str;
}

/*
 * function is used to update site settings data
 */
function update_site_data( $options ) {
    $args = array(
        'wpf_site_id' => get_option( 'wpf_site_id' ),
        'options' => $options
	);
    $url         = WPF_CRM_API . 'update-site-data';
    $sendtocloud = wp_json_encode( $args );
    $myposts     = wpf_send_remote_post( $url, $sendtocloud );
    if ( $myposts['status'] == 200 ) {
		get_notif_sitedata_filterdata();
        return 1;
    } else {
        return 0;
    }
}

function get_task_time_type( $date ) {
    $current    = strtotime( date( 'Y-m-d' ) );
    $datediff   = $date - $current;
    $difference = floor( $datediff / (60 * 60 * 24) );
    if ( $difference == 0 ) {
	    return 'today';
    } else if ( $difference > 1 ) {
		return 'Future Date';
    } else if ( $difference > 0 ) {
		return 'tomorrow';
    } else if ( $difference < -1 ) {
		return 'Long Back';
    } else {
		return 'yesterday';
    }
}

/*
 * This function is used to show notice if the license is not active.
 *
 * @input NULL
 * @return NULL
 */
function licence_invalid_notice() {
	if( get_option( 'wpf_license' ) != 'valid' && ( wpf_user_type() === 'advisor' ) ) {
	echo '<div class="notice notice-warning wpf_admin_notice">
            <div class="wpf_admin_notice_icon">
                <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1080 1080">
                    <defs>
                        <style>
                            .cls-1 {
                                fill: #fff;
                            }
                            .cls-2 {
                                fill: #052055;
                            }
                        </style>
                    </defs>
                    <title>Atarim Logo Inverted</title>
                    <g>
                        <g>
                            <polygon class="cls-1" points="937.344 785.955 746.1 856.215 851.972 1060.257 1080 1059.991 937.344 785.955"/>
                            <polygon class="cls-1" points="539.938 19.669 0 1059.991 228.152 1059.991 539.873 458.766 652.263 675.369 843.507 605.108 539.938 19.669"/>
                        </g>
                        <polygon class="cls-2" points="227.659 1060.331 373.967 778.521 1055.074 519.371 227.659 1060.331"/>
                    </g>
                </svg>
            </div>
            <div class="wpf_admin_notice_content">
                <div class="wpf_admin_notice_title">Welcome to Atarim 👋</div>
                Please activate your license to continue using the platform.
                <p class="admin_notice_footer"><i>* This notice is shown to you as the Webmaster.</i></p>
            </div>
            <div class="wpf_admin_notice_button_col"><a class="wpf_admin_notice_button" href="'. admin_url() .'admin.php?page=collaboration_page_permissions"><span class="dashicons dashicons dashicons-update"></span> Activate & Connect</a></div>
		</div>';
	}
}
add_action( 'admin_notices', 'licence_invalid_notice' );


/**
 * This notice will show on the admin when wpf_site_archived = 1 on the wp_options table
 */
function site_archived_notice()
{
    if ( get_site_data_by_key( 'wpf_site_archived' ) && ( wpf_user_type() === 'advisor' ) ) { ?>
        <div class="notice notice-warning wpf_admin_notice">
            <div class="wpf_admin_notice_icon">
                <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1080 1080">
                    <defs>
                        <style>
                            .cls-1 {
                                fill: #fff;
                            }
                            .cls-2 {
                                fill: #052055;
                            }
                        </style>
                    </defs>
                    <title>Atarim Logo Inverted</title>
                    <g>
                        <g>
                            <polygon class="cls-1" points="937.344 785.955 746.1 856.215 851.972 1060.257 1080 1059.991 937.344 785.955"/>
                            <polygon class="cls-1" points="539.938 19.669 0 1059.991 228.152 1059.991 539.873 458.766 652.263 675.369 843.507 605.108 539.938 19.669"/>
                        </g>
                        <polygon class="cls-2" points="227.659 1060.331 373.967 778.521 1055.074 519.371 227.659 1060.331"/>
                    </g>
                </svg>
            </div>
            <div class="wpf_admin_notice_content">
                Collaboration is disabled because this website has been archived on the Atarim Dashboard. To re-enable the plugin,
                please go to the <a href="<?php echo WPF_APP_SITE_URL; ?>" target=_blank >Websites</a> screen in your Atarim
                Dashboard and <strong>unarchive this website</strong>
                <p class="admin_notice_footer"><i>* This notice is shown to you as the Webmaster.</i></p>
            </div>
        </div>
        <?php 
    }
}
add_action( 'admin_notices', 'site_archived_notice' );

function avc_yoast() {
    ?>
    <style>
        #wpseo_meta {
            box-sizing: border-box;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 99999;
            height: 100vh;
            overflow-y: auto;
            padding: 25px 175px;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        #wpseo_meta::-webkit-scrollbar {
            display: none;
        }
        .postbox-header {
            border: none;
        }
        .postbox-header .handle-actions {
            display: none;
        }
        #wpseo_meta .inside {
            box-shadow: 0em 0em 3em 0em rgb(0 0 0 / 13%);
            padding: 25px;
            border-radius: 10px;
        }
        .wpseo-metabox-content {
            max-height: 600px;
            overflow-y: auto;
            max-width: 100%;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .wpseo-metabox-content::-webkit-scrollbar {
            display: none;
        }
        #wpseo-meta-section-content {
            max-width: 49%;
        }
        #avc-yoast-user-site {
            position: absolute;
            width: 49%;
            top: 0;
            right: 0;
            height: calc(100% - 96px);
            margin-top: 71px;
            overflow: hidden;
            margin-right: 10px;
            box-shadow: 0em 0em 3em 0em rgb(0 0 0 / 13%);
            border-radius: 5px;
        }
        #myFrame {
            width: 100%;
            height: 100%;
        }
        .avc_yoast_close {
            font-size: 32px;
            color: #e54f6d;
            font-weight: 500;
            line-height: 1;
            text-shadow: 0 1px 0 #fff;
            text-decoration: none;
            cursor: pointer;
            rotate: 45deg;
        }
        .avc_yoast_close:hover, .avc_yoast_close:focus {
            color: #e54f6d;
            text-decoration: none;
            outline: 0;
            box-shadow: none;
        }
        .avc_yoast_button {
            position: absolute;
            top: 15px;
            right: 0;
            display: flex;
            width: 49%;
            justify-content: space-between;
            margin-right: 10px;
        }
        .avc_yoast_button a {
            text-decoration: none;
            outline: 0;
        }
        .avc_yoast_button a:hover, .avc_yoast_button a:focus {
            text-decoration: none;
            outline: 0;
            box-shadow: unset;
        }
        .avc-yoast-prev-next {
            display: flex;
            justify-content: space-between;
        }
        .avc-yoast-prev, .avc-yoast-next, .avc-yoast-tmt {
            color: #272d3c;
            border-radius: 5px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            font-weight: 500;
            cursor: pointer;
            padding: 10px 20px;
            border: none;
        }
        .avc-yoast-prev {
            border: 1px solid #dde1e5;
        }
        .avc-yoast-prev-next a {
            margin-left: 15px;
        }
        .avc-yoast-next, .avc-yoast-tmt {
            background-color: #3ed696;
        }
    </style>
    <?php
}

function yoast_footer() {
    $current_page = get_permalink();
    $current_edit_url = get_edit_post_link();
    $next_post = $prev_post = '';
    $next = avc_yoast_prev_next('>');
    if ( $next ) {
        $next_post = get_edit_post_link( $next->ID ).'&yoast=true';
    }
    $prev = avc_yoast_prev_next('<');
    if ( $prev ) {
        $prev_post = get_edit_post_link( $prev->ID ).'&yoast=true';
    }
    ?>
    <script>
        var current_page = '<?php echo $current_page ?>';
        var current_edit_url = '<?php echo $current_edit_url ?>';
        var next_post = '<?php echo $next_post ?>';
        var prev_post = '<?php echo $prev_post ?>';
        var yoast_buttons = '<div class="avc_yoast_button"><div class="avc_yoast_tmet"><a href="'+ current_page +'" target="_blank" ><div class="avc-yoast-tmt"><i class="gg-external"></i>Take me there</div></a></div><div class="avc-yoast-prev-next"><a href="'+ prev_post +'" ><div class="avc-yoast-prev">Previous</div><a href="'+ next_post +'" ><div class="avc-yoast-next">Next</div></div></div>';
        var close_button = '<a href="'+ current_edit_url +'" class="avc_yoast_close" >+</a>';
        jQuery(document).ready(function() {
            jQuery('.wpseo-metabox-content').append('<div id="avc-yoast-user-site"></div>');
            jQuery('#wpseo_meta .postbox-header').append(close_button);
            jQuery('.wpseo-metabox-content').append(yoast_buttons);
            jQuery('#avc-yoast-user-site').append('<iframe src="'+ current_page +'" frameborder="0" style="transform: scale(0.58, .58) translate(-360px, -400px);width: 1000px; height: 1110px" id="myFrame"></iframe>');
            jQuery('#myFrame').load( function() {
                    jQuery('#myFrame').contents().find('head')
                .append(jQuery('<style type="text/css">html { margin-top: 0px !important; } #wpadminbar { display: none; }</style>'));
            });
        });
    </script>
    <?php
}
if ( isset( $_GET['yoast'] ) && $_GET['yoast'] == 'true' ) {
    add_action( 'admin_head', 'avc_yoast' );
    add_action( 'admin_footer', 'yoast_footer' );
}

function avc_yoast_prev_next( $type = '<', $offset = 0, $limit = 15 ) {
    global $post_ID, $wpdb;

    if ( $type != '<' ) {
        $type = '>';
    }
    $offset = (int) $offset;
    $limit  = (int) $limit;

    $post = get_post( $post_ID );

    $post_type = esc_sql( get_post_type( $post->ID ) );

    if ( ! $post ) {
        return false;
    }

    $sql = "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = '$post_type'AND post_status = 'publish'";

    // Determine order.
    $orderby = 'post_date';

    $datatype = in_array( $orderby, array( 'comment_count', 'ID', 'menu_order', 'post_parent' ) ) ? '%d' : '%s';
    $sql .= $wpdb->prepare( "AND {$orderby} {$type} {$datatype} ", $post->$orderby );

    $sort = $type == '<' ? 'DESC' : 'ASC';
    $sql .= "ORDER BY {$orderby} {$sort} LIMIT {$offset}, {$limit}";

    // Find the first post the user can actually edit.
    $posts = $wpdb->get_results( $sql );
    $result = false;
    if ( $posts ) {
        foreach ( $posts as $post ) {
            if ( current_user_can( 'edit_post', $post->ID ) ) {
                $result = $post;
                break;
            }
        }
        if ( ! $result ) { // The fetch did not yield a post editable by user, so query again.
            $offset += $limit;
            // Double the limit each time (if haven't found a post yet, chances are we may not, so try to get through posts quicker).
            $limit += $limit;
            return avc_yoast_prev_next( $type, $offset, $limit );
        }
    }
    return $result;
}

function add_custom_cookie_admin() {
    global $current_user;
    if ( is_user_logged_in() ) {
        $wpf_user_id = get_current_user_id();
        if ( ! isset( $_COOKIE['wordpress_manage_ip'] ) ) {
            // save user id in the cookie to use it on react side
            setcookie( 'wordpress_manage_ip', $wpf_user_id, time() + 86400, '/');
        } else if ( $_COOKIE['wordpress_manage_ip'] != $wpf_user_id ) {
            setcookie( 'wordpress_manage_ip', $wpf_user_id, time() + 86400, '/');
        }
    }
}
add_action('init', 'add_custom_cookie_admin');

// Adding side bar interface isnide Visual Composer editor
function myExamplePlugin_registerEditorScrips()
{
    wp_register_script(
        'vcv:myExamplePlugin:addon:editor:settingsPanel',
        plugin_dir_url(__FILE__) . 'visual-composer/public/dist/element.bundle.js',
        ['vcv:assets:vendor:script'],
        '1.0',
        true
    );

    // element bundle css
    wp_register_style(
        'vcv:myExamplePlugin:addon:editor:settingsPanel',
        plugin_dir_url(__FILE__) . 'visual-composer/public/dist/element.bundle.css',
        [],
        '1.0'
    );
}
add_action('init', 'myExamplePlugin_registerEditorScrips');
add_action(
    'vcv:api',
    function () {
        $filters = vchelper('Filters');
        $events = vchelper('Events');
        // listen for editor loading data request action:
        $filters->listen(
            'vcv:dataAjax:getData',
            function ($response, $payload) {
                // receive saved value
                $exampleInsights = get_post_meta($payload['sourceId'], '_vcv-exampleInsights', true);
                if (!empty($exampleInsights)) {
                    // pass the value to the editor with a specific key
                    $response['exampleInsights'] = $exampleInsights;
                }
                return $response; // must return response
            }
        );
        // listen for editor saving request action:
        $filters->listen(
            'vcv:dataAjax:setData',
            function ($response, $payload) {
                $requestHelper = vchelper('Request');
                // get our passed value from the editor
                $exampleInsights = $requestHelper->input('exampleInsights');
                $sourceId = $payload['sourceId'];
                // save the value for the current page
                update_post_meta($sourceId, '_vcv-exampleInsights', $exampleInsights);
                return $response; // must return response
            }
        );
        // listen for editor render action:
        $events->listen(
            'vcv:frontend:render',
            function ($sourceId) {
                wp_enqueue_script('vcv:myExamplePlugin:addon:editor:settingsPanel');
                wp_enqueue_style('vcv:myExamplePlugin:addon:editor:settingsPanel');
            }, 11
        );
    }
);