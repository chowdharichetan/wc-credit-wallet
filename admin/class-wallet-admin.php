<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCW_Wallet_Admin {

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		// Hook for product options fields
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );

		// Hook for admin menu
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
	}

	/**
	 * Display custom wallet fields in product general tab.
	 */
	public static function add_product_fields() {
		echo '<div class="options_group wccw-product-options">';
		
		// Heading
		echo '<h3>' . esc_html__( 'Wallet Credit Settings', 'wc-credit-wallet' ) . '</h3>';

		// Credits to award on one-time purchase
		woocommerce_wp_text_input( array(
			'id'          => '_wallet_credits',
			'label'       => __( 'One-time Credits to Give', 'wc-credit-wallet' ),
			'description' => __( 'The number of credits added to user wallet immediately upon successful purchase of this product.', 'wc-credit-wallet' ),
			'type'        => 'number',
			'custom_attributes' => array(
				'step' => 'any',
				'min'  => '0',
			),
			'desc_tip'    => true,
		) );

		// Checkbox for subscription plan
		woocommerce_wp_checkbox( array(
			'id'          => '_is_wallet_subscription',
			'label'       => __( 'Is Subscription Credit Plan?', 'wc-credit-wallet' ),
			'description' => __( 'Check this if purchasing this product sets up a recurring monthly credit subscription.', 'wc-credit-wallet' ),
			'desc_tip'    => true,
		) );

		// Recurring credits
		woocommerce_wp_text_input( array(
			'id'          => '_subscription_credits',
			'label'       => __( 'Monthly Recurring Credits', 'wc-credit-wallet' ),
			'description' => __( 'The number of credits added to user wallet every 30 days if this is a subscription plan.', 'wc-credit-wallet' ),
			'type'        => 'number',
			'custom_attributes' => array(
				'step' => '1',
				'min'  => '0',
			),
			'desc_tip'    => true,
		) );

		echo '</div>';
	}

	/**
	 * Save product custom wallet fields.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save_product_fields( $product_id ) {
		$is_sub = isset( $_POST['_is_wallet_subscription'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_is_wallet_subscription', $is_sub );

		$credits = isset( $_POST['_wallet_credits'] ) ? sanitize_text_field( $_POST['_wallet_credits'] ) : '';
		update_post_meta( $product_id, '_wallet_credits', $credits );

		$sub_credits = isset( $_POST['_subscription_credits'] ) ? sanitize_text_field( $_POST['_subscription_credits'] ) : '';
		update_post_meta( $product_id, '_subscription_credits', $sub_credits );
	}

	/**
	 * Register Credit Wallet admin page.
	 */
	public static function register_admin_page() {
		add_menu_page(
			__( 'Credit Wallet', 'wc-credit-wallet' ),
			__( 'Credit Wallet', 'wc-credit-wallet' ),
			'manage_woocommerce',
			'wc-credit-wallet',
			array( __CLASS__, 'render_admin_dashboard' ),
			'dashicons-vault',
			56
		);
	}

	/**
	 * Render the administration dashboard view.
	 */
	public static function render_admin_dashboard() {
		global $wpdb;
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';

		// Handle cancellation trigger from admin panel
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'admin_cancel_sub' ) {
			check_admin_referer( 'wccw_admin_cancel_sub' );
			$sub_id = isset( $_GET['sub_id'] ) ? (int) $_GET['sub_id'] : 0;
			if ( $sub_id ) {
				$table = WCCW_Wallet_DB::get_subscriptions_table();
				$wpdb->update( $table, array( 'status' => 'cancelled' ), array( 'id' => $sub_id ) );
				echo '<div class="updated notice is-dismissible"><p>' . esc_html__( 'Subscription cancelled successfully.', 'wc-credit-wallet' ) . '</p></div>';
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooCommerce Credit Wallet Dashboard', 'wc-credit-wallet' ); ?></h1>
			<h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
				<a href="?page=wc-credit-wallet&tab=overview" class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'wc-credit-wallet' ); ?></a>
				<a href="?page=wc-credit-wallet&tab=balances" class="nav-tab <?php echo $tab === 'balances' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'User Balances', 'wc-credit-wallet' ); ?></a>
				<a href="?page=wc-credit-wallet&tab=transactions" class="nav-tab <?php echo $tab === 'transactions' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Transaction History', 'wc-credit-wallet' ); ?></a>
				<a href="?page=wc-credit-wallet&tab=subscriptions" class="nav-tab <?php echo $tab === 'subscriptions' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Subscriptions', 'wc-credit-wallet' ); ?></a>
			</h2>

			<?php
			switch ( $tab ) {
				case 'balances':
					self::render_balances_tab();
					break;
				case 'transactions':
					self::render_transactions_tab();
					break;
				case 'subscriptions':
					self::render_subscriptions_tab();
					break;
				case 'overview':
				default:
					self::render_overview_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render overview stats dashboard tab.
	 */
	private static function render_overview_tab() {
		global $wpdb;
		$tx_table  = WCCW_Wallet_DB::get_transactions_table();
		$sub_table = WCCW_Wallet_DB::get_subscriptions_table();
		$w_table   = WCCW_Wallet_DB::get_wallet_table();

		$total_credits_issued = (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$tx_table} WHERE type = 'credit'" );
		$total_credits_used   = (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$tx_table} WHERE type = 'debit'" );
		$total_active_subs    = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$sub_table} WHERE status = 'active'" );
		$total_wallet_balance = (float) $wpdb->get_var( "SELECT SUM(balance) FROM {$w_table}" );

		?>
		<div style="display: flex; gap: 20px; flex-wrap: wrap;">
			<div class="card" style="flex: 1; min-width: 220px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h2><?php esc_html_e( 'Total Credits Issued', 'wc-credit-wallet' ); ?></h2>
				<p style="font-size: 28px; margin: 10px 0 0 0; font-weight: bold; color: #46b450;"><?php echo esc_html( number_format_i18n( $total_credits_issued, 2 ) ); ?></p>
			</div>
			<div class="card" style="flex: 1; min-width: 220px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h2><?php esc_html_e( 'Total Credits Spent', 'wc-credit-wallet' ); ?></h2>
				<p style="font-size: 28px; margin: 10px 0 0 0; font-weight: bold; color: #dc3232;"><?php echo esc_html( number_format_i18n( $total_credits_used, 2 ) ); ?></p>
			</div>
			<div class="card" style="flex: 1; min-width: 220px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h2><?php esc_html_e( 'Total Circulating Balance', 'wc-credit-wallet' ); ?></h2>
				<p style="font-size: 28px; margin: 10px 0 0 0; font-weight: bold; color: #0073aa;"><?php echo esc_html( number_format_i18n( $total_wallet_balance, 2 ) ); ?></p>
			</div>
			<div class="card" style="flex: 1; min-width: 220px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h2><?php esc_html_e( 'Active Subscriptions', 'wc-credit-wallet' ); ?></h2>
				<p style="font-size: 28px; margin: 10px 0 0 0; font-weight: bold; color: #ffb900;"><?php echo esc_html( $total_active_subs ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render user balances list tab.
	 */
	private static function render_balances_tab() {
		global $wpdb;
		$w_table = WCCW_Wallet_DB::get_wallet_table();

		// Pagination
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$limit = 20;
		$offset = ( $paged - 1 ) * $limit;

		$total = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$w_table}" );
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$w_table} ORDER BY balance DESC LIMIT %d OFFSET %d", $limit, $offset )
		);

		?>
		<table class="widefat fixed striped" style="margin-top: 10px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'User', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Email', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Current Balance', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Last Updated', 'wc-credit-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $results ) ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No wallets found.', 'wc-credit-wallet' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $results as $row ) : 
						$user = get_userdata( $row->user_id );
						$user_name = $user ? $user->display_name . ' (' . $user->user_login . ')' : '#' . $row->user_id;
						$user_email = $user ? $user->user_email : '';
						?>
						<tr>
							<td><strong><?php echo esc_html( $user_name ); ?></strong></td>
							<td><?php echo esc_html( $user_email ); ?></td>
							<td><strong style="color: #0073aa;"><?php echo esc_html( number_format_i18n( $row->balance, 2 ) ); ?></strong></td>
							<td><?php echo esc_html( $row->updated_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		self::render_pagination( $total, $limit, $paged, 'balances' );
	}

	/**
	 * Render transaction log list tab with user filters.
	 */
	private static function render_transactions_tab() {
		$user_id = isset( $_GET['filter_user'] ) ? (int) $_GET['filter_user'] : 0;
		$type    = isset( $_GET['filter_type'] ) ? sanitize_text_field( $_GET['filter_type'] ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$limit   = 20;
		$offset  = ( $paged - 1 ) * $limit;

		$filter_args = array(
			'user_id' => $user_id,
			'type'    => $type,
			'limit'   => $limit,
			'offset'  => $offset,
		);

		$results = WCCW_Transactions::get_all_transactions( $filter_args );
		$total   = WCCW_Transactions::get_all_transactions_count( $filter_args );

		?>
		<form method="GET" action="" style="margin-top: 10px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
			<input type="hidden" name="page" value="wc-credit-wallet" />
			<input type="hidden" name="tab" value="transactions" />
			
			<div>
				<label for="filter_user"><strong><?php esc_html_e( 'User ID:', 'wc-credit-wallet' ); ?></strong></label>
				<input type="number" id="filter_user" name="filter_user" value="<?php echo $user_id ? esc_attr( $user_id ) : ''; ?>" placeholder="e.g. 5" style="width: 100px; height: 30px;" />
			</div>

			<div>
				<label for="filter_type"><strong><?php esc_html_e( 'Type:', 'wc-credit-wallet' ); ?></strong></label>
				<select id="filter_type" name="filter_type" style="height: 30px;">
					<option value=""><?php esc_html_e( 'All Types', 'wc-credit-wallet' ); ?></option>
					<option value="credit" <?php selected( $type, 'credit' ); ?>><?php esc_html_e( 'Credit', 'wc-credit-wallet' ); ?></option>
					<option value="debit" <?php selected( $type, 'debit' ); ?>><?php esc_html_e( 'Debit', 'wc-credit-wallet' ); ?></option>
				</select>
			</div>

			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Filter', 'wc-credit-wallet' ); ?>" />
			<?php if ( $user_id || $type ) : ?>
				<a href="?page=wc-credit-wallet&tab=transactions" class="button"><?php esc_html_e( 'Reset', 'wc-credit-wallet' ); ?></a>
			<?php endif; ?>
		</form>

		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 10%;"><?php esc_html_e( 'ID', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'User', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Order Ref', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Description', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Timestamp', 'wc-credit-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $results ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No transactions found.', 'wc-credit-wallet' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $results as $row ) : 
						$user = get_userdata( $row['user_id'] );
						$user_name = $user ? $user->display_name : '#' . $row['user_id'];
						$type_style = $row['type'] === 'credit' ? 'color: #46b450;' : 'color: #dc3232;';
						?>
						<tr>
							<td><?php echo esc_html( $row['id'] ); ?></td>
							<td><?php echo esc_html( $user_name ); ?> (ID: <?php echo esc_html( $row['user_id'] ); ?>)</td>
							<td><strong style="<?php echo $type_style; ?>"><?php echo esc_html( strtoupper( $row['type'] ) ); ?></strong></td>
							<td><strong><?php echo esc_html( number_format_i18n( $row['amount'], 2 ) ); ?></strong></td>
							<td>
								<?php if ( $row['order_id'] ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $row['order_id'] ) ); ?>" target="_blank">
										#<?php echo esc_html( $row['order_id'] ); ?>
									</a>
								<?php else : ?>
									-
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['description'] ); ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		self::render_pagination( $total, $limit, $paged, 'transactions', array( 'filter_user' => $user_id, 'filter_type' => $type ) );
	}

	/**
	 * Render subscriptions tracking list tab.
	 */
	private static function render_subscriptions_tab() {
		global $wpdb;
		$sub_table = WCCW_Wallet_DB::get_subscriptions_table();

		// Pagination
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$limit = 20;
		$offset = ( $paged - 1 ) * $limit;

		$total = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$sub_table}" );
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$sub_table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset )
		);

		?>
		<table class="widefat fixed striped" style="margin-top: 10px;">
			<thead>
				<tr>
					<th style="width: 8%;"><?php esc_html_e( 'ID', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'User', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Product', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Order Ref', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Monthly Credits', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Last Credited', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Next Billing Date', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wc-credit-wallet' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wc-credit-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $results ) ) : ?>
					<tr>
						<td colspan="9"><?php esc_html_e( 'No subscriptions found.', 'wc-credit-wallet' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $results as $row ) : 
						$user = get_userdata( $row->user_id );
						$user_name = $user ? $user->display_name : '#' . $row->user_id;
						$product_title = get_the_title( $row->product_id );
						$status_color = $row->status === 'active' ? '#46b450' : '#dc3232';
						?>
						<tr>
							<td><?php echo esc_html( $row->id ); ?></td>
							<td><?php echo esc_html( $user_name ); ?></td>
							<td><?php echo esc_html( $product_title ? $product_title : '#' . $row->product_id ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row->order_id ) ); ?>" target="_blank">
									#<?php echo esc_html( $row->order_id ); ?>
								</a>
							</td>
							<td><strong><?php echo esc_html( $row->credit_amount ); ?></strong></td>
							<td><?php echo esc_html( $row->last_credited_at ? $row->last_credited_at : '-' ); ?></td>
							<td><?php echo esc_html( $row->next_billing_date ); ?></td>
							<td><strong style="color: <?php echo esc_attr( $status_color ); ?>;"><?php echo esc_html( strtoupper( $row->status ) ); ?></strong></td>
							<td>
								<?php if ( $row->status === 'active' ) : 
									$cancel_url = wp_nonce_url(
										admin_url( 'admin.php?page=wc-credit-wallet&tab=subscriptions&action=admin_cancel_sub&sub_id=' . $row->id ),
										'wccw_admin_cancel_sub'
									);
									?>
									<a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small delete" onclick="return confirm('<?php esc_html_e( 'Are you sure you want to cancel this subscription?', 'wc-credit-wallet' ); ?>');">
										<?php esc_html_e( 'Cancel', 'wc-credit-wallet' ); ?>
									</a>
								<?php else : ?>
									-
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		self::render_pagination( $total, $limit, $paged, 'subscriptions' );
	}

	/**
	 * Render list navigation paginator link markup.
	 */
	private static function render_pagination( $total, $limit, $paged, $tab, $extra_args = array() ) {
		$total_pages = ceil( $total / $limit );
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = '?page=wc-credit-wallet&tab=' . $tab;
		foreach ( $extra_args as $key => $val ) {
			if ( ! empty( $val ) ) {
				$base_url .= '&' . urlencode( $key ) . '=' . urlencode( $val );
			}
		}

		echo '<div class="tablenav"><div class="tablenav-pages" style="margin: 10px 0;">';
		echo '<span class="displaying-num" style="margin-right: 15px;">' . sprintf( _n( '%s item', '%s items', $total, 'wc-credit-wallet' ), number_format_i18n( $total ) ) . '</span>';
		
		$page_links = paginate_links( array(
			'base'      => add_query_arg( 'paged', '%#%', $base_url ),
			'format'    => '',
			'prev_text' => __( '&laquo;', 'wc-credit-wallet' ),
			'next_text' => __( '&raquo;', 'wc-credit-wallet' ),
			'total'     => $total_pages,
			'current'   => $paged,
		) );

		if ( $page_links ) {
			echo $page_links;
		}
		echo '</div></div>';
	}
}
