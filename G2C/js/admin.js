jQuery(document).ready(function($){
    const allowPayments = $('#allow_payments');
    const maxPaymentsWrapper = $('#gaddon-setting-row-max_payments');
    const notifyTextArea = $('#tranzila_notify_url');
    const notifyURL = adminLocalized.notify_url;

	$('#submit-activation-license').click(function(){
		const license_key = $('#activation-form #license_key').val();
        const activation_email = $('#activation-form #activation_email').val();
        const product_id = $('#activation-form #product_id').val();
        const params = {action: 'sp_activate_license', license_key: license_key, activation_email: activation_email, product_id: product_id};


        $('#loading-gif').show();
		$.post(php_data.admin_url, params, function(data){
			$('#license-activation-msg').html(data);
		}).always(function(){$('#loading-gif').hide();});
	});

    notifyTextArea.html(notifyURL);

    notifyTextArea.keypress(function (e) {
	    e.preventDefault();
    });

    notifyTextArea.keydown(function (e) {
        if ( e.keyCode === 8 ) {
            alert('Please do not try to delete the notify URL! :(');
            e.preventDefault();

            return false;
        }
    });

	if ( allowPayments.is(':checked') ) {
        maxPaymentsWrapper.show();
    }

    allowPayments.change(function () {
       if ( $(this).is(':checked') ) {
           maxPaymentsWrapper.show();
       } else {
           maxPaymentsWrapper.hide();
       }
    });


});

function adminReactivateSubscription(entryID, feedID) {
    const security = adminLocalized.security;
    const currentURL = window.location.href;

    jQuery('#G2C-reactivate-subscription').addClass('disabled');
    jQuery('#G2C-reactivate-subscription-loader').show();

    jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        dataType: 'json',
        data: {
            action: 'admin_try_reactivate_subscription',
            security: security,
            entry_id: entryID,
            feed_id: feedID
        }
    })
        .success(function (response) {
            if ( typeof response.Response !== 'undefined' ) {
                if ( response.Response === '000' ) {
                    if ( window.confirm('Subscription activated successfully!') ) {
                        window.location.reload();
                    }
                } else {
                    const message = encodeURI(response.error_msg);
                    window.location.href = currentURL + '&G2C_failed_payment&G2C_code=' + response.Response + '&G2C_message=' + message;
                }
            } else if ( typeof response.data.G2C_error !== 'undefined') {
                jQuery('#G2C-reactivate-subscription-wrapper .error-feedback').html(response.data.message).show();
            } else {
                const message = encodeURI(response.response.message);
                window.location.href = currentURL + '&G2C_failed_payment&G2C_code=' + response.response.code + '&G2C_message=' + message;
            }
    })
        .fail(function (jqXHR, textStatus) {
            console.error(textStatus);
    })
        .done(function () {
            jQuery('#G2C-reactivate-subscription').removeClass('disabled');
            jQuery('#G2C-reactivate-subscription-loader').hide();
        });
}

function cancelSubscription(entryID, feedID) {
    const security = adminLocalized.security_cancel_subscription;
    const cancelSubsc =  jQuery('#G2C-cancel-subscription');
    const cancelSubscLoader =  jQuery('#G2C-cancel-subscription-loader');

    cancelSubsc.addClass('disabled');
    cancelSubscLoader.show();

    if ( window.confirm('Are you sure you want to cancel the subscription?') ) {
        jQuery.ajax({
            method: 'POST',
            url: ajaxurl,
            dataType: 'json',
            data: {
                action: 'cancel_subscription',
                security: security,
                entry_id: entryID,
                feed_id: feedID
            }
        })
            .success(function (response) {
                if ( typeof response.error !== 'undefined' && response.error === '0' ) {
                    if ( window.confirm(response.msg) ) {
                        window.location.reload();
                    }
                } else if( typeof response.data.G2C_error !== 'undefined' ) {
                    jQuery('#G2C-cancel-subscription-wrapper .error-feedback').html(response.data.message).show();
                } else {
                    alert('Something went wrong, please try again in a few seconds.');
                }
            })
            .fail(function (jqXHR, textStatus) {
                console.error(textStatus);
            })
            .done(function () {
                cancelSubsc.removeClass('disabled');
                cancelSubscLoader.hide();
            });
    } else {
        cancelSubsc.removeClass('disabled');
        cancelSubscLoader.hide();
    }
}

function resumeSubscription(entryID, feedID) {
    const security = adminLocalized.security_resume_subscription;
    const resSubsc =  jQuery('#G2C-resume-subscription');
    const resSubscLoader =  jQuery('#G2C-resume-subscription-loader');

    resSubsc.addClass('disabled');
    resSubscLoader.show();

    if ( window.confirm('Are you sure you want to resume the subscription?') ) {
        jQuery.ajax({
            method: 'POST',
            url: ajaxurl,
            dataType: 'json',
            data: {
                action: 'resume_subscription',
                security: security,
                entry_id: entryID,
                feed_id: feedID
            }
        })
            .success(function (response) {
                if ( typeof response.error !== 'undefined' && response.error === '0' ) {
                    if ( window.confirm(response.msg) ) {
                        window.location.reload();
                    }
                } else if( typeof response.data.G2C_error !== 'undefined' ) {
                    jQuery('#G2C-resume-subscription-wrapper .error-feedback').html(response.data.message).show();
                } else {
                    alert('Something went wrong, please try again in a few seconds.');
                }
            })
            .fail(function (jqXHR, textStatus) {
                console.error(textStatus);
            })
            .done(function () {
                resSubsc.removeClass('disabled');
                resSubscLoader.hide();
            });
    } else {
        resSubsc.removeClass('disabled');
        resSubscLoader.hide();
    }
}