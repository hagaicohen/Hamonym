/**
 * This is js for license activation form
 */

jQuery(document).ready(function($){
	$('#submit-activation-license').click(function(){
		$('#loading-gif').show();
		var license_key = $('#activation-form #license_key').val();
		var activation_email = $('#activation-form #activation_email').val();
		var product_id = $('#activation-form #product_id').val();
		var params = {action: 'sp_activate_license', license_key: license_key, activation_email: activation_email, product_id: product_id};
		$.post(php_data.admin_url, params, function(data){
			$('#license-activation-msg').html(data);
		}).always(function(){$('#loading-gif').hide();});
	});
});