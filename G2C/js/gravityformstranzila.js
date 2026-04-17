jQuery(document).ready(function(){
	jQuery('#G2C_waiting_msg img').fadeToggle(1000);
	setTimeout(function(){
		jQuery('#tranzila-form').submit();

		jQuery('#tranzila_frame').load(function(){
			jQuery('#G2C_waiting_msg').hide();
			jQuery(this).parent('div').slideDown();
		});
		
	}, 4000);
	
});

