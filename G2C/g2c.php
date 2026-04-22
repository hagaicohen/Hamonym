<?php
/*
Plugin Name: G2C - CARD COM (FINAL WORKING)
*/

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path(__FILE__) . 'admin/g2c-admin.php';

/* =========================
   LOGGER (תמיד עובד)
========================= */
function g2c_log($msg, $data = null) {
    $file = WP_CONTENT_DIR . '/g2c.log';

    if ($data !== null) {
        $msg .= ' => ' . print_r($data, true);
    }

    error_log('[G2C] ' . $msg);
    file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}


/* =========================
   HANDLE RETURN
========================= */
add_action('template_redirect', function() {

    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!isset($_GET['lowprofilecode'])) return;

    $lp       = sanitize_text_field($_GET['lowprofilecode']);
    $response = $_GET['ResponseCode'] ?? '';

    global $wpdb;

    $entry_id = $wpdb->get_var($wpdb->prepare("
        SELECT entry_id 
        FROM {$wpdb->prefix}gf_entry_meta
        WHERE meta_key = 'lowprofile_id'
        AND meta_value = %s
        LIMIT 1
    ", $lp));

    if (!$entry_id) return;

    $entry = GFAPI::get_entry($entry_id);
    if (is_wp_error($entry) || empty($entry)) return;

    if ($response == '0' && $entry['payment_status'] !== 'Paid') {

        GFAPI::update_entry_property($entry_id, 'payment_status', 'Paid');
        gform_update_meta($entry_id, 'transaction_id', $lp);

        g2c_log('HANDLE RETURN START', $entry_id);

        // timezone
        $tz = get_option('timezone_string');
        if (empty($tz)) {
            $tz = 'Asia/Jerusalem';
        }
        date_default_timezone_set($tz);

        // FEED
        $feeds = GFAPI::get_feeds(null, $entry['form_id']);
        $feed  = !empty($feeds) ? $feeds[0] : null;
        $meta  = $feed['meta'];

        // סכום
        $sum = floatval(preg_replace('/[^\d.]/', '', rgar($entry, '151')));

        // מיפוי
        $first_name = rgar($entry, $meta['billingInformation_firstName']);
        $last_name  = rgar($entry, $meta['billingInformation_lastName']);
        $email      = rgar($entry, $meta['billingInformation_email']);
        $city       = rgar($entry, $meta['billingInformation_city']);

        // fallback
        if (empty($first_name)) $first_name = rgar($entry, '9.3');
        if (empty($last_name))  $last_name  = rgar($entry, '9.6');
        if (empty($email))      $email      = rgar($entry, '10');
        if (empty($city))       $city       = rgar($entry, '11.3');

        $post = [
            'Response'   => '000',
            'sum'        => $sum,
            'index'      => $lp,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'city'       => $city,
            'contact'    => trim($first_name . ' ' . $last_name),
            'date'       => current_time('mysql'),
        ];

        g2c_log('POST BEFORE HOOK', $post);

        try {
            do_action('gform_tranzila_payment_complete', $post, $entry, $feed);
            g2c_log('HOOK FIRED SUCCESS');
        } catch (Throwable $e) {
            g2c_log('HOOK ERROR', $e->getMessage());
        }

        // 🔥 FALLBACK CONTRIBUTORS
        try {

            $campaign_id = url_to_postid($entry['source_url']);
            g2c_log('CAMPAIGN ID', $campaign_id);

            if ($campaign_id) {

                $contributors = get_post_meta($campaign_id, 'contributors', true);
                g2c_log('EXISTING CONTRIBUTORS', $contributors);

                if (!is_array($contributors)) {
                    $contributors = [];
                }

                $contributors[] = [
                    'name' => $post['contact'],
                    'sum'  => $post['sum']
                ];

                update_post_meta($campaign_id, 'contributors', $contributors);

                g2c_log('FALLBACK CONTRIBUTOR ADDED', $contributors);
            }

        } catch (Throwable $e) {
            g2c_log('FALLBACK ERROR', $e->getMessage());
        }
    }

    $clean_url = strtok(home_url($_SERVER['REQUEST_URI']), '?');
    wp_safe_redirect($clean_url);
    exit;
});


/* =========================
   CREATE PAYMENT
========================= */
add_action('wp_ajax_g2c_create_payment', 'g2c_create_payment');
add_action('wp_ajax_nopriv_g2c_create_payment', 'g2c_create_payment');

function g2c_create_payment() {

    $form_id  = intval($_POST['form_id'] ?? 0);
    $entry_id = intval($_POST['entry_id'] ?? 0);

    if (!$form_id || !$entry_id) wp_die();

    $entry = GFAPI::get_entry($entry_id);
    if (is_wp_error($entry) || empty($entry)) wp_die();

    $settings = get_option('g2c_settings_' . $form_id, []);

    $amount = floatval($entry[$settings['amount']] ?? 0);
    $email  = $entry[$settings['email']] ?? '';
    $name   = $entry[$settings['first_name']] ?? '';

    $success_url = !empty($settings['success_url']) ? $settings['success_url'] : home_url('/');
    $fail_url    = !empty($settings['fail_url'])    ? $settings['fail_url']    : home_url('/');

    $response = wp_remote_post(
        "https://secure.cardcom.solutions/api/v11/LowProfile/Create",
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                "TerminalNumber" => $settings['g2c_terminal'],
                "ApiName"        => $settings['g2c_api_user'],
                "ApiPassword"    => $settings['g2c_api_password'],
                "Amount"         => $amount,
                "CustomerName"   => $name,
                "CustomerEmail"  => $email,
                "ReturnValue"    => (string)$entry_id,
                "SuccessRedirectUrl" => $success_url,
                "FailedRedirectUrl"  => $fail_url
            ])
        ]
    );

    $json = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($json['LowProfileId']) || empty($json['Url'])) {
        wp_die();
    }

    gform_update_meta($entry_id, 'lowprofile_id', $json['LowProfileId']);

    echo json_encode(['url' => $json['Url']]);
    wp_die();
}


/* =========================
   IFRAME
========================= */
add_action('wp_footer', function() {
?>
<script>
jQuery(function($){

    let opened = false;

    $(document).on('gform_confirmation_loaded', function(event, formId){
        console.log('G2C: FORM CONFIRMATION LOADED', formId);

        if (opened) return;
        opened = true;

        const entryId = $('#g2c-entry-id').data('id');
        console.log('G2C: ENTRY ID', entryId);

        if (!entryId) return;

        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action: 'g2c_create_payment',
            form_id: formId,
            entry_id: entryId
        }, function(res){

            if (!res.url) {
                console.log('G2C: NO URL FROM SERVER');
                return;
            }

            console.log('G2C: OPEN IFRAME', res.url);

            $('body').append(
                '<div id="g2c-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;background:#fff;">' +
                    '<iframe id="g2c-frame" src="'+res.url+'" style="width:100%;height:100%;border:none;"></iframe>' +
                '</div>'
            );

            const interval = setInterval(function(){

                try {
                    const frame = document.getElementById('g2c-frame');
                    const url = frame.contentWindow.location.href;

                    if (url.includes('ResponseCode') || url.includes('success') || url.includes('fail')) {
                        clearInterval(interval);
                        window.location.href = url;
                    }

                } catch(e) {}

            }, 1000);

        }, 'json');
    });

});
</script>
<?php
});


/* =========================
   ENTRY ID
========================= */
add_filter('gform_confirmation', function($confirmation, $form, $entry){
    return $confirmation . '<div id="g2c-entry-id" data-id="' . esc_attr($entry['id']) . '"></div>';
}, 10, 3);