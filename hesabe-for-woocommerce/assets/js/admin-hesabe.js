( function ( $ ) {
	function toggleDirectRows() {
		var mode = $( 'select.hesabe-wc-integration-mode' ).val();
		var show = mode === 'direct';
		$( '.hesabe-wc-direct-row' ).closest( 'tr' ).toggle( show );
	}

	$( document ).on( 'change', 'select.hesabe-wc-integration-mode', toggleDirectRows );
	$( toggleDirectRows );
}( jQuery ) );
