<?php
/**
 * Hesabe's Transaction Enquiry.
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asks Hesabe what it recorded for an order reference.
 *
 * `GET api/transaction/<reference>?isOrderReference=1` with the access code. A 404, or a
 * "not found" message, means no payment for the reference reached Hesabe: the customer has
 * not paid yet, or 3-D Secure declined the card, which Hesabe does not record.
 */
class Hesabe_WC_Enquiry {

	/**
	 * How long to wait for Hesabe, in seconds.
	 */
	const TIMEOUT_SECONDS = 20;

	/**
	 * Every payment attempt Hesabe holds for the reference.
	 *
	 * The answer's `results.data` lists the attempts, newest first, and `data` repeats the
	 * newest. Hesabe finds them by reference alone, so on an account shared by several shops,
	 * like the public sandbox, the list also holds other shops' payments for the same order
	 * number (November 2026); Hesabe_WC_Settlement keeps the order's own.
	 *
	 * @param string $reference The order reference sent at checkout: the order ID.
	 * @return array<int, array<string, mixed>>|WP_Error An empty list when Hesabe has none.
	 */
	public static function attempts( $reference ) {
		$c        = Hesabe_WC_Config::credentials();
		$response = wp_remote_get(
			trailingslashit( $c['base_url'] ) . 'api/transaction/' . rawurlencode( (string) $reference ) . '?isOrderReference=1',
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'accessCode' => $c['access_code'],
					'Accept'     => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the connection error */
			return new WP_Error( 'hesabe_enquiry', sprintf( __( 'Hesabe could not be reached: %s', 'hesabe-for-woocommerce' ), $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return array();
		}
		if ( 200 !== $status ) {
			/* translators: %d: HTTP status */
			return new WP_Error( 'hesabe_enquiry', sprintf( __( 'Hesabe answered HTTP %d.', 'hesabe-for-woocommerce' ), $status ) );
		}

		// The enquiry answers in plain JSON; an encrypted answer is decrypted, as the checkout's is.
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( '' !== $body && ctype_xdigit( $body ) ) {
			$body = (string) Hesabe_WC_Crypt::decrypt( $body, $c['secret_key'], $c['iv_key'] );
		}
		$answer = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $answer ) ) {
			return new WP_Error( 'hesabe_enquiry', __( 'Hesabe answered something other than JSON.', 'hesabe-for-woocommerce' ) );
		}
		if ( isset( $answer['status'] ) && false === $answer['status'] ) {
			$message = isset( $answer['message'] ) ? (string) $answer['message'] : '';
			if ( false !== stripos( $message, 'not found' ) ) {
				return array();
			}
			/* translators: %s: Hesabe's message */
			return new WP_Error( 'hesabe_enquiry', sprintf( __( 'Hesabe refused the enquiry: %s', 'hesabe-for-woocommerce' ), sanitize_text_field( $message ) ) );
		}

		$records = isset( $answer['results']['data'] ) && is_array( $answer['results']['data'] ) ? $answer['results']['data'] : array();
		if ( empty( $records ) && isset( $answer['data'] ) && is_array( $answer['data'] ) ) {
			$records = array( $answer['data'] );
		}
		$attempts = array();
		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['reference_number'] ) && (string) $record['reference_number'] === (string) $reference ) {
				$attempts[] = $record;
			}
		}
		return $attempts;
	}
}
