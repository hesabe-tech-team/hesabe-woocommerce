<?php
/**
 * WooCommerce Blocks payment method integration for Hesabe (single gateway).
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the Hesabe gateway with the WooCommerce Blocks checkout.
 */
class Hesabe_WC_Blocks_Hesabe extends AbstractPaymentMethodType {

	/**
	 * @var string
	 */
	protected $name = 'hesabe';

	/**
	 * @var \WC_Payment_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Initialize.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_hesabe_settings', array() );
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && $wc->payment_gateways() ) {
				$gateways      = $wc->payment_gateways()->payment_gateways();
				$this->gateway = isset( $gateways['hesabe'] ) ? $gateways['hesabe'] : null;
			}
		}
	}

	/**
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway instanceof \WC_Payment_Gateway && $this->gateway->is_available();
	}

	/**
	 * @return array<int, string>
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'hesabe-wc-blocks',
			HESABE_WC_URL . 'assets/js/hesabe-blocks.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
			),
			HESABE_WC_VERSION,
			true
		);
		return array( 'hesabe-wc-blocks' );
	}

	/**
	 * Data passed to the JS block component via wc.wcSettings.getSetting('hesabe_data').
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		$title       = __( 'Hesabe Payments', 'hesabe-for-woocommerce' );
		$description = __( 'Pay securely with Hesabe.', 'hesabe-for-woocommerce' );
		$supports    = array( 'products' );

		if ( $this->gateway instanceof \WC_Payment_Gateway ) {
			$title       = $this->gateway->get_title();
			$description = $this->gateway->get_description();
			$supports    = is_array( $this->gateway->supports ) ? $this->gateway->supports : $supports;
		}

		$is_direct = Hesabe_WC_Config::is_direct_mode();

		// Build the enabled-brand map for Direct mode: value => label.
		$brands = array();
		if ( $is_direct ) {
			$s = Hesabe_WC_Config::main_settings();
			if ( ! empty( $s['direct_knet'] ) && 'yes' === $s['direct_knet'] ) {
				$brands['knet'] = __( 'KNET', 'hesabe-for-woocommerce' );
			}
			if ( ! empty( $s['direct_visa'] ) && 'yes' === $s['direct_visa'] ) {
				$brands['visa'] = __( 'Visa / Mastercard', 'hesabe-for-woocommerce' );
			}
			if ( ! empty( $s['direct_apple'] ) && 'yes' === $s['direct_apple'] ) {
				$brands['apple'] = __( 'Apple Pay', 'hesabe-for-woocommerce' );
			}
		}

		return array(
			'title'        => $title,
			'description'  => $description,
			'supports'     => $supports,
			'is_direct'    => $is_direct,
			'brands'       => $brands,
			'select_title' => __( 'Select a payment method', 'hesabe-for-woocommerce' ),
		);
	}
}
