<?php
/**
 * The popup that pops to the user after pressing "update credit card details" button.
 */

// load WP
define('WP_USE_THEMES', false);
require '../../../../wp-load.php';

// the safer the better
if ( ! wp_verify_nonce($_GET['nonce'], 'update_cc_card') || ! isset($_GET['entry_id']) ) {
	exit;
}
$entry_id = $_GET['entry_id'];
$iframe = Planwize\Subscription\G2C_Update_Credit_Card::instance()->get_update_tranzila_iframe($entry_id, 600, 500);

if ( $iframe ) {
	echo '<h3 class="cc-update-title">' . __('Update Credit Card', 'G2C') . '</h3>';
	echo '<p class="cc-update-msg">'.__('Do not worry, you will not be charged! This process just test weather your credit card is OK.', 'G2C'). '</p>';
	echo $iframe;
    ?>
    <script>
        // submit tranzila's form on page load
        window.onload = function(){
            //console.log(document.forms);
            document.forms['tranzila-form'].submit();
        }
    </script>

<?php
} else {
    echo '<div class="went-wrong">' . __('Something went wrong, Please contact as for details.') . '</div>';
}
?>

<style>
    body{
        font-family: sans-serif;
        padding-top: 1.5rem;
    }
    .cc-update-title{
        text-align: center;
    }
    .cc-update-msg{
        position: relative;
        padding: .75rem 1.25rem;
        border: 1px solid #d6d8db;
        border-radius: .25rem;
        color: #383d41;
        background-color: #e2e3e5;
        width: 80%;
        margin: 0 auto;
        text-align: center;
    }
    .went-wrong{
        text-align: center;
    }
</style>


