jQuery(document).ready(function() {
    jQuery(document).on('click', '.avc-deactivate-project', function(e) {
        e.preventDefault();
        jQuery.ajax({
            url:ajax.ajaxurl,
            method: 'POST',
            data:{ 
                action: 'avc_deactivate_collab',
                avc_nonce: avcSettings.avc_nonce,
            },
            beforeSend: function(){},
            success: function() {
                location.reload();
            }
        });
    });
});