<?php
/**
 * Personal area page template.
 */

if ( ! is_user_logged_in() ) {
	wp_safe_redirect(get_site_url() . '/G2C-personal-area');
	exit;
}

$user_id = get_current_user_id();
$subscriptions_reverse = get_user_meta($user_id, 'subscriptions', true);
// reverse the array to get the elements from the newest
$subscriptions = $subscriptions_reverse ? array_reverse($subscriptions_reverse, true) : array();
$homepage_id = get_option('page_on_front');

add_thickbox();

// load Bootstrap
echo '<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">';

get_header();
?>

<div id="G2C-personal-area" class="mt-5" role="main">
	<div class="G2C-personal-area-wrapper">
        <div class="container">
            <?php the_title('<h1 class="entry-title text-center">', '</h1>'); ?>

            <div class="entry-content mb-3">
		        <?php echo $post->post_content ?>
            </div>

            <?php
            if ( isset($_GET['cancelled']) ) {
                if ( $_GET['cancelled'] == 'yes' ) {
                    echo '<div class="alert alert-success text-center" role="alert">';
                    _e('Subscription cancelled.', 'G2C');
                } else {
                    echo '<div class="alert alert-danger text-center" role="alert">';
                    _e('<strong>Error:</strong> failed to cancel subscription, please contact our support.', 'G2C');
                }
                echo '</div>';
            } else if ( isset($_GET['updated_cc']) ) {
                if ( $_GET['updated_cc'] == '1' ) {
                    echo '<div class="alert alert-success text-center" role="alert">';
                    _e('Your credit card details updated.', 'G2C');
                } else {
                    echo '<div class="alert alert-danger text-center" role="alert">';
                    if ( isset($_GET['response_code']) && $_GET['response_code'] != '-1' ) {
                        echo sprintf( __( '<strong>Error:</strong> failed to update the credit card, please check the details again. Error Code: %s', 'G2C' ), $_GET['response_code']);
                    } else {
                        _e( '<strong>Error:</strong> failed to update the credit card, please check the details again.', 'G2C' );
                    }
                }
                echo '</div>';
            }
            ?>

            <?php if ( count( $subscriptions ) > 0 ) : ?>
                <div class="subscs-table-wrapper">
                    <table id="subscriptions" class="table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( '#', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Status', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Campaign', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Amount', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Next Payment', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Payments So Far', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'End Date', 'G2C' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Actions', 'G2C' ); ?></th>
                            </tr>
                        </thead>

                        <tbody>

                            <h2><?php _e('Your Subscriptions', 'G2C') ?></h2>
                            <?php foreach ( $subscriptions as $subscription_id => $subscription ) : ?>
                                <?php
                                // get subscription data
                                $entry_id = rgar($subscription, 'entry_id');
                                $entry = GFAPI::get_entry($entry_id);
                                $next_scheduled = gform_get_meta($entry_id, 'next_scheduled');
                                $billing_cycle_length = gform_get_meta($entry_id, 'G2C_billing_cycle_length');
                                $billing_cycle_unit = gform_get_meta($entry_id, 'G2C_billing_cycle_unit');
                                $recurring_times = gform_get_meta($entry_id, 'G2C_recurring_times');
                                $end_date = gform_get_meta($entry_id, 'G2C_subscription_end_date');
                                $campaign_id = rgar($subscription, 'campaign_id');

                                if ( $next_scheduled ) {
                                    $next_scheduled_date = date('d/m/Y H:i:s', intval($next_scheduled));
                                }
                                ?>
                                <tr id="subscription-<?php echo $subscription_id ?>" class="subscription-item">
                                    <td class="entry-id" scope="row">
                                        <?php echo rgar($entry, 'id'); ?>
                                    </td>
                                    <td class="campaign-name <?php echo strtolower(rgar($entry, 'payment_status')) ?>">
                                        <?php echo gf_tranzila()->translate_status(rgar($entry, 'payment_status')); ?>
                                    </td>
                                    <td class="campaign-name">
                                        <?php
                                        if ( -1 != $campaign_id ) {
                                            $campaign = get_post($campaign_id);
                                            echo '<a href="'.esc_url(get_the_permalink($campaign_id)).'"><h5>' . esc_html($campaign->post_title) . '</h5></a>';
                                        }
                                        ?>
                                    </td>
                                    <td class="amount">
                                        <?php echo GFCommon::to_money(rgar($subscription, 'amount')); ?>
                                    </td>
                                    <td class="next-payment" valign="middle">
                                        <?php
                                        if ( isset($next_scheduled_date) && rgar($entry, 'payment_status') == 'Active' ) {
                                            echo $next_scheduled_date;
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td class="payments-so-far">
                                        <?php echo gform_get_meta($entry_id, 'payments_so_far') ?>
                                    </td>
                                    <td class="end-date">
                                        <?php
                                        if ( $end_date && rgar($entry, 'payment_status') == 'Active' ) {
                                            echo $end_date;
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td class="actions">
                                        <div class="update-cc">
                                            <a role="button" class="thickbox btn btn-info w-100" href="<?php echo plugins_url('includes/update-cc-popup.php', G2C_FILE) . "?entry_id={$entry_id}&nonce=" . wp_create_nonce('update_cc_card') . "&TB_iframe=true&width=600&height=550" ?>"><?php esc_html_e('Update credit card','G2C')?></a>
                                        </div>
                                        <div class="cancel-subscription">
                                            <?php if ( rgar($entry, 'payment_status') == 'Active' ) : ?>
                                                <form id="cancel-<?php echo $subscription_id  ?>-miniform" action="<?php echo plugins_url('includes/frontend-cancel-subscription.php', G2C_FILE) ?>" method="post" class="cancel-miniform mb-0" onsubmit="return confirm('<?php _e('Do you really want to cancel the subscription?', 'G2C') ?>');">
                                                    <input type="submit" class="cancel-btn mt-1" name="cancel_subscription" value="<?php esc_attr_e('Cancel', 'G2C') ?>" data-subscription-id="<?php echo $subscription_id ?>">
                                                    <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry_id) ?>">
                                                    <input type="hidden" name="feed_id" value="<?php echo esc_attr(gform_get_meta($entry_id, 'G2C_subscription_feed_id')) ?>">
                                                    <?php wp_nonce_field('cancel_subscription', 'cancel_subsc_nonce'); ?>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php do_action('G2C_personal_area_actions', $entry) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php
            // @see themes/hamonym/functions.php
            if ( $homepage_id && function_exists('hamonym_campaigns_slider') ) {
	            hamonym_campaigns_slider((int)$homepage_id, __('Active Campaigns', 'G2C'));
            }
            ?>

        </div>
    </div>
</div>
<?php get_footer() ?>
