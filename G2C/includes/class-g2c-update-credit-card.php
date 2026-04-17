<?php
/**
 * All functionality related to users updating their credit card info, including sending email notifications once a month.
 */

namespace Planwize\Subscription;
use Planwize\G2C_Subscription;

class G2C_Update_Credit_Card{

	protected static $_instance;

	public static function instance(){
		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	private function __construct() {
		add_action('G2C_check_expdates', array($this, 'send_notifications'));
	}

	public function send_notifications(){
		set_time_limit(0);

		$logger = G2C_Subscription::instance()->get_logger();

		$user_query = new \WP_User_Query(array(
			'meta_key' => 'subscriptions',
			'meta_compare' => 'EXISTS'
		));

		$users = $user_query->get_results();

		foreach ( $users as $user ) {
			$subscriptions = get_user_meta($user->ID, 'subscriptions', true);

			if ( count($subscriptions) > 0 ) foreach ( $subscriptions as $subscription ) {
			    $entry = \GFAPI::get_entry(rgar($subscription, 'entry_id'));

			    if ( rgar($entry, 'payment_status') != 'Active' ) {
			        continue;
                }

				$current_year = date('y', time());
				$current_month = date('m', time());
				$expdate = rgar($subscription, 'tranzila_cc_expdate');
				$exp_year = substr($expdate, -2);
				$exp_month = substr($expdate, 0, 2);

				if ( $current_year != $exp_year || $exp_month <= $current_month ) {
					continue;
				}

				if ( ((intval($exp_month) - intval($current_month)) == 2) || ((intval($exp_month) - intval($current_month)) == 1 ) ) {

					$campaign_name = rgar($subscription, 'campaign_id') == -1 ? false : get_post(rgar($subscription, 'campaign_id'))->post_title;
					$to = $user->user_email;
					$subject = __('Your credit card is about to expire', 'G2C');

					ob_start();
					?>
					<div style="text-align: <?php echo is_rtl() ? 'right' : 'left' ?>;">
						<p><?php echo sprintf( __( 'Hi %s,', 'G2C' ), $user->display_name ) ?></p>

						<p>
							<?php echo sprintf(
								__( 'Please update your payment details here: <a href="%s" target="_blank">Update Payment</a>', 'G2C' ),
								get_site_url() . '/G2C-personal-area'
							) ?>
						</p>

						<p><?php _e('Regards,<br/>Hamonym team.', 'G2C') ?></p>
					</div>
					<?php
					$message = ob_get_clean();

					wp_mail($to, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
				}
			}
		}
	}

	/**
     * NEW: Cardcom iframe (replaces Tranzila)
     */
	public function get_update_cardcom_iframe($entry_id, $iframe_width, $iframe_height){

		ob_start();
		?>

        <iframe id="cardcom_iframe"
                width="<?php echo $iframe_width?>"
                height="<?php echo $iframe_height ?>"
                scrolling="no"
                style="border: none;"></iframe>

        <script>
            jQuery(function(){

                jQuery.ajax({
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    method: 'POST',
                    data: {
                        action: 'g2c_create_payment',
                        entry_id: <?php echo $entry_id; ?>
                    },
                    success: function(res){
                        if(res.success){
                            jQuery('#cardcom_iframe').attr('src', res.data.url);
                        }
                    }
                });

            });
        </script>

		<?php

        return ob_get_clean();
    }
}

G2C_Update_Credit_Card::instance();