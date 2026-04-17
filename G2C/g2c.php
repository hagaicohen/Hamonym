<?php
/*
Plugin Name: G2C SAFE TEST
*/

add_action('init', function() {
    error_log('🔥 G2C PLUGIN LOADED');
});

if ( ! defined( 'ABSPATH' ) ) exit;


// =======================
// 🔹 GRAVITY FIELDS
// =======================

function g2c_get_gravity_fields($form_id) {

    if (!class_exists('GFAPI')) return [];

    $form = GFAPI::get_form($form_id);

    if (!$form || empty($form['fields'])) return [];

    return $form['fields'];
}


// =======================
// 🔹 SELECT (עם selected)
// =======================

function g2c_select($name, $form_id, $settings) {

    $fields = g2c_get_gravity_fields($form_id);
    $selected_value = $settings[$name] ?? '';
    $selected_label = '';

    foreach ($fields as $field) {
        if ($field->id == $selected_value) {
            $selected_label = $field->label . ' (ID: ' . $field->id . ')';
        }
    }

    echo '<div class="g2c-dd">';

    // value אמיתי שנשמר
    echo '<input type="hidden" name="g2c_settings[' . esc_attr($name) . ']" value="' . esc_attr($selected_value) . '" class="g2c-val">';

    // שדה תצוגה
    echo '<div class="g2c-display">' . esc_html($selected_label ?: 'בחר שדה...') . '</div>';

    // רשימה
    echo '<div class="g2c-options">';

    foreach ($fields as $field) {
        echo '<div class="g2c-option" data-val="' . esc_attr($field->id) . '">';
        echo esc_html($field->label) . ' (ID: ' . $field->id . ')';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
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

// =======================
// 🔥 MENU
// =======================

add_filter('gform_form_settings_menu', function($menu_items) {

    $menu_items['g2c'] = array(
        'name'  => 'g2c',
        'label' => 'Cardcom',
        'icon'  => 'gform-icon--credit-card'
    );

    return $menu_items;

});

add_filter('gform_confirmation', function($confirmation, $form, $entry, $ajax){

    // 🔧 FIX – לא להרוג את ה-flow
    $confirmation .= '<div id="g2c-entry-id" data-id="' . esc_attr($entry['id']) . '"></div>';

    return $confirmation;

}, 10, 4);


// =======================
// 🔥 PAGE
// =======================

add_action('gform_form_settings_page_g2c', function() {

$form_id = absint($_GET['id'] ?? 0);

// 🔥 שמירה לפי טופס (תוספת בלבד)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['g2c_settings'])) {

    $new_settings = $_POST['g2c_settings'];
    update_option('g2c_settings_' . $form_id, $new_settings);

    echo '<div class="updated"><p>נשמר ✔</p></div>';
}

// 🔥 טעינה לפי טופס
$settings = get_option('g2c_settings_' . $form_id, []);

?>

<h2 class="g2c-title">הגדרות Cardcom 💳</h2>
<p class="g2c-sub">הגדרת חיבור ומיפוי שדות לתשלום מאובטח</p>

<form method="post">
<div class="g2c-panel">

<!-- 🔑 חיבור -->
<div class="g2c-section">
<h4>פרטי חיבור</h4>

<div class="g2c-field">
<label>מספר מסוף</label>
<input type="text" name="g2c_settings[g2c_terminal]"
value="<?= esc_attr($settings['g2c_terminal'] ?? '') ?>">
</div>


<div class="g2c-field">
<label>API User</label>
<input type="text" name="g2c_settings[g2c_api_user]"
value="<?= esc_attr($settings['g2c_api_user'] ?? '') ?>">
</div>

<div class="g2c-field">
<label>API Password</label>
<div class="g2c-password-wrap">
    <input type="password" id="g2c_api_password"
    name="g2c_settings[g2c_api_password]"
    value="<?= esc_attr($settings['g2c_api_password'] ?? '') ?>">
    <span class="dashicons dashicons-visibility g2c-eye"
          onclick="togglePassword('g2c_api_password', this)"></span>
</div>
</div>

</div>


<!-- 👤 לקוח -->
<div class="g2c-section">
<h4>פרטי לקוח (מיפוי)</h4>

<div class="g2c-field">
<label>שם פרטי</label>
<?php g2c_select('first_name', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>שם משפחה</label>
<?php g2c_select('last_name', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>Comments</label>
<?php g2c_select('comments', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>Campaign ID</label>
<?php g2c_select('campaign_id', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>טלפון</label>
<?php g2c_select('phone', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>אימייל</label>
<?php g2c_select('email', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>כתובת</label>
<?php g2c_select('address', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>עיר</label>
<?php g2c_select('city', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>מיקוד</label>
<?php g2c_select('zip', $form_id, $settings); ?>
</div>

</div>


<!-- 💰 עסקה -->
<div class="g2c-section">
<h4>פרטי העסקה</h4>

<div class="g2c-field">
<label>סה"כ תשלום</label>
<?php g2c_select('amount', $form_id, $settings); ?>
</div>

<div class="g2c-field">
<label>תיאור מוצר</label>
<input type="text" name="g2c_settings[g2c_product]"
value="<?= esc_attr($settings['g2c_product'] ?? '') ?>">
</div>

</div>


<!-- 📐 iframe -->
<div class="g2c-section">
<h4>חלון תשלום</h4>

<div class="g2c-field">
<label>רוחב</label>
<input type="number" name="g2c_settings[g2c_iframe_width]"
value="<?= esc_attr($settings['g2c_iframe_width'] ?? 600) ?>">
</div>

<div class="g2c-field">
<label>גובה</label>
<input type="number" name="g2c_settings[g2c_iframe_height]"
value="<?= esc_attr($settings['g2c_iframe_height'] ?? 700) ?>">
</div>

</div>

<button class="button button-primary">שמור</button>

</div>
</form>

<?php
// =======================
// 🔥 TEST IFRAME
// =======================

$iframe_url = '';

if (!empty($settings['g2c_terminal']) && !empty($settings['g2c_api_user'])) {
//    $iframe_url = g2c_create_lowprofile($settings);
$iframe_url = ''; // 🔥 FIX – לא ליצור תשלום בלי entry
    echo $iframe_url;
}
?>

<div class="g2c-section">
    <h4>בדיקת תשלום (IFRAME)</h4>

    <button type="button" class="button"
        onclick="document.getElementById('g2c_iframe').style.display='block'">
        פתח חלון תשלום
    </button>
<a href="<?= esc_url($iframe_url) ?>" target="_blank" class="button">
    פתח תשלום בלשונית חדשה
</a>
    <div style="margin-top:15px;">
        <iframe
            id="g2c_iframe"
            src="<?= esc_url($iframe_url) ?>"
            width="<?= esc_attr($settings['g2c_iframe_width'] ?? 600) ?>"
            height="<?= esc_attr($settings['g2c_iframe_height'] ?? 700) ?>"
            style="border:1px solid #ccc; display:block;">
        </iframe>
        <?php echo '<a href="'.$iframe_url.'" target="_blank">TEST LINK</a>'; ?>
    </div>
</div>
<script>
function togglePassword(id, el) {
    const input = document.getElementById(id);

    if (input.type === "password") {
        input.type = "text";
        el.classList.remove('dashicons-visibility');
        el.classList.add('dashicons-hidden');
    } else {
        input.type = "password";
        el.classList.remove('dashicons-hidden');
        el.classList.add('dashicons-visibility');
    }
}
</script>
<script>
jQuery(function($){

    // פתיחה
    $(document).on('click', '.g2c-display', function(){
        $('.g2c-options').hide();
        $(this).siblings('.g2c-options').toggle();
    });

    // בחירה
    $(document).on('click', '.g2c-option', function(){
        const val = $(this).data('val');
        const text = $(this).text();
        const box = $(this).closest('.g2c-dd');

        box.find('.g2c-val').val(val);
        box.find('.g2c-display').text(text);
        box.find('.g2c-options').hide();
    });

    // סגירה
    $(document).on('click', function(e){
        if (!$(e.target).closest('.g2c-dd').length) {
            $('.g2c-options').hide();
        }
    });

});
</script>
<?php
});


// =======================
// 🎨 CSS (לא נגעתי בכלל)
// =======================

add_action('admin_head', function () {
?>
<style>

/* כאן בדיוק ה-CSS שלך כמו שהיה */

.g2c-title {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 5px;
}

.g2c-sub {
    color: #666;
    margin-bottom: 20px;
}

.g2c-panel {
    max-width: 600px;
    margin-top: 20px;
}

.g2c-section {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.g2c-section h4 {
    margin-bottom: 15px;
}

.g2c-field {
    display: flex;
    flex-direction: column;
    margin-bottom: 15px;
}

.g2c-field label {
    margin-bottom: 5px;
    font-weight: 500;
}

.g2c-field input,
.g2c-field select {
    width: 100%;
    max-width: 100%;
    height: 38px;
    border-radius: 8px;
    border: 1px solid #ccc;
    padding: 0 10px;
    box-sizing: border-box;
}

.g2c-field select {
    display: block;
    min-width: 0;
}

@media (max-width: 600px) {

    .g2c-panel {
        max-width: 100%;
        padding: 0 10px;
    }

    .g2c-field input,
    .g2c-field select {
        width: 100%;
    }

}

.g2c-field input:focus,
.g2c-field select:focus {
    border-color: #2271b1;
    outline: none;
}

.g2c-password-wrap {
    position: relative;
    width: 100%;
}

.g2c-password-wrap input {
    width: 100%;
    padding-left: 35px;
    box-sizing: border-box;
}

.g2c-eye {
    position: absolute;
    left: 8px;
    top: 7px;
    cursor: pointer;
    color: #777;
}

.g2c-eye:hover {
    color: #000;
}

/* 🔥 הפתרון האמיתי */
.gform-settings-panel__content,
.g2c-panel,
.g2c-section {
    overflow: visible !important;
}

.g2c-dd {
    position: relative;
    width: 100%;
}

.g2c-display {
    height: 38px;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 0 10px;
    display: flex;
    align-items: center;
    cursor: pointer;
    background: #fff;
}

.g2c-options {
    position: absolute;
    top: 100%;
    right: 0;
    left: 0;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 8px;
    max-height: 220px;
    overflow-y: auto;
    display: none;
    z-index: 9999;
}

.g2c-option {
    padding: 8px 10px;
    cursor: pointer;
    text-align: right;
}

.g2c-option:hover {
    background: #f1f1f1;
}

</style>
<?php
});

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
