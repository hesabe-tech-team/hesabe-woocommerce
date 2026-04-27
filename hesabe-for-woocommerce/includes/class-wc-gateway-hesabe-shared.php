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
		// Inline Apple Pay AJAX: cancel order on wallet cancel.
		add_action( 'wp_ajax_hesabe_apple_cancel', array( __CLASS__, 'ajax_apple_cancel' ) );
		add_action( 'wp_ajax_nopriv_hesabe_apple_cancel', array( __CLASS__, 'ajax_apple_cancel' ) );
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

	/**
	 * AJAX: Cancel the pending WC order when user dismisses the Apple Pay sheet.
	 */
	public static function ajax_apple_cancel() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hesabe_apple_nonce' ) ) {
			wp_send_json_error( 'Security check failed.' );
		}

		$order_id  = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( $order && hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			$order->update_status( 'cancelled', __( 'Apple Pay cancelled by customer.', 'hesabe-for-woocommerce' ) );
		}

		wp_send_json_success();
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
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

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
		// It must be loaded as <script src> (not fetch+inject) so the same-origin
		// variable rendering works correctly. The script expects:
		//   document.querySelector(".applePaybtn")  → attach click + ApplePaySession
		//   document.getElementById("applePayment") → shown when canMakePaymentsWithActiveCard() = true
		$applepay_script_url = esc_url_raw( rtrim( $base_url, '/' ) . '/applepay?data=' . rawurlencode( (string) $token ) );
		$site_name           = esc_html( get_bloginfo( 'name' ) );
		$checkout_url        = esc_js( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . $site_name . ' — Apple Pay</title>
<link rel="preload" href="https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js" as="script" crossorigin="anonymous">
<link rel="preload" href="' . $applepay_script_url . '" as="script">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
 display:flex;align-items:center;justify-content:center;
 min-height:100vh;background:#000;color:#fff;
 font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",sans-serif;
}
.wrap{text-align:center;padding:2rem;max-width:380px;width:100%}
#hap-loading{font-size:.9rem;opacity:.75}
#hap-unavailable,#hap-cancelled,#hap-error{display:none;font-size:.9rem}
#hap-error{color:#ff6b6b}
#hap-cancelled a,#hap-unavailable a{color:#fff;text-decoration:underline}
.spinner{
 display:inline-block;width:22px;height:22px;margin-right:8px;
 border:2px solid rgba(255,255,255,.25);border-top-color:#fff;
 border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;
}
@keyframes spin{to{transform:rotate(360deg)}}
#applePayment{margin-top:1.5rem}
.applePaybtn{
 display:block;width:100%;height:52px;padding:0;
 background:#fff;color:transparent;border:none;border-radius:10px;
 cursor:pointer;-webkit-appearance:none;appearance:none;
 background-image:-webkit-named-image(apple-pay-logo-black);
 background-repeat:no-repeat;background-position:center;
 background-size:auto 60%;
 font-size:0;line-height:0;text-indent:-9999px;overflow:hidden;
}
.applePaybtn:active{opacity:.8}
</style>
</head>
<body>
<div class="wrap">

 <svg style="opacity:.8;margin-bottom:1.5rem" width="44" height="44" viewBox="0 0 24 24" fill="white">
  <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
 </svg>

 <div id="hap-loading"><span class="spinner"></span>Opening Apple Pay&hellip;</div>

 <div id="hap-unavailable">
  Apple Pay is not available on this device.<br><br>
  <a href="' . $checkout_url . '">Return to checkout</a> and choose another payment method.
 </div>

 <div id="hap-cancelled">
  Apple Pay was cancelled.<br><br>
  <a href="' . $checkout_url . '">Return to checkout</a>
 </div>

 <div id="hap-error"></div>

 <!--
   Required DOM structure for Hesabe Apple Pay script (applepay.blade.php):
     #applePayment  — shown by Hesabe script when canMakePaymentsWithActiveCard() = true
     .applePaybtn   — click handler is attached here by Hesabe script
 -->
 <div id="applePayment" style="display:none">
  <button class="applePaybtn" type="button" aria-label="Pay with Apple Pay"></button>
 </div>

</div>

<!-- Step 1: Apple Pay SDK -->
<script src="https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js" crossorigin="anonymous"></script>

<!--
  Step 2: Patch ApplePaySession BEFORE Hesabe script runs.

  Root cause of "stuck on Processing / Payment Failed":
    Hesabe applepay.blade.php has a bug — it does window.location.href = paymentUrl
    inside a Promise that never resolves, so applePaySession.completePayment() is
    never called. Apple Pay waits 30 s for the merchant response, then times out
    with "Payment Failed." The actual payment may have succeeded on Hesabe side.

  Fix: wrap the ApplePaySession constructor so that every new session:
    (a) registers a beforeunload listener that calls completePayment(SUCCESS)
        the instant the page begins navigating away — before JS context tears down.
    (b) intercepts the oncancel setter to redirect the user back to checkout
        instead of leaving them stranded on this page.
-->
<script>
(function(){
 if(!window.ApplePaySession) return;

 var _Orig        = window.ApplePaySession;
 var STATUS_OK    = _Orig.STATUS_SUCCESS;
 var checkoutUrl  = \'' . $checkout_url . '\';

 function HesabePatchedSession(version, paymentRequest) {
  /* Create the real session via the original constructor. */
  var session = new _Orig(version, paymentRequest);
  var done = false;

  /* Called once — either from beforeunload (navigation) or explicitly. */
  function completeOnce() {
   if(done) return;
   done = true;
   try { session.completePayment(STATUS_OK); } catch(e){}
   window.removeEventListener("beforeunload", completeOnce);
  }

  /* When the page navigates away (Hesabe script sets window.location.href),
     signal Apple Pay SUCCESS before the JS context is torn down. */
  window.addEventListener("beforeunload", completeOnce);

  /*
   * Override the oncancel setter so we can intercept cancellations.
   * Hesabe script does:  applePaySession.oncancel = function(e){…}
   * We wrap that assignment to also show the "cancelled" UI + redirect.
   */
  var _cancelFn = null;
  Object.defineProperty(session, "oncancel", {
   get: function(){ return _cancelFn; },
   set: function(fn){
    _cancelFn = function(event){
     done = true; /* prevent completePayment from beforeunload */
     window.removeEventListener("beforeunload", completeOnce);
     if(typeof fn === "function"){ try{ fn(event); }catch(e){} }
     /* Show cancelled UI then redirect back to checkout. */
     var loadEl = document.getElementById("hap-loading");
     var cancelEl = document.getElementById("hap-cancelled");
     if(loadEl)  loadEl.style.display  = "none";
     if(cancelEl) cancelEl.style.display = "block";
     setTimeout(function(){ window.location.href = checkoutUrl; }, 3000);
    };
   },
   configurable: true
  });

  return session; /* new operator returns this object, not `this` */
 }

 /* Copy prototype and all static properties (STATUS_SUCCESS, STATUS_FAILURE,
    canMakePayments, canMakePaymentsWithActiveCard, etc.) */
 HesabePatchedSession.prototype = _Orig.prototype;
 try {
  Object.getOwnPropertyNames(_Orig).forEach(function(name){
   if(name === "prototype" || name === "length" || name === "name") return;
   try{
    var d = Object.getOwnPropertyDescriptor(_Orig, name);
    if(!d) return;
    Object.defineProperty(HesabePatchedSession, name,
     typeof d.value === "function"
      ? { value: d.value.bind(_Orig), writable:true, configurable:true, enumerable:d.enumerable }
      : d
    );
   }catch(e){}
  });
 }catch(e){}

 window.ApplePaySession = HesabePatchedSession;
})();
</script>

<!-- Step 3: Hesabe init script — rendered JS with payment token and merchant config baked in -->
<script src="' . $applepay_script_url . '"></script>

<!-- Step 4: Auto-trigger wallet + unavailability fallback -->
<script>
(function(){
 var loadEl    = document.getElementById("hap-loading");
 var unavailEl = document.getElementById("hap-unavailable");
 var apDiv     = document.getElementById("applePayment");

 function showUnavailable(){
  if(loadEl)   loadEl.style.display   = "none";
  if(unavailEl) unavailEl.style.display = "block";
 }

 if(typeof ApplePaySession === "undefined"){
  showUnavailable();
  return;
 }

 function triggerWallet(){
  var btn = apDiv.querySelector(".applePaybtn");
  if(btn) btn.click();
 }

 var observer = new MutationObserver(function(){
  if(apDiv.style.display !== "none"){
   observer.disconnect();
   triggerWallet();
  }
 });
 observer.observe(apDiv, {attributes:true, attributeFilter:["style"]});

 if(apDiv.style.display !== "none"){
  observer.disconnect();
  triggerWallet();
  return;
 }

 setTimeout(function(){
  observer.disconnect();
  if(apDiv.style.display === "none") showUnavailable();
 }, 8000);

})();
</script>
</body>
</html>';
		// phpcs:enable
		exit;
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

		if ( $ok ) {
			$msg_class = 'success';
			$message   = __( 'Thank you. Your payment was successful.', 'hesabe-for-woocommerce' );
			if ( ! $order->is_paid() ) {
				$order->payment_complete();
				if ( isset( $info->paymentId ) ) {
					$order->update_meta_data( '_hesabe_payment_id', sanitize_text_field( (string) $info->paymentId ) );
				}
				$order->save();
			}
			if ( function_exists( 'WC' ) ) {
				$wc = WC();
				if ( $wc && $wc->cart ) {
					$wc->cart->empty_cart();
				}
			}
		} else {
			$order->update_status( 'failed', __( 'Hesabe payment failed or declined.', 'hesabe-for-woocommerce' ) );
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
			echo '<p>' . esc_html( sprintf( __( 'Payment could not start (Code %1$s — %2$s). Please go back and try again.', 'hesabe-for-woocommerce' ), $code, $message ) ) . '</p>';
			return;
		}

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
		$notify = home_url( '/wc-api/wc_hesabe' );

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
			'version'              => '2.0',
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
		 * Use this to add optional fields from the Direct Apple Pay / Checkout docs,
		 * e.g. `webhookUrl` for server-to-server notifications.
		 *
		 * @param array<string, mixed> $post_values  Request fields.
		 * @param WC_Order             $order         Order.
		 * @param string                 $payment_type  Hesabe paymentType.
		 * @param array<string, mixed> $c              Credentials.
		 */
		return apply_filters( 'hesabe_checkout_post_values', $post_values, $order, (string) $payment_type, $c );
	}
}
