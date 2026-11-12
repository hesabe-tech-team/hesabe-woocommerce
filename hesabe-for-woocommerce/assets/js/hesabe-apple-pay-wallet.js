/**
 * Hesabe — Apple Pay page: opens the wallet, or says Apple Pay is unavailable.
 *
 * Loaded after Hesabe's /applepay script. That script shows #applePayment when the wallet
 * has a card for the merchant, and attaches the ApplePaySession to .applePaybtn inside it.
 * This script presses the button as soon as it is shown, and shows the "unavailable"
 * message when it has not appeared after 8 seconds.
 */
( function () {
	var loadEl    = document.getElementById( 'hap-loading' );
	var unavailEl = document.getElementById( 'hap-unavailable' );
	var apDiv     = document.getElementById( 'applePayment' );

	function showUnavailable() {
		if ( loadEl ) {
			loadEl.style.display = 'none';
		}
		if ( unavailEl ) {
			unavailEl.style.display = 'block';
		}
	}

	if ( typeof ApplePaySession === 'undefined' ) {
		showUnavailable();
		return;
	}

	function triggerWallet() {
		var btn = apDiv.querySelector( '.applePaybtn' );
		if ( btn ) {
			btn.click();
		}
	}

	var observer = new MutationObserver( function () {
		if ( apDiv.style.display !== 'none' ) {
			observer.disconnect();
			triggerWallet();
		}
	} );
	observer.observe( apDiv, { attributes: true, attributeFilter: [ 'style' ] } );

	if ( apDiv.style.display !== 'none' ) {
		observer.disconnect();
		triggerWallet();
		return;
	}

	setTimeout( function () {
		observer.disconnect();
		if ( apDiv.style.display === 'none' ) {
			showUnavailable();
		}
	}, 8000 );
} )();
