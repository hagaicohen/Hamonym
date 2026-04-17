<?php
/**
 * This class handle all the functionality for G2C subscription payments.
 *
 * @author Avishay Guttman
 * @date 2/8/2018
 */

/*
 * I wrote this code just before leaving planwize, hope you'll be able to maintain it without me.
 */

namespace Planwize;
use Katzgrau, Psr;


class G2C_Subscription {

	const TRANZILA_BASE_API_URL = "https://secure5.tranzila.com/cgi-bin";
	const TIMEZONE_IDENTIFIER = 'Asia/Jerusalem';

	protected static $_instance;

	private $logger = null;

	private $log_path;


	/**
	 * Create a singleton for the class.
	 *
	 * @return G2C_Subscription
	 */
	public static function instance(){
		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}

		return self::$_instance;
	}

	private function __construct() {
		date_default_timezone_set(self::TIMEZONE_IDENTIFIER);

		$this->log_path = wp_upload_dir()['basedir'];

		if( class_exists('Katzgrau\KLogger\Logger') && class_exists('Psr\Log\LogLevel') ) {
			$this->logger = new Katzgrau\KLogger\Logger( $this->log_path, Psr\Log\LogLevel::DEBUG, array( 'filename' => 'G2C-subscription.log' ) );
		}

		add_action('signup_subscription_payment_complete', array($this, 'schedule_subscription'), 10, 3);
		add_action('G2C_subscription_cron', array($this, 'process_subscription_payment'), 10, 2);
		add_action('gform_payment_details', array($this, 'add_subscription_entry_details'), 10, 2);
		add_action('wp_ajax_admin_try_reactivate_subscription', array($this, 'admin_try_reactivate_subscription'));
		add_action('wp_ajax_cancel_subscription', array($this, 'cancel_subscription_request'));
		add_action('wp_ajax_resume_subscription', array($this, 'resume_subscription_request'));
		add_action('admin_notices', array($this, 'add_admin_notices'));
		add_filter('gform_entry_detail_meta_boxes', array($this, 'add_subscription_action_metabox'), 10, 3);
		add_action('gform_user_registered', array($this, 'save_registered_user_id'), 10, 3);
		add_action('G2C_subscription_scheduled', array($this, 'add_subscribed_user_metadata'), 10, 2);
		add_action('gform_entry_detail_content_after', array($this, 'print_transactions_to_entry_page'), 10, 2);
		add_filter('gform_is_delayed_pre_process_feed', array($this, 'delay_user_registration_feed'), 10, 4);
	}

	/**
     * Set the log path.
     *
	 * @param $log_pah
     *
     * @return void
	 */
	public function set_log_path($log_pah){
	    if ( is_dir($log_pah) ) {
		    $this->log_path = $log_pah;
	    }
    }

	/**
	 * Get the logger instance.
     *
     * @return bool|Katzgrau\KLogger\Logger|\GFLogging
	 */
    public function get_logger(){
	    if ( ! is_null($this->logger) ) {
	        return $this->logger;
        }

        // TODO find alternative logger that for sure is not null to return here
        return false;
    }

	/**
     * schedule subscription after a successful initial payment.
     *
     * @hooked signup_subscription_payment_complete
     *
	 * @param $post
	 * @param $entry
	 * @param $feed
	 */
	public function schedule_subscription($post, $entry, $feed){
		$billing_cycle_length = isset($feed['meta']['billingCycle_length']) ? $feed['meta']['billingCycle_length'] : '';
		$billing_cycle_unit = isset($feed['meta']['billingCycle_unit']) ? $feed['meta']['billingCycle_unit'] : '';
		$recurring_times = isset($feed['meta']['recurringTimes']) ? $feed['meta']['recurringTimes'] : '';
		$args = array($entry['id'], $feed['id']);

		// attache a schedule event to the just-created entry
		switch ( $billing_cycle_unit ) {
			case 'day':
				$time_str = '+' . $billing_cycle_length . ' days';
				$start = strtotime($time_str);
				wp_schedule_event($start, $billing_cycle_length . '_day', 'G2C_subscription_cron', $args);
				break;
			case 'week':
				$time_str = '+' . $billing_cycle_length . ' weeks';
				$start = strtotime($time_str);
				wp_schedule_event($start, $billing_cycle_length . '_week', 'G2C_subscription_cron', $args);
				break;
			case 'month':
				$time_str = '+' . $billing_cycle_length . ' months';
				$start = strtotime($time_str);
				wp_schedule_event($start, $billing_cycle_length . '_month', 'G2C_subscription_cron', $args);
				break;
			case 'year':
				$time_str = '+' . $billing_cycle_length . ' years';
				$start = strtotime($time_str);
				wp_schedule_event($start, $billing_cycle_length . '_year', 'G2C_subscription_cron', $args);
				break;
			default: // TODO just for testing
				break;
		}

		// get the interval length. we will have a deviation of a few microseconds, but it's ok
		$next_scheduled = wp_next_scheduled('G2C_subscription_cron', $args);
		$interval_length = $next_scheduled - time();

		// save data to the entry
		gform_add_meta($entry['id'], 'next_scheduled', $next_scheduled);
		gform_add_meta($entry['id'], 'G2C_interval_length', $interval_length);
		gform_add_meta($entry['id'], 'G2C_subscription_feed_id', $feed['id']);
		gform_add_meta($entry['id'], 'G2C_billing_cycle_length', $billing_cycle_length);
		gform_add_meta($entry['id'], 'G2C_billing_cycle_unit', $billing_cycle_unit);
		gform_add_meta($entry['id'], 'G2C_recurring_times', $recurring_times);
		if ( $recurring_times != '0' ) {
			$total_seconds_to_end = $interval_length * (intval($recurring_times)-1);
			$end_date = date('d/m/Y H:i:s', strtotime('+' . $total_seconds_to_end . ' seconds'));
			gform_add_meta($entry['id'], 'G2C_subscription_end_date', $end_date);
		} else {
			gform_add_meta($entry['id'], 'G2C_subscription_end_date', 'none');
		}

		// process registration add-on delayed feed if there is one
		$feed_delayed = gform_get_meta($entry['id'], 'feed_delayed');
		if ( $feed_delayed ) {
			$feed_found = false;
		    $feeds = \GFAPI::get_feeds(null, $entry['form_id']);
		    $form = \GFAPI::get_form($entry['form_id']);
		    foreach ( $feeds as $feed ) {
		        if ( rgar($feed, 'addon_slug') == 'gravityformsuserregistration' && rgar(rgar($feed, 'meta'), 'feedType') == 'create' ){
		            $feed_found = true;
		            break;
                }
            }

            if ( $feed_found ) {
	            \GF_User_Registration::get_instance()->process_feed( $feed, $entry, $form );
            }

			gform_delete_meta( $entry['id'], 'feed_delayed' );
        }

		$this->save_transaction_id($entry['id'], $post['index']);

		do_action('G2C_subscription_scheduled', $post, $entry, $feed);
	}

	/**
	 * Delay user registration feed process so the registration will occur only after successful tranzila payment.
	 *
     * @hooked gform_is_delayed_pre_process_feed
     *
	 * @param $is_delayed
	 * @param $form
	 * @param $entry
	 * @param $slug
	 *
	 * @return bool
	 */
	public function delay_user_registration_feed($is_delayed, $form, $entry, $slug){
		if ( $slug != 'gravityformsuserregistration' ) {
			return $is_delayed;
		}

		$has_G2C_feed = false;
		$create_user_action = false;
		$feeds = \GFAPI::get_feeds(null, $form['id']);

		// find whether we have a tranzila feed in the current form, and whether the registration add-on feed for that form is for creating users and not update their info
		foreach ( $feeds as $feed ) {
			if ( rgar($feed, 'addon_slug') == 'G2C' ) {
				$has_G2C_feed = true;
			} else if ( rgar($feed, 'addon_slug') == 'gravityformsuserregistration' && rgar(rgar($feed, 'meta'), 'feedType') == 'create' ) {
				$create_user_action = true;
            }
		}

		if ( ! $has_G2C_feed || ! $create_user_action ) {
			return $is_delayed;
		}

		// if we got here, we have both G2C and registration add-on feeds, delay the process
        $feed_delayed = gform_get_meta($entry['id'], 'feed_delayed');
		if ( ! $feed_delayed ) {
			gform_add_meta($entry['id'], 'feed_delayed', 1);
        }

		return true;
	}

	/**
	 * Process subscription payment, send request to tranzila and handling the response.
	 *
     * @hooked G2C_subscription_cron
     *
	 * @param $entry_id
	 * @param $feed_id
	 *
	 * @return void|array
	 */
	public function process_subscription_payment($entry_id, $feed_id){
		$G2C = gf_tranzila();
		$entry = \GFAPI::get_entry($entry_id);
		$form = \GFAPI::get_form($entry['form_id']);
		$feed = $G2C->get_feed($feed_id);
		$submission_data = $G2C->get_submission_data($feed, $form, $entry);
		$recurring_times = gform_get_meta($entry_id, 'G2C_recurring_times');
		$payments_so_far = gform_get_meta($entry_id, 'payments_so_far');
		$next_scheduled = wp_next_scheduled('G2C_subscription_cron', array($entry_id, $feed_id));

		// collect data for subscription payment
		$tranzila_args  = $G2C->get_tranzila_args($submission_data, $entry, $feed, 'payment');
		$tranzila_args['supplier'] = $feed['meta']['gf_tokens_username'];
		$tranzila_args['TranzilaPW'] = $feed['meta']['gf_tokens_password'];
		$tranzila_args['TranzilaTK'] = gform_get_meta($entry_id, 'TranzilaTK');
		$tranzila_args['expdate'] = gform_get_meta($entry_id, 'expdate');
		$tranzila_args['response_return_format'] = 'json';
        $tranzila_args['cred_type'] = '1';

		// update next scheduled
		gform_update_meta($entry_id, 'next_scheduled', $next_scheduled);

		$this->logger->info('Before sending request for subscription payment => ' . print_r($tranzila_args, true));

		$tranzila_res = $this->send_request_for_subscription_payment($tranzila_args, $entry_id, $feed_id, $payments_so_far);

		// handle tranzila response
		if ( isset($tranzila_res['Response']) && $tranzila_res['Response'] == '000' ) {
			$this->logger->info('Subscription payment proceeded successfully => ' . print_r($tranzila_res, true));

			\GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Active' );

			$this->save_transaction_id($entry_id, $tranzila_res['index']);

			do_action('G2C_subscription_payment_complete', $entry_id, $tranzila_res, $payments_so_far);
		} else {
			$recurring_retry = isset($feed['meta']['recurringRetry']) &&  $feed['meta']['recurringRetry'] == '1' ? true : false;

			// maybe we need to try bill again, don't do this if it is an ajax request
			if ( $recurring_retry && ! wp_doing_ajax() ) {
				$this->retry_payment($tranzila_args, $entry_id, $feed_id, $recurring_times, $payments_so_far, $G2C);
			} else {
			    gform_update_meta($entry_id, 'next_scheduled', '-');
				if ( ! wp_doing_ajax() ) {
				    if ( isset($tranzila_res['Response']) ) {
					    $this->logger->error( 'Subscription payment failed for entry #' . $entry_id . ' => ' . print_r( $tranzila_res, true ) );
					    $G2C->add_note( $entry_id, sprintf(__( 'Subscription payment failed, Error Code: %s, Error MessageL %s. See the error log file for more info at wp-content/uploads/G2C-subscription.log', 'G2C' ), $tranzila_res['Response'], $tranzila_res['error_msg']), 'error' );
                    } else {
					    $this->logger->error( 'Subscription payment failed for entry #' . $entry_id . ' => ' . print_r( $tranzila_res['body'], true ) );
					    $G2C->add_note( $entry_id, sprintf(__( 'Subscription payment failed, Error Code: %s, Error MessageL %s. See the error log file for more info at wp-content/uploads/G2C-subscription.log', 'G2C' ), $tranzila_res['response']['code'], $tranzila_res['response']['message']), 'error' );
                    }

					\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Failed' );
					if ( ! gform_get_meta($entry_id, 'subscription_failed') ) {
						gform_add_meta( $entry_id, 'subscription_failed', 1 );
					}

					// clear the scheduled event
					wp_clear_scheduled_hook( 'G2C_subscription_cron', array( $entry_id, $feed_id ) );

					do_action('G2C_subscription_payment_failed', $entry_id, $tranzila_res, $payments_so_far);
				}
			}

			// return the results if it's an ajax request
			if ( wp_doing_ajax() ) {
				return $tranzila_res;
			}

			return;
		}

		// if we got here, the subscription payment happen successfully
		if ( wp_doing_ajax() ) {
			return $tranzila_res;
		}

		// check if this was the last payment
		$stop_subscription = $this->maybe_stop_subscription($entry_id, $feed_id, $recurring_times);

		if ( $stop_subscription ) {
			$G2C->add_note($entry_id, __('Subscription payment finished.', 'G2C'), 'success');
		}

	}

	/**
	 * Try to bill the customer again after failed attempt.
	 *
	 * @param $args array Args to send to tranzila
	 * @param $entry_id
	 * @param $feed_id
	 * @param $recurring_times
	 * @param $payments_so_far
	 * @param $G2C
	 */
	private function retry_payment($args, $entry_id, $feed_id, $recurring_times,  $payments_so_far, $G2C ) {
		$this->logger->info('Try to bill customer again after failed attempt on entry #' . $entry_id . '...');

		$tranzila_res = $this->send_request_for_subscription_payment($args, $entry_id, $feed_id, $payments_so_far);

		if ( isset($tranzila_res['Response']) && $tranzila_res['Response'] == '000' ) {
			$this->logger->info('Subscription payment proceeded successfully => ' . print_r($tranzila_res, true));

			\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Active' );

			$this->save_transaction_id($entry_id, $tranzila_res['index']);

			do_action('G2C_subscription_payment_complete', $entry_id, $tranzila_res, $payments_so_far);
		} else {
			gform_update_meta( $entry_id, 'next_scheduled', '-' );
			if ( isset($tranzila_res['Response']) ) {
				$this->logger->error( 'Subscription payment failed for entry #' . $entry_id . ' => ' . print_r( $tranzila_res, true ) );
				$G2C->add_note( $entry_id, sprintf(__( 'Subscription payment failed, Error Code: %s, Error MessageL %s. See the error log file for more info at wp-content/uploads/G2C-subscription.log', 'G2C' ), $tranzila_res['Response'], $tranzila_res['error_msg']), 'error' );
			} else {
				$this->logger->error( 'Subscription payment failed for entry #' . $entry_id . ' => ' . print_r( $tranzila_res['body'], true ) );
				$G2C->add_note( $entry_id, sprintf(__( 'Subscription payment failed, Error Code: %s, Error MessageL %s. See the error log file for more info at wp-content/uploads/G2C-subscription.log', 'G2C' ), $tranzila_res['response']['code'], $tranzila_res['response']['message']), 'error' );
			}

			\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Failed' );
			if ( ! gform_get_meta($entry_id, 'subscription_failed') ) {
				gform_add_meta( $entry_id, 'subscription_failed', 1 );
			}

			// clear the scheduled event
			wp_clear_scheduled_hook( 'G2C_subscription_cron', array( $entry_id, $feed_id ) );

			do_action('G2C_subscription_payment_failed', $entry_id, $tranzila_res, $payments_so_far);

			return;
		}

		$stop_subscription = $this->maybe_stop_subscription($entry_id, $feed_id, $recurring_times);

		if ( $stop_subscription ) {
			$G2C->add_note($entry_id, __('Subscription payment finished.', 'G2C'), 'success');
		}
	}

	/**
	 * Check if the subscripting should be stopped.
	 *
	 * @param $entry_id
	 * @param $feed_id
	 * @param $recurring_times
	 *
	 * @return bool
	 */
	private function maybe_stop_subscription($entry_id, $feed_id, $recurring_times){
		$payments_so_far = gform_get_meta($entry_id, 'payments_so_far');

		if ( $payments_so_far == $recurring_times ) {
			wp_clear_scheduled_hook('G2C_subscription_cron', array($entry_id, $feed_id));
			gform_add_meta($entry_id, 'subscription_finished', 1);
			gform_update_meta($entry_id, 'next_scheduled', '-');
			\GFAPI::update_entry_property($entry_id, 'payment_status', 'Expired');

			return true;
		}

		return false;
	}

	/**
	 * Send request to tranzila for subscription transaction.
	 *
	 * @param $args
	 * @param $entry_id
	 * @param $payments_so_far
	 *
	 * @return array|bool|mixed|object
	 */
	private function send_request_for_subscription_payment($args, $entry_id, $feed_id, $payments_so_far){
		if ( empty($args) ) return false;

		$headers = array('Content-type' => 'application/x-www-form-urlencoded');

		$express_terminal = gform_get_meta($entry_id, 'express_terminal') && intval(gform_get_meta($entry_id, 'express_terminal')) == 1;

		$target_url = $express_terminal ? self::TRANZILA_BASE_API_URL . '/tranzila71pme.cgi' : self::TRANZILA_BASE_API_URL . '/tranzila71u.cgi';

		// Fire away the payment request, we get the response as JSON
		$tranzila_response = wp_remote_post($target_url, array(
			'method'    => 'POST',
			'timeout'   => 45,
			'headers'   => $headers,
			'body'      => $args,
		) );

		$response = json_decode($tranzila_response['body'], true);

		if ( isset($response['Response']) ) {
		    if ( $response['Response'] == '000' ) {
			    gform_update_meta( $entry_id, 'payments_so_far', ( $payments_so_far + 1 ) );
		    }

			return $response;
		}

		return $tranzila_response;
	}

	/**
	 * Add more stuff to the subscription entry details metabox.
     *
     * @hooked gform_payment_details
	 *
	 * @param $form_id
	 * @param $entry
	 */
	public function add_subscription_entry_details($form_id, $entry){
        $feed_id = gform_get_meta($entry['id'], 'G2C_subscription_feed_id');
		$next_scheduled = wp_next_scheduled('G2C_subscription_cron', array($entry['id'], $feed_id));
		if ( ! $next_scheduled ) {
			$next_scheduled = '-';
        } else {
			$next_scheduled = gform_get_meta($entry['id'], 'next_scheduled');
        }

		$subscription_end_date = gform_get_meta($entry['id'], 'G2C_subscription_end_date');
		$payments_so_far = gform_get_meta($entry['id'], 'payments_so_far');

		if ( $entry['payment_status'] == 'Expired' ) {
	        $subscription_end_date = __('Done', 'G2C');
		} else if ( $entry['payment_status'] == 'Failed' || $entry['payment_status'] == 'Cancelled' || $entry['payment_status'] == 'Processing' ) {
			$subscription_end_date = '-';
		} else if ( $subscription_end_date == 'none' ) {
			$subscription_end_date = __('None', 'G2C');
        }

		if ( $next_scheduled != '-' ) {
			$next_payment = date('d/m/Y H:i:s', intval($next_scheduled));
		} else {
			$next_payment = '-';
		}
		?>
		<div id="gf_subscription_payments_so_far" class="gf_payment_detail">
			<?php esc_html_e('Payments So Far:', 'G2C') ?>
			<span id="gform_subscription_payments_so_far"><?php echo esc_html($payments_so_far) ?></span>
		</div>
		<div id="gf_subscription_next_payment" class="gf_payment_detail">
			<?php esc_html_e('Next Payment:', 'G2C') ?>
			<span id="gform_subscription_next_payment"><?php echo esc_html($next_payment) ?></span>
		</div>
		<div id="gf_subscription_end_date" class="gf_payment_detail">
			<?php esc_html_e('End Date:', 'G2C') ?>
			<span id="gform_subscription_end_date"><?php echo esc_html($subscription_end_date) ?></span>
		</div>
		<?php
	}

	/**
	 * Attempt to reactivate failed subscription, initiated by the admin.
	 *
     * @hooked wp_ajax_admin_try_reactivate_subscription
	 */
	public function admin_try_reactivate_subscription(){
		check_ajax_referer('admin_reactivate_subscription', 'security');

		$response = array();
		$entry_id = $_POST['entry_id'];
		$feed_id = $_POST['feed_id'];
        $G2C = gf_tranzila();

		if ( $entry_id == '-1' || is_null($G2C) ) {
		    $this->logger->error(__FUNCTION__ . '(): was not able to locate the entry.');
			$response['G2C_error'] = 1;
			$response['message'] = __('Error: Was not able to locate the entry.', 'G2C');
            wp_send_json_error($response);
		}

		$response = $this->process_subscription_payment($entry_id, $feed_id);

		if ( isset($response['Response']) && $response['Response'] == '000' ) {
			$payments_so_far = gform_get_meta($entry_id, 'payments_so_far');
			$billing_cycle_length = gform_get_meta($entry_id, 'G2C_billing_cycle_length');
			$billing_cycle_unit = gform_get_meta($entry_id, 'G2C_billing_cycle_unit');
			$recurring_times = gform_get_meta($entry_id, 'G2C_recurring_times');
			$interval_length = gform_get_meta($entry_id, 'G2C_interval_length');
			gform_update_meta($entry_id, 'subscription_failed', 0);

			// reschedule the subscription if needed
			if ( $recurring_times != $payments_so_far ) {
                wp_schedule_event(time() + $interval_length, $billing_cycle_length . '_' . $billing_cycle_unit, 'G2C_subscription_cron', array($entry_id, $feed_id));
				$next_scheduled = wp_next_scheduled('G2C_subscription_cron', array($entry_id, $feed_id));
				gform_update_meta($entry_id, 'next_scheduled', $next_scheduled);

				// update the end date
				$total_seconds_to_end = $interval_length * (intval($recurring_times)-$payments_so_far);
				$end_date = date('d/m/Y H:i:s', strtotime('+' . $total_seconds_to_end . ' seconds'));
				gform_update_meta($entry_id, 'G2C_subscription_end_date', $end_date);
				\GFAPI::update_entry_property($entry_id, 'payment_status', 'Active');
				$G2C->add_note($entry_id, __('Subscription reactivated by admin.', 'G2C'), 'success');
            } else {
			    // if it was the last payment, just mark the subscription as Expired, The schedule already cleared when the payment was failed.
				gform_add_meta($entry_id, 'subscription_finished', 1);
				gform_update_meta($entry_id, 'next_scheduled', '-');
				\GFAPI::update_entry_property($entry_id, 'payment_status', 'Expired');
				$G2C->add_note($entry_id, __('Subscription payment finished.', 'G2C'), 'success');
            }
		} else {
		    if ( isset($response['Response']) ) {
			    $G2C->add_note( $entry_id, sprintf( __( 'Attempt to activate the subscription failed. Error Code: %s, Error Message: %s', 'G2C' ), $response['Response'], $response['error_msg'] ), 'error' );
		    } else {
			    $G2C->add_note( $entry_id, sprintf( __( 'Attempt to activate the subscription failed. Error Code: %s, Error Message: %s', 'G2C' ), $response['response']['code'], $response['response']['message'] ), 'error' );
            }
		}

		echo json_encode($response);

		wp_die();
	}

	/**
	 * Handle ajax request to cancel a subscription.
	 */
	public function cancel_subscription_request(){
		check_ajax_referer('cancel_subscription', 'security');

		$entry_id = $_POST['entry_id'];
		$feed_id = $_POST['feed_id'];
		$G2C = gf_tranzila();

		if ( $entry_id == '-1' || is_null($G2C) ) {
			$this->logger->error(__FUNCTION__ . '(): was not able to locate the entry.');
			$response['G2C_error'] = 1;
			$response['message'] = __('Error: Was not able to locate the entry.', 'G2C');
			wp_send_json_error($response);
		}

		$cancelled = $this->cancel_subscription($entry_id, $feed_id, true);

		if ( $cancelled ) {
			$G2C->add_note($entry_id, __('Subscription canceled by the admin.', 'G2C'), 'success');
			wp_send_json(array('error' => '0', 'msg' => __('The subscription canceled.', 'G2C')));
        }

		wp_send_json(array('error' => '1', 'msg' => __('Was not able to cancel the subscription', 'G2C')));

	    wp_die();
    }

	/**
	 * Handle ajax request to resume a subscription.
	 */
    public function resume_subscription_request(){
	    check_ajax_referer('resume_subscription', 'security');

	    $entry_id = $_POST['entry_id'];
	    $feed_id = $_POST['feed_id'];
	    $G2C = gf_tranzila();

	    if ( $entry_id == '-1' || is_null($G2C) ) {
	        $this->logger->error(__FUNCTION__ . '():  Was not able to locate the entry.');
		    $response['G2C_error'] = 1;
		    $response['message'] = __('Error: was not able to locate the entry.', 'G2C');
		    wp_send_json_error($response);
	    }

	    $resumed = $this->resume_subscription($entry_id, $feed_id, true);
	    if ( $resumed ) {
		    $G2C->add_note($entry_id, __('Subscription resumed by the admin.', 'G2C'), 'success');
            wp_send_json(array('error' => '0', 'msg' => __('The subscription resumed.', 'G2C')));
        } else {
		    wp_send_json(array('error' => '0', 'msg' => __('Was not able to resume the subscription', 'G2C')));
        }

	    wp_die();
    }

	/**
     * Cancel a subscription.
     *
	 * @param $entry_id
	 * @param $feed_id
	 * @param bool $ajax //TODO check if we need this parameter
	 *
	 * @return bool
	 */
    public function cancel_subscription($entry_id, $feed_id, $ajax = false){
        $entry = \GFAPI::get_entry($entry_id);
        if ( $entry['payment_status'] == 'Cancelled' ) {
            $this->logger->error(__FUNCTION__ . '(): The subscription already canceled. Entry id: #' . $entry_id);
            return false;
        }

	    wp_clear_scheduled_hook('G2C_subscription_cron', array($entry_id, $feed_id));
	    gform_update_meta($entry_id, 'next_scheduled', '-');
	    \GFAPI::update_entry_property($entry_id, 'payment_status', 'Cancelled');

	    do_action('G2C_subscription_cancelled', $entry, $feed_id);

	    return true;
    }

	/**
     * Resume a cancelled subscription.
     *
	 * @param $entry_id
	 * @param $feed_id
	 * @param bool $ajax
     *
     * @return bool
	 */
    private function resume_subscription($entry_id, $feed_id, $ajax = false){
        $entry = \GFAPI::get_entry($entry_id);

        if ( false !== wp_next_scheduled('G2C_subscription_cron', array($entry_id, $feed_id)) ) {
            $this->logger->error(__FUNCTION__ . '(): Tried to resume a subscription which already scheduled. Entry id: #' . $entry_id);
            return false;
        }

        if ( $entry['payment_status'] == 'Active' ) {
	        $this->logger->error(__FUNCTION__ . '(): Subscription is already active. Entry id: #' . $entry_id);
	        return false;
        }

	    $payments_so_far = gform_get_meta($entry_id, 'payments_so_far');
	    $billing_cycle_length = gform_get_meta($entry_id, 'G2C_billing_cycle_length');
	    $billing_cycle_unit = gform_get_meta($entry_id, 'G2C_billing_cycle_unit');
	    $recurring_times = gform_get_meta($entry_id, 'G2C_recurring_times');
        $interval_length = gform_get_meta($entry_id, 'G2C_interval_length');

        // reschedule
        wp_schedule_event(time() + $interval_length, $billing_cycle_length . '_' . $billing_cycle_unit, 'G2C_subscription_cron', array($entry_id, $feed_id));

        $next_scheduled = wp_next_scheduled('G2C_subscription_cron', array($entry_id, $feed_id));

	    if ( ! $next_scheduled ) {
		    $this->logger->error(__FUNCTION__ . '(): Was not able to schedule event. Entry id: #' . $entry_id);
	        return false;
        }

	    gform_update_meta($entry_id, 'next_scheduled', $next_scheduled);

	    // update the end date
	    $total_seconds_to_end = $interval_length * (intval($recurring_times)-$payments_so_far);
	    $end_date = date('d/m/Y H:i:s', strtotime('+' . $total_seconds_to_end . ' seconds'));
	    gform_update_meta($entry_id, 'G2C_subscription_end_date', $end_date);
	    \GFAPI::update_entry_property($entry_id, 'payment_status', 'Active');

	    return true;
    }

	/**
	 * Add admin notices.
     *
     * @hooked admin_notices
	 */
	public function add_admin_notices(){
		if ( isset($_GET['G2C_failed_payment']) ) {
			if ( isset($_GET['G2C_code']) && isset($_GET['G2C_message']) ) {
				$class = 'notice notice-error';
				$message = sprintf(__('Payment Failed! Error code: %s, Error Message: %s', 'G2C'), $_GET['G2C_code'], $_GET['G2C_message']);

				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			}
		}
	}

	/**
     * Add subscription-actions metabox.
     *
	 * @param $meta_boxes
	 * @param $entry
	 * @param $form
	 *
	 * @return mixed
	 */
	public function add_subscription_action_metabox($meta_boxes, $entry, $form){
	    if ( $entry['transaction_type'] == 2 ) {
		    $meta_boxes['subscription_action'] = array(
			    'title'    => esc_html__( 'Subscription Actions', 'G2C' ),
			    'callback' => array( 'Planwize\G2C_Subscription', 'meta_box_subscription_actions' ),
			    'context'  => 'side',
                'priority' => 'high',
		    );
	    }

	    return $meta_boxes;
    }

	/**
     * The content inside subscription_action metabox.
     *
	 * @param $args array args supplied to the callback.
	 */
    public static function meta_box_subscription_actions($args){
        $entry = $args['entry'];
	    $feed_id = gform_get_meta($entry['id'], 'G2C_subscription_feed_id');
        ?>

	    <?php if ( $entry['payment_status'] == 'Active' ) : ?>
            <div id="G2C-cancel-subscription-wrapper" class="G2C-subscription-actions">
                <input id="G2C-cancel-subscription" type="button" class="button" value="<?php esc_attr_e('Cancel Subscription', 'G2C') ?>" onclick="cancelSubscription('<?php echo isset($_GET['lid']) ? $_GET['lid'] : -1 ?>', '<?php echo $feed_id  ?>');"/>
                <img id="G2C-cancel-subscription-loader" style="display: none; vertical-align: middle;" src="<?php echo plugins_url('images/loading.gif', __FILE__) ?>" alt="Loading"/>
                <div class="error-feedback" style="display: none;border: 1px solid red;padding: .2rem;background-color: #FFEBE8;"></div>
            </div>
	    <?php endif ?>

	    <?php if ( $entry['payment_status'] == 'Cancelled' ) : ?>
            <div id="G2C-resume-subscription-wrapper" class="G2C-subscription-actions">
                <input id="G2C-resume-subscription" type="button" class="button" value="<?php esc_attr_e('Resume Subscription', 'G2C') ?>" onclick="resumeSubscription('<?php echo isset($_GET['lid']) ? $_GET['lid'] : -1 ?>', '<?php echo $feed_id  ?>');"/>
                <img id="G2C-resume-subscription-loader" style="display: none; vertical-align: middle;" src="<?php echo plugins_url('images/loading.gif', __FILE__) ?>" alt="Loading"/>
                <div class="error-feedback" style="display: none;border: 1px solid red;padding: .2rem;background-color: #FFEBE8;"></div>
            </div>
	    <?php endif ?>

        <?php if ( $entry['payment_status'] == 'Failed' ) : ?>
			<div id="G2C-reactivate-subscription-wrapper" class="G2C-subscription-actions">
				<input id="reactivate-subscription" type="button" class="button" value="<?php esc_attr_e('Reactivate Subscription', 'G2C') ?>" onclick="adminReactivateSubscription('<?php echo isset($_GET['lid']) ? $_GET['lid'] : -1 ?>', '<?php echo $feed_id  ?>');"/>
                <img id="G2C-reactivate-subscription-loader" style="display: none; vertical-align: middle;" src="<?php echo plugins_url('images/loading.gif', __FILE__) ?>" alt="Loading"/>
				<p class="description" style="margin-top: 0; padding-top: 0"><?php esc_html_e('Manually try to reactivate failed subscription.', 'G2C') ?></p>
                <div class="error-feedback" style="display: none;border: 1px solid red;padding: .2rem;background-color: #FFEBE8;"></div>
			</div>
		<?php endif ?>

    <?php
    }

	/**
     * Save the just-registered user.
     *
     * @hooked gform_user_registered
     *
	 * @param $user_id
	 * @param $feed
	 * @param $entry
     *
     * @return void
	 */
    public function save_registered_user_id($user_id, $feed, $entry){
        if ( is_user_logged_in() ) {
            return;
        }

        $feeds = \GFAPI::get_feeds(null, $entry['form_id']);

        // extract the relevant feed
	    if ( count($feeds) > 0 ) foreach ( $feeds as $feed ) {
		    if ( $feed['addon_slug'] == 'G2C' ) {
			    $transaction_type = $feed['meta']['transactionType'];
			    break;
		    }
	    }

	    if ( ! isset($transaction_type) || $transaction_type != 'subscription' ) {
	        return;
        }

        gform_add_meta($entry['id'], 'G2C_subscribed_user', $user_id);
    }

	/**
     * Add subscription meta data to the just-submitted user.
     *
	 * @param $post
	 * @param $entry
	 */
    public function add_subscribed_user_metadata($post, $entry){
        /*
         * If a user has been created with this submission, set the subscription data to him.
         * Otherwise, set the subscription data to the logged-in user
         * If both fails, do nothing.
         */
	    $user_id = gform_get_meta( $entry['id'], 'G2C_subscribed_user' );

	    if ( ! $user_id ) {
		    $user_id = isset($post['current_user_id']) ? $post['current_user_id'] : 0;

		    if ( ! $user_id ) {
			    return;
		    }
        }

	    // get campaign ID if exists
        $form = \GFAPI::get_form($entry['form_id']);
        foreach ( $form['fields'] as $field ) {
            if ( $field['type'] == 'hidden' && $field['inputName'] == 'campaign_id' ) {
                $campaign_id = $entry[$field['id']];
                break;
            }
        }

        $subscription_data = array(
            'entry_id' => $entry['id'],
            'campaign_id' => isset($campaign_id) ? $campaign_id : -1,
            'amount' => $post['sum'],
            'tranzila_cc_token' =>isset($post['TranzilaTK']) ? $post['TranzilaTK'] : '',
            'tranzila_cc_expdate' => ($date = gform_get_meta($entry['id'], 'expdate')) ? $date : '',
        );

	    $subscriptions = get_user_meta($user_id, 'subscriptions', true);

	    if ( $subscriptions ) {
		    $subscriptions[$post['index']] = $subscription_data;
	        update_user_meta($user_id, 'subscriptions', $subscriptions);
        } else {
	        add_user_meta($user_id, 'subscriptions', array($post['index'] => $subscription_data));
        }

        $this->logger->info(__FUNCTION__ . '(): Subscription #' . $post['index'] . ' added to user #' . $user_id);
    }

	/**
     * Save successful transactions as entry meta
     *
	 * @param $entry_id
	 * @param $transaction_id
     *
     * @return void
	 */
    private function save_transaction_id($entry_id, $transaction_id){
        $cur_transactions = gform_get_meta($entry_id, 'G2C_successful_transactions');

        if ( ! $cur_transactions ) {
            gform_add_meta($entry_id, 'G2C_successful_transactions', array($transaction_id));
            return;
        }

	    $cur_transactions[] = $transaction_id;
        gform_update_meta($entry_id, 'G2C_successful_transactions', $cur_transactions);
    }

	/**
     * @hooked gform_entry_detail_content_after
     *
	 * @param $form
	 * @param $entry
	 */
    public function print_transactions_to_entry_page($form, $entry){
	    $transactions = gform_get_meta($entry['id'], 'G2C_successful_transactions');

        if ( ! $transactions ) {
            return;
        }

        $count = count($transactions);
        $i = 1;

        echo '<div class="entry-transactions postbox"><div class="inside">';
        echo '<h4 style="border-bottom: 1px solid gainsboro;padding-bottom:0.2rem;margin: .8rem 0;">' . __('Related tranzila transactions: ', 'G2C') . '</h4>';
        echo '<div class="inside">';

        foreach ( $transactions as $transaction ) {
            echo '<span class="entry-transaction">' . esc_html($transaction) . '</span>';

            if ( $i != $count ) {
                echo '&nbsp;&#44;';
            }

            $i++;
        }


        echo '</div></div></div>';
    }

}

G2C_Subscription::instance();
