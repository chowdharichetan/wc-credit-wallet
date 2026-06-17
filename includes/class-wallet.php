<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Wallet {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Hook into order status changes
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_order_status_change' ), 10, 4 );
	}

	/**
	 * Get the credit balance of a user.
	 *
	 * @param int $user_id User ID.
	 * @return float
	 */
	public static function get_balance( $user_id ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_wallet_table();
		$balance = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT balance FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
		return $balance !== null ? (float) $balance : 0.0;
	}

	/**
	 * Atomic credit operation.
	 *
	 * @param int    $user_id User ID.
	 * @param float  $amount Amount to add.
	 * @param string $description Optional description.
	 * @param int    $order_id Optional order ID.
	 * @return bool
	 */
	public static function credit( $user_id, $amount, $description = '', $order_id = null ) {
		if ( $amount <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = WCCW_Wallet_DB::get_wallet_table();

		// Use INSERT ... ON DUPLICATE KEY UPDATE for atomic insert or add
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, balance) VALUES (%d, %f) 
				ON DUPLICATE KEY UPDATE balance = balance + %f",
				$user_id,
				$amount,
				$amount
			)
		);

		if ( $result !== false ) {
			WCCW_Transactions::log( $user_id, $amount, 'credit', $order_id, $description );
			return true;
		}

		return false;
	}

	/**
	 * Atomic debit operation (prevents negative balance).
	 *
	 * @param int    $user_id User ID.
	 * @param float  $amount Amount to deduct.
	 * @param string $description Optional description.
	 * @param int    $order_id Optional order ID.
	 * @return bool True on success, false on failure (insufficient balance).
	 */
	public static function debit( $user_id, $amount, $description = '', $order_id = null ) {
		if ( $amount <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = WCCW_Wallet_DB::get_wallet_table();

		// Atomic update: only deduct if current balance >= amount
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET balance = balance - %f WHERE user_id = %d AND balance >= %f",
				$amount,
				$user_id,
				$amount
			)
		);

		if ( $result && $wpdb->rows_affected > 0 ) {
			WCCW_Transactions::log( $user_id, $amount, 'debit', $order_id, $description );
			return true;
		}

		return false;
	}

	/**
	 * Handle order status changes.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order Order object.
	 */
	public static function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
		// Award credits when order goes to processing or completed
		if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			self::maybe_award_order_credits( $order );
		}

		// Rollback / Refund credits if order paid by wallet is cancelled, failed, or refunded
		if ( in_array( $new_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			self::maybe_restore_wallet_payment( $order );
			self::maybe_revoke_order_credits( $order );
		}
	}

	/**
	 * Award credits to user wallet if they purchased credit products.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function maybe_award_order_credits( $order ) {
		if ( $order->get_meta( '_wccw_credits_added' ) === 'yes' ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return; // Guest orders cannot have wallets
		}

		$total_credits = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// Check if the product has credit meta
			$credits = $product->get_meta( '_wallet_credits' );
			if ( ! empty( $credits ) && is_numeric( $credits ) ) {
				$qty = $item->get_quantity();
				$total_credits += (float) $credits * $qty;
			}
		}

		if ( $total_credits > 0 ) {
			$description = sprintf(
				__( 'Credits purchased in Order #%s', 'wc-credit-wallet' ),
				$order->get_order_number()
			);
			
			$credited = self::credit( $user_id, $total_credits, $description, $order->get_id() );
			
			if ( $credited ) {
				$order->update_meta_data( '_wccw_credits_added', 'yes' );
				$order->save();

				// Trigger action for subscription or other integrations
				do_action( 'wccw_order_credits_awarded', $order, $user_id, $total_credits );
			}
		}
	}

	/**
	 * Restore credits if an order paid via wallet is cancelled or refunded.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function maybe_restore_wallet_payment( $order ) {
		if ( $order->get_payment_method() !== 'wallet' ) {
			return;
		}

		if ( $order->get_meta( '_wccw_payment_refunded' ) === 'yes' ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		// Refund the amount paid via wallet (which is equal to the order total)
		$refund_amount = (float) $order->get_total();

		if ( $refund_amount > 0 ) {
			$description = sprintf(
				__( 'Refund/Rollback of Order #%s payment', 'wc-credit-wallet' ),
				$order->get_order_number()
			);

			$refunded = self::credit( $user_id, $refund_amount, $description, $order->get_id() );

			if ( $refunded ) {
				$order->update_meta_data( '_wccw_payment_refunded', 'yes' );
				$order->save();
			}
		}
	}

	/**
	 * Revoke credited balance if a purchased credit product order is cancelled or refunded.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function maybe_revoke_order_credits( $order ) {
		if ( $order->get_meta( '_wccw_credits_added' ) !== 'yes' ) {
			return;
		}

		if ( $order->get_meta( '_wccw_credits_revoked' ) === 'yes' ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		$total_credits = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$credits = $product->get_meta( '_wallet_credits' );
			if ( ! empty( $credits ) && is_numeric( $credits ) ) {
				$qty = $item->get_quantity();
				$total_credits += (float) $credits * $qty;
			}
		}

		if ( $total_credits > 0 ) {
			$description = sprintf(
				__( 'Revoked purchased credits due to cancel/refund of Order #%s', 'wc-credit-wallet' ),
				$order->get_order_number()
			);

			// Deduct the credits (enforcing balance limits)
			$revoked = self::debit( $user_id, $total_credits, $description, $order->get_id() );

			// Mark as revoked regardless of whether debit succeeded (since we attempted to reverse it)
			$order->update_meta_data( '_wccw_credits_revoked', 'yes' );
			$order->save();

			do_action( 'wccw_order_credits_revoked', $order, $user_id, $total_credits, $revoked );
		}
	}
}
