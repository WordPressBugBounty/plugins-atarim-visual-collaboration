/* global tippy */
(function ($) {

})(jQuery);

jQuery_WPF(document).ready(function () {

    //Password strenth check on plugin activation by Pratap
    jQuery_WPF('.wpf-user-pass').on('focus', function() {
        jQuery('.wpf-pass-error').removeClass('wpf-pass-error-hide');
    });

    jQuery_WPF('.wpf-user-pass').on('focusout', function() {
        if(jQuery_WPF(this).val().length == 0) {
            jQuery('.wpf-pass-error').addClass('wpf-pass-error-hide');
        }
    });

    var strength = 0;
    jQuery_WPF('.wpf-user-pass').on('keyup', function() {
        strength = 0;
        var pass = jQuery_WPF(this).val();
        if(pass.length >= 8) {
            strength += 1;
            jQuery_WPF('.wpf-pass-leng').addClass('remove-marker');
        } else {
            jQuery_WPF('.wpf-pass-leng').removeClass('remove-marker');
        }
        if(hasnumupperlower(pass)) {
            strength += 1;
            jQuery_WPF('.wpf-pass-capnum').addClass('remove-marker');
        } else {
            jQuery_WPF('.wpf-pass-capnum').removeClass('remove-marker');
        }
        if(hasapecialchar(pass)) {
            strength += 1;
            jQuery_WPF('.wpf-pass-special').addClass('remove-marker');
        } else {
            jQuery_WPF('.wpf-pass-special').removeClass('remove-marker');
        }
    });

    function hasnumupperlower(str) { 
        let pattern = new RegExp( 
            "^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d).+$"
        ); 
        if (pattern.test(str)) {
            return true;
        } else {
            return false;
        }
    }
    
    function hasapecialchar(str) { 
        let pattern = new RegExp( 
            "^(?=.*[!”£$%^&*()?<>?@|{}~,.¬`]).+$"
        ); 
        if (pattern.test(str)) {
            return true;
        } else {
            return false;
        }
    }

    //Create user on plugin activation by Pratap
    jQuery_WPF('.wpf_create_user').on('click', function() {
        jQuery_WPF('.wpf-account-msg').text('');
        var wpf_name = jQuery_WPF('.wpf-user-name').val();
        var wpf_email = jQuery_WPF('.wpf-user-email').val();
        var wpf_pass = jQuery_WPF('.wpf-user-pass').val();
        if(wpf_name == ''){
            jQuery_WPF('.wpf-user-name').prev().show();
            return false;
        } else {
            jQuery_WPF('.wpf-user-name').prev().hide();
        }
        if(wpf_email == ''){
            jQuery_WPF('.wpf-user-email').prev().show();
            return false;
        } else {
            jQuery_WPF('.wpf-user-email').prev().hide();
        }
        if(IsEmail(wpf_email) == 'false'){
            jQuery_WPF('wpf-user-email').prev().show();
            return false;
        } else {
            jQuery_WPF('.wpf-user-email').prev().hide();
        }
        if(wpf_pass == ''){
            jQuery_WPF('.wpf-user-pass').prev().show();
            return false;
        } else {
            jQuery_WPF('.wpf-user-pass').prev().hide();
        }
        if(strength < 3) {
            return false;
        }
        jQuery_WPF.ajax({
            url:ajaxurl,
            method: 'POST',
            data:{ 
                action: 'wpf_create_account',
                wpf_nonce: wpf_nonce,
                wpf_name: wpf_name,
                wpf_email: wpf_email,
                wpf_pass: wpf_pass
            },
            beforeSend: function(){
                jQuery_WPF('.wpf_loader_admin').show();
            },
            success: function (result) {
                jQuery_WPF('.wpf_loader_admin').hide();
                var response = JSON.parse(result);
                response = response.result;
                if( response.hasOwnProperty( 'user_id' ) ) {
                    window.location.href = logged_user.site_url;
                } else if( response.hasOwnProperty( 'error' ) ) {
                    jQuery_WPF('.wpf-account-msg').text(response.error);
                } else if( response == 'failed' ) {
                    jQuery_WPF('.wpf-account-msg').html('Sorry! something went wrong.');
                }
            }
        });
    });
    //validate email format by Pratap
    function IsEmail(email) {
        var regex = /^([a-zA-Z0-9_\.\-\+])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/;
        if(!regex.test(email)) {
          return 'false';
        }else{
          return 'true';
        }
    }


    jQuery_WPF("ul#all_wpf_list li.wpf_list").first().find('a').trigger('click');
    jQuery_WPF("ul#all_wpf_list li.wpf_list").first().addClass('active');
    jQuery_WPF('ul#all_wpf_list li').on('click', function(event) {
        if (event.target === this) {
            event.stopImmediatePropagation();
            jQuery_WPF('a', this).trigger('click');
        }
    });

    jQuery_WPF(document).find("#wpf_delete_task_container").on("click",".wpf_task_delete",function(e){
        var elemid = jQuery_WPF(this).data('elemid');
        var task_id = jQuery_WPF(this).data('taskid');
        wpf_admin_delete_task(elemid,task_id);
    });

    jQuery_WPF(document).find("#wpf_attributes_content").on("click",".wpf_task_delete_btn",function(e) {
        jQuery_WPF('#wpf_task_delete').show();
    });

    //close upgrade notice popup by Pratap
    jQuery_WPF(document).on('click', '.wpf-uf-pop-wrapper, .wpf-uf-pop-container, .wpf-uf-close-popup, .wpf-uf-close-popup .gg-close', function(e){
        if(e.target == this){
            jQuery_WPF('.wpf-uf-pop-wrapper').hide();
            jQuery_WPF('.wpf-uf-popup-image img').attr('src', '');
            jQuery_WPF('.wpf-uf-plan-title').text('');
            jQuery_WPF('.wpf-uf-plan-detail').html('');
            jQuery_WPF('.wpf-uf-plan-link').attr('href', '#');
        }
    });

    //close upgrade subscription notice popup by Pratap
    jQuery_WPF(document).on('click', '.wpf-le-pop-wrapper, .wpf-le-pop-container, .wpf-le-close-popup, .wpf-le-close-popup .gg-close', function(e){
        if(e.target == this){
            jQuery_WPF('.wpf-le-pop-wrapper').hide();
        }
    });

    //remove onclick if section is blocked by Pratap
    jQuery_WPF('.blocked a, .blocked input').removeAttr("onclick");

    var upg_url = upgrade_url.url;
    var plugin_url = upgrade_url.plugin_url;

    //show upgrade notice popup on task page by Pratap
    jQuery_WPF('#wpf_tasks div, #wpf_tasks button, #wpf_tasks input, #wpf_tasks a, #wpf_tasks select').on('click change keyup keydown keypress', function(e){
        if (jQuery_WPF(this).parents('.blocked').length) {
            jQuery_WPF('.wpf-uf-popup-image img').attr('src', plugin_url + '/images/task-center.png');
            jQuery_WPF('.wpf-uf-plan-title').text('WP Admin Task Center');
            jQuery_WPF('.wpf-uf-plan-detail').html('Use the internal task centre to easily manage tasks from this website by unlocking filtering, bulk actions and access to task comment feeds.');
            jQuery_WPF('.wpf-uf-plan-link').attr('href', upg_url + '?&feature=taskcenter');
            jQuery_WPF('.wpf-uf-pop-wrapper').show();
            e.preventDefault();
            return false;
        }
    });

    //show upgrade notice popup on blocked white label setting by Pratap
    jQuery_WPF('.wpf-whitelabel-parent.blocked input, .wpf-whitelabel-parent.blocked textarea, .wpf-whitelabel-parent.blocked button').on('click change keyup keydown keypress', function(){
        jQuery_WPF('.wpf-uf-popup-image img').attr('src', plugin_url + '/images/white-labelling.png');
        jQuery_WPF('.wpf-uf-plan-title').text('White Labelling');
        jQuery_WPF('.wpf-uf-plan-detail').html('Completely customise the look of Atarim on this website with white label settings. Change the logo, icon and main color to give your clients’ a unique experience.');
        jQuery_WPF('.wpf-uf-plan-link').attr('href', upg_url + '?&feature=whitelabel');
        jQuery_WPF('.wpf-uf-pop-wrapper').show();
        if (this.checked == false) {
            jQuery_WPF(this).prop('checked', true);
        } else {
            jQuery_WPF(this).prop('checked', false);
        }
        return false;
    });

    //show upgrade notice popup on auto report setting by Pratap
    jQuery_WPF('.auto-report.blocked').change(function(){
        jQuery_WPF('.wpf-uf-popup-image img').attr('src', plugin_url + '/images/auto-report.png');
        jQuery_WPF('.wpf-uf-plan-title').text('Auto Reports');
        jQuery_WPF('.wpf-uf-plan-detail').html('Automatically send 24 hour and 7 day reports to your clients with the click of a button, that summarise all activity on tasks, keeping your clients up to date on the work that’s been done.');
        jQuery_WPF('.wpf-uf-plan-link').attr('href', upg_url + '?&feature=reports');
        jQuery_WPF('.wpf-uf-pop-wrapper').show();
        if (this.checked == false) {
            jQuery_WPF(this).prop('checked', true);
        } else {
            jQuery_WPF(this).prop('checked', false);
        }
    });

    //show upgrade notice popup on blocked user permission by Pratap
    jQuery_WPF('.blocked .wpf_perm_table input[type=checkbox]').change(function(){
        jQuery_WPF('.wpf-uf-popup-image img').attr('src', plugin_url + '/images/user-permissions.png');
        jQuery_WPF('.wpf-uf-plan-title').text('User Permissions');
        jQuery_WPF('.wpf-uf-plan-detail').html('Take full control over what users can do with Atarim user permissions and fully customise experience based on user role.');
        jQuery_WPF('.wpf-uf-plan-link').attr('href', upg_url + '?&feature=permissions');
        jQuery_WPF('.wpf-uf-pop-wrapper').show();
        if (this.checked == false) {
            jQuery_WPF(this).prop('checked', true);
        } else {
            jQuery_WPF(this).prop('checked', false);
        }
    });

    jQuery_WPF('.wpf_global_settings').change(function () {
        //show upgrade notice popup on blocked global settings by Pratap
        if(jQuery_WPF(this).hasClass('blocked')) {
            jQuery_WPF('.wpf-uf-popup-image img').attr('src', plugin_url + '/images/global-settings.png');
            jQuery_WPF('.wpf-uf-plan-title').text('Global Settings');
            jQuery_WPF('.wpf-uf-plan-detail').html('Save time and make it easy to set up this website by applying settings from your Atarim Dashboard with one click, including white labelling, notification settings and permissions.');
            jQuery_WPF('.wpf-uf-plan-link').attr('href', upg_url + '?&feature=global');
            jQuery_WPF('.wpf-uf-pop-wrapper').show();
            if(this.checked) {
                jQuery(this).prop('checked', false);
            } else {
                jQuery(this).prop('checked', true);
            }
            
        } else {
            var checked = this.checked;
            var wpf_global_settings = 'no';
            if(this.checked){
                wpf_global_settings = 'yes';
            }
            jQuery_WPF.ajax({
                url:ajaxurl,
                method: 'POST',
                data:{action: 'wpf_global_settings',wpf_nonce:wpf_nonce,wpf_global_settings:wpf_global_settings},
                beforeSend: function(){
                    jQuery_WPF('.wpf_loader_admin').show();
                },
                success: function (data) {
                    jQuery_WPF('.wpf_loader_admin').hide();
                    if(data==1){
                        jQuery_WPF('#wpf_global_settings_overlay').show();
                        jQuery_WPF('#wpf_global_settings_overlay .wpf_global_settings').attr("checked", "checked");
                        jQuery_WPF('#wpfeedback_enable_global .wpf_global_settings').attr("checked", "checked");
                        jQuery_WPF('wpf_global_erro_msg').hide();
                    }else if(data==3){
                        jQuery_WPF('#wpf_global_erro_msg').show();
                        jQuery_WPF('.wpf_global_settings').removeAttr('checked');
                        jQuery_WPF('.wpfeedback_enable_global .wpf_global_settings').removeAttr('checked');
                    }
                    else{
                        jQuery_WPF('wpf_global_erro_msg').hide();
                        jQuery_WPF('#wpf_global_settings_overlay').hide();
                        jQuery_WPF('.wpf_global_settings').removeAttr('checked');
                        jQuery_WPF('.wpfeedback_enable_global .wpf_global_settings').removeAttr('checked');
                    }
                }
            });
        }
    });

    // Remove admin notice of Feedback tool.
    jQuery_WPF('.wpf-gftool-notice .notice-dismiss').on('click', function() {
        jQuery_WPF.ajax({
            url:ajaxurl,
            method: 'POST',
            data:{
                action: 'remove_feedbacktool_notice',
                wpf_nonce: wpf_nonce
            },
            success: function () {
            }
        });
    });
});

function readURL(input) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    
    reader.onload = function(e) {
        if(input.id == 'wpf_icon_file'){
            jQuery_WPF('#wpfeedback_icon-preview').attr('src', e.target.result);
        }else{
            jQuery_WPF('#wpfeedback_image-preview').attr('src', e.target.result);
            if((input.id == 'upload_graphic_image') || (input.id == 'upload_graphic_image_version')){
                // jQuery_WPF('#wpf_field_label').html(input.target.files[0].name);
                jQuery_WPF('#wpf_preview_graphic').fadeIn(1500);
                jQuery_WPF('#wpf_preview_graphic').css({'text-align':'center','display':'block'});
                jQuery_WPF('#wpfeedback_image-preview').css('width','150px');
            }
        }
    }
    
    reader.readAsDataURL(input.files[0]); // convert to base64 string
  }
}

jQuery_WPF("#wpf_logo_file, #upload_graphic_image, #upload_graphic_image_version, #wpf_icon_file").change(function() {
    var val = jQuery_WPF(this).val().toLowerCase(),
    regex = new RegExp("(.*?)\.(jpeg|jpg|png)$");

    if (!(regex.test(val))) {
	jQuery_WPF(this).val('');
        // alert('Please select correct file format');
        alert('Unable to upload this image at the moment, please try again');
    } else {
	    readURL(this);
    }
});

/*Reset Setting*/
function wpfeedback_reset_setting() {
    jQuery_WPF.ajax({
        method: "POST",
        url: ajaxurl,
        data: {action: "wpfeedback_reset_setting",wpf_nonce:wpf_nonce},
        success: function (data) {
            if (data == 1) {
                location.reload();
            }
        }
    });
}

function wpf_admin_delete_task(id,task_id){
    var task_info = [];
    task_info['task_id'] = task_id;
    task_info['task_no']=id;
    var task_info_obj = jQuery_WPF.extend({}, task_info);
    var wpf_task_num_top= jQuery_WPF('wpf_task_details .wpf_task_num_top').text();
    jQuery_WPF.ajax({
        method : "POST",
        url : ajaxurl,
        data : {action: "wpfb_delete_task",wpf_nonce:wpf_nonce,task_info:task_info_obj},
        beforeSend: function(){
            jQuery_WPF('.wpf_loader_admin').show();
        },
        success : function(data){
            if(id == wpf_task_num_top){
                jQuery_WPF("#wpf_task_details #wpf_message_content,#wpf_task_details #wpf_message_form,#wpf_task_details .wpf_chat_top").hide();
            }
            jQuery_WPF('li.post_'+task_id).remove();
            jQuery_WPF('.wpf_loader_admin').hide();
            if(jQuery_WPF("ul#all_wpf_list li.wpf_list a:first-child").first().length==0 && id != ''){
                location.reload();
            }
            else{
                jQuery_WPF("ul#all_wpf_list li.wpf_list a:first-child").first().trigger('click');
                jQuery_WPF("ul#all_wpf_list li.wpf_list a").first().parent().addClass('active');
            }
        }
    });
}

function wpf_send_report(type) {
    jQuery_WPF.ajax({
        method: "POST",
        url: ajaxurl,
        data: {action: "wpf_send_email_report",wpf_nonce:wpf_nonce, type:type, forced: "yes"},
        beforeSend: function(){
            jQuery_WPF('.wpf_loader_admin').show();
        },
        success: function (data) {
            jQuery_WPF('.wpf_loader_admin').hide();
            jQuery_WPF('#wpf_back_report_sent_span').show();
            setTimeout(function() {
                jQuery_WPF('#wpf_back_report_sent_span').hide();
            }, 3000);
        }
    });
}

function wpf_restore_orphan() {
    jQuery_WPF.ajax({
        method: "POST",
        url: ajaxurl,
        data: {action: "wpf_set_task_element",wpf_nonce:wpf_nonce, wpf_task_ids:wpf_orphan_tasks},
        beforeSend: function(){
            jQuery_WPF('.wpf_loader_admin').show();
        },
        success: function (data) {
            jQuery_WPF('.wpf_loader_admin').hide();
                if(data==1){
                    wpf_orphan_tasks="";
                    jQuery_WPF('#wpf_restore_orphan_tasks_span').show();
                    setTimeout(function() {
                        jQuery_WPF('#wpf_restore_orphan_tasks_span').hide();
                    }, 3000);
                    jQuery_WPF.ajax({
                        method: "POST",
                        url: ajaxurl,
                        data: {
                            action: "wpfeedback_get_post_list_ajax",
                            wpf_nonce:wpf_nonce
                        },
                        success: function (data) {
                            jQuery_WPF('#all_wpf_list').html(data);
                        }
                    });
                }
                else{
                    jQuery_WPF('#wpf_no_orphan_tasks_span').show();
                    setTimeout(function() {
                        jQuery_WPF('#wpf_no_orphan_tasks_span').hide();
                    }, 3000);
                }
        }
    });
}
var image_open_icon = '<svg height="18px" version="1.1" viewBox="0 0 100 100" width="18px" xmlns="http://www.w3.org/2000/svg" xmlns:sketch="http://www.bohemiancoding.com/sketch/ns" xmlns:xlink="http://www.w3.org/1999/xlink"><title/><desc/><defs/><g fill="none" fill-rule="evenodd" id="MiMedia---Web" stroke="none" stroke-width="1"><g fill="#fff" id="icon24pt_new_window" transform="translate(2.000000, 2.000000)"><path d="M73.7883228,16 L44.56401,45.2243128 C42.8484762,46.9398466 42.8459918,49.728257 44.5642987,51.4465639 C46.2791092,53.1613744 49.0684023,53.1650001 50.7865498,51.4468526 L80,22.2334024 L80,32.0031611 C80,34.2058797 81.790861,36 84,36 C86.2046438,36 88,34.2105543 88,32.0031611 L88,11.9968389 C88,10.8960049 87.5527117,9.89722307 86.8294627,9.17343595 C86.1051125,8.44841019 85.1063303,8 84.0031611,8 L63.9968389,8 C61.7941203,8 60,9.790861 60,12 C60,14.2046438 61.7894457,16 63.9968389,16 L73.7883228,16 L73.7883228,16 Z M88,56 L88,36.9851507 L88,78.0296986 C88,83.536144 84.0327876,88 79.1329365,88 L16.8670635,88 C11.9699196,88 8,83.5274312 8,78.0296986 L8,17.9703014 C8,12.463856 11.9672124,8 16.8670635,8 L59.5664682,8 L40,8 C42.209139,8 44,9.790861 44,12 C44,14.209139 42.209139,16 40,16 L18.2777939,16 C17.0052872,16 16,17.1947367 16,18.668519 L16,77.331481 C16,78.7786636 17.0198031,80 18.2777939,80 L77.7222061,80 C78.9947128,80 80,78.8052633 80,77.331481 L80,56 C80,53.790861 81.790861,52 84,52 C86.209139,52 88,53.790861 88,56 L88,56 Z" id="Rectangle-2064"/></g></g></svg>';
var push_to_media_icon='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="32px" height="31px" viewBox="0 0 32 31" version="1.1">';
push_to_media_icon += '<g id="surface1"><path style=" stroke:none;fill-rule:nonzero;fill:#ffffff;fill-opacity:1;" d="M 16.234375 16.746094 L 13.558594 24.3125 L 13.550781 24.3125 L 11.476562 30.09375 C 11.621094 30.132812 11.761719 30.164062 11.910156 30.203125 C 11.917969 30.203125 11.925781 30.203125 11.933594 30.203125 C 13.222656 30.535156 14.578125 30.71875 15.972656 30.71875 C 16.667969 30.71875 17.34375 30.679688 18.007812 30.574219 C 18.921875 30.464844 19.800781 30.277344 20.660156 30.015625 C 20.871094 29.953125 21.082031 29.878906 21.296875 29.808594 C 21.066406 29.335938 20.578125 28.28125 20.554688 28.234375 Z M 16.234375 16.746094 "/>';
push_to_media_icon += '<path style=" stroke:none;fill-rule:nonzero;fill:#ffffff;fill-opacity:1;" d="M 1.6875 9.5625 C 0.871094 11.351562 0.316406 13.550781 0.316406 15.535156 C 0.316406 16.03125 0.339844 16.53125 0.390625 17.019531 C 0.953125 22.652344 4.707031 27.382812 9.867188 29.507812 C 10.078125 29.59375 10.300781 29.683594 10.519531 29.761719 L 2.929688 9.570312 C 2.277344 9.546875 2.152344 9.585938 1.6875 9.5625 Z M 1.6875 9.5625 "/>';
push_to_media_icon += '<path style=" stroke:none;fill-rule:nonzero;fill:#ffffff;fill-opacity:1;" d="M 30.210938 9.160156 C 29.859375 8.425781 29.441406 7.722656 28.976562 7.058594 C 28.847656 6.867188 28.699219 6.675781 28.5625 6.488281 C 26.804688 4.210938 24.414062 2.421875 21.628906 1.378906 C 19.882812 0.714844 17.972656 0.351562 15.980469 0.351562 C 11.058594 0.351562 6.660156 2.566406 3.785156 6.019531 C 3.253906 6.652344 2.78125 7.332031 2.355469 8.046875 C 3.515625 8.054688 4.953125 8.054688 5.117188 8.054688 C 6.59375 8.054688 8.871094 7.878906 8.871094 7.878906 C 9.640625 7.832031 9.71875 8.914062 8.960938 9.003906 C 8.960938 9.003906 8.195312 9.089844 7.34375 9.128906 L 12.480469 23.917969 L 15.566406 14.957031 L 13.378906 9.136719 C 12.609375 9.097656 11.898438 9.011719 11.898438 9.011719 C 11.132812 8.972656 11.230469 7.839844 11.980469 7.886719 C 11.980469 7.886719 14.308594 8.0625 15.695312 8.0625 C 17.171875 8.0625 19.453125 7.886719 19.453125 7.886719 C 20.210938 7.839844 20.308594 8.921875 19.539062 9.011719 C 19.539062 9.011719 18.78125 9.097656 17.933594 9.136719 L 23.019531 23.816406 L 24.429688 19.257812 C 25.140625 17.488281 25.5 16.023438 25.5 14.855469 C 25.5 13.171875 24.871094 12 24.332031 11.089844 C 23.621094 9.960938 22.953125 9.011719 22.953125 7.894531 C 22.953125 6.636719 23.933594 5.46875 25.320312 5.46875 C 25.378906 5.46875 25.441406 5.46875 25.5 5.46875 C 27.640625 5.414062 28.339844 7.46875 28.429688 8.867188 C 28.429688 8.867188 28.429688 8.898438 28.429688 8.914062 C 28.464844 9.484375 28.4375 9.902344 28.4375 10.402344 C 28.4375 11.777344 28.167969 13.335938 27.371094 15.289062 L 24.1875 24.210938 L 22.367188 29.40625 C 22.511719 29.34375 22.652344 29.277344 22.796875 29.207031 C 27.425781 27.042969 30.796875 22.722656 31.507812 17.605469 C 31.613281 16.933594 31.664062 16.246094 31.664062 15.550781 C 31.664062 13.265625 31.140625 11.097656 30.210938 9.160156 Z M 30.210938 9.160156 "/>';
push_to_media_icon += '</g></svg>';
img_dwn_icon  = "<span id='wpf_push_media' class='wpf_push_media wpf_image_download'>"+push_to_media_icon+"</span><span id='wpf_image_open' class='wpf_image_open' onclick='wpf_image_open_new_tab(this)'>"+image_open_icon+"</span>";

function wpf_upload_file_admin(wpf_taskid){
    var elemid = jQuery_WPF(this).attr('data-elemid'), task_info=[];
    var wpf_file = jQuery_WPF('#wpf_uploadfile')[0].files[0];
    var wpf_comment = '';
    var wpf_upload_form = new FormData();
    wpf_upload_form.append('action', 'wpf_upload_file');
    wpf_upload_form.append("wpf_nonce", wpf_nonce);
    wpf_upload_form.append("wpf_taskid", wpf_taskid);
    wpf_upload_form.append("wpf_upload_file", wpf_file);
    wpf_upload_form.append('task_config_author_name', current_user_name);
    if(wpf_file){
        jQuery_WPF.ajax({
            type: 'POST',
            url: ajaxurl,
            data: wpf_upload_form,
            contentType: false,
            processData: false,
            beforeSend: function(){
                jQuery_WPF('.wpf_loader_admin').show();
            },
            success: function (data) {
                var response = JSON.parse(data);
                jQuery_WPF('.wpf_loader_admin').hide();

                if(response.status==1){
                    jQuery_WPF("input[name=wpf_uploadfile]").val('');
                    var comment_html = '';

                    var author = response.author;
                    author_html = '';
                    if ( logged_user.author_img == '' || logged_user.author_img == 'undefined') {
                        author_html = author.slice(0, 2);
                    } else {
                        author_html = '<img src="' + logged_user.author_img + '" alt="author" ></img>';
                    }

                    if(response.type==1){
                        comment_html = '<li class="chat_author" id=""><div class="wpf-comment-container"><div class="wpf-author-img">' + author_html + '</div><div class="wpf-comment-wrapper"><level class="wpf-author"><div>' + author + '</div><span>now</span></level><div class="task_text"><a href="'+response.message+'" target="_blank"></a><div class="tag_img" style="width: 275px;height: 183px;"><div class="meassage_area_main"><a href="'+response.message+'" target="_blank"></a><img style="width: 100%;" class="wpfb_task_screenshot" src="'+response.message+'" alt="screenshot" />'+img_dwn_icon+'</div></div></div></div></div></li>';
                    }
                    else{
                        var wpf_download_file  = response.message.split("/").pop();
                        var link = response.message;
                        var parts = link.split('">');
                        var response_message = '<a href="' + parts[0] + '" target="_blank" download><i class="gg-software-download"></i>' + wpf_download_file + "</a>";
                        comment_html = '<li class="chat_author"><div class="wpf-comment-container"><div class="wpf-author-img">' + author_html + '</div><div class="wpf-comment-wrapper"><level class="wpf-author"><div>' + author + '</div><span>now</span></level><div class="task_text">'+response_message+'</div></div></div></li>';
                    }
                    jQuery_WPF('ul#wpf_message_list').append(comment_html);
                    jQuery_WPF('#wpf_message_content').animate({scrollTop: jQuery_WPF('#wpf_message_content').prop("scrollHeight")}, 2000);
                }
                else{
                    jQuery_WPF('#wpf_upload_error').show();
                    setTimeout(function() {
                        jQuery_WPF('#wpf_upload_error').hide();
                    }, 5000);
                }
            }
        });
    }
}

function wpf_general_comment(){
    wpf_open_tab('wpf_message_content');
    jQuery_WPF("#wpf_edit_title").hide();
    jQuery_WPF("#wpf_task_tabs_container").hide();
    chat_form = get_wpf_message_form();
    jQuery_WPF('#wpf_message_form').html(chat_form);
    var wpf_all_info_array = JSON.parse(wpf_all_pages);
    var wpfb_all_pages_html = '<select class="wpf_pages_list" id="wpf_pages_list">';
    wpfb_all_pages_html+='<option value="">'+wpf_general_task_option+'</option>';

    for (var key in wpf_all_info_array){

        if (wpf_all_info_array.hasOwnProperty(key)) {
            wpfb_all_pages_html+='<optgroup label="'+key+'">';
            for (var key_1 in wpf_all_info_array[key]){
                if (wpf_all_info_array[key].hasOwnProperty(key_1)) {
                    wpfb_all_pages_html+='<option value="'+key_1+'">'+ wpf_all_info_array[key][key_1] + '</option>';
                }
            }
            wpfb_all_pages_html+='</optgroup>';
        }

    }
    wpfb_all_pages_html+='</select>';

    var curr_browser = get_browser();
    html_element_width = window.screen.width;
    html_element_height = window.screen.height;

    var additional_info_html = '<p><span class="wpf_task_ad_info_title">Resolution:</span> ' + html_element_width + ' X ' +html_element_height+'</p><p><span class="wpf_task_ad_info_title">Browser:</span> ' + curr_browser['name']+' '+curr_browser['version'] + '</p><p><span class="wpf_task_ad_info_title">User Name:</span> ' + current_user_name + '</p>';
    jQuery_WPF("div#wpf_attributes_content #additional_information").html(additional_info_html);

    jQuery_WPF("#wpf_task_details #send_chat").attr("onclick","wpf_generate_front_task()");
    jQuery_WPF("#wpf_task_details .wpf_mark_note").attr("onclick","wpf_generate_front_task(0, true)");
    jQuery_WPF("#wpf_form > .chat_button > .wpf_mark_internal_task_center").removeClass('wpf_is_internal').attr("onclick","wpf_generate_front_task(1)");
    jQuery_WPF('#task_task_status_attr').removeAttr("onchange");
    jQuery_WPF('#task_task_priority_attr').removeAttr("onchange");
    jQuery_WPF('#wpf_attributes_content input[name="author_list_task"]').removeAttr("onclick");
    jQuery_WPF('#wpfb_attr_task_page_link').removeAttr("href");
    jQuery_WPF('#wpf_delete_task_container .wpf_task_delete_btn').remove();
    jQuery_WPF('#wpf_attributes_content input[name=author_list_task]').attr('checked', false);
    jQuery_WPF('.wpf_upload_button.wpf_button').hide();

    jQuery_WPF('div#wpf_task_details .wpf_task_main_top .wpf_task_title_top').html('');
    jQuery_WPF('div#wpf_task_details .wpf_task_num_top').html('');
    jQuery_WPF('div#wpf_task_details #wpf_message_list').html('');
    jQuery_WPF("input[type=text],input[type=hidden], textarea").val("");
    jQuery_WPF("#task_task_priority_attr").val("low");
    jQuery_WPF("#task_task_status_attr").val("open");

    jQuery_WPF('div#wpf_task_details .wpf_task_main_top .wpf_task_title_top').html(wpfb_all_pages_html);
    jQuery_WPF('div#wpf_task_details .wpf_task_num_top').html(comment_count);
    jQuery_WPF('div#wpf_task_details .wpf_task_details_top').html('By '+current_user_name+' '+wpf_comment_time);
    
    jQuery_WPF('div#wpf_task_details #wpf_message_list').html('');
    jQuery_WPF("input[type=text],input[type=hidden], textarea").val("");
    jQuery_WPF("#task_task_priority_attr").val("low");
    jQuery_WPF("#task_task_status_attr").val("open");
    jQuery_WPF('#all_tag_list').html('');
    jQuery_WPF('.wpf_task_tags').hide();
    // add rich text editor for Task center by Pratap
    jQuery(document).find('.wpf-tc-editor').each(function() {
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

function wpf_generate_front_task(is_internal = 0, note = false){
    var wpf_comment = jQuery_WPF('#wpf_comment').val();
    var curr_browser = get_browser();
    var new_task = Array();
    var current_page_id = jQuery_WPF('#wpf_page_list').val();
    var task_priority = jQuery_WPF('#wpf_attributes_content #task_task_priority_attr').val();
    var task_status = jQuery_WPF('#wpf_attributes_content #task_task_status_attr').val();
    var task_notify_users = [];
    var task_comment = jQuery_WPF('#wpf_comment').val();
    jQuery_WPF.each(jQuery_WPF('#wpf_attributes_content input[name=author_list_task]:checked'), function(){
        task_notify_users.push(jQuery_WPF(this).val());
    });
    task_notify_users =task_notify_users.join(",");
    new_task['task_number']=comment_count;
    new_task['task_priority']=task_priority;
    new_task['task_status']=task_status;
    new_task['task_config_author_browser']=curr_browser['name'];
    new_task['task_config_author_browserVersion']=curr_browser['version'];
    new_task['task_config_author_browserOS']=curr_browser['OS'];
    new_task['task_config_author_name']=current_user_name;
    new_task['task_config_author_id']=current_user_id;
    new_task['task_config_author_resX']=window.screen.width;
    new_task['task_config_author_resY']=window.screen.height;
    new_task['task_title']=task_comment;
    new_task['current_page_id']=current_page_id;
    new_task['task_comment_message']=task_comment;
    new_task['task_notify_users']=task_notify_users;
    new_task['task_type']='general';
    new_task['is_note'] = note;

    if ( is_internal ) {
        new_task['is_internal'] = true;
    }

    var new_task_obj = jQuery_WPF.extend({}, new_task);
   
     if ( jQuery_WPF('#wpf_comment').val().trim().length > 0 && task_notify_users.length > 0 && jQuery_WPF('#wpf_page_list').val() && !note ) {
        jQuery_WPF.ajax({
            method : "POST",
            url : ajaxurl,
            data : {action: "wpf_add_new_task",wpf_nonce:wpf_nonce,new_task:new_task_obj},
            beforeSend: function(){
                jQuery_WPF('.wpf_loader_admin').show();
            },
            success : function(data){
                try {
                    const jsonData = JSON.parse(data);
                    if ( jsonData['limit'] === true ) {
                        jQuery(".wpf_locked_modal_container").show();
                        return;
                    }
                } catch (excep) {}
                
                location.reload();
            }
        });
     }
    else {
        if(!jQuery_WPF('#wpf_page_list').val() ){
            jQuery_WPF("p.form-submit.chat_button #wpf_error_page").remove();
            jQuery_WPF("p.form-submit.chat_button #wpf_error").remove();
            jQuery_WPF("#wpf_page_list").css('border', '1px solid red');
            jQuery_WPF("p.form-submit.chat_button").append('<p id="wpf_error_page">Page/post must be selected to post a comment</span>');
        }
        else if(task_notify_users.length == 0){
            jQuery_WPF("#wpf_page_list").removeAttr('style');
            jQuery_WPF("p.form-submit.chat_button #wpf_error_page").remove();
            jQuery_WPF("p.form-submit.chat_button #wpf_error").remove();
            jQuery_WPF("p.form-submit.chat_button").append('<p id="wpf_error">'+wpf_task_text_error_msg+'</span>');
        }else if( note ){
            jQuery_WPF("#wpf_page_list").removeAttr('style');
            jQuery_WPF("p.form-submit.chat_button #wpf_error_page").remove();
            jQuery_WPF("p.form-submit.chat_button #wpf_error").remove();
            jQuery_WPF("p.form-submit.chat_button").append('<p id="wpf_error">'+wpf_task_note_error_msg+'</span>');
        }else{
            jQuery_WPF("#wpf_page_list").removeAttr('style');
            jQuery_WPF("#wpf_comment").css('border', '1px solid red');
            jQuery_WPF("#wpf_comment").focus();
        }
        jQuery_WPF("#wpf_loader_admin").hide();
        jQuery_WPF("#get_masg_loader").hide();
    }
    
}

function wpf_edit_license() {
    jQuery_WPF('#wpfeedback_licence_key').prop('disabled',false);
    jQuery_WPF('#wpfeedback_licence_key').attr('type','text');
    jQuery_WPF('#wpfeedback_licence_key').val('');

}
/*resync dashboard */
function wpf_resync_dashboard() {
    jQuery_WPF.ajax({
        method: "POST",
        url: ajaxurl,
        data: {action: "wpf_resync_dashboard",wpf_nonce:wpf_nonce},
        beforeSend: function(){
                jQuery_WPF('.wpf_loader_admin').show();
            },
        success: function (data) {
            if (data == 1) {
                var url = window.location.href+'&resync_dashboard=1';
                window.location.href = url;
            }else{
                var url = window.location.href+'&resync_dashboard=2';
            }
        }
    });
}
function wpf_edit_title(){
    jQuery_WPF("#wpf_edit_title_box").toggle();
    var wpf_task_title = jQuery_WPF('#wpf_task_details .wpf_chat_top .wpf_task_title_top').text();
    jQuery_WPF("#wpf_title_val").val(wpf_task_title);
}

function wpf_update_title(){
    var wpf_new_task_title = jQuery_WPF("#wpf_title_val").val();
    var wpf_task_id = jQuery_WPF('#comment_post_ID').val();
    if(wpf_new_task_title !='' && wpf_task_id !=''){
        jQuery_WPF.ajax({
        method: "POST",
        url: ajaxurl,
        data: {action: "wpf_update_title",wpf_new_task_title:wpf_new_task_title,wpf_task_id:wpf_task_id,wpf_nonce:wpf_nonce},
            success: function (data) {
                var wpf_task_info = JSON.parse(data);
                jQuery_WPF("#wpf_edit_title_box").toggle();
                jQuery_WPF("#wpf_title_val").val();
                jQuery_WPF('#wpf_task_details .wpf_chat_top .wpf_task_title_top').text(wpf_task_info.wpf_new_task_title);
                if(wpf_task_info.wpf_new_task_title == null){
                    wpf_task_info.wpf_new_task_title = "";
                }
                jQuery_WPF('#wpf-task-'+wpf_task_info.wpf_task_id+' .wpf_chat_top .wpf_task_pagename').text(wpf_task_info.wpf_new_task_title);    
                jQuery_WPF('#wpf-task-'+wpf_task_info.wpf_task_id).data('task_page_title',wpf_task_info.wpf_new_task_title);
            }
        });
    }
    
}


function wpf_add_tag_admin(e) {
    var tag_name = jQuery_WPF('#wpf_tags').val();
    var task_id = jQuery_WPF('#comment_post_ID').val();
    var wpf_task_tag_info = [];
    wpf_task_tag_info['wpf_task_tag_name'] = tag_name;
    wpf_task_tag_info['wpf_task_id']=task_id;
    var wpf_task_tag_info_obj = jQuery_WPF.extend({}, wpf_task_tag_info);

    if(task_id !='' && tag_name !=''){
        jQuery_WPF.ajax({
            method : "POST",
            url : ajaxurl,
            data : {action: "wpfb_set_task_tag",wpf_nonce:wpf_nonce,wpf_task_tag_info:wpf_task_tag_info_obj},
            beforeSend: function(){			
                jQuery_WPF('.wpf_loader_admin').show();
            },
            success : function(data){
                var task_tag_info = JSON.parse(data);
                jQuery_WPF('.wpf_loader_admin').hide();
                jQuery_WPF('.wpf_loader').hide();
                if( task_tag_info.wpf_tag_type == 'already_exit' ) {
                    alert('The tag "' + tag_name + '" exists for this task');
                    jQuery_WPF('#wpf_tags').attr('style','border: 1px solid red;');
                } else if ( task_tag_info.wpf_tag_type == 'invalid_tag' ) {
                    alert('It is an invalid tag');
                    jQuery_WPF('#wpf_tags').attr('style','border: 1px solid red;');
                } else {
                    jQuery_WPF('#wpf_tags').attr('style','border: 1px solid #ccc;');				
                    jQuery_WPF('#wpf_tags').val('');
                    jQuery_WPF('#all_tag_list').append("<span class='wpf_tag_name "+task_tag_info.wpf_task_tag_slug+"'>"+task_tag_info.wpf_task_tag_name+"<a href='javascript:void(0)' onclick='wpf_delete_tag_admin(\""+task_tag_info.wpf_task_tag_name+"\",\""+task_tag_info.wpf_task_tag_slug+"\","+task_id+")'><i class='gg-close-o'></i></a></span>");
                }
            }
        });
    }
}

function wpf_delete_tag_admin(wpf_task_tag_name,wpf_task_tag_slug, id){
    var wpf_task_tag_info = [];
    wpf_task_tag_info['wpf_task_tag_slug'] = wpf_task_tag_slug;
    wpf_task_tag_info['wpf_task_tag_name'] = wpf_task_tag_name;
    wpf_task_tag_info['wpf_task_id']=id;
    var wpf_task_tag_info_obj = jQuery_WPF.extend({}, wpf_task_tag_info);
    if(id !='' && wpf_task_tag_slug !=''){
        jQuery_WPF.ajax({
            method : "POST",
            url : ajaxurl,
            data : {action: "wpfb_delete_task_tag",wpf_nonce:wpf_nonce,wpf_task_tag_info:wpf_task_tag_info_obj},
            beforeSend: function(){
                jQuery_WPF('.wpf_loader_'+id).show();
                jQuery_WPF('.wpf_loader_admin').show();
            },
            success : function(data){
                var task_tag_info = JSON.parse(data);
                if(task_tag_info.wpf_msg == 1 ){
                    jQuery_WPF('#all_tag_list '+'.'+task_tag_info.wpf_task_tag_slug).remove();
                    jQuery_WPF('.wpf_loader_admin').hide();
                }
            }
        });
    } 
}

function wpf_tag_autocomplete(inp, arr) {
    var currentFocus;
    if(inp){
        inp.addEventListener("input", function(e) {
            var a, b, i, val = this.value;
            wpf_tag_closeAllLists();
            if (!val) { return false;}
            currentFocus = -1;
            a = document.createElement("DIV");
            a.setAttribute("id", this.id + "wpf_tag_autocomplete-list");
            a.setAttribute("class", "wpf_tag_autocomplete-items");
            this.parentNode.appendChild(a);
            for (i = 0; i < arr.length; i++) {
                if (arr[i].substr(0, val.length).toUpperCase() == val.toUpperCase()) {
                    b = document.createElement("DIV");
                    b.innerHTML = "<strong>" + arr[i].substr(0, val.length) + "</strong>";
                    b.innerHTML += arr[i].substr(val.length);
                    b.innerHTML += "<input type='hidden' value='" + arr[i] + "'>";
                    b.addEventListener("click", function(e) {
                        inp.value = this.getElementsByTagName("input")[0].value;
                        wpf_tag_closeAllLists();
                    });
                    a.appendChild(b);
                }
            }
        });
        inp.addEventListener("keydown", function(e) {
            var x = document.getElementById(this.id + "wpf_tag_autocomplete-list");
            if (x) x = x.getElementsByTagName("div");
            if (e.keyCode == 40) {
                currentFocus++;
                wpf_tag_addActive(x);
            } else if (e.keyCode == 38) {
                currentFocus--;
                wpf_tag_addActive(x);
            } else if (e.keyCode == 13) {
                e.preventDefault();
                if (currentFocus > -1) {
                    if (x) x[currentFocus].click();
                    // wpf_add_tag_admin(this.id);
                }
                // wpf_add_tag_admin(this.id);
            }
        });
        function wpf_tag_addActive(x) {
            if (!x) return false;
            wpf_tag_removeActive(x);
            if (currentFocus >= x.length) currentFocus = 0;
            if (currentFocus < 0) currentFocus = (x.length - 1);
            x[currentFocus].classList.add("wpf_tag_autocomplete-active");
        }
        function wpf_tag_removeActive(x) {
            for (var i = 0; i < x.length; i++) {
                x[i].classList.remove("wpf_tag_autocomplete-active");
            }
        }
        function wpf_tag_closeAllLists(elmnt) {
            var x = document.getElementsByClassName("wpf_tag_autocomplete-items");
            for (var i = 0; i < x.length; i++) {
                if (elmnt != x[i] && elmnt != inp) {
                    x[i].parentNode.removeChild(x[i]);
                }
            }
        }
        document.addEventListener("click", function (e) {
            wpf_tag_closeAllLists(e.target);
        });
    }
}

jQuery_WPF('#wpf_display_all_taskmeta_tasktab').click(function(){
    jQuery_WPF('#wpf_display_all_taskmeta_tasktab').parent().toggleClass("wpf_isSelected");
    if(jQuery_WPF('ul#all_wpf_list  li .wpf_task_meta').hasClass('wpf_active')){
        jQuery_WPF('ul#all_wpf_list li .wpf_task_meta').removeClass('wpf_active');
    }else{
        jQuery_WPF('ul#all_wpf_list li .wpf_task_meta').addClass('wpf_active');
    }
});

function wpf_tasks_tabs(tab) {
    jQuery_WPF('#wpf_task_bulk_tab').parent().toggleClass("wpf_isSelected");
    if(jQuery_WPF('#wpf_task_bulk_tab').is(":checked")) {
        jQuery_WPF('#wpf_task_tab_title').html(wpf_bulk_editing_tasks);
        jQuery_WPF('.wpf_tasks-list').addClass('wpf_bulk_edit_mode');
        jQuery_WPF('#wpf_add_general_task').hide();
        jQuery_WPF('.wpf_task_num_top').hide();
        jQuery_WPF('#wpf_task_all_tab').removeClass('active');
        jQuery_WPF('ul#all_wpf_list li .wpf_task_id').addClass('wpf_active');
        jQuery_WPF('ul#all_wpf_list #wpf_bulk_select_task_checkbox').addClass('wpf_active');
        jQuery_WPF('#wpf_bulk_select_task_checkbox').show();

        jQuery_WPF('#wpf_task_details').hide();
        jQuery_WPF('#wpf_attributes_content').hide();
        jQuery_WPF('#wpf_bulk_update_content').show();
    }
    else{
        jQuery_WPF('#wpf_task_tab_title').html(wpf_tasks_found);
        jQuery_WPF('.wpf_tasks-list').removeClass('wpf_bulk_edit_mode');
        jQuery_WPF('#wpf_add_general_task').show();
        jQuery_WPF('.wpf_task_num_top').show();
        jQuery_WPF('#wpf_task_all_tab').addClass('active');
        jQuery_WPF('#wpf_task_bulk_tab').removeClass('active');
        jQuery_WPF('ul#all_wpf_list li .wpf_task_id').removeClass('wpf_active');
        jQuery_WPF('ul#all_wpf_list #wpf_bulk_select_task_checkbox').removeClass('wpf_active');
        jQuery_WPF('#wpf_task_details').show();
        jQuery_WPF('#wpf_attributes_content').show();
        jQuery_WPF('#wpf_bulk_update_content').hide();
        jQuery_WPF('#wpf_bulk_select_task_checkbox').hide();
        jQuery_WPF("ul#all_wpf_list li.wpf_list input[name='wpf_task_id']:checked").prop("checked", false);

        jQuery_WPF('#wpf_task_details').show();
        jQuery_WPF('#wpf_attributes_content').show();
        jQuery_WPF('#wpf_bulk_update_content').hide();
    }
}

function wpf_open_tab(show) {
    if(show=='wpf_task_screenshot_tab'){
        jQuery_WPF('#wpf_task_screenshot_tab').show();
        jQuery_WPF('#wpf_message_content').hide();
        jQuery_WPF('#wpf_message_form').hide();
        jQuery_WPF('#wpf_task_tabs_container .wpf_task_tab_item').removeClass('active');
        jQuery_WPF('#wpf_task_tabs_container .wpf_task_screenshot_tab').addClass('active');
    } else {
        jQuery_WPF('#wpf_task_screenshot_tab').hide();
        jQuery_WPF('#wpf_message_content').show();
        jQuery_WPF('#wpf_message_form').show();
        jQuery_WPF('#wpf_task_tabs_container .wpf_task_tab_item').removeClass('active');
        jQuery_WPF('#wpf_task_tabs_container .wpf_message_content').addClass('active');
    }
}

jQuery_WPF(document).on("click", "#wpf_select_all_task" , function(){
    if(jQuery_WPF('#wpf_select_all_task').is(":checked")){
        jQuery_WPF( '.wpf_list input[type="checkbox"]' ).prop('checked', this.checked).parent().addClass('active');
    }
    else{
        jQuery_WPF( '.wpf_list input[type="checkbox"]' ).prop('checked', this.checked).parent().removeClass('active');
    }
});

jQuery_WPF( '#wpf_conformation_box_show' ).click( function () {
    var selected_tasks = jQuery_WPF('.wpf_list input[type="checkbox"]:checked').length;
    if(selected_tasks > 0){
        jQuery_WPF('#wpf_update_option_container').show();
    }
});

jQuery_WPF('.wpf_task_id').on('click',function(){
    if(jQuery_WPF(this).is(":checked")){
        jQuery_WPF(this).parent().addClass('active');
    }else{
        jQuery_WPF(this).parent().removeClass('active');
    }

});

function wpf_bulk_update() {
    var wpf_task_ids = [];
    jQuery_WPF.each(jQuery_WPF("ul#all_wpf_list li.wpf_list input[name='wpf_task_id']:checked"), function () {
        wpf_task_ids.push(jQuery_WPF(this).val());
    });
    var wpf_task_priority_attr = jQuery_WPF("#wpf_bulk_update_content select#task_task_priority_attr"). val();
    var wpf_task_task_status_attr = jQuery_WPF("#wpf_bulk_update_content select#task_task_status_attr"). val();
    if(wpf_task_ids != '' && (wpf_task_priority_attr != '' || wpf_task_task_status_attr != '')){
        jQuery_WPF.ajax({
            method : "POST",
            url : ajaxurl,
            data : {action: "wpf_bulk_update_tasks",wpf_nonce:wpf_nonce,wpf_task_task_status_attr:wpf_task_task_status_attr,wpf_task_priority_attr:wpf_task_priority_attr,wpf_task_ids:wpf_task_ids},
            beforeSend: function(){
                jQuery_WPF('.wpf_loader_admin').show();
            },
            success : function(data){
                var task_response_info = JSON.parse(data);
                if(task_response_info.wpf_msg == 1 ){
                    jQuery_WPF.ajax({
                        method: "POST",
                        url: ajaxurl,
                        data: {
                            action: "wpfeedback_get_post_list_ajax",
                            wpf_nonce:wpf_nonce
                        },
                        success: function (data) {
                            jQuery_WPF('.wpf_loader_admin').hide();
                            jQuery_WPF('#all_wpf_list').html(data);
                            jQuery_WPF("#wpf_display_all_taskmeta_tasktab").trigger('click');
                            jQuery_WPF("#wpf_display_all_taskmeta_tasktab").prop("checked", true);
                            jQuery_WPF("#wpf_task_bulk_tab").trigger('click');
                            jQuery_WPF("#wpf_task_bulk_tab").prop("checked", true);
                            wpf_tasks_tabs('bulk');
                            jQuery_WPF.each(wpf_task_ids,function (index, value) {
                                jQuery_WPF("#wpf_"+value).prop("checked", true);                                
                            });

                        }
                    });
                }
            }
        });
    }
}

jQuery_WPF(document).find("#wpf_bulk_delete_task_container").on("click",".wpf_bulk_task_delete_btn",function(e) {
    jQuery_WPF('#wpf_bulk_task_delete').show();
});

jQuery_WPF(document).find("#wpf_bulk_delete_task_container").on("click",".wpf_bulk_task_delete",function(e){
    var wpf_task_ids = [];
    var selected_tasks = jQuery_WPF("ul#all_wpf_list li.wpf_list input[name='wpf_task_id']:checked").length;
    if(selected_tasks > 0) {
        jQuery_WPF.each(jQuery_WPF("ul#all_wpf_list li.wpf_list input[name='wpf_task_id']:checked"), function () {
            var elemid = '';
            var task_id = jQuery_WPF(this).val();
            wpf_admin_delete_task(elemid, task_id);
        });
        jQuery_WPF('#wpf_bulk_task_delete').hide();
    }
});