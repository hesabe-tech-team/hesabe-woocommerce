<?php
/**
 * Shared Hesabe gateway behaviour (trait + return handler).
 *
 * Concrete gateways must extend WC_Payment_Gateway directly — not an abstract subclass —
 * or PHP 8 throws "Cannot call constructor" because WC_Payment_Gateway has no __construct().
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static handler for wc-api return callbacks and the Apple Pay init page.
 */
class Hesabe_WC_Return_Handler {

	/** Apple's Apple Pay SDK, as Apple serves it. */
	const APPLE_SDK_URL = 'https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js';

	/** Handle of the Apple Pay page's stylesheet. */
	const APPLE_PAGE_STYLE = 'hesabe-wc-apple-pay-page';

	/** Handles of the Apple Pay page's scripts, in the order they run. */
	const APPLE_SDK_SCRIPT     = 'hesabe-wc-apple-pay-sdk';
	const APPLE_SESSION_SCRIPT = 'hesabe-wc-apple-pay-session';
	const APPLE_INIT_SCRIPT    = 'hesabe-wc-apple-pay-init';
	const APPLE_WALLET_SCRIPT  = 'hesabe-wc-apple-pay-wallet';

	/**
	 * @var bool
	 */
	private static $hook_added = false;

	/**
	 * Register wc-api callbacks once.
	 */
	public static function maybe_register_hook() {
		if ( self::$hook_added ) {
			return;
		}
		// Standard return (KNET, Visa, Apple Pay post-payment).
		add_action( 'woocommerce_api_wc_hesabe', array( __CLASS__, 'handle_return' ) );
		// Apple Pay init page (kept as fallback; primary flow is now inline on checkout page).
		add_action( 'woocommerce_api_wc_hesabe_apple', array( __CLASS__, 'handle_apple_page' ) );
		// Inline Apple Pay AJAX: merchant validation proxy.
		add_action( 'wp_ajax_hesabe_apple_validate', array( __CLASS__, 'ajax_apple_validate' ) );
		add_action( 'wp_ajax_nopriv_hesabe_apple_validate', array( __CLASS__, 'ajax_apple_validate' ) );
		self::$hook_added = true;
	}

	// -------------------------------------------------------------------------
	// Inline Apple Pay — AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Merchant validation + return all data needed for onpaymentauthorized.
	 *
	 * Called from checkout page JS during ApplePaySession.onvalidatemerchant.
	 * Flow:
	 *   1. WC checkout AJAX already ran → created order + called Hesabe /checkout
	 *      → stored _hesabe_apple_token (encrypted data) + _hesabe_base_url in order meta.
	 *   2. We fetch {base_url}/applepay?data={token} to parse paymentToken, sessionId, serviceType.
	 *   3. The browser (not this PHP request) must GET {base_url}/applepayment?u=…&token=…
	 *      so the Origin header is the store domain — required for Hesabe CORS + Apple validation.
	 *      (Server-side wp_remote_get has no Origin; validation often fails or returns empty.)
	 *   4. We return payment_token, data_token, session_id, and URLs only — JS calls applepayment
	 *      then completeMerchantValidation( session ).
	 */
	public static function ajax_apple_validate() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hesabe_apple_nonce' ) ) {
			wp_send_json_error( 'Security check failed.' );
		}

		$order_id  = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		if ( ! $order_id ) {
			wp_send_json_error( 'Missing order id.' );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( 'Invalid order.' );
		}

		$data_token   = (string) $order->get_meta( '_hesabe_apple_token', true );
		$base_url     = (string) $order->get_meta( '_hesabe_base_url', true );
		$payment_type = (string) $order->get_meta( '_hesabe_payment_type', true );

		if ( ! $data_token || ! $base_url ) {
			wp_send_json_error( 'Apple Pay not initialised for this order.' );
		}

		// ── Step 1: fetch Hesabe's rendered applepay script to extract paymentToken etc. ──
		$applepay_url      = rtrim( $base_url, '/' ) . '/applepay?data=' . rawurlencode( $data_token );
		$applepay_response = wp_remote_get( $applepay_url, array( 'timeout' => 30, 'sslverify' => true ) );

		if ( is_wp_error( $applepay_response ) ) {
			wp_send_json_error( 'Apple Pay script fetch failed: ' . $applepay_response->get_error_message() );
		}

		$script_body = wp_remote_retrieve_body( $applepay_response );

		// paymentToken appears in the validation XHR URL:
		//   xhr.open("GET", "https://.../applepayment?u=" + valURL + "&token=TOKEN&domain=...")
		preg_match( '/applepayment[^"\']*?&token=([^&"\'`\s\\\\]+)/', $script_body, $m );
		$payment_token = isset( $m[1] ) ? trim( $m[1] ) : '';

		// serviceType appears as:  const serviceId = 'N';
		preg_match( '/[?&]serviceId=([0-9]+)/', $script_body, $m );
		$service_type = isset( $m[1] ) ? $m[1] : '1';

		// session_id appears in the payment URL template:  &session_id=SESS_ID`
		preg_match( '/[&`]session_id=([^"\'&`\s\\\\]+)/', $script_body, $m );
		$session_id = isset( $m[1] ) ? trim( $m[1] ) : uniqid( 'hap', true );

		if ( empty( $payment_token ) ) {
			wp_send_json_error( 'Could not extract payment token from Apple Pay script.' );
		}

		$domain = self::apple_pay_domain();

		wp_send_json_success(
			array(
				'payment_token' => $payment_token,
				'data_token'    => $data_token,
				'session_id'    => $session_id,
				'service_type'  => $service_type,
				'payment_type'  => $payment_type,
				'base_url'      => $base_url,
				'domain'        => $domain,
			)
		);
	}

	/**
	 * Current checkout host for Apple Pay domain validation.
	 *
	 * Apple requires a fully qualified domain name only: no scheme, no path,
	 * no port. Prefer the current request host over home_url() so staging sites
	 * behind a different WP Site URL still send the browser's checkout domain.
	 *
	 * @return string
	 */
	private static function apple_pay_domain() {
		$host = '';
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}
		if ( '' === $host ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$host = preg_replace( '#^https?://#i', '', $host );
		$host = preg_replace( '#/.*$#', '', $host );
		$host = preg_replace( '#:\d+$#', '', $host );
		return strtolower( trim( (string) $host ) );
	}

	/**
	 * Expose the same sanitized host for checkout payload construction.
	 *
	 * @return string
	 */
	public static function apple_pay_domain_for_payload() {
		return self::apple_pay_domain();
	}

	// -------------------------------------------------------------------------
	// Apple Pay init page
	// -------------------------------------------------------------------------

	/**
	 * Outputs the Apple Pay page (no WC layout, served on the merchant's whitelisted domain).
	 *
	 * How Hesabe's Apple Pay works (from source analysis):
	 *
	 *  GET {base_url}/applepay?data={token}  →  returns Blade-rendered JavaScript that:
	 *    1. Calls ApplePaySession.canMakePaymentsWithActiveCard(merchantIdentifier)
	 *       → when true, sets #applePayment display:block
	 *    2. Attaches ApplePaySession click handler to document.querySelector(".applePaybtn")
	 *    3. onvalidatemerchant  → GET {base_url}/applepayment?u={validationURL}&token={paymentToken}&...
	 *       Hesabe's server POSTs to Apple with merchant certificate → returns merchantSession
	 *       (CORS allowed because merchant domain is whitelisted in Hesabe's MerchantIntegration table)
	 *    4. onpaymentauthorized → window.location.href = {base_url}/payment?...&applePaymentToken=...
	 *       Hesabe processes the Apple token → redirects to our responseUrl (wc-api/wc_hesabe)
	 *
	 * Required DOM elements for Hesabe's script:
	 *   - div#applePayment  (hidden; shown by script when wallet is available)
	 *   - button.applePaybtn inside #applePayment  (click handler attached by script)
	 */
	public static function handle_apple_page() {
		// process_payment() redirects the customer to this page with the order's ID and key.
		// There is no form to carry a nonce: the order key authorises the request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$token    = $order->get_meta( '_hesabe_apple_token', true );
		$base_url = $order->get_meta( '_hesabe_base_url', true );

		if ( ! $token || ! $base_url ) {
			wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
			exit;
		}

		// Hesabe's /applepay?data={token} endpoint returns Blade-rendered JS.
		// It must be loaded as a script of its own (not fetch+inject) so the same-origin
		// variable rendering works correctly. The script expects:
		//   document.querySelector(".applePaybtn")  → attach click + ApplePaySession
		//   document.getElementById("applePayment") → shown when canMakePaymentsWithActiveCard() = true
		$applepay_script_url = rtrim( $base_url, '/' ) . '/applepay?data=' . rawurlencode( (string) $token );
		$checkout_url        = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );

		self::register_apple_page_assets( $applepay_script_url, $checkout_url );

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — Apple Pay</title>
<link rel="preload" href="<?php echo esc_url( self::APPLE_SDK_URL ); ?>" as="script" crossorigin="anonymous">
<link rel="preload" href="<?php echo esc_url( $applepay_script_url ); ?>" as="script">
		<?php wp_print_styles( self::APPLE_PAGE_STYLE ); ?>
</head>
<body>
<div class="wrap">
<div id="hap-loading"></div>
<div id="hap-unavailable"><?php esc_html_e( 'Apple Pay Not Enabled for selected method.', 'hesabe-for-woocommerce' ); ?></div>
<div id="hap-cancelled"></div>
<div id="hap-error"></div>
<div id="applePayment" style="display:none">
<button class="applePaybtn" type="button" aria-label="<?php esc_attr_e( 'Pay with Apple Pay', 'hesabe-for-woocommerce' ); ?>"></button>
</div>
</div>
		<?php wp_print_scripts( self::APPLE_WALLET_SCRIPT ); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Registers the Apple Pay page's stylesheet and its four scripts. Printing the last script
	 * prints all four, in the order they must run:
	 *
	 *  1. Apple's Apple Pay SDK.
	 *  2. hesabe-apple-pay-session.js wraps ApplePaySession before Hesabe's script creates one.
	 *  3. Hesabe's /applepay script for this payment.
	 *  4. hesabe-apple-pay-wallet.js opens the wallet once Hesabe's script shows the button.
	 *
	 * Apple's SDK and Hesabe's script are registered without a version, so WordPress adds no
	 * `ver` argument to the two remote URLs.
	 *
	 * @param string $applepay_script_url Hesabe's /applepay script for this payment.
	 * @param string $checkout_url        Where a customer who cancels the sheet is sent back to.
	 */
	private static function register_apple_page_assets( $applepay_script_url, $checkout_url ) {
		wp_register_style(
			self::APPLE_PAGE_STYLE,
			HESABE_WC_URL . 'assets/css/hesabe-apple-pay.css',
			array(),
			HESABE_WC_VERSION
		);

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Apple's URL carries its own version (v1).
		wp_register_script( self::APPLE_SDK_SCRIPT, self::APPLE_SDK_URL, array(), null, true );
		add_filter( 'script_loader_tag', array( __CLASS__, 'apple_sdk_crossorigin' ), 10, 2 );

		wp_register_script(
			self::APPLE_SESSION_SCRIPT,
			HESABE_WC_URL . 'assets/js/hesabe-apple-pay-session.js',
			array( self::APPLE_SDK_SCRIPT ),
			HESABE_WC_VERSION,
			true
		);
		wp_localize_script( self::APPLE_SESSION_SCRIPT, 'hesabeApplePayPage', array( 'checkoutUrl' => $checkout_url ) );

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The URL names one payment; Hesabe's endpoint takes no other argument.
		wp_register_script( self::APPLE_INIT_SCRIPT, $applepay_script_url, array( self::APPLE_SESSION_SCRIPT ), null, true );

		wp_register_script(
			self::APPLE_WALLET_SCRIPT,
			HESABE_WC_URL . 'assets/js/hesabe-apple-pay-wallet.js',
			array( self::APPLE_INIT_SCRIPT ),
			HESABE_WC_VERSION,
			true
		);
	}

	/**
	 * Adds `crossorigin="anonymous"` to the Apple Pay SDK's script tag, as Apple's own snippet
	 * has it, and so the tag matches the page's preload hint.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script's handle.
	 * @return string
	 */
	public static function apple_sdk_crossorigin( $tag, $handle ) {
		if ( self::APPLE_SDK_SCRIPT === $handle && false === strpos( $tag, 'crossorigin' ) ) {
			$tag = str_replace( ' src=', ' crossorigin="anonymous" src=', $tag );
		}
		return $tag;
	}

	// -------------------------------------------------------------------------
	// Standard payment return
	// -------------------------------------------------------------------------

	/**
	 * Handles Hesabe's responseUrl / failureUrl callback (all payment types).
	 */
	public static function handle_return() {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_add_notice' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$c = Hesabe_WC_Config::credentials();
		if ( ! $c['merchant_code'] || ! $c['access_code'] || ! $c['secret_key'] || ! $c['iv_key'] ) {
			wc_add_notice( __( 'Hesabe is not configured.', 'hesabe-for-woocommerce' ), 'error' );
			wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
			exit;
		}

		$msg_class = 'error';
		$message   = __( 'This transaction could not be completed. Please try again.', 'hesabe-for-woocommerce' );

		$response_data = isset( $_REQUEST['data'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['data'] ) ) : '';
		if ( '' === $response_data ) {
			wc_add_notice( $message, $msg_class );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$decrypted = Hesabe_WC_Crypt::decrypt( $response_data, $c['secret_key'], $c['iv_key'] );
		if ( false === $decrypted ) {
			wc_add_notice( $message, $msg_class );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$json = json_decode( $decrypted );
		if ( ! $json || ! isset( $json->response ) ) {
			wc_add_notice( $message, $msg_class );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$info     = $json->response;
		$order_id = isset( $info->variable1 ) ? absint( $info->variable1 ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wc_add_notice( $message, $msg_class );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order_status = isset( $info->resultCode ) ? (string) $info->resultCode : '';
		$order->add_order_note(
			sprintf(
				'Hesabe return: %s | amount: %s',
				$order_status,
				isset( $info->amount ) ? (string) $info->amount : ''
			)
		);

		$ok = ! empty( $json->status )
			&& in_array( $order_status, array( 'CAPTURED', 'ACCEPT', 'AUTHORIZED', 'PARTIALLY_CAPTURED', 'SUCCESS' ), true );

		// The return decides an order still awaiting payment. An order the webhook settled
		// first is checked again with the Transaction Enquiry, and a failed return never
		// changes a paid order, so the customer's page follows Hesabe's latest status.
		$paid = Hesabe_WC_Settlement::from_return( $order_id, $ok, $info );

		if ( $paid ) {
			$msg_class = 'success';
			$message   = __( 'Thank you. Your payment was successful.', 'hesabe-for-woocommerce' );
		}
		if ( $paid && $ok && function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && $wc->cart ) {
				$wc->cart->empty_cart();
			}
		}

		wc_add_notice( $message, $msg_class );
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$pm = $order->get_payment_method();
			$gw = null;
			if ( function_exists( 'WC' ) ) {
				$wc = WC();
				if ( $wc && $wc->payment_gateways() ) {
					$all = $wc->payment_gateways()->payment_gateways();
					$gw  = isset( $all[ $pm ] ) ? $all[ $pm ] : null;
				}
			}
			if ( $gw && is_a( $gw, 'WC_Payment_Gateway' ) ) {
				wp_safe_redirect( $gw->get_return_url( $order ) );
			} else {
				wp_safe_redirect( $order->get_checkout_order_received_url() );
			}
		} else {
			wp_safe_redirect( wc_get_checkout_url() );
		}
		exit;
	}
}

// =============================================================================
// Trait
// =============================================================================

/**
 * Trait: receipt page (KNET / Visa), checkout payload builder, hook registration.
 *
 * Apple Pay (Direct) follows the Hesabe “Direct Apple Pay” flow: encrypted checkout API,
 * merchant domain in `variable5`, `currency` for Apple Pay types, then Apple Pay on the
 * client. See: https://developer.hesabe.com/docs/guides/direct-apple-pay
 */
trait Hesabe_WC_Gateway_Shared {

	/**
	 * @return void
	 */
	protected function hesabe_register_hooks() {
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		// receipt_page is only used for KNET / Visa (paymentType 1/2). Apple Pay skips it.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ), 10, 1 );

		Hesabe_WC_Return_Handler::maybe_register_hook();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function hesabe_get_creds() {
		return Hesabe_WC_Config::credentials();
	}

	/**
	 * Pay page — used for KNET and Visa only (Apple Pay is handled differently).
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo esc_html__( 'Order not found.', 'hesabe-for-woocommerce' );
			return;
		}

		$c = $this->hesabe_get_creds();
		if ( ! $c['merchant_code'] || ! $c['access_code'] || ! $c['secret_key'] || ! $c['iv_key'] ) {
			echo esc_html__( 'Hesabe is not configured.', 'hesabe-for-woocommerce' );
			return;
		}

		$payment_type = (string) $order->get_meta( '_hesabe_payment_type', true );

		// Apple Pay types (9–14) are handled via process_apple_pay(); they should never reach here.
		$apple_types = array( '9', '10', '11', '12', '13', '14' );
		if ( in_array( $payment_type, $apple_types, true ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
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
			$order->add_order_note( 'Hesabe checkout error: ' . $result->get_error_message() );
			echo '<p>' . esc_html( $result->get_error_message() ) . '</p>';
			return;
		}

		if ( empty( $result->status ) || (int) $result->status !== 1 || empty( $result->response->data ) ) {
			$code    = isset( $result->code )    ? (string) $result->code    : '';
			$message = isset( $result->message ) ? (string) $result->message : __( 'Unknown error', 'hesabe-for-woocommerce' );
			$order->add_order_note(
				sprintf(
					'Hesabe checkout failed — status: %s | code: %s | message: %s | paymentType: %s | raw: %s',
					isset( $result->status ) ? (string) $result->status : '',
					$code,
					$message,
					$payment_type,
					wp_json_encode( $result )
				)
			);
			/* translators: 1: Hesabe's error code, 2: Hesabe's error message */
			echo '<p>' . esc_html( sprintf( __( 'Payment could not start (Code %1$s — %2$s). Please go back and try again.', 'hesabe-for-woocommerce' ), $code, $message ) ) . '</p>';
			return;
		}

		// The checkout's token names this payment in Hesabe's webhook and Transaction Enquiry.
		Hesabe_WC_Settlement::remember_checkout( $order, isset( $result->token ) ? (string) $result->token : '' );

		$token = (string) $result->response->data;
		wp_redirect( $c['base_url'] . '/payment?data=' . rawurlencode( $token ) );
		exit;
	}

	/**
	 * Builds the Hesabe checkout payload.
	 *
	 * For Apple Pay payment types (9–14), `variable5` must be the merchant domain
	 * (e.g. example.com) and `currency` is required, per
	 * {@link https://developer.hesabe.com/docs/guides/direct-apple-pay Direct Apple Pay}.
	 *
	 * API hosts: sandbox `https://sandbox.hesabe.com/checkout`, production
	 * `https://api.hesabe.com/checkout` (set via environment credentials).
	 *
	 * @param WC_Order             $order         Order.
	 * @param string               $payment_type  Hesabe paymentType code.
	 * @param array<string, mixed> $c             Credentials.
	 * @return array<string, mixed>
	 */
	protected function hesabe_build_checkout_payload( WC_Order $order, $payment_type, array $c ) {
		$data   = $order->get_data();
		$first  = isset( $data['billing']['first_name'] ) ? $data['billing']['first_name'] : '';
		$last   = isset( $data['billing']['last_name'] ) ? $data['billing']['last_name'] : '';
		$phone  = isset( $data['billing']['phone'] ) ? preg_replace( '/[^0-9]/', '', $data['billing']['phone'] ) : '';
		$email  = isset( $data['billing']['email'] ) ? $data['billing']['email'] : '';
		$amount = number_format( (float) $order->get_total(), 3, '.', '' );
		// WooCommerce's own URL for the endpoint: `/wc-api/wc_hesabe/` with pretty permalinks,
		// `/?wc-api=wc_hesabe` with plain ones, where `/wc-api/` answers 404.
		$notify = WC()->api_request_url( 'wc_hesabe' );

		// Apple Pay types: 9=MPGS, 10=CYBS, 11=KNET Debit, 12=KNET Credit, 13=KNET Intl, 14=AMEX
		// For these, variable5 must be the merchant domain (e.g. "example.com"), not email.
		$apple_pay_types = array( '9', '10', '11', '12', '13', '14' );
		$is_apple        = in_array( (string) $payment_type, $apple_pay_types, true );
		$variable5       = $is_apple ? Hesabe_WC_Return_Handler::apple_pay_domain_for_payload() : $email;

		$post_values = array(
			'merchantCode'         => $c['merchant_code'],
			'amount'               => $amount,
			'responseUrl'          => $notify,
			'failureUrl'           => $notify,
			'paymentType'          => (int) $payment_type,
			// 3.0 makes Hesabe sign its webhook (`x-signature`); a 2.0 webhook is unsigned.
			'version'              => '3.0',
			'webhookUrl'           => WC()->api_request_url( 'wc_hesabe_webhook' ),
			'orderReferenceNumber' => $order->get_id(),
			'variable1'            => (string) $order->get_id(),
			'variable2'            => isset( $data['version'] ) ? (string) $data['version'] : '',
			'variable3'            => trim( $first . ' ' . $last ),
			'variable4'            => $phone,
			'variable5'            => (string) $variable5,
			'name'                 => trim( $first . ' ' . $last ),
			'mobile_number'        => $phone,
		);

		if ( ! empty( $email ) && is_email( $email ) ) {
			$post_values['email'] = $email;
		}

		// Currency:
		// Apple Pay — always required by Hesabe.
		// Other types — only include when currency_convert is enabled and order is not KWD.
		if ( $is_apple ) {
			$post_values['currency'] = $order->get_currency() ? $order->get_currency() : 'KWD';
		} elseif ( ! empty( $c['currency_convert'] ) && $order->get_currency() && 'KWD' !== $order->get_currency() ) {
			$post_values['currency'] = $order->get_currency();
		}

		/**
		 * Filter the Hesabe checkout POST body before encryption.
		 *
		 * Use this to add optional fields from the Direct Apple Pay / Checkout docs.
		 *
		 * @param array<string, mixed> $post_values  Request fields.
		 * @param WC_Order             $order         Order.
		 * @param string                 $payment_type  Hesabe paymentType.
		 * @param array<string, mixed> $c              Credentials.
		 */
		return apply_filters( 'hesabe_checkout_post_values', $post_values, $order, (string) $payment_type, $c );
	}
}
