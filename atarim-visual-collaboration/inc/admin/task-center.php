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
        <button class="wpf_tab_item wpf_tasks active">
            <?php esc_attr_e( 'Tasks', 'atarim-visual-collaboration' ); ?>
        </button>
        <?php if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) { ?>
            <button class="wpf_tab_item wpf_settings" onclick="location.href='admin.php?page=collaboration_page_settings'">
                <?php esc_attr_e( 'Settings', 'atarim-visual-collaboration' ); ?>
            </button>
        <?php }
        if ( $wpf_user_type == 'advisor' || ( $wpf_user_type == '' && current_user_can( 'administrator' ) ) ) { ?>
            <button class="wpf_tab_item wpf_misc" onclick="location.href='admin.php?page=collaboration_page_permissions'">
                <?php esc_attr_e( 'Permissions', 'atarim-visual-collaboration' ); ?>
            </button>
        <?php } ?>
    </div>
    
    <!-- ================= TASKS PAGE ================-->
    <?php
    $wpf_daily_report  = get_site_data_by_key( 'wpf_daily_report' );
    $wpf_weekly_report = get_site_data_by_key( 'wpf_weekly_report' );
    ?>
    <div id="wpf_tasks" class="wpf_container <?php if ( ! is_feature_enabled( 'task_center' ) ) { ?> blocked <?php } ?>">
        <?php
        $wpf_license = get_option( 'wpf_license' );
        if ( $wpf_license != 'valid' ) {
        ?>
		<div id="wpf_tasks_overlay">
            <div class="wpf_welcome_wrap">
                <div class="wpf_welcome_title"><?php esc_attr_e( 'Welcome to Atarim', 'atarim-visual-collaboration' ); ?> </div>
                <div class="wpf_welcome_note"><?php esc_attr_e( "It's good to have you here", 'atarim-visual-collaboration' ); ?> <?php esc_attr_e( $wpf_user_name, 'atarim-visual-collaboration' ); ?>! ❤</div>
                <div class="wpf_welcome_image"><img alt="" src="<?php echo WPF_PLUGIN_URL.'images/WPF-welcome_720.png'; ?>"/></div>
                <div class="wpf_welcome_note"><?php esc_attr_e( 'Please click on the', 'atarim-visual-collaboration' ); ?> <u onclick="location.href='admin.php?page=collaboration_page_permissions'"><?php esc_attr_e( 'Permissions tab', 'atarim-visual-collaboration' ); ?></u> <?php esc_attr_e( 'and verify your license to start using the plugin', 'atarim-visual-collaboration' ); ?></div>
            </div>
        </div>
        <?php } ?>
        <div class="wpf_section_title">
            <?php esc_attr_e( 'Tasks Center', 'atarim-visual-collaboration' ); ?>
            <div class="wpf_report_buttons">
                <span class="wpf_search_box">
                    <i class="gg-search"></i>
                    <input onchange="wp_feedback_filter()" type="text" name="wpf_search_title" class="wpf_search_title" value="" id="wpf_search_title" placeholder="<?php esc_attr_e( 'Search by task title', 'atarim-visual-collaboration' ); ?>">
                </span>
                <span id="wpf_back_report_sent_span" class="wpf_hide text-success"><?php esc_attr_e( 'Your report was sent', 'atarim-visual-collaboration' ); ?></span>
                <span id="wpf_restore_orphan_tasks_span" class="wpf_hide text-success"><?php esc_attr_e( 'All Orphan tasks are restored', 'atarim-visual-collaboration' ); ?></span>
                <span id="wpf_no_orphan_tasks_span" class="wpf_hide text-success"><?php esc_attr_e( 'There are no Orphan tasks', 'atarim-visual-collaboration' ); ?></span>
                <?php
                    if ( $wpf_daily_report == 'yes' ) {
                        ?>
                        <a href="javascript:wpf_send_report('daily_report')">
                            <i class="gg-mail"></i> <?php esc_attr_e( 'Daily Report', 'atarim-visual-collaboration' ); ?>
                        </a>
                        <?php
                    }
                    if ( $wpf_weekly_report == 'yes' ) {
                        ?>
                        <a href="javascript:wpf_send_report('weekly_report')">
                            <i class="gg-mail"></i> <?php esc_attr_e( 'Weekly Report', 'atarim-visual-collaboration' ); ?>
                        </a>
                        <?php
                    }
            	 ?>
                <a href="javascript:wpf_restore_orphan()">
                    <i class="gg-sync"></i> <?php esc_attr_e( 'Restore Orphan Tasks', 'atarim-visual-collaboration' ); ?>
                </a>
			</div>
        </div>
        <div class="wpf_flex_wrap">
            <div class="wpf_filter_col wpf_gen_col">
                <div class="wpf_filter_status wpf_icon_box">
                    <div class="wpf_title"><?php esc_attr_e( 'Filter Tasks', 'atarim-visual-collaboration' ); ?></div>
                    <form method="post" action="#" id="wpf_filter_form">
                        <div class="wpf_task_status_title wpf_icon_title"><i class="gg-screen"></i> <?php esc_attr_e( 'Task Types', 'atarim-visual-collaboration' ); ?></div>
                         <div>
                            <ul class="wp_feedback_filter_checkbox task_type">
                                <li><input onclick="wp_feedback_filter()" type="checkbox" name="task_types_meta" value="general" class="wp_feedback_task_type" id="wpf_task_type_general"><label for="wpf_task_type_general"><?php esc_attr_e( 'General', 'atarim-visual-collaboration' ); ?></label></li>                                
                                <li><input onclick="wp_feedback_filter()" type="checkbox" name="task_types" value="wpf_admin" class="wp_feedback_task_type" id="wpf_task_type_admin"><label for="wpf_task_type_admin"><?php esc_attr_e( 'Admin', 'atarim-visual-collaboration' ); ?></label></li>
                                <li><input onclick="wp_feedback_filter()" type="checkbox" name="task_types" value="publish" class="wp_feedback_task_type" id="wpf_task_type_page"><label for="wpf_task_type_page"><?php esc_attr_e( 'Page', 'atarim-visual-collaboration' ); ?></label></li>
                                <li><input onclick="wp_feedback_filter()" type="checkbox" name="task_types_meta" value="email" class="wp_feedback_task_type" id="wpf_task_type_email"><label for="wpf_task_type_email"><?php esc_attr_e( 'Email', 'atarim-visual-collaboration' ); ?></label></li>
                                <li><input onclick="wp_feedback_filter()" type="checkbox" name="task_types_meta" value="internal" class="wp_feedback_task_type" id="wpf_task_type_internal"><label for="wpf_task_type_internal"><?php esc_attr_e( 'Internal', 'atarim-visual-collaboration' ); ?></label></li>
                            </ul>
                        </div>
                        <div class="wpf_task_status_title wpf_icon_title"><?php echo get_wpf_status_icon(); ?><?php esc_attr_e( 'Task Status', 'atarim-visual-collaboration' ); ?></div>
                        <input type="hidden" name="page" value="collaboration_page_settings">
                        <div><?php echo wp_feedback_get_texonomy( 'task_status' ); ?></div>
                        <div class="wpf_task_priority_title wpf_icon_title"><?php echo get_wpf_priority_icon(); ?><?php esc_attr_e( 'Task Urgency', 'atarim-visual-collaboration' ); ?></div>
                        <div><?php echo wp_feedback_get_texonomy( 'task_priority' ); ?></div>
                        <div class="wpf_user_title wpf_icon_title"><?php echo get_wpf_user_icon();?> <?php esc_attr_e( 'By Users', 'atarim-visual-collaboration' ); ?></div>
                        <div><?php echo do_shortcode('[wpf_user_list]'); ?></div>
                    </form>
                </div>
            </div>
            <div class="wpf_loader_admin hidden"></div>
            <div class="wpf_tasks_col wpf_gen_col">
				<div class="wpf_top_found">
                    <div class="wpf_title" id="wpf_task_tab_title"><?php esc_attr_e( 'Tasks Found', 'atarim-visual-collaboration' ); ?></div>
                    <a href="javascript:wpf_general_comment();" title="Click to give your feedback!" data-placement="left" class="wpf_general_comment_btn" id="wpf_add_general_task"><i class="gg-add"></i> <?php esc_attr_e( 'General', 'atarim-visual-collaboration' ); ?></a>
                    <div class="wpf_display_all_taskmeta_div"></div>
                </div>
                <div class="wpf_tasks_tabs_wrap">
                    <label><input type="checkbox" name="wpf_task_bulk_tab" class="wpf_task_bulk_tab" id="wpf_task_bulk_tab" onclick="wpf_tasks_tabs('bulk')" /><?php esc_attr_e( 'Bulk Action', 'atarim-visual-collaboration' ); ?></label>
                </div>
                <div id="wpf_bulk_select_task_checkbox" style="display: none;">
                    <label><input type="checkbox" name="wpf_select_all_task" id="wpf_select_all_task" class="wpf_select_all_task"><?php esc_attr_e( 'Edit All', 'atarim-visual-collaboration' ); ?></label>
                </div>
                <?php
                $tasks = wpfeedback_get_post_list();
                ?>
                <div class="wpf_tasks-list">
                    <?php echo $tasks[0]; ?>
                    <div class="wpf_loading">Loading...</div>
                </div>
            </div>
            <div class="wpf_chat_col wpf_gen_col" id="wpf_task_details">
                <div class="wpf_chat_top">
                    <div class="wpf_task_num_top"></div>
                    <div class="wpf_task_main_top">
                        <div class="wpf_task_title_top"></div>
                        <a href="javascript:void(0)" onclick="wpf_edit_title()" id="wpf_edit_title"><i class="gg-pen"></i></a>
                        <div id="wpf_edit_title_box" class="wpf_hide">
                            <input type="text" name="wpf_edit_title" value="" id="wpf_title_val" > 
                            <button id="wpf_title_update_btn" onclick="wpf_update_title()" class="submit wpf_button submit"><i class="gg-check"></i></button>
                        </div>
                        <div class="wpf_task_details_top"></div>
                    </div>
					<a href="#" id="wpfb_attr_task_page_link" target="_blank" class="wpf_button"><i class="gg-external"></i>
                        <input type="button" name="wp_feedback_task_page" class="wpf_button_inner" value="<?php esc_attr_e( 'Open Task Page', 'atarim-visual-collaboration' ); ?>"></a>
                </div>				
                <?php 
                if ( $tasks == '<div class="wpf_no_tasks_found"><i class="gg-info"></i> No tasks found</div>' ) { 
                    ?>
                    <div class="wpf_chat_box" id="wpf_message_content">
                        <p class="wpf_no_task_message"><b><?php esc_attr_e( 'No Tasks found.', 'atarim-visual-collaboration' ); ?></b><br/>
                    </div>
                    <?php 
                } else {
                    ?>
                    <div class="wpf_chat_box" id="wpf_message_content">
                        <ul id="wpf_message_list"></ul>
                    </div>
                <?php } ?>
                <div class="wpf_chat_reply" id="wpf_message_form"></div>
            </div>
            <div class="wpf_attributes_col wpf_gen_col" id="wpf_attributes_content">
                <div class="wpf_task_attr wpf_task_title">
					<div class="wpf_title"><?php esc_attr_e( 'Task Attributes', 'atarim-visual-collaboration' ); ?></div>
                    <div class="wpf_icon_title at_fill_color at_att_screenshot"><?php echo get_wpf_screenshot_icon(); ?><?php esc_attr_e( 'Auto Screenshot', 'atarim-visual-collaboration' ); ?></div>
					<a href="#" id="wpf_task_screenshot_link" target="_blank">
                        <img src="" id="wpf_task_screenshot" alt="task screenshot">
                    </a>
				</div>
                <div class="wpf_task_attr wpf_task_page">
                <?php
                if ( $wpf_tab_permission_information == 'yes' ) {
                    ?>
                    <div class="wpf_icon_title at_fill_color at_att_info"><?php echo get_wpf_info_icon(); ?><?php esc_attr_e( 'Additional Information', 'atarim-visual-collaboration' ); ?></div>
                    <div id="additional_information"></div>
                <?php } ?>
                </div>
                <div class="wpf_task_attr">
                    <?php
                    if ( $wpf_user_type == 'advisor' ) {
                        ?>
                        <div class="wpf_task_tags">
                            <div class="wpf_icon_title at_fill_color at_att_tags"><i class="gg-tag"></i> <?php esc_attr_e( 'Custom Tags', 'atarim-visual-collaboration' ); ?></div>
                            <div class="wpf_tag_autocomplete">
                                <input type="text" name="wpfeedback_tags" class="wpf_tag" value="" id="wpf_tags" onkeydown="wpf_search_tags(this)" >
                                <button class="wpf_tag_submit_btn" onclick="wpf_add_tag_admin(this)"><i class="gg-corner-down-left"></i></button>
                            </div>
    						<div id="all_tag_list"></div>
                        </div>
                    <?php } ?>
                    <?php
                    if ( $wpf_tab_permission_status == 'yes' ) {
                        ?>
                        <div class="wpf_task_status at_fill_color at_att_status">
                            <div class="wpf_icon_title"><?php echo get_wpf_status_icon(); ?> <?php esc_attr_e( 'Task Status', 'atarim-visual-collaboration' ); ?></div>
                            <?php echo wp_feedback_get_texonomy_selectbox( 'task_status' ); ?>
                        </div>
                    <?php } ?>
                    <?php
                    if ( $wpf_tab_permission_priority == 'yes' ) {
                        ?>
                        <div class="wpf_task_urgency at_fill_color at_att_priority">
                            <div class="wpf_icon_title"><?php echo get_wpf_priority_icon(); ?> <?php esc_attr_e( 'Task Urgency', 'atarim-visual-collaboration' ); ?></div>
                            <?php echo wp_feedback_get_texonomy_selectbox( 'task_priority' ); ?>
                        </div>
                    <?php } ?>
                    <?php
                    if ( $wpf_tab_permission_delete_task == 'yes' ) {
                        ?>
                        <div class="wpf_task_attr wpf_task_title" id="wpf_delete_task_container"></div>
                        <?php
                    } else {
                        ?>
                        <div class="wpf_task_attr wpf_task_title" id="wpf_delete_task_container"></div>
                    <?php } ?>
                </div>
                <div class="wpf_task_attr wpf_task_users at_att_users">
                    <?php
                    if ( $wpf_tab_permission_user == 'yes' ) {
                        ?>
                        <div class="wpf_icon_title"><?php echo get_wpf_user_icon();?> <?php esc_attr_e( 'Notify Users', 'atarim-visual-collaboration' ); ?></div>
                        <div class="wpf_att_users"><?php echo do_shortcode('[wpf_user_list_task]'); ?></div>
                    <?php } ?>
                </div>
            </div>
            <div class="wpf_bulk_update_col wpf_gen_col" id="wpf_bulk_update_content" style="display: none;">
                <div class="wpf_task_options">
                    <div class="wpf_task_status">
                        <div class="wpf_icon_title"><?php echo get_wpf_status_icon(); ?> <?php esc_attr_e( 'Task Status', 'atarim-visual-collaboration' ); ?></div>
                        <?php echo wpf_bulk_update_get_texonomy_selectbox( 'task_status' ); ?>
                    </div>
                    <div class="wpf_task_urgency">
                        <div class="wpf_icon_title"><?php echo get_wpf_priority_icon();?> <?php esc_attr_e( 'Task Urgency', 'atarim-visual-collaboration' ); ?></div>
                        <?php echo wpf_bulk_update_get_texonomy_selectbox( 'task_priority' ); ?>
                    </div>
                    <div class="wpf_task_attr wpf_task_title" id="wpf_bulk_delete_task_container">
                        <a href="javascript:void(0)" class="wpf_bulk_task_delete_btn">
                            <i class="gg-trash"></i> <?php esc_attr_e( 'Delete ticket','atarim-visual-collaboration' ); ?>
                        </a>
                        <p class="wpf_hide" id="wpf_bulk_task_delete"><?php esc_attr_e( 'Are you sure you want to delete?', 'atarim-visual-collaboration' ); ?> <a href="javascript:void(0);" class="wpf_bulk_task_delete">Yes</a></p>
                    </div>
                    <input type="button" value="<?php esc_attr_e( 'Save Bulk Changes', 'atarim-visual-collaboration' ); ?>" class="wpf_button" onclick="wpf_bulk_update()">
                </div>
            </div>
        </div>
    </div>
</div>
<?php
echo "<script>var wpf_orphan_tasks=" . wp_json_encode( $tasks[1] ) . ";</script>";
