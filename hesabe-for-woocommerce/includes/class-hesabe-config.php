<?php
/**
 * Shared Hesabe settings (stored under main gateway id `hesabe`).
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Hesabe_WC_Config
 */
class Hesabe_WC_Config {

	const OPTION_MAIN = 'woocommerce_hesabe_settings';

	/**
	 * Main settings array (API keys, integration mode, brand toggles).
	 *
	 * @return array<string, string>
	 */
	public static function main_settings() {
		return (array) get_option( self::OPTION_MAIN, array() );
	}

	/**
	 * @return bool
	 */
	public static function is_direct_mode() {
		$s = self::main_settings();
		return isset( $s['integration_mode'] ) && 'direct' === $s['integration_mode'];
	}

	/**
	 * @return bool
	 */
	public static function is_hosted_mode() {
		return ! self::is_direct_mode();
	}

	/**
	 * @param string $key direct_knet|direct_visa|direct_apple.
	 * @return bool
	 */
	public static function is_brand_enabled_in_panel( $key ) {
		$s = self::main_settings();
		return isset( $s[ $key ] ) && 'yes' === $s[ $key ];
	}

	/**
	 * Credentials for the active environment.
	 *
	 * Sandbox: reads sandbox_* fields (pre-filled with Hesabe public sandbox keys).
	 * Production: reads production_* fields (merchant-provided live keys).
	 *
	 * @return array{environment:string,base_url:string,merchant_code:string,access_code:string,secret_key:string,iv_key:string,currency_convert:bool}
	 */
	public static function credentials() {
		$s   = self::main_settings();
		$env = isset( $s['environment'] ) ? $s['environment'] : 'sandbox';

		if ( 'sandbox' === $env ) {
			$base = defined( 'WC_HESABE_TEST_URL' ) ? WC_HESABE_TEST_URL : HESABE_WC_SANDBOX_BASE_URL;
			// Fall back to built-in sandbox constants when the saved value is empty.
			$mc = ! empty( $s['sandbox_merchant_code'] ) ? $s['sandbox_merchant_code'] : HESABE_WC_SANDBOX_MERCHANT_CODE;
			$ac = ! empty( $s['sandbox_access_code'] )   ? $s['sandbox_access_code']   : HESABE_WC_SANDBOX_ACCESS_CODE;
			$sk = ! empty( $s['sandbox_secret_key'] )    ? $s['sandbox_secret_key']    : HESABE_WC_SANDBOX_SECRET_KEY;
			$iv = ! empty( $s['sandbox_iv_key'] )        ? $s['sandbox_iv_key']        : HESABE_WC_SANDBOX_IV_KEY;
		} else {
			$base = defined( 'WC_HESABE_LIVE_URL' ) ? WC_HESABE_LIVE_URL : HESABE_WC_PRODUCTION_BASE_URL;
			$mc   = isset( $s['production_merchant_code'] ) ? $s['production_merchant_code'] : '';
			$ac   = isset( $s['production_access_code'] )   ? $s['production_access_code']   : '';
			$sk   = isset( $s['production_secret_key'] )    ? $s['production_secret_key']    : '';
			$iv   = isset( $s['production_iv_key'] )        ? $s['production_iv_key']        : '';
		}

		$cc = ! empty( $s['currency_convert'] ) && 'yes' === $s['currency_convert'];

		return array(
			'environment'      => $env,
			'base_url'         => $base,
			'merchant_code'    => $mc,
			'access_code'      => $ac,
			'secret_key'       => $sk,
			'iv_key'           => $iv,
			'currency_convert' => $cc,
		);
	}
}
