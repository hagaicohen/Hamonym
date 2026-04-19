<?php
/*
Plugin Name: G2C SAFE TEST
*/

add_action('init', function() {

    if (!isset($_GET['g2c_done'])) return;

    echo '<script>
        console.log("🔥 G2C RETURN PAGE LOADED");

        try {
            window.top.postMessage({ type: "G2C_DONE" }, "*");
            console.log("✅ G2C_DONE SENT");
        } catch(e) {
            console.log("❌ POSTMESSAGE ERROR", e);
        }
    </script>';

    echo "RETURN OK";
    exit;
});

if ( ! defined( 'ABSPATH' ) ) exit;
require_once plugin_dir_path(__FILE__) . 'admin/g2c-admin.php';

add_action('wp_ajax_g2c_callback', 'g2c_callback');
add_action('wp_ajax_nopriv_g2c_callback', 'g2c_callback');

function g2c_callback() {

    try {

        $raw = file_get_contents('php://input');

        if (!$raw) {
            error_log('❌ CALLBACK EMPTY');
            echo json_encode(['error' => 'EMPTY']);
            wp_die();
        }

        $json = json_decode($raw, true);

        if (!is_array($json)) {
            error_log('❌ CALLBACK BAD JSON: ' . $raw);
            echo json_encode(['error' => 'BAD_JSON']);
            wp_die();
        }

        error_log('🔥 CALLBACK DATA: ' . $raw);

        $entry_id = intval($json['ReturnValue'] ?? 0);

        if (!$entry_id) {
            error_log('❌ CALLBACK NO ENTRY');
            echo json_encode(['error' => 'NO_ENTRY']);
            wp_die();
        }

        // 🔥 הכי חשוב — לא להפיל אם משהו נכשל
        if (function_exists('g2c_mark_as_paid')) {
            g2c_mark_as_paid($entry_id, $json);
        } else {
            error_log('❌ g2c_mark_as_paid NOT FOUND');
        }

        echo json_encode(['ok' => true]);

    } catch (Throwable $e) {

        error_log('💣 CALLBACK CRASH: ' . $e->getMessage());

        echo json_encode(['error' => 'CRASH']);
    }

    wp_die();
}

add_action('wp_ajax_g2c_check', 'g2c_check');
add_action('wp_ajax_nopriv_g2c_check', 'g2c_check');

function g2c_check() {

    error_log('🔥 CHECK FUNCTION RUNNING');

    // =========================
    // 🔥 קבלת entry_id מה-frontend
    // =========================
    $entry_id = intval($_POST['entry_id'] ?? 0);

    if (!$entry_id) {
        echo json_encode(['error' => 'NO_ENTRY']);
        wp_die();
    }

    // =========================
    // 🔥 שליפת LowProfileId אמיתי
    // =========================
    $lp = gform_get_meta($entry_id, 'lowprofile_id');

    if (!$lp) {
        error_log("❌ NO LP FOR ENTRY {$entry_id}");
        echo json_encode(['error' => 'NO_LP']);
        wp_die();
    }

    // =========================
    // 🔥 שליפת entry
    // =========================
    $entry = GFAPI::get_entry($entry_id);

    if (is_wp_error($entry) || empty($entry)) {
        echo json_encode(['error' => 'INVALID_ENTRY']);
        wp_die();
    }

    // =========================
    // 🔥 שליפת settings דינמית
    // =========================
    $form_id  = $entry['form_id'];
    $settings = get_option('g2c_settings_' . $form_id, []);

    $terminal = $settings['g2c_terminal'] ?? '';
    $api_user = $settings['g2c_api_user'] ?? '';

    if (!$terminal || !$api_user) {
        error_log("❌ MISSING API SETTINGS FOR FORM {$form_id}");
        echo json_encode(['error' => 'NO_API_SETTINGS']);
        wp_die();
    }

    // =========================
    // 🔥 קריאה ל-Cardcom
    // =========================
    $url = "https://secure.cardcom.solutions/api/v11/LowProfile/GetLpResult";

    $data = [
        "TerminalNumber" => $terminal,
        "ApiName"        => $api_user,
        "LowProfileId"   => $lp
    ];

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('❌ G2C CHECK ERROR: ' . $response->get_error_message());
        echo json_encode(['error' => 'REQUEST_FAILED']);
        wp_die();
    }

    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    error_log('🔥 G2C RESULT: ' . $raw);

    // =========================
    // 🔥 בדיקת הצלחה
    // =========================
    if (($json['ResponseCode'] ?? -1) == 0) {

        // ✔ עדכון entry
        g2c_mark_as_paid($entry_id, $json);

        // =========================
        // 🔥 בניית gf_tranzila_return
        // =========================
        $ids = $form_id . '|' . $entry_id;

        $data = [
            'ids'  => $ids,
            'hash' => wp_hash('ids=' . $ids)
        ];

        $encoded = base64_encode(http_build_query($data));

        echo json_encode([
            'ResponseCode' => 0,
            'redirect'     => home_url('/?gf_tranzila_return=' . $encoded)
        ]);

    } else {

        echo json_encode([
            'ResponseCode' => $json['ResponseCode'] ?? -1
        ]);
    }

    wp_die();
}
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

    // =========================
    // 🔥 בדיקות בסיסיות
    // =========================
    $terminal = $settings['g2c_terminal'] ?? '';
    $api_user = $settings['g2c_api_user'] ?? '';
    $api_pass = $settings['g2c_api_password'] ?? '';
    $entry_id = intval($settings['entry_id'] ?? 0);

    if (!$terminal || !$api_user || !$entry_id) {
        error_log('❌ G2C CREATE: Missing required settings');
        return false;
    }

    // =========================
    // 🔥 ניקוי נתונים
    // =========================
    $amount = floatval($settings['amount_real'] ?? 0);
    if ($amount <= 0) $amount = 1;

    $name  = trim($settings['name_real'] ?? '');
    $email = trim($settings['email_real'] ?? '');

    if (!$name)  $name  = 'Customer';
    if (!$email) $email = get_bloginfo('admin_email');

    // =========================
    // 🔥 בניית request
    // =========================
    $data = [
        "TerminalNumber"      => $terminal,
        "ApiName"             => $api_user,
        "ApiPassword"         => $api_pass,

        "Amount"              => $amount,
        "CustomerName"        => $name,
        "CustomerEmail"       => $email,

        "Operation"           => "Debit",
        "ISOCoinId"           => 1,
        "Language"            => "he",

        // 🔥 קישור בין Cardcom ל-entry
        "ReturnValue"         => (string)$entry_id,

        // 🔥 חזרה ל-iframe שלך
        "SuccessRedirectUrl"  => home_url('/?g2c_done=1'),
        "FailedRedirectUrl"   => home_url('/?g2c_done=1'),

        // 🔥 callback
        "WebHookUrl"          => admin_url('admin-ajax.php?action=g2c_callback'),
    ];

    // =========================
    // 🔥 קריאה ל-API
    // =========================
    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('❌ G2C CREATE ERROR: ' . $response->get_error_message());
        return false;
    }

    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    error_log('🔥 G2C CREATE RESPONSE: ' . $raw);

    // =========================
    // 🔥 שמירת LowProfileId
    // =========================
    if (!empty($json['LowProfileId'])) {

        gform_update_meta($entry_id, 'lowprofile_id', $json['LowProfileId']);

        error_log("💾 LP SAVED: Entry {$entry_id} -> " . $json['LowProfileId']);
    } else {
        error_log("❌ NO LowProfileId RETURNED");
    }

    // =========================
    // 🔥 החזרת URL
    // =========================
    if (!empty($json['Url'])) {
        return $json['Url'];
    }

    error_log('❌ G2C CREATE BAD RESPONSE: ' . $raw);
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

    $ajax_url = admin_url('admin-ajax.php');

    wp_add_inline_script('jquery', "

        console.log('🔥 G2C SCRIPT LOADED');

        function g2cFetch(entryId) {
            return fetch('{$ajax_url}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=g2c_check&entry_id=' + entryId
            }).then(r => r.json());
        }

        function startPolling(entryId) {

            let handled = false;

            const interval = setInterval(() => {

                if (handled) return;

                console.log('🔄 POLLING CHECK...');

                g2cFetch(entryId)
                .then(data => {

                    if (data.ResponseCode === 0 && data.redirect) {

                        handled = true;
                        clearInterval(interval);

                        jQuery('#g2c_iframe_wrap').remove();

                        window.location.href = data.redirect;
                    }

                })
                .catch(() => {});

            }, 2000);
        }

        jQuery(document).on('gform_confirmation_loaded', function(event, formId){

            const entryId = jQuery('#g2c-entry-id').data('id');

            if (!entryId) return;

            jQuery.ajax({
                url: '{$ajax_url}',
                method: 'POST',
                data: {
                    action: 'g2c_create_payment',
                    form_id: formId,
                    entry_id: entryId
                },
                success: function(res){

                    if (!res) return;

                    res = res.trim();

                    if (!res.startsWith('http')) return;

                    if (!jQuery('#g2c_iframe_wrap').length) {

                        jQuery('body').append(
                            '<div id=\"g2c_iframe_wrap\" style=\"position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;display:flex;justify-content:center;align-items:center;\">' +
                                '<iframe id=\"g2c_iframe\" style=\"width:90%;height:90%;border:none;background:#fff;border-radius:10px;\"></iframe>' +
                                '<div id=\"g2c_close\" style=\"position:absolute;top:20px;left:20px;color:#fff;font-size:20px;cursor:pointer;\">✖</div>' +
                            '</div>'
                        );

                        jQuery(document).on('click', '#g2c_close', function(){
                            jQuery('#g2c_iframe_wrap').remove();
                        });
                    }

                    jQuery('#g2c_iframe').attr('src', res);

                    startPolling(entryId);
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

    // =======================
    // 🔥 שליפה אחידה
    // =======================
    $amount = g2c_get_value($entry, $settings['amount'] ?? '');
    $email  = g2c_get_value($entry, $settings['email'] ?? '');
    $name   = g2c_get_value($entry, $settings['first_name'] ?? '');

    // 🔥 ניקוי
    $amount = floatval($amount);
    if ($amount <= 0) $amount = 100;

    if (!$email) $email = 'test@test.com';
    if (!$name)  $name  = 'לקוח';

    // =======================
    // 🔥 מציאת campaign (בלי hardcode)
    // =======================
    $campaign = '';

    // 1. חיפוש חכם בשדות
    foreach ($entry as $key => $val) {
        if (!empty($val) && stripos($key, 'campaign') !== false) {
            $campaign = $val;
            break;
        }
    }

    // 2. fallback (אם כבר נשמר)
    if (!$campaign) {
        $campaign = gform_get_meta($entry_id, 'campaignid');
    }

    // 3. ניקוי אם URL
    if ($campaign && filter_var($campaign, FILTER_VALIDATE_URL)) {
        $campaign = trim(parse_url($campaign, PHP_URL_PATH), '/');
        $parts = explode('/', $campaign);
        $campaign = end($parts);
    }

    // 4. ניקוי slug
    if ($campaign) {
        $campaign = sanitize_title($campaign);

        // 🔥 שמירה ל-entry (קריטי להמשך flow)
        gform_update_meta($entry_id, 'campaignid', $campaign);
    }

    error_log("🎯 G2C CREATE PAYMENT: Entry {$entry_id}, Campaign: {$campaign}");

    // =======================
    // 🔥 הזרקה ל-settings
    // =======================
    $settings['amount_real'] = $amount;
    $settings['email_real']  = $email;
    $settings['name_real']   = $name;
    $settings['entry_id']    = $entry_id;

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
add_action('wp_footer', function() {
?>
<script>
jQuery(function($){

    let handled = false;
    let interval = null;

    function handlePaymentDone(entryId, source) {

    if (!entryId) {
        console.log('❌ NO ENTRY ID IN handlePaymentDone');
        return;
    }

    console.log('💥 PAYMENT DONE via:', source);

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=g2c_check&entry_id=' + entryId
    })
    .then(r => r.json())
    .then(data => {

        console.log('💥 RESULT', data);

        jQuery('#g2c_iframe_wrap').remove();

        if (data.ResponseCode === 0 && data.redirect) {
            window.location.href = data.redirect;
        } else {
            window.location.href = '/failed';
        }

    })
    .catch(err => {
        console.error('❌ CHECK ERROR', err);
        window.location.href = '/failed';
    });
}

    // 🔥 אחרי שליחת טופס
    $(document).on('gform_confirmation_loaded', function(event, formId){

        if (!window.g2c_enable_submit_handler) {
            return;
        }

        console.log('FORM ID:', formId);

        var entryId = $('#g2c-entry-id').data('id');

        console.log('ENTRY ID:', entryId);

        if (!entryId) {
            console.log('❌ אין entry_id');
            return;
        }

        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            method: 'POST',
            data: {
                action: 'g2c_create_payment',
                form_id: formId,
                entry_id: entryId
            },
            success: function(res){

                if (!res) {
                    console.log('❌ NO RESPONSE');
                    return;
                }

                res = res.trim();

                if (!res.startsWith('http')) {
                    console.log('❌ NOT URL');
                    return;
                }

                console.log('💥 IFRAME URL:', res);

                if (!$('#g2c_iframe_wrap').length) {

                    $('body').append(
                        '<div id="g2c_iframe_wrap" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;display:flex;justify-content:center;align-items:center;">' +
                            '<iframe id="g2c_iframe" style="width:90%;height:90%;border:none;background:#fff;border-radius:10px;"></iframe>' +
                            '<div id="g2c_close" style="position:absolute;top:20px;left:20px;color:#fff;font-size:20px;cursor:pointer;">✖</div>' +
                        '</div>'
                    );

                    $(document).on('click', '#g2c_close', function(){
                        $('#g2c_iframe_wrap').remove();
                        if (interval) clearInterval(interval);
                    });
                }

                $('#g2c_iframe').attr('src', res);

                // =========================
                // 🔥 START POLLING (fallback)
                // =========================
                interval = setInterval(() => {

                    if (handled) return;

                    console.log('🔄 POLLING CHECK...');

                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: 'action=g2c_check&entry_id=' + entryId
                            })
                        .then(r => r.json())
                        .then(data => {

                            if (data.ResponseCode === 0) {
                                handlePaymentDone('polling');
                            }

                        })
                        .catch(() => {});

                }, 2000);

            }
        });

    });

    // =========================
    // 🔥 EVENT LISTENER (אם מגיע)
    // =========================
    window.addEventListener('message', function(e) {

        console.log('📩 MESSAGE RECEIVED:', e.data);

        if (e.data?.type !== 'G2C_DONE') return;

        const entryId = jQuery('#g2c-entry-id').data('id');

        if (!entryId) {
            console.log('❌ NO ENTRY ID');
            return;
        }

        // 🔥 לא עושה check כפול — רק redirect דרך polling logic
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=g2c_check&entry_id=' + entryId
        })
        .then(r => r.json())
        .then(data => {

            if (data.ResponseCode === 0 && data.redirect) {
                window.location.href = data.redirect;
            }

        });

    });

});
</script>
<?php
});

function g2c_mark_as_paid($entry_id, $result) {

    if (!$entry_id || empty($result)) {
        return;
    }

    $entry = GFAPI::get_entry($entry_id);

    if (is_wp_error($entry) || empty($entry)) {
        return;
    }

    // 🔥 כבר שולם? אל תיגע
    if ($entry['payment_status'] === 'Paid') {
        error_log("⚠️ ALREADY PAID {$entry_id}");
        return;
    }

    $responseCode   = $result['ResponseCode'] ?? -1;
    $transaction_id = $result['TranzactionId'] ?? ($result['LowProfileId'] ?? '');
    $amount         = $result['TranzactionInfo']['Amount'] ?? ($result['Amount'] ?? 0);

    if ($responseCode == 0) {

        GFAPI::update_entry_property($entry_id, 'payment_status', 'Paid');
        GFAPI::update_entry_property($entry_id, 'payment_amount', $amount);
        GFAPI::update_entry_property($entry_id, 'transaction_id', $transaction_id);
        GFAPI::update_entry_property($entry_id, 'payment_method', 'Cardcom');

        gform_update_meta($entry_id, 'sum', $amount);

        error_log("💰 PAYMENT MARKED ONCE: {$entry_id}");

        do_action('g2t_subscription_payment_complete', [
            'Response' => '000',
            'Sum'      => $amount
        ], $entry);

    }
}

add_action('template_redirect', function() {

    if (!isset($_GET['gf_tranzila_return'])) {
        return;
    }

    $str = base64_decode($_GET['gf_tranzila_return']);
    parse_str($str, $query);

    if (empty($query['ids'])) {
        return;
    }

    list($form_id, $entry_id) = explode('|', $query['ids']);

    $entry = GFAPI::get_entry($entry_id);

    if (is_wp_error($entry) || empty($entry)) {
        return;
    }

    // 🔥 קח campaign מכל מקום אפשרי — בלי hardcode
    $campaign = '';

    foreach ($entry as $key => $val) {
        if (!empty($val) && strpos(strtolower($key), 'campaign') !== false) {
            $campaign = $val;
            break;
        }
    }

    if (!$campaign) {
        $campaign = gform_get_meta($entry_id, 'campaignid');
    }

    if (!$campaign) {
        return;
    }

    // אם זה URL → מוציא slug
    if (filter_var($campaign, FILTER_VALIDATE_URL)) {
        $campaign = trim(parse_url($campaign, PHP_URL_PATH), '/');
        $parts = explode('/', $campaign);
        $campaign = end($parts);
    }

    $campaign = sanitize_title($campaign);

    if (!$campaign) {
        return;
    }

    $url = home_url('/campaign-payment-success/' . $campaign . '/');

    wp_redirect($url);
    exit;

}, 1);

add_action('wp_footer', function() {
?>
<script>

function startPolling(entryId) {

    let handled = false;

    const interval = setInterval(function() {

        if (handled) return;

        fetch(window.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=g2c_check&entry_id=' + entryId
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {

            if (data.ResponseCode === 0 && data.redirect) {

                handled = true;
                clearInterval(interval);

                jQuery('#g2c_iframe_wrap').remove();

                window.location.href = data.redirect;
            }

        });

    }, 2000);
}

</script>
<?php
});