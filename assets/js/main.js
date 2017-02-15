(function($) {
	$(document).ready(function() {
		var ajax_url = data_rpbr.ajax_url;

		if ( $( '.pcr-rpbr_restrict_access:checked' ).length > 0 ) {
			$( '.pcr-rpbr_box-select-role' ).css( 'display', 'block' );
		}

		$( '.pcr-rpbr_restrict_access' ).change( function() {
			if ( $('.pcr-rpbr_restrict_access').is(':checked') ) {
				$( '.pcr-rpbr_box-select-role' ).fadeIn(300);
			} else {
				$( '.pcr-rpbr_box-select-role' ).fadeOut(300);
			}
		});
	});
})(jQuery);
