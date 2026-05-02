<?php

add_action('admin_footer', function() {
    echo "<script>console.log('🔥 ADMIN FILE LOADED');</script>";
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

    echo '<input type="hidden" name="g2c_settings[' . esc_attr($name) . ']" value="' . esc_attr($selected_value) . '" class="g2c-val">';

    echo '<div class="g2c-display">' . esc_html($selected_label ?: 'בחר שדה...') . '</div>';

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
// 🔥 MENU (תיקון כאן בלבד)
// =======================

// =======================
// 🔥 MENU (תיקון סופי)
// =======================

add_filter('gform_form_settings_menu', function($menu_items) {

    $menu_items[] = array(   // 🔥 זה התיקון
        'name'  => 'g2c',
        'label' => 'Cardcom',
        'icon'  => 'gform-icon--credit-card',
    );

    return $menu_items;

});

// =======================
// 🔥 PAGE
// =======================

add_action('gform_form_settings_page_g2c', function() {
    $form_id = absint($_GET['id'] ?? 0);

    // שמירה
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['g2c_settings'])) {
        check_admin_referer('gform_settings_save', 'gform_settings_save');
        $new_settings = $_POST['g2c_settings'];
        update_option('g2c_settings_' . $form_id, $new_settings);
        GFCommon::add_message('נשמר ✔');
    }

    // טעינה
    $settings = get_option('g2c_settings_' . $form_id, []);

    // 🔥 header מתוקן - חיוני כדי ש-Gravity Forms יזהה את הדף
    GFFormSettings::page_header();
    ?>

    <div class="gform-settings__content">
        <form method="post">
            <?php wp_nonce_field('gform_settings_save', 'gform_settings_save'); ?>

            <h2 class="g2c-title">הגדרות Cardcom 💳</h2>
            <p class="g2c-sub">הגדרת חיבור ומיפוי שדות לתשלום מאובטח</p>

            <div class="g2c-panel">
                <!-- 🔑 חיבור -->
                <div class="g2c-section">
                    <h4>פרטי חיבור</h4>
                    <div class="g2c-field">
                        <label>מספר מסוף</label>
                        <input type="text" name="g2c_settings[g2c_terminal]" value="<?= esc_attr($settings['g2c_terminal'] ?? '') ?>">
                    </div>
                    <div class="g2c-field">
                        <label>API User</label>
                        <input type="text" name="g2c_settings[g2c_api_user]" value="<?= esc_attr($settings['g2c_api_user'] ?? '') ?>">
                    </div>
                    <div class="g2c-field">
                        <label>API Password</label>
                        <div class="g2c-password-wrap">
                            <input type="password" id="g2c_api_password" name="g2c_settings[g2c_api_password]" value="<?= esc_attr($settings['g2c_api_password'] ?? '') ?>">
                            <span class="dashicons dashicons-visibility g2c-eye" onclick="togglePassword('g2c_api_password', this)"></span>
                        </div>
                    </div>
                </div>

                <!-- 👤 לקוח -->
                <div class="g2c-section">
                    <h4>פרטי לקוח (מיפוי)</h4>
                    <div class="g2c-field"><label>שם פרטי</label><?php g2c_select('first_name', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>שם משפחה</label><?php g2c_select('last_name', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>Comments</label><?php g2c_select('comments', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>Campaign ID</label><?php g2c_select('campaign_id', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>טלפון</label><?php g2c_select('phone', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>אימייל</label><?php g2c_select('email', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>כתובת</label><?php g2c_select('address', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>עיר</label><?php g2c_select('city', $form_id, $settings); ?></div>
                    <div class="g2c-field"><label>מיקוד</label><?php g2c_select('zip', $form_id, $settings); ?></div>
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
                        <input type="text" name="g2c_settings[g2c_product]" value="<?= esc_attr($settings['g2c_product'] ?? '') ?>">
                    </div>
                    <div class="g2c-field">
                        <label>SUCCESS URL</label>
                        <input type="text" name="g2c_settings[success_url]" value="<?= esc_attr($settings['success_url'] ?? '') ?>">
                    </div>
                    <div class="g2c-field">
                        <label>FAIL URL</label>
                        <input type="text" name="g2c_settings[fail_url]" value="<?= esc_attr($settings['fail_url'] ?? '') ?>">
                    </div>
                </div>

                <!-- 📐 iframe -->
                <div class="g2c-section">
                    <h4>חלון תשלום</h4>
                    <div class="g2c-field">
                        <label>רוחב</label>
                        <input type="number" name="g2c_settings[g2c_iframe_width]" value="<?= esc_attr($settings['g2c_iframe_width'] ?? 600) ?>">
                    </div>
                    <div class="g2c-field">
                        <label>גובה</label>
                        <input type="number" name="g2c_settings[g2c_iframe_height]" value="<?= esc_attr($settings['g2c_iframe_height'] ?? 700) ?>">
                    </div>
                </div>

                <button class="button button-primary">שמור</button>
            </div>
        </form>
    </div>

    <?php
    // 🔥 חיוני: סגור את הדף עם page_footer
    GFFormSettings::page_footer();
});

// =======================
// 🎨 STYLE (ללא שינוי)
// =======================

add_action('admin_head', function () {
?>
<style>

.g2c-title { font-size:22px; font-weight:700; margin-bottom:5px; }
.g2c-sub { color:#666; margin-bottom:20px; }

.g2c-panel { margin-top:20px; }

.g2c-section {
    background:#fff;
    border:1px solid #ddd;
    border-radius:6px;
    padding:16px;
    margin-bottom:16px;
}

.g2c-field { margin-bottom:12px; }

.g2c-field label {
    display:block;
    margin-bottom:4px;
    font-weight:500;
}

.g2c-field input {
    width:100%;
    height:34px;
    border:1px solid #ccc;
    border-radius:4px;
    padding:0 8px;
    box-sizing:border-box;
}

.g2c-dd { position:relative; }

.g2c-display {
    height:34px;
    border:1px solid #ccc;
    border-radius:4px;
    padding:0 8px;
    display:flex;
    align-items:center;
    cursor:pointer;
    background:#fff;
}

.g2c-options {
    position:absolute;
    top:100%;
    right:0;
    left:0;
    background:#fff;
    border:1px solid #ccc;
    max-height:200px;
    overflow-y:auto;
    display:none;
    z-index:1000;
}

.g2c-option {
    padding:6px 8px;
    cursor:pointer;
}

.g2c-option:hover {
    background:#f1f1f1;
}

</style>

<?php
});

