/**
 * Hesabe — Apple Pay page: wraps ApplePaySession before Hesabe's script creates one.
 *
 * Loaded after Apple's SDK and before Hesabe's /applepay script. Once the customer authorises
 * the payment, Hesabe's script sends the browser on to Hesabe's payment URL and does not call
 * completePayment(). Without that call the Apple Pay sheet waits 30 seconds and then reports
 * "Payment Failed", although Hesabe may have taken the payment.
 *
 * Every session created through the wrapped constructor therefore:
 *   (a) calls completePayment( STATUS_SUCCESS ) on beforeunload, the moment the page starts
 *       to navigate away and before its scripts stop;
 *   (b) wraps the oncancel handler Hesabe's script assigns, to show the cancelled state and
 *       send the customer back to the checkout instead of leaving them on this page.
 */
( function () {
	if ( ! window.ApplePaySession ) {
		return;
	}

	var _Orig       = window.ApplePaySession;
	var STATUS_OK   = _Orig.STATUS_SUCCESS;
	var pageData    = window.hesabeApplePayPage || {};
	var checkoutUrl = pageData.checkoutUrl || '/';

	function HesabePatchedSession( version, paymentRequest ) {
		/* Create the real session via the original constructor. */
		var session = new _Orig( version, paymentRequest );
		var done    = false;

		/* Called once — either from beforeunload (navigation) or explicitly. */
		function completeOnce() {
			if ( done ) {
				return;
			}
			done = true;
			try {
				session.completePayment( STATUS_OK );
			} catch ( e ) {}
			window.removeEventListener( 'beforeunload', completeOnce );
		}

		/* When the page navigates away (Hesabe's script sets window.location.href),
		   signal Apple Pay SUCCESS before the JS context is torn down. */
		window.addEventListener( 'beforeunload', completeOnce );

		/*
		 * Override the oncancel setter so we can intercept cancellations.
		 * Hesabe's script does:  applePaySession.oncancel = function(e){…}
		 * We wrap that assignment to also show the "cancelled" UI + redirect.
		 */
		var _cancelFn = null;
		Object.defineProperty( session, 'oncancel', {
			get: function () {
				return _cancelFn;
			},
			set: function ( fn ) {
				_cancelFn = function ( event ) {
					done = true; /* prevent completePayment from beforeunload */
					window.removeEventListener( 'beforeunload', completeOnce );
					if ( typeof fn === 'function' ) {
						try {
							fn( event );
						} catch ( e ) {}
					}
					/* Show cancelled UI then redirect back to checkout. */
					var loadEl   = document.getElementById( 'hap-loading' );
					var cancelEl = document.getElementById( 'hap-cancelled' );
					if ( loadEl ) {
						loadEl.style.display = 'none';
					}
					if ( cancelEl ) {
						cancelEl.style.display = 'block';
					}
					setTimeout( function () {
						window.location.href = checkoutUrl;
					}, 3000 );
				};
			},
			configurable: true
		} );

		return session; /* new operator returns this object, not `this` */
	}

	/* Copy prototype and all static properties (STATUS_SUCCESS, STATUS_FAILURE,
	   canMakePayments, canMakePaymentsWithActiveCard, etc.) */
	HesabePatchedSession.prototype = _Orig.prototype;
	try {
		Object.getOwnPropertyNames( _Orig ).forEach( function ( name ) {
			if ( name === 'prototype' || name === 'length' || name === 'name' ) {
				return;
			}
			try {
				var d = Object.getOwnPropertyDescriptor( _Orig, name );
				if ( ! d ) {
					return;
				}
				Object.defineProperty(
					HesabePatchedSession,
					name,
					typeof d.value === 'function'
						? { value: d.value.bind( _Orig ), writable: true, configurable: true, enumerable: d.enumerable }
						: d
				);
			} catch ( e ) {}
		} );
	} catch ( e ) {}

	window.ApplePaySession = HesabePatchedSession;
} )();
