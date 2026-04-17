<?php
/**
 * That script handles subscription cancel when requested from the personal area page.
 */

// load WP
define('WP_USE_THEMES', false);
require '../../../../wp-load.php';

//verify nonce
if ( ! isset($_POST['cancel_subsc_nonce']) || ! wp_verify_nonce($_POST['cancel_subsc_nonce'], 'cancel_subscription') ) {
	print 'Trying to hack ah? try again...';
	exit;
}

if ( ! isset($_POST['entry_id']) || ! isset($_POST['feed_id']) ) {
	exit;
}

$user = wp_get_current_user();
$G2C = gf_tranzila();
$cancelled = \Planwize\G2C_Subscription::instance()->cancel_subscription($_POST['entry_id'], $_POST['feed_id']);

if ( $cancelled ) {
	$G2C->add_note($_POST['entry_id'], sprintf(__('User %s cancelled the subscription.', 'G2C'), $user->display_name), 'success');
	$cancelled_str = 'yes';
} else {
	$G2C->add_note($_POST['entry_id'], sprintf(__('User %s tried to cancel the subscription, but it failed.', 'G2C'), $user->display_name), 'error');
	$cancelled_str = 'no';
}

// redirect the user back to the personal area page
wp_safe_redirect( get_home_url() . '/G2C-personal-area?cancelled=' . $cancelled_str );
exit;
