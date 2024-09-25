//show upgrade notice popup for edit/delete by Pratap
function edit_delete_plan() {
    jQuery_WPF('.wpf-uf-popup-image img').attr('src', plugin_url + '/images/edit-delete.png');
    jQuery_WPF('.wpf-uf-plan-title').text('Edit/Delete comment');
    jQuery_WPF('.wpf-uf-plan-detail').html('Make it easy to collaborate with your team on tasks that your clients cannot see with internal tasks. Change the logo, icon and main color to give your clients’ a unique experience.');
    jQuery_WPF('.wpf-uf-plan-link').attr('href', upg_url + '?&feature=edit');
    jQuery_WPF('.wpf-uf-pop-wrapper').show();
}

//close upgrade notice popup by Pratap
jQuery_WPF(document).on('click', '.wpf-uf-pop-wrapper, .wpf-uf-pop-container, .wpf-uf-close-popup .gg-close', function(e){
    if(e.target == this){
        jQuery_WPF('.wpf-uf-pop-wrapper').hide();
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

jQuery_WPF(document).ready(function(){
    wpf_tab_permission.user=wpf_tab_permission_user;
    wpf_tab_permission.priority=wpf_tab_permission_priority;
    wpf_tab_permission.status=wpf_tab_permission_status;
    wpf_tab_permission.screenshot=wpf_tab_permission_screenshot;
    wpf_tab_permission.information=wpf_tab_permission_information;
    wpf_tab_permission.delete_task=wpf_tab_permission_delete_task;
    wpf_tab_permission.auto_screenshot = wpf_tab_permission_auto_screenshot;
    
    /* initialze display sticker permission */
    wpf_tab_permission.display_stickers = wpf_tab_permission_display_stickers;
    wpf_tab_permission.display_task_id = wpf_tab_permission_display_task_id;

    /* keyboard shortcut permission v2.1.0 */
    wpf_tab_permission.keyboard_shortcut = wpf_tab_permission_keyboard_shortcut;
    img_dwn_icon  = "<span id='wpf_push_media' class='wpf_push_media wpf_image_download'>"+push_to_media_icon+"</span><span id='wpf_image_download' class='wpf_image_download' onclick='wpf_image_download_action(this)'>"+image_download_icon+"</span><span id='wpf_image_open' class='wpf_image_open' onclick='wpf_image_open_new_tab(this)'>"+image_open_icon+"</span>";

    jQuery_WPF(document).on('click','.wpf_push_media',function(){
        let media_link=jQuery_WPF(this).parent().find('a').attr('href');
        let id_element=jQuery_WPF(this).parent().closest('.wpf_current_chat_box').attr('id'); // taking the id from "data-comment_id"
        let current_id = id_element.split("_")[2]; // data-comment_id
        const curElement = jQuery_WPF(this);
        
        jQuery_WPF.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action:'push_to_media',wpf_nonce:wpf_nonce,media_link:media_link },
            beforeSend: function(){
                if ( !curElement.parents('.chat_author').length )
                    jQuery_WPF('.wpf_loader_'+current_id).show();
                else
                    jQuery_WPF('.wpf_loader_admin').show();
            },
            success: function(data){
                if ( !curElement.parents('.chat_author').length )
                    jQuery_WPF('.wpf_loader_'+current_id).hide();
                else 
                    jQuery_WPF('.wpf_loader_admin').hide();

                if(data==1){
                    jQuery_WPF('#pushed_to_media').show().delay(5000).fadeOut();
                }else{
                    jQuery_WPF('#wpf_push_to_media_error_'+current_id).show().delay(5000).fadeOut();
                }
            }
        });
    })

    jQuery_WPF(document).on('mouseenter','.wpf_task_number',function(){
        jQuery_WPF(this).find('span').hide();
    });

    jQuery_WPF(document).on('mouseleave','.wpf_task_number',function(){
         jQuery_WPF(this).find('span').show();
    });

    if ( jQuery_WPF('.custom_today').find('li').length == 0 ) {
        jQuery_WPF('.custom_today ul').append('<li class="no_task_found">No Task Found!</li>');
    }

    if ( jQuery_WPF('.custom_yesterday ul li').length < 1 ) {
        jQuery_WPF('.custom_yesterday ul').append('<li class="no_task_found">No Task Found!</li>');
    }

    if ( jQuery_WPF('.custom_Weekly ul li').length < 1 ) {
        jQuery_WPF('.custom_Weekly ul').append('<li class="no_task_found">No Task Found!</li>');
    }

    /* Keyboard shortcut => v2.1.0 */
    if (wpf_tab_permission.keyboard_shortcut == 'yes') {
        let isShiftKeyPressed = false;
        jQuery_WPF(document).on('keydown', function (e) {

            // check if the target is an input field or not
            // This check is needed because user can type C (SHIFT + c) on any input area
            // If that happen, we don't need to fire the Keyboard Shortcut functionality
            const target = jQuery_WPF( e.target );
            if ( !(target.is('input')) && !(target.is('textarea')) && !(target.is('select')) && !(target.is('.CodeMirror-code'))  && !(target.is('.block-editor-rich-text__editable')) ) {

                // if SHIFT key is pressed
                if (!isShiftKeyPressed) {
                    if (e.keyCode == 16) {
                        isShiftKeyPressed = true;
                    }
                } else {

                    switch (e.keyCode) {

                        case 70:
                            enable_comment(); // key F - open comment popup => v2.1.1
                            break;

                        case 71:
                            wpf_new_general_task(); // key G - open general comment popup
                            break;
                    }
                }
            }
        }).on('keyup', function (e1) {

            if (e1.keyCode == 16) {
                isShiftKeyPressed = false;
            }
        });
    }
});

jQuery_WPF("body").scroll(function() {
    if(document.body.scrollTop > 100){
        jQuery_WPF(".wpf_settings_sidebar").addClass("wpf_settings_sidebar_sticky");
        jQuery_WPF(".wpf_settings_col").css("border-left","1px solid #e3ebf6");
        jQuery_WPF(".wpf_settings_sidebar").css("border-right","none !important");
    }else{
        jQuery_WPF(".wpf_settings_sidebar").css("top","10px");
        jQuery_WPF(".wpf_settings_sidebar").removeClass("wpf_settings_sidebar_sticky");
        jQuery_WPF(".wpf_settings_col").css("border-left","");
    }
});

function screenshot(id){
    const rollSound = new Audio(wpf_screenshot_sound);
    if(tasks_on_page[id] > 0){
        rollSound.play();

        html2canvas(document.body,{
            x: window.scrollX,
            y: window.scrollY,
            width: window.innerWidth,
            height: window.innerHeight,
            useCORS: true,
            logging: true,}).then(function(canvas) {
            var base64URL = canvas.toDataURL('image/jpeg',1);
            task_screenshot['post_id']=tasks_on_page[id];
            task_screenshot['task_config_author_name']=current_user_name;
            task_screenshot['task_config_author_id']=current_user_id;
            var new_task_screenshot_obj = jQuery_WPF.extend({}, task_screenshot);
                jQuery_WPF('body').addClass('wpfb_screenshot_class');
                setTimeout(function(){
                    jQuery_WPF('body').removeClass('wpfb_screenshot_class');
                }, 500);

                jQuery_WPF.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {action:'wpfb_save_screenshot',wpf_nonce:wpf_nonce, task_screenshot:new_task_screenshot_obj, image: base64URL},
                    beforeSend: function(){
                        jQuery_WPF('.wpf_loader_'+id).show();
                    },
                    success: function(data){
                        jQuery_WPF('.wpf_loader_'+id).hide();
                        var author = '';
                        if ( logged_user.author != '' ) {
                            author = logged_user.author;
                        } else {
                            author = current_user_name;
                        }
                        var comment_html = '<li class="wpf_author"><div class="wpf-comment-container"><div class="wpf-author-img">' + author_html + '</div><div class="wpf-comment-wrapper"><level class="task-author"><div class="author-name">' + current_user_name + '</div><span>now</span></level><div class="meassage_area_main"><a href="'+data+'" target="_blank"><img src="'+data+'" alt="" /></a></div></div></div></li>';
                        jQuery_WPF('#task_comments_'+id).append(comment_html);
                        jQuery_WPF('#task_comments_'+id).animate({scrollTop: jQuery_WPF('#task_comments_'+id).prop("scrollHeight")}, 2000);
                    }
                });
        });
    }
    else{
        jQuery_WPF('#wpf_error_'+id).hide();
        //jQuery_WPF('#wpf_task_error_'+id).show();
        }
}

jQuery_WPF(document).ready(function(){

    jQuery_WPF('a').click(function(e) {
        if(jQuery_WPF(this).hasClass("active_comment")){
            e.preventDefault();
        }
        else{
        }
    });
    
    jQuery_WPF('a img').click(function(e) {
        if(jQuery_WPF(this).parent().hasClass("active_comment")){
            e.preventDefault();
        }
        else{
        }
    });

    jQuery_WPF('input[type="button"]').click(function(e) {
        if(jQuery_WPF(this).hasClass("active_comment")){
            e.preventDefault();
            return false;
        }
        else{
        }
    });

    jQuery_WPF("form").submit(function(e){
        if(comments==true){
            e.preventDefault();
        }
    });
});

// Sanitize HTML to avoid XSS attack by Pratap.
function sanitize_comment(raw_comment) {
    var esc_html = ['onerror', 'onmouseover', 'onmouseenter', 'onmouseleave', 'onmousedown', 'onmouseup', 'onmousemove', 'onmouseout', 'alert', 'prompt'];
    raw_comment = raw_comment.replace(new RegExp(esc_html.join("|"), "g"), "");
    return raw_comment;
}

function timeSort(a, b) {
    return b-a
}

function wpf_edit_box_active(comment_id){
    var com_html = jQuery_WPF( '#wpf-chat-text-' + comment_id ).html();
    jQuery_WPF( '#wpfb-edit-comment-wrapper-' + comment_id + ' .wpf-editor .ql-editor').html(com_html);
    jQuery_WPF("#wpfb-edit-comment-wrapper-"+comment_id).show();
}

function wpfb_edit_comment(comment_id) {
    var task_info = [];

    task_info['comment_id'] = comment_id;
    var comment_content = jQuery_WPF("#wpfb-edit-comment-wrapper-"+comment_id+" textarea").val();
    if ( comment_content ) {
        task_info['comment_content'] = comment_content;
        jQuery_WPF('#wpfb-edit-comment-wrapper-' + comment_id + ' .wpf_hide').hide();
        var task_info_obj = jQuery_WPF.extend({}, task_info);

        jQuery_WPF.ajax({
            method : "POST",
            url : ajaxurl,
            data : {action: "wpfb_edit_comment",wpf_nonce:wpf_nonce,task_info:task_info_obj},
            beforeSend: function(){
                
            },
            success : function(data){
                jQuery_WPF("#wpf-chat-text-"+comment_id).html(task_info['comment_content']);
                jQuery_WPF("#wpfb-edit-comment-wrapper-"+comment_id).hide();
            }
        });
    } else {
        jQuery_WPF('#wpfb-edit-comment-wrapper-' + comment_id + ' .wpf_hide').show();
    }
}

jQuery_WPF('.wpf_sidebar_content').bind('scroll', function() {
    if( jQuery_WPF(window).scrollTop() >= (jQuery_WPF('#wpf_allpages').offset().top + jQuery_WPF('#wpf_allpages').outerHeight() - window.innerHeight)) {
        if ( reload_task == true && page_no > 0 ) {
            load_all_page_tasks();
            reload_task = false;
        }
    }
});

function getDomPath(el) {
    var stack = [];
    while ( el.parentNode != null ) {
        var sibCount = 0;
        var sibIndex = 0;
        for ( var i = 0; i < el.parentNode.childNodes.length; i++ ) {
            var sib = el.parentNode.childNodes[i];
            if ( sib.nodeName == el.nodeName ) {
                if ( sib === el ) {
                    sibIndex = sibCount;
                }
                sibCount++;
            }
        }
        if ( el.hasAttribute('id') && el.id != '' ) {
            stack.unshift(el.nodeName.toLowerCase() + '#' + el.id);
        } else if ( sibCount > 1 ) {
            stack.unshift(el.nodeName.toLowerCase() + ':eq(' + sibIndex + ')');
        } else {
            stack.unshift(el.nodeName.toLowerCase());
        }
        el = el.parentNode;
    }

    return stack.slice(1); /*removes the html element*/
}

jQuery_WPF.fn.onPositionChanged = function (trigger, millis) {
    if (millis == null) millis = 100;
    var o = jQuery_WPF(this[0]); /*our jquery object*/
    if (o.length < 1) return o;

    var lastPos = null;
    var lastOff = null;
    setInterval(function () {
        if (o == null || o.length < 1) return o; /*abort if element is non existend eny more*/
        if (lastPos == null) lastPos = o.position();
        if (lastOff == null) lastOff = o.offset();
        var newPos = o.position();
        var newOff = o.offset();
        if (lastPos.top != newPos.top || lastPos.left != newPos.left) {
            jQuery_WPF(this).trigger('onPositionChanged', { lastPos: lastPos, newPos: newPos });
            if (typeof (trigger) == "function") trigger(lastPos, newPos);
            lastPos = o.position();
        }
        if (lastOff.top != newOff.top || lastOff.left != newOff.left) {
            jQuery_WPF(this).trigger('onOffsetChanged', { lastOff: lastOff, newOff: newOff});
            if (typeof (trigger) == "function") trigger(lastOff, newOff);
            lastOff= o.offset();
        }
    }, millis);

    return o;
};

function wpf_bubble_tracker(comment_count,task_clean_dom_elem_path) {
    jQuery_WPF(task_clean_dom_elem_path).onPositionChanged(function(){
        setTimeout(function() {
            var element_center = getelementcenter(task_clean_dom_elem_path);
            element_center['left']=element_center['left']-25;
            element_center['top']=element_center['top']-25;
            jQuery_WPF('#bubble-'+comment_count).attr('style','top:'+element_center['top']+'px; left:'+element_center['left']+'px;')
        }, 0);

    });
}

function trigger_bubble_label(){
    var wpf_page_value = getParameterByName('wpf_taskid');
    if(wpf_page_value != ''){
        jQuery_WPF('#wpfb_display_completed_tasks').trigger('click');
        //wpf_display_tasks();

        setTimeout(function() { jQuery_WPF('body').find('#bubble-'+wpf_page_value).trigger('click')},20);


        if(jQuery_WPF("#bubble-"+wpf_page_value).length > 0) {
                jQuery_WPF('html, body').animate({
                    scrollTop: jQuery_WPF("#bubble-"+wpf_page_value).offset().top - 200
                }, 1000);
        }
    }

    var wpf_general_taskid_value = getParameterByName('wpf_general_taskid');
    if(wpf_general_taskid_value != ''){
        wpf_load_general_task(wpf_general_taskid_value);
    }
}

function getParameterByName( name ){
    name = name.replace(/[\[]/,"\\\[").replace(/[\]]/,"\\\]");
    var regexS = "[\\?&]"+name+"=([^&#]*)";
    var regex = new RegExp( regexS );
    var results = regex.exec( window.location.href );
    if( results == null )
        return "";
    else
        return decodeURIComponent(results[1].replace(/\+/g, " "));
}

// Check if editor is empty before adding value to textarea.
function  isQuillEmpty( quill ) {
    if ( ( quill.getContents()['ops'] || [] ).length !== 1) {
        return false;
    }
    return quill.getText().trim().length === 0
}

/*Start Code for Fiter task in sidebar*/
function wpf_show(filter_type){
    jQuery_WPF('.wpf_sidebar_content input[name="wpf_filter_task_status"]:checked').prop('checked',false);
    jQuery_WPF('.wpf_sidebar_content input[name="wpf_filter_task_priority"]:checked').prop('checked',false);
    jQuery_WPF('.wpf_sidebar_filter').find('.wpf_filter').removeClass("wpf_active");

    if(filter_type == 'wpf_filter_taskstatus'){
        jQuery_WPF('.wpf_filter_taskstatus').addClass("wpf_active");
        jQuery_WPF('ul#wpf_backend_container li').show();
        jQuery_WPF('#wpf_filter_taskstatus').show();
        jQuery_WPF('#wpf_filter_taskpriority').hide();
    }

    if(filter_type == 'wpf_filter_taskpriority'){
        jQuery_WPF('.wpf_filter_taskpriority').addClass("wpf_active");
        jQuery_WPF('ul#wpf_backend_container li').show();
        jQuery_WPF('#wpf_filter_taskpriority').show();
        jQuery_WPF('#wpf_filter_taskstatus').hide();
    }
}

jQuery_WPF(document).ready(function(){
    jQuery_WPF('#wpf_sidebar_filter_task_status input[name="wpf_filter_task_status"]').click(function(){
        jQuery_WPF('.wpf_container.wpf_active_filter ul li').hide();
        var wpf_task_status = [];
        jQuery_WPF.each(jQuery_WPF('#wpf_sidebar_filter_task_status input[name="wpf_filter_task_status"]:checked'), function(){
            jQuery_WPF('.wpf_container.wpf_active_filter .'+jQuery_WPF(this). val()).show();
            wpf_task_status.push(jQuery_WPF(this).val());
        });
        if (wpf_task_status.length === 0) {
            jQuery_WPF('.wpf_container.wpf_active_filter ul li').show();
        }
    });

    jQuery_WPF('#wpf_sidebar_filter_task_priority input[name="wpf_filter_task_priority"]').click(function(){
        jQuery_WPF('.wpf_container.wpf_active_filter ul li').hide();
        var wpf_task_priority = [];
        jQuery_WPF.each(jQuery_WPF('#wpf_sidebar_filter_task_priority input[name="wpf_filter_task_priority"]:checked'), function(){
            jQuery_WPF('.wpf_container.wpf_active_filter .'+jQuery_WPF(this). val()).show();
            wpf_task_priority.push(jQuery_WPF(this).val()); 
        });
        if (wpf_task_priority.length === 0) {
            jQuery_WPF('.wpf_container.wpf_active_filter ul li').show();
        }
    });

    jQuery_WPF('#wpf_filter_taskstatus .wpf_sidebar_filter_reset_task_status').click(function(){
        jQuery_WPF('#wpf_sidebar_filter_task_status input[name="wpf_filter_task_status"]:checked').prop('checked',false);
        jQuery_WPF('.wpf_container.wpf_active_filter ul li').show();
    });

    jQuery_WPF('#wpf_filter_taskpriority .wpf_sidebar_filter_reset_task_priority').click(function(){
        jQuery_WPF('#wpf_sidebar_filter_task_priority input[name="wpf_filter_task_priority"]:checked').prop('checked',false);
        jQuery_WPF('.wpf_container.wpf_active_filter ul li').show();
    });
});
/*END Code for Fiter task in sidebar*/

function wpf_submit_tags(ele,id){
    var tag_val = jQuery_WPF('#wpfeedback_tags_'+id).val();
}
function wpf_search_tags(ele,id) {
    if(!wpf_tag_initialized[id]){
        wpf_tag_autocomplete(document.getElementById("wpfeedback_tags_"+id), wpf_all_tags);
        wpf_tag_initialized[id]=true;
    }
}

function wpf_add_tag(id) {
    var tag_name = jQuery_WPF('#'+id).val();
	var commentid = jQuery_WPF('#'+id).data('commentid');
    var task_id = jQuery_WPF('#'+id).data('id');
    var wpf_task_tag_info = [];
    wpf_task_tag_info['wpf_task_tag_name'] = tag_name;
    wpf_task_tag_info['wpf_task_id']=task_id;
    var wpf_task_tag_info_obj = jQuery_WPF.extend({}, wpf_task_tag_info);

    if(id !='' && tag_name !=''){
        jQuery_WPF.ajax({
            method : "POST",
            url : ajaxurl,
            data : {action: "wpfb_set_task_tag",wpf_nonce:wpf_nonce,wpf_task_tag_info:wpf_task_tag_info_obj},
            beforeSend: function(){
                jQuery_WPF('.wpf_loader_'+task_id).show();
                jQuery_WPF('.wpf_loader_'+commentid).show();
            },
            success : function(data){
                var task_tag_info = JSON.parse(data);
                wpf_all_tags.indexOf(task_tag_info.wpf_task_tag_name) === -1 ? wpf_all_tags.push(task_tag_info.wpf_task_tag_name) : 0;
                if(task_tag_info.wpf_tag_type != 'already_exit'){
                    jQuery_WPF('#wpfeedback_tags_'+task_id).attr('style','border: 1px solid #ccc;');
                    jQuery_WPF('.wpf_loader_'+task_id).hide();
					jQuery_WPF('.wpf_loader_'+commentid).hide();
                    jQuery_WPF('#wpfeedback_tags_'+task_id).val('');
                    jQuery_WPF('#all_tag_list_'+task_id).append("<span class='wpf_tag_name "+task_tag_info.wpf_task_tag_slug+"'>"+task_tag_info.wpf_task_tag_name+"<a href='javascript:void(0)' onclick='wpf_delete_tag(\""+task_tag_info.wpf_task_tag_name+"\",\""+task_tag_info.wpf_task_tag_slug+"\","+task_id+")'><i class=\"gg-close-o\"></i></a></span>");
                }else{
                    alert('The tag "' + tag_name + '" exists for this task');
                    jQuery_WPF('#wpfeedback_tags_'+task_id).attr('style','border: 1px solid red;');
                    jQuery_WPF('.wpf_loader_'+commentid).hide();
                }
            }
        });
    }
}

function wpf_delete_tag(wpf_task_tag_name,wpf_task_tag_slug, id){
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
                jQuery_WPF('.wpf_loader').show();
            },
            success : function(data){
                var task_tag_info = JSON.parse(data);
                if(task_tag_info.wpf_msg == 1 ){
                    jQuery_WPF('#all_tag_list_'+task_tag_info.wpf_task_id +' .'+task_tag_info.wpf_task_tag_slug).remove();
                    jQuery_WPF(document).find('#wpf_general_comment '+' #all_tag_list_'+task_tag_info.wpf_task_id +' .'+task_tag_info.wpf_task_tag_slug).remove();
                }
				jQuery_WPF('.wpf_loader').hide();
            }
        });
    }
}