<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Wallet_Public {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Register WooCommerce My Account endpoints
		add_action( 'init', array( __CLASS__, 'register_endpoints' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_items' ) );
		add_action( 'woocommerce_account_wallet-history_endpoint', array( __CLASS__, 'endpoint_content' ) );
	}

	/**
	 * Register the rewrite endpoint for My Account.
	 */
	public static function register_endpoints() {
		add_rewrite_endpoint( 'wallet-history', EP_PAGES );
	}

	/**
	 * Add "Wallet" navigation tab link to WooCommerce My Account menu.
	 */
	public static function add_menu_items( $items ) {
		$new_items = array();
		foreach ( $items as $key => $val ) {
			if ( $key === 'customer-logout' ) {
				$new_items['wallet-history'] = __( 'Wallet', 'wc-credit-wallet' );
			}
			$new_items[ $key ] = $val;
		}
		return $new_items;
	}

	/**
	 * Render content of the "Wallet" Account tab.
	 */
	public static function endpoint_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$balance = WCCW_Wallet::get_balance( $user_id );
		
		// Transaction Pagination
		$paged = isset( $_GET['wccw_page'] ) ? max( 1, (int) $_GET['wccw_page'] ) : 1;
		$limit = 10;
		$offset = ( $paged - 1 ) * $limit;

		$txs   = WCCW_Transactions::get_user_transactions( $user_id, $limit, $offset );
		$total = WCCW_Transactions::get_user_transactions_count( $user_id );

		// Fetch subscriptions for this user
		global $wpdb;
		$sub_table = WCCW_Wallet_DB::get_subscriptions_table();
		$subs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$sub_table} WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			)
		);

		?>
		<h2><?php esc_html_e( 'My Credit Wallet', 'wc-credit-wallet' ); ?></h2>
		
		<!-- Balance Card -->
		<div class="wccw-balance-card" style="background: #f7f7f7; border: 1px solid #ddd; padding: 20px; border-radius: 4px; margin-bottom: 30px;">
			<h4 style="margin: 0; color: #777; text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px;"><?php esc_html_e( 'Current Balance', 'wc-credit-wallet' ); ?></h4>
			<div style="font-size: 36px; font-weight: bold; color: #111; margin-top: 5px;">
				<?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?> <span style="font-size: 18px; font-weight: normal; color: #666;"><?php esc_html_e( 'Credits', 'wc-credit-wallet' ); ?></span>
			</div>
		</div>

		<!-- Transactions Table -->
		<h3><?php esc_html_e( 'Transaction History', 'wc-credit-wallet' ); ?></h3>
		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders" style="margin-bottom: 30px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Description', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wc-credit-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $txs ) ) : ?>
					<tr>
						<td colspan="4" style="text-align: center;"><?php esc_html_e( 'No transactions yet.', 'wc-credit-wallet' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $txs as $tx ) : 
						$type_label = $tx['type'] === 'credit' ? __( 'Credit', 'wc-credit-wallet' ) : __( 'Debit', 'wc-credit-wallet' );
						$type_color = $tx['type'] === 'credit' ? '#46b450' : '#dc3232';
						$amount_prefix = $tx['type'] === 'credit' ? '+' : '-';
						?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Type', 'wc-credit-wallet' ); ?>">
								<strong style="color: <?php echo esc_attr( $type_color ); ?>;"><?php echo esc_html( $type_label ); ?></strong>
							</td>
							<td data-title="<?php esc_attr_e( 'Amount', 'wc-credit-wallet' ); ?>">
								<strong><?php echo esc_html( $amount_prefix . number_format_i18n( $tx['amount'], 2 ) ); ?></strong>
							</td>
							<td data-title="<?php esc_attr_e( 'Description', 'wc-credit-wallet' ); ?>">
								<?php echo esc_html( $tx['description'] ); ?>
								<?php if ( ! empty( $tx['order_id'] ) ) : ?>
									(<?php echo sprintf( __( 'Order #%s', 'wc-credit-wallet' ), esc_html( $tx['order_id'] ) ); ?>)
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Date', 'wc-credit-wallet' ); ?>">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $tx['created_at'] ) ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
		// Pagination Links
		$total_pages = ceil( $total / $limit );
		if ( $total_pages > 1 ) {
			echo '<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination" style="margin-bottom: 40px; display: flex; gap: 10px;">';
			if ( $paged > 1 ) {
				echo '<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button" href="' . esc_url( add_query_arg( 'wccw_page', $paged - 1 ) ) . '">' . esc_html__( 'Previous', 'wc-credit-wallet' ) . '</a>';
			}
			if ( $paged < $total_pages ) {
				echo '<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button" href="' . esc_url( add_query_arg( 'wccw_page', $paged + 1 ) ) . '">' . esc_html__( 'Next', 'wc-credit-wallet' ) . '</a>';
			}
			echo '</div>';
		}
		?>

		<!-- Subscriptions Section -->
		<?php if ( ! empty( $subs ) ) : ?>
			<h3 style="margin-top: 40px;"><?php esc_html_e( 'My Credit Subscriptions', 'wc-credit-wallet' ); ?></h3>
			<table class="woocommerce-orders-table shop_table shop_table_responsive">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plan Name', 'wc-credit-wallet' ); ?></th>
						<th><?php esc_html_e( 'Monthly Credits', 'wc-credit-wallet' ); ?></th>
						<th><?php esc_html_e( 'Next Billing Date', 'wc-credit-wallet' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-credit-wallet' ); ?></th>
						<th><?php esc_html_e( 'Action', 'wc-credit-wallet' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $subs as $sub ) : 
						$status_color = $sub->status === 'active' ? '#46b450' : '#888';
						?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Plan Name', 'wc-credit-wallet' ); ?>">
								<?php echo esc_html( get_the_title( $sub->product_id ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Monthly Credits', 'wc-credit-wallet' ); ?>">
								<strong><?php echo esc_html( $sub->credit_amount ); ?></strong>
							</td>
							<td data-title="<?php esc_attr_e( 'Next Billing Date', 'wc-credit-wallet' ); ?>">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub->next_billing_date ) ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Status', 'wc-credit-wallet' ); ?>">
								<strong style="color: <?php echo esc_attr( $status_color ); ?>;"><?php echo esc_html( strtoupper( $sub->status ) ); ?></strong>
							</td>
							<td data-title="<?php esc_attr_e( 'Action', 'wc-credit-wallet' ); ?>">
								<?php if ( $sub->status === 'active' ) : 
									$cancel_url = wp_nonce_url(
										add_query_arg( array( 'action' => 'wccw_cancel_sub', 'sub_id' => $sub->id ) ),
										'wccw_cancel_sub_' . $sub->id
									);
									?>
									<a href="<?php echo esc_url( $cancel_url ); ?>" class="button cancel" onclick="return confirm('<?php esc_html_e( 'Are you sure you want to cancel this plan?', 'wc-credit-wallet' ); ?>');">
										<?php esc_html_e( 'Cancel Plan', 'wc-credit-wallet' ); ?>
									</a>
								<?php else : ?>
									-
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
