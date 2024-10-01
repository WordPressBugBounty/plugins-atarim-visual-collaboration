<?php
/*
 *  wpf_admin_function.pgp
 *  This file contains the code to initialize  the commenting on the backend.
 *  Below are the mentioned functions present in the file.
 */

/*
 * This function contains the code to initialize the sidebar and commenting feature on the backend. 
 *
 * @input NULL
 * @return NULL
 */
if ( ! function_exists( 'wpf_comment_button_admin' ) ) {
    function wpf_comment_button_admin() {
        global $wpdb;
        $disable_for_admin  = 0;
        $wpf_current_screen = '';
        if ( is_admin() ) {
            $wpf_current_screen = get_current_screen();
            $wpf_current_screen = $wpf_current_screen->id;
        }
        // STEP 1: Fetching current user information
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

        if ( $wpf_current_role == 'advisor' ) {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_user_webmaster' ) : 'no';
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_user_webmaster' ) : 'no';
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_status_webmaster' ) : 'no';
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_screenshot_webmaster' ) : 'no';
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_information_webmaster' ) : 'no';
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_delete_task_webmaster' ) : 'no';
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_auto_screenshot_task_webmaster' ) : 'no';
            $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) : 'no';
            $wpf_tab_permission_display_task_id   = get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) : 'no';
            $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_webmaster' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_webmaster' ) : 'no';
        } else if ( $wpf_current_role == 'king' ) {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_user_client' ) : 'no';
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_priority_client' ) : 'no';
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_status_client' ) : 'no';
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_screenshot_client' ) : 'no';
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_information_client' ) : 'no';
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_delete_task_client' ) : 'no';
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_client' ) != '' ? get_site_data_by_key( 'wpf_tab_auto_screenshot_task_client' ) : 'no';
            $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' ) : 'no';
            $wpf_tab_permission_display_task_id   = get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' ) : 'no';
            $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_client' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_client' ) : 'no';
        } else if ( $wpf_current_role == 'council' ) {
            $wpf_tab_permission_user              = get_site_data_by_key( 'wpf_tab_permission_user_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_user_others' ) : 'no';
            $wpf_tab_permission_priority          = get_site_data_by_key( 'wpf_tab_permission_priority_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_priority_others' ) : 'no';
            $wpf_tab_permission_status            = get_site_data_by_key( 'wpf_tab_permission_status_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_status_others' ) : 'no';
            $wpf_tab_permission_screenshot        = get_site_data_by_key( 'wpf_tab_permission_screenshot_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_screenshot_others' ) : 'no';
            $wpf_tab_permission_information       = get_site_data_by_key( 'wpf_tab_permission_information_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_information_others' ) : 'no';
            $wpf_tab_permission_delete_task       = get_site_data_by_key( 'wpf_tab_permission_delete_task_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_delete_task_others' ) : 'no';
            $wpf_tab_permission_auto_screenshot   = get_site_data_by_key( 'wpf_tab_auto_screenshot_task_others' ) != '' ? get_site_data_by_key( 'wpf_tab_auto_screenshot_task_others' ) : 'no';
            $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' ) : 'no';
            $wpf_tab_permission_display_task_id   = get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' ) : 'no';
            $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_others' ) != '' ? get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_others' ) : 'no';
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

        // STEP 3: Fetching current page url for task meta information
        $protocol = ( ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] != 'off' ) || $_SERVER['SERVER_PORT'] == 443 ) ? "https://" : "http://";

        $current_page_id = '';
        if ( isset( $_GET['action'] ) && $_GET['action'] == 'edit' && isset( $_GET['vcv-action'] ) && $_GET['vcv-action'] == 'frontend' && isset( $_GET['vcv-source-id'] ) ) {
            $current_page_id = $_GET['vcv-source-id'];
        }
        $current_page_url   = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $current_page_title = '';
        $current_option     = get_current_screen();
        if ( ! empty( $current_option ) ) {
            $current_page_title = $current_option->id;
        }

        // STEP 4: Fetching plugin options
        $wpf_disable_for_admin = get_site_data_by_key( 'wpf_disable_for_admin' );
        if ( $wpf_disable_for_admin == 'yes' && $current_role == 'administrator' ) {
            $disable_for_admin = 1;
        } else {
            $disable_for_admin = 0;
        }

        $wpf_show_front_stikers = get_site_data_by_key( 'wpf_show_front_stikers' );
        $wpfb_users             = do_shortcode( '[wpf_user_list_front]' );
        $ajax_url               = admin_url( 'admin-ajax.php' );
        $plugin_url             = WPF_PLUGIN_URL;
        $sound_file             = WPF_PLUGIN_URL . 'images/wpf-screenshot-sound.mp3';
        $wpf_tag_enter_img      = WPF_PLUGIN_URL . 'images/enter.png';
        $wpf_reconnect_icon     = WPF_PLUGIN_URL . 'images/wpf_reconnect.png';
        $bubble_and_db_id       = get_last_task_id(true);
        $comment_count          = $bubble_and_db_id['Dbid'];
        $bubble_comment_count   = $bubble_and_db_id['Bubbleid'];

        // STEP 5: Fetching options for task meta information
        $wpf_powered_class            = '_blank';
        $wpf_powered_by               = get_site_data_by_key( 'wpfeedback_powered_by' );
        $selected_roles               = get_site_data_by_key( 'wpf_selcted_role' );
        $current_user                 = wp_get_current_user();
        $wpf_user_name                = $current_user->display_name;
        $wpf_user_email               = $current_user->user_email;
        $wpf_allow_backend_commenting = get_site_data_by_key( 'wpf_allow_backend_commenting' );
        $selected_roles               = explode( ',', $selected_roles );
        $wpf_powerbylink              = WPF_MAIN_SITE_URL . '/reviews/?website=' . get_bloginfo( 'name' ) . '&email=' . $wpf_user_email . '&nameu=' . $wpf_user_name;
        $wpf_powerbylogo              = get_wpf_logo();
        if ( $wpf_powered_by == 'yes' ) {
            $wpf_powered_class = '_self';
            $wpf_powered_link  = get_site_data_by_key( 'wpf_powered_link' );
            if ( $wpf_powered_link != '' ) {
                $wpf_powerbylink   = esc_html( $wpf_powered_link );
                $wpf_powered_class = '_blank';
            } else {
                $wpf_powerbylink = "javascript:void(0)";
            }
        }

        // STEP 6: Checking if page builder is active on current page (if so then remove the feature of adding new task)
        $wpf_check_page_builder_active = wpf_check_page_builder_active();
       
        if ( class_exists( 'GeoDirectory' ) ) {
            if ( $wpf_current_screen == 'gd_place_page_gd_place-settings' ) {
                $wpf_check_page_builder_active = 1;
            }
        }

        // STEP 7: Initialize the structure of sidebar HTML+PHP
        $wpf_active       = wpf_check_if_enable();
        $is_site_archived = get_site_data_by_key( 'wpf_site_archived' );
        if ( $current_user_id > 0 ) {
            $sidebar_col = "wpf_col3";
        } else {
            $sidebar_col = "wpf_col2";
        }

        $wpf_check_atarim_server = get_option( 'atarim_server_down_check' );
        $unix_time_now           = time();
        if ( $unix_time_now > $wpf_check_atarim_server ) {
            update_option( 'atarim_server_down', 'false', 'no' );
        }

        $atarim_server_down = get_option( 'atarim_server_down' );
        $restrict_plugin    = get_option( 'restrict_plugin' );
        $wpf_nonce          = wpf_wp_create_nonce();
        echo "<script>var fallback_link_check = '', page_type = '', wpf_tab_permission_display_stickers = '$wpf_tab_permission_display_stickers', wpf_tab_permission_display_task_id = '$wpf_tab_permission_display_task_id';</script>";
        require_once( WPF_PLUGIN_DIR . 'inc/wpf_popup_string.php' );
        if ( $wpf_active == 1 && $wpf_check_page_builder_active == 0 && $wpf_allow_backend_commenting != 'yes' && $wpf_current_screen != 'settings_page_menu_editor' && $wpf_current_screen != 'collaborate_page_collaboration_page_settings' && ( ! $is_site_archived ) ) {
            echo "<script>var fallback_link_check = '', page_type = '', wpf_reconnect_icon = '$wpf_reconnect_icon', wpf_tag_enter_img = '$wpf_tag_enter_img', disable_for_admin = '$disable_for_admin', wpf_nonce = '$wpf_nonce', wpf_current_screen = '$wpf_current_screen', current_role = '$current_role', wpf_current_role = '$wpf_current_role', current_user_name = '$current_user_name', current_user_id = '$current_user_id', wpf_website_builder = " . json_encode( $wpf_website_builder ) . ", wpfb_users = '$wpfb_users',  ajaxurl = '$ajax_url', current_page_url = '$current_page_url', current_page_title = '$current_page_title', current_page_id = '$current_page_id', wpf_screenshot_sound = '$sound_file', plugin_url = '$plugin_url', comment_count = '$comment_count', bubble_comment_count = '$bubble_comment_count', wpf_show_front_stikers = '$wpf_show_front_stikers', wpf_tab_permission_user = '$wpf_tab_permission_user', wpf_tab_permission_priority = '$wpf_tab_permission_priority', wpf_tab_permission_status = '$wpf_tab_permission_status', wpf_tab_permission_screenshot = '$wpf_tab_permission_screenshot', wpf_tab_permission_information = '$wpf_tab_permission_information', wpf_tab_permission_delete_task = '$wpf_tab_permission_delete_task', wpf_tab_permission_auto_screenshot = '$wpf_tab_permission_auto_screenshot', wpf_tab_permission_keyboard_shortcut = '$wpf_tab_permission_keyboard_shortcut', wpf_admin_bar = 1, restrict_plugin = '$restrict_plugin', atarim_server_down = '$atarim_server_down';</script>";
            if ( $disable_for_admin == 0 ) {
                $wpf_site_id         = get_option( 'wpf_site_id' );

                /* ================filter Tabs Content HTML================ */
                $wpf_task_status_filter_btn   = '<div id="wpf_filter_taskstatus" class=""><label class="wpf_filter_title">' . get_wpf_status_icon() . ' ' . __( 'Filter by Status:', 'atarim-visual-collaboration' ) . '</label>' . wp_feedback_get_texonomy_filter( "task_status" ) . '</div>';
                $wpf_task_priority_filter_btn = '<div id="wpf_filter_taskpriority" class=""><label class="wpf_filter_title">' . get_wpf_priority_icon() . ' ' . __( "Filter by Priority:", 'atarim-visual-collaboration' ) . '</label>' . wp_feedback_get_texonomy_filter( "task_priority" ) . '</div>';
                $wpf_sidebar_header = sidebar_header();
                $sidebar_tabs = sidebar_tabs();
                $sidebar_content = sidebar_content();
                $launcher = wpf_launcher();
                $bottom_bar_html = '';
                if ( is_feature_enabled( 'bottom_bar_enabled' ) && ! is_non_collab_screen() ) {
                    $bottom_bar_html = '<div id="pushed_to_media" class="wpf_hide"><div class="wpf_notice_title">' . __( "Pushed to Media Folder.", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __( "The file was added to the website's media folder, you can now use it from the there.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= '<div id="wpf_already_comment" class="wpf_hide"><div class="wpf_notice_title">' . __( "Task already exist for this element.", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __( "Write your message in the existing thread. <br>Here, we opened it for you.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= '<div id="wpf_reconnecting_task" class="wpf_hide" style="display: none;"><div class="wpf_notice_title">' . __( "Remapping task....", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __( "Give it a few seconds. <br>Then, refresh the page to see the task in the new position.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= '<div id="wpf_reconnecting_enabled" class="wpf_hide" style="display: none;"><div class="wpf_notice_title">' . __( "Remap task", 'atarim-visual-collaboration' ) . '</div><div class="wpf_notice_text">' . __( "Place the task anywhere on the page to pinpoint the location of the request.", 'atarim-visual-collaboration' ) . '</div></div>';
                    $bottom_bar_html .= $launcher;
                    $bottom_bar_html .= '<div id="wpf_launcher" class="wpf_for_plugin_active_check" data-html2canvas-ignore="true" >
                                            <div class="wpf_sidebar_container">
                                                ' . $wpf_sidebar_header . $sidebar_tabs . '
                                                ' . $sidebar_content . '
                                            </div>' . generate_bottom_part_html() . '
                                        </div>';
                }
                echo $bottom_bar_html;
                require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_general_task_modal.php' );
                require_once( WPF_PLUGIN_DIR . 'inc/frontend/wpf_restrictions_modal.php' );
            }
        }
    }
}
add_action( 'admin_footer', 'wpf_comment_button_admin' );

/*
 * This function contains the code to remove the plugin initialization if Oxygen builder is detected.
 *
 * @input NULL
 * @return NULL
 */
if ( class_exists( 'Oxygen_Gutenberg' ) || isset( $_GET['ct_builder'] ) || isset( $_GET['ct_template'] ) || isset( $_GET['ct_inner'] ) ) {
    function remove_wp_foorer_action() {
        remove_action( 'wp_footer', 'show_wpf_comment_button' );
    }
    add_action( 'admin_init', 'remove_wp_foorer_action', 99 );
}

/*
 * This function contains the code to register the JS and CSS files to the page if plugin is active
 *
 * @input NULL
 * @return NULL
 */
if ( ! function_exists( 'wpfeedback_add_stylesheet_to_admin' ) ) {
    function wpfeedback_add_stylesheet_to_admin() {
        // only enque CSS and JS if tool is allowed.
        $wpf_check_page_builder_active = wpf_check_page_builder_active();
        if ( $wpf_check_page_builder_active == 0 ) {
            $is_site_archived      = get_site_data_by_key( 'wpf_site_archived' );
            $wpf_current_screen_id = '';
            if ( is_admin() ) {
                $wpf_current_screen    = get_current_screen();
                $wpf_current_screen_id = $wpf_current_screen->id;
            }

            /*===========Removed WPF on mailpoet plugin related in all pages ==========*/
            $mailpoet_page = array( 'mailpoet_page_mailpoet-segments', 'admin_page_mailpoet-newsletter-editor', 'toplevel_page_mailpoet-newsletters', 'mailpoet_page_mailpoet-forms', 'mailpoet_page_mailpoet-subscribers', 'mailpoet_pa', 'ge_mailpoet-segments', 'mailpoet_page_mailpoet-dynamic-segments', 'mailpoet_page_mailpoet-settings', 'mailpoet_page_mailpoet-help', 'mailpoet_page_mailpoet-premium' );
            if ( in_array( $wpf_current_screen_id, $mailpoet_page ) ) {
                if ( is_plugin_active( 'mailpoet/mailpoet.php' ) ) {
                    remove_action( 'admin_footer', 'wpf_comment_button_admin' );
                }
            }
            /*===========End mailpoet plugin==========*/

            /*===========Removed WPF on gravity forms plugin related in all pages ==========*/
            $gravityf_page = array( 'forms_page_gf_new_form', 'toplevel_page_gf_edit_forms', 'forms_page_gf_entries', 'forms_page_gf_settings', 'forms_page_gf_export', 'forms_page_gf_addons', 'forms_page_gf_system_status', 'forms_page_gf_help' );
            if ( in_array( $wpf_current_screen_id, $gravityf_page ) ) {
                    remove_action( 'admin_footer', 'wpf_comment_button_admin' );
            }
            /*===========End gravity forms plugin==========*/

            $wpf_license = get_option( 'wpf_license' );

            wp_register_style( 'wpf_wpf-icons', WPF_PLUGIN_URL . 'css/wpf-icons.css', false, WPF_VERSION );
            wp_enqueue_style( 'wpf_wpf-icons' );

            wp_register_style( 'wpf-admin-setting-style', WPF_PLUGIN_URL . 'css/admin-settings.css', false, strtotime( "now" ) );
            wp_enqueue_style( 'wpf-admin-setting-style' );
            
            if ( $wpf_current_screen_id != 'settings_page_menu_editor' ) {
                wp_register_style( 'wpf_admin_style', WPF_PLUGIN_URL . 'css/admin.css', false, strtotime( "now" ) );
                wp_enqueue_style( 'wpf_admin_style' );
            }

            wp_register_style( 'wpf_wpf-common', WPF_PLUGIN_URL . 'css/wpf-common.css', false, strtotime( "now" ) );
            wp_enqueue_style( 'wpf_wpf-common' );

            wp_register_style( 'wpf_rt_style', WPF_PLUGIN_URL . 'css/quill.css', false, strtotime( "now" ) );
            wp_enqueue_style( 'wpf_rt_style' );

            wp_register_script( 'wpf_rt_script', WPF_PLUGIN_URL . 'js/quill.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_rt_script' );

            wp_register_script( 'wpf_jquery_script', WPF_PLUGIN_URL . 'js/atarimjs.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_jquery_script' );

            if ( isset( $_GET['page'] ) && $_GET['page'] == "collaboration_page_settings" ) {
                wp_register_script( 'pickr', WPF_PLUGIN_URL . 'js/pickr.min.js', null, null, true );
                wp_enqueue_script( 'pickr' );
                wp_register_script( 'cpickr', WPF_PLUGIN_URL . 'js/cpickr.js', null, null, true );
                wp_enqueue_script( 'cpickr' );
            }
            wp_register_style( 'pickr_monolith', WPF_PLUGIN_URL . 'css/monolith.min.css' );
            wp_enqueue_style( 'pickr_monolith' );

            wp_register_script( 'wpf_admin_script', WPF_PLUGIN_URL . 'js/admin.js', array(), strtotime( "now" ), true );
            wp_enqueue_script( 'wpf_admin_script' );
            
            $wpf_user_type = wpf_user_type();
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
            wp_localize_script( 'wpf_admin_script', 'logged_user', array( 'current_user' => $wpf_user_type, 'author_img' => $avatar_url, 'author' => $display_name, 'site_url' => WPF_SITE_URL, 'wpside' => 'backend' ) );
            
            $feature = array();
            $edit    = is_feature_enabled( 'edit' );
            if ( ! $edit ) {
                $feature[] = 'edit';
            }
            wp_localize_script( 'wpf_admin_script', 'blocked', $feature );

            $upgrade_url = get_option( 'upgrade_url' );
            wp_localize_script( 'wpf_admin_script', 'upgrade_url', array( 'url' => $upgrade_url, 'plugin_url' => WPF_PLUGIN_URL ) );

            wp_register_script( 'wpf_jscolor_script', WPF_PLUGIN_URL . 'js/jscolor.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_jscolor_script' );

            wp_register_script( 'wpf_browser_info_script', WPF_PLUGIN_URL . 'js/wpf_browser_info.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_browser_info_script' );

            wp_enqueue_script( 'wpf_lottie_script', 'https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js', array(), strtotime( "now" ), true );

            wp_register_script( 'wpf_popper_script', WPF_PLUGIN_URL . 'js/popper.min.js', array(), WPF_VERSION, true );
            wp_enqueue_script( 'wpf_popper_script' );
            if ( ! is_non_collab_screen() ) {
                wp_register_script( 'wpf_common_functions', WPF_PLUGIN_URL . 'js/wpf_common_functions.js', array(), strtotime( "now" ), true );
                wp_enqueue_script( 'wpf_common_functions' );
            }

            wp_enqueue_media();

            /* ===========Admin Side================ */
            /*=====Start Check customize.php====*/
            if ( class_exists( 'GeoDirectory' ) ) {
                if ( $wpf_current_screen_id == 'gd_place_page_gd_place-settings' ) {
                    $wpf_check_page_builder_active = 1;
                }
            }
            /*=====END check customize.php====*/
            $enabled_wpfeedback           = wpf_check_if_enable();
            $wpf_allow_backend_commenting = get_site_data_by_key( 'wpf_allow_backend_commenting' );

            if ( $wpf_allow_backend_commenting != 'yes' ) {
                if ( $wpf_current_screen_id != 'events' && ( ( isset( $_GET['page'] ) && $_GET['page'] != "updraftplus" ) || ( defined( 'BSF_AIOSRS_PRO_VER' ) == false && class_exists( 'BSF_AIOSRS_Pro_Markup' ) == false ) ) ) {
                    wp_register_script( 'wpf_jquery_ui_script', WPF_PLUGIN_URL . 'js/atarim-ui.js', array(), WPF_VERSION, true );
                    wp_enqueue_script( 'wpf_jquery_ui_script' );
                }

                wp_register_script( 'wpf_popper_script', WPF_PLUGIN_URL . 'js/popper.min.js', array(), WPF_VERSION, true );
                wp_enqueue_script( 'wpf_popper_script' );

                if ( $wpf_current_screen_id != 'settings_page_menu_editor' && ( isset( $_GET['page'] ) && $_GET['page'] != "formidable" ) ) {
                    wp_register_script( 'wpf_bootstrap_script', WPF_PLUGIN_URL . 'js/bootstrap.min.js', array(), WPF_VERSION, true );
                    wp_enqueue_script( 'wpf_bootstrap_script' );
                }
            }

            $wpf_exclude_page = array( "wp-feedback_page_collaboration_task_center", "wp-feedback_page_collaboration_page_settings", "wp-feedback_page_collaboration_page_permissions" );
            if ( $enabled_wpfeedback == 1 && $wpf_allow_backend_commenting != 'yes' && ( ! $is_site_archived ) ) {
                if ( ! in_array( $wpf_current_screen_id, $wpf_exclude_page ) ) {
                
                }
                if ( ! in_array( $wpf_current_screen_id, $gravityf_page ) ) {
                
                }
                if ( $wpf_check_page_builder_active == 0 && $wpf_current_screen_id != 'settings_page_menu_editor' ) {

                    wp_register_script( 'wpf_app_script', WPF_PLUGIN_URL . 'js/admin/admin_app.js', array(), strtotime( "now" ), true );
                    wp_enqueue_script( 'wpf_app_script' );

                    wp_register_script( 'wpf_html2canvas_script', WPF_PLUGIN_URL . 'js/html2canvas.js', array(), WPF_VERSION, true );
                    wp_enqueue_script( 'wpf_html2canvas_script' );

                    wp_register_script( 'wpf_custompopover_script', WPF_PLUGIN_URL . 'js/custompopover.js', array(), WPF_VERSION, true );
                    wp_enqueue_script( 'wpf_custompopover_script' );

                    wp_register_script( 'wpf_selectoroverlay_script', WPF_PLUGIN_URL . 'js/selectoroverlay.js', array(), WPF_VERSION, true );
                    wp_enqueue_script( 'wpf_selectoroverlay_script' );

                    wp_register_script( 'wpf_xyposition_script', WPF_PLUGIN_URL . 'js/xyposition.js', array(), WPF_VERSION, true );
                    wp_enqueue_script( 'wpf_xyposition_script' );

                    if ( ! defined( 'WDT_BASENAME' ) || ! defined( 'WDT_ROOT_PATH' ) ) {
                        wp_register_script( 'wpf_bootstrap_script', WPF_PLUGIN_URL . 'js/bootstrap.min.js', array(), WPF_VERSION, true );
                        wp_enqueue_script( 'wpf_bootstrap_script' );
                    }
                }
            }
        }
    }
}
add_action( 'admin_enqueue_scripts', 'wpfeedback_add_stylesheet_to_admin' );

/*
 * This function contains the code to load all the tasks on the admin side as well as the comments inside the tasks.
 *
 * @input NULL
 * @return JSON
 */
if ( ! function_exists( 'load_wpfb_tasks_admin' ) ) {
    function load_wpfb_tasks_admin() {
        ob_clean();
        global $wpdb, $current_user;
        wpf_security_check();
        $response = array();

       if ( isset( $_POST['wpf_current_screen'] ) && $_POST['wpf_current_screen'] != '' && $_POST['all_page'] != 1 ) {               
            global $wp;
            $post_data = array(
                'wpf_site_id'        => get_option( 'wpf_site_id' ),
                'wpf_current_screen' => sanitize_text_field( $_POST['wpf_current_screen'] ),
                'task_types'         => ['general','page'],
                "sort"               =>  ["task_title", "created_at"],
                "sort_by"            => "asc",
                'url'                => WPF_HOME_URL,
                "is_admin_task"      => 1
            );

            $url         = WPF_CRM_API . 'wp-api/all/task';
            $sendtocloud = wp_json_encode( $post_data );
            $wpfb_tasks  = wpf_send_remote_post( $url, $sendtocloud );
        } else {
            $page_no = $_POST['page_no'];
            $post_data = array(
                'wpf_site_id'     => get_option( 'wpf_site_id' ),
                'task_types'      => [],
                "current_page_id" => '',
                'post_type'       => 'wpfeedback',
                'numberposts'     => -1,
                'limit'           => 20,
                'page_no'         => $page_no,
                'post_status'     => 'any',
                'orderby'         => 'date',
                'order'           => 'DESC',
                'url'             => WPF_HOME_URL,
                'is_admin_task'   => 1
            );

            $url         = WPF_CRM_API . 'wp-api/all/task';
            $sendtocloud = wp_json_encode( $post_data );
            $wpfb_tasks  = wpf_send_remote_post( $url, $sendtocloud );
        }
        
        if ( ! empty( $wpfb_tasks ) ) {
            $response = process_task_response( $wpfb_tasks );
        }

        // Tags.
        if ( ! empty( $wpfb_tasks ) && isset( $wpfb_tasks['wpf_all_tags'] ) && ! is_null( $wpfb_tasks['wpf_all_tags'] ) ) {
            $response['wpf_all_tags'] = $wpfb_tasks['wpf_all_tags'];
        }

        ob_end_clean();
        echo wp_json_encode( $response );
        exit;
    }
}
add_action( 'wp_ajax_load_wpfb_tasks_admin', 'load_wpfb_tasks_admin' );
add_action( 'wp_ajax_nopriv_load_wpfb_tasks_admin', 'load_wpfb_tasks_admin' );

/*
 * This function contains the code to disable the commenting on the admin side (If option is selected from the admin)
 *
 * @input NULL
 * @output NULL
 */
if ( ! function_exists( 'wpf_disable_comment_for_admin_page' ) ) {
    function wpf_disable_comment_for_admin_page() {
        $response = 0;
        if ( is_admin() ) {
            $wpf_current_screen = get_current_screen();
            if ( $wpf_current_screen ) {
                $wpf_current_screen_id = $wpf_current_screen->id;
                if ( $wpf_current_screen_id == 'toplevel_page_tvr-microthemer' ) {
                    remove_action( 'admin_footer', 'wpf_comment_button_admin' );
                    wp_dequeue_script( 'wpf_app_script' );
                    ?>
                    <script>
                    jQuery(window).load(function() {
                        jQuery("#viframe").contents().find("body").find("#wpf_launcher").css("display","none");
                        jQuery("#viframe").contents().find("body").find(".wpfb-point").css("display","none");
                    });
                    </script>
                <?php }
                if ( $wpf_current_screen_id == 'nav-menus' ) {
                    if ( function_exists( '_QuadMenu' ) ) {
                        remove_action( 'admin_footer', 'wpf_comment_button_admin' );
                        remove_action( 'admin_enqueue_scripts', 'wpfeedback_add_stylesheet_to_admin' );
                        wp_dequeue_script( 'wpf_app_script' );
                        wp_dequeue_script( 'wpf_bootstrap_script' );
                    }
                }
            }
        }
    }
}
add_action( 'admin_head', 'wpf_disable_comment_for_admin_page', 10 );

// Remove admin notice of Feedback tool.
function remove_feedbacktool_notice() {
    wpf_security_check();
    if ( is_user_logged_in() ) {
        update_option('wp_feedback_notice', 'false', 'no');
    }
}
add_action( 'wp_ajax_remove_feedbacktool_notice', 'remove_feedbacktool_notice' );
add_action( 'wp_ajax_nopriv_remove_feedbacktool_notice', 'remove_feedbacktool_notice' );