jQuery(document).ready(function($) {
    if (typeof nwg_ajax === 'undefined') {
        return;
    }
    
    var ajaxNonce = nwg_ajax.nonce;
    var ajaxurl = nwg_ajax.ajaxurl;

    // 1. Direct Message Handler
    $('#nwg_btn_direct_send').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var statusBox = $('#nwg_direct_send_status');
        var phone = $('#nwg_direct_phone').val().trim();
        var message = $('#nwg_direct_message').val().trim();

        if (!phone) {
            alert('Please enter a valid client phone number.');
            return;
        }
        if (!message) {
            alert('Please enter a message to text the client.');
            return;
        }

        btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0; vertical-align:middle;"></span> Sending...');
        statusBox.hide().html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'notify_with_gowa_direct_send',
                phone: phone,
                message: message,
                nonce: ajaxNonce
            },
            success: function(res) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> Send Message via WhatsApp');
                if (res.success) {
                    statusBox.html('<div class="notice notice-success inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#155724;">' + res.data.message + '</p></div>').fadeIn();
                } else {
                    var errMsg = (res.data && res.data.message) ? res.data.message : 'Unknown error';
                    statusBox.html('<div class="notice notice-error inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#721c24;">Send Failed: ' + errMsg + '</p></div>').fadeIn();
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> Send Message via WhatsApp');
                var errText = error ? error : (xhr.responseText ? xhr.responseText : status);
                statusBox.html('<div class="notice notice-error inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#721c24;">Server AJAX Error: ' + errText + '</p></div>').fadeIn();
            }
        });
    });

    // 2. Gateway Connection Check Handler
    $('#nwg_btn_check_connection').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var statusBox = $('#nwg_conn_status_box');

        btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0; vertical-align:middle;"></span> Checking GOWA Gateway...');
        statusBox.hide().html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'notify_with_gowa_check_connection',
                nonce: ajaxNonce
            },
            success: function(res) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top:-2px;"></span> Check GOWA Connection');
                if (res.success) {
                    statusBox.html('<div class="notice notice-success inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#155724;">' + res.data.message + '</p></div>').fadeIn();
                } else {
                    var errMsg = (res.data && res.data.message) ? res.data.message : 'Unknown error';
                    statusBox.html('<div class="notice notice-error inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#721c24;">Gateway Connection Failed: ' + errMsg + '</p></div>').fadeIn();
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top:-2px;"></span> Check GOWA Connection');
                var errText = error ? error : (xhr.responseText ? xhr.responseText : status);
                statusBox.html('<div class="notice notice-error inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#721c24;">Server AJAX Error: ' + errText + '</p></div>').fadeIn();
            }
        });
    });
});
