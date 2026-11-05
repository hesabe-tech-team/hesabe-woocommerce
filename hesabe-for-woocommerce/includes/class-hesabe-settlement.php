<?php
/**
 * Applies what Hesabe reports about a payment to its WooCommerce order.
 *
 * @package Hesabe_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies what Hesabe reports about a payment to its order.
 *
 * Two things report a payment: the customer's return and Hesabe's webhook. The return comes
 * first: it decides an order still awaiting
 * payment from what it carried, as the plugin always has. The webhook is the backup for a
 * customer who never comes back, whose order WooCommerce would otherwise cancel when its
 * "Hold stock" time runs out. The two often land within a second of each other, and Hesabe
 * retries a webhook, so every change happens under a per-order lock. When the webhook settled
 * the order before the customer came back, the return checks it again with the Transaction
 * Enquiry. A failure report never changes a paid order.
 */
class Hesabe_WC_Settlement {

	/** The order is now paid. */
	const PAID = 'paid';

	/** Hesabe confirms the payment and the order was already paid. */
	const ALREADY_PAID = 'already_paid';

	/** The order was awaiting payment and is now failed. */
	const FAILED = 'failed';

	/** Hesabe reports a failed payment and the order was already failed or cancelled. */
	const ALREADY_FAILED = 'already_failed';

	/** Hesabe has no final answer yet; nothing changed. */
	const WAITING = 'waiting';

	/** Hesabe and the order disagree; nothing changed, the order has a note. */
	const CONFLICT = 'conflict';

	/** A webhook for a payment the order did not start; nothing changed. */
	const IGNORED = 'ignored';

	/** Hesabe could not be asked; nothing changed. */
	const UNREACHABLE = 'unreachable';

	/** Another request is updating the order; nothing changed. */
	const BUSY = 'busy';

	/** No Hesabe order has this ID. */
	const NOT_FOUND = 'not_found';

	/** The customer came back to an order the webhook had already settled. */
	const SETTLED_BEFORE_RETURN = 'settled_before_return';

	/** Order meta holding the tokens of the order's checkouts. */
	const TOKENS_META = '_hesabe_checkout_tokens';

	/** How long a request waits for another one to finish with the order, in seconds. */
	const LOCK_SECONDS = 15;

	/** How much earlier than its order a payment may seem, for clocks that disagree. */
	const CLOCK_SKEW_SECONDS = 600;

	/**
	 * Registers the webhook endpoint.
	 */
	public static function register() {
		add_action( 'woocommerce_api_wc_hesabe_webhook', array( 'Hesabe_WC_Webhook', 'handle' ) );
	}

	/**
	 * Keeps the token Hesabe returned for a checkout of the order: it names the payment in the
	 * webhook and the Transaction Enquiry. A customer who comes back from Hesabe's page and pays
	 * again from "Pay for order" gets a new checkout, so every token is kept.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $token The checkout answer's `token`.
	 */
	public static function remember_checkout( WC_Order $order, $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return;
		}
		$tokens   = self::checkout_tokens( $order );
		$tokens[] = $token;
		$order->update_meta_data( self::TOKENS_META, array_values( array_unique( $tokens ) ) );
		$order->save();
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool Whether the order is paid with this plugin.
	 */
	public static function is_hesabe_order( WC_Order $order ) {
		return 'hesabe' === $order->get_payment_method();
	}

	/**
	 * Applies the customer's return, as the return page always has: a successful return marks
	 * an unpaid order paid, a failed one marks an order awaiting payment failed. An order the
	 * webhook settled first is checked again with the Transaction Enquiry.
	 *
	 * @param int    $order_id  Order ID from the return.
	 * @param bool   $succeeded Whether the return reports a successful payment.
	 * @param object $info      The return's `response`.
	 * @return bool Whether the order is paid afterwards; the customer's page follows it.
	 */
	public static function from_return( $order_id, $succeeded, $info ) {
		$outcome = self::locked(
			$order_id,
			function ( WC_Order $order ) use ( $succeeded, $info ) {
				if ( $succeeded && ! $order->is_paid() ) {
					$order->payment_complete();
					if ( isset( $info->paymentId ) ) {
						$order->update_meta_data( '_hesabe_payment_id', sanitize_text_field( (string) $info->paymentId ) );
						$order->save();
					}
					return self::PAID;
				}
				if ( ! $succeeded && $order->has_status( 'pending' ) ) {
					$order->update_status( 'failed', __( 'Hesabe payment failed or declined.', 'hesabe-for-woocommerce' ) );
					return self::FAILED;
				}
				// The webhook carries no payment ID; the return that follows it does.
				if ( $succeeded && isset( $info->paymentId ) && '' === (string) $order->get_meta( '_hesabe_payment_id', true ) ) {
					$order->update_meta_data( '_hesabe_payment_id', sanitize_text_field( (string) $info->paymentId ) );
					$order->save();
				}
				return self::SETTLED_BEFORE_RETURN;
			}
		);

		if ( self::SETTLED_BEFORE_RETURN === $outcome ) {
			self::enquire( $order_id, __( 'Transaction Enquiry on the customer\'s return', 'hesabe-for-woocommerce' ), true );
		}
		$order = self::fresh_order( $order_id );
		return $order instanceof WC_Order && $order->is_paid();
	}

	/**
	 * Applies a webhook whose signature has been checked. Everything read from it is covered by
	 * the signature.
	 *
	 * @param array<string, mixed> $webhook The decoded body.
	 * @return string One of the outcome constants.
	 */
	public static function from_webhook( array $webhook ) {
		$reference = isset( $webhook['reference_number'] ) ? (string) $webhook['reference_number'] : '';
		if ( '' === $reference || ! ctype_digit( $reference ) ) {
			return self::NOT_FOUND;
		}
		$source = __( 'Hesabe webhook', 'hesabe-for-woocommerce' );

		return self::locked(
			(int) $reference,
			function ( WC_Order $order ) use ( $webhook, $source ) {
				if ( ! self::is_hesabe_order( $order ) ) {
					return self::NOT_FOUND;
				}
				$token = isset( $webhook['token'] ) ? (string) $webhook['token'] : '';
				if ( ! in_array( $token, self::checkout_tokens( $order ), true ) ) {
					/* translators: %s: Hesabe's payment token */
					$order->add_order_note( sprintf( __( 'Hesabe webhook for a payment this order did not start (token %s). Nothing was changed.', 'hesabe-for-woocommerce' ), $token ) );
					return self::IGNORED;
				}
				return self::apply( $order, $webhook, $source, true );
			}
		);
	}

	/**
	 * Asks Hesabe's Transaction Enquiry about the order and applies the latest status.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $source         What asked, for the order notes.
	 * @param bool   $note_agreement Whether an order Hesabe agrees with gets a note saying so.
	 * @return string One of the outcome constants.
	 */
	private static function enquire( $order_id, $source, $note_agreement ) {
		$attempts = Hesabe_WC_Enquiry::attempts( (string) $order_id );
		if ( is_wp_error( $attempts ) ) {
			$order = self::fresh_order( $order_id );
			if ( $order instanceof WC_Order ) {
				/* translators: 1: what asked, 2: the error */
				$order->add_order_note( sprintf( __( 'Hesabe %1$s failed: %2$s Nothing was changed.', 'hesabe-for-woocommerce' ), $source, $attempts->get_error_message() ) );
			}
			return self::UNREACHABLE;
		}

		return self::locked(
			$order_id,
			function ( WC_Order $order ) use ( $attempts, $source, $note_agreement ) {
				$record = self::pick( self::own_attempts( $order, $attempts ) );
				if ( null === $record ) {
					if ( ! $order->is_paid() ) {
						return self::WAITING;
					}
					/* translators: %s: what asked */
					return self::conflict( $order, sprintf( __( 'Hesabe has no payment for this order (%s), but the order is paid. Check it in the Hesabe Merchant Panel.', 'hesabe-for-woocommerce' ), $source ) );
				}

				$token = isset( $record['token'] ) ? (string) $record['token'] : '';
				if ( 'SUCCESSFUL' !== self::status( $record ) && $order->is_paid() ) {
					// pick() prefers a successful attempt, so Hesabe has none for this paid order.
					/* translators: 1: what asked, 2: Hesabe's status, 3: Hesabe's payment token */
					return self::conflict( $order, sprintf( __( 'Hesabe has no successful payment for this order (%1$s: latest attempt %2$s, token %3$s), but the order is paid. Check it in the Hesabe Merchant Panel.', 'hesabe-for-woocommerce' ), $source, self::status( $record ), $token ) );
				}

				$outcome = self::apply( $order, $record, $source, in_array( $token, self::checkout_tokens( $order ), true ) );
				if ( self::ALREADY_PAID === $outcome && ! empty( $record['PaymentID'] ) && is_scalar( $record['PaymentID'] ) && '' === (string) $order->get_meta( '_hesabe_payment_id', true ) ) {
					// An order the webhook paid has no payment ID; the enquiry has it.
					$order->update_meta_data( '_hesabe_payment_id', sanitize_text_field( (string) $record['PaymentID'] ) );
					$order->save();
				}
				if ( $note_agreement && self::ALREADY_PAID === $outcome ) {
					/* translators: 1: what asked, 2: Hesabe's payment token */
					$order->add_order_note( sprintf( __( 'Hesabe confirms the payment (%1$s, token %2$s).', 'hesabe-for-woocommerce' ), $source, $token ) );
				} elseif ( $note_agreement && self::ALREADY_FAILED === $outcome ) {
					/* translators: 1: what asked, 2: Hesabe's payment token */
					$order->add_order_note( sprintf( __( 'Hesabe confirms the payment failed (%1$s, token %2$s).', 'hesabe-for-woocommerce' ), $source, $token ) );
				}
				return $outcome;
			}
		);
	}

	/**
	 * Applies one transaction record, from the webhook or the enquiry, to a locked order.
	 *
	 * A confirmed payment marks the order paid where WooCommerce's payment_complete() allows it:
	 * pending, failed, on hold, or cancelled, which is what WooCommerce does to an unpaid order
	 * when its "Hold stock" time runs out. A failure only fails a pending order.
	 *
	 * The amount must match the order total. An order in another currency is the exception:
	 * Hesabe reports the amount it charged in KWD after converting (1.00 USD came back as
	 * 0.311, sandbox, November 2026), so for it the record must be one of the order's own
	 * checkouts instead, which carried the order's amount and currency.
	 *
	 * @param WC_Order             $order    Order, fresh and locked.
	 * @param array<string, mixed> $record   Hesabe's record: `status`, `amount`, `token`, ...
	 * @param string               $source   What reported it, for the order notes.
	 * @param bool                 $own_token Whether the record's token is one of the order's checkouts.
	 * @return string One of the outcome constants.
	 */
	private static function apply( WC_Order $order, array $record, $source, $own_token ) {
		$status = self::status( $record );
		$token  = isset( $record['token'] ) ? (string) $record['token'] : '';

		if ( 'SUCCESSFUL' === $status ) {
			if ( $order->is_paid() ) {
				return self::ALREADY_PAID;
			}
			$amount = self::amount( isset( $record['amount'] ) ? $record['amount'] : null );
			$total  = self::amount( $order->get_total() );
			/** This filter is documented in WooCommerce's WC_Order::payment_complete(). */
			$payable = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed', 'cancelled' ), $order );
			if ( ! $order->has_status( $payable ) ) {
				/* translators: 1: what reported it, 2: amount, 3: Hesabe's payment token, 4: order status */
				return self::conflict( $order, sprintf( __( 'Hesabe reports this order paid (%1$s, %2$s, token %3$s), but the order is %4$s, so it was not changed. Check it in the Hesabe Merchant Panel.', 'hesabe-for-woocommerce' ), $source, null === $amount ? '?' : $amount, $token, wc_get_order_status_name( $order->get_status() ) ) );
			}
			$converted = 'KWD' !== strtoupper( (string) $order->get_currency() );
			if ( null === $amount || ( $amount !== $total && ! ( $converted && $own_token ) ) ) {
				/* translators: 1: Hesabe's amount, 2: what reported it, 3: Hesabe's payment token, 4: order total, 5: order currency */
				return self::conflict( $order, sprintf( __( 'Hesabe reports a payment of %1$s (%2$s, token %3$s) for this order of %4$s %5$s. The order was not changed.', 'hesabe-for-woocommerce' ), null === $amount ? '?' : $amount, $source, $token, $order->get_total(), $order->get_currency() ) );
			}
			if ( $amount !== $total ) {
				/* translators: 1: what reported it, 2: Hesabe's payment token, 3: amount charged in KWD, 4: order total, 5: order currency */
				$order->add_order_note( sprintf( __( 'Hesabe confirms the payment (%1$s, token %2$s): %3$s KWD for this order of %4$s %5$s.', 'hesabe-for-woocommerce' ), $source, $token, $amount, $order->get_total(), $order->get_currency() ) );
			} else {
				/* translators: 1: what reported it, 2: Hesabe's payment token */
				$order->add_order_note( sprintf( __( 'Hesabe confirms the payment (%1$s, token %2$s).', 'hesabe-for-woocommerce' ), $source, $token ) );
			}
			if ( ! empty( $record['PaymentID'] ) && is_scalar( $record['PaymentID'] ) ) {
				$order->update_meta_data( '_hesabe_payment_id', sanitize_text_field( (string) $record['PaymentID'] ) );
			}
			$order->payment_complete();
			return self::PAID;
		}

		if ( 'FAILED' === $status ) {
			if ( $order->is_paid() ) {
				/* translators: 1: what reported it, 2: Hesabe's payment token */
				$order->add_order_note( sprintf( __( 'Hesabe reports a failed payment attempt (%1$s, token %2$s). The order is already paid, so it was not changed.', 'hesabe-for-woocommerce' ), $source, $token ) );
				return self::ALREADY_PAID;
			}
			if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
				return self::ALREADY_FAILED;
			}
			if ( ! $order->has_status( 'pending' ) ) {
				/* translators: 1: what reported it, 2: Hesabe's payment token, 3: order status */
				return self::conflict( $order, sprintf( __( 'Hesabe reports the payment failed (%1$s, token %2$s), but the order is %3$s, so it was not changed.', 'hesabe-for-woocommerce' ), $source, $token, wc_get_order_status_name( $order->get_status() ) ) );
			}
			/* translators: 1: what reported it, 2: Hesabe's payment token */
			$order->update_status( 'failed', sprintf( __( 'Hesabe reports the payment failed (%1$s, token %2$s).', 'hesabe-for-woocommerce' ), $source, $token ) );
			return self::FAILED;
		}

		return self::WAITING;
	}

	/**
	 * Hesabe's latest status among the attempts: the paid attempt if there is one, else the
	 * newest.
	 *
	 * @param array<int, array<string, mixed>> $attempts The order's own attempts.
	 * @return array<string, mixed>|null
	 */
	private static function pick( array $attempts ) {
		$latest      = null;
		$latest_time = null;
		foreach ( $attempts as $attempt ) {
			if ( 'SUCCESSFUL' === self::status( $attempt ) ) {
				return $attempt;
			}
			$time = self::kuwait_time( isset( $attempt['datetime'] ) ? $attempt['datetime'] : '' );
			if ( null === $latest || ( null !== $time && ( null === $latest_time || $time > $latest_time ) ) ) {
				$latest      = $attempt;
				$latest_time = $time;
			}
		}
		return $latest;
	}

	/**
	 * The attempts that are this order's own payment. Hesabe finds attempts by order number, and
	 * order numbers repeat across shops on one Hesabe account: on the sandbox, which every shop
	 * shares, order 21 also listed other shops' payments from 2020 to October 2026 (November
	 * 2026). The checkout tokens pick the order's own attempts. An order placed before the
	 * plugin kept them is matched by time instead: a payment cannot come before its order.
	 *
	 * @param WC_Order                         $order    Order.
	 * @param array<int, array<string, mixed>> $attempts Every attempt Hesabe listed.
	 * @return array<int, array<string, mixed>>
	 */
	private static function own_attempts( WC_Order $order, array $attempts ) {
		$tokens = self::checkout_tokens( $order );
		$own    = array();
		if ( ! empty( $tokens ) ) {
			foreach ( $attempts as $attempt ) {
				if ( in_array( isset( $attempt['token'] ) ? (string) $attempt['token'] : '', $tokens, true ) ) {
					$own[] = $attempt;
				}
			}
			return $own;
		}

		$created = $order->get_date_created();
		$placed  = ( $created ? $created->getTimestamp() : 0 ) - self::CLOCK_SKEW_SECONDS;
		foreach ( $attempts as $attempt ) {
			$time = self::kuwait_time( isset( $attempt['datetime'] ) ? $attempt['datetime'] : '' );
			if ( null !== $time && $time >= $placed ) {
				$own[] = $attempt;
			}
		}
		return $own;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array<int, string>
	 */
	private static function checkout_tokens( WC_Order $order ) {
		$tokens = $order->get_meta( self::TOKENS_META, true );
		return is_array( $tokens ) ? array_values( array_map( 'strval', $tokens ) ) : array();
	}

	/**
	 * Runs $work on a fresh copy of the order while holding the order's lock.
	 *
	 * The lock is MySQL's GET_LOCK: it holds across requests, and the server releases it if the
	 * request dies.
	 *
	 * @param int      $order_id Order ID.
	 * @param callable $work     Receives the order, returns an outcome.
	 * @return string One of the outcome constants.
	 */
	private static function locked( $order_id, $work ) {
		global $wpdb;
		$name = 'hesabe_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 12 ) . '_' . absint( $order_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_SECONDS ) ) ) {
			return self::BUSY;
		}
		try {
			$order = self::fresh_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return self::NOT_FOUND;
			}
			return $work( $order );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Reads the order again, past WooCommerce's per-request order cache, so a change another
	 * request made while this one waited for the lock is seen.
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|false|null
	 */
	private static function fresh_order( $order_id ) {
		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) && function_exists( 'wc_get_container' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		clean_post_cache( $order_id );
		return wc_get_order( $order_id );
	}

	/**
	 * Adds a note for a person to act on, and logs it.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  What disagrees.
	 * @return string self::CONFLICT
	 */
	private static function conflict( WC_Order $order, $note ) {
		$order->add_order_note( $note );
		wc_get_logger()->warning( sprintf( 'Order %d: %s', $order->get_id(), $note ), array( 'source' => 'hesabe' ) );
		return self::CONFLICT;
	}

	/**
	 * @param array<string, mixed> $record Hesabe's record.
	 * @return string `SUCCESSFUL`, `FAILED`, or another status Hesabe reports.
	 */
	private static function status( array $record ) {
		return strtoupper( trim( isset( $record['status'] ) && is_scalar( $record['status'] ) ? (string) $record['status'] : '' ) );
	}

	/**
	 * Hesabe's times are Kuwait time without a zone (production, October 2026).
	 *
	 * @param mixed $value `Y-m-d H:i:s`.
	 * @return int|null
	 */
	private static function kuwait_time( $value ) {
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', trim( is_scalar( $value ) ? (string) $value : '' ), new DateTimeZone( 'Asia/Kuwait' ) );
		return false === $time ? null : $time->getTimestamp();
	}

	/**
	 * An amount as three-decimal text without separators, or null when it is not one.
	 *
	 * @param mixed $value Amount.
	 * @return string|null
	 */
	private static function amount( $value ) {
		$text = is_scalar( $value ) ? str_replace( ',', '', trim( (string) $value ) ) : '';
		return is_numeric( $text ) ? number_format( (float) $text, 3, '.', '' ) : null;
	}
}
