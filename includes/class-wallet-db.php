<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Wallet_DB {

	/**
	 * Get wallets table name.
	 */
	public static function get_wallet_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_wallets';
	}

	/**
	 * Get transactions table name.
	 */
	public static function get_transactions_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_wallet_transactions';
	}

	/**
	 * Get subscriptions table name.
	 */
	public static function get_subscriptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_wallet_subscriptions';
	}

	/**
	 * Create or update custom database tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$wallet_table = self::get_wallet_table();
		$sql_wallets = "CREATE TABLE $wallet_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			balance decimal(12,4) NOT NULL DEFAULT '0.0000',
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id)
		) $charset_collate;";

		$tx_table = self::get_transactions_table();
		$sql_transactions = "CREATE TABLE $tx_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			amount decimal(12,4) NOT NULL,
			type varchar(10) NOT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			description text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id)
		) $charset_collate;";

		$sub_table = self::get_subscriptions_table();
		$sql_subscriptions = "CREATE TABLE $sub_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			credit_amount int(11) NOT NULL,
			last_credited_at datetime DEFAULT NULL,
			next_billing_date datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status_next_billing (status, next_billing_date)
		) $charset_collate;";

		dbDelta( $sql_wallets );
		dbDelta( $sql_transactions );
		dbDelta( $sql_subscriptions );
	}
}
