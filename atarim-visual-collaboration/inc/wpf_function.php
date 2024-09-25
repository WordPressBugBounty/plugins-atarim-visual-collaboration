<?php
/*
 * wpf_functions.php
 * This file contains the helper functions called from across the plugin.
 */

/*
 * This function is used to get the checkbox of task status / priority from the terms on website.
 *
 * @input String
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_texonomy' ) ) {
    function wp_feedback_get_texonomy( $my_term ) {
        $filter_data = get_option( 'filter_data' );
        $filter_data_db = $filter_data[$my_term];
        if ( isset( $filter_data_db ) && ! empty( $filter_data_db ) ) {
            echo '<ul class="wp_feedback_filter_checkbox">';
            foreach ( $filter_data_db as $term ) {
                if ( $term['label'] == 'In Progress' ) {
                    $term['label'] = 'In Prog';
                } elseif ( $term['label'] == 'Pending Review' ) {
                    $term['label'] = 'Pending';
                } else {
                    $term['label'] = $term['label'];
                }
                echo '<li><input onclick="wp_feedback_filter()" type="checkbox" name="' . $my_term . '" value="' . $term['value'] . '" class="wp_feedback_task wpf_checkbox"  id="' . $term['value'] . '"/><label for="' . $term['value'] . '">' . __( $term['label'], 'atarim-visual-collaboration' ) . '</label></li>';
            }
            echo '</ul>';
        }
    }
}

/*
 * This function is used to get all the roles allowed to use Atarim features.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_user_role_list' ) ) {
    function wp_feedback_get_user_role_list() {
        $editable_roles = get_editable_roles();
        return $editable_roles;
    }
}
add_shortcode( 'wpf_user_role_list', 'wp_feedback_get_user_role_list' );

/*
 * This function is used to get all the users (based on role) which are supposed to get notified. This is called in the Tasks Center for "Filters" section.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_user_list' ) ) {
    function wp_feedback_get_user_list() {
        $get_notif_user_db = get_option( 'notify_users' );
        $notify_users      = isset( $get_notif_user_db ) ? $get_notif_user_db : [];

        if ( is_array( $notify_users ) ) {
            if ( count( $notify_users ) > 0) {
                echo '<ul class="wp_feedback_filter_checkbox user">';
                if ( ! empty( $notify_users ) ) {
                    foreach ( $notify_users as $user ) {
                        $wpfusr     = get_user_by( 'id', htmlspecialchars( $user['wpf_id'], ENT_QUOTES, 'UTF-8' ) );
                        $wpfusrname = $wpfusr->display_name;
                        echo '<li><input onclick="wp_feedback_filter()"  type="checkbox" name="author_list" value="' . $user['value'] . '" class="wp_feedback_task wpf_checkbox" data-wp-username="' . $user['label'] . '"  id="user_' . $user['value'] . '" /><label for="user_' . $user['value'] . '" class="wpf_checkbox_label">' . $wpfusrname . '</label></li>';
                    }
                }
                echo '</ul>';
            }
        }
    }
}
add_shortcode( 'wpf_user_list', 'wp_feedback_get_user_list' );

/*
 * This function is used to get all the users (based on role) which are supposed to get notified. This is called in the Tasks Center for the "Notify Users" section.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_user_list_task' ) ) {
    function wp_feedback_get_user_list_task() {
        $get_notif_user_db = get_option( 'notify_users' );
        $notify_users      = isset( $get_notif_user_db ) ? $get_notif_user_db : [];
        if ( is_array( $notify_users ) ) {
            if ( count( $notify_users ) > 0 ) {
                echo '<ul class="wp_feedback_filter_checkbox user">';
                if ( ! empty( $notify_users ) ) {
                    foreach ( $notify_users as $user ) {
                        $wpfusr      = get_user_by( 'id', htmlspecialchars( $user['wpf_id'], ENT_QUOTES, 'UTF-8' ) );
                        $wpfusr_meta = get_user_meta( $wpfusr->ID );
                        $user_name   = $wpfusr->display_name;
                        echo '<li><input type="checkbox" name="author_list_task" value="' . $user['value'] . '" class="wp_feedback_task wpf_checkbox" data-wp-username="' . $user['label'] . '" id="' . $user['value'] . '" onclick="update_notify_user(' . $user['value'] . ')" /><label for="' . $user['value'] . '" class="wpf_checkbox_label">' . $user_name . '</label></li>'; //!push
                    }
                }
                echo '</ul>';
            }
        }
    }
}
add_shortcode( 'wpf_user_list_task', 'wp_feedback_get_user_list_task' );

/*
 * This function is used to get all the users (based on role) which are supposed to get notified. This is called in the frontend on the Tasks Popup.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_user_list_front' ) ) {
    function wp_feedback_get_user_list_front() {
	    $response          = [];
        $get_notif_user_db = get_option( 'notify_users' );
        $notify_users      = isset( $get_notif_user_db ) ? $get_notif_user_db : [];

        if ( is_array( $notify_users ) ) {
            if ( count( $notify_users ) > 0 ) {
                if ( ! empty( $notify_users ) ) {
                    foreach ( $notify_users as $user ) {
                        $userdetails              = get_user_by( 'id', htmlspecialchars( $user['wpf_id'], ENT_QUOTES, 'UTF-8' ) );
                        $response[$user['value']] = array(
                            "id"          => htmlspecialchars( $userdetails->ID, ENT_QUOTES, 'UTF-8' ), 
                            "username"    => htmlspecialchars( $user['label'], ENT_QUOTES, 'UTF-8' ), 
                            "displayname" => htmlspecialchars( $userdetails->display_name, ENT_QUOTES, 'UTF-8' ), 
                            "first_name"  => htmlspecialchars( $userdetails->first_name, ENT_QUOTES, 'UTF-8' ), 
                            "last_name"   => htmlspecialchars( $userdetails->last_name, ENT_QUOTES, 'UTF-8' )
                        );
                    }
                }
            }
        }
        return wp_json_encode( $response );
    }
}
add_shortcode( 'wpf_user_list_front', 'wp_feedback_get_user_list_front' );

/*
 * This function is used to save the Settings of the plugin when saved from the "Settings" tab.
 *
 * @input NULL
 * @return Redirect
 */
if ( ! function_exists( 'process_wpfeedback_options' ) ) {
    function process_wpfeedback_options() {
        if ( !check_if_allowed_to_save_settings() ) {
            wp_die( 'Unauthorized access' );
        }
	    $options = [];   
        // Check that user has proper security level
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed' );
        }
	
	    if ( ! empty( $_FILES ) ) {
            foreach ( $_FILES as $key => $file ) {
                if ( is_array( $file ) ) {
                    $temp_wpf_file_name = $file["name"];
                    $temp_wpf_file_type = $file["type"];
                    $fname              = explode( ".", $temp_wpf_file_name );
                    $temp_name          = $file['tmp_name'];
                    if ( empty( $temp_name ) ) {
                        continue;
                    }
                    $data    = file_get_contents( $temp_name );
                    $invalid = 0;
                    if ( ! in_array( $temp_wpf_file_type, array( 'image/jpeg', 'image/png' ) ) ) {
                        $invalid = 1;
                    }

                    if ( $invalid == 0 ) {
                        $base64_image = 'data:' . $temp_wpf_file_type . ';base64,' . base64_encode( $data );
                        if ( $key == 'wpf_logo_file' ) {
                            $options['logo']['image']     = $base64_image;
                            $options['logo']['file_name'] = str_replace( ' ', '_', trim( $fname[0] ) );
                            $options['logo']['type']      = $temp_wpf_file_type;
                        }
                        if ( $key == 'wpf_favicon_file' ) {
                            $options['favicon']['image']     = $base64_image;
                            $options['favicon']['file_name'] = str_replace( ' ', '_', trim( $fname[0] ) );
                            $options['favicon']['type']      = $temp_wpf_file_type;
                        }
                    }
                }
            }
        }

        $options['enabled_wpfeedback']           = isset( $_POST['enabled_wpfeedback'] ) ? 'yes' : 'no';
        $options['wpf_enable_clear_cache']       = isset( $_POST['wpf_enable_clear_cache'] ) ? 'yes' : 'no';
        $options['delete_data_wpfeedback']       = isset( $_POST['delete_data_wpfeedback'] ) ? 'yes' : 'no';
        $options['wpf_allow_backend_commenting'] = isset( $_POST['wpf_allow_backend_commenting'] ) ? 'yes' : 'no';
        $options['wpf_show_front_stikers']       = isset( $_POST['wpf_show_front_stikers'] ) ? 'yes' : 'no';
        $options['wpf_from_email']               = $_POST['wpf_from_email'];
        $options['wpfeedback_more_emails']       = $_POST['wpfeedback_more_emails'];
        $options['wpfeedback_powered_by']        = isset( $_POST['wpfeedback_powered_by'] ) ? 'yes' : 'no';
        $options['wpfeedback_color']             = $_POST['wpfeedback_color'];
        $options['wpf_powered_link']             = sanitize_text_field( $_POST['wpf_powered_link'] );
        $options['wpfeedback_powered_by']        = isset( $_POST['wpfeedback_powered_by'] ) ? 'yes' : 'no';
        $options['wpf_every_new_task']           = isset( $_POST['wpf_every_new_task'] ) ? 'yes' : 'no';
        $options['wpf_every_new_comment']        = isset( $_POST['wpf_every_new_comment'] ) ? 'yes' : 'no';
        $options['wpf_every_new_complete']       = isset( $_POST['wpf_every_new_complete'] ) ? 'yes' : 'no';
        $options['wpf_every_status_change']      = isset( $_POST['wpf_every_status_change'] ) ? 'yes' : 'no';
        $options['wpf_daily_report']             = isset( $_POST['wpf_daily_report'] ) ? 'yes' : 'no';
        $options['wpf_weekly_report']            = isset( $_POST['wpf_weekly_report'] ) ? 'yes' : 'no';
        $options['wpf_auto_daily_report']        = isset( $_POST['wpf_auto_daily_report'] ) ? 'yes' : 'no';
        $options['wpf_auto_weekly_report']       = isset( $_POST['wpf_auto_weekly_report'] ) ? 'yes' : 'no';
        $options['wpf_site_id']                  = get_option( 'wpf_site_id' );

        $parms = [];
        foreach ( $options as $key => $value ) {
            array_push( $parms, ['name' => $key,'value' => $value] );
        }
        update_site_data( $parms );
        wp_redirect( add_query_arg( 'page', 'collaboration_page_settings&wpf_setting=1', admin_url( 'admin.php' ) ) );
        exit;
    }
}
add_action( 'admin_post_save_wpfeedback_options', 'process_wpfeedback_options' );

/*
 * This function is used to save the Permissions options of the plugin when saved from the "Permissions" tab.
 *
 * @input Array ( $_POST )
 * @return Redirect
 */
if ( ! function_exists( 'process_wpfeedback_misc_options' ) ) {
    function process_wpfeedback_misc_options() {	
        if ( !check_if_allowed_to_save_settings() ) {
            wp_die( 'Unauthorized access' );
        }
        $options = [];
        if ( isset( $_POST['wpf_license_deactivate'] ) ) {
            update_option( 'wpf_license', 'invalid', 'no' );
        }
        if ( isset( $_POST ) ) {
            $options['wpf_allow_guest']       =  isset( $_POST['wpfeedback_guest_allowed'] ) ? sanitize_text_field( $_POST['wpfeedback_guest_allowed'] ) : 'no';
            $options['wpf_disable_for_admin'] =  isset( $_POST['wpfeedback_disable_for_admin'] ) ? sanitize_text_field( $_POST['wpfeedback_disable_for_admin'] ) : 'no';
            $options['wpf_enable_autologin']  =  isset( $_POST['wpfeedback_disable_autologin'] ) ? sanitize_text_field( $_POST['wpfeedback_disable_autologin'] ) : 'no';
            $options['wpf_disable_for_app']   =  isset( $_POST['wpfeedback_disable_for_app'] ) ? sanitize_text_field( $_POST['wpfeedback_disable_for_app'] ) : 'no';
	        $wpfeedback_selected_roles        = '';
            if ( isset( $_POST['wpfeedback_selcted_role'] ) ) {
                $wpfeedback_selected_roles = implode( ',', $_POST['wpfeedback_selcted_role'] );
            } else {
                $wpfeedback_selected_roles = "administrator";
            }
            update_option( 'wpf_selcted_role', $wpfeedback_selected_roles, 'no' );
            $options['wpf_selcted_role']                         = $wpfeedback_selected_roles;
            $options['wpf_customisations_client']                = sanitize_text_field(  $_POST['wpf_customisations_client'] );
            $options['wpf_customisations_webmaster']             = sanitize_text_field(  $_POST['wpf_customisations_webmaster'] );
            $options['wpf_customisations_others']                = sanitize_text_field(  $_POST['wpf_customisations_others'] );
            $options['wpf_website_client']                       = sanitize_text_field(  $_POST['wpf_website_client'] );
            $options['wpf_website_developer']                    = sanitize_text_field(  $_POST['wpf_website_developer'] );
            $options['wpf_tab_permission_user_client']           = isset( $_POST['wpf_tab_permission_user_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_user_client'] ) : 'no';
            $options['wpf_tab_permission_user_webmaster']        = isset( $_POST['wpf_tab_permission_user_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_user_webmaster'] ) : 'no';
            $options['wpf_tab_permission_user_others']           = isset( $_POST['wpf_tab_permission_user_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_user_others'] ) : 'no';
            $options['wpf_tab_permission_user_guest']            = isset( $_POST['wpf_tab_permission_user_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_user_guest'] ) : 'no';
            $options['wpf_tab_permission_priority_client']       = isset( $_POST['wpf_tab_permission_priority_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_priority_client'] ) : 'no';
            $options['wpf_tab_permission_priority_webmaster']    = isset( $_POST['wpf_tab_permission_priority_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_priority_webmaster'] ) : 'no';
            $options['wpf_tab_permission_priority_others']       = isset( $_POST['wpf_tab_permission_priority_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_priority_others'] ) : 'no';
            $options['wpf_tab_permission_priority_guest']        = isset( $_POST['wpf_tab_permission_priority_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_priority_guest'] ) : 'no';
            $options['wpf_tab_permission_status_client']         = isset( $_POST['wpf_tab_permission_status_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_status_client'] ) : 'no';
            $options['wpf_tab_permission_status_webmaster']      = isset( $_POST['wpf_tab_permission_status_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_status_webmaster'] ) : 'no';
            $options['wpf_tab_permission_status_others']         = isset( $_POST['wpf_tab_permission_status_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_status_others'] ) : 'no';
            $options['wpf_tab_permission_status_guest']          = isset( $_POST['wpf_tab_permission_status_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_status_guest'] ) : 'no';
            $options['wpf_tab_permission_screenshot_client']     = isset( $_POST['wpf_tab_permission_screenshot_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_screenshot_client'] ) : 'no';
            $options['wpf_tab_permission_screenshot_webmaster']  = isset( $_POST['wpf_tab_permission_screenshot_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_screenshot_webmaster'] ) : 'no';
            $options['wpf_tab_permission_screenshot_others']     = isset( $_POST['wpf_tab_permission_screenshot_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_screenshot_others'] ) : 'no';
            $options['wpf_tab_permission_screenshot_guest']      = isset( $_POST['wpf_tab_permission_screenshot_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_screenshot_guest'] ) : 'no';
            $options['wpf_tab_permission_information_client']    = isset( $_POST['wpf_tab_permission_information_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_information_client'] ) : 'no';
            $options['wpf_tab_permission_information_webmaster'] = isset( $_POST['wpf_tab_permission_information_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_information_webmaster'] ) : 'no';
            $options['wpf_tab_permission_information_others']    = isset( $_POST['wpf_tab_permission_information_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_information_others'] ) : 'no';
            $options['wpf_tab_permission_information_guest']     = isset( $_POST['wpf_tab_permission_information_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_information_guest'] ) : 'no';
            $options['wpf_tab_permission_delete_task_client']    = isset( $_POST['wpf_tab_permission_delete_task_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_delete_task_client'] ) : 'no';
            $options['wpf_tab_permission_delete_task_webmaster'] = isset( $_POST['wpf_tab_permission_delete_task_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_delete_task_webmaster'] ) : 'no';
            $options['wpf_tab_permission_delete_task_others']    = isset( $_POST['wpf_tab_permission_delete_task_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_delete_task_others'] ) : 'no';
            $options['wpf_tab_permission_delete_task_guest']     = isset( $_POST['wpf_tab_permission_delete_task_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_delete_task_guest'] ) : 'no';           
            $options['wpf_tab_auto_screenshot_task_client']      = isset( $_POST['wpf_tab_auto_screenshot_task_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_auto_screenshot_task_client'] ) : 'no';
            $options['wpf_tab_auto_screenshot_task_webmaster']   = isset( $_POST['wpf_tab_auto_screenshot_task_webmaster'] ) ? sanitize_text_field(  $_POST['wpf_tab_auto_screenshot_task_webmaster'] ) : 'no';
            $options['wpf_tab_auto_screenshot_task_others']      = isset( $_POST['wpf_tab_auto_screenshot_task_others'] ) ? sanitize_text_field(  $_POST['wpf_tab_auto_screenshot_task_others'] ) : 'no';
            $options['wpf_tab_auto_screenshot_task_guest']       = isset( $_POST['wpf_tab_auto_screenshot_task_guest'] ) ? sanitize_text_field(  $_POST['wpf_tab_auto_screenshot_task_guest'] ) : 'no';

            /* update settings for display stickers  */
            $webmaster_sticker = 'no';
            if ( isset( $_POST['wpf_tab_permission_display_stickers_webmaster'] ) && $_POST['wpf_tab_permission_display_stickers_webmaster'] == 'yes' ) {
                $webmaster_sticker = 'yes';
            }

            $options['wpf_tab_permission_display_stickers_client']    = isset( $_POST['wpf_tab_permission_display_stickers_client'] ) ? sanitize_text_field(  $_POST['wpf_tab_permission_display_stickers_client'] ) : 'no';
            $options['wpf_tab_permission_display_stickers_webmaster'] = $webmaster_sticker;
            $options['wpf_tab_permission_display_stickers_others']    = isset( $_POST['wpf_tab_permission_display_stickers_others'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_display_stickers_others'] ) : 'no';
            $options['wpf_tab_permission_display_stickers_guest']     = isset( $_POST['wpf_tab_permission_display_stickers_guest'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_display_stickers_guest'] ) : 'no';
            
            $webmaster_taskid = 'no';
            if ( isset( $_POST['wpf_tab_permission_display_task_id_webmaster'] ) && $_POST['wpf_tab_permission_display_task_id_webmaster'] == 'yes' ) {
                $webmaster_taskid = 'yes';
            }
            $options['wpf_tab_permission_display_task_id_client']    = isset( $_POST['wpf_tab_permission_display_task_id_client'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_display_task_id_client'] ) : 'no';
            $options['wpf_tab_permission_display_task_id_webmaster'] = $webmaster_taskid;
            $options['wpf_tab_permission_display_task_id_others']    = isset( $_POST['wpf_tab_permission_display_task_id_others'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_display_task_id_others'] ) : 'no';
            $options['wpf_tab_permission_display_task_id_guest']     = isset( $_POST['wpf_tab_permission_display_task_id_guest'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_display_task_id_guest'] ) : 'no';

            // => v2.1.0
            $keyboard_shortcut = 'no';

            if ( isset( $_POST['wpf_tab_permission_keyboard_shortcut_webmaster'] ) && $_POST['wpf_tab_permission_keyboard_shortcut_webmaster'] == 'yes' ) {
                $keyboard_shortcut = 'yes';
            }
            $options['wpf_tab_permission_keyboard_shortcut_client']    = isset( $_POST['wpf_tab_permission_keyboard_shortcut_client'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_keyboard_shortcut_client'] ) : 'no';
            $options['wpf_tab_permission_keyboard_shortcut_webmaster'] = $keyboard_shortcut;
            $options['wpf_tab_permission_keyboard_shortcut_others']    = isset( $_POST['wpf_tab_permission_keyboard_shortcut_others'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_keyboard_shortcut_others'] ) : 'no';
            $options['wpf_tab_permission_keyboard_shortcut_guest']     = isset( $_POST['wpf_tab_permission_keyboard_shortcut_guest'] ) ? sanitize_text_field( $_POST['wpf_tab_permission_keyboard_shortcut_guest'] ) : 'no';

            $parms1 = [];
            foreach ( $options as $key => $value ) {
                array_push( $parms1, ['name' => $key, 'value' => $value] );
            }
            syncUsers();
            update_site_data( $parms1 );

            if ( isset( $_POST['wpfeedback_licence_key'] ) && $_POST['wpfeedback_licence_key'] != "" ) {
                if ( $_POST['wpfeedback_licence_key'] != '00000000000000000000000000000000' ) {
                    $wpf_license_key = sanitize_text_field( $_POST['wpfeedback_licence_key'] ); 
                    $wpf_result      = wpf_license_key_license_item( $wpf_license_key );
                    if ( $wpf_result['license'] == 'valid' ) {
                        $wpf_crypt_key = wpf_crypt_key( $wpf_license_key, 'e' );
                        update_option( 'wpf_license_key', $wpf_crypt_key, 'no' );
                        update_option( 'wpf_license', $wpf_result['license'], 'no' );
                        update_option( 'wpf_license_expires', $wpf_result['expires'], 'no' );
                        if ( ! get_option( 'wpf_decr_key' ) ) {
                            update_option( 'wpf_decr_key', $wpf_result['payment_id'], 'no' );
                            update_option( 'wpf_decr_checksum', $wpf_result['checksum'], 'no' );
                            $wpf_crypt_key = wpf_crypt_key( $wpf_license_key, 'e' );
                            update_option( 'wpf_license_key', $wpf_crypt_key, 'no' );
                        }
                        do_action( 'wpf_initial_sync', $wpf_license_key );
                    } else {
                        update_option( 'wpf_license_key', sanitize_text_field( $_POST['wpfeedback_licence_key'] ), 'no' );
                        update_option( 'wpf_license', $wpf_result['license'], 'no' );
                    }
                }
            }
        }
        wp_redirect( add_query_arg( 'page', 'collaboration_page_permissions', admin_url( 'admin.php' ) ) );
        exit;
    }
}
add_action( 'admin_post_save_wpfeedback_misc_options', 'process_wpfeedback_misc_options' );

// This function checks if the user is allowed to change the plugin settings or not.
function check_if_allowed_to_save_settings() {
    // Verify nonce
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wpfeedback' ) ) {
        return false;
    }
    if ( is_user_logged_in() ) {
        // Get the current logged-in user ID
        $user_id = get_current_user_id();
    
        // Retrieve the user meta value
        $user_meta_value = get_user_meta( $user_id, 'wpf_user_type', true );
    
        // Check if the user is Webmaster or not.
        if ( $user_meta_value === 'advisor' ) {
            return true;
        }
        return false;
    }
}

/*
 * This function is used to create a dropdown of the roles available in website on the "Permissions" tab for the selection.
 *
 * @input Boolean
 * @return String
 */
if ( ! function_exists( 'wpfeedback_dropdown_roles' ) ) {
    function wpfeedback_dropdown_roles( $selected = false ) {
        global $wp_roles;
        $p = '';
        $r = '';
        $editable_roles = $wp_roles->get_names();
        $selected_roles = get_site_data_by_key( 'wpf_selcted_role' );
        // For backwards compatibility
        if ( is_string( $selected_roles ) ) {
            $selected_roles = explode( ',', $selected_roles );
            foreach ( $editable_roles as $role => $details ) {
                if ( is_array( $selected_roles ) AND in_array( $role, $selected_roles ) ) // preselect specified role
                    $p .= "\n\t<option selected='selected' value='" . esc_attr( $role ) . "'>$details</option>";
                else
                    $r .= "\n\t<option value='" . esc_attr( $role ) . "'>$details</option>";
            }
        }
        return $p . $r;
    }
}

/*
 * This function is used to get the listing of all the wpfeedback tasks.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wpfeedback_get_post_list' ) ) {
    function wpfeedback_get_post_list() {
        $output = '';
        $args   = array(
            'numberposts' => -1,
            'limit'       => 20,
            'page_no'     => 1,
            'post_type'   => 'wpfeedback',
            'orderby'     => 'title',
            'orderby'     => 'date',
            'order'       => 'DESC',
            'task_center' => 1,
            'wpf_site_id' => get_option( 'wpf_site_id' ),
        );


        /* START */
        $currnet_user_information = wpf_get_current_user_information();
        $current_role             = $currnet_user_information['role'];
        $current_user_name        = $currnet_user_information['display_name'];
        $current_user_id          = $currnet_user_information['user_id'];
        $wpf_website_builder      = get_site_data_by_key( 'wpf_website_developer' ) == 1 ? get_site_data_by_key( 'wpf_website_developer' ) : 0;
        if ( $current_user_name == 'Guest' ) {
            $wpf_website_client = get_site_data_by_key( 'wpf_website_client' ) == 1 ? get_site_data_by_key( 'wpf_website_client' ) : 0;
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
            $wpf_tab_permission_display_stickers = ( get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != 'no') ? 'yes' : 'no';
            $wpf_tab_permission_display_task_id  = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no') ? 'yes' : 'no';
        } elseif ( $wpf_current_role == 'king' ) {
            $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' ) == 'yes' ? 'yes' : 'no';
            $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' ) == 'yes' ? 'yes' : 'no';
        } elseif ( $wpf_current_role == 'council' ) {
            $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' ) == 'yes' ? 'yes' : 'no';
            $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' ) == 'yes' ? 'yes' : 'no';
        } else {
            $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_guest' ) == 'yes' ? 'yes' : 'no';
            $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_guest' ) == 'yes' ? 'yes' : 'no';
        }
        /* END */

        $url         = WPF_CRM_API . 'wp-api/all/task-center-tasks';
        $sendtocloud = wp_json_encode( $args );
        $myposts     = wpf_send_remote_post( $url, $sendtocloud );
        $data        = isset( $myposts['data'] ) ? $myposts['data'] : '' ;
	    $wpf_orphans = array();
        if ( $myposts ) {
            $output .= '<ul id="all_wpf_list" style="list-style-type: none; font-size:12px;">';         
            if ( ! empty( $data ) ) {
                $output .= return_task_list_html_taskcenter( $data, $current_user_id, $wpf_tab_permission_display_stickers, $wpf_tab_permission_display_task_id );
	        }
            wp_reset_postdata();
            $output .= '</ul>';
        } else {
            $output = '<div class="wpf_no_tasks_found"><i class="gg-info"></i> No tasks found</div>';
        }   
        $response[0] = $output;
        $response[1] = $wpf_orphans;
        return $response;
    }
}

function return_task_list_html_taskcenter( $data, $current_user_id, $wpf_tab_permission_display_stickers, $wpf_tab_permission_display_task_id ) {
    $output = '';
    foreach ( $data as $mypost ) {
        $user_atarim_type = get_user_meta( $current_user_id, 'wpf_user_type', true );
        if ( $mypost['task']['is_internal'] == '1' ) {
            if ( $user_atarim_type != 'advisor' ) {
                continue;
            }
        }
        $post_id                 = $mypost['task']['id'];
        $site_task_id            = $mypost['task']['site_task_id'];
        $wpf_task_id             = $mypost['task']['wpf_task_id'];
        $author_id               = $mypost['task']['task_config_author_id'];
        $get_post_date           = $mypost['task']['created_at'];
        $date                    = strtotime( $get_post_date );
        $post_date               = get_date_from_gmt( date( 'Y-m-d H:i:s', $date ), 'd/m/Y H:i' );
        $post_title              = $mypost['task']['task_title'];
        $task_page_url           = $mypost['task']['task_page_url'];
        $wpf_task_screenshot     = $mypost['task']['wpf_task_screenshot'];
        $task_page_title         = ( $mypost['task']['task_page_title'] != null ) ? $mypost['task']['task_page_title'] : "";
        $task_config_author_name = $mypost['task']['task_config_author_name'];
        $task_notify_users       = $mypost['task']['task_notify_users'];
        $task_config_author_resX = $mypost['task']['task_config_author_resX'];
        $task_config_author_resY = $mypost['task']['task_config_author_resY'];
        $get_task_type           = $mypost['task']['task_type'];
        $is_internal             = $mypost['task']['is_internal'];

        if ( $get_task_type == 'general' ) {
            $task_type = 'general';
            $general   = '<span class="wpf_task_type">' . __( "General", 'atarim-visual-collaboration' ) . '</span>';
        } elseif ( $get_task_type == 'email' ) { //!email
            $task_type = 'email';
            $general   = '<span class="wpf_task_type">' . __( "Email", 'atarim-visual-collaboration' ) . '</span>';
        } else {
            $task_type = '';
            $general   = '';
        }                
        if ( $is_internal == '1' ) {
            $internal       = '<span class="wpf_task_type" title="Task type">' . __( "Internal", 'atarim-visual-collaboration' ) . '</span>';
            $internal_icon  = $internal_icon_html = '<span class="wpf_chevron_wrapper wpf_internal_task_wrapper"><img src="' . WPF_PLUGIN_URL . 'images/eye-off-white.svg" alt="eye off white" class="wpf-internal-img"></span>';
            $internal_class = 'wpfb-internal';
        } else {
            $internal       = '';
            $internal_icon  = '';
            $internal_class = '';
        }
    
        //create list of orphan tasks
        if ( $get_task_type == "general" && ( $mypost['task']['wpfb_task_bubble'] != NULL ) ) {
            $wpf_orphans[] = $post_id;
        }
        if ( $mypost['task']['is_admin_task'] == 1 ) {
            $wpf_task_status = 'wpf_admin';
            $admin_tag       = '<span class="wpf_task_type">' . __( "Admin", 'atarim-visual-collaboration' ) . '</span>';
        } else {
            $wpf_task_status = 'public';
            $admin_tag       = '';
        }

        $task_config_author_browser        = $mypost['task']['task_config_author_browser'];
        $task_config_author_browserVersion = $mypost['task']['task_config_author_browserVersion'];
        $task_comment_id                   = $mypost['task']['wpf_task_id'];
        $task_priority                     = $mypost['task']['task_priority'];
        $task_status                       = $mypost['task']['task_status'];
        $task_tags                         = $mypost['task']['tags'];
        $all_other_tag                     = '';
        $wpfb_tags_html                    = '';

        if ( $task_tags ) {
            $tag_length     = count( $task_tags );
            $wpfb_tags_html = '<div class="wpf_task_tags">';
            $i              = 1;
            foreach ( $task_tags as $task_tag => $task_tags_value ) {
                if ( $i == 1 ) {
                    $wpfb_tags_html .= '<span class="wpf_task_tag">' . $task_tags_value["tag"] . '</span>';
                } else {
                    if ( $tag_length == $i ) {
                        $all_other_tag .= $task_tags_value['tag'];
                    } else {
                        $all_other_tag .= $task_tags_value["tag"].', ';
                    }
                }
                $i++;
            }
            if ( $tag_length > 1 ) {
                $wpfb_tags_html .= '<span class="wpf_task_tag_more" title="' . $all_other_tag . '">...</span>';
            }
            $wpfb_tags_html .= '</div>';
        }
        $task_date                = $mypost['task']['created_at'];
        $task_date                = strtotime( $task_date );
        $task_date                = get_date_from_gmt( date( 'Y-m-d H:i:s', $task_date ), 'Y-m-d H:i:s' );
        $task_date1               = date_create($task_date);
        $wpf_wp_current_timestamp = date( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) );
        $task_date2               = date_create( $wpf_wp_current_timestamp );
        $curr_task_time           = wpfb_time_difference( $task_date1, $task_date2 );
        $display_span             = '';
        $custom_class             = '';
        if ( $wpf_tab_permission_display_stickers == 'yes' ) {
            $display_span = '<span class="' . $task_priority . '_custom  wpf_top_badge"></span> ';
            $custom_class = $task_status . "_custom";
        }                 
        $display_check_mark = '';
        if ( $wpf_tab_permission_display_task_id != 'yes' ) {
            $display_check_mark = '<i class="gg-check"></i>';
        } else {
            $display_check_mark =  '<span class="wpf_bubble_num_wrapper">' . $mypost['task']['site_task_id'] . '</span>' . $internal_icon;//$task_comment_id;
        }
        if ( $task_status == 'complete' ) {
            $bubble_label = $display_span . $display_check_mark;
        } else {
            $bubble_label = $display_span . '<span class="wpf_bubble_num_wrapper">' . $mypost['task']['site_task_id'] . '</span>' . $internal_icon;//$task_comment_id;
        }
        $author                  = get_task_author( $mypost['task'] );
        $wpf_task_status_label   = '<div class="wpf_task_label"><span class="task_status wpf_' . $task_status . '" title="Status: ' . $task_status . '">' . get_wpf_status_icon() . '</span>';
        $wpf_task_priority_label = '<span class="task_priority wpf_' . $task_priority . '" title="Priority: ' . $task_priority . '">' . get_wpf_priority_icon() . '</span></div>';                   
        $author_name             = "'" . $author . "'";
        $output                 .= '<li class="post_' . $post_id . ' ' . $task_priority . ' ' . $task_status . ' current_page_task wpf_list"><a href="javascript:void(0)" class="' . $internal_class . ' ' . $task_status . ' ' . $internal_class . '" id="wpf-task-' . $post_id . '" data-wpf_task_status="' . $wpf_task_status . '"" data-task_type="' . $task_type . '" data-task_author_name="' . $task_config_author_name . '" data-task_config_author_browserVersion="' . $task_config_author_browserVersion . '" data-task_config_author_res="' . $task_config_author_resX . ' X ' . $task_config_author_resY . '" data-task_config_author_browser="' . $task_config_author_browser . '" data-task_config_author_name="' . __( 'By ', 'atarim-visual-collaboration' ) . $task_config_author_name . ' ' . $post_date . '" data-task_notify_users="' . $task_notify_users . '" data-task_page_url="' . $task_page_url . '" data-wpf_task_screenshot="' . $wpf_task_screenshot . '" data-task_page_title="' . $post_title . '" data-task_priority="' . $task_priority . '" data-task_status="' . $task_status . '" data-disp-id="' . $site_task_id . '" data-is-internal="' . $is_internal . '" onclick="get_wpf_chat(this,true,' . $author_name . ')" data-postid="' . $post_id . '" data-uid="' . $author_id . '"  data-task_no="' . $task_comment_id . '"><div class="wpf_task_info"><input type="checkbox" value="' . $post_id . '" name="wpf_task_id" data-disp-id="' . $site_task_id . '" id="wpf_' . $post_id . '" class="wpf_task_id" style="display:none;"><div class="wpf_task_num_top ' . $custom_class . '">' . $bubble_label . '</div><div class="wpf_task_main_top"><level class="task-author">' . $author . ' <span>' . $curr_task_time['comment_time'] . '</span></level><div class="current_page_task_list">' . $post_title . '</div></div></div><div class="wpf_task_meta"><div class="wpf_task_tagg">' . $internal . $general . '</div></div></a></li>';
    }
    return $output;
}

// This function is used to get the logo.
function get_wpf_logo() {
    $get_logourl = get_site_data_by_key( 'wpfeedback_logo' );
    $alterimg    = esc_url( WPF_PLUGIN_URL . 'images/Atarim.svg' );
    return image_exists_checker( $get_logourl, $alterimg );
}

// This function is used to get the fevicon.
function get_wpf_favicon() {
    $get_faviconurl = get_site_data_by_key( 'wpfeedback_favicon' );
    $alterimg       = esc_url( WPF_PLUGIN_URL . 'images/atarim_icon.svg' );
    return image_exists_checker( $get_faviconurl, $alterimg );
}

// Function to check if an image exists at a given URL
function image_exists_checker( $imageUrl, $alterimg ) {
    $imageUrl = str_replace(' ', '%20', $imageUrl);
    // Check if the URL is reachable
    if ( $imageUrl != '' ) {
        if ( @getimagesize( $imageUrl ) ) {
            return $imageUrl;
        }
    }
    return $alterimg; 
}

/*
 * get user profile svg icon
 */
if ( ! function_exists( 'get_wpf_user_icon' ) ) {
    function get_wpf_user_icon() {
        return '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="200px" height="166.5px" viewBox="0 0 200 166.5" enable-background="new 0 0 200 166.5" xml:space="preserve"> <path fill="none" stroke="#4B5668" stroke-width="16" stroke-linecap="round" stroke-linejoin="round" d="M141.4,158.021V141.46 c0-18.29-14.83-33.119-33.12-33.119H42.04c-18.292,0-33.121,14.829-33.121,33.119v16.561"/> <circle fill="none" stroke="#4B5668" stroke-width="16" stroke-linecap="round" stroke-linejoin="round" cx="75.16" cy="42.099" r="33.12"/> <path fill="none" stroke="#4B5668" stroke-width="16" stroke-linecap="round" stroke-linejoin="round" d="M191.082,158.021V141.46 c-0.018-15.088-10.221-28.269-24.841-32.044"/> <path fill="none" stroke="#4B5668" stroke-width="16" stroke-linecap="round" stroke-linejoin="round" d="M133.122,10.054 c17.723,4.54,28.413,22.581,23.869,40.301c-2.993,11.725-12.146,20.875-23.869,23.87"/> </svg>';
    }
}

/*
 * get user screenshot svg icon
 */
if ( ! function_exists( 'get_wpf_screenshot_icon' ) ) {
    function get_wpf_screenshot_icon() {
        return '<svg enable-background="new 0 0 24 24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M21 23h-18c-1.654 0-3-1.346-3-3v-12c0-1.654 1.346-3 3-3 .853 0 1.619-.474 2-1.236l.211-.422c.722-1.445 2.174-2.342 3.789-2.342h6c1.615 0 3.067.897 3.789 2.342l.211.422c.381.762 1.147 1.236 2 1.236 1.654 0 3 1.346 3 3v12c0 1.654-1.346 3-3 3zm-12-20c-.853 0-1.619.474-2 1.236l-.211.422c-.722 1.445-2.174 2.342-3.789 2.342-.551 0-1 .449-1 1v12c0 .552.449 1 1 1h18c.552 0 1-.448 1-1v-12c0-.551-.448-1-1-1-1.615 0-3.067-.897-3.789-2.342l-.211-.422c-.381-.762-1.147-1.236-2-1.236h-6zm3 16c-3.309 0-6-2.691-6-6s2.691-6 6-6 6 2.691 6 6-2.691 6-6 6zm0-10c-2.206 0-4 1.794-4 4s1.794 4 4 4 4-1.794 4-4-1.794-4-4-4z"/></svg>';
    }
}

/*
 * get status svg icon
 */
if ( ! function_exists( 'get_wpf_status_icon' ) ) {
    function get_wpf_status_icon() {
        return '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="200px" height="110.836px" viewBox="0 0 200 110.836" enable-background="new 0 0 200 110.836" xml:space="preserve"> <g> <path fill="#4B5668" stroke="#FFFFFF" stroke-width="3" stroke-miterlimit="10" d="M96.199,84.264 c3.212-8.122,5.887-14.854,8.54-21.596c6.955-17.673,14.016-35.305,20.766-53.054c1.697-4.463,3.078-8.703,8.865-8.447 c5.82,0.257,7.804,4.332,9.179,9.283c4.447,16.01,9.085,31.967,13.669,47.941c0.518,1.799,1.149,3.571,1.829,5.669 c10.653,0,21.103,0.078,31.551-0.034c5.134-0.054,9.407,1.354,9.421,7.142c0.014,5.783-4.243,7.199-9.379,7.208 c-12.833,0.021-25.67,0.222-38.504,0.363c-5.496,0.062-7.919-3.141-9.272-8.057c-3.298-11.993-6.843-23.916-10.682-37.219 c-1.465,2.919-2.413,4.478-3.063,6.153c-8.467,21.917-16.763,43.902-25.501,65.708c-1.02,2.545-4.559,5.876-6.71,5.726 c-2.753-0.194-6.52-3-7.705-5.638c-7.184-16.025-13.744-32.325-20.544-48.52c-0.972-2.317-2.113-4.565-3.642-7.844 c-4.028,8.057-7.796,15.156-11.148,22.445c-2.247,4.89-5.341,7.185-11.01,6.967c-10.686-0.407-21.399-0.201-32.1-0.085 c-5.209,0.056-10.13-0.729-10.255-7.028c-0.124-6.294,4.633-7.399,9.939-7.321c8.323,0.124,16.667-0.326,24.963,0.168 c4.906,0.292,7.171-1.588,9.085-5.808c4.018-8.863,8.283-17.642,13.061-26.11c1.543-2.737,5.344-6.432,7.502-6.07 c3.262,0.548,7.29,3.667,8.725,6.747c6.71,14.412,12.632,29.188,18.867,43.823C93.527,78.845,94.57,80.845,96.199,84.264z"/> </g> </svg>';
    }
}

/*
 * get priority svg icon
 */
if ( ! function_exists( 'get_wpf_priority_icon' ) ) {
    function get_wpf_priority_icon() {
        return '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="200px" height="200px" viewBox="0 0 200 200" enable-background="new 0 0 200 200" xml:space="preserve"> <path fill="#4B5668" d="M197.058,61.695L157.311,5.664c-2.004-2.826-5.271-4.514-8.736-4.515c-0.002,0-0.002,0-0.002,0 c-3.467,0-6.733,1.687-8.738,4.515l-39.747,56.032c-2.328,3.281-2.628,7.542-0.781,11.118c1.847,3.575,5.496,5.796,9.52,5.796 h16.558v108.869c0,6.141,4.994,11.135,11.135,11.135h24.106c6.141,0,11.136-4.995,11.136-11.135V78.609h16.558 c4.024,0,7.671-2.222,9.519-5.796C199.683,69.237,199.385,64.976,197.058,61.695z M164.363,63.817c-4.087,0-7.396,3.311-7.396,7.396 v112.61h-16.794V71.214c0-4.086-3.31-7.396-7.395-7.396h-16.063l31.854-44.907l31.855,44.907L164.363,63.817L164.363,63.817z"/> <path fill="#4B5668" d="M91.176,121.152h-16.56V12.282c0-6.141-4.994-11.136-11.135-11.136H39.375 c-6.141,0-11.135,4.995-11.135,11.136V52.68c0,4.086,3.31,7.396,7.396,7.396c4.085,0,7.396-3.31,7.396-7.396V15.939h16.792v112.61 c0,4.085,3.31,7.396,7.396,7.396h16.062L51.428,180.85l-31.853-44.905h16.062c4.085,0,7.396-3.311,7.396-7.396V87.077 c0-4.085-3.311-7.395-7.396-7.395c-4.086,0-7.396,3.31-7.396,7.395v34.075H11.683c-4.026,0-7.674,2.222-9.521,5.797 c-1.847,3.576-1.546,7.836,0.782,11.115l39.747,56.032c2.006,2.827,5.272,4.517,8.738,4.517h0.002 c3.468-0.001,6.733-1.691,8.737-4.517l39.746-56.03c2.329-3.281,2.63-7.541,0.782-11.117 C98.848,123.374,95.2,121.152,91.176,121.152L91.176,121.152z"/> </svg>';
    }
}

/*
 * get info svg icon
 */
if ( ! function_exists( 'get_wpf_info_icon' ) ) {
    function get_wpf_info_icon() {
        return '<svg height="100px" version="1.1" viewBox="0 0 100 100" width="100px" xmlns="http://www.w3.org/2000/svg" xmlns:sketch="http://www.bohemiancoding.com/sketch/ns" xmlns:xlink="http://www.w3.org/1999/xlink"><title/><desc/><defs/><g fill="none" fill-rule="evenodd" id="MiMedia---iOS/Android" stroke="none" stroke-width="1"><g fill="#4B5668" id="icon32pt_info"><path d="M50,94 C74.300529,94 94,74.300529 94,50 C94,25.699471 74.300529,6 50,6 C25.699471,6 6,25.699471 6,50 C6,74.300529 25.699471,94 50,94 L50,94 Z M50,86 C69.882251,86 86,69.882251 86,50 C86,30.117749 69.882251,14 50,14 C30.117749,14 14,30.117749 14,50 C14,69.882251 30.117749,86 50,86 L50,86 Z M45,49.0044356 C45,46.2405621 47.2441952,44 50,44 C52.7614237,44 55,46.2303666 55,49.0044356 L55,68.9955644 C55,71.7594379 52.7558048,74 50,74 C47.2385763,74 45,71.7696334 45,68.9955644 L45,49.0044356 L45,49.0044356 Z M44,32 C44,28.6862915 46.6930342,26 50,26 C53.3137085,26 56,28.6930342 56,32 C56,35.3137085 53.3069658,38 50,38 C46.6862915,38 44,35.3069658 44,32 L44,32 Z" id="Oval-58"/></g></g></svg>';
    }
}

/*
 * get close svg icon
 */
if ( ! function_exists( 'get_wpf_close_icon' ) ) {
    function get_wpf_close_icon() {
	    return '<svg data-v-07452373="" xmlns="http://www.w3.org/2000/svg" height="18" width="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="feather feather-x feather__content"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    }
}

/*
 * get right svg icon
 */
if ( ! function_exists( 'get_wpf_right_icon' ) ) {
    function get_wpf_right_icon() {
	    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"> <polyline points="20 6 9 17 4 12"></polyline> </svg>';
    }
}

/*
 * get pro svg icon
 */
if ( ! function_exists( 'get_wpf_pro_icon' ) ) {
    function get_wpf_pro_icon() {
	    return '<svg viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg"> <path d="M19.0044 8.71143L19.0044 1.71143L12.0044 1.71143L12.0044 8.71143L19.0044 8.71143Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> <path d="M19.0044 19.7114L19.0044 12.7114L12.0044 12.7114L12.0044 19.7114L19.0044 19.7114Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> <path d="M8.00439 19.7114L8.00439 12.7114L1.00439 12.7114L1.00439 19.7114L8.00439 19.7114Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> <path d="M8.00439 8.71143L8.00439 1.71143L1.00439 1.71143L1.00439 8.71143L8.00439 8.71143Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> </svg>';
    }
}

/*
 * get atarim svg icon
 */
if ( ! function_exists( 'get_wpf_icon' ) ) {
    function get_wpf_icon() {
	    return '<svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1080 1080"> <defs><style>.cls-1{fill:#052055}.cls-2{fill:#6d5df3}</style></defs><title>#9304 - New logo request</title> <g> <g> <polygon class="cls-1" points="937.344 785.955 746.1 856.215 851.972 1060.257 1080 1059.991 937.344 785.955"/> <polygon class="cls-1" points="539.938 19.669 0 1059.991 228.152 1059.991 539.873 458.766 652.263 675.369 843.507 605.108 539.938 19.669"/> </g> <polygon class="cls-2" points="227.659 1060.331 373.967 778.521 1055.074 519.371 227.659 1060.331"/> </g> </svg>';
    }
}

/*
 * get download icon
 */
if ( ! function_exists( 'get_wpf_image_download_icon' ) ) {
    function get_wpf_image_download_icon() {
        return '<svg viewBox="0 0 512 512" id="ion-android-download" width="18" height="18" fill="#fff"><path d="M403.002 217.001C388.998 148.002 328.998 96 256 96c-57.998 0-107.998 32.998-132.998 81.001C63.002 183.002 16 233.998 16 296c0 65.996 53.999 120 120 120h260c55 0 100-45 100-100 0-52.998-40.996-96.001-92.998-98.999zM224 268v-76h64v76h68L256 368 156 268h68z"></path></svg>';
    }
}

/*
 * get open icon
 */
if ( ! function_exists( 'get_wpf_image_open_icon' ) ) {
    function get_wpf_image_open_icon() {
        return '<svg height="18" version="1.1" viewBox="0 0 100 100" width="18" xmlns="http://www.w3.org/2000/svg" xmlns:sketch="http://www.bohemiancoding.com/sketch/ns" xmlns:xlink="http://www.w3.org/1999/xlink"><title/><desc/><defs/><g fill="none" fill-rule="evenodd" id="MiMedia---Web" stroke="none" stroke-width="1"><g fill="#fff" id="icon24pt_new_window" transform="translate(2.000000, 2.000000)"><path d="M73.7883228,16 L44.56401,45.2243128 C42.8484762,46.9398466 42.8459918,49.728257 44.5642987,51.4465639 C46.2791092,53.1613744 49.0684023,53.1650001 50.7865498,51.4468526 L80,22.2334024 L80,32.0031611 C80,34.2058797 81.790861,36 84,36 C86.2046438,36 88,34.2105543 88,32.0031611 L88,11.9968389 C88,10.8960049 87.5527117,9.89722307 86.8294627,9.17343595 C86.1051125,8.44841019 85.1063303,8 84.0031611,8 L63.9968389,8 C61.7941203,8 60,9.790861 60,12 C60,14.2046438 61.7894457,16 63.9968389,16 L73.7883228,16 L73.7883228,16 Z M88,56 L88,36.9851507 L88,78.0296986 C88,83.536144 84.0327876,88 79.1329365,88 L16.8670635,88 C11.9699196,88 8,83.5274312 8,78.0296986 L8,17.9703014 C8,12.463856 11.9672124,8 16.8670635,8 L59.5664682,8 L40,8 C42.209139,8 44,9.790861 44,12 C44,14.209139 42.209139,16 40,16 L18.2777939,16 C17.0052872,16 16,17.1947367 16,18.668519 L16,77.331481 C16,78.7786636 17.0198031,80 18.2777939,80 L77.7222061,80 C78.9947128,80 80,78.8052633 80,77.331481 L80,56 C80,53.790861 81.790861,52 84,52 C86.209139,52 88,53.790861 88,56 L88,56 Z" id="Rectangle-2064"/></g></g></svg>';
    }
}

/*
 * push to media icon
 */
if ( ! function_exists( 'get_wpf_push_to_media_icon' ) ) {
    function get_wpf_push_to_media_icon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="32pt" height="31pt" viewBox="0 0 32 31" version="1.1"><g id="surface1"><path style=" stroke:none;fill-rule:nonzero;fill:#ffffff;fill-opacity:1;" d="M 16.234375 16.746094 L 13.558594 24.3125 L 13.550781 24.3125 L 11.476562 30.09375 C 11.621094 30.132812 11.761719 30.164062 11.910156 30.203125 C 11.917969 30.203125 11.925781 30.203125 11.933594 30.203125 C 13.222656 30.535156 14.578125 30.71875 15.972656 30.71875 C 16.667969 30.71875 17.34375 30.679688 18.007812 30.574219 C 18.921875 30.464844 19.800781 30.277344 20.660156 30.015625 C 20.871094 29.953125 21.082031 29.878906 21.296875 29.808594 C 21.066406 29.335938 20.578125 28.28125 20.554688 28.234375 Z M 16.234375 16.746094 "/><path style=" stroke:none;fill-rule:nonzero;fill:#ffffff;fill-opacity:1;" d="M 1.6875 9.5625 C 0.871094 11.351562 0.316406 13.550781 0.316406 15.535156 C 0.316406 16.03125 0.339844 16.53125 0.390625 17.019531 C 0.953125 22.652344 4.707031 27.382812 9.867188 29.507812 C 10.078125 29.59375 10.300781 29.683594 10.519531 29.761719 L 2.929688 9.570312 C 2.277344 9.546875 2.152344 9.585938 1.6875 9.5625 Z M 1.6875 9.5625 "/><path style=" stroke:none;fill-rule:nonzero;fill:#ffffff;fill-opacity:1;" d="M 30.210938 9.160156 C 29.859375 8.425781 29.441406 7.722656 28.976562 7.058594 C 28.847656 6.867188 28.699219 6.675781 28.5625 6.488281 C 26.804688 4.210938 24.414062 2.421875 21.628906 1.378906 C 19.882812 0.714844 17.972656 0.351562 15.980469 0.351562 C 11.058594 0.351562 6.660156 2.566406 3.785156 6.019531 C 3.253906 6.652344 2.78125 7.332031 2.355469 8.046875 C 3.515625 8.054688 4.953125 8.054688 5.117188 8.054688 C 6.59375 8.054688 8.871094 7.878906 8.871094 7.878906 C 9.640625 7.832031 9.71875 8.914062 8.960938 9.003906 C 8.960938 9.003906 8.195312 9.089844 7.34375 9.128906 L 12.480469 23.917969 L 15.566406 14.957031 L 13.378906 9.136719 C 12.609375 9.097656 11.898438 9.011719 11.898438 9.011719 C 11.132812 8.972656 11.230469 7.839844 11.980469 7.886719 C 11.980469 7.886719 14.308594 8.0625 15.695312 8.0625 C 17.171875 8.0625 19.453125 7.886719 19.453125 7.886719 C 20.210938 7.839844 20.308594 8.921875 19.539062 9.011719 C 19.539062 9.011719 18.78125 9.097656 17.933594 9.136719 L 23.019531 23.816406 L 24.429688 19.257812 C 25.140625 17.488281 25.5 16.023438 25.5 14.855469 C 25.5 13.171875 24.871094 12 24.332031 11.089844 C 23.621094 9.960938 22.953125 9.011719 22.953125 7.894531 C 22.953125 6.636719 23.933594 5.46875 25.320312 5.46875 C 25.378906 5.46875 25.441406 5.46875 25.5 5.46875 C 27.640625 5.414062 28.339844 7.46875 28.429688 8.867188 C 28.429688 8.867188 28.429688 8.898438 28.429688 8.914062 C 28.464844 9.484375 28.4375 9.902344 28.4375 10.402344 C 28.4375 11.777344 28.167969 13.335938 27.371094 15.289062 L 24.1875 24.210938 L 22.367188 29.40625 C 22.511719 29.34375 22.652344 29.277344 22.796875 29.207031 C 27.425781 27.042969 30.796875 22.722656 31.507812 17.605469 C 31.613281 16.933594 31.664062 16.246094 31.664062 15.550781 C 31.664062 13.265625 31.140625 11.097656 30.210938 9.160156 Z M 30.210938 9.160156 "/></g></svg>';
    }
}

if ( ! function_exists( 'wpf_get_current_user_information' ) ) {
    function wpf_get_current_user_information( $author_id = '' ) {
        $response = array();
        $wpf_website_developer = 0;
        if ( ! is_user_logged_in() ) {
            $wpfb_users_json       = do_shortcode( '[wpf_user_list_front]' );
            $wpfb_users            = json_decode( $wpfb_users_json );
            $wpf_website_developer = !empty( get_site_data_by_key( 'wpf_website_client' ) ) ? get_site_data_by_key( 'wpf_website_client' ) : 0;
            // Allow user if used Share version 2 by Pratap
            if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) && $_COOKIE['wordpress_avc_allow_guest'] != '' ) {
                $user = json_decode( stripslashes( $_COOKIE['wordpress_avc_allow_guest'] ), true );
                $response['first_name']   = $user['fname'];
                $response['display_name'] = $user['dname'];
                $response['role']         = $user['role'];
                $response['user_id']      = $user['id'];
                return $response;
            }
        }

        if ( $author_id != '' ) {
            $user                     = get_userdata( $author_id );
            $user_details             = ( array )$user->data;
            $roles                    = ( array )$user->roles;
            $roles                    = array_values( $roles );
            $response['first_name']   = $user_details['first_name'] ?? "";
            $response['last_name']    = $user_details['last_name'] ?? "";
            $response['display_name'] = $user_details['display_name'];
            $response['user_id']      = $user_details['ID'];
            $response['role']         = $roles[0];
            return $response;
        } elseif ( is_user_logged_in() == true ) {
            $user         = wp_get_current_user();
            $user_details = ( array )$user->data;
            $roles        = ( array )$user->roles;
            $roles        = array_values( $roles );
            // to ge the first and last name
            $user_meta                = get_user_meta( $user_details['ID'] );
            $response['first_name']   = ( ! empty( $user_meta['first_name'][0] ) ) ? $user_meta['first_name'][0] : "";
            $response['last_name']    = ( ! empty( $user_meta['last_name'][0] ) ) ? $user_meta['last_name'][0] : "";          
            $response['display_name'] = $user_details['display_name'];
            $response['user_id']      = $user_details['ID'];
            $response['role']         = $roles[0];
            return $response;
        } elseif ( $wpf_website_developer != 0 ) {
            foreach ( $wpfb_users as $key => $val ) {
                if ( $wpf_website_developer == $key ) {
                    /**
                     * user id was not matching in graphics page when guest mode on and default user selected
                     * => v2.1.0
                     */
                    if ( intval( $wpf_website_developer ) > 0 ) {
                        $user                     = get_user_by( 'id', $val->username );
                        $response['first_name']   = $val->first_name ?? "";
                        $response['last_name']    = $val->last_name ?? "";
                        $response['display_name'] = $val->displayname;
                        $response['user_id']      = $wpf_website_developer;

                        if ( ! empty( $user->roles ) ) {
                            $roles            = array_values( $user->roles );
                            $response['role'] = $roles[0];
                        } else {
                            $response['role'] = 'Guest';
                        }
                    } else {
                        $response['first_name']   = $val->first_name ?? "";
                        $response['last_name']    = $val->last_name ?? "";
                        $response['display_name'] = $val->displayname;
                        $response['user_id']      = 0;
                        $response['role']         = 'Guest';
                    }
                    return $response;
                }
            }

            $response['display_name'] = 'Guest';
            $response['user_id']      = 0;
            $response['role']         = 'Guest';
            return $response;
        } else {
            $response['display_name'] = 'Guest';
            $response['user_id']      = 0;
            $response['role']         = 'Guest';
            return $response;
        }
    }
}

/*
 * This function is used to get the time difference between two timestamps. It is basically used to get  the time difference between current time and the time when comment was posted.
 *
 * @input Timestamp, Timestamp
 * @return Array
 */
if ( ! function_exists( 'wpfb_time_difference' ) ) {
    function wpfb_time_difference( $datetime1, $datetime2 ) {
        $response = array();
        $interval = date_diff( $datetime1, $datetime2 );
        if ( $interval->y == 0 ) {
            if ( $interval->m == 0 ) {
                if ( $interval->d == 0 ) {
                    if ( $interval->h == 0 ) {
                        if ( $interval->i == 0 ) {
                            $comment_time = $interval->s . __( 's', 'atarim-visual-collaboration' );
                        } else {
                            $comment_time = $interval->i . __( 'm', 'atarim-visual-collaboration' );
                        }
                    } else {
                        $comment_time = $interval->h . __( 'h', 'atarim-visual-collaboration' );
                    }
                } else {
                    $comment_time = $interval->d . __( 'd', 'atarim-visual-collaboration' );
                }
            } else {
                $comment_time = $interval->m . __( 'mth', 'atarim-visual-collaboration' );
            }
        } else {
            $comment_time = $interval->y . __( 'yr', 'atarim-visual-collaboration' );
        }
        $response['interval']     = $interval;
        $response['comment_time'] = $comment_time;
        return $response;
    }
}

/*
 * This function is used to get the listing of status and priorities inside the Tasks Center.
 *
 * @input String
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_texonomy_selectbox' ) ) {
    function wp_feedback_get_texonomy_selectbox( $my_term ) {
        $filter_data = get_option( 'filter_data' );
        $filter_data_db = $filter_data[$my_term];

        //edited by Pratap
        $disable = '';
        if ( $my_term == 'task_status' || $my_term == 'task_priority' ) {
            if ( !is_feature_enabled( 'task_center' ) ) {
                $disable = 'disabled';
            }
        }
	
        if ( isset( $filter_data_db ) && ! empty( $filter_data_db ) ) {
            echo '<select id="task_' . $my_term . '_attr" onchange="' . $my_term . '_changed(this);" '. $disable .'>';
            foreach ( $filter_data_db as $term ) {
                if ( $term['label'] == 'In Progress' ) {
                    $term['label'] = 'In Prog';
                } elseif ( $term['label'] == 'Pending Review' ) {
                    $term['label'] = 'Pending';
                } else {
                    $term['label'] = $term['label'];
                }
                echo '<option name="' . $my_term . '" value="' . $term['value'] . '" class="wpf_task" id="wpf_' . $term['value'] . '"/>' . __( $term['label'], 'atarim-visual-collaboration' ) . '</option>';
            }
            echo '</select>';
        }
    }
}

/*
 * This function is used to check if Atarim is enabled on the website.
 *
 * @input Int
 * @return Boolean
 */
if ( ! function_exists( 'wpf_check_if_enable' ) ) {
    function wpf_check_if_enable( $author_id = '' ) {
        if ( $author_id == '' ) {
            $current_user_information = wpf_get_current_user_information();
            $user                     = wp_get_current_user();
        } else {
            $current_user_information = wpf_get_current_user_information( $author_id );
            $user                     = get_userdata( $author_id );
        }
	
        $wpf_license      = get_option( 'wpf_license' );
        $wpf_enabled      = get_site_data_by_key( 'enabled_wpfeedback' );   
	    $wpf_selcted_role = get_site_data_by_key( 'wpf_selcted_role' );	
        $wpf_allow_guest  = get_site_data_by_key( 'wpf_allow_guest' );
        $selected_roles   = explode( ',', $wpf_selcted_role );       
	    $user_details     = ( array )$user->data;
        $roles            = ( array )$user->roles;
        $roles            = array_values( $roles );
        // Allow user if used Share version 2 by Pratap
        if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) ) {
            $wpf_allow_guest = 'yes';
        }

        if ( isset( $_COOKIE['wordpress_avc_guest'] ) ) {
            $wpf_allow_guest = 'yes';
        }

        if ( $wpf_license == 'valid' && $wpf_enabled == 'yes' && ( ! empty( array_intersect( $roles, $selected_roles ) ) || $wpf_allow_guest == 'yes' ) ) {
            $wpf_access_output = 1;
        } else {
            $wpf_access_output = 0;
        }
        return $wpf_access_output;
    }
}

/*
 * This function is used to get extra user settings for the Atarim.
 *
 * @input Object
 * @return NULL
 */
if ( ! function_exists( 'wpf_show_extra_profile_fields' ) ) {
    function wpf_show_extra_profile_fields( $user ) {	
        $wpf_get_user_type = get_user_meta( $user->ID, 'wpf_user_type', true );
        $selected_roles    = get_site_data_by_key( 'wpf_selcted_role' );
        $selected_roles    = explode( ',', $selected_roles );	
        $wpf_enabled       = get_site_data_by_key( 'enabled_wpfeedback' );       
        if ( ( array_intersect( $user->roles, $selected_roles ) && $wpf_enabled == 'yes' ) || current_user_can( 'administrator' ) ) {
            $notifications_html = wpf_get_allowed_notification_list( $user->ID, 'no' );
            ?>
            <h3><?php _e( 'Collaborate Information', 'atarim-visual-collaboration' ); ?></h3>
            <table class="form-table wpf_fields">
                <tr>
                    <th><label for="wpf_user_type"><?php _e( "User Type", 'atarim-visual-collaboration' ); ?></label></th>
                    <td>
                        <select id="wpf_user_type" name="wpf_user_type">
                            <option value="" <?php if ( $wpf_get_user_type == '' ) { echo 'selected'; } ?> ><?php _e( 'Select', 'atarim-visual-collaboration' ) ?></option>
                            <option value="king" <?php if ( $wpf_get_user_type == 'king' ) { echo 'selected'; } ?> ><?php echo ! empty( get_site_data_by_key( 'wpf_customisations_client' ) ) ? esc_html( get_site_data_by_key( 'wpf_customisations_client' ) ) : 'Client (Website Owner)'; ?></option>
                            <option value="advisor" <?php if ( $wpf_get_user_type == 'advisor' ) { echo 'selected'; } ?> ><?php echo ! empty( get_site_data_by_key( 'wpf_customisations_webmaster' ) ) ? esc_html( get_site_data_by_key( 'wpf_customisations_webmaster' ) ) : 'Webmaster'; ?></option>
                            <option value="council" <?php if ( $wpf_get_user_type == 'council' ) { echo 'selected'; } ?> ><?php echo ! empty( get_site_data_by_key( 'wpf_customisations_others' ) ) ? esc_html( get_site_data_by_key( 'wpf_customisations_others' ) ) : 'Others'; ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="city"><?php _e( "Email notifications", 'atarim-visual-collaboration' ); ?></label></th>
                    <td>
                        <?php echo $notifications_html; ?>
                    </td>
                </tr>
            </table>
        <?php 
        }
    }
}
add_action( 'show_user_profile', 'wpf_show_extra_profile_fields' );
add_action( 'edit_user_profile', 'wpf_show_extra_profile_fields' );

/*
 * This function is used to save the extra user settings for the Atarim.
 *
 * @input Int
 * @return Boolean
 */
if ( ! function_exists( 'wpf_save_user_profile_fields' ) ) {
    function wpf_save_user_profile_fields( $user_id ) {
        syncUsers();	
        $user           = get_userdata( $user_id );
        $selected_roles = get_site_data_by_key( 'wpf_selcted_role' );
        $selected_roles = explode( ',', $selected_roles );	
        $wpf_enabled    = get_site_data_by_key( 'enabled_wpfeedback' );        
        $args           = array(
            'wpf_site_id'   => get_option( 'wpf_site_id' ),
            'wpf_id'        => sanitize_text_field( $_POST['user_id'] ),
            'username'      => $user->data->user_login,
            'wpf_email'     => sanitize_email( $_POST['email'] ),
            'first_name'    => sanitize_text_field( $_POST['first_name'] ),
            'last_name'     => sanitize_text_field( $_POST['last_name'] ),
            'wpf_user_type' => sanitize_text_field( $_POST['wpf_user_type'] ),
        );

        update_user_meta( $user->ID, 'wpf_user_type', sanitize_text_field( $_POST['wpf_user_type'] ) );

        if ( ( array_intersect( $user->roles, $selected_roles ) && $wpf_enabled == 'yes' && current_user_can( 'edit_user', $user_id ) ) || current_user_can( 'administrator' ) ) {
            $options                            = []; 
            $options['wpf_site_id']             = get_option( 'wpf_site_id' );
            $options['wpf_every_new_task']      = isset( $_POST['wpf_every_new_task'] ) ? 1 : 0;
            $options['wpf_every_new_comment']   = isset( $_POST['wpf_every_new_comment'] ) ? 1 : 0;
            $options['wpf_every_new_complete']  = isset( $_POST['wpf_every_new_complete'] ) ? 1 : 0;
            $options['wpf_every_status_change'] = isset( $_POST['wpf_every_status_change'] ) ? 1 : 0;
            $options['wpf_daily_report']        = isset( $_POST['wpf_daily_report'] ) ? 1 : 0;
            $options['wpf_weekly_report']       = isset( $_POST['wpf_weekly_report'] ) ? 1 : 0;
            $options['wpf_auto_daily_report']   = isset( $_POST['wpf_auto_daily_report'] ) ? 1 : 0;
            $options['wpf_auto_weekly_report']  = isset( $_POST['wpf_auto_weekly_report'] ) ? 1 : 0;	    
	        $args['notifications']              = $options;	    
	        $url                                = WPF_CRM_API . 'wp-api/wpfuser/update';
            $res                                = wpf_send_remote_post( $url, wp_json_encode( $args ) );
        } else {
            return false;
        } 
    }
}
add_action( 'personal_options_update', 'wpf_save_user_profile_fields' );
add_action( 'edit_user_profile_update', 'wpf_save_user_profile_fields' );

// sync users
function wpf_sync_users( $user_id ) {
    syncUsers();
}
add_action( 'user_register', 'wpf_sync_users' );
add_action( 'deleted_user', 'wpf_sync_users', 10 );
add_action( 'profile_update', 'wpf_sync_users', 10 );

/* update WP users into API */
function syncUsers() {
    $users                = wpf_api_func_get_users();
    $args                 = [];
    $args['wpf_site_id']  = get_option( 'wpf_site_id' );
    $args['responseBody'] = json_decode( $users );  
    // get all the user ID
    $wp_users = get_users( array( 'fields' => array( 'ID' ) ) );
    // flaten the data to a single array and added to the request
    $args['wpf_wp_user_ids'] = array_map( function( $user ) {
        return $user->ID;
    }, $wp_users );

    $url         = WPF_CRM_API . "wp-api/sync/users";
    $sendtocloud = wp_json_encode( $args );
    $res         = wpf_send_remote_post( $url, $sendtocloud );
}


function syncSite() {
    $url                     = WPF_CRM_API . 'wp-api/wpfsite/update';
    $response                = array();
    $response['wpf_site_id'] = get_option( 'wpf_site_id' );
    $response['url']         = get_option( 'siteurl' );
    $response['name']        = get_option( 'blogname' );
    $body                    = wp_json_encode( $response );
    $res                     = wpf_send_remote_post( $url, $body );
}

add_action( 'update_option_blogname', function( $old_value, $new_value ) {
    syncSite();
}, 10, 2); 

add_action( 'update_option_siteurl', function( $old_value, $new_value ) {
    syncSite();
}, 10, 2); 

/*
 * This function is used to get the allowed notification list to be displayed on the users extra settings.
 *
 * @input Int, String
 * @return String
 */
if ( ! function_exists( 'wpf_get_allowed_notification_list' ) ) {
    function wpf_get_allowed_notification_list( $userid, $default = 'no' ) {
	    $wpf_site_id             = get_option( 'wpf_site_id' );
        $args                    = [];
        $args['wpf_site_id']     = $wpf_site_id;
        $args['wpf_user_id']     = $userid;
        $url                     = WPF_CRM_API . "wp-api/wpfuser/getWpfUser";
        $sendtocloud             = wp_json_encode( $args );
        $res                     = wpf_send_remote_post( $url, $sendtocloud );        
        $response                = '';
        $wpf_every_new_task      = get_site_data_by_key( 'wpf_every_new_task' );
        $wpf_every_new_comment   = get_site_data_by_key( 'wpf_every_new_comment' );
        $wpf_every_new_complete  = get_site_data_by_key( 'wpf_every_new_complete' );
        $wpf_every_status_change = get_site_data_by_key( 'wpf_every_status_change' );
        $wpf_daily_report        = get_site_data_by_key( 'wpf_daily_report' );
        $wpf_weekly_report       = get_site_data_by_key( 'wpf_weekly_report' );
        $wpf_auto_daily_report   = get_site_data_by_key( 'wpf_auto_daily_report' );
        $wpf_auto_weekly_report  = get_site_data_by_key( 'wpf_auto_weekly_report' );
        if ( $wpf_every_new_task == 'yes' ) {
            if ( isset( $res['data']['preference']['every_new_task'] ) && $res['data']['preference']['every_new_task'] == 1 ) {
                $checked = 'checked';
            } else {
                $checked = '';
            }
            if ( $default == 'yes' ) {
                $checked = 'checked';
            }
            $response .= '<div><input type="checkbox" name="wpf_every_new_task" value="yes" class="wpf_checkbox"
                           id="wpf_every_new_task" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_every_new_task">' . __( 'Receive email notification for every new task', 'atarim-visual-collaboration' ) . '</label></div>';
        }
        if ( $wpf_every_new_comment == 'yes' ) {
            if ( isset( $res['data']['preference']['every_new_comment'] ) && $res['data']['preference']['every_new_comment'] == 1 ) {
                $checked = 'checked';
            } else {
                $checked = '';
            }
            if ( $default == 'yes' ) {
                $checked = 'checked';
            }
            $response .= '<div><input type="checkbox" name="wpf_every_new_comment" value="yes" class="wpf_checkbox"
                           id="wpf_every_new_comment" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_every_new_comment">' . __( 'Receive email notification for every new comment', 'atarim-visual-collaboration' ) . '</label></div>';
        }
        if ( $wpf_every_new_complete == 'yes' ) {
            if ( isset( $res['data']['preference']['wpf_every_new_complete'] ) && $res['data']['preference']['wpf_every_new_complete'] == 1 ) {
                $checked = 'checked';
            } else {
                $checked = '';
            }
            if ( $default == 'yes' ) {
                $checked = 'checked';
            }
            $response .= '<div><input type="checkbox" name="wpf_every_new_complete" value="yes" class="wpf_checkbox"
                           id="wpf_every_new_complete" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_every_new_complete">' . __( 'Receive email notification when a task is marked as complete', 'atarim-visual-collaboration' ) . '</label></div>';
        }
        if ( $wpf_every_status_change == 'yes' ) {
            if ( isset( $res['data']['preference']['every_status_change'] ) && $res['data']['preference']['every_status_change'] == 1 ) {
                $checked = 'checked';
            } else {
                $checked = '';
            }
            if ( $default == 'yes' ) {
                $checked = 'checked';
            }
            $response .= '<div><input type="checkbox" name="wpf_every_status_change" value="yes" class="wpf_checkbox"
                           id="wpf_every_status_change" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_every_status_change">' . __( 'Receive email notification for every status change', 'atarim-visual-collaboration' ) . '</label></div>';
        }
        if ( $wpf_daily_report == 'yes' ) {
            if ( isset( $res['data']['preference']['daily_report'] ) && $res['data']['preference']['daily_report'] == 1 ) {
                $checked='checked';
            } else {
                $checked = '';
            }
            if ( $default == 'yes' ) {
                $checked = 'checked';
            }
            $response .= '<div><input type="checkbox" name="wpf_daily_report" value="yes" class="wpf_checkbox"
                               id="wpf_daily_report" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_daily_report">' . __( 'Receive email notification for  daily report', 'atarim-visual-collaboration' ) . '</label></div>';
        }
        if ( $wpf_weekly_report == 'yes' ) {
            if ( isset( $res['data']['preference']['weekly_report'] ) && $res['data']['preference']['weekly_report'] == 1 ) {
                $checked='checked';
            } else {
                $checked = '';
            }
            if ( $default == 'yes' ) {
                $checked = 'checked';
            }
            $response .= '<div><input type="checkbox" name="wpf_weekly_report" value="yes" class="wpf_checkbox"
                            id="wpf_weekly_report" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_weekly_report">' . __( 'Receive email notification for weekly report', 'atarim-visual-collaboration' ) . '</label></div>';
        }
        if ( is_feature_enabled( 'auto_reports' ) ) {            
            if ( $wpf_auto_daily_report == 'yes' ) {
                if ( isset( $res['data']['preference']['wpf_auto_daily_report'] ) && $res['data']['preference']['wpf_auto_daily_report'] == 1 ) {
                    $checked = 'checked';
                } else {
                    $checked = '';
                }
                if ( $default == 'yes' ) {
                    $checked = 'checked';
                }
                $response .= '<div><input type="checkbox" class="wpf_checkbox" name="wpf_auto_daily_report" value="yes" id="wpf_auto_daily_report" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_auto_daily_report">' . __( 'Auto receive email notification for daily report', 'atarim-visual-collaboration' ) . '</label></div>';
            }
            if ( $wpf_auto_weekly_report == 'yes' ) {
                if ( isset( $res['data']['preference']['wpf_auto_weekly_report'] ) && $res['data']['preference']['wpf_auto_weekly_report'] == 1 ) {
                    $checked = 'checked';
                } else {
                    $checked = '';
                }
                if ( $default == 'yes' ) {
                    $checked = 'checked';
                }
                $response .= '<div><input type="checkbox" name="wpf_auto_weekly_report" value="yes" class="wpf_checkbox"
                            id="wpf_auto_weekly_report" ' . $checked . ' /><label class="wpf_checkbox_label" for="wpf_auto_weekly_report">' . __( 'Auto receive email notification for weekly report', 'atarim-visual-collaboration' ) . '</label></div>';
            }
        }
        return $response;
    }
}

/*
 * This function is used to get the Atarim user type.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wpf_user_type' ) ) {
    function wpf_user_type() {
	    global $current_user;
        return get_user_meta( $current_user->ID, 'wpf_user_type', true );
    }
}

/*
 * This function is used to verify if the uploaded file is valid or not.
 *
 * @input File
 * @return Boolean
 */
if ( ! function_exists( 'wpf_verify_file_upload' ) ) {
    function wpf_verify_file_upload( $server, $file_data ) {
        $allowed_file_types = array( 'image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','video/webm','video/mp4','video/mov','video/wmv','video/avi','font/ttf','text/plain' );
            if ( function_exists( 'finfo_open' ) ) {
                $imgdata   = base64_decode( $file_data );
                $f         = finfo_open();
                $mime_type = finfo_buffer( $f, $imgdata, FILEINFO_MIME_TYPE );
                if ( in_array( $mime_type, $allowed_file_types ) ) {
                    $response = 0;
                } else {
                    $response = 1;
                }
            } else {
                $response = 0;
            }
        return $response;
    }
}

/*
 * This function is used to verify if the uploaded file extension is proper or not.
 *
 * @input String
 * @return Boolean
 */
if ( ! function_exists( 'wpf_verify_file_upload_type' ) ) {
    function wpf_verify_file_upload_type( $server, $mime_type ) {
        $allowed_file_types = array( 'application/msword','image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'video/webm', 'video/mp4', 'video/quicktime', 'video/x-ms-wmv', 'video/avi', 'font/ttf', 'text/plain' );
            if ( ! empty( $mime_type ) ) {
                if ( in_array( $mime_type, $allowed_file_types ) ) {
                    $response = 0;
                } else {
                    $response = 1;
                }
            } else {
                $response = 0;
            }
        return $response;
    }
}

/*
 * This function is used to get the list of the pages on the website. It is called to get the dropdown list when creating a general task from backend.
 *
 * @input String
 * @return JSON
 */
if ( ! function_exists( 'wpf_get_page_list' ) ) {
    function wpf_get_page_list( $type = 'task_page' ) {
        $response = array();
        if ( class_exists( 'WooCommerce' ) ) {
            $wpf_default_wp_post_types = array( "page" => "page", "post" => "post", "product" => "product" );
        } else {
            $wpf_default_wp_post_types = array( "page" => "page", "post" => "post" );
        }
        $wpf_wp_cpts    = get_post_types( array( 'public' => true, 'exclude_from_search' => true, '_builtin' => false ) );
        $wpf_post_types = array_merge( $wpf_default_wp_post_types, $wpf_wp_cpts );

        foreach ( $wpf_post_types as $wpf_post_type ) {
            $objType = get_post_type_object( $wpf_post_type );
            if ( $wpf_post_type == 'page' ) {
                $numberposts = -1;
            } else {
                $numberposts = 10;
            }
            $wpf_temp_arg = array(
                'post_type'   => $wpf_post_type,
                'numberposts' => $numberposts,
            );
            $posts          = get_posts( $wpf_temp_arg );
            $wpf_count_post = count( $posts );
            if ( $wpf_count_post ) {
                foreach ( $posts as $post ) {
                    if ( $type == 'task_page' ) {
                        $response[$objType->labels->singular_name][$post->ID] = htmlspecialchars( $post->post_title, ENT_QUOTES, 'UTF-8' );
                    } else {
                        $temp_res = array(
                            'id'    => $post->ID,
                            'name'  => htmlspecialchars( $post->post_title, ENT_QUOTES, 'UTF-8' ),
                            'type'  => $objType->labels->singular_name,
                            'url'   => get_permalink( $post->ID )
                        );
                        $response[] = $temp_res;
                    }
                }
            }
        }
        return wp_json_encode( $response );
    }
}

/*
 * This function is used to deregister the scripts of the plugins that are conflicting with the Atarim.
 *
 * @input NULL
 * @return NULL
 */
if ( ! function_exists( 'wpf_mootools_deregister_javascript' ) ) {
    function wpf_mootools_deregister_javascript() {
        if ( ! is_admin() ) {
            wp_deregister_script( 'mootools-local' );
            wp_deregister_script( 'enlighter-local' );
            wp_deregister_script( 'dct-carousel-jquery' );
            wp_deregister_script( 'onepress-js-plugins' );
        }
    }
}
add_action( 'wp_print_scripts', 'wpf_mootools_deregister_javascript', 99 );

/*
 * This function is to remove script/tags that could be used for XSS attack by Pratap.
 *
 * @input NULL
 * @return NULL
 */
function wpf_wp_kses_check( $content ) {
    $allowed_html = array(
        'a'      => array(
            'href'   => array(),
            'target' => array(),
        ),
        'img' => array(
            'title' => array(),
            'src'	=> array(),
            'alt'	=> array(),
        ),
        'p'     => array(),
        'br'     => array(),
        'em'     => array(),
        'strong' => array(),
        's'      => array(),
        'u'      => array(),
        'ul'     => array(),
        'ol'     => array(),
        'li'     => array(),
        'pre'    => array(
            'class'      => array(),
            'spellcheck' => array(),
        ),
    );
    return wp_kses( $content, $allowed_html );
}


/*
 * This function is used to strip all the code elements from the data.
 *
 * @input String
 * @return String
 */
if ( ! function_exists( 'wpf_test_input' ) ) {
    function wpf_test_input( $data ) {
        $data = trim( $data );
        $data = stripslashes( $data );
        $data = htmlspecialchars( $data );
        return $data;
    }
}

/*
 * This function is used by all the ajax request to make user that they are coming from authentic source.
 *
 * @input NULL
 * @return String
 */
function wpf_security_check() {
    $roles        = array();
    $user         = wp_get_current_user();
    $user_details = ( array )$user->data;
    $roles        = ( array )$user->roles;
    $roles        = array_values( $roles );
    if ( ! check_ajax_referer( 'wpfeedback-script-nonce', 'wpf_nonce' ) ) {
        echo 'Invalid security token sent.';
        wp_die();
    } else {
        $selected_roles = get_site_data_by_key( 'wpf_selcted_role' );
        $selected_roles = explode( ',', $selected_roles );
        if ( ! in_array( "administrator", $selected_roles ) ) {
            array_push( $selected_roles, "administrator" );
        }
        $wpf_allow_guest = get_site_data_by_key( 'wpf_allow_guest' );
        // Allow user if used Share version 2 by Pratap
        if ( isset( $_COOKIE['wordpress_avc_allow_guest'] ) ) {
            $wpf_allow_guest = 'yes';
        }
        if ( isset( $_COOKIE['wordpress_avc_guest'] ) ) {
            $wpf_allow_guest = 'yes';
        }
        if ( $wpf_allow_guest == 'yes' ) {
            $selected_roles[] = 'Guest';
            $roles[]          = 'Guest';
        }
        if ( empty( array_intersect( $roles, $selected_roles ) ) ) {
            echo 'Invalid user.';
            wp_die();
        }
    }
}
add_filter( 'get_comment_text', 'make_clickable', 99 );

/*
 * This function is to get the listing of status and priority for the sidebar filters.
 *
 * @input String
 * @return String
 */
if ( ! function_exists( 'wp_feedback_get_texonomy_filter' ) ) {
    function wp_feedback_get_texonomy_filter( $my_term ) {
        $output         = '';
        $filter_data = get_option( 'filter_data' );
        $filter_data_db = $filter_data[$my_term];
	
        if ( isset( $filter_data_db ) && ! empty( $filter_data_db ) ) {
            $output .=  '<ul class="wpf_filter_checkbox" id="wpf_sidebar_filter_' . $my_term . '">';
            foreach ( $filter_data_db as $term ) {
                if ( $term['label'] == 'In Progress' ) {
                    $term['label'] = 'In Prog';
                } elseif ( $term['label'] == 'Pending Review' ) {
                    $term['label'] = 'Pending';
                } else {
                    $term['label'] = $term['label'];
                }
                $output .= '<li><input type="checkbox" name="wpf_filter_' . $my_term . '" value="' . $term['value'] . '" class="wp_feedback_task wpf_checkbox" id="wpf_sidebar_filter_' . $term['value'] . '" /><label for="wpf_sidebar_filter_' . $term['value'] . '" class="wpf_checkbox_label">' . __( $term['label'], 'atarim-visual-collaboration' ) . '</label></li>';
            }
            $output .= '</ul><a class="wpf_sidebar_filter_reset_' . $my_term . '" href="javascript:void(0)">'. __( 'Reset', 'atarim-visual-collaboration' ) . '</a>';
            return $output;
        }
    }
}

/*
 * This function is used to encrypt and decrypt the license key.
 *
 * @input String, String
 * @return String
 */
function wpf_crypt_key( $string, $action = 'e' ) {
    if ( strlen( $string ) == 32 ) {
        return $string;
    }
    $wpf_decr_key      = get_option( 'wpf_decr_key' );
    $wpf_decr_checksum = get_option( 'wpf_decr_checksum' );
    $output            = false;
    $encrypt_method    = "AES-256-CBC";
    $key               = hash( 'sha256', $wpf_decr_key );
    $iv                = substr( hash( 'sha256', $wpf_decr_checksum ), 0, 16 );

    if ( $action == 'e' ) {
        $output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
    } elseif ( $action == 'd' ) {
        $output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
        update_option( 'wpf_license_key', $output, 'no' );
    }
    return $output;
}

/*========WPF Login Form========*/
/*
 * This function is used to get the login form.
 *
 * @input NULL
 * @return String
 */
function wpf_login_form() {
    $output      = '';
    $wpf_enabled = get_site_data_by_key( 'enabled_wpfeedback' );
    if ( ! is_user_logged_in() && $wpf_enabled == 'yes' ) {
        $output = '<div id="login_form_content">
                    <p>
                        <b>Dive straight into the feedback!</b></br>
                        Login below and you can start commenting using your own user instantly
                    </p>
                    <form id="wpf_login" method="post">
                        <div class="wpf_user">
                            <label for="username"></label>
                            <input id="username" placeholder="Username OR Email Address" type="text" name="username">
                        </div>
                        <div class="wpf_password">
                            <label for="password"></label>
                            <input id="password" placeholder="Password" type="password" name="password">
                        </div>'
                        . wp_nonce_field( 'wpfeedback-script-nonce', 'wpf_security', true, false ) . 
                        '<input class="wpf_submit_button" type="submit" value="Login and start commenting" name="submit">
                        <p class="wpf_status"></p>
                    </form>
                </div>';
        $checkinguser    = __( 'Sending user info, please wait...', 'atarim-visual-collaboration' );
        $wrongcredential = __( 'Wrong username or password.', 'atarim-visual-collaboration' );
        $loginsuccessful = __( 'Login successful, redirecting...', 'atarim-visual-collaboration' );
        $localize        = '<script>var checkinguser= "' . addslashes( $checkinguser ) . '", wrongcredential= "' . addslashes( $wrongcredential ) . '", loginsuccessful= "' . addslashes( $loginsuccessful ) . '"</script>';
        $output .= $localize;
    }
    return $output;
}
add_shortcode( 'wpf_login_form', 'wpf_login_form' );


/*
 * This function is used to manage the errors generated while logging in from Atarim login modal.
 *
 * @input NULL
 * @return String
 */
function wpf_user_errors() {
    static $wp_error; // Will hold global variable safely
    return isset( $wp_error ) ? $wp_error : ( $wp_error = new WP_Error( null, null, null ) );
}

/*
 * This function is used to handle login from the Atarim login modal.
 *
 * @input NULL
 * @return JSON
 */
function wpf_ajax_login() {
    global $wpdb;
    // First check the nonce, if it fails the function will break
    check_ajax_referer( 'wpfeedback-script-nonce', 'wpf_security' );

    // Nonce is checked, get the POST data and sign user on
    $info                  = array();
    $info['user_login']    = sanitize_text_field( $_POST['username'] );
    $info['user_password'] = sanitize_text_field( $_POST['password'] );
    $info['remember']      = true;
    $user_name             = sanitize_text_field( $_POST['username'] );
    $user_login            = '';
    $user                  = '';
    
    $resultsap = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}users WHERE user_login = %s OR user_email = %s limit 1", $user_name, $user_name ) , ARRAY_A );
    if ( $resultsap ) {
        $user_login = isset( $resultsap[0]['user_login'] ) ? $resultsap[0]['user_login'] : "";
        $user = get_user_by( 'login', $user_login );
    }
    if ( ! $user ) {
        // if the user name doesn't exist
        wpf_user_errors()->add( 'empty_username', __( 'Invalid username' ) );
    }

    if ( ! isset( $_POST['password'] ) || $_POST['password'] == '' ) {
        // if no password was entered
        wpf_user_errors()->add( 'empty_password', __( 'Please enter a password' ) );
    }

    // check the user's login with their password

    if ( ! empty( $user ) && ! wp_check_password( $_POST['password'], $user->user_pass, $user->ID ) ) {
        // if the password is incorrect for the specified user
        wpf_user_errors()->add( 'empty_password', __( 'Incorrect password' ) );
    }

    // retrieve all error messages
    $errors = wpf_user_errors()->get_error_messages();
    if ( ! empty( $errors ) ) {
        echo wp_json_encode( array( 'loggedin' => false ) );
    } else { 
        wp_set_auth_cookie( $user->ID, true );
        wp_set_current_user( $user->ID, $_POST['username'] );
        echo wp_json_encode( array( 'loggedin' => true ) );
    }
    die();
}
// Enable the user with no privileges to run ajax_login() in AJAX
add_action( 'wp_ajax_nopriv_wpf_ajaxlogin', 'wpf_ajax_login' );

/*
 * This function is used to start the color picker.
 *
 * @input NULL
 * @return NULL
 */
if ( ! ( isset( $_GET['ct_builder'] ) ) && ! ( isset( $_GET['ct_inner'] ) ) ) {	
    function wpf_enqueue_color_picker( $hook_suffix ) {
        wp_enqueue_style( 'wp-color-picker' );
        if ( ! ( isset( $_GET['et_tb'] ) ) && ! ( isset( $_GET['et_fb'] ) ) ) {
            wp_enqueue_script( 'iris', admin_url( 'js/iris.min.js' ), array( 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-touch-punch' ), WPF_VERSION, true );
        }
    }
    add_action( 'wp_enqueue_scripts', 'wpf_enqueue_color_picker' );
}

/*
 * This function is used to check if the caching plugin is active on the website and deregister the Atarim CSS and JS if found.
 *
 * @input NULL
 * @return NULL
 */
function wpf_check_for_caching_plugin() {
    if ( is_plugin_active( 'wp-rocket/wp-rocket.php' ) ) {
        $wp_rocket_settings                  = get_option( 'wp_rocket_settings' );
        $wp_rocket_settings['exclude_css'][] = plugins_url() . '/atarim-client-interface-plugin/css/(.*).css';
        $wp_rocket_settings['exclude_js'][]  = plugins_url() . '/atarim-client-interface-plugin/js/(.*).js';
        if ( get_option( 'wpr_check' ) == "" ) {
            update_option( 'wp_rocket_settings', $wp_rocket_settings );
            update_option( 'wpr_check', 'true' );
        }
    }

    if ( is_plugin_active( 'fast-velocity-minify/fvm.php' ) ) {
        $wpf_fvm_options                = get_option( 'fastvelocity_min_ignorelist' );
        $wpf_update_fastvelocity_option = get_option( 'wpf_update_fastvelocity_option' );
        $wpf_fvm_options                = explode( PHP_EOL, $wpf_fvm_options );
        array_push( $wpf_fvm_options, '/atarim-client-interface-plugin/' );
        if ( $wpf_update_fastvelocity_option != 'yes' ) {
            update_option( 'wpf_update_fastvelocity_option', 'yes','no' );
            update_option( 'fastvelocity_min_ignorelist', implode( PHP_EOL, $wpf_fvm_options ) );
        }
    }
    if ( is_plugin_active( 'breeze/breeze.php' ) ) {
        $get_breeze_advanced_settings                     = get_option( 'wpf_update_breeze_option' );
        $breeze_advanced_settings                         = get_option( 'breeze_advanced_settings' );
        $breeze_advanced_settings['breeze-exclude-css'][] = '/wp-content/plugins/atarim-client-interface-plugin/css/(.*).css';
        $breeze_advanced_settings['breeze-exclude-js'][]  = '/wp-content/plugins/atarim-client-interface-plugin/js/(.*).js';
        if ( $get_breeze_advanced_settings != 'yes' ) {
            update_option( 'breeze_advanced_settings', $breeze_advanced_settings );
            update_option( 'wpf_update_breeze_option', 'yes','no' );
        }
    }
    if ( defined( 'WPFC_WP_PLUGIN_DIR' ) ) {
        $rules_std          = array();
        $new_rule1          = new stdClass;
        $new_rule2          = new stdClass;
        $new_rule1->prefix  = "contain";
        $new_rule1->content = "wp-content/plugins/atarim-client-interface-plugin/css";
        $new_rule1->type    = "css";
        $new_rule2->prefix  = "contain";
        $new_rule2->content = "wp-content/plugins/atarim-client-interface-plugin/js";
        $new_rule2->type    = "js";

        $wpfeedback_WpFastestCache_save = get_option( "wpf_WpFastestCache_option" );
        if ( $wpfeedback_WpFastestCache_save != 'true' ) {
            $get_rules_json = get_option( "WpFastestCacheExclude" );
            if ( $get_rules_json === false ) {
                array_push( $rules_std, $new_rule1 );
                array_push( $rules_std, $new_rule2 );
                update_option( "WpFastestCacheExclude", wp_json_encode( $rules_std ), "yes" );
                update_option( "wpf_WpFastestCache_option", 'true', "no" );
            } else {
                $rules_std = json_decode( $get_rules_json );
                if ( ! is_array( $rules_std ) ) {
                    $rules_std = array();
                }
                array_push( $rules_std, $new_rule1 );
                array_push( $rules_std, $new_rule2 );
                update_option( "WpFastestCacheExclude", wp_json_encode( $rules_std ), "yes" );
                update_option( "wpf_WpFastestCache_option", 'true', "no" );
            }
        }
    }
}
add_action( 'admin_init', 'wpf_check_for_caching_plugin' );

/*
 * This function is used create the options for the bulk updates of status and priority.
 *
 * @input String
 * @return String
 */
if ( ! function_exists( 'wpf_bulk_update_get_texonomy_selectbox' ) ) {
    function wpf_bulk_update_get_texonomy_selectbox( $my_term ) {
        $filter_data = get_option( 'filter_data' );
        $filter_data_db = $filter_data[$my_term];
        if ( isset( $filter_data_db ) && ! empty( $filter_data_db ) ) {
            echo '<select id="task_' . $my_term . '_attr" ><option name="' . $my_term . '" value="" class="wpf_task" id="wpf_critical">'. __( "Select Option", 'atarim-visual-collaboration' ) . '</option>';
            foreach ( $filter_data_db as $term ) {
                if ( $term['label'] == 'In Progress' ) {
                    $term['label'] = 'In Prog';
                } elseif ( $term['label'] == 'Pending Review' ) {
                    $term['label'] = 'Pending';
                } else {
                    $term['label'] = $term['label'];
                }
                echo '<option name="' . $my_term . '" value="' . $term['value'] . '" class="wpf_task" id="wpf_' . $term['value'] . '"/>' . __( $term['label'], 'atarim-visual-collaboration' ) . '</option>';
            }
            echo '</select>';
        }
    }
}

/*
 * function is used to generate
 * bottom task panel
 */
function generate_bottom_part_html() {
    global $wpdb, $wp_query, $post;
    global $wpf_task_status_filter_btn, $wpf_task_priority_filter_btn;
    $current_page_id = '';
    if ( is_feature_enabled( 'bottom_bar_enabled' ) ) {
        if ( is_admin() ) {
            $current_page_id = get_the_ID();
        }
        if ( $current_page_id == '' ) {
            if ( isset( $wp_query->post->ID ) ) {
                $current_page_id = $wp_query->post->ID;
            }
        }

        $current_user           = wp_get_current_user();
        $wpf_user_name          = $current_user->display_name;
        $wpf_user_email         = $current_user->user_email;
        $url                    = WPF_CRM_API . 'wp-api/page/is-approved';
        $sendarr                = array();
        $sendarr["wpf_site_id"] = get_option( 'wpf_site_id' );
        $sendarr["page_id"]     = $current_page_id;
        $sendtocloud            = wp_json_encode( $sendarr );
        $response               = wpf_send_remote_post( $url, $sendtocloud );
        $is_approved            = 0;
        $data                   = [];

        if ( isset( $response['status'] ) && $response['status'] ) {
            $is_approved = 1;
        }
        
        $currnet_user_information = wpf_get_current_user_information();
        $current_role             = $currnet_user_information['role'];
        $current_user_name        = ( ! empty( $currnet_user_information['first_name'] ) ) ? $currnet_user_information['first_name'] : $currnet_user_information['display_name'];
        $current_user_id          = $currnet_user_information['user_id'];
        
        if ( $current_user_name == 'Guest' ) {
            $wpf_current_role = 'guest';
        } else {
            $wpf_current_role = wpf_user_type();
        }
        $current_user_name = addslashes( $current_user_name ); 
        $wpf_powered_class = '_blank';
        $wpf_powered_by    = get_site_data_by_key( 'wpfeedback_powered_by' );
        $wpf_powerbylink   = WPF_APP_SITE_URL;
        $wpf_powerbylogo   = get_wpf_logo();
        $wpf_powerbyicon   = get_wpf_favicon();
        $inbox_link        = WPF_APP_SITE_URL . '/tasks?website=' . $sendarr["wpf_site_id"];
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
        
        $wpf_show_front_stikers = get_site_data_by_key( 'wpf_show_front_stikers' );
        
        /* =====Start filter sidebar HTML Structure==== */
        if ( $wpf_show_front_stikers == 'yes' ) {
            $checkbox_checked = "checked";
        } else {
            $checkbox_checked = "";
        }
        
        $wpf_current_page_url = get_permalink() . '?wpf_login=1';

        $inbox_link_html = '<a class="wpf_inbox_link_wrapper" href="' . $inbox_link . '" target="_blank">
                                <img src="' . WPF_PLUGIN_URL . 'images/app-project.svg" alt="link to inbox" >
                            </a>';
        
        if ( $is_approved == 1 ) {
            $btn_title         = __( "Approved", 'atarim-visual-collaboration' );
            $btn_approve_class = "";
        } else {
            $btn_title         = __( "Approve Page", 'atarim-visual-collaboration' );
            $btn_approve_class = "wpf_not_approved";
        }
               
        $approve_btn = '<button class="wpf_green_btn wpf_btn_hover approve-page ' . $btn_approve_class . '" id="open_approve_modal" title="' . __( "Approve Page", 'atarim-visual-collaboration' ) . '" data-is-approve="' . $is_approved . '">' . get_wpf_right_icon() . '<span>' . $btn_title . '</span> </button>';
        $responsive_btn = '';
        if ( !is_admin() ) {
            $responsive_btn = '<div class="wpf_responsive_icons_bar wpf_btn_hover">
                                    <img src="' . WPF_PLUGIN_URL . 'images/responsive.svg" alt="responsive box">
                                    <div class="wpf_responsove_options">
                                        <div class="wpf_desktop_view"><img src="' . WPF_PLUGIN_URL . 'images/desktop.svg" alt="desktop icon"><span>Desktop</span></div>
                                        <div class="wpf_tab_view wpf_responsive_tablet"><img src="' . WPF_PLUGIN_URL . 'images/tablet.svg" alt="tablate icon"><span>Tablet</span></div>
                                        <div class="wpf_mobile_view wpf_responsive_mobile"><img src="' . WPF_PLUGIN_URL . 'images/mobile.svg" alt="mobile icon"><span>Mobile</span></div>
                                    </div>
                            </div>';
        }
        
        if ( is_admin() == 1 ) {
            $approve_btn = "";
        }

        $milestone = '<div id="wpf_site_milestone"></div><div class="wpf_list wpf_hide" id="wpf_site_milestone_wpf_list"><div class="wpf_loader wpf_loader_2 wpf_hide"></div></div>';
        
        // Share version 2 popup structure by Pratap.
        $share_popup = '';
        if( current_user_can( 'administrator' ) ) {
            $current_page      = '';
            $page_token_url    = '';
            $current_login_url = '';
            if ( is_admin() ) {
                $screen = get_current_screen();
                if ( $screen -> id == 'dashboard' ) {
                    $current_page = admin_url();
                } else {
                    $current_page = admin_url( basename( $_SERVER['REQUEST_URI'] ) );
                }
                /*
                * Wordpress cannot allow accessing admin panel without login due to which guest access is not possible on admin side
                * Hence we set the frontend url in the invitation link
                */
                $current_page = get_site_url();
            } else {
                global $wp;
                $current_page = add_query_arg( $wp->query_vars, home_url( $wp->request ) );
            }

            $current_page = rtrim( $current_page, '/' );
            $guest_token  = 'guest_token=' . get_option( 'wpf_guest_token' ) . '&action=atarim';

            if ( strpos( $current_page, '?' ) !== false) {
                $page_token_url    = $current_page . '&collab_token=';
                $current_login_url = $current_page . '&' . $guest_token;
            } else {
                $page_token_url = $current_page . '/?collab_token=';
                $current_login_url = $current_page . '/?' . $guest_token;
            }

            $site_title = get_bloginfo( 'name' );

            $args = array(
                'meta_key' => 'avc_user_token',
                'meta_value' => '',
                'meta_compare' => '!='
            );
            $user_query = new WP_User_Query( $args );
            $user_list = $user_query->get_results();
            $user_data = array();
            if ( ! empty( $user_list ) ) {
                foreach ( $user_list as $user ) {
                    $user_info = $user->data;
                    $user_data[$user_info->ID]['id'] = $user_info->ID;
                    $user_data[$user_info->ID]['email'] = $user_info->user_email;
                    $user_data[$user_info->ID]['name'] = $user_info->display_name;
                    $token = get_user_meta( $user_info->ID, 'avc_user_token', true );
                    $link  = $page_token_url . $token;
                    $user_data[$user_info->ID]['link'] = $link . '&action=atarim';
                    $user_initial = substr( $user_info->user_email, 0, 2 );
                    $user_data[$user_info->ID]['initial'] = strtoupper( $user_initial );
                }
            }
        
            $share_popup = '<div class="avc_invite_share_wrapper">
                                <div class="avc_invite_share_container">
                                    <div class="avc_close_share_container"><span>+</span></div>
                                    <div class="avc_invite_title">Invite Collaborators</div>
                                    <div class="avc_invite_subtitle">Invite clients and stakeholders to collaborate on this project.</div>
                                    <div class="avc_invite_container">
                                        <div class="avc_invite_wrapper">
                                            <div class="avc_each_invite">
                                                <div class="avc_invite_fields">
                                                    <input tpye="text" name="invite_name" class="avc_invite_name" placeholder="Full name" >
                                                    <input type="email" name="invite_email" class="avc_invite_email" placeholder="Email address" >
                                                </div>
                                                <div class="avc_add_remove_invite">
                                                    <button class="avc_add_invite" tabindex="0" type="button">
                                                        <svg class="avc_add_inivite_svg" focusable="false" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"></path>
                                                        </svg>
                                                    </button>
                                                    <button class="avc_remove_invite" tabindex="0" type="button">
                                                        <svg class="avc_remove_inivite_svg" focusable="false" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path d="M19 13H5v-2h14v2z"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="send_invite">Invite Users</div>
                                    </div>
                                    <input type="hidden" name="avc_current_page" id="avc_current_page" value="'. $page_token_url .'" >
                                    <input type="hidden" name="avc_site_title" id="avc_site_title" value="'. $site_title .'" >
                                    <div class="avc_shared_container">
                                        <div class="avc_invited_user_title">People With Access</div>
                                        <div class="avc_shared_user_list">';
                                        if ( ! empty( $user_data ) ) {
                                            $i = 1;
                                            foreach( $user_data as $data ) {
                                                $share_popup .= '<div class="avc_invited_user">
                                                                    <div class="avc_ivi_user_info">
                                                                        <div class="avc_ivi_user_img">' . $data["initial"] . '</div>
                                                                        <div class="avc_ivi_user_name"><span>' . $data["name"] . '</span><br>' . $data["email"] . '</div>
                                                                        <div class="avc_user_share_action">
                                                                            <div class="avc_share_button">
                                                                                <input type="text" class="avc_share_page_link_input" id="share_link_input'. $i .'" value="' . $data["link"] . '">
                                                                                <a href="javascript:void(0);" onclick="avc_copy_to_clipboard(\'share_link_input'. $i .'\')" class="avc_copy_link_icon">
                                                                                    <img src="'.WPF_PLUGIN_URL.'/images/link.svg" > <span>Reshare Link</span>
                                                                                </a>
                                                                            </div>
                                                                            <div class="avc_delete_ivi_user" data-id="' . $data["id"] . '"><img src="' . WPF_PLUGIN_URL . 'images/delete.svg" alt="delete invitation" ></div>
                                                                        </div>
                                                                    </div>
                                                                </div>';
                                                $i++;
                                            }
                                        } else {
                                            echo '<style>
                                                .avc_shared_container {
                                                    display: none;
                                                }
                                            </style>';
                                        }
                                        $share_popup .= '</div>
                                    </div>
                                    <div class="avc_share_page_link">
                                        <div class="avc_share_or">Or Share This Link</div>
                                        <div class="avc_share_page_container">
                                            <div class="avc_share_link_container">
                                                <div class="avc_share_link">' . $current_login_url . '</div>
                                                <input type="text" class="avc_share_page_link_input" id="share_link_input" value="' . $current_login_url . '">
                                                <a href="javascript:void(0);" onclick="avc_copypagelink_to_clipboard(\'share_link_input\')" class="avc_copy_pagelink">Copy</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="avc_invite_error_container">
                                    <div class="avc_invite_error_msg1"><div class="avc_err_icon"></div>Please add the Full name.</div>
                                    <div class="avc_invite_error_msg2"><div class="avc_err_icon"></div>Please add an Email address.</div>
                                    <div class="avc_invite_error_msg3"><div class="avc_err_icon"></div>Name must be minimum 3 character long.</div>
                                    <div class="avc_invite_error_msg4"><div class="avc_err_icon"></div>Must be a valid Email address.</div>
                                </div>
                                <div class="wpf_loader wpf_hide"></div>
                                <div class="avc_suc_err_container">
                                    <div class="avc_invite_success"><div class="avc_succ_icon"></div>Invitation has been sent</div>
                                    <div class="avc_delete_success"><div class="avc_succ_icon"></div>Invitation has been deleted</div>
                                    <div class="avc_invite_fail"><div class="avc_err_icon"></div>Invitation failed</div>
                                </div>
                                <div class="avc_hidden_user_structure">
                                    <div class="avc_invited_user">
                                        <div class="avc_ivi_user_info">
                                            <div class="avc_ivi_user_img"></div>
                                            <div class="avc_ivi_user_name"></div>
                                            <div class="avc_user_share_action">
                                                <div class="avc_share_button">
                                                    <input type="text" class="avc_share_page_link_input" id="" value="">
                                                    <a href="javascript:void(0);" onclick="" class="avc_copy_link_icon">
                                                        <img src="'.WPF_PLUGIN_URL.'/images/link.svg" > <span>Reshare Link</span>
                                                    </a>
                                                </div>
                                                <div class="avc_delete_ivi_user" data-id=""><img src="' . WPF_PLUGIN_URL . 'images/delete.svg" alt="delete invitation" ></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>';
        }
        // share popup structure ends.

        $general_button = '<a class="wpf_general_btn wpf_comment_mode_general_task wpf_btn_hover" id="wpf_comment_mode_general_task" href="javascript:void(0)">
                                <img src="' . WPF_PLUGIN_URL . 'images/general.svg" alt="general button icon">
                                <span class="wpf_tooltip">' . __( 'Create a general task not tied to any page element, great for adding documents or engaging in wider project conversations.', 'atarim-visual-collaboration' ) . '</span>
                            </a>';
        $share_btn = '';
        if ( is_user_logged_in() ) {
            $share_btn = '<div class="wpf_share_btn wpf_btn_hover" href="javascript:void(0);">
                            <img src="' . WPF_PLUGIN_URL . 'images/share.svg" alt="share icon">
                            <span>' . __( 'Share', 'atarim-visual-collaboration' ) . '</span>
                            <span class="wpf_tooltip">' . __( 'Get everyone on board – stakeholders, clients, and your team – for an exciting visual collaboration journey on this project!', 'atarim-visual-collaboration' ) . '</span>
                        </div>';
        }

        $wpfb_users_json = do_shortcode('[wpf_user_list_front]');
        $wpfb_users      = json_decode($wpfb_users_json, true);
        $collab_user_html = '';
        if ( ! empty( $wpfb_users ) ) {
            $collab_user_html .= '<div class="wpf_collab_users_wrapper">
                                    <div class="wpf_collab_user_container">';
            foreach( $wpfb_users as $wpfb_user ) {
                $first_name = $wpfb_user['first_name'] ? $wpfb_user['first_name'] : $wpfb_user['displayname'];
                $last_name = $wpfb_user['last_name'] ? ' ' . $wpfb_user['last_name'] : '';
                $name = $first_name . $last_name;
                $collab_user_html .= '<div class="wpf_each_collab_user">
                                        <div class="wpf_collab_user_avatar">' . substr( $name, 0, 2) . '</div>
                                        <div class="wpf_collab_user_name">' . $name . '</div>
                                    </div>';
            }
            $collab_user_html .= '</div></div>';
            $total_users = count( $wpfb_users );
            $collab_user_html .= '<div class="wpf_total_collab_users">' . $total_users . '</div>';
        }
        $user_list_button = '';
        if ( is_user_logged_in() ) {
        $user_list_button = '<div class="wpf_collab_user_list">
                                <span class="wpf_current_user_name">' . substr( $current_user_name, 0, 2) . '</span>
                                ' . $collab_user_html . '
                            </div>';
        }

        $wpf_comment = '<span class="wpf_bc_text">Browse</span>
                        <label class="wpf_bc_switch">
                            <span class="wpf_bc_switch_slider wpf_bc_switch_round active_browse"></span>
                        </label>
                        <span class="wpf_bc_text">Comment</span>';

        return '<section class="wpf_bottombar_section"> <div id="wpf_bottom_bar"><div class="wpf_progress_bar"><div class="red-pb" id="open_progress"></div><div class="orange-pb" id="inprogress_progress"></div><div class="yellow-pb" id="pending_progress"></div><div class="green-pb" id="completed_progress"></div></div><div id="wpf_panel" class="wpf_row"><div class="wpf_bottom_left"><div class="footer-logo" title="Logo"><a href="' . $wpf_powerbylink . '" target="' . $wpf_powered_class . '"><img src="' . $wpf_powerbyicon . '" alt="poweredby" /></a></div>  ' . $inbox_link_html . $approve_btn . $responsive_btn . $milestone . '</div><div class="wpf_bottom_middle">'. $wpf_comment .'</div><div class="wpf_bottom_right">' . $user_list_button . $general_button . $share_btn . '</div></div><div id="wpf_enable_comment" class="wpf_row" style="display:none;"><div class="wpf_bottom_left"><div class="footer-logo" title="Logo"> <img src="' . $wpf_powerbylogo . '" alt="poweredby"></div> <span class="message">Choose a part of the page to add a message or, click the "General" button for a generic request</span></div><div class="wpf_bottom_right">  <a href="javascript:disable_comment();" id="disable_comment_a" class="tasks-btn wpf_red_btn" title="' . __( 'Cancel', 'atarim-visual-collaboration' ) . '">' . get_wpf_close_icon() . '<span class="title">' . __( 'Cancel', 'atarim-visual-collaboration' ) . '</span> </a></div></div><div class="wpf_page_loader"></div></div></section>'. $share_popup;
    }  
}
add_action( 'wpf_generate_bottom_part_html', 'generate_bottom_part_html' );

// Upgrade popup by Pratap
function wpf_upgrade_plan() {
    $wpf_user_type      = wpf_user_type();
    $enabled_wpfeedback = wpf_check_if_enable();
    if ( $enabled_wpfeedback ) {
    ?>
        <div class="wpf-uf-pop-wrapper" style="display:none">
            <div class="wpf-uf-pop-container">
                <div class="wpf-uf-popup">
                    <div class="wpf-uf-close-popup"><i class="gg-close"></i></div>
                    <div class="wpf-uf-popup-title">Let's Unlock This Feature</div>
                    <p>Unlock this feature and improve your workflow even further by upgrading your plan.</p>
                    <div class="wpf-uf-popup-plans">
                        <div class="wpf-uf-popup-image">
                            <img alt="" src="">
                        </div>
                        <div class="wpf-uf-plan">
                            <div class="wpf-uf-plan-title"></div>
                            <div class="wpf-uf-plan-detail"></div>
                            <a class="wpf-uf-plan-link" href="#" target="_blank"><div class="wpf-uf-plan-btn">Upgrade To Unlock</div></a>
                        </div>
                    </div>
                    <?php if ( $wpf_user_type = 'advisor' ) { ?>
                        <div class="wpf-uf-webmaster-notice">*This is only shown to you as a webmaster, other users will not see this</div>
                    <?php } else { ?>
                        <div class="wpf-uf-otheruser-notice">Please contact your webmaster to unlock this feature</div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php
    }
}
add_action( 'wp_footer', 'wpf_upgrade_plan' );
add_action( 'admin_footer', 'wpf_upgrade_plan' );

// Upgrade popup on license expires by Pratap
function wpf_subscribe_plan() {
    $wpf_user_type      = wpf_user_type();
    $enabled_wpfeedback = wpf_check_if_enable();
    if ( $enabled_wpfeedback ) {
    ?>
        <div class="wpf-le-pop-wrapper" style="display:none">
            <div class="wpf-le-pop-container">
                <div class="wpf-le-popup">
                    <div class="wpf-le-close-popup"><i class="gg-close"></i></div>
                    <div class="wpf-le-popup-plans">
                        <div class="wpf-le-plan">
                            <div class="wpf-le-icon"><img src= "<?php echo WPF_PLUGIN_URL; ?>/images/lock.svg" alt="lock image"></div>
                            <div class="wpf-le-title">Renew or upgrade your subscription</div>
                            <div class="wpf-le-detail">It looks like your subscription has expired or doesn’t exist, please upgrade your account to unlock this feature.</div>
                            <a class="wpf-le-plan-link" href="https://atarim.io/pricing" target="_blank"><div class="wpf-le-plan-btn">Upgrade Now</div></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }
}
add_action( 'wp_footer', 'wpf_subscribe_plan' );
add_action( 'admin_footer', 'wpf_subscribe_plan' );

function wpf_multifile_upload() {
    $wpf_user_type      = wpf_user_type();
    $enabled_wpfeedback = wpf_check_if_enable();
    $wpf_check_page_builder_active = wpf_check_page_builder_active();
    if ( $enabled_wpfeedback && $wpf_check_page_builder_active == 0 ) {
        echo '<div class="wpf_multifile_wrapper">
                <div class="wpf_multifile_container">
                    <div class="wpf_multifile_box">
                        <h2>Upload Files
                            <span>Drag and drop your files here or click the button to upload your files from your computer</span>
                            <button class="wpf_multifile_close" role="button" tabindex="0"><img src="' . WPF_PLUGIN_URL . 'images/cross.svg" ></button>
                        </h2>
                        <div class="wpf_multifile_form_container">
                            <div class="wpf_multifile_form">
                                <img src="' . WPF_PLUGIN_URL . 'images/multi-file.svg" alt="multi file upload" >
                                <span>Drag and drop your files</span>
                                <span>Max Upload: 20 MB</span>
                                <button class="wpf_file_upload_button">
                                    <input multiple type="file" name="wpf_uploadfile_" id="wpf_uploadfile_" data-elemid="" class="wpf_uploadfile"> 
                                    <img src="' . WPF_PLUGIN_URL . 'images/upload.svg" > Upload Files</button>
                            </div>
                            <div class="wpf_selectedfile_list"></div>
                            <div class="wpf_multifile_actions">
                                <button class="wpf_multifile_cancel">Cancel</button>
                                <button class="wpf_multifile_submit wpf_multifile_submit_disabled">Upload Files</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
    }
}
add_action( 'wp_footer', 'wpf_multifile_upload' );
add_action( 'admin_footer', 'wpf_multifile_upload' );

function image_preview() {
    $wpf_user_type      = wpf_user_type();
    $enabled_wpfeedback = wpf_check_if_enable();
    $wpf_check_page_builder_active = wpf_check_page_builder_active();
    if ( $enabled_wpfeedback && $wpf_check_page_builder_active == 0 ) {
        echo '<div class="wpf_image_preview_wrappper">
                <div class="wpf_image_preview_container">
                    <div class="wpf_image_preview_box">
                        <span class="wpf_close_image_preview"><img src="' . WPF_PLUGIN_URL . 'images/cross.svg" alt="close image preview" ></span>
                        <div class="wpf_image_preview">
                            <img src="" alt="image preview" class="wpf_preview_image">
                        </div>
                    </div>
                </div>
            </div>';
    }
}
add_action( 'wp_footer', 'image_preview' );
add_action( 'admin_footer', 'image_preview' );

/**
 * Get the author name of the tasks
 */
function get_task_author( $mypost ) {
    /* assign the WP names (first & last name / nickname) if the author is on the site */

    // by default, we take name that is coming from the API
    $author = $mypost['task_config_author_name'] ?? "Guest";

    // Task author name for Share version 2 by Pratap.
    if ( $mypost['task_config_author_id'] > 0 ) {
        $userInfo = get_userdata( $mypost['task_config_author_id'] );
        if ( ! empty( $userInfo ) ) {
            $userr = $userInfo->data;
            return $userr->display_name;
        }
    }

    // list all the usere who's roles are allowed to create tasks
    $wpf_front_users = do_shortcode( '[wpf_user_list_front]' );
                
    if ( $wpf_front_users ) {

        // convert JSON to array
        $wpf_front_users_arr = json_decode( $wpf_front_users, true );

        // get the user data by filtering the roles allowed to create tasks
        $front_user_meta = array_filter( $wpf_front_users_arr, function( $user ) use( $mypost ) {
            return intval( $user['id'] ) === intval( $mypost['task_config_author_id'] );
        } );

        // if the user dats found, we will assign the appropriate name
        if ( is_array( $front_user_meta ) ) { // if ( !empty( $front_user_name ) && is_array( $front_user_meta ) )

            // as the filter returns the array with the task's id as key, we only need the data in the array by unpacking
            $front_user_metas = array_merge( $front_user_meta );

            // assign the first and last name if present, nickname otherwise.
            return $front_user_metas[0]['displayname'] ?? $author;
        }
    }
    return $author;
}


/**
 * Checks if the feature has enabled by the plan or not
 */
function is_feature_enabled( $feature_name ) {
    $wpf_user_plan = get_option( 'wpf_user_plan', false );
    if ( $wpf_user_plan ) {
        $wpf_user_plan = unserialize( $wpf_user_plan );
    }

    // Enable the feature if the data not in the DB
    if ( empty( $wpf_user_plan ) ) {
        return true;
    }
    return ( ! empty( $wpf_user_plan[$feature_name] ) && $wpf_user_plan[$feature_name] === 'yes' );
}

/**
 * This function is to add sidebar header
 */
function sidebar_header() {
    return '<div class="wpf_sidebar_header">
                <div class="top_tabs">
                    <div class="wpf_sidebar_top1">
                        <div class="wpf_hide_sidebar_wrapper">
                            <div class="wpf_hide_sidebar">
                                <img src="' . WPF_PLUGIN_URL . "images/cross.svg" . '"  class="wpf_hide_sidebar_icon" alt="hide sidebar icon" >
                            </div>
                        </div>
                        <div class="wpf_side_switch">
                            <img src="' . WPF_PLUGIN_URL . "images/sidebar-left.svg" . '"  class="wpf_sidebar_left" alt="sidebar left" >
                        </div>
                    </div>
                    <div class="wpf_sidebar_top2">
                        <div class="wpf_sidebar_search">
                            <img src="' . WPF_PLUGIN_URL . "images/search.svg" . '"  class="wpf_search_img" alt="search icon" >
                        </div>
                        <div class="wpf_sidebarmenu">
                            <img src="' . WPF_PLUGIN_URL . "images/sidebarmenu.svg" . '"  class="wpf_sidebarmenu_img" alt="sidebar menu" >
                            <div class="wpf_sidebar_submenu">
                                <div class="wpf_tab_sidebar wpf_thispage" onclick="openWPFTab(\'wpf_thispage\')"><span class="wpf_filter_check active_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>This Page</div>
                                <div class="wpf_tab_sidebar wpf_allpages" onclick="openWPFTab(\'wpf_allpages\')"><span class="wpf_filter_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>All Pages</div>
                                <div class="wpf_tab_sidebar wpf_showcomp" onclick="openWPFTab(\'wpf_showcomp\')"><span class="wpf_filter_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>Show Complete Tasks</div>
                                <div class="wpf_tab_sidebar wpf_showint" onclick="openWPFTab(\'wpf_showint\')"><span class="wpf_filter_check active_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>Show Internal Tasks</div>
                                <div class="wpf_tab_sidebar wpf_bydate" onclick="openWPFTab(\'wpf_bydate\')"><span class="wpf_filter_check active_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>Sort by Date</div>
                                <div class="wpf_tab_sidebar wpf_byprior" onclick="openWPFTab(\'wpf_byprior\')"><span class="wpf_filter_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>Sort by Priority</div>
                                <div class="wpf_tab_sidebar wpf_bystatus" onclick="openWPFTab(\'wpf_bystatus\')"><span class="wpf_filter_check"><img src="' . WPF_PLUGIN_URL . "images/checked.svg" . '" alt="sidebar menu" ></span>Sort by Status</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="wpf_search_field">
                    <input onkeyup="wp_feedback_task_filter(event, this)" type="text" name="wpf_search_title" placeholder="Search" class="wpf_search_title" id="wpf_search_title">
                </div>             
            </div>';
}
/**
 * This function is to add sidebar tabs
 */
function sidebar_tabs() {
    return '<div class="wpf_sidebar_tabs">
                <div class="wpf_tab_wrapper">
                    <div class="wpf_tab_container">
                        <div class="wpf_task_tab wpf_active_tab">Tasks</div>
                        <div class="wpf_page_tab">Pages</div>
                        <div class="wpf_tab_active"></div>
                    </div>
                </div>
            </div>';
}
/**
 * This function is to add no task found message on sidebar
 */
function sidebar_content() {
    global $wp;
    return '<div class="wpf_sidebar_content">
                <div class="wpf_no_task wpf_hide">
                    <div class="wpf_no_task_img">
                        <img src="' . WPF_PLUGIN_URL . "images/no-task.svg" . '" alt="no task in sidebar" >
                    </div>
                    <div class="wpf_no_task_title">Add your comments</div>
                    <div class="wpf_no_task_desc">Click any part of the page to start collaborating</div>
                </div>
                <div class="wpf_sidebar_loader wpf_hide"></div>                
                <div id="wpf_thispage" class="wpf_thispage_tab wpf_container wpf_active_filter"><!--<div class="custom_today">today</div>--><ul id="wpf_thispage_container_today"></ul><!--<div class="custom_yesterday">yesterday</div> --> <ul id="wpf_thispage_container_yesterday"></ul><!-- <div class="custom_Weekly">Weekly</div> --> <ul id="wpf_thispage_container_this_week"></ul><!-- <div class="custom_this_month">This Month</div> --> <ul id="wpf_thispage_container_this_month"></ul><!-- <div class="custom_year">This Year</div> --> <ul id="wpf_thispage_container_year"></ul> <!-- <div class="custom_other">Other</div> --> <ul id="wpf_thispage_container_other"></ul></div>
                <div id="wpf_allpages" class="wpf_allpages_tab wpf_container" style="display:none;"><!--<div class="custom_today">today</div>--><ul id="wpf_allpages_container_today"></ul><!--<div class="custom_yesterday">yesterday</div> --> <ul id="wpf_allpages_container_yesterday"></ul><!-- <div class="custom_Weekly">Weekly</div> --> <ul id="wpf_allpages_container_this_week"></ul><!-- <div class="custom_this_month">This Month</div> --> <ul id="wpf_allpages_container_this_month"></ul><!-- <div class="custom_year">This Year</div> --> <ul id="wpf_allpages_container_year"></ul> <!-- <div class="custom_other">Other</div> --> <ul id="wpf_allpages_container_other"></ul></div>
                <div id="wpf_all_pages" style="display:none;">
                    <div class="wpf_page_list">

                    </div>
                    <div class="wpf_add_page">Add This Page</div>
                </div>
                <div class="wpf_loading">Loading...</div>
            </div>';
}

function wpf_launcher() {
    $wpf_powerbyicon = get_wpf_favicon();
    return '<div class="wpf_launch_comment_mode">
                <img src="' . $wpf_powerbyicon . '" alt="poweredby" />
                <div class="wpf_total_task_number"></div>
            </div>';
}

function page_tab_content( $wpside ) {
    $post_data = array(
        'wpf_site_id'  => get_option( 'wpf_site_id' ),            
        'wpf_is_front' => ( $wpside == 'backend' ) ? false : true ,
    );
    $args = array(
        'body'        => $post_data,
        'method'      => 'GET',
        'data_format' => 'body',
        'timeout'     => 100,
    );
    $url = WPF_CRM_API . 'wp-api/site/pages';
    $response = wp_remote_post( $url, $args );
    $pages_html = '';
    if( $response['response']['code'] == 200 ) {
        if( $response['body'] != '' ) {
            $body = json_decode($response['body']);
            $data = $body->data;
            $pages = $data->wp_pages;
            if ( ! empty( $pages ) ) {
                $pages_html = get_all_pages( $pages );
            } else {
                $pages_html = get_no_page_html();
            }
        }
    }
    return $pages_html;
}

function get_all_pages( $pages ) {
    $pages_html = '';
    foreach( $pages as $page ) {
        $page_url = $page->url;
        $page_id = $page->id;
        $page_title = $page->label;
        $total_tasks = $page->total_tasks;
        $alterimg = WPF_PLUGIN_URL . 'images/placeholder-image.png';
        $page_screenshot = image_exists_checker( $page->screenshot, $alterimg );
        $is_approved = $page->is_approved;
        $total_task_html = '<div class="wpf_task_cout">' . $total_tasks . '</div>';
        $approved_class = '';
        $approved_label = '';
        $pages_html .= page_html( $page_id, $page_url, $page_title, $total_tasks, $is_approved, $page_screenshot );
    }
    return $pages_html;
}

function page_html( $page_id, $page_url, $page_title, $total_tasks, $is_approved, $page_screenshot ) {
    $total_task_html = '<div class="wpf_task_cout">' . $total_tasks . '</div>';
    $approved_class = '';
    $approved_label = '';
    $active = '';
    $current_url = '';
    if ( is_admin() ) {
        $current_url = site_url($_SERVER['REQUEST_URI']);
    } else {
        $current_url = home_url(add_query_arg(array(), $_SERVER['REQUEST_URI']));
    }
    if ( $current_url == $page_url ) {
        $active = 'active';
    }
    if ( $is_approved == 1 ) {
        $approved_class = 'approved';
        $approved_label = '<span class="wpf_approved_label">Approved</span>';
        $total_task_html = '<img src="' . WPF_PLUGIN_URL . "images/approved.svg" . '" alt="page approved icon">';
    }
    return '<div class="wpf_each_page ' . $active . '" data-page-id="' . $page_id . '" data-page-url="' . $page_url . '">
                <div class="wpf_page_header">
                    <span class="wpf_page_title">' . $page_title . '</span>
                    <span class="wpf_page_task_total">' . $total_task_html . '</span>
                    <span class="wpf_delete_page"><img src="' . WPF_PLUGIN_URL . "images/delete.svg" . '" alt="delete icon" class="wpf_delete_page_icon"></span>
                </div>
                <div class="wpf_page_content '. $approved_class .'" style="background-image: url(' . WPF_PLUGIN_URL . 'images/placeholder-image.png); background-position: bottom; background-size: cover;">
                    <img src="' . $page_screenshot . '" alt="page screenshot">
                    ' . $approved_label . '
                </div>
            </div>';
}

function get_no_page_html() {
    return '<div class="wpf_no_page_found">
                <div class="wpf_no_page_img">
                    <img src="' . WPF_PLUGIN_URL . "images/no-pages.svg" . '" alt="no page image" >
                </div>
                <div class="wpf_no_page_header">' . __( "Let's get started", "atarim-visual-collaboration" ) . '</div>
                <div class="wpf_no_page_body">' . __( "Join forces - click the button below to add this page", "atarim-visual-collaboration" ) . '</div>
            </div>';
}

function is_non_collab_screen() {
    if ( is_admin() ) {
        $wpf_current_screen = get_current_screen();
        $wpf_current_screen = $wpf_current_screen->id;
        if ( $wpf_current_screen == 'collaborate_page_collaboration_page_permissions' || $wpf_current_screen == 'collaborate_page_collaboration_page_settings' || $wpf_current_screen == 'toplevel_page_collaboration_task_center' ) {
            return true;
        }
    }
    return false;
}

// funciton to return uploaded file html for task center by Pratap.
function get_files_html($files) {
    $files_html = '';
    if( count($files) > 0 ) {
        $img_html = '';
        $docu_html = '';
        $each_img_html = '';
        $each_docu_html = '';
        $has_img = false;
        $has_docu = false;
        foreach( $files as $file ) {
            $file_name = explode('_', $file['name'])[1];
            $file_size = get_file_size($file['size']);
            $bg_class = '';
            if( $file['type'] == 'image/png' || $file['type'] == 'image/jpeg' || $file['type'] == 'image/gif' ) {
                $has_img = true;
                $each_img_html .= '<div class="wpf_comment_each_img wpf_uploaded_doc"><img src="' . $file['url'] . '" alt="file upload"><div class="wpf_file_icons" data-file-id="' . $file['id'] . '">' . $preview_image_icon . $delete_icon . '</div></div>';
            } else {
                $has_docu = true;
                if( $file['type'] == 'application/pdf' ) {
                    $src = WPF_PLUGIN_URL . 'images/pdf.svg';
                    $bg_class = 'pdf';
                } else if( $file['type'] == 'text/plain' ) {
                    $src = WPF_PLUGIN_URL . 'images/txt.svg';
                    $bg_class = 'txt';
                } else {
                    $src = WPF_PLUGIN_URL . 'images/docs.svg';
                }
                $each_docu_html .= '<div class="wpf_comment_each_doc wpf_uploaded_doc"><div class="wpf_doc_container"><div class="wpf_doc_img_container ' . $bg_class . '"><img src="' . $src . '" alt="file upload"></div><div class="wpf_doc_meta_container"><div class="wpf_doc_name" data-link="' . $file['url'] . '">' . $file_name . '</div><span class="wpf_doc_size">' . $file_size . '</span></div></div><div class="wpf_file_icons" data-file-id="' . $file['id'] . '">' . $download_file_icon . $delete_icon . '</div></div>';
            }
        }
        if( $has_img ) {
            $img_html = '<div class="wpf_comment_images">' . $each_img_html . '</div>';
        }
        if( $has_docu ) {
            $docu_html = '<div class="wpf_comment_documents">' . $each_docu_html . '</div>';
        }
        $files_html .= '<div class="wpf_comment_files_wrapper">' . $img_html . $docu_html . '</div>';
    }
    return $files_html;
}

function get_file_size($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'M', 'G', 'T');   

    return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
}

// Function to encrypt the integer ID
function encrypt_id( $id ) {
    $ciphering = "AES-128-CTR";
    $iv_length = openssl_cipher_iv_length($ciphering);
    $options = 0;

    // Convert the ID to a string
    $id_string = (string)$id;

    // Generate a secure, random IV
    $encryption_iv = openssl_random_pseudo_bytes($iv_length);

    $encryption_key = "atarim";

    // openssl_encrypt() function to encrypt the data
    $encryption = openssl_encrypt($id_string, $ciphering, $encryption_key, $options, $encryption_iv);

    // Encode the IV and the encrypted string together
    $encrypted_string = base64_encode($encryption_iv . $encryption);

    // Make the base64 URL-safe
    $encrypted_string = str_replace(['+', '/', '='], ['-', '_', ''], $encrypted_string);

    return $encrypted_string;
}

// Function to decrypt the encrypted string back to the integer ID
function decrypt_id( $encrypted_string ) {
    $ciphering = "AES-128-CTR";
    $iv_length = openssl_cipher_iv_length($ciphering);
    $options = 0;

    $encryption_key = "atarim";

    // Reverse the URL-safe base64 encoding
    $encrypted_string = str_replace(['-', '_'], ['+', '/'], $encrypted_string);

    // Ensure proper padding for base64 decoding
    $mod4 = strlen($encrypted_string) % 4;
    if ($mod4) {
        $encrypted_string .= substr('====', $mod4);
    }

    // Decode the encrypted string
    $decoded_encrypted_string = base64_decode($encrypted_string);

    // Extract the IV and the encrypted data
    $extracted_iv = substr($decoded_encrypted_string, 0, $iv_length);
    $extracted_encrypted_data = substr($decoded_encrypted_string, $iv_length);

    // Decrypt the data
    $decryption = openssl_decrypt($extracted_encrypted_data, $ciphering, $encryption_key, $options, $extracted_iv);

    // Convert the decrypted string back to an integer
    $id = (int)$decryption;

    return $id;
}

