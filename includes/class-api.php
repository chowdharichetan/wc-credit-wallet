<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_API {

	/**
	 * Register API Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		$namespace = 'wc-credit-wallet/v1';

		register_rest_route( $namespace, '/balance', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_balance' ),
				'permission_callback' => array( __CLASS__, 'get_balance_permissions_check' ),
				'args'                => array(
					'user_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			),
		) );

		register_rest_route( $namespace, '/transactions', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_transactions' ),
				'permission_callback' => array( __CLASS__, 'get_transactions_permissions_check' ),
				'args'                => array(
					'user_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'limit'   => array(
						'required'          => false,
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
					'offset'  => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			),
		) );

		register_rest_route( $namespace, '/deduct', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'deduct_credits' ),
				'permission_callback' => array( __CLASS__, 'deduct_permissions_check' ),
				'args'                => array(
					'user_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'amount' => array(
						'required'          => true,
						'sanitize_callback' => 'floatval',
					),
					'description' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'REST API Deduction',
					),
				),
			),
		) );
	}

	/**
	 * Permission check for getting balance.
	 */
	public static function get_balance_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'wccw_rest_unauthorized', __( 'Unauthorized access.', 'wc-credit-wallet' ), array( 'status' => 401 ) );
		}

		$requested_user_id = $request->get_param( 'user_id' );
		$current_user_id   = get_current_user_id();

		// Users can check their own balance.
		if ( empty( $requested_user_id ) || $requested_user_id === $current_user_id ) {
			return true;
		}

		// Admins can check anyone's balance.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error( 'wccw_rest_forbidden', __( 'Forbidden to view other user balances.', 'wc-credit-wallet' ), array( 'status' => 403 ) );
	}

	/**
	 * Get credit balance.
	 */
	public static function get_balance( $request ) {
		$user_id = $request->get_param( 'user_id' );
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$balance = WCCW_Wallet::get_balance( $user_id );

		return new WP_REST_Response( array(
			'user_id' => $user_id,
			'balance' => $balance,
		), 200 );
	}

	/**
	 * Permission check for fetching transactions.
	 */
	public static function get_transactions_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'wccw_rest_unauthorized', __( 'Unauthorized access.', 'wc-credit-wallet' ), array( 'status' => 401 ) );
		}

		$requested_user_id = $request->get_param( 'user_id' );
		$current_user_id   = get_current_user_id();

		// Users can check their own transactions.
		if ( empty( $requested_user_id ) || $requested_user_id === $current_user_id ) {
			return true;
		}

		// Admins can check anyone's transactions.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error( 'wccw_rest_forbidden', __( 'Forbidden to view other user transactions.', 'wc-credit-wallet' ), array( 'status' => 403 ) );
	}

	/**
	 * Get transactions list.
	 */
	public static function get_transactions( $request ) {
		$user_id = $request->get_param( 'user_id' );
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$limit  = $request->get_param( 'limit' );
		$offset = $request->get_param( 'offset' );

		$txs   = WCCW_Transactions::get_user_transactions( $user_id, $limit, $offset );
		$total = WCCW_Transactions::get_user_transactions_count( $user_id );

		return new WP_REST_Response( array(
			'user_id'      => $user_id,
			'transactions' => $txs,
			'total'        => $total,
			'limit'        => $limit,
			'offset'       => $offset,
		), 200 );
	}

	/**
	 * Permission check for deducting credits.
	 */
	public static function deduct_permissions_check( $request ) {
		// Only admins/shop managers are allowed to deduct credits.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error( 'wccw_rest_forbidden', __( 'Forbidden. You do not have permissions to deduct credits.', 'wc-credit-wallet' ), array( 'status' => 403 ) );
	}

	/**
	 * Deduct credits from user wallet.
	 */
	public static function deduct_credits( $request ) {
		$user_id     = $request->get_param( 'user_id' );
		$amount      = (float) $request->get_param( 'amount' );
		$description = $request->get_param( 'description' );

		if ( $amount <= 0 ) {
			return new WP_Error( 'wccw_invalid_amount', __( 'Amount must be greater than zero.', 'wc-credit-wallet' ), array( 'status' => 400 ) );
		}

		// Check if user exists
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'wccw_user_not_found', __( 'User not found.', 'wc-credit-wallet' ), array( 'status' => 404 ) );
		}

		$current_balance = WCCW_Wallet::get_balance( $user_id );
		if ( $current_balance < $amount ) {
			return new WP_Error( 'wccw_insufficient_funds', __( 'Insufficient wallet balance.', 'wc-credit-wallet' ), array( 'status' => 400 ) );
		}

		// Perform atomic debit
		$debited = WCCW_Wallet::debit( $user_id, $amount, $description );

		if ( $debited ) {
			return new WP_REST_Response( array(
				'success'     => true,
				'user_id'     => $user_id,
				'amount'      => $amount,
				'new_balance' => WCCW_Wallet::get_balance( $user_id ),
			), 200 );
		}

		return new WP_Error( 'wccw_deduct_failed', __( 'Failed to deduct credits. Concurrency or system error.', 'wc-credit-wallet' ), array( 'status' => 500 ) );
	}
}
