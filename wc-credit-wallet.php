<?php
/**
 * Plugin Name: WC Credit Wallet
 * Description: WooCommerce Subscription & Credit Wallet System.
 * Version: 1.0.0
 * Author: Chetan Chowdhari
 * Text Domain: wc-credit-wallet
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCCW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCCW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCCW_PLUGIN_FILE', __FILE__ );

// Register activation and deactivation hooks
register_activation_hook( __FILE__, 'wccw_activate_plugin' );
register_deactivation_hook( __FILE__, 'wccw_deactivate_plugin' );

function wccw_activate_plugin() {
	require_once WCCW_PLUGIN_DIR . 'includes/class-wallet-db.php';
	WCCW_Wallet_DB::create_tables();

	require_once WCCW_PLUGIN_DIR . 'public/class-wallet-public.php';
	WCCW_Wallet_Public::register_endpoints();
	flush_rewrite_rules();
}

function wccw_deactivate_plugin() {
	flush_rewrite_rules();
}

add_action( 'plugins_loaded', 'wccw_init_plugin' );

function wccw_init_plugin() {
	// Check if WooCommerce is active
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wccw_woocommerce_missing_notice' );
		return;
	}

	// Include files
	require_once WCCW_PLUGIN_DIR . 'includes/class-wallet-db.php';
	require_once WCCW_PLUGIN_DIR . 'includes/class-wallet.php';
	require_once WCCW_PLUGIN_DIR . 'includes/class-transactions.php';
	require_once WCCW_PLUGIN_DIR . 'includes/class-gateway.php';
	require_once WCCW_PLUGIN_DIR . 'includes/class-subscription.php';
	require_once WCCW_PLUGIN_DIR . 'includes/class-api.php';
	require_once WCCW_PLUGIN_DIR . 'admin/class-wallet-admin.php';
	require_once WCCW_PLUGIN_DIR . 'public/class-wallet-public.php';

	// Initialize components
	WCCW_Wallet::init();
	WCCW_Transactions::init();
	WCCW_Gateway::init();
	WCCW_Subscription::init();
	WCCW_API::init();
	WCCW_Wallet_Admin::init();
	WCCW_Wallet_Public::init();
}

function wccw_woocommerce_missing_notice() {
	?>
	<div class="error notice">
		<p><?php esc_html_e( 'WC Credit Wallet requires WooCommerce to be installed and active.', 'wc-credit-wallet' ); ?></p>
	</div>
	<?php
}
