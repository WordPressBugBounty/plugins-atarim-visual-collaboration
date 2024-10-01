<div class="wrap wpfeedback-settings">
    <style>
        div#wpf_launcher {
            display: none !important;
        }
    </style>
    <?php 
    global $current_user;
    $wpf_user_name                  = $current_user->user_nicename;
    $wpf_user_email                 = $current_user->user_email;
    $wpfeedback_font_awesome_script = get_site_data_by_key( 'wpfeedback_font_awesome_script' );
    $wpf_user_type                  = wpf_user_type();
    $wpf_license_key                = get_option( 'wpf_license_key' );
    $wpf_license_key                = wpf_crypt_key( $wpf_license_key, 'd' );

    if ( $wpf_user_type == 'advisor' ) {
        $wpf_tab_permission_user              = ! empty( get_site_data_by_key( 'wpf_tab_permission_user_webmaster' ) ) ? get_site_data_by_key( 'wpf_tab_permission_user_webmaster' ) : 'no';
        $wpf_tab_permission_priority          = ! empty( get_site_data_by_key( 'wpf_tab_permission_priority_webmaster' ) ) ? get_site_data_by_key( 'wpf_tab_permission_priority_webmaster' ) : 'no';
        $wpf_tab_permission_status            = ! empty( get_site_data_by_key( 'wpf_tab_permission_status_webmaster' ) ) ? get_site_data_by_key( 'wpf_tab_permission_status_webmaster' ) : 'no';
        $wpf_tab_permission_screenshot        = ! empty( get_site_data_by_key( 'wpf_tab_permission_screenshot_webmaster' ) ) ? get_site_data_by_key( 'wpf_tab_permission_screenshot_webmaster' ) : 'no';
        $wpf_tab_permission_information       = ! empty( get_site_data_by_key( 'wpf_tab_permission_information_webmaster' ) ) ? get_site_data_by_key( 'wpf_tab_permission_information_webmaster' ) : 'no';
        $wpf_tab_permission_delete_task       = ! empty( get_site_data_by_key( 'wpf_tab_permission_delete_task_webmaster' ) ) ? get_site_data_by_key( 'wpf_tab_permission_delete_task_webmaster' ) : 'no';
        $wpf_tab_permission_display_stickers  = get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != 'no' ? get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) : 'no';
        $wpf_tab_permission_display_task_id   = get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ? get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) : 'no';
        $wpf_tab_permission_keyboard_shortcut = get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_webmaster' ) != 'no' ? get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_webmaster' ) : 'no'; /* v2.1.0 */
    }
    else if ( $wpf_user_type == 'king' ) {
        $wpf_tab_permission_user              = ! empty( get_site_data_by_key( 'wpf_tab_permission_user_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_user_client' ) : 'no';
        $wpf_tab_permission_priority          = ! empty( get_site_data_by_key( 'wpf_tab_permission_priority_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_priority_client' ) : 'no';
        $wpf_tab_permission_status            = ! empty( get_site_data_by_key( 'wpf_tab_permission_status_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_status_client' ) : 'no';
        $wpf_tab_permission_screenshot        = ! empty( get_site_data_by_key( 'wpf_tab_permission_screenshot_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_screenshot_client' ) : 'no';
        $wpf_tab_permission_information       = ! empty( get_site_data_by_key( 'wpf_tab_permission_information_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_information_client' ) : 'no';
        $wpf_tab_permission_delete_task       = ! empty( get_site_data_by_key( 'wpf_tab_permission_delete_task_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_delete_task_client' ) : 'no';
        $wpf_tab_permission_display_stickers  = ! empty( get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' ) : 'no';
        $wpf_tab_permission_display_task_id   = ! empty( get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' ) : 'no';
        $wpf_tab_permission_keyboard_shortcut = ! empty( get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_client' ) ) ? get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_client' ) : 'no'; /* v2.1.0 */
    }
    else if ( $wpf_user_type == 'council' ) {
        $wpf_tab_permission_user              = ! empty( get_site_data_by_key( 'wpf_tab_permission_user_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_user_others' ) : 'no';
        $wpf_tab_permission_priority          = ! empty( get_site_data_by_key( 'wpf_tab_permission_priority_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_priority_others' ) : 'no';
        $wpf_tab_permission_status            = ! empty( get_site_data_by_key( 'wpf_tab_permission_status_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_status_others' ) : 'no';
        $wpf_tab_permission_screenshot        = ! empty( get_site_data_by_key( 'wpf_tab_permission_screenshot_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_screenshot_others' ) : 'no';
        $wpf_tab_permission_information       = ! empty( get_site_data_by_key( 'wpf_tab_permission_information_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_information_others' ) : 'no';
        $wpf_tab_permission_delete_task       = ! empty( get_site_data_by_key( 'wpf_tab_permission_delete_task_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_delete_task_others' ) : 'no';
        $wpf_tab_permission_display_stickers  = ! empty( get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' ) : 'no';
        $wpf_tab_permission_display_task_id   = ! empty( get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' ) : 'no';
        $wpf_tab_permission_keyboard_shortcut = ! empty( get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_others' ) ) ? get_site_data_by_key( 'wpf_tab_permission_keyboard_shortcut_others' ) : 'no'; /* v2.1.0 */
    }
    else {
        $wpf_tab_permission_user              = 'no';
        $wpf_tab_permission_priority          = 'no';
        $wpf_tab_permission_status            = 'no';
        $wpf_tab_permission_screenshot        = 'yes';
        $wpf_tab_permission_information       = 'yes';
        $wpf_tab_permission_delete_task       = 'no';
        $wpf_tab_permission_display_stickers  = 'no';
        $wpf_tab_permission_display_task_id   = 'no';
        $wpf_tab_permission_keyboard_shortcut = 'no'; /* v2.1.0 */
    }


    if ( $wpfeedback_font_awesome_script == 'yes' ) { ?>
        <link rel='stylesheet' id='wpf-font-awesome-all'  href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" media="all" crossorigin="anonymous"/>
    <?php } ?>
    <script>
        jQuery(document).ready(function () {
            jQuery_WPF('[data-toggle="tooltip"]').tooltip();
        });
    </script>
    <div class="wpf_logo">
        <img src="<?php echo get_wpf_logo(); ?>" alt="Atarim">
    </div>

    <!-- ================= TOP TABS ================-->

    <div class="wpf_tabs_container" id="wpf_tabs_container">
        <button class="wpf_tab_item wpf_tasks" onclick="location.href='admin.php?page=collaboration_task_center'">
            <?php esc_attr_e( 'Tasks', 'atarim-visual-collaboration' ); ?>
        </button>
        <?php if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) { ?>
            <button class="wpf_tab_item wpf_settings active">
                <?php esc_attr_e( 'Settings', 'atarim-visual-collaboration' ); ?>
            </button>
        <?php }
        if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) { ?>
            <button class="wpf_tab_item wpf_misc" onclick="location.href='admin.php?page=collaboration_page_permissions'">
                <?php esc_attr_e( 'Permissions', 'atarim-visual-collaboration' ); ?>
            </button>
        <?php } ?>
    </div>
    <?php
    $wpf_daily_report  = get_site_data_by_key( 'wpf_daily_report' );
    $wpf_weekly_report = get_site_data_by_key( 'wpf_weekly_report' );
    if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) {
        ?>
        <div id="wpf_settings" class="wpf_container">
            <div class="wpf_loader_admin hidden"></div>
            <div id="wpf_global_settings_overlay" <?php if ( get_site_data_by_key( 'wpf_global_settings' ) != 'yes' ) { echo "class='wpf_hide'"; } ?> >
                <div class="wpf_welcome_wrap"><div class="wpf_welcome_title"><?php esc_attr_e( 'Global Settings', 'atarim-visual-collaboration' ); ?></div>
                    <p><?php esc_attr_e( 'Update your settings from the Global Settings area within your Atarim dashboard.', 'atarim-visual-collaboration' ); ?></p>
                    <div class="wpf_golbalsettings_buttons">
                        <div class="wpf_settings_icon">Local <i class="gg-database"></i></div>
                        <label class="wpf_switch">
                            <input type="checkbox" name="wpf_global_settings" class="wpf_global_settings" <?php if ( get_site_data_by_key( 'wpf_global_settings' ) == 'yes' ) { echo "checked"; } ?> >
                            <span class="wpf_switch_slider wpf_switch_round"></span>
                        </label>
						<div class="wpf_settings_icon"><i class="gg-cloud"></i> <?php esc_attr_e( 'Dashboard', 'atarim-visual-collaboration' ); ?></div>
                    </div>
                    <p><a href="<?php echo WPF_APP_SITE_URL; ?>/settings" target="_blank"><?php esc_attr_e( 'Edit your global settings', 'atarim-visual-collaboration' ); ?></a></p>
					<div class="wpf_welcome_image"><img alt="global settings" src="<?php echo esc_url( WPF_PLUGIN_URL . 'images/global-settings.png' ); ?>"/></div>
                </div>
            </div>
            <div class="wpf_section_title"><?php esc_attr_e( 'Main Settings', 'atarim-visual-collaboration' ); ?></div>
            <p id="wpf_global_erro_msg" class="wpf_hide" style="color: red;"><?php esc_attr_e( "There seems to be some issue with enabling the global settings. Please contact support for help.", 'atarim-visual-collaboration' ); ?></p>
            <form method="post" action="admin-post.php" id="wpf_form_site_setting" enctype="multipart/form-data" >
                <div class="wpf_settings_ctt_wrap">
					<div class="wpf_settings_sidebar">
                        <div class="wpf_settings_inner_sidebar">
                            <a href="#wpf_global"><?php esc_attr_e( 'Global Settings', 'atarim-visual-collaboration' ); ?></a>
                            <a href="#wpf_general_settings"><?php esc_attr_e( 'General Settings', 'atarim-visual-collaboration' ); ?></a>
                            <a href="#wpf_branding"><?php esc_attr_e( 'White Label', 'atarim-visual-collaboration' ); ?></a>
                            <a href="#wpf_notifications"><?php esc_attr_e( 'Notification Settings', 'atarim-visual-collaboration' ); ?></a>
                            <a href="#wpf_email"><?php esc_attr_e( 'Email Notifications', 'atarim-visual-collaboration' ); ?></a>
						    <?php
                            $wpf_license         = get_option( 'wpf_license' );
                            $wpf_disable_for_app = get_site_data_by_key( 'wpf_disable_for_app' );
                            if ( $wpf_license == 'valid' && $wpf_disable_for_app != 'yes' ) {
                                ?>
                                <div class="wpf_resync_dashboard">
                                    <div class="wpf_title">
                                        <input type="button" value="<?php esc_attr_e('Resync the Atarim Dashboard', 'atarim-visual-collaboration'); ?>" class="wpf_button" onclick="wpf_resync_dashboard()"/>
                                            <?php 
                                            if ( isset( $_GET['resync_dashboard'] ) && $_GET['resync_dashboard'] == 1 ) {
                                                ?>
                                                <span class="wpf_resync_dashboard_msg" style="color: green; font-size: 12px;"><?php esc_attr_e( 'Websites should now be resync / added now to the dashboard. Please contact support in case if does not.', 'atarim-visual-collaboration' ); ?></span>
                                           <?php } ?>
                                    </div>
                                </div>
                            <?php }
					    esc_attr_e( 'Remember to Save Changes at the bottom of this screen to apply any changes.', 'atarim-visual-collaboration' ); ?>
					    </div>
                    </div>
                    <div class="wpf_settings_col">
                        <div class="wpf_inner_settings_col">						
                            <div class="wpf_title_section" id="wpf_global"><?php esc_attr_e( 'Global Settings', 'atarim-visual-collaboration' ); ?></div>
                            <div class="wpf_settings_option wpfeedback_enable_global">
                                <div class="wpf_title"><?php esc_attr_e( 'Enable Global Settings', 'atarim-visual-collaboration' ); ?></div>                            
                                <div class="wpf_description"><?php esc_attr_e( 'Everything you see on this screen can be managed globally from within your Atarim Dashboard. Enable this option to pull your General Settings, Branding options and Notification options from the Global Settings panel.', 'atarim-visual-collaboration' ); ?></div>
                                <label class="wpf_switch">
                                    <!--edited by Pratap-->
                                    <input type="checkbox" name="wpf_global_settings" class="wpf_global_settings <?php if ( ! is_feature_enabled( 'client_interface_global_settings' ) ) { ?> blocked <?php } ?>" <?php if ( get_site_data_by_key( 'wpf_global_settings' ) == 'yes' ) { echo "checked"; } ?> >
                                    <span class="wpf_switch_slider wpf_switch_round"></span>
                                </label>
                            </div>                   
                            <div class="wpf_title_section" id="wpf_general_settings"><?php esc_attr_e( 'General Settings', 'atarim-visual-collaboration' ); ?></div>
                            <p><?php esc_attr_e( 'On this screen, you can manage different settings of the plugin. You can white label it to match your own branding, control which notifications are sent out to the users of this WordPress website and a few other options below this text.', 'atarim-visual-collaboration' ); ?></p>
                            <p><b><?php esc_attr_e( 'You can also control the permissions of Atarim Client Interface:', 'atarim-visual-collaboration' ); ?></b><?php esc_attr_e( ' you can allow or disallow users to use certain functions, you can even turn on guest mode to allow any visitor to the website to use the tool without needing to login.' , 'atarim-visual-collaboration' ); ?>
                                <a href="admin.php?page=collaboration_page_permissions"><?php esc_attr_e( 'To find these settings, go here.', 'atarim-visual-collaboration' ); ?></a><?php esc_attr_e( 'You will also see your license settings on this page.', 'atarim-visual-collaboration' ); ?><br><br>
                            </p>
                            <div class="wpf_settings_option enabled_wpfeedback">
                                <div class="wpf_title"><?php esc_attr_e( 'Enable Atarim\'s Client Interface Plugin on this website', 'atarim-visual-collaboration' ); ?></div>
                                <div class="wpf_description"><?php esc_attr_e( 'This is used to enable and disable the collaboration functions on this website, to save you the trouble of having to deactivate it in your plugin settings.', 'atarim-visual-collaboration' ); ?></div>
                                <label class="wpf_switch">
                                    <input type="checkbox" name="enabled_wpfeedback" value="yes" id="enabled_wpfeedback" <?php if ( get_site_data_by_key( 'enabled_wpfeedback' ) == 'yes' ) { echo 'checked'; } ?> />
                                    <span class="wpf_switch_slider wpf_switch_round"></span>
                                </label>
                            </div>
                            <div class="wpf_settings_option wpf_enable_clear_cache">
                                <div class="wpf_title"><?php esc_attr_e( 'Clear object cache while commenting and creating tasks', 'atarim-visual-collaboration' ); ?></div>
                                <div class="wpf_description"><?php esc_attr_e( 'If you have object caching enabled on this website, you can tick this on to clear the cache when a comment or task is created. This may affect the speed of the website.', 'atarim-visual-collaboration' ); ?></div>
                                <label class="wpf_switch">
                                    <input type="checkbox" name="wpf_enable_clear_cache" value="yes" id="wpf_enable_clear_cache" <?php if ( get_site_data_by_key( 'wpf_enable_clear_cache' ) == 'yes' ) { echo 'checked'; } ?> />
                                    <span class="wpf_switch_slider wpf_switch_round"></span>
                                </label>
                            </div>
                            <div class="wpf_settings_option wpf_show_front_stikers">
                                <div class="wpf_title"><?php esc_attr_e( 'Show task stickers by default', 'atarim-visual-collaboration' ); ?></div>
                                <div class="wpf_description"><?php esc_attr_e( 'If this is switched off, you will not see stickers unless you open the sidebar while on the front-end', 'atarim-visual-collaboration' ); ?></div>
                                <label class="wpf_switch">
                                    <input type="checkbox" name="wpf_show_front_stikers" value="yes" id="wpf_show_front_stikers" <?php if ( get_site_data_by_key( 'wpf_show_front_stikers' ) == 'yes' ) { echo 'checked'; } ?> />
                                    <span class="wpf_switch_slider wpf_switch_round"></span>
                                </label>
                            </div>
                            <div class="wpf_settings_option wpf_allow_backend_commenting">
                                <div class="wpf_title"><?php esc_attr_e( 'Remove backend commenting', 'atarim-visual-collaboration' ); ?></div>
                                <div class="wpf_description"><?php esc_attr_e( 'By default you can create tasks on the front end AND on the back end. By ticking this option on, users will not be able to create tasks on any of the WP admin screens.', 'atarim-visual-collaboration' ); ?></div>
                                <label class="wpf_switch">
                                    <input type="checkbox" name="wpf_allow_backend_commenting" value="yes" id="wpf_allow_backend_commenting" <?php if ( get_site_data_by_key( 'wpf_allow_backend_commenting' ) == 'yes' ) { echo 'checked'; } ?> />
                                    <span class="wpf_switch_slider wpf_switch_round"></span>
                                </label>
                            </div>
                            <!--edited by Pratap-->
                            <div class="wpf-whitelabel-parent <?php if ( get_option( 'wpf_allowed_whitelabel' ) != 'true' ) { ?> blocked <?php } ?>">
                                <div class="wpf_title_section" id="wpf_branding"><?php esc_attr_e( 'White Label', 'atarim-visual-collaboration' ); ?></div>
                                <p><?php esc_attr_e( 'Here you can rebrand Atarim Client Interface by changing the main color and the logo.', 'atarim-visual-collaboration' ); ?><br />
                                <?php esc_attr_e( 'You can ', 'atarim-visual-collaboration' ); ?><strong><?php esc_attr_e( 'manage Global Settings across all of your websites', 'atarim-visual-collaboration' ); ?></strong> <?php esc_attr_e( 'where your license is activated by visiting the general settings screen on your', 'atarim-visual-collaboration' ); ?> <a href="<?php echo WPF_APP_SITE_URL; ?>/settings#whitelabel" target="_blank"><?php esc_attr_e( 'Atarim Dashboard', 'atarim-visual-collaboration' ); ?></a>.</p>                           
                                <div class="wpf_settings_option wpfeedback_replace_logo">
                                    <div class="wpf_title"><?php esc_attr_e( 'Replace the Atarim logo', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'This will replace the logo in the top right of this page and the logo on the notification emails that are sent out.', 'atarim-visual-collaboration' ); ?></div>
                                    <span class="img_desc"><?php esc_attr_e( 'The image should be 180x45 px. Allowed jpg, png.', 'atarim-visual-collaboration' ); ?></span>
                                    <div class="wpf_upload_image_button graphics_fields custom_image_upload"> 
                                        <div class="wpf_field_input">
                                            <div class="wpf_field_label"><?php esc_attr_e( 'Upload Image', 'atarim-visual-collaboration' ); ?></div>
                                            <i class="gg-image"></i>
                                            <input id="wpf_logo_file" type="file" name="wpf_logo_file" class="button">
                                        </div>
                                        <span class="wpf_preview_graphics_img wpf_hide"></span>
                                        <span class="wpf_error graphics_img"><?php esc_attr_e( 'Please select image', 'atarim-visual-collaboration' ); ?></span>
                                    </div>
                                    <div class='wpfeedback_image-preview-wrapper'>
                                        <img id='wpfeedback_image-preview' src='<?php echo get_wpf_logo(); ?>' alt="logo" height='100' />
                                    </div>
                                </div>                            
                                <div class="wpf_settings_option wpfeedback_replace_logo wpf_replace_icon">
                                    <div class="wpf_title"><?php esc_attr_e( 'Replace the Atarim icon', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'This will replace the Atarim icon in the admin side menu.', 'atarim-visual-collaboration' ); ?></div>
                                    <span class="img_desc"><?php esc_attr_e( 'The image should be 50px X 50px. Allowed jpg, png.', 'atarim-visual-collaboration' ); ?></span>
                                    <div class="wpf_upload_image_button graphics_fields custom_image_upload"> 
                                        <div class="wpf_field_input">
                                            <div class="wpf_field_label"><?php esc_attr_e( 'Upload Image', 'atarim-visual-collaboration' ); ?></div>
                                            <i class="gg-image"></i>
                                            <input id="wpf_icon_file" type="file" name="wpf_favicon_file" class="button">
                                        </div>
                                        <span class="wpf_preview_graphics_img wpf_hide"></span>
                                        <span class="wpf_error graphics_img"><?php esc_attr_e( 'Please select image', 'atarim-visual-collaboration' ); ?></span>
                                    </div>                    
                                    <div class='wpfeedback_image-preview-wrapper'>
                                        <img id='wpfeedback_icon-preview' src='<?php echo get_wpf_favicon(); ?>' alt="icon" width='80' />
                                    </div>
                                </div>
                                <div class="wpf_settings_option wpfeedback_more_emails">
                                    <div class="wpf_title"><?php esc_attr_e('Change the logo link', 'atarim-visual-collaboration'); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e('This will replace the &quot;Powered by Atarim&quot; link to your own.', 'atarim-visual-collaboration'); ?>
                                    <?php esc_attr_e('This is great for upselling your clients or making them aware of additional services that you can provide.', 'atarim-visual-collaboration'); ?></div>
                                    <input type="text" name="wpf_powered_link" value="<?php echo esc_html( get_site_data_by_key('wpf_powered_link') ); ?>" class="" />
                                </div>                            
                                <div class="wpf_settings_option wpfeedback_main_color">
                                    <div class="wpf_title"><?php esc_attr_e( 'Change the main color', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Where ever you see the blue, this option will change it to whatever color you want!', 'atarim-visual-collaboration' ); ?></div>
                                    <input type="hidden" name="wpfeedback_color" value="<?php echo get_site_data_by_key( 'wpfeedback_color' ) != '' ? str_replace( '#', '', get_site_data_by_key( 'wpfeedback_color' ) ) : '002157'; ?>" class="jscolor" id="wpfeedback_color"/>
                                    <div class="color-picker"></div>
                                </div>
                                <div class="wpf_settings_option wpfeedback_powered_by">
                                    <div class="wpf_title"><?php esc_attr_e( 'Remove mention of "Atarim" from the plugin', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Tick this setting to remove the name Atarim. Add your own logo and change the logo link above to ensure that the Client Interface Plugin is white labelled entirely.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpfeedback_powered_by" value="yes" id="wpfeedback_powered_by" <?php if ( get_site_data_by_key( 'wpfeedback_powered_by' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpfeedback_reset_setting">
                                    <div class="wpf_title"><?php esc_attr_e( 'Reset White Label Settings', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Click this button to revent the whitelabel options to their original state.', 'atarim-visual-collaboration' ); ?></div>
                                    <input type="button" value="<?php esc_attr_e( 'Reset White Label Settings', 'atarim-visual-collaboration' ); ?>" class="wpf_button" onclick="wpfeedback_reset_setting()"/>
                                </div>
                            </div>
                            <input type="hidden" name="action" value="save_wpfeedback_options"/>
                            <?php wp_nonce_field( 'wpfeedback' ); ?>
                            <div class="wpf_title_section" id="wpf_notifications"><?php esc_attr_e( 'Notifications Settings', 'atarim-visual-collaboration' ); ?></div>                       
                            <div class="wpf_settings_option wpfeedback_more_emails">
                                <div class="wpf_title"><?php esc_attr_e( 'Send email notifications to the following address', 'atarim-visual-collaboration' ); ?></div>
                                <div class="wpf_description"><?php esc_attr_e( 'This option is in addition to the user emails. Seperate with comma for multiple addresses.', 'atarim-visual-collaboration' ); ?></div>
                                <input type="text" name="wpfeedback_more_emails" value="<?php echo get_site_data_by_key( 'wpfeedback_more_emails' ); ?>"/>
                            </div>
                            <div class="wpfeedback_email_notifications">
                                <div class="wpf_title_section" id="wpf_email"><?php esc_attr_e( 'Email Notifications', 'atarim-visual-collaboration' ); ?></div>
                                    <p><?php esc_attr_e( 'Ticking these on will display <b>them as an option on the front-end wizard for users to choose</b>. For example, if you don\'t want users to choose the option to send 24 hour reports, tick that off and it will not display on the front-end wizard.', 'atarim-visual-collaboration' ); ?></p>
                                    <p><?php esc_attr_e( 'If a user <b>does not</b> choose to receive any notifications and you\'d like to change that, go to their user profile in the WordPress Admin and they can be ticked on there, you can view more info on notifications <a href="' . WPF_LEARN_SITE_URL . '/knowledge-base/faq/task-notifications/" target="_blank">here</a>.', 'atarim-visual-collaboration' ); ?></p>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Send email notification for every new task', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpf_every_new_task" value="yes" id="wpf_every_new_task" <?php if ( get_site_data_by_key( 'wpf_every_new_task' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Send email notification for every new comment', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpf_every_new_comment" value="yes" id="wpf_every_new_comment" <?php if ( get_site_data_by_key( 'wpf_every_new_comment' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Send email notification when a task is marked as complete', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpf_every_new_complete" value="yes" id="wpf_every_new_complete" <?php if ( get_site_data_by_key( 'wpf_every_new_complete' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Send email notification for every status change', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpf_every_status_change" value="yes" id="wpf_every_status_change" <?php if ( get_site_data_by_key( 'wpf_every_status_change' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Send email notification for last 24 hours report', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpf_daily_report" value="yes" id="wpf_daily_report" <?php if ( get_site_data_by_key( 'wpf_daily_report' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Send email notification for last 7 days report', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <input type="checkbox" name="wpf_weekly_report" value="yes" id="wpf_weekly_report" <?php if ( get_site_data_by_key( 'wpf_weekly_report' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>                            
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Auto send email notification for daily report', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <!--edited by Pratap-->
                                        <input type="checkbox" name="wpf_auto_daily_report" value="yes" class="auto-report <?php if ( ! is_feature_enabled( 'auto_reports' ) ) { ?> blocked <?php } ?>" id="wpf_auto_daily_report" <?php if ( get_site_data_by_key( 'wpf_auto_daily_report' ) == 'yes' ) { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <div class="wpf_settings_option wpf_checkbox_settings">
                                    <div class="wpf_title"><?php esc_attr_e( 'Auto send email notification for weekly report', 'atarim-visual-collaboration' ); ?></div>
                                    <div class="wpf_description"><?php esc_attr_e( 'Allow users to choose this setting on the front-end wizard and inside their WordPress Profile.', 'atarim-visual-collaboration' ); ?></div>
                                    <label class="wpf_switch">
                                        <!--edited by Pratap-->
                                        <input type="checkbox" name="wpf_auto_weekly_report" value="yes" class="auto-report <?php if ( ! is_feature_enabled( 'auto_reports' ) ) { ?> blocked <?php } ?>" id="wpf_auto_weekly_report" <?php if (get_site_data_by_key('wpf_auto_weekly_report') == 'yes') { echo 'checked'; } ?> />
                                        <span class="wpf_switch_slider wpf_switch_round"></span>
                                    </label>
                                </div>
                                <br>
                            </div>
                            <input type="submit" value="<?php esc_attr_e('Save Changes', 'atarim-visual-collaboration'); ?>" class="wpf_button" id="wpf_save_setting" />
                        </div>
                    </div>
                </div>
            </form>
        </div>
    <?php } ?>
</div>