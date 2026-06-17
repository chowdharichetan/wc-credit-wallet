<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Subscription {

	/**
	 * Initialize hooks and cron.
	 */
	public static function init() {
		// Hook into order credits awarded to check for subscription purchases
		add_action( 'wccw_order_credits_awarded', array( __CLASS__, 'create_subscription_from_order' ), 10, 3 );

		// Register cron hooks
		add_action( 'wccw_subscription_renewal_cron', array( __CLASS__, 'process_subscriptions' ) );

		if ( ! wp_next_scheduled( 'wccw_subscription_renewal_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'wccw_subscription_renewal_cron' );
		}

		// Handle cancellation action from frontend
		add_action( 'init', array( __CLASS__, 'handle_user_cancellation' ) );
	}

	/**
	 * Create a subscription record when a user purchases a subscription credit product.
	 *
	 * @param WC_Order $order Order object.
	 * @param int      $user_id Customer User ID.
	 * @param float    $total_credits Total credits awarded in this order.
	 */
	public static function create_subscription_from_order( $order, $user_id, $total_credits ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_subscriptions_table();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// Check product configuration meta
			$is_sub       = $product->get_meta( '_is_wallet_subscription' );
			$sub_credits  = $product->get_meta( '_subscription_credits' );

			if ( $is_sub === 'yes' && ! empty( $sub_credits ) && is_numeric( $sub_credits ) ) {
				$qty = $item->get_quantity();
				$credit_amount = (int) $sub_credits * $qty;

				// Verify it wasn't already registered
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE order_id = %d AND product_id = %d",
						$order->get_id(),
						$product->get_id()
					)
				);

				if ( ! $exists ) {
					$now = current_time( 'mysql' );
					$next_billing = date( 'Y-m-d H:i:s', strtotime( '+30 days', current_time( 'timestamp' ) ) );

					$wpdb->insert(
						$table,
						array(
							'user_id'           => $user_id,
							'product_id'        => $product->get_id(),
							'order_id'          => $order->get_id(),
							'status'            => 'active',
							'credit_amount'     => $credit_amount,
							'last_credited_at'  => $now,
							'next_billing_date' => $next_billing,
							'created_at'        => $now,
						),
						array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
					);
				}
			}
		}
	}

	/**
	 * Process renewal of due subscriptions (WP Cron callback).
	 */
	public static function process_subscriptions() {
		global $wpdb;
		$sub_table = WCCW_Wallet_DB::get_subscriptions_table();
		$now = current_time( 'mysql' );

		// Query active subscriptions that are due for billing
		$due_subs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$sub_table} WHERE status = 'active' AND next_billing_date <= %s",
				$now
			)
		);

		if ( empty( $due_subs ) ) {
			return;
		}

		foreach ( $due_subs as $sub ) {
			// Concurrency control: Atomic state transition to block double runs
			$locked = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$sub_table} SET status = 'renewing' WHERE id = %d AND status = 'active'",
					$sub->id
				)
			);

			if ( ! $locked ) {
				continue; // Already processed in another process
			}

			// Add credits
			$product_title = get_the_title( $sub->product_id );
			$description = sprintf(
				__( 'Monthly credit renewal for subscription plan: %s', 'wc-credit-wallet' ),
				$product_title ? $product_title : '#' . $sub->product_id
			);

			$credited = WCCW_Wallet::credit( $sub->user_id, $sub->credit_amount, $description, $sub->order_id );

			if ( $credited ) {
				// Shift billing date forward by 30 days
				$current_next_billing = strtotime( $sub->next_billing_date );
				$new_next_billing = date( 'Y-m-d H:i:s', strtotime( '+30 days', $current_next_billing ) );

				$wpdb->update(
					$sub_table,
					array(
						'status'           => 'active',
						'last_credited_at' => current_time( 'mysql' ),
						'next_billing_date'=> $new_next_billing,
					),
					array( 'id' => $sub->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				// Revert status to active so it can be processed next time
				$wpdb->update(
					$sub_table,
					array( 'status' => 'active' ),
					array( 'id' => $sub->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param int $sub_id Subscription ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function cancel_subscription( $sub_id, $user_id ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_subscriptions_table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled' WHERE id = %d AND user_id = %d AND status = 'active'",
				$sub_id,
				$user_id
			)
		);

		return $result !== false;
	}

	/**
	 * Handle subscription cancellation trigger from public request.
	 */
	public static function handle_user_cancellation() {
		if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'wccw_cancel_sub' ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$sub_id = isset( $_GET['sub_id'] ) ? (int) $_GET['sub_id'] : 0;
		$nonce  = isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'wccw_cancel_sub_' . $sub_id ) ) {
			wc_add_notice( __( 'Security verification failed.', 'wc-credit-wallet' ), 'error' );
			return;
		}

		$user_id = get_current_user_id();
		$cancelled = self::cancel_subscription( $sub_id, $user_id );

		if ( $cancelled ) {
			wc_add_notice( __( 'Subscription cancelled successfully.', 'wc-credit-wallet' ), 'success' );
		} else {
			wc_add_notice( __( 'Failed to cancel subscription or subscription already inactive.', 'wc-credit-wallet' ), 'error' );
		}

		wp_safe_redirect( wc_get_endpoint_url( 'wallet-history', '', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}
}
