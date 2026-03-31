<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;

/**
 * Operational admin pages: Dashboard, Orders, Errors, Payouts, Settings.
 *
 * Page slugs:
 *   mb-dashboard  – stats overview
 *   mb-orders     – unsynced WooCommerce orders
 *   mb-errors     – failed tasks
 *   mb-payouts    – payout & reconciliation visibility
 *   mb-settings   – plugin configuration
 */
class AdminUI {
	public const PAGE_DASHBOARD = 'mb-dashboard';
	public const PAGE_ORDERS    = 'mb-orders';
	public const PAGE_ERRORS    = 'mb-errors';
	public const PAGE_PAYOUTS   = 'mb-payouts';
	public const PAGE_SETTINGS  = 'mb-settings';
	public const NONCE_ACTION   = 'mbsfw_admin_action';
	public const OPTION_KEY     = 'mbsfw_settings';

	private TaskQueue $queue;
	private Logger $logger;
	private \wpdb $db;

	public function __construct( TaskQueue $queue, Logger $logger, \wpdb $db ) {
		$this->queue  = $queue;
		$this->logger = $logger;
		$this->db     = $db;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_mbsfw_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_mbsfw_retry_task', array( $this, 'ajax_retry_task' ) );
		add_action( 'wp_ajax_mbsfw_delete_task', array( $this, 'ajax_delete_task' ) );
		add_action( 'wp_ajax_mbsfw_manual_sync', array( $this, 'ajax_manual_sync' ) );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────

	public function add_menu_pages(): void {
		$settings    = self::get_settings();
		$is_setup    = ! empty( $settings['sync_enabled'] );
		$parent_slug = $is_setup ? self::PAGE_DASHBOARD : Onboarding::PAGE_SLUG;

		add_menu_page(
			__( 'Moneybird Sync', 'moneybird-sync-for-woo' ),
			__( 'Moneybird Sync', 'moneybird-sync-for-woo' ),
			'manage_woocommerce',
			$parent_slug,
			$is_setup ? array( $this, 'render_dashboard' ) : '__return_null',
			'dashicons-book-alt',
			56
		);

		if ( $is_setup ) {
			add_submenu_page( self::PAGE_DASHBOARD, __( 'Dashboard', 'moneybird-sync-for-woo' ), __( 'Dashboard', 'moneybird-sync-for-woo' ), 'manage_woocommerce', self::PAGE_DASHBOARD, array( $this, 'render_dashboard' ) );
			add_submenu_page( self::PAGE_DASHBOARD, __( 'Unsynced Orders', 'moneybird-sync-for-woo' ), __( 'Orders', 'moneybird-sync-for-woo' ), 'manage_woocommerce', self::PAGE_ORDERS, array( $this, 'render_orders' ) );
			add_submenu_page( self::PAGE_DASHBOARD, __( 'Failed Tasks', 'moneybird-sync-for-woo' ), __( 'Errors', 'moneybird-sync-for-woo' ), 'manage_woocommerce', self::PAGE_ERRORS, array( $this, 'render_errors' ) );
			add_submenu_page( self::PAGE_DASHBOARD, __( 'Payouts & Reconciliation', 'moneybird-sync-for-woo' ), __( 'Payouts', 'moneybird-sync-for-woo' ), 'manage_woocommerce', self::PAGE_PAYOUTS, array( $this, 'render_payouts' ) );
		}

		// Settings and Setup always visible.
		add_submenu_page( $parent_slug, __( 'Settings', 'moneybird-sync-for-woo' ), __( 'Settings', 'moneybird-sync-for-woo' ), 'manage_woocommerce', self::PAGE_SETTINGS, array( $this, 'render_settings' ) );
		add_submenu_page( $parent_slug, __( 'Setup', 'moneybird-sync-for-woo' ), __( 'Setup', 'moneybird-sync-for-woo' ), 'manage_woocommerce', Onboarding::PAGE_SLUG, '__return_null' );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		$mbsfw_pages = array(
			'toplevel_page_' . self::PAGE_DASHBOARD,
			'toplevel_page_' . Onboarding::PAGE_SLUG,
			'moneybird-sync_page_' . self::PAGE_ORDERS,
			'moneybird-sync_page_' . self::PAGE_ERRORS,
			'moneybird-sync_page_' . self::PAGE_PAYOUTS,
			'moneybird-sync_page_' . self::PAGE_SETTINGS,
			'moneybird-sync_page_' . Onboarding::PAGE_SLUG,
		);
		if ( ! in_array( $hook, $mbsfw_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'mbsfw-admin', plugins_url( 'assets/admin.css', MBSFW_PLUGIN_FILE ), array(), MBSFW_VERSION );
		wp_enqueue_script( 'mbsfw-admin', plugins_url( 'assets/admin.js', MBSFW_PLUGIN_FILE ), array( 'jquery' ), MBSFW_VERSION, true );
		wp_localize_script( 'mbsfw-admin', 'mbsfwAdmin', array(
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => array(
				'confirm_delete' => __( 'Delete this task permanently?', 'moneybird-sync-for-woo' ),
				'syncing'        => __( 'Queuing…', 'moneybird-sync-for-woo' ),
			),
		) );
	}

	// ── Settings registration ────────────────────────────────────────────────

	public function register_settings(): void {
		register_setting( 'mbsfw_settings_group', self::OPTION_KEY, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public function sanitize_settings( array $input ): array {
		return array(
			'oauth_client_id'        => sanitize_text_field( (string) ( $input['oauth_client_id'] ?? '' ) ),
			'oauth_client_secret'    => sanitize_text_field( (string) ( $input['oauth_client_secret'] ?? '' ) ),
			'clearing_account_id'    => sanitize_text_field( (string) ( $input['clearing_account_id'] ?? '' ) ),
			'bank_account_id'        => sanitize_text_field( (string) ( $input['bank_account_id'] ?? '' ) ),
			'fees_ledger_account_id' => sanitize_text_field( (string) ( $input['fees_ledger_account_id'] ?? '' ) ),
			'sync_enabled'           => ! empty( $input['sync_enabled'] ) ? '1' : '',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_settings(): array {
		return (array) get_option( self::OPTION_KEY, array() );
	}

	// ── Dashboard ─────────────────────────────────────────────────────────────

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'moneybird-sync-for-woo' ) );
		}
		if ( isset( $_GET['onboarding_complete'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( '🎉 Moneybird Sync is now active! Your first WooCommerce Stripe order will be synced automatically.', 'moneybird-sync-for-woo' ) . '</p></div>';
		}

		$pending   = $this->queue->count_by_status( Task::STATUS_PENDING );
		$failed    = $this->queue->count_by_status( Task::STATUS_FAILED );
		$completed = $this->queue->count_by_status( Task::STATUS_COMPLETED );
		$synced    = $this->count_synced_orders();
		$last_sync = $this->get_last_sync_time();
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e( 'Moneybird Sync — Dashboard', 'moneybird-sync-for-woo' ); ?></h1>

			<div class="mbsfw-stat-cards">
				<?php
				$this->stat_card( __( 'Synced Orders', 'moneybird-sync-for-woo' ), (string) $synced, 'dashicons-yes-alt', 'green' );
				$this->stat_card( __( 'Pending Tasks', 'moneybird-sync-for-woo' ), (string) $pending, 'dashicons-clock', 'blue' );
				$this->stat_card( __( 'Failed Tasks', 'moneybird-sync-for-woo' ), (string) $failed, 'dashicons-warning', $failed > 0 ? 'red' : 'grey' );
				$this->stat_card( __( 'Completed Tasks', 'moneybird-sync-for-woo' ), (string) $completed, 'dashicons-yes', 'grey' );
				?>
			</div>

			<p class="mbsfw-last-sync">
				<?php
				if ( $last_sync ) {
					printf(
						/* translators: %s: datetime */
						esc_html__( 'Last sync: %s', 'moneybird-sync-for-woo' ),
						esc_html( $last_sync )
					);
				} else {
					esc_html_e( 'No tasks processed yet.', 'moneybird-sync-for-woo' );
				}
				?>
			</p>

			<?php if ( $failed > 0 ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: 1: count, 2: errors page URL */
								__( '%1$d task(s) failed. <a href="%2$s">View failed tasks →</a>', 'moneybird-sync-for-woo' ),
								array( 'a' => array( 'href' => array() ) )
							),
							intval( $failed ),
							esc_url( admin_url( 'admin.php?page=' . self::PAGE_ERRORS ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent Tasks', 'moneybird-sync-for-woo' ); ?></h2>
			<?php $this->render_task_table( $this->queue->get_all( 20 ) ); ?>
		</div>
		<?php
	}

	// ── Orders page ──────────────────────────────────────────────────────────

	public function render_orders(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'moneybird-sync-for-woo' ) );
		}

		$payment_method = isset( $_GET['payment_method'] ) ? sanitize_key( (string) $_GET['payment_method'] ) : 'stripe';
		$status_filter  = isset( $_GET['sync_status'] ) ? sanitize_key( (string) $_GET['sync_status'] ) : '';
		$date_after     = isset( $_GET['date_after'] ) ? sanitize_text_field( (string) $_GET['date_after'] ) : '';
		$date_before    = isset( $_GET['date_before'] ) ? sanitize_text_field( (string) $_GET['date_before'] ) : '';
		$paged          = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$per_page       = 25;

		// Build task map (order_id → task) for sync status overlay.
		$task_map = $this->build_task_map();

		// Query unsynced orders.
		$orders = $this->get_unsynced_orders( $payment_method, $date_after, $date_before, $per_page, ( $paged - 1 ) * $per_page );
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e( 'Moneybird Sync — Unsynced Orders', 'moneybird-sync-for-woo' ); ?></h1>

			<form method="get" class="mbsfw-filter-bar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_ORDERS ); ?>" />
				<select name="payment_method">
					<option value=""><?php esc_html_e( 'All payment methods', 'moneybird-sync-for-woo' ); ?></option>
					<option value="stripe" <?php selected( $payment_method, 'stripe' ); ?>><?php esc_html_e( 'Stripe', 'moneybird-sync-for-woo' ); ?></option>
				</select>
				<select name="sync_status">
					<option value=""><?php esc_html_e( 'All sync statuses', 'moneybird-sync-for-woo' ); ?></option>
					<option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Pending', 'moneybird-sync-for-woo' ); ?></option>
					<option value="processing" <?php selected( $status_filter, 'processing' ); ?>><?php esc_html_e( 'Processing', 'moneybird-sync-for-woo' ); ?></option>
					<option value="failed" <?php selected( $status_filter, 'failed' ); ?>><?php esc_html_e( 'Failed', 'moneybird-sync-for-woo' ); ?></option>
				</select>
				<label><?php esc_html_e( 'From:', 'moneybird-sync-for-woo' ); ?> <input type="date" name="date_after" value="<?php echo esc_attr( $date_after ); ?>" /></label>
				<label><?php esc_html_e( 'To:', 'moneybird-sync-for-woo' ); ?> <input type="date" name="date_before" value="<?php echo esc_attr( $date_before ); ?>" /></label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'moneybird-sync-for-woo' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped mbsfw-orders-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Total', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Sync Status', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Last Error', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'moneybird-sync-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $orders as $order ) :
					$order_id   = $order->get_id();
					$task       = $task_map[ $order_id ] ?? null;
					$sync_class = $this->sync_status_class( $order, $task );
					$last_error = $task ? $this->get_last_task_error( (int) $task->id ) : '';
				?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $order_id ) ?: '' ); ?>">#<?php echo esc_html( (string) $order_id ); ?></a>
						</td>
						<td><?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?></td>
						<td><?php echo esc_html( $order->get_formatted_order_total() ); ?></td>
						<td><?php echo esc_html( $order->get_payment_method_title() ); ?></td>
						<td><span class="mbsfw-badge mbsfw-badge--<?php echo esc_attr( $sync_class ); ?>"><?php echo esc_html( $sync_class ); ?></span></td>
						<td><?php echo esc_html( $last_error ); ?></td>
						<td>
							<?php if ( ! $task || Task::STATUS_FAILED === (string) $task->status ) : ?>
								<button class="button button-small mbsfw-manual-sync" data-order-id="<?php echo esc_attr( (string) $order_id ); ?>">
									<?php esc_html_e( 'Sync Now', 'moneybird-sync-for-woo' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( $task && Task::STATUS_FAILED === (string) $task->status ) : ?>
								<button class="button button-small mbsfw-retry-task" data-task-id="<?php echo esc_attr( (string) $task->id ); ?>">
									<?php esc_html_e( 'Retry', 'moneybird-sync-for-woo' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( $task ) : ?>
								<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr( (string) $task->id ); ?>">
									<?php esc_html_e( 'Logs', 'moneybird-sync-for-woo' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $orders ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No unsynced orders found.', 'moneybird-sync-for-woo' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div id="mbsfw-logs-panel" style="display:none; margin-top:20px;">
			<div class="mbsfw-wizard-card">
				<h2><?php esc_html_e( 'Task Logs', 'moneybird-sync-for-woo' ); ?> <button id="mbsfw-logs-close" class="button button-small"><?php esc_html_e( 'Close', 'moneybird-sync-for-woo' ); ?></button></h2>
				<div id="mbsfw-logs-content"></div>
			</div>
		</div>
		<?php
	}

	// ── Errors page ───────────────────────────────────────────────────────────

	public function render_errors(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'moneybird-sync-for-woo' ) );
		}

		$tasks = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->db->prefix}mb_tasks WHERE status = %s ORDER BY updated_at DESC LIMIT 100",
				Task::STATUS_FAILED
			)
		);
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e( 'Moneybird Sync — Failed Tasks', 'moneybird-sync-for-woo' ); ?></h1>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Task ID', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Type', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Order / Reference', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Last Error', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'moneybird-sync-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $tasks as $row ) :
					$payload   = (array) json_decode( (string) $row->payload, true );
					$reference = (string) ( $payload['order_id'] ?? $payload['stripe_payout_id'] ?? $payload['stripe_fee_id'] ?? '—' );
					$last_err  = $this->get_last_task_error( (int) $row->id );
				?>
					<tr>
						<td>#<?php echo esc_html( (string) $row->id ); ?></td>
						<td><?php echo esc_html( (string) $row->type ); ?></td>
						<td><?php echo esc_html( $reference ); ?></td>
						<td><?php echo esc_html( $row->attempts . '/' . $row->max_attempts ); ?></td>
						<td class="mbsfw-error-msg"><?php echo esc_html( $last_err ); ?></td>
						<td><?php echo esc_html( (string) $row->updated_at ); ?></td>
						<td>
							<button class="button button-small mbsfw-retry-task" data-task-id="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php esc_html_e( 'Retry', 'moneybird-sync-for-woo' ); ?>
							</button>
							<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php esc_html_e( 'Logs', 'moneybird-sync-for-woo' ); ?>
							</button>
							<button class="button button-small mbsfw-view-payload" data-payload="<?php echo esc_attr( (string) $row->payload ); ?>">
								<?php esc_html_e( 'Payload', 'moneybird-sync-for-woo' ); ?>
							</button>
							<button class="button button-small button-link-delete mbsfw-delete-task" data-task-id="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php esc_html_e( 'Delete', 'moneybird-sync-for-woo' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $tasks ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No failed tasks. 🎉', 'moneybird-sync-for-woo' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div id="mbsfw-logs-panel" style="display:none; margin-top:20px;">
			<div class="mbsfw-wizard-card">
				<h2><?php esc_html_e( 'Task Logs', 'moneybird-sync-for-woo' ); ?> <button id="mbsfw-logs-close" class="button button-small"><?php esc_html_e( 'Close', 'moneybird-sync-for-woo' ); ?></button></h2>
				<div id="mbsfw-logs-content"></div>
			</div>
		</div>
		<?php
	}

	// ── Payouts page ──────────────────────────────────────────────────────────

	public function render_payouts(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'moneybird-sync-for-woo' ) );
		}

		$payout_tasks = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->db->prefix}mb_tasks WHERE type = %s ORDER BY created_at DESC LIMIT 100",
				Task::TYPE_SYNC_PAYOUT
			)
		);
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e( 'Moneybird Sync — Payouts & Reconciliation', 'moneybird-sync-for-woo' ); ?></h1>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Payouts are reconciled as totals: Bank ↔ Stripe Clearing Account. They are never matched to individual invoices.', 'moneybird-sync-for-woo' ); ?></p>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Task ID', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Stripe Payout ID', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Date', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'moneybird-sync-for-woo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'moneybird-sync-for-woo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $payout_tasks as $row ) :
					$payload = (array) json_decode( (string) $row->payload, true );
				?>
					<tr>
						<td>#<?php echo esc_html( (string) $row->id ); ?></td>
						<td><?php echo esc_html( (string) ( $payload['stripe_payout_id'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $payload['amount'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $payload['date'] ?? '—' ) ); ?></td>
						<td><span class="mbsfw-badge mbsfw-badge--<?php echo esc_attr( (string) $row->status ); ?>"><?php echo esc_html( (string) $row->status ); ?></span></td>
						<td>
							<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php esc_html_e( 'Logs', 'moneybird-sync-for-woo' ); ?>
							</button>
							<?php if ( Task::STATUS_FAILED === (string) $row->status ) : ?>
								<button class="button button-small mbsfw-retry-task" data-task-id="<?php echo esc_attr( (string) $row->id ); ?>">
									<?php esc_html_e( 'Retry', 'moneybird-sync-for-woo' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $payout_tasks ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No payout tasks found. Queue them via the Stripe webhook handler.', 'moneybird-sync-for-woo' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div id="mbsfw-logs-panel" style="display:none; margin-top:20px;">
			<div class="mbsfw-wizard-card">
				<h2><?php esc_html_e( 'Task Logs', 'moneybird-sync-for-woo' ); ?> <button id="mbsfw-logs-close" class="button button-small"><?php esc_html_e( 'Close', 'moneybird-sync-for-woo' ); ?></button></h2>
				<div id="mbsfw-logs-content"></div>
			</div>
		</div>
		<?php
	}

	// ── Settings page ─────────────────────────────────────────────────────────

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'moneybird-sync-for-woo' ) );
		}
		$s                   = self::get_settings();
		$credentials_in_code = defined( 'MBSFW_OAUTH_CLIENT_ID' );
		$oauth               = OAuthClient::from_settings();
		$is_connected        = $oauth->is_connected();
		$admin_id            = $oauth->get_selected_administration_id();
		$disconnect_url      = wp_nonce_url(
			admin_url( 'admin.php?page=' . Onboarding::PAGE_SLUG . '&mbsfw_oauth=disconnect' ),
			'mbsfw_disconnect'
		);
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e( 'Moneybird Sync — Settings', 'moneybird-sync-for-woo' ); ?></h1>

			<h2><?php esc_html_e( 'Moneybird Connection', 'moneybird-sync-for-woo' ); ?></h2>
			<?php if ( $is_connected ) : ?>
				<div class="mbsfw-connection-status mbsfw-connection-status--connected">
					<span class="dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'Connected', 'moneybird-sync-for-woo' ); ?></strong>
						<?php if ( $admin_id ) : ?>
							<br /><small><?php esc_html_e( 'Administration ID:', 'moneybird-sync-for-woo' ); ?> <code><?php echo esc_html( $admin_id ); ?></code></small>
						<?php endif; ?>
					</div>
					<a href="<?php echo esc_url( $disconnect_url ); ?>"
					   class="button"
					   onclick="return confirm('<?php echo esc_js( __( 'Reset the Moneybird connection? You will need to reconnect and re-run the setup wizard.', 'moneybird-sync-for-woo' ) ); ?>')">
						<?php esc_html_e( 'Reset Connection', 'moneybird-sync-for-woo' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="mbsfw-connection-status mbsfw-connection-status--disconnected">
					<span class="dashicons dashicons-warning"></span>
					<div>
						<strong><?php esc_html_e( 'Not connected', 'moneybird-sync-for-woo' ); ?></strong>
						<br /><small><?php esc_html_e( 'Complete the setup wizard to connect your Moneybird account.', 'moneybird-sync-for-woo' ); ?></small>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Onboarding::PAGE_SLUG ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Run Setup Wizard →', 'moneybird-sync-for-woo' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<hr />

			<form method="post" action="options.php">
				<?php settings_fields( 'mbsfw_settings_group' ); ?>

				<h2><?php esc_html_e( 'OAuth Application', 'moneybird-sync-for-woo' ); ?></h2>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: URL placeholder */
							__( 'Create an OAuth application at <a href="%s" target="_blank" rel="noopener">moneybird.com/user/applications</a>.', 'moneybird-sync-for-woo' ),
							array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
						),
						'https://moneybird.com/user/applications'
					);
					?>
					<?php esc_html_e( 'Redirect URI:', 'moneybird-sync-for-woo' ); ?>
					<code><?php echo esc_html( admin_url( 'admin.php?page=mb-onboarding&mbsfw_oauth=callback' ) ); ?></code>
				</p>

				<?php if ( $credentials_in_code ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'OAuth credentials are set via wp-config.php constants.', 'moneybird-sync-for-woo' ); ?></p></div>
				<?php else : ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="oauth_client_id"><?php esc_html_e( 'Client ID', 'moneybird-sync-for-woo' ); ?></label></th>
							<td><input type="text" id="oauth_client_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[oauth_client_id]" value="<?php echo esc_attr( (string) ( $s['oauth_client_id'] ?? '' ) ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="oauth_client_secret"><?php esc_html_e( 'Client Secret', 'moneybird-sync-for-woo' ); ?></label></th>
							<td><input type="password" id="oauth_client_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[oauth_client_secret]" value="<?php echo esc_attr( (string) ( $s['oauth_client_secret'] ?? '' ) ); ?>" class="regular-text" /></td>
						</tr>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Sync', 'moneybird-sync-for-woo' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Sync', 'moneybird-sync-for-woo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_enabled]" value="1" <?php checked( ! empty( $s['sync_enabled'] ) ); ?> />
								<?php esc_html_e( 'Automatically sync new Stripe orders to Moneybird', 'moneybird-sync-for-woo' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Account Mapping', 'moneybird-sync-for-woo' ); ?></h2>
			<p>
				<?php
				printf(
					wp_kses(
						__( 'To change account mappings, use the <a href="%s">Setup Wizard</a>.', 'moneybird-sync-for-woo' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'admin.php?page=' . Onboarding::PAGE_SLUG . '&step=3' ) )
				);
				?>
			</p>
			<ul>
				<li><?php esc_html_e( 'Stripe Clearing Account:', 'moneybird-sync-for-woo' ); ?> <code><?php echo esc_html( (string) ( $s['clearing_account_id'] ?? '—' ) ); ?></code></li>
				<li><?php esc_html_e( 'Bank Account:', 'moneybird-sync-for-woo' ); ?> <code><?php echo esc_html( (string) ( $s['bank_account_id'] ?? '—' ) ); ?></code></li>
				<li><?php esc_html_e( 'Fees Ledger Account:', 'moneybird-sync-for-woo' ); ?> <code><?php echo esc_html( (string) ( $s['fees_ledger_account_id'] ?? '—' ) ); ?></code></li>
			</ul>
		</div>
		<?php
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	public function ajax_get_logs(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}
		$task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : null;
		$logs    = $this->logger->get_logs( $task_id );
		wp_send_json_success( array_map( static function ( object $log ): array {
			return array(
				'id'         => (int) $log->id,
				'task_id'    => isset( $log->task_id ) ? (int) $log->task_id : null,
				'level'      => esc_html( (string) $log->level ),
				'message'    => esc_html( (string) $log->message ),
				'created_at' => esc_html( (string) $log->created_at ),
			);
		}, $logs ) );
	}

	public function ajax_retry_task(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}
		$task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0;
		$task    = $task_id ? $this->queue->get( $task_id ) : null;
		if ( ! $task || Task::STATUS_FAILED !== $task->status ) {
			wp_send_json_error( 'Task not found or not failed.' );
			return;
		}
		$this->db->update(
			$this->db->prefix . 'mb_tasks',
			array( 'status' => Task::STATUS_PENDING, 'attempts' => 0, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $task_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		$this->logger->info( "Task #{$task_id} manually reset for retry." );
		wp_send_json_success( array( 'message' => 'Task reset for retry.' ) );
	}

	public function ajax_delete_task(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}
		$task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0;
		if ( ! $task_id ) {
			wp_send_json_error( 'Invalid task ID.' );
			return;
		}
		$this->db->delete( $this->db->prefix . 'mb_tasks', array( 'id' => $task_id ), array( '%d' ) );
		$this->logger->info( "Task #{$task_id} deleted by admin." );
		wp_send_json_success();
	}

	public function ajax_manual_sync(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		if ( ! $order_id ) {
			wp_send_json_error( 'Invalid order ID.' );
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
			return;
		}
		// Clear idempotency flags so a fresh sync runs.
		$order->delete_meta_data( '_mb_sync_queued' );
		$order->save_meta_data();

		$task_id = $this->queue->create( Task::TYPE_SYNC_ORDER, array( 'order_id' => $order_id ) );
		$order->update_meta_data( '_mb_sync_queued', '1' );
		$order->save_meta_data();

		$this->logger->info( "Admin manually queued sync_order task #{$task_id} for order {$order_id}." );
		wp_send_json_success( array( 'task_id' => $task_id ) );
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	private function stat_card( string $label, string $value, string $icon, string $color ): void {
		printf(
			'<div class="mbsfw-stat-card mbsfw-stat-card--%s"><span class="dashicons %s"></span><div class="mbsfw-stat-card__body"><strong>%s</strong><span>%s</span></div></div>',
			esc_attr( $color ),
			esc_attr( $icon ),
			esc_html( $value ),
			esc_html( $label )
		);
	}

	/**
	 * @param \MoneybirdSyncForWoo\Models\Task[] $tasks
	 */
	private function render_task_table( array $tasks ): void {
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'moneybird-sync-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'moneybird-sync-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'moneybird-sync-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Attempts', 'moneybird-sync-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'moneybird-sync-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'moneybird-sync-for-woo' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $task ) {
			printf(
				'<tr><td>#%d</td><td>%s</td><td><span class="mbsfw-badge mbsfw-badge--%s">%s</span></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				intval( $task->id ),
				esc_html( $task->type ),
				esc_attr( $task->status ),
				esc_html( $task->status ),
				esc_html( $task->attempts . '/' . $task->max_attempts ),
				esc_html( $task->created_at ),
				'<button class="button button-small mbsfw-view-logs" data-task-id="' . esc_attr( (string) $task->id ) . '">' . esc_html__( 'Logs', 'moneybird-sync-for-woo' ) . '</button>'
			);
		}
		if ( empty( $tasks ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No tasks yet.', 'moneybird-sync-for-woo' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Build a map of order_id → task row for pending/failed/processing tasks.
	 *
	 * @return array<int, object>
	 */
	private function build_task_map(): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, payload, status, attempts, max_attempts, updated_at FROM {$this->db->prefix}mb_tasks
                 WHERE type = %s AND status IN ('pending','processing','failed')
                 ORDER BY created_at DESC",
				Task::TYPE_SYNC_ORDER
			)
		);
		$map = array();
		foreach ( $rows ?? array() as $row ) {
			$payload  = (array) json_decode( (string) $row->payload, true );
			$order_id = isset( $payload['order_id'] ) ? (int) $payload['order_id'] : 0;
			if ( $order_id ) {
				$map[ $order_id ] = $row;
			}
		}
		return $map;
	}

	/**
	 * @return \WC_Order[]
	 */
	private function get_unsynced_orders( string $payment_method, string $date_after, string $date_before, int $limit, int $offset ): array {
		$args = array(
			'type'         => 'shop_order',
			'status'       => array( 'wc-processing', 'wc-completed' ),
			'limit'        => $limit,
			'offset'       => $offset,
			'return'       => 'objects',
			'meta_query'   => array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array( 'key' => '_mb_payment_created', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_mb_payment_created', 'value' => '', 'compare' => '=' ),
				),
			),
		);

		if ( $payment_method ) {
			$args['payment_method'] = $payment_method;
		}
		if ( $date_after ) {
			$args['date_created'] = '>=' . $date_after;
		}
		if ( $date_before ) {
			$args['date_created'] = ( $date_after ? $date_after . '...' : '' ) . $date_before;
		}

		return wc_get_orders( $args );
	}

	private function sync_status_class( \WC_Order $order, ?object $task ): string {
		if ( $order->get_meta( '_mb_payment_created', true ) ) {
			return 'completed';
		}
		if ( ! $task ) {
			return 'not-queued';
		}
		return (string) $task->status;
	}

	private function get_last_task_error( int $task_id ): string {
		$log = $this->db->get_row(
			$this->db->prepare(
				"SELECT message FROM {$this->db->prefix}mb_logs WHERE task_id = %d AND level = 'error' ORDER BY created_at DESC LIMIT 1",
				$task_id
			)
		);
		return $log ? (string) $log->message : '';
	}

	private function count_synced_orders(): int {
		return (int) $this->db->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$this->db->prefix}postmeta
             WHERE meta_key = '_mb_payment_created' AND meta_value = '1'"
		);
	}

	private function get_last_sync_time(): ?string {
		$val = $this->db->get_var(
			"SELECT completed_at FROM {$this->db->prefix}mb_tasks
             WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1"
		);
		return $val ? (string) $val : null;
	}
}
