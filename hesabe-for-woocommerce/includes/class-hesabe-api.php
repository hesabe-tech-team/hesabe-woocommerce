<?php
/**
 * Checkout API request helper.
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Hesabe_WC_API
 */
class Hesabe_WC_API {

	/**
	 * POST encrypted payload to Hesabe checkout endpoint and return decrypted JSON object or WP_Error.
	 *
	 * Checkout URLs per Hesabe: sandbox `https://sandbox.hesabe.com/checkout`, production
	 * `https://api.hesabe.com/checkout` — see https://developer.hesabe.com/docs/guides/direct-apple-pay
	 *
	 * @param string               $base_url    e.g. https://sandbox.hesabe.com
	 * @param string               $access_code Access code header.
	 * @param string               $secret_key  Secret key.
	 * @param string               $iv_key      IV key.
	 * @param array<string, mixed> $payload     Checkout request fields.
	 * @return object|\WP_Error
	 */
	public static function checkout( $base_url, $access_code, $secret_key, $iv_key, array $payload ) {
		$json       = wp_json_encode( $payload );
		$encrypted  = Hesabe_WC_Crypt::encrypt( $json, $secret_key, $iv_key );
		$checkout_url = trailingslashit( $base_url ) . 'checkout';

		$response = wp_remote_post(
			$checkout_url,
			array(
				'timeout'     => 45,
				'sslverify'   => true,
				'redirection' => 0,
				'headers'     => array(
					'accessCode'   => $access_code,
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
				),
				'body'        => 'data=' . $encrypted,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'hesabe_http', sprintf( /* translators: %d HTTP status */ __( 'Checkout request failed (HTTP %d).', 'hesabe-for-woocommerce' ), $code ), array( 'status' => $code, 'body' => $body ) );
		}

		$body = trim( $body );
		$decrypted = Hesabe_WC_Crypt::decrypt( $body, $secret_key, $iv_key );
		if ( false === $decrypted ) {
			return new \WP_Error( 'hesabe_decrypt', __( 'Could not decrypt checkout response.', 'hesabe-for-woocommerce' ) );
		}

		$data = json_decode( $decrypted );
		if ( null === $data ) {
			return new \WP_Error( 'hesabe_json', __( 'Invalid checkout response.', 'hesabe-for-woocommerce' ) );
		}

		return $data;
	}
}
