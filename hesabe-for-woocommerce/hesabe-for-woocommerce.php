<?php
/**
 * Plugin Name: Hesabe for WooCommerce
 * Plugin URI: https://hesabe.com/
 * Description: Hesabe payment gateway for WooCommerce — hosted Hesabe checkout or direct payment method selection (KNET, Visa/Mastercard, Apple Pay).
 * Version: 1.0.13
 * Author: Hesabe
 * Text Domain: hesabe-for-woocommerce
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 11.2
 * License: GPL-3.0-or-later
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

define( 'HESABE_WC_VERSION', '1.0.13' );
define( 'HESABE_WC_FILE', __FILE__ );
define( 'HESABE_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'HESABE_WC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Sandbox defaults (Hesabe public sandbox — testing only).
 *
 * @see https://developer.hesabe.com/docs/support/sandbox-details
 */
define( 'HESABE_WC_SANDBOX_BASE_URL', 'https://sandbox.hesabe.com' );
define( 'HESABE_WC_PRODUCTION_BASE_URL', 'https://api.hesabe.com' );
// Legacy aliases kept for compatibility with older Hesabe integrations.
define( 'WC_HESABE_TEST_URL', HESABE_WC_SANDBOX_BASE_URL );
define( 'WC_HESABE_LIVE_URL', HESABE_WC_PRODUCTION_BASE_URL );
define( 'HESABE_WC_SANDBOX_APPLE_DOMAIN', 'test-woocommerce.hesabe.com' );
define( 'HESABE_WC_SANDBOX_MERCHANT_CODE', '842217' );
define( 'HESABE_WC_SANDBOX_ACCESS_CODE', 'c333729b-d060-4b74-a49d-7686a8353481' );
define( 'HESABE_WC_SANDBOX_SECRET_KEY', 'PkW64zMe5NVdrlPVNnjo2Jy9nOb7v1Xg' );
define( 'HESABE_WC_SANDBOX_IV_KEY', '5NVdrlPVNnjo2Jy9' );

require_once HESABE_WC_PATH . 'includes/class-hesabe-crypt.php';
require_once HESABE_WC_PATH . 'includes/class-hesabe-api.php';
require_once HESABE_WC_PATH . 'includes/class-hesabe-config.php';
require_once HESABE_WC_PATH . 'includes/class-hesabe-enquiry.php';
require_once HESABE_WC_PATH . 'includes/class-hesabe-settlement.php';
require_once HESABE_WC_PATH . 'includes/class-hesabe-webhook.php';

add_action( 'before_woocommerce_init', 'hesabe_wc_declare_compatibility' );
add_action( 'plugins_loaded', 'hesabe_wc_init', 20 );
add_action( 'woocommerce_blocks_loaded', 'hesabe_wc_register_blocks_integration' );

/**
 * Declare compatibility with custom order tables (HPOS) and the Cart and Checkout blocks.
 *
 * Without the blocks declaration, WooCommerce lists the plugin as neither compatible nor
 * incompatible with the blocks, although the plugin registers a Checkout block payment method.
 */
function hesabe_wc_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HESABE_WC_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HESABE_WC_FILE, true );
	}
}

/**
 * Bootstrap gateway after WooCommerce loads.
 */
function hesabe_wc_init() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	// Load only after WooCommerce defines WC_Payment_Gateway (avoid fatal if we load before WooCommerce).
	require_once HESABE_WC_PATH . 'includes/class-wc-gateway-hesabe-shared.php';
	require_once HESABE_WC_PATH . 'includes/class-wc-gateway-hesabe.php';

	add_filter( 'woocommerce_payment_gateways', 'hesabe_wc_add_gateway' );
	Hesabe_WC_Settlement::register();
}

/**
 * @param array<string, string> $methods Gateway classes.
 * @return array<string, string>
 */
function hesabe_wc_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_Hesabe';
	return $methods;
}

/**
 * Register Checkout Block payment method integration.
 */
function hesabe_wc_register_blocks_integration() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'WC_Gateway_Hesabe', false ) ) {
		require_once HESABE_WC_PATH . 'includes/class-wc-gateway-hesabe-shared.php';
		require_once HESABE_WC_PATH . 'includes/class-wc-gateway-hesabe.php';
	}

	require_once HESABE_WC_PATH . 'includes/class-hesabe-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new Hesabe_WC_Blocks_Hesabe() );
		}
	);
}
