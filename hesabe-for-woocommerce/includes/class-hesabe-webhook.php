<?php
/**
 * Hesabe's webhook (Checkout API 3.0).
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives Hesabe's webhook at `wc-api/wc_hesabe_webhook`, the backup to the customer's return:
 * the checkout asks for it with every payment, so the order is updated even when the customer
 * never comes back to the shop.
 *
 * Only a webhook with a valid `x-signature` changes anything. Hesabe retries a delivery that
 * does not get a 200 up to three times, so the answer is 503 when the order is busy and the
 * next try should do the work.
 */
class Hesabe_WC_Webhook {

	/**
	 * Handles one delivery and answers it.
	 */
	public static function handle() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			self::answer( 405, array( 'received' => false ) );
		}

		$webhook   = json_decode( (string) file_get_contents( 'php://input' ), true, 512, JSON_BIGINT_AS_STRING );
		$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) : '';
		if ( ! is_array( $webhook ) || ! self::is_signature_valid( $webhook, $signature ) ) {
			wc_get_logger()->warning( 'Hesabe webhook refused: the signature is missing or does not match.', array( 'source' => 'hesabe' ) );
			self::answer( 401, array( 'received' => false ) );
		}

		$outcome = Hesabe_WC_Settlement::from_webhook( $webhook );
		if ( Hesabe_WC_Settlement::NOT_FOUND === $outcome ) {
			self::answer( 404, array( 'received' => false ) );
		}
		self::answer(
			Hesabe_WC_Settlement::BUSY === $outcome ? 503 : 200,
			array(
				'received' => true,
				'outcome'  => $outcome,
			)
		);
	}

	/**
	 * Checks the `x-signature` header Hesabe puts on Checkout API 3.0 webhooks.
	 *
	 * As observed on the sandbox in November 2026: HMAC-SHA256 in lowercase hex, keyed with the
	 * merchant's access code, over every top-level value of the JSON body except `blocks` (a
	 * display layout), in the order Hesabe sends them, joined without a separator. Webhooks for
	 * version 2.0 checkouts carry no signature, so they are never accepted.
	 *
	 * @param array<string, mixed> $webhook   The decoded body.
	 * @param string               $signature The `x-signature` header.
	 * @return bool
	 */
	public static function is_signature_valid( array $webhook, $signature ) {
		$credentials = Hesabe_WC_Config::credentials();
		$key         = (string) $credentials['access_code'];
		$signature   = strtolower( trim( (string) $signature ) );
		if ( '' === $key || '' === $signature ) {
			return false;
		}

		unset( $webhook['blocks'] );
		foreach ( $webhook as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				return false;
			}
		}
		// implode() turns numbers and booleans into text the way Hesabe's PHP sender does.
		return hash_equals( hash_hmac( 'sha256', implode( '', $webhook ), $key ), $signature );
	}

	/**
	 * Sends the JSON answer and ends the request.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   Answer.
	 */
	private static function answer( $status, array $body ) {
		wp_send_json( $body, $status );
	}
}
