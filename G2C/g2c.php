<?php
/*
Plugin Name: G2C - CARD COM
*/

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path(__FILE__) . 'admin/g2c-admin.php';

/* =========================
   LOGGER
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

    g2c_log('🔥 RETURN GET PARAMS', $_GET);

    $lp = sanitize_text_field($_GET['lowprofilecode']);
    $response_code = $_GET['ResponseCode'] ?? ($_GET['ResponeCode'] ?? null);

    global $wpdb;

    $entry_id = $wpdb->get_var($wpdb->prepare("
        SELECT entry_id 
        FROM {$wpdb->prefix}gf_entry_meta
        WHERE meta_key = 'lowprofile_id'
        AND meta_value = %s
        LIMIT 1
    ", $lp));

    if (!$entry_id) {
        g2c_log('❌ ENTRY NOT FOUND FOR LP', $lp);
        return;
    }

    $entry = GFAPI::get_entry($entry_id);
    if (is_wp_error($entry) || empty($entry)) return;

    $is_success = (trim((string)$response_code) === '0');

    if ($is_success) {

        $sum = floatval(preg_replace('/[^\d.]/', '', rgar($entry, '151')));

        GFAPI::update_entry_property($entry_id, 'payment_status', 'Paid');
        GFAPI::update_entry_property($entry_id, 'payment_date', date('Y-m-d H:i:s'));
        GFAPI::update_entry_property($entry_id, 'payment_amount', $sum);

        gform_update_meta($entry_id, 'transaction_id', $_GET['internalDealNumber'] ?? $lp);

        g2c_log('HANDLE RETURN START', $entry_id);

        $feeds = GFAPI::get_feeds(null, $entry['form_id']);
        $feed  = (!empty($feeds) && !is_wp_error($feeds)) ? $feeds[0] : [];
        $meta  = (isset($feed['meta']) && is_array($feed['meta'])) ? $feed['meta'] : [];

        $first_name = isset($meta['billingInformation_firstName']) ? rgar($entry, $meta['billingInformation_firstName']) : '';
        $last_name  = isset($meta['billingInformation_lastName'])  ? rgar($entry, $meta['billingInformation_lastName'])  : '';
        $email      = isset($meta['billingInformation_email'])     ? rgar($entry, $meta['billingInformation_email'])     : '';
        $city       = isset($meta['billingInformation_city'])      ? rgar($entry, $meta['billingInformation_city'])      : '';

        if (!$first_name) $first_name = rgar($entry, '9.3');
        if (!$last_name)  $last_name  = rgar($entry, '9.6');
        if (!$email)      $email      = rgar($entry, '10');
        if (!$city)       $city       = rgar($entry, '11.3');

       $post = [
            'ResponseCode'  => 0,
            'Response'      => '000',
            'TransactionId' => $_GET['internalDealNumber'] ?? $lp,
            'Amount'        => $sum,
            'Currency'      => 1,

            'sum'           => $sum,
            'index'         => $lp,

            'first_name'    => $first_name ?: 'תורם',
            'last_name'     => $last_name ?: '',
            'full_name'     => trim($first_name . ' ' . $last_name), // 🔥 חשוב

            'email'         => $email,
            'city'          => $city,
            'contact'       => trim($first_name . ' ' . $last_name),

            'phone'         => rgar($entry, '10') ?? '', // 🔥 אם יש
            'date'          => date('c'),
        ];

        g2c_log('POST BEFORE HOOK', $post);

        try {
            g2c_log('FINAL POST SENT TO HOOK', $post);
            do_action('gform_tranzila_payment_complete', $post, $entry, $feed);
            g2c_log('HOOK FIRED SUCCESS');
        } catch (Throwable $e) {
            g2c_log('HOOK ERROR', $e->getMessage());
        }

    } else {

        GFAPI::update_entry_property($entry_id, 'payment_status', 'Failed');
        g2c_log('PAYMENT FAILED', $entry_id);
    }

    wp_safe_redirect(strtok(home_url($_SERVER['REQUEST_URI']), '?'));
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

    // 🔥 פרטי משתמש
    $email  = $entry[$settings['email']] ?? '';
    $first  = $entry[$settings['first_name']] ?? '';
    $last   = $entry[$settings['last_name']] ?? '';

    if (empty($first)) $first = rgar($entry, '9.3');
    if (empty($last))  $last  = rgar($entry, '9.6');

    $name = trim($first . ' ' . $last);

    $success_url = !empty($settings['success_url']) ? $settings['success_url'] : home_url('/');
    $fail_url    = !empty($settings['fail_url'])    ? $settings['fail_url']    : home_url('/');

    // 🔥 שליפת FORM + מוצרים
    $form = GFAPI::get_form($form_id);
    $products = GFCommon::get_product_fields($form, $entry);

    // 🔥 בניית map לשדות (ביצועים!)
    $field_map = [];
    foreach ($form['fields'] as $field) {
        $field_map[$field->id] = $field;
    }

    $cardcom_products = [];
    $total_amount = 0;

    if (!empty($products['products'])) {

        foreach ($products['products'] as $product_id => $p) {

            $product_field = $field_map[$product_id] ?? null;

            $title = $p['name'] ?? 'מוצר'; // fallback

            // 🔥 זה הקסם: product → inventory_parent → label
            if ($product_field && !empty($product_field->inventory_parent)) {

                $parent_id = $product_field->inventory_parent;

                if (!empty($field_map[$parent_id])) {
                    $title = $field_map[$parent_id]->label;
                }
            }

            // 🔥 מחיר + כמות
            $price = floatval(preg_replace('/[^\d.]/', '', $p['price'] ?? 0));
            $qty   = intval($p['quantity'] ?? 1);

            if ($qty < 1) $qty = 1;

            // 🔥 כל יחידה = שורה (כדי לא להסתבך עם Cardcom)
            for ($i = 0; $i < $qty; $i++) {
                $cardcom_products[] = [
                    "Description" => $title,
                    "UnitCost"    => round($price, 2)
                ];
            }

            $total_amount += $price * $qty;

            // 🔥 לוג
            g2c_log('PRODUCT MAPPED', [
                'product_id' => $product_id,
                'title'      => $title,
                'price'      => $price,
                'qty'        => $qty
            ]);
        }
    }

    // 🔥 fallback אם אין מוצרים
    if (empty($cardcom_products)) {

        $amount = round(floatval($entry[$settings['amount']] ?? 0), 2);

        $cardcom_products[] = [
            "Description" => 'תרומה',
            "UnitCost"    => $amount
        ];

        $total_amount = $amount;
    }

    // 🔥 payload
    $payload = [
        "TerminalNumber" => $settings['g2c_terminal'],
        "ApiName"        => $settings['g2c_api_user'],
        "ApiPassword"    => $settings['g2c_api_password'],

        "Amount" => round($total_amount, 2),

        "SuccessRedirectUrl" => $success_url,
        "FailedRedirectUrl"  => $fail_url,

        "ReturnValue" => (string)$entry_id,

        "Document" => [
            "To"    => $name,
            "Email" => $email,
            "Products" => $cardcom_products
        ]
    ];

    g2c_log('🚀 FINAL PAYLOAD', $payload);

    $response = wp_remote_post(
        "https://secure.cardcom.solutions/api/v11/LowProfile/Create",
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($payload)
        ]
    );

    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    g2c_log('📥 CARDCOM RESPONSE', $json);

    if (empty($json['LowProfileId']) || empty($json['Url'])) {
        wp_die();
    }

    gform_update_meta($entry_id, 'lowprofile_id', $json['LowProfileId']);
    GFAPI::update_entry_property($entry_id, 'payment_status', 'Processing');

    echo json_encode([
        'url' => $json['Url']
    ]);

    wp_die();
}

/* =========================
   IFRAME
========================= */
/* =========================
   IFRAME
========================= */
if (!is_admin()) {

add_action('wp_footer', function() {
?>
<style>
#g2c-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: #fff;
    z-index: 999999;
}

#g2c-overlay iframe {
    width: 100%;
    height: 100%;
    border: none;
}
</style>

<script>
jQuery(function($){

    let opened = false;

    $(document).on('gform_confirmation_loaded', function(event, formId){

        if (opened) return;
        opened = true;

        const entryId = $('#g2c-entry-id').data('id');
        if (!entryId) return;

        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action: 'g2c_create_payment',
            form_id: formId,
            entry_id: entryId
        }, function(res){

            if (typeof res === 'string') {
                try { res = JSON.parse(res); } catch(e) { return; }
            }

            if (!res.url) return;

            // 🔥 ניקוי אם כבר קיים
            $('#g2c-overlay').remove();

            // 🔥 פתיחת IFRAME FULL SCREEN
            $('body').append(`
                <div id="g2c-overlay">
                    <iframe src="${res.url}"></iframe>
                </div>
            `);

        }, 'json');
    });

});
</script>
<?php
});

}


/* =========================
   ENTRY ID
========================= */
add_filter('gform_confirmation', function($confirmation, $form, $entry){
    return $confirmation . '<div id="g2c-entry-id" data-id="' . esc_attr($entry['id']) . '"></div>';
}, 10, 3);
