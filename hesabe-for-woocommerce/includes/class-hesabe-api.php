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
			$reason = self::refusal_reason( $body, $secret_key, $iv_key );
			if ( '' === $reason ) {
				/* translators: %d HTTP status */
				$message = sprintf( __( 'Checkout request failed (HTTP %d).', 'hesabe-for-woocommerce' ), $code );
			} else {
				/* translators: 1: HTTP status, 2: Hesabe's reason, such as "The email must be a valid email address." */
				$message = sprintf( __( 'Hesabe could not start the payment (HTTP %1$d): %2$s', 'hesabe-for-woocommerce' ), $code, $reason );
			}
			return new \WP_Error( 'hesabe_http', $message, array( 'status' => $code, 'body' => $body ) );
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

	/**
	 * Hesabe's reason for refusing a checkout, or '' when the answer has none.
	 *
	 * A refusal comes encrypted like any answer, for example HTTP 422 with
	 * `{"status":false,"code":506,"message":"The email must be a valid email address.",...}`
	 * for a customer email at example.com (sandbox, November 2026).
	 *
	 * @param string $body       Answer body.
	 * @param string $secret_key Secret key.
	 * @param string $iv_key     IV key.
	 * @return string
	 */
	private static function refusal_reason( $body, $secret_key, $iv_key ) {
		$body = trim( (string) $body );
		if ( '' !== $body && ctype_xdigit( $body ) ) {
			$body = (string) Hesabe_WC_Crypt::decrypt( $body, $secret_key, $iv_key );
		}
		$answer = json_decode( $body, true );
		if ( ! is_array( $answer ) || ! isset( $answer['message'] ) || ! is_string( $answer['message'] ) ) {
			return '';
		}
		return substr( sanitize_text_field( $answer['message'] ), 0, 200 );
	}
}
