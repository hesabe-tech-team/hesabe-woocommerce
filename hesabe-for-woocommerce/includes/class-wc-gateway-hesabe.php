<?php
/**
 * Hesabe WooCommerce gateway — single entry in admin.
 *
 * Hosted mode  : one "Hesabe Payments" option at checkout → Hesabe landing page.
 * Direct mode  : inline radio buttons (KNET / Visa / Apple Pay) → Hesabe by brand.
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main (and only) gateway class for Hesabe.
 */
class WC_Gateway_Hesabe extends WC_Payment_Gateway {

	use Hesabe_WC_Gateway_Shared;

	/**
	 * WC_Gateway_Hesabe constructor.
	 */
	public function __construct() {
		$this->id                 = 'hesabe';
		$this->icon               = HESABE_WC_URL . 'assets/images/hesabe-new.png';
		$this->method_title       = __( 'Hesabe', 'hesabe-for-woocommerce' );
		$this->method_description = __( 'Hesabe payment gateway. Use Hosted to send customers to the Hesabe page, or Direct to show KNET, Visa/Mastercard and Apple Pay as inline options at checkout.', 'hesabe-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// Show inline fields at checkout only when Direct mode is active.
		$this->has_fields = Hesabe_WC_Config::is_direct_mode();

		$this->hesabe_register_hooks();
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'checkout_scripts' ) );
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	/**
	 * @param string $hook_suffix Admin hook.
	 */
	public function admin_scripts( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			'hesabe-wc-admin',
			HESABE_WC_URL . 'assets/js/admin-hesabe.js',
			array( 'jquery' ),
			HESABE_WC_VERSION,
			true
		);
	}

	/**
	 * Enqueue checkout JS and localise Apple Pay configuration.
	 */
	public function checkout_scripts() {
		if ( ! is_checkout() || ! $this->is_available() ) {
			return;
		}
		wp_enqueue_style(
			'hesabe-wc-checkout-style',
			HESABE_WC_URL . 'assets/css/hesabe-checkout.css',
			array(),
			HESABE_WC_VERSION
		);
		wp_enqueue_script(
			'hesabe-wc-checkout',
			HESABE_WC_URL . 'assets/js/hesabe-checkout.js',
			array( 'jquery' ),
			HESABE_WC_VERSION,
			true
		);

		// Pass data needed by the inline Apple Pay session.
		wp_localize_script(
			'hesabe-wc-checkout',
			'hesabeApplePayData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'hesabe_apple_nonce' ),
				'currency'    => get_woocommerce_currency(),
				'decimals'    => (string) wc_get_price_decimals(),
				'countryCode' => apply_filters( 'hesabe_apple_pay_country_code', 'KW' ),
				'storeName'   => get_bloginfo( 'name' ),
				'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ),
			)
		);
	}

	/**
	 * Settings form (API keys, mode, brand toggles, credentials).
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Hesabe Payments', 'hesabe-for-woocommerce' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Checkout title', 'hesabe-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Label shown to customers at checkout.', 'hesabe-for-woocommerce' ),
				'default'     => __( 'Hesabe Payments', 'hesabe-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'   => __( 'Checkout description', 'hesabe-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely via Hesabe.', 'hesabe-for-woocommerce' ),
			),
			'environment'      => array(
				'title'   => __( 'Environment', 'hesabe-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'sandbox',
				'options' => array(
					'sandbox'    => __( 'Sandbox (testing)', 'hesabe-for-woocommerce' ),
					'production' => __( 'Production', 'hesabe-for-woocommerce' ),
				),
			),
			'integration_mode' => array(
				'title'       => __( 'Integration mode', 'hesabe-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Hosted: customer is sent to the Hesabe payment page. Direct: KNET / Visa / Apple Pay radio buttons appear in the checkout form.', 'hesabe-for-woocommerce' ),
				'default'     => 'hosted',
				'options'     => array(
					'hosted' => __( 'Hosted — Hesabe payment page', 'hesabe-for-woocommerce' ),
					'direct' => __( 'Direct — inline brand selection', 'hesabe-for-woocommerce' ),
				),
				'class'       => 'hesabe-wc-integration-mode',
			),
			'direct_knet'      => array(
				'title'   => __( 'Direct: KNET', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show KNET option', 'hesabe-for-woocommerce' ),
				'default' => 'yes',
				'class'   => 'hesabe-wc-direct-row',
			),
			'direct_visa'      => array(
				'title'   => __( 'Direct: Visa / Mastercard', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Visa / Mastercard option', 'hesabe-for-woocommerce' ),
				'default' => 'yes',
				'class'   => 'hesabe-wc-direct-row',
			),
			'apple_pay_info'      => array(
				'title'       => __( 'Direct: Apple Pay', 'hesabe-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: link to Direct Apple Pay documentation */
					__( 'Per Hesabe: enable Apple Pay on your merchant account, whitelist your domain, and place Apple’s domain verification file at %s. See the %s guide.', 'hesabe-for-woocommerce' ),
					'<code>.well-known/apple-developer-merchantid-domain-association</code>',
					'<a href="https://developer.hesabe.com/docs/guides/direct-apple-pay" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Direct Apple Pay', 'hesabe-for-woocommerce' ) . '</a>'
				),
				'class'       => 'hesabe-wc-direct-row',
			),
			'apple_pay_types_title' => array(
				'title'       => __( 'Apple Pay types', 'hesabe-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: link to same documentation page (paymentType list) */
					__( 'Enable one or more types that are active on your Hesabe account. See the paymentType table in the %1$s. Contact support@hesabe.com to confirm.', 'hesabe-for-woocommerce' ),
					'<a href="https://developer.hesabe.com/docs/guides/direct-apple-pay" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Direct Apple Pay documentation', 'hesabe-for-woocommerce' ) . '</a>'
				),
				'class'       => 'hesabe-wc-direct-row',
			),
			'apple_pay_type_9' => array(
				'title'   => __( 'MPGS Apple Pay (9)', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable MPGS Apple Pay', 'hesabe-for-woocommerce' ),
				'default' => 'no',
				'class'   => 'hesabe-wc-direct-row',
			),
			'apple_pay_type_10' => array(
				'title'   => __( 'CYBS Apple Pay (10)', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CYBS Apple Pay', 'hesabe-for-woocommerce' ),
				'default' => 'no',
				'class'   => 'hesabe-wc-direct-row',
			),
			'apple_pay_type_11' => array(
				'title'   => __( 'KNET Debit Apple Pay (11)', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable KNET Debit Apple Pay', 'hesabe-for-woocommerce' ),
				'default' => 'no',
				'class'   => 'hesabe-wc-direct-row',
			),
			'apple_pay_type_12' => array(
				'title'   => __( 'KNET Credit Apple Pay (12)', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable KNET Credit Apple Pay', 'hesabe-for-woocommerce' ),
				'default' => 'no',
				'class'   => 'hesabe-wc-direct-row',
			),
			'apple_pay_type_13' => array(
				'title'   => __( 'KNET Apple Pay International (13)', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable KNET Apple Pay International', 'hesabe-for-woocommerce' ),
				'default' => 'no',
				'class'   => 'hesabe-wc-direct-row',
			),
			'apple_pay_type_14' => array(
				'title'   => __( 'AMEX Apple Pay International (14)', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable AMEX Apple Pay International', 'hesabe-for-woocommerce' ),
				'default' => 'no',
				'class'   => 'hesabe-wc-direct-row',
			),
			'currency_convert'        => array(
				'title'   => __( 'Currency converter', 'hesabe-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send currency when order currency is not KWD', 'hesabe-for-woocommerce' ),
				'default' => 'no',
			),

			// ── Sandbox credentials ──────────────────────────────────────────
			'sandbox_credentials_title' => array(
				'title' => __( 'Sandbox credentials', 'hesabe-for-woocommerce' ),
				'type'  => 'title',
				'description' => __( 'Used when Environment is set to Sandbox. Pre-filled with Hesabe public sandbox keys.', 'hesabe-for-woocommerce' ),
			),
			'sandbox_merchant_code'  => array(
				'title'   => __( 'Sandbox merchant code', 'hesabe-for-woocommerce' ),
				'type'    => 'text',
				'default' => HESABE_WC_SANDBOX_MERCHANT_CODE,
			),
			'sandbox_access_code'    => array(
				'title'   => __( 'Sandbox access code', 'hesabe-for-woocommerce' ),
				'type'    => 'text',
				'default' => HESABE_WC_SANDBOX_ACCESS_CODE,
			),
			'sandbox_secret_key'     => array(
				'title'   => __( 'Sandbox secret key', 'hesabe-for-woocommerce' ),
				'type'    => 'password',
				'default' => HESABE_WC_SANDBOX_SECRET_KEY,
			),
			'sandbox_iv_key'         => array(
				'title'   => __( 'Sandbox IV key', 'hesabe-for-woocommerce' ),
				'type'    => 'text',
				'default' => HESABE_WC_SANDBOX_IV_KEY,
			),

			// ── Production credentials ───────────────────────────────────────
			'production_credentials_title' => array(
				'title'       => __( 'Production credentials', 'hesabe-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Used when Environment is set to Production. Enter your live Hesabe merchant keys here.', 'hesabe-for-woocommerce' ),
			),
			'production_merchant_code'  => array(
				'title'   => __( 'Production merchant code', 'hesabe-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'production_access_code'    => array(
				'title'   => __( 'Production access code', 'hesabe-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'production_secret_key'     => array(
				'title'   => __( 'Production secret key', 'hesabe-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'production_iv_key'         => array(
				'title'   => __( 'Production IV key', 'hesabe-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Availability
	// -------------------------------------------------------------------------

	/**
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		$c = Hesabe_WC_Config::credentials();
		if ( ! $c['merchant_code'] || ! $c['access_code'] || ! $c['secret_key'] || ! $c['iv_key'] ) {
			return false;
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Checkout fields (Direct mode)
	// -------------------------------------------------------------------------

	/**
	 * Render checkout payment fields.
	 *
	 * Hosted mode: description text only.
	 * Direct mode: brand radio buttons (KNET / Visa / Apple Pay).
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( ! Hesabe_WC_Config::is_direct_mode() ) {
			return;
		}

		$brands = $this->get_enabled_direct_brands();
		if ( empty( $brands ) ) {
			echo '<p>' . esc_html__( 'No payment brands are enabled. Please contact the store administrator.', 'hesabe-for-woocommerce' ) . '</p>';
			return;
		}

		$legend = __( 'Select a payment method', 'hesabe-for-woocommerce' );
		$lid    = 'hesabe-direct-legend';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is static, labels escaped below.
		echo '<fieldset class="hesabe-direct-brands" role="group" aria-labelledby="' . esc_attr( $lid ) . '">';
		printf( '<div class="hesabe-direct-brands__title" id="%s">%s</div>', esc_attr( $lid ), esc_html( $legend ) );
		echo '<ul class="hesabe-direct-brands__list" role="radiogroup" aria-label="' . esc_attr( $legend ) . '">';

		$first = true;
		foreach ( $brands as $value => $label ) {
			$id   = 'hesabe_brand_' . esc_attr( $value );
			$lic  = 'hesabe-brand-card';
			if ( $first ) {
				$lic .= ' hesabe-brand-card--selected';
			}
			printf(
				'<li class="%1$s" data-hesabe-brand="%2$s">',
				esc_attr( $lic ),
				esc_attr( $value )
			);
			printf(
				'<label for="%1$s" class="hesabe-brand-card__label" style="display:flex;align-items:center;gap:0.75rem;width:100%%;margin:0;cursor:pointer;">',
				esc_attr( $id )
			);
			printf(
				'<input type="radio" id="%1$s" name="hesabe_brand" value="%2$s" %3$s />',
				esc_attr( $id ),
				esc_attr( $value ),
				$first ? 'checked="checked" ' : ''
			);
			echo '<span class="hesabe-brand-card__icon">' . $this->hesabe_direct_brand_icon_svg( (string) $value ) . '</span>';
			printf( '<span class="hesabe-brand-card__text">%s</span>', esc_html( $label ) );
			echo '</label></li>';
			$first = false;
		}
		// phpcs:enable
		echo '</ul></fieldset>';
	}

	/**
	 * Small inline SVGs for each brand (legacy direct plugin used PNGs).
	 *
	 * @param string $key knet|visa|apple.
	 * @return string
	 */
	private function hesabe_direct_brand_icon_svg( $key ) {
		if ( 0 === strpos( (string) $key, 'apple_' ) ) {
			$key = 'apple';
		}
		switch ( $key ) {
			case 'knet':
				return '<svg class="hesabe-icon" width="40" height="24" viewBox="0 0 40 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><rect width="40" height="24" rx="3" fill="#0a6ebd"/><text x="20" y="16" text-anchor="middle" fill="#fff" font-size="8" font-family="system-ui,sans-serif" font-weight="700">KNET</text></svg>';
			case 'visa':
				return '<svg class="hesabe-icon" width="40" height="24" viewBox="0 0 40 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><rect width="40" height="24" rx="3" fill="#1a1f71"/><text x="20" y="16" text-anchor="middle" fill="#fff" font-size="6.5" font-family="system-ui,sans-serif" font-weight="800" letter-spacing="0.5px">VISA</text></svg>';
			case 'apple':
				return '<svg class="hesabe-icon" width="40" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" fill="#1d1d1f"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>';
		}
		return '';
	}

	/**
	 * Returns enabled brand options for Direct mode as value => label.
	 *
	 * @return array<string, string>
	 */
	private function get_enabled_direct_brands() {
		$brands = array();
		if ( 'yes' === $this->get_option( 'direct_knet', 'yes' ) ) {
			$brands['knet'] = __( 'KNET', 'hesabe-for-woocommerce' );
		}
		if ( 'yes' === $this->get_option( 'direct_visa', 'yes' ) ) {
			$brands['visa'] = __( 'Visa / Mastercard', 'hesabe-for-woocommerce' );
		}
		$options = $this->get_apple_pay_type_options();
		foreach ( Hesabe_WC_Config::enabled_apple_pay_types() as $type ) {
			$label = isset( $options[ $type ] ) ? $options[ $type ] : sprintf( __( 'Apple Pay (%s)', 'hesabe-for-woocommerce' ), $type );
			$brands[ 'apple_' . $type ] = $label;
		}
		return $brands;
	}

	/**
	 * Selected brand from classic checkout POST or Blocks payment_data.
	 *
	 * @return string
	 */
	private function get_selected_hesabe_brand() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['hesabe_brand'] ) ) {
			return sanitize_key( wp_unslash( $_POST['hesabe_brand'] ) );
		}

		// WooCommerce Blocks checkout sends custom fields in payment_data.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['payment_data'] ) && is_array( $_POST['payment_data'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['payment_data'] ) as $item ) {
				if ( is_array( $item ) && isset( $item['key'], $item['value'] ) && 'hesabe_brand' === $item['key'] ) {
					return sanitize_key( (string) $item['value'] );
				}
			}
		}

		return '';
	}

	/**
	 * Resolve Hesabe paymentType from Direct mode brand selection.
	 *
	 * @return string|null Payment type code or null when unresolved.
	 */
	private function resolve_direct_payment_type() {
		$apple_types = Hesabe_WC_Config::enabled_apple_pay_types();
		$selected    = $this->get_selected_hesabe_brand();

		if ( 'apple' === $selected && ! empty( $apple_types ) ) {
			$selected = 'apple_' . $apple_types[0];
		}

		if ( preg_match( '/^apple_([0-9]{1,2})$/', $selected, $m ) ) {
			$candidate = (string) $m[1];
			if ( in_array( $candidate, $apple_types, true ) ) {
				return $candidate;
			}

			wc_add_notice( __( 'Selected Apple Pay type is not enabled. Please choose another payment method.', 'hesabe-for-woocommerce' ), 'error' );
			return null;
		}

		if ( 'knet' === $selected ) {
			return '1';
		}

		if ( 'visa' === $selected ) {
			return '2';
		}

		$brands = $this->get_enabled_direct_brands();
		if ( empty( $brands ) ) {
			wc_add_notice( __( 'No payment brands are enabled. Please contact the store administrator.', 'hesabe-for-woocommerce' ), 'error' );
			return null;
		}

		$fallback = (string) array_key_first( $brands );
		if ( preg_match( '/^apple_([0-9]{1,2})$/', $fallback, $m ) ) {
			$candidate = (string) $m[1];
			if ( in_array( $candidate, $apple_types, true ) ) {
				return $candidate;
			}
		}

		if ( 'visa' === $fallback ) {
			return '2';
		}

		if ( 'knet' === $fallback ) {
			return '1';
		}

		return '1';
	}

	/**
	 * Apple Pay paymentType labels.
	 *
	 * @return array<string, string>
	 */
	private function get_apple_pay_type_options() {
		return array(
			'9'  => __( 'MPGS Apple Pay', 'hesabe-for-woocommerce' ),
			'10' => __( 'CYBS Apple Pay', 'hesabe-for-woocommerce' ),
			'11' => __( 'KNET Debit Apple Pay', 'hesabe-for-woocommerce' ),
			'12' => __( 'KNET Credit Apple Pay', 'hesabe-for-woocommerce' ),
			'13' => __( 'KNET Apple Pay International', 'hesabe-for-woocommerce' ),
			'14' => __( 'AMEX Apple Pay International', 'hesabe-for-woocommerce' ),
		);
	}

	// -------------------------------------------------------------------------
	// Payment processing
	// -------------------------------------------------------------------------

	/**
	 * @param int $order_id Order ID.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		// Resolve payment type from mode + brand selection.
		if ( Hesabe_WC_Config::is_direct_mode() ) {
			$payment_type = $this->resolve_direct_payment_type();
			if ( null === $payment_type ) {
				return array( 'result' => 'failure' );
			}
		} else {
			$payment_type = '0';
		}

		$order->update_meta_data( '_hesabe_payment_type', $payment_type );
		$order->save();

		// Apple Pay types (9-14): skip the WC pay page, call Hesabe API now,
		// redirect directly to our minimal Apple Pay init page.
		$apple_types = array( '9', '10', '11', '12', '13', '14' );
		if ( in_array( $payment_type, $apple_types, true ) ) {
			return $this->process_apple_pay( $order, $payment_type );
		}

		// KNET / Visa / Hosted: go through the WC pay page (receipt_page hook).
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Calls Hesabe API for Apple Pay and returns redirect to the minimal Apple Pay init page.
	 * Handles all Apple Pay types (9=MPGS, 10=CYBS, 11-14=KNET/AMEX variants).
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $payment_type '9'–'14'.
	 * @return array<string, string>
	 */
	private function process_apple_pay( WC_Order $order, $payment_type ) {
		$c = $this->hesabe_get_creds();
		if ( ! $c['merchant_code'] || ! $c['access_code'] || ! $c['secret_key'] || ! $c['iv_key'] ) {
			wc_add_notice( __( 'Hesabe is not configured.', 'hesabe-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payload = $this->hesabe_build_checkout_payload( $order, $payment_type, $c );
		$result  = Hesabe_WC_API::checkout(
			$c['base_url'],
			$c['access_code'],
			$c['secret_key'],
			$c['iv_key'],
			$payload
		);

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'Hesabe Apple Pay checkout error: ' . $result->get_error_message() );
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $result->status ) || (int) $result->status !== 1 || empty( $result->response->data ) ) {
			$code    = isset( $result->code )    ? (string) $result->code    : '';
			$msg     = isset( $result->message ) ? (string) $result->message : 'Apple Pay could not start.';
			$status  = isset( $result->status )  ? (string) $result->status  : '';

			// Full response to order notes for admin diagnosis.
			$order->add_order_note(
				sprintf(
					'Hesabe Apple Pay API error — status: %s | code: %s | message: %s | paymentType: %s | raw: %s',
					$status,
					$code,
					$msg,
					$payment_type,
					wp_json_encode( $result )
				)
			);
			$order->save();

			// "Invalid Payment Method" means the Apple Pay type is not enabled on your Hesabe account.
			// Contact support@hesabe.com or itsupport@hesabe.com to enable it.
			if ( false !== stripos( $msg, 'Invalid Payment Method' ) || false !== stripos( $msg, 'invalid payment' ) ) {
				$user_msg = __( 'Apple Pay is not enabled on this store yet. Please choose KNET or Visa/Mastercard, or contact the store administrator.', 'hesabe-for-woocommerce' );
			} else {
				$user_msg = sprintf( __( 'Apple Pay could not start: %s', 'hesabe-for-woocommerce' ), $msg );
			}

			wc_add_notice( $user_msg, 'error' );
			return array( 'result' => 'failure' );
		}

		$token = (string) $result->response->data;
		$order->update_meta_data( '_hesabe_apple_token', $token );
		$order->update_meta_data( '_hesabe_base_url', $c['base_url'] );
		$order->save();

		// Redirect to our Apple Pay init page (woocommerce_api_wc_hesabe_apple).
		$apple_page_url = add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( 'wc_hesabe_apple' )
		);

		return array(
			'result'   => 'success',
			'redirect' => $apple_page_url,
		);
	}
}
