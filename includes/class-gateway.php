<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Gateway {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_available_gateways' ) );
	}

	/**
	 * Add custom gateway class to WooCommerce list.
	 */
	public static function add_gateway( $gateways ) {
		$gateways[] = 'WC_Gateway_Wallet';
		return $gateways;
	}

	/**
	 * Filter available gateways so Wallet Payment only appears for logged-in users with enough balance.
	 */
	public static function filter_available_gateways( $available_gateways ) {
		if ( is_admin() ) {
			return $available_gateways;
		}

		if ( ! isset( $available_gateways['wallet'] ) ) {
			return $available_gateways;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			unset( $available_gateways['wallet'] );
			return $available_gateways;
		}

		$balance = WCCW_Wallet::get_balance( $user_id );
		$cart_total = 0;
		if ( WC() && WC()->cart ) {
			$cart_total = (float) WC()->cart->get_total( 'edit' );
		}

		if ( $balance < $cart_total ) {
			unset( $available_gateways['wallet'] );
		}

		return $available_gateways;
	}
}

/**
 * Custom WooCommerce payment gateway using Credit Wallet balance.
 */
class WC_Gateway_Wallet extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'wallet';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Wallet Payment', 'wc-credit-wallet' );
		$this->method_description = __( 'Allows customers to pay using their credit wallet balance.', 'wc-credit-wallet' );

		// Load settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user front-end details
		$this->title       = $this->get_option( 'title', __( 'Wallet Payment', 'wc-credit-wallet' ) );
		$this->description = $this->get_option( 'description', __( 'Pay using your credit wallet balance.', 'wc-credit-wallet' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'wc-credit-wallet' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Wallet Payment', 'wc-credit-wallet' ),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __( 'Title', 'wc-credit-wallet' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'wc-credit-wallet' ),
				'default'     => __( 'Wallet Payment', 'wc-credit-wallet' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'wc-credit-wallet' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'wc-credit-wallet' ),
				'default'     => __( 'Pay using your credit wallet balance.', 'wc-credit-wallet' ),
			),
		);
	}

	/**
	 * Process payment for checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return array|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$user_id = $order->get_customer_id();

		if ( ! $user_id ) {
			wc_add_notice( __( 'Guest orders are not allowed to pay using the Wallet.', 'wc-credit-wallet' ), 'error' );
			return;
		}

		$order_total = (float) $order->get_total();
		$balance = WCCW_Wallet::get_balance( $user_id );

		if ( $balance < $order_total ) {
			wc_add_notice( __( 'Insufficient wallet balance.', 'wc-credit-wallet' ), 'error' );
			return;
		}

		// Perform atomic debit
		$description = sprintf( __( 'Payment for Order #%s', 'wc-credit-wallet' ), $order->get_order_number() );
		$debited = WCCW_Wallet::debit( $user_id, $order_total, $description, $order_id );

		if ( ! $debited ) {
			wc_add_notice( __( 'Payment failed. Insufficient wallet balance or processing issue.', 'wc-credit-wallet' ), 'error' );
			return;
		}

		// Mark order status and empty cart
		$order->payment_complete();
		$order->add_order_note( __( 'Payment processed successfully using credit wallet.', 'wc-credit-wallet' ) );
		
		if ( WC() && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
