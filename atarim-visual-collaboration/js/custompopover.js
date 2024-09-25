var clone,wpf_bootstrap_version,wpf_popover_template, old_selected_tab;
jQuery_WPF(document).ready(function(){
    wpf_popover_template = '<div class="popover wpf_comment_container" role="tooltip"><div class="arrow wpf_arrow"></div><h3 class="popover-header"></h3><div class="popover-body"></div></div>';
    if ( typeof istheme !== 'undefined' ) {
        if (  istheme.active_theme == 'ePress' || istheme.active_theme == 'ePress Child' || istheme.active_theme == 'Woodmart Child' || istheme.active_theme == 'Woodmart' ) {
            wpf_popover_template = '<div class="popover wpf_comment_container" role="tooltip"><div class="arrow wpf_arrow"></div><h3 class="popover-header"></h3><div class="popover-content"></div></div>';
        }
    }
    jQuery_WPF('#wpadminbar').attr("data-html2canvas-ignore", "true");
    init_popover();
});

function init_popover() {
    jQuery_WPF('[rel="popover"]').popover({
        html: true,
        trigger: 'click',
        template: wpf_popover_template,
        content: function () {
            if (jQuery_WPF(jQuery_WPF(this).data('popover-content')).clone(true).hasClass('hide')) {
                clone = jQuery_WPF(jQuery_WPF(this).data('popover-content')).clone(true).removeClass('hide');
            }
            $clone = jQuery_WPF('#popoverContent').remove().html();
            return clone;
        }
    });

    jQuery_WPF('.wpf_comment_container .nav-tabs a').click(function (e) {
        e.preventDefault();
        jQuery_WPF(this).tab('show');
        return false;
    });
    jQuery_WPF('.wpf_comment_container .nav-tabs a').on('touchstart', function (e) {
        e.preventDefault();
        jQuery_WPF(this).tab('show');
        return false;
    });
    jQuery_WPF('[data-toggle="popover"]').on('inserted.bs.popover', function () {
        setTimeout(function(){ 
            jQuery_WPF('#comment-'+id).focus(); 
        }, 100);
        jQuery_WPF('.popover').attr("data-html2canvas-ignore", "true");
    });

    jQuery_WPF(function () {
        jQuery_WPF('[data-toggle="milestone-popover"]').popover({
            template: '<div class="popover milestone_popover wpf_comment_container" role="tooltip"><div class="arrow"></div><a href="javascript:void(0)" onclick="close_milestone_popover();" class="close" data-dismiss="alert">×</a><h3 class="popover-header"></h3><div class="popover-body"></div></div>',
            content: "Loading...",
            placement: 'top',
            title: 'Project Phases',
            html: true,
            trigger: 'click',
            container: 'body',
            appendToBody: true,
            boundary: 'viewport',
        })
    });
}

//var quill = [];
/*popup for new task*/
function init_custom_popover(id) {
    var clone;
    var clone1;
    jQuery_WPF('[rel="popover-' + id + '"]').popover({
        html: true,
        trigger: 'click',
        template: wpf_popover_template,
        content: function () {
            if (jQuery_WPF(jQuery_WPF(this).data('popover-content')).clone(true).hasClass('hide')) {
                clone1 = jQuery_WPF(jQuery_WPF(this).data('popover-content')).clone(true).removeClass('hide');
            }
            // riche text editor for Popover by Pratap
            clone1.find('.wpf-editor').each(function() {
                if ( ! jQuery_WPF(this).hasClass('activee') ) {
                    var $this = jQuery_WPF(this);
                    jQuery_WPF(this).addClass('activee');
                    $c_id = jQuery_WPF(this).data('id');
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
                        if(quill.root.innerText != '\n') {
                            $this.closest('.form-group').find('.wpf_comment_btn, .wpf_mark_note, .wpf_mark_internal').removeClass('disabled');
                        } else {
                            $this.closest('.form-group').find('.wpf_comment_btn, .wpf_mark_note').addClass('disabled');
                        }
                        var isempty = isQuillEmpty( quill );
                        if ( !isempty ) {
                            $this.closest('.form-group').find('textarea').val(quill.root.innerHTML);
                        } else {
                            $this.closest('.form-group').find('textarea').val('');
                        }
                    });
                    $this.on('keydown', function(event) {
                        // Check if the pressed key is Enter (keyCode 13 or key "Enter")
                        if ( (event.keyCode == 13 || event.key === "Enter") && !event.shiftKey ) {
                            $this.closest('.form-group').find('.wpf_comment_btn.task_comment').trigger('click');
                        }
                    });
                    setTimeout(() => { 
                        quill.focus();
                    },100);
                }
            });
            $clone1 = jQuery_WPF('#popover-content-c' + id).remove().html();
            return clone1;
        }
    });
    
    //jQuery_WPF('#wpfbsysinfo-' + id + ' .wpf_tag_autocomplete').hide();
    //jQuery_WPF('#wpfbsysinfo-' + id + ' .wpf_icon_title').hide();
    jQuery_WPF('[rel="popover-' + id + '"]').popover('show');
    setTimeout(function(){ 
        jQuery_WPF('#comment-'+id).focus(); 
    }, 100);

    jQuery_WPF('#myTab-' + id + ' a').click(function (e) {

        /**
         *  Some user has issue when clicking the tab item, it automatically scroll up the page.
         *  To get around this issue, the below code put manual tab switch functionality rather than bootstrap's one
         *  This also can be solve by replacing "href" by "data-target" on tab item
         *  => v2.1.0
         */

        /**
         * Changed the logic => v2.1.1
         */

        // clean old selected value
        if ( jQuery_WPF(this).hasClass('active') ) {
            jQuery_WPF(this).removeClass('active');
            jQuery_WPF(this).closest('.nav-item').removeClass('active');
            jQuery_WPF('.tab-content .tab-pane').removeClass('active');
        } else {
            jQuery_WPF(this).parents('.nav-tabs').find('.nav-item').each(function(index, element) {
                jQuery_WPF(this).removeClass('active');
                if ( jQuery_WPF(element).children().hasClass('active') ) {
                    jQuery_WPF(element).children().removeClass('active');
                    jQuery_WPF(element).parents('.nav-tabs').next().find(jQuery_WPF(element).children().attr('href')).removeClass('active');
                }
            });

            if ( jQuery_WPF(old_selected_tab).attr('id') !== jQuery_WPF(this).attr('id') ) {
                const tab_content_id = jQuery_WPF(this).attr('href');
                jQuery_WPF(tab_content_id).addClass('active');
                jQuery_WPF(this).closest('.nav-item').addClass('active');
                jQuery_WPF(this).addClass('active');

                old_selected_tab = jQuery_WPF(this);
            } else {
                old_selected_tab = null;
            }
        }
    
        // prevents to execute the bootstrap functionality
        e.preventDefault();
        e.stopImmediatePropagation();
    }); 
    jQuery_WPF('.wpf_comment_container .nav-tabs a').on('touchstart', function (e) {
        e.preventDefault();
        jQuery_WPF(this).tab('show');
        return false;
    });

    jQuery_WPF(document).on("click", ".close" , function(e){
        jQuery_WPF(this).parents(".popover").popover('hide');
    });

    jQuery_WPF('[data-toggle="popover"]').on('inserted.bs.popover', function () {
        setTimeout(function(){ 
            jQuery_WPF('#comment-'+id).focus(); 
        }, 100);
        jQuery_WPF('.popover').attr("data-html2canvas-ignore", "true");
    });
    jQuery_WPF('#wpfbscreenshot-tab-' + id).click(function (e) {
        jQuery_WPF(this).parents('.popover').attr("data-html2canvas-ignore", "true");
    });
    jQuery_WPF('.wpf_task_temp_delete_btn').click(function(){
        var btn_taskid = jQuery_WPF(this).data('btn_elemid');
        jQuery_WPF('.wpfbsysinfo_temp_delete_task_id_'+btn_taskid).show();
    });
    jQuery_WPF('.wpf_task_temp_delete').click(function(){
        var elemid = jQuery_WPF(this).data('elemid');
        jQuery_WPF('#popover-content-c'+elemid+' .close').trigger('click');
        jQuery_WPF('#bubble-'+elemid).remove();
        comment_count--;
    });   

    jQuery_WPF( function() {
        jQuery_WPF( "#bubble-"+id ).draggable({ containment: "body" });
    });
}

/* popup for already created task */
function init_custom_popover_first(id) {
    var clone;
    var clone1;
    jQuery_WPF('[rel="popover-' + id + '"]').popover({
        html: true,
        trigger: 'click',
        template: wpf_popover_template,
        content: function () {
            if (jQuery_WPF(jQuery_WPF(this).data('popover-content')).clone(true).hasClass('hide')) {
                clone1 = jQuery_WPF(jQuery_WPF(this).data('popover-content')).clone(true).removeClass('hide');
            }
            // riche text editor for Popover by Pratap
            clone1.find('.wpf-editor').each(function() {
                if ( ! jQuery_WPF(this).hasClass('activee') ) {
                    var $this = jQuery_WPF(this);
                    jQuery_WPF(this).addClass('activee');
                    $c_id = jQuery_WPF(this).data('id');
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
                        if(quill.root.innerText != '\n') {
                            $this.closest('.form-group').find('.wpf_comment_btn, .wpf_mark_note').removeClass('disabled');
                        } else {
                            $this.closest('.form-group').find('.wpf_comment_btn, .wpf_mark_note').addClass('disabled');
                        }
                        var isempty = isQuillEmpty( quill );
                        if ( !isempty ) {
                            $this.closest('.form-group').find('textarea').val(quill.root.innerHTML);
                        } else {
                            $this.closest('.form-group').find('textarea').val('');
                        }
                    });
                    $this.on('keydown', function(event) {
                        // Check if the pressed key is Enter (keyCode 13 or key "Enter")
                        if ( (event.keyCode == 13 || event.key === "Enter") && !event.shiftKey ) {
                            $this.closest('.form-group').find('.wpf_comment_btn.task_comment').trigger('click');
                        }
                    });
                    setTimeout(() => { 
                        quill.focus();
                    },100);
                }
            });
            $clone1 = jQuery_WPF('#popover-content-c' + id).remove().html();
            return clone1;
        }
    });
    jQuery_WPF('.wpf_comment_container .nav-tabs a').click(function (e) {
        e.preventDefault();
        jQuery_WPF(this).tab('show');
        return false;
    });
    jQuery_WPF('.wpf_comment_container .nav-tabs a').on('touchstart', function (e) {
        e.preventDefault();
        jQuery_WPF(this).tab('show');
        return false;
    });

    jQuery_WPF('#myTab-' + id + ' a').click(function (e) {

        /**
         *  Some user has issue when clicking the tab item, it automatically scroll up the page.
         *  To get around this issue, the below code put manual tab switch functionality rather than bootstrap's one
         *  This also can be solve by replacing "href" by "data-target" on tab item
         *  => v2.1.0
         */

        /**
         * Changed the logic => v2.1.1
         */

        // clean old selected value
        if ( jQuery_WPF(this).hasClass('active') ) {
            jQuery_WPF(this).removeClass('active');
            jQuery_WPF(this).closest('.nav-item').removeClass('active');
            jQuery_WPF('.tab-content .tab-pane').removeClass('active');
        } else {
            jQuery_WPF(this).parents('.nav-tabs').find('.nav-item').each(function(index, element) {
                jQuery_WPF(this).removeClass('active');
                if ( jQuery_WPF(element).children().hasClass('active') ) {
                    jQuery_WPF(element).children().removeClass('active');
                    jQuery_WPF(element).parents('.nav-tabs').next().find(jQuery_WPF(element).children().attr('href')).removeClass('active');
                }
            });

            if ( jQuery_WPF(old_selected_tab).attr('id') !== jQuery_WPF(this).attr('id') ) {
                const tab_content_id = jQuery_WPF(this).attr('href');
                jQuery_WPF(tab_content_id).addClass('active');
                jQuery_WPF(this).closest('.nav-item').addClass('active');
                jQuery_WPF(this).addClass('active');

                old_selected_tab = jQuery_WPF(this);
            } else {
                old_selected_tab = null;
            }
        }
    
        // prevents to execute the bootstrap functionality
        e.preventDefault();
        e.stopImmediatePropagation();
    });

    jQuery_WPF('a.wpfb-point').click(function (e) {
        var text_id = jQuery_WPF(this).text();
        setTimeout(function(){     
            jQuery_WPF('#comment-'+text_id).focus();
        }, 100);
        });
        
    jQuery_WPF('[data-toggle="popover"]').on('inserted.bs.popover', function () {
        jQuery_WPF('.popover').attr("data-html2canvas-ignore", "true");
        jQuery_WPF('#task_comments_' + id).animate({scrollTop: jQuery_WPF('#task_comments_' + id).prop("scrollHeight")}, 2000);
    });
}

// Check if editor is empty before adding value to textarea.
function  isQuillEmpty( quill ) {
    if ( ( quill.getContents()['ops'] || [] ).length !== 1) {
        return false;
    }
    return quill.getText().trim().length === 0
}