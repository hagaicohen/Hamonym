jQuery(document).ready(function ($) {
    $('.cancel-subscription-btn').click(function (e) {
        e.preventDefault();
        subscriptionID = $(this).data('subscription-id');
        if ( window.confirm('Are you sure you want to cancel this subscription?') ) {
            $('#cancel-' + subscriptionID + '-miniform').submit();
        }
    });
});