<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Transactions {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Nothing to hook currently, but standard interface structure
	}

	/**
	 * Log a transaction.
	 *
	 * @param int    $user_id User ID.
	 * @param float  $amount Transaction amount.
	 * @param string $type Transaction type ('credit' or 'debit').
	 * @param int    $order_id Optional WooCommerce order ID reference.
	 * @param string $description Transaction description.
	 * @return bool
	 */
	public static function log( $user_id, $amount, $type, $order_id = null, $description = '' ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_transactions_table();

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'amount'      => $amount,
				'type'        => $type,
				'order_id'    => $order_id ? $order_id : null,
				'description' => $description,
				'created_at'  => current_time( 'mysql' ),
			),
			array(
				'%d',
				'%f',
				'%s',
				$order_id ? '%d' : '%s', // handle null
				'%s',
				'%s',
			)
		);

		return $result !== false;
	}

	/**
	 * Get transaction history for a specific user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Max transactions.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function get_user_transactions( $user_id, $limit = 10, $offset = 0 ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_transactions_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Get total count of transactions for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_user_transactions_count( $user_id ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_transactions_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Get transactions matching filters (for Admin dashboard).
	 *
	 * @param array $args Filter arguments.
	 * @return array
	 */
	public static function get_all_transactions( $args = array() ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_transactions_table();

		$defaults = array(
			'user_id' => 0,
			'type'    => '',
			'limit'   => 20,
			'offset'  => 0,
			'order'   => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array();
		$params = array();

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$params[] = $args['user_id'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where[] = 'type = %s';
			$params[] = $args['type'];
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		
		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at {$order} LIMIT %d OFFSET %d";
		$params[] = $args['limit'];
		$params[] = $args['offset'];

		return $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);
	}

	/**
	 * Get total count of all transactions matching filters.
	 *
	 * @param array $args Filter arguments.
	 * @return int
	 */
	public static function get_all_transactions_count( $args = array() ) {
		global $wpdb;
		$table = WCCW_Wallet_DB::get_transactions_table();

		$where = array();
		$params = array();

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$params[] = $args['user_id'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where[] = 'type = %s';
			$params[] = $args['type'];
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$sql = "SELECT COUNT(id) FROM {$table} {$where_sql}";

		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		} else {
			return (int) $wpdb->get_var( $sql );
		}
	}
}
