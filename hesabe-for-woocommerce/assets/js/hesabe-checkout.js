/**
 * Hesabe — checkout page behaviour.
 *
 * Apple Pay uses the proven V5 plugin flow on the same checkout page:
 * WC creates the order + gets Hesabe token, then we load Apple's SDK and
 * Hesabe's /applepay?data=... script against a real .applePaybtn/#applePayment
 * button. No separate Apple Pay page is used.
 */
( function ( $ ) {
	'use strict';

	/* ── Constants ──────────────────────────────────────────────────────── */
	var BRAND_VALUE   = 'apple';
	var APPLE_SDK_URL = 'https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js';
	var DATA          = window.hesabeApplePayData || {};

	/* ── Overlay ────────────────────────────────────────────────────────── */
	var OVERLAY_ID = 'hesabe-ap-overlay';

	function injectOverlay() {
		if ( document.getElementById( OVERLAY_ID ) ) { return; }
		var style = document.createElement( 'style' );
		style.textContent =
			'#' + OVERLAY_ID + '{'
			+ 'display:none;position:fixed;top:0;left:0;width:100%;height:100%;'
			+ 'background:rgba(0,0,0,.85);z-index:99999;'
			+ 'align-items:center;justify-content:center;flex-direction:column;'
			+ 'font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#fff;text-align:center;'
			+ '}'
			+ '#' + OVERLAY_ID + '.is-visible{display:flex}'
			+ '#hesabe-applepay-holder .applePaybtn{'
			+ 'display:block;width:100%;height:52px;padding:0;border:0;border-radius:10px;'
			+ 'cursor:pointer;-webkit-appearance:none;appearance:none;'
			+ 'background:#fff;background-image:-webkit-named-image(apple-pay-logo-black);'
			+ 'background-repeat:no-repeat;background-position:center;background-size:auto 60%;'
			+ 'color:transparent;font-size:0;line-height:0;text-indent:-9999px;overflow:hidden;'
			+ '}'
			+ '@keyframes hapSpin{to{transform:rotate(360deg)}}';
		document.head.appendChild( style );

		var el = document.createElement( 'div' );
		el.id = OVERLAY_ID;
		el.innerHTML =
			'<div style="margin-bottom:1rem">'
			+ '<svg width="44" height="44" viewBox="0 0 24 24" fill="white">'
			+ '<path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79'
			+ '-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39'
			+ 'c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91'
			+ '.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04'
			+ '-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35'
			+ '-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>'
			+ '</svg>'
			+ '</div>'
			+ '<div style="display:inline-block;width:24px;height:24px;'
			+ 'border:2px solid rgba(255,255,255,.25);border-top-color:#fff;'
			+ 'border-radius:50%;animation:hapSpin .7s linear infinite;margin-bottom:.9rem"></div>'
			+ '<p id="hesabe-ap-msg" style="font-size:.95rem;opacity:.75;margin:0"></p>';
		document.body.appendChild( el );
	}

	function showOverlay( msg ) {
		var el = document.getElementById( OVERLAY_ID );
		if ( el ) {
			el.classList.add( 'is-visible' );
			var msgEl = document.getElementById( 'hesabe-ap-msg' );
			if ( msgEl ) {
				msgEl.textContent = ( msg != null && msg !== '' ) ? msg : '';
			}
		}
	}

	function hideOverlay() {
		var el = document.getElementById( OVERLAY_ID );
		if ( el ) { el.classList.remove( 'is-visible' ); }
	}

	/* ── Apple Pay SDK preload ──────────────────────────────────────────── */
	function preloadAppleSDK() {
		if ( document.querySelector( 'link[href="' + APPLE_SDK_URL + '"]' ) ) { return; }
		var link = document.createElement( 'link' );
		link.rel         = 'preload';
		link.as          = 'script';
		link.href        = APPLE_SDK_URL;
		link.crossOrigin = 'anonymous';
		document.head.appendChild( link );
	}

	/* ── Helpers ────────────────────────────────────────────────────────── */
	function applePaySupported() {
		return !! (
			window.ApplePaySession &&
			typeof ApplePaySession.canMakePayments === 'function' &&
			ApplePaySession.canMakePayments()
		);
	}

	function applePayKnownUnavailable() {
		return !! (
			window.ApplePaySession &&
			typeof ApplePaySession.canMakePayments === 'function' &&
			! ApplePaySession.canMakePayments()
		);
	}

	function getAppleRadio() {
		return $( 'input[type="radio"][name="hesabe_brand"][value="' + BRAND_VALUE + '"]' );
	}

	function syncBrandSelectionUI() {
		$( '.hesabe-brand-card' ).removeClass( 'hesabe-brand-card--selected' );
		$( 'input[name="hesabe_brand"]:checked' ).each( function () {
			$( this ).closest( '.hesabe-brand-card' ).addClass( 'hesabe-brand-card--selected' );
		} );
	}

	function markUnavailable( $radio ) {
		$radio.prop( 'disabled', true );
		var $card = $radio.closest( '.hesabe-brand-card' );
		$card.addClass( 'hesabe-brand-card--unavailable' );
		var $text = $card.find( '.hesabe-brand-card__text' ).first();
		if ( $text.length && ! $text.find( '.hesabe-apple-unavailable' ).length ) {
			$text.append(
				$( '<span>' )
					.addClass( 'hesabe-apple-unavailable' )
					.css( { color: '#666', fontSize: '0.85em', marginLeft: '4px' } )
					.text( '(not available)' )
			);
		}
		if ( $radio.is( ':checked' ) ) {
			$radio.prop( 'checked', false );
			$( 'input[type="radio"][name="hesabe_brand"]:not(:disabled)' ).first().prop( 'checked', true ).trigger( 'change' );
		}
		syncBrandSelectionUI();
	}

	/**
	 * Read the displayed order total from the WC order-review table.
	 * Returns a string like "10.000" formatted to the store's decimal count.
	 */
	function getOrderTotal() {
		var $el = $( '.order-total .woocommerce-Price-amount bdi' );
		if ( ! $el.length ) { $el = $( '.order-total .woocommerce-Price-amount' ); }
		var raw = ( $el.first().text() || '0' ).replace( /[^\d.]/g, '' );
		var amount = parseFloat( raw ) || 0;
		var dec    = parseInt( DATA.decimals || '3', 10 );
		return amount.toFixed( dec );
	}

	/**
	 * Parse order_id and key out of a WC checkout redirect URL.
	 * e.g. /wc-api/wc_hesabe_apple?order_id=123&key=wc_order_xxx
	 */
	function parseRedirect( url ) {
		var idMatch  = url.match( /[?&]order_id=(\d+)/ );
		var keyMatch = url.match( /[?&]key=([^&#]+)/ );
		return {
			orderId  : idMatch  ? idMatch[1]                    : null,
			orderKey : keyMatch ? decodeURIComponent( keyMatch[1] ) : '',
		};
	}

	function normalizeAppleDomain( value ) {
		var domain = String( value || window.location.hostname || '' ).trim();
		domain = domain.replace( /^https?:\/\//i, '' );
		domain = domain.replace( /\/.*$/, '' );
		domain = domain.replace( /:\d+$/, '' );
		return domain.toLowerCase();
	}

	function getSafeAppleDomain() {
		// Force merchant domain from current checkout host name.
		// This keeps browser-side validation aligned with the storefront domain.
		return normalizeAppleDomain( window.location.hostname );
	}

	function ensureAppleButton() {
		var existing = document.getElementById( 'applePayment' );
		if ( existing ) {
			return existing;
		}

		var holder = document.createElement( 'div' );
		holder.id = 'hesabe-applepay-holder';
		holder.style.marginTop = '12px';
		holder.style.display = 'none';

		var button = document.createElement( 'button' );
		button.id = 'applePayment';
		button.className = 'applePaybtn';
		button.type = 'button';
		button.setAttribute( 'aria-label', 'Pay with Apple Pay' );
		button.style.cssText = 'display:none;';
		button.textContent = '';

		holder.appendChild( button );

		var $brands = $( '.hesabe-direct-brands' ).first();
		if ( $brands.length ) {
			$brands.after( holder );
		} else {
			$( '#payment_method_hesabe' ).closest( 'li,wc-payment-method' ).append( holder );
		}

		return button;
	}

	function loadAppleSdk() {
		if ( window.ApplePaySession ) {
			return $.Deferred().resolve().promise();
		}
		var d = $.Deferred();
		var script = document.createElement( 'script' );
		script.src = APPLE_SDK_URL;
		script.onload = function () { d.resolve(); };
		script.onerror = function () { d.reject(); };
		document.body.appendChild( script );
		return d.promise();
	}

	function runHesabeAppleScript( apData ) {
		var d = $.Deferred();
		var button = ensureAppleButton();
		var scriptUrl = String( apData.base_url || '' ).replace( /\/$/, '' ) + '/applepay?data=' + encodeURIComponent( apData.data_token );

		$.get( scriptUrl )
			.done( function ( response ) {
				var safeDomain = normalizeAppleDomain( window.location.hostname );
				var code = String( response || '' ).replace( /<\/?script[^>]*>/gi, '' );
				code = code.replace(
					/(const|let|var)\s+domain\s*=\s*(['"`])[^'"`\r\n;]*\2\s*;?/,
					'$1 domain = "' + safeDomain + '";'
				);
				code = code.replace(
					/&domain="\s*\+\s*domain\s*\+\s*"/g,
					'&domain=' + safeDomain
				);
				code = code.replace(
					/domain=https?%3A%2F%2F[^&"'`]+/gi,
					'domain=' + encodeURIComponent( safeDomain )
				);
				code = code.replace(
					/domain=https?:\/\/[^&"'`]+/gi,
					'domain=' + safeDomain
				);
				code = code.replace(
					/domain=[^&"'`\s]+/gi,
					function ( match ) {
						// Keep plain FQDN only in all generated validation URLs.
						if ( /^domain=/.test( match ) ) {
							return 'domain=' + safeDomain;
						}
						return match;
					}
				);
				var inlineScript = document.createElement( 'script' );
				inlineScript.type = 'text/javascript';
				inlineScript.text = code;
				document.body.appendChild( inlineScript );

				$( '#hesabe-applepay-holder' ).show();
				hideOverlay();

				// Safari requires ApplePaySession to be created from a real user
				// gesture. Do not programmatically click here; show the real button
				// and let the customer tap it.
				function showWhenReady() {
					if ( button ) {
						$( button ).show();
					}
					d.resolve();
				}

				var observer = new MutationObserver( function () {
					if ( button && $( button ).is( ':visible' ) ) {
						observer.disconnect();
						showWhenReady();
					}
				} );
				observer.observe( button, { attributes: true, attributeFilter: [ 'style', 'class' ] } );
				setTimeout( function () {
					observer.disconnect();
					showWhenReady();
				}, 3000 );
			} )
			.fail( function () {
				hideOverlay();
				window.alert( 'Apple Pay could not start. Please try again or choose another payment method.' );
				d.reject();
			} );

		return d.promise();
	}

	/* ── V5-style Apple Pay bootstrap on checkout page ──────────────────── */
	function startApplePay() {
		showOverlay( 'Preparing Apple Pay...' );

		var $form    = $( 'form.checkout' );
		var formData = $form.serializeArray();
		if ( typeof wc_checkout_params !== 'undefined' && wc_checkout_params.checkout_nonce ) {
			formData.push( { name: 'security', value: wc_checkout_params.checkout_nonce } );
		}

		var checkoutDfd = $.Deferred();

		$.ajax( {
			type    : 'POST',
			url     : ( typeof wc_checkout_params !== 'undefined' && wc_checkout_params.checkout_url )
				? wc_checkout_params.checkout_url
				: '/?wc-ajax=checkout',
			data    : $.param( formData ),
			dataType: 'json',
			success : function ( result ) {
				if ( result && result.result === 'success' && result.redirect ) {
					var parsed = parseRedirect( result.redirect );
					if ( parsed.orderId ) {
						checkoutDfd.resolve( parsed );
					} else {
						checkoutDfd.reject( result );
					}
				} else {
					checkoutDfd.reject( result );
				}
			},
			error: function ( xhr ) {
				checkoutDfd.reject( { error: xhr.statusText } );
			},
		} );

		$.when( checkoutDfd )
			.then( function ( parsed ) {
				return $.ajax( {
					type: 'POST',
					url:  DATA.ajaxUrl || '',
					data: {
						action:    'hesabe_apple_validate',
						order_id:  parsed.orderId,
						order_key: parsed.orderKey,
						nonce:     DATA.nonce || '',
					},
					dataType: 'json',
				} );
			}, function ( err ) {
				hideOverlay();
				if ( err && err.messages ) {
					$( '.woocommerce-notices-wrapper' ).first().html( err.messages );
					$( 'html, body' ).animate( { scrollTop: 0 }, 400 );
				}
				return $.Deferred().reject( err );
			} )
			.then( function ( resp ) {
				if ( ! resp || ! resp.success || ! resp.data ) {
					hideOverlay();
					window.alert( 'Apple Pay could not start. Please try again or choose another payment method.' );
					return $.Deferred().reject( 'metadata' );
				}
				return loadAppleSdk().then( function () {
					return runHesabeAppleScript( resp.data );
				} );
			} );
	}

	/* ── Form-submit guard ──────────────────────────────────────────────── */
	function bindBrandCards() {
		$( document.body )
			.off( 'change.hesabeBrand', 'input[name="hesabe_brand"]' )
			.on( 'change.hesabeBrand', 'input[name="hesabe_brand"]', syncBrandSelectionUI );
		syncBrandSelectionUI();
	}

	function guardSubmit() {
		$( document.body ).on( 'checkout_place_order', function () {
			// Only intercept when Hesabe is the active gateway.
			var $gateway = $( '#payment_method_hesabe' );
			if ( $gateway.length && ! $gateway.is( ':checked' ) ) { return true; }

			var $apple = getAppleRadio();
			if ( ! $apple.length || ! $apple.is( ':checked' ) ) { return true; }

			startApplePay();
			return false; // Prevent WC from submitting the form via its own AJAX.
		} );

		// If WC shows an error (e.g. stock check failed), hide overlay.
		$( document.body ).on( 'checkout_error', function () { hideOverlay(); } );
	}

	/* ── Init ───────────────────────────────────────────────────────────── */
	function init() {
		injectOverlay();
		bindBrandCards();

		var $apple = getAppleRadio();
		if ( ! $apple.length ) {
			// Apple Pay radio not on page (Hosted mode or brand disabled).
			guardSubmit();
			return;
		}

		// Preload SDK so the first merchant-validation call is faster.
		preloadAppleSDK();

		if ( applePayKnownUnavailable() ) {
			markUnavailable( $apple );
		}

		guardSubmit();

		// Re-check after WC refreshes the payment section.
		$( document.body ).on( 'updated_checkout', function () {
			bindBrandCards();
			var $r = getAppleRadio();
			if ( $r.length && applePayKnownUnavailable() ) {
				markUnavailable( $r );
			}
		} );
	}

	$( document ).ready( init );
} )( jQuery );
