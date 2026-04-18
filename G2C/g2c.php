<?php
/*
Plugin Name: G2C SAFE TEST
*/

add_action('init', function() {
    error_log('🔥 G2C PLUGIN LOADED');
});

if ( ! defined( 'ABSPATH' ) ) exit;
require_once plugin_dir_path(__FILE__) . 'admin/g2c-admin.php';

// =======================
// 🔥 CREATE LOWPROFILE (API)
// =======================
function g2c_get_value($entry, $field_id) {

    if (!$field_id) return '';

    if (isset($entry[$field_id]) && $entry[$field_id] !== '') {
        return $entry[$field_id];
    }

    foreach ($entry as $key => $value) {
        if (strpos((string)$key, $field_id . '.') === 0 && $value !== '') {
            return $value;
        }
    }

    return '';
}

function g2c_map_entry($entry, $settings) {

    $amount = g2c_get_value($entry, $settings['amount'] ?? '');
    $email  = g2c_get_value($entry, $settings['email'] ?? '');
    $name   = g2c_get_value($entry, $settings['first_name'] ?? '');

    $amount = floatval($amount);
    if ($amount <= 0) $amount = 10;

    if (!$email) $email = 'test@test.com';
    if (!$name)  $name  = 'לקוח';

    return [
        'amount' => $amount,
        'email'  => $email,
        'name'   => $name,
    ];
}

function g2c_create_lowprofile($settings) {

    $url = "https://secure.cardcom.solutions/api/v11/LowProfile/Create";

    $data = [
        "TerminalNumber" => $settings['g2c_terminal'] ?? '',
        "ApiName"        => $settings['g2c_api_user'] ?? '',
        "ApiPassword"    => $settings['g2c_api_password'] ?? '',

        // 🔧 FIX – שימוש בערכים מהטופס
        "Amount"         => $settings['amount_real'] ?? 100,
        "CustomerName"   => $settings['name_real'] ?? 'לקוח',
        "CustomerEmail"  => $settings['email_real'] ?? 'test@test.com',

        "Operation"      => "Debit",
        "ISOCoinId"      => 1,
        "Language"       => "he",

        "SuccessRedirectUrl" => "https://google.com",
        "FailedRedirectUrl"  => "https://google.com"
    ];

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($data),
        'timeout' => 30
    ]);

    // 🔧 FIX – לא echo ולא die
    if (is_wp_error($response)) {
        error_log('Cardcom ERROR: ' . $response->get_error_message());
        return false;
    }

    $raw = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    // 🔧 FIX – החזרת URL אמיתי
    if (!empty($json['Url'])) {
        return $json['Url'];
    }

    error_log('Cardcom BAD RESPONSE: ' . $raw);
    return false;
}

add_filter('gform_confirmation', function($confirmation, $form, $entry, $ajax){

    // 🔧 FIX – לא להרוג את ה-flow
    $confirmation .= '<div id="g2c-entry-id" data-id="' . esc_attr($entry['id']) . '"></div>';

    return $confirmation;

}, 10, 4);


// =======================
// 🔥 SHORTCODE PAYMENT
// =======================


add_action('wp_enqueue_scripts', function() {

    wp_add_inline_script('jquery', "

        console.log('🔥 G2C SCRIPT LOADED');

        jQuery(document).on('gform_confirmation_loaded', function(event, formId){

            console.log('🔥 EVENT FIRED, formId:', formId);

            const entryEl = jQuery('#g2c-entry-id');
            console.log('ENTRY ELEMENT:', entryEl);

            const entryId = entryEl.data('id');
            console.log('ENTRY ID:', entryId);

            if (!entryId) {
                console.log('❌ אין entry_id');
                return;
            }

            console.log('🚀 SENDING AJAX...');

            jQuery.ajax({
                url: '" . admin_url('admin-ajax.php') . "',
                method: 'POST',
                data: {
                    action: 'g2c_create_payment',
                    form_id: formId,
                    entry_id: entryId
                },
                success: function(res){

                    console.log('✅ AJAX SUCCESS');
                    console.log('RESPONSE RAW:', res);
                    console.log('TYPE:', typeof res);

                    if (!res) {
                        console.log('❌ RESPONSE EMPTY');
                        return;
                    }

                    res = res.trim(); // 🔧 FIX קריטי

                    if (!res || !res.startsWith('http')) {
                        console.log('❌ RESPONSE NOT URL');
                        return;
                    }

                    console.log('✅ URL VALID, CONTINUING...');

                    var iframe = jQuery('#g2c_payment_frame');
                    console.log('IFRAME FOUND:', iframe.length);

                    if (!iframe.length) {

                        console.log('➕ CREATING IFRAME');

                        jQuery('body').append(
                            '<div id=\"g2c_iframe_wrap\" style=\"position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;display:flex;justify-content:center;align-items:center;\">' +
                                '<iframe id=\"g2c_payment_frame\" style=\"width:90%;height:90%;border:none;background:#fff;border-radius:10px;\"></iframe>' +
                                '<div id=\"g2c_close\" style=\"position:absolute;top:20px;left:20px;color:#fff;font-size:20px;cursor:pointer;\">✖</div>' +
                            '</div>'
                        );

                        iframe = jQuery('#g2c_payment_frame');

                        jQuery(document).on('click', '#g2c_close', function(){
                            console.log('❌ CLOSE CLICKED');
                            jQuery('#g2c_iframe_wrap').remove();
                        });
                    }

                    console.log('🎯 SETTING IFRAME SRC');

                    iframe.attr('src', res);

                    console.log('✅ DONE');

                },
                error: function(err){
                    console.log('❌ AJAX ERROR:', err);
                }
            });

        });

    ");

});
add_action('wp_ajax_g2c_create_payment', 'g2c_create_payment');
add_action('wp_ajax_nopriv_g2c_create_payment', 'g2c_create_payment');

function g2c_create_payment() {

    // 🔥 בדיקות בסיסיות
    if (empty($_POST['form_id']) || empty($_POST['entry_id'])) {
        echo 'ERROR: missing params';
        wp_die();
    }

    $form_id  = intval($_POST['form_id']);
    $entry_id = intval($_POST['entry_id']);

    // 🔥 שליפת entry
    $entry = GFAPI::get_entry($entry_id);

    if (is_wp_error($entry) || empty($entry)) {
        echo 'ERROR: invalid entry';
        wp_die();
    }

    // 🔥 שליפת settings
    $settings = get_option('g2c_settings_' . $form_id, []);

    if (empty($settings)) {
        echo 'ERROR: missing settings';
        wp_die();
    }

    // 🔥 שליפה גנרית (בלי mapper מסובך כרגע)
    $amount_field = $settings['amount'] ?? '';
    $email_field  = $settings['email'] ?? '';
    $name_field   = $settings['first_name'] ?? '';

    $amount = $amount_field && isset($entry[$amount_field]) ? $entry[$amount_field] : 0;
    $email  = $email_field  && isset($entry[$email_field])  ? $entry[$email_field]  : '';
    $name   = $name_field   && isset($entry[$name_field])   ? $entry[$name_field]   : '';

    // 🔥 ניקוי
    $amount = floatval($amount);
    if ($amount <= 0) $amount = 100;

    if (!$email) $email = 'test@test.com';
    if (!$name)  $name  = 'לקוח';

    // 🔥 הזרקה ל-settings
    $settings['amount_real'] = $amount;
    $settings['email_real']  = $email;
    $settings['name_real']   = $name;

    // 🔥 קריאה ל-Cardcom
    $url = g2c_create_lowprofile($settings);

    if (!$url) {
        echo 'ERROR: no url';
        wp_die();
    }

    echo $url;
    wp_die();
}

if (false) {
add_action('wp_footer', function() {
?>
<script>
jQuery(function($){

    $(document).on('submit', 'form[id^="gform_"]', function(e){

            if (!window.g2c_enable_submit_handler) {
                return;
            }

            var formId = $(this).attr('id').replace('gform_', '');
            console.log('FORM ID:', formId);

            setTimeout(function(){

                console.log('Sending form_id to server:', formId);

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: {
                        action: 'g2c_create_payment',
                        form_id: formId
                    },
                    success: function(res){

                        if (!res) return;

                        $('body').html(
                            '<div style="padding:20px">' +
                            '<iframe src="' + res + '" width="100%" height="700"></iframe>' +
                            '</div>'
                        );

                    }
                });

            }, 800);

        });

    });
</script>
<?php
});
}
