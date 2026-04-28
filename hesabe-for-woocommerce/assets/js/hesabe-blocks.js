/**
 * Hesabe — WooCommerce Blocks checkout integration.
 *
 * Direct mode: card-style brand selection (same UX as classic direct plugin).
 * Apple Pay hidden when wallet unavailable. Brand sent as hesabe_brand in paymentMethodData.
 */
( function () {
	var wcSettings   = window.wc && window.wc.wcSettings        ? window.wc.wcSettings        : null;
	var wcRegistry   = window.wc && window.wc.wcBlocksRegistry  ? window.wc.wcBlocksRegistry  : null;
	var wpElement    = window.wp && window.wp.element            ? window.wp.element            : null;
	var htmlEntities = window.wp && window.wp.htmlEntities       ? window.wp.htmlEntities       : null;

	if ( ! wcSettings || ! wcRegistry || ! wpElement || ! htmlEntities ) {
		return;
	}

	var createElement = wpElement.createElement;
	var useState      = wpElement.useState;
	var useEffect     = wpElement.useEffect;

	var gatewayData = wcSettings.getSetting( 'hesabe_data', null );
	if ( ! gatewayData ) {
		return;
	}

	var title        = htmlEntities.decodeEntities( gatewayData.title        || 'Hesabe' );
	var description  = htmlEntities.decodeEntities( gatewayData.description  || '' );
	var selectTitle  = htmlEntities.decodeEntities( gatewayData.select_title || 'Select a payment method' );
	var isDirect     = !! gatewayData.is_direct;
	var allBrands    = gatewayData.brands || {};

	function applePayAvailable() {
		return !! (
			window.ApplePaySession &&
			typeof window.ApplePaySession.canMakePayments === 'function' &&
			window.ApplePaySession.canMakePayments()
		);
	}

	function filterBrands( brands ) {
		var keys = Object.keys( brands || {} );
		var hasApple = keys.some( function ( k ) {
			return k.indexOf( 'apple' ) === 0;
		} );
		if ( ! hasApple ) {
			return brands || {};
		}
		if ( applePayAvailable() ) {
			return brands;
		}
		var filtered = {};
		Object.keys( brands ).forEach( function ( k ) {
			if ( k.indexOf( 'apple' ) !== 0 ) {
				filtered[ k ] = brands[ k ];
			}
		} );
		return filtered;
	}

	/** Compact icon strip per brand (matches classic checkout SVGs). */
	function brandIcon( key ) {
		var wrap = { width: 40, height: 24, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' };
		if ( key === 'knet' ) {
			return createElement(
				'svg',
				{ width: 40, height: 24, viewBox: '0 0 40 24', 'aria-hidden': 'true' },
				createElement( 'rect', { width: 40, height: 24, rx: 3, fill: '#0a6ebd' } ),
				createElement( 'text', { x: 20, y: 16, textAnchor: 'middle', fill: '#fff', fontSize: 8, fontFamily: 'system-ui,sans-serif', fontWeight: '700' }, 'KNET' )
			);
		}
		if ( key === 'visa' ) {
			return createElement(
				'svg',
				{ width: 40, height: 24, viewBox: '0 0 40 24', 'aria-hidden': 'true' },
				createElement( 'rect', { width: 40, height: 24, rx: 3, fill: '#1a1f71' } ),
				createElement( 'text', { x: 20, y: 16, textAnchor: 'middle', fill: '#fff', fontSize: 6.5, fontFamily: 'system-ui,sans-serif', fontWeight: '800', letterSpacing: '0.5px' }, 'VISA' )
			);
		}
		if ( key === 'apple' || key.indexOf( 'apple_' ) === 0 ) {
			return createElement(
				'svg',
				{ width: 28, height: 28, viewBox: '0 0 24 24', 'aria-hidden': 'true' },
				createElement( 'path', {
					fill: '#1d1d1f',
					d: 'M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z',
				} )
			);
		}
		return createElement( 'div', { style: wrap } );
	}

	var HesabeContent = function ( props ) {
		var eventRegistration = props.eventRegistration || {};
		var emitResponse      = props.emitResponse      || {};

		var brands = isDirect ? filterBrands( allBrands ) : {};
		var brandKeys = Object.keys( brands );
		var firstBrand = brandKeys.length > 0 ? brandKeys[ 0 ] : '';

		var stateArr = useState( firstBrand );
		var selectedBrand = stateArr[ 0 ];
		var setSelectedBrand = stateArr[ 1 ];

		// If Apple (or any) brand is not available, keep selection on a valid key.
		useEffect(
			function () {
				if ( ! brandKeys.length ) {
					return;
				}
				setSelectedBrand( function ( prev ) {
					if ( prev && brandKeys.indexOf( prev ) >= 0 ) {
						return prev;
					}
					return brandKeys[ 0 ];
				} );
			},
			[ brandKeys.join( ',' ) ] // eslint-disable-line
		);

		useEffect(
			function () {
				if ( ! isDirect || ! eventRegistration.onPaymentSetup ) {
					return;
				}
				var unsubscribe = eventRegistration.onPaymentSetup( function () {
					if ( isDirect && ! selectedBrand ) {
						return {
							type: emitResponse.responseTypes
								? emitResponse.responseTypes.ERROR
								: 'error',
							message: 'Please select a payment brand.',
						};
					}
					return {
						type: emitResponse.responseTypes
							? emitResponse.responseTypes.SUCCESS
							: 'success',
						meta: {
							paymentMethodData: {
								hesabe_brand: selectedBrand,
							},
						},
					};
				} );
				return unsubscribe;
			},
			[ selectedBrand ]
		);

		var children = [];

		if ( description ) {
			children.push(
				createElement( 'p', { key: 'desc', style: { margin: '0 0 10px 0' } }, description )
			);
		}

		if ( isDirect && brandKeys.length > 0 ) {
			var list = brandKeys.map( function ( value ) {
				var label    = htmlEntities.decodeEntities( brands[ value ] || value );
				var selected = selectedBrand === value;
				return createElement(
					'div',
					{
						key: value,
						className: 'hesabe-wc-block-brand',
						role: 'button',
						tabIndex: 0,
						onKeyDown: function ( ev ) {
							if ( ev.key === 'Enter' || ev.key === ' ' ) {
								ev.preventDefault();
								setSelectedBrand( value );
							}
						},
						onClick: function () { setSelectedBrand( value ); },
						style: {
							display: 'flex',
							alignItems: 'center',
							gap: '10px',
							marginBottom: '8px',
							padding: '10px 12px',
							border: '1px solid ' + ( selected ? '#2271b1' : '#d0d0d0' ),
							borderRadius: '6px',
							cursor: 'pointer',
							background: selected ? '#f0f6fc' : '#fff',
							fontWeight: 'normal',
							outline: 'none',
						},
					},
					createElement( 'input', {
						type: 'radio',
						name: 'hesabe_brand',
						value: value,
						checked: selected,
						onChange: function () { setSelectedBrand( value ); },
						onClick: function ( e ) { e.stopPropagation(); },
						style: { margin: 0 },
					} ),
					createElement( 'div', { style: { display: 'flex', alignItems: 'center' } }, brandIcon( value ) ),
					createElement( 'span', { style: { fontSize: '0.95em' } }, label )
				);
			} );

			children.push(
				createElement(
					'div',
					{ key: 'brands', className: 'hesabe-wc-block-brands' },
					createElement( 'div', { style: { fontWeight: 600, fontSize: '0.95em', marginBottom: '0.5rem' } }, selectTitle ),
					list
				)
			);
		} else if ( isDirect && brandKeys.length === 0 ) {
			children.push(
				createElement(
					'p',
					{ key: 'no-brands', style: { color: '#c00', margin: '8px 0 0' } },
					'No payment options are available. Please contact the store.'
				)
			);
		}

		return createElement( 'div', { className: 'hesabe-payment-fields' }, children );
	};

	wcRegistry.registerPaymentMethod( {
		name:      'hesabe',
		label:     title,
		ariaLabel: title,
		content:   createElement( HesabeContent, null ),
		edit:      createElement( 'div', {}, description ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: gatewayData.supports || [ 'products' ],
		},
	} );
} )();
