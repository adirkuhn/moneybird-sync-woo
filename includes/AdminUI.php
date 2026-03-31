<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;

/**
 * Operational admin pages: Dashboard, Orders, Errors, Payouts, Settings.
 */
class AdminUI
{
	public const PAGE_DASHBOARD = 'mb-dashboard';
	public const PAGE_ORDERS = 'mb-orders';
	public const PAGE_ERRORS = 'mb-errors';
	public const PAGE_PAYOUTS = 'mb-payouts';
	public const PAGE_SETTINGS = 'mb-settings';
	public const NONCE_ACTION = 'mbsfw_admin_action';
	public const OPTION_KEY = 'mbsfw_settings';

	private TaskQueue $queue;
	private Onboarding $onboarding;
	private Logger $logger;
	private \wpdb $db;
	private Worker $worker;

	public function __construct(TaskQueue $queue, Onboarding $onboarding, Logger $logger, \wpdb $db, Worker $worker)
	{
		$this->queue = $queue;
		$this->onboarding = $onboarding;
		$this->logger = $logger;
		$this->db = $db;
		$this->worker = $worker;
	}

	public function register(): void
	{
		add_action('admin_menu', array($this, 'add_menu_pages'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('wp_ajax_mbsfw_get_logs', array($this, 'ajax_get_logs'));
		add_action('wp_ajax_mbsfw_retry_task', array($this, 'ajax_retry_task'));
		add_action('wp_ajax_mbsfw_delete_task', array($this, 'ajax_delete_task'));
		add_action('wp_ajax_mbsfw_manual_sync', array($this, 'ajax_manual_sync'));
		add_action('wp_ajax_mbsfw_trigger_worker', array($this, 'ajax_trigger_worker'));
	}

	public function add_menu_pages(): void
	{
		$settings = self::get_settings();
		$is_setup = isset($settings['sync_enabled']) && '' !== $settings['sync_enabled'];
		$parent_slug = $is_setup ? self::PAGE_DASHBOARD : Onboarding::PAGE_SLUG;

		add_menu_page(
			__('Moneybird Sync', 'moneybird-sync-for-woo'),
			__('Moneybird Sync', 'moneybird-sync-for-woo'),
			'manage_woocommerce',
			$parent_slug,
			$is_setup ? array($this, 'render_dashboard') : '__return_null',
			'dashicons-book-alt',
			56
		);

		if ($is_setup) {
			add_submenu_page(self::PAGE_DASHBOARD, __('Dashboard', 'moneybird-sync-for-woo'), __('Dashboard', 'moneybird-sync-for-woo'), 'manage_woocommerce', self::PAGE_DASHBOARD, array($this, 'render_dashboard'));
			add_submenu_page(self::PAGE_DASHBOARD, __('Unsynced Orders', 'moneybird-sync-for-woo'), __('Orders', 'moneybird-sync-for-woo'), 'manage_woocommerce', self::PAGE_ORDERS, array($this, 'render_orders'));
			add_submenu_page(self::PAGE_DASHBOARD, __('Failed Tasks', 'moneybird-sync-for-woo'), __('Errors', 'moneybird-sync-for-woo'), 'manage_woocommerce', self::PAGE_ERRORS, array($this, 'render_errors'));
			add_submenu_page(self::PAGE_DASHBOARD, __('Payouts & Reconciliation', 'moneybird-sync-for-woo'), __('Payouts', 'moneybird-sync-for-woo'), 'manage_woocommerce', self::PAGE_PAYOUTS, array($this, 'render_payouts'));
		}

		add_submenu_page($parent_slug, __('Settings', 'moneybird-sync-for-woo'), __('Settings', 'moneybird-sync-for-woo'), 'manage_woocommerce', self::PAGE_SETTINGS, array($this, 'render_settings'));
		add_submenu_page($parent_slug, __('Setup', 'moneybird-sync-for-woo'), __('Setup', 'moneybird-sync-for-woo'), 'manage_woocommerce', Onboarding::PAGE_SLUG, array($this, 'render_onboarding'));
	}

	public function enqueue_assets(string $hook): void
	{
		$mbsfw_pages = array(
			'toplevel_page_' . self::PAGE_DASHBOARD,
			'toplevel_page_' . Onboarding::PAGE_SLUG,
			'moneybird-sync_page_' . self::PAGE_ORDERS,
			'moneybird-sync_page_' . self::PAGE_ERRORS,
			'moneybird-sync_page_' . self::PAGE_PAYOUTS,
			'moneybird-sync_page_' . self::PAGE_SETTINGS,
			'moneybird-sync_page_' . Onboarding::PAGE_SLUG,
		);
		if (!in_array($hook, $mbsfw_pages, true)) {
			return;
		}
		wp_enqueue_style('mbsfw-admin', plugins_url('assets/admin.css', MBSFW_PLUGIN_FILE), array(), defined('WP_DEBUG') && WP_DEBUG ? (string)time() : MBSFW_VERSION);
		wp_enqueue_script('mbsfw-admin', plugins_url('assets/admin.js', MBSFW_PLUGIN_FILE), array('jquery'), defined('WP_DEBUG') && WP_DEBUG ? (string)time() : MBSFW_VERSION, true);
		wp_localize_script('mbsfw-admin', 'mbsfwAdmin', array(
			'nonce' => wp_create_nonce(self::NONCE_ACTION),
			'ajaxurl' => admin_url('admin-ajax.php'),
			'i18n' => array(
				'confirm_delete' => __('Delete this task permanently?', 'moneybird-sync-for-woo'),
				'syncing' => __('Queuing…', 'moneybird-sync-for-woo'),
			),
		));
	}

	public function register_settings(): void
	{
		register_setting('mbsfw_settings_group', self::OPTION_KEY, array('sanitize_callback' => array($this, 'sanitize_settings')));
	}

	public function sanitize_settings(array $input): array
	{
		$methods = array_values(array_filter(array_map('sanitize_key', (array) ($input['allowed_payment_methods'] ?? []))));
		if (empty($methods)) {
			$methods = array('stripe');
		}
		return array(
			'oauth_client_id' => sanitize_text_field((string) ($input['oauth_client_id'] ?? '')),
			'oauth_client_secret' => sanitize_text_field((string) ($input['oauth_client_secret'] ?? '')),
			'clearing_account_id' => sanitize_text_field((string) ($input['clearing_account_id'] ?? '')),
			'bank_account_id' => sanitize_text_field((string) ($input['bank_account_id'] ?? '')),
			'fees_ledger_account_id' => sanitize_text_field((string) ($input['fees_ledger_account_id'] ?? '')),
			'sync_enabled' => (isset($input['sync_enabled']) && '' !== $input['sync_enabled']) ? '1' : '',
			'allowed_payment_methods' => (string) wp_json_encode($methods),
		);
	}

	public static function get_settings(): array
	{
		return (array) get_option(self::OPTION_KEY, array());
	}

	public function render_dashboard(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Insufficient permissions.', 'moneybird-sync-for-woo'));
		}
		$pending = $this->queue->count_by_status(Task::STATUS_PENDING);
		$failed = $this->queue->count_by_status(Task::STATUS_FAILED);
		$completed = $this->queue->count_by_status(Task::STATUS_COMPLETED);
		$synced = $this->count_synced_orders();
		$last_sync = $this->get_last_sync_time();
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e('Moneybird Sync — Dashboard', 'moneybird-sync-for-woo'); ?></h1>
			<div class="mbsfw-stat-cards">
				<?php
				$this->stat_card(__('Synced Orders', 'moneybird-sync-for-woo'), (string) $synced, 'dashicons-yes-alt', 'green');
				$this->stat_card(__('Pending Tasks', 'moneybird-sync-for-woo'), (string) $pending, 'dashicons-clock', 'blue');
				$this->stat_card(__('Failed Tasks', 'moneybird-sync-for-woo'), (string) $failed, 'dashicons-warning', $failed > 0 ? 'red' : 'grey');
				$this->stat_card(__('Completed Tasks', 'moneybird-sync-for-woo'), (string) $completed, 'dashicons-yes', 'grey');
				?>
			</div>
			<div class="mbsfw-dashboard-actions" style="margin: 20px 0;">
				<button id="mbsfw-trigger-worker" class="button button-primary">
					<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
					<?php esc_html_e('Process Queue Now', 'moneybird-sync-for-woo'); ?>
				</button>
			</div>
			<p class="mbsfw-last-sync">
				<?php if ($last_sync) printf(esc_html__('Last sync: %s', 'moneybird-sync-for-woo'), esc_html($last_sync)); else esc_html_e('No tasks processed yet.', 'moneybird-sync-for-woo'); ?>
			</p>
			<h2><?php esc_html_e('Recent Tasks', 'moneybird-sync-for-woo'); ?></h2>
			<?php $this->render_task_table($this->queue->get_all(20)); ?>
			<?php $this->render_logs_panel(); ?>
		</div>
		<?php
	}

	public function render_orders(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Insufficient permissions.', 'moneybird-sync-for-woo'));
		}
		$payment_method = isset($_GET['payment_method']) ? sanitize_key((string) $_GET['payment_method']) : 'stripe';
		$date_after = isset($_GET['date_after']) ? sanitize_text_field((string) $_GET['date_after']) : '';
		$date_before = isset($_GET['date_before']) ? sanitize_text_field((string) $_GET['date_before']) : '';
		$paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
		$per_page = 25;
		$task_map = $this->build_task_map();
		$orders = $this->get_unsynced_orders($payment_method, $date_after, $date_before, $per_page, ($paged - 1) * $per_page);
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e('Moneybird Sync — Unsynced Orders', 'moneybird-sync-for-woo'); ?></h1>
			<form method="get" class="mbsfw-filter-bar" style="margin-bottom:20px; background:#fff; padding:15px; border:1px solid #ccd0d4;">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_ORDERS); ?>" />
				<select name="payment_method">
					<option value=""><?php esc_html_e('All payment methods', 'moneybird-sync-for-woo'); ?></option>
					<?php foreach (WC()->payment_gateways()->get_available_payment_gateways() as $id => $gateway): ?>
						<option value="<?php echo esc_attr($id); ?>" <?php selected($payment_method, $id); ?>><?php echo esc_html($gateway->get_method_title() ?: $gateway->get_title()); ?></option>
					<?php endforeach; ?>
				</select>
				<label><?php esc_html_e('From:', 'moneybird-sync-for-woo'); ?> <input type="date" name="date_after" value="<?php echo esc_attr($date_after); ?>" /></label>
				<label><?php esc_html_e('To:', 'moneybird-sync-for-woo'); ?> <input type="date" name="date_before" value="<?php echo esc_attr($date_before); ?>" /></label>
				<button type="submit" class="button"><?php esc_html_e('Filter', 'moneybird-sync-for-woo'); ?></button>
			</form>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Order', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Customer', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Total', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Payment', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Sync Status', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Actions', 'moneybird-sync-for-woo'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($orders as $order):
						$order_id = $order->get_id();
						$task = $task_map[$order_id] ?? null;
						$sync_class = $this->sync_status_class($order, $task);
						?>
						<tr>
							<td><a href="<?php echo esc_url(get_edit_post_link($order_id)); ?>">#<?php echo esc_html((string) $order_id); ?></a></td>
							<td><?php echo esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())); ?></td>
							<td><?php echo esc_html($order->get_formatted_order_total()); ?></td>
							<td><?php echo esc_html($order->get_payment_method_title()); ?> <br><code style="font-size:10px;"><?php echo esc_html($order->get_payment_method()); ?></code></td>
							<td><span class="mbsfw-badge mbsfw-badge--<?php echo esc_attr($sync_class); ?>"><?php echo esc_html($sync_class); ?></span></td>
							<td>
								<?php if (!$task || Task::STATUS_FAILED === (string) $task->status): ?>
									<button class="button button-small mbsfw-manual-sync" data-order-id="<?php echo esc_attr((string) $order_id); ?>"><?php esc_html_e('Sync Now', 'moneybird-sync-for-woo'); ?></button>
								<?php endif; ?>
								<?php if ($task): ?>
									<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr((string) $task->id); ?>"><?php esc_html_e('Logs', 'moneybird-sync-for-woo'); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_logs_panel(); ?>
		</div>
		<?php
	}

	public function render_errors(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Insufficient permissions.', 'moneybird-sync-for-woo'));
		}
		$tasks = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->db->prefix}mb_tasks WHERE status = %s ORDER BY updated_at DESC LIMIT 100", Task::STATUS_FAILED)) ?? array();
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e('Moneybird Sync — Failed Tasks', 'moneybird-sync-for-woo'); ?></h1>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Task ID', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Type', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Details', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Last Error', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Actions', 'moneybird-sync-for-woo'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tasks as $row): $last_err = $this->get_last_task_error((int) $row->id); ?>
						<tr>
							<td>#<?php echo esc_html((string) $row->id); ?></td>
							<td><?php echo esc_html((string) $row->type); ?></td>
							<td><?php echo esc_html((string) $row->updated_at); ?></td>
							<td class="mbsfw-error-msg"><?php echo esc_html($last_err); ?></td>
							<td>
								<button class="button button-small mbsfw-retry-task" data-task-id="<?php echo esc_attr((string) $row->id); ?>"><?php esc_html_e('Retry', 'moneybird-sync-for-woo'); ?></button>
								<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr((string) $row->id); ?>"><?php esc_html_e('Logs', 'moneybird-sync-for-woo'); ?></button>
								<button class="button button-small button-link-delete mbsfw-delete-task" data-task-id="<?php echo esc_attr((string) $row->id); ?>"><?php esc_html_e('Delete', 'moneybird-sync-for-woo'); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_logs_panel(); ?>
		</div>
		<?php
	}

	public function render_payouts(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Insufficient permissions.', 'moneybird-sync-for-woo'));
		}
		$tasks = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->db->prefix}mb_tasks WHERE type = %s ORDER BY created_at DESC LIMIT 100", Task::TYPE_SYNC_PAYOUT)) ?? array();
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e('Moneybird Sync — Payouts', 'moneybird-sync-for-woo'); ?></h1>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Task ID', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Payout ID', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Status', 'moneybird-sync-for-woo'); ?></th>
						<th><?php esc_html_e('Actions', 'moneybird-sync-for-woo'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tasks as $row): ?>
						<tr>
							<td>#<?php echo esc_html((string) $row->id); ?></td>
							<td><?php $p = json_decode((string)$row->payload, true); echo esc_html($p['stripe_payout_id'] ?? '—'); ?></td>
							<td><?php echo esc_html((string) $row->status); ?></td>
							<td>
								<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr((string) $row->id); ?>"><?php esc_html_e('Logs', 'moneybird-sync-for-woo'); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_logs_panel(); ?>
		</div>
		<?php
	}

	public function render_settings(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Insufficient permissions.', 'moneybird-sync-for-woo'));
		}
		$s = self::get_settings();
		$oauth = OAuthClient::from_settings();
		$is_connected = $oauth->is_connected();
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e('Moneybird Sync — Settings', 'moneybird-sync-for-woo'); ?></h1>

			<h2><?php esc_html_e('Moneybird Connection', 'moneybird-sync-for-woo'); ?></h2>
			<?php if ($is_connected): ?>
				<div class="mbsfw-connection-status mbsfw-connection-status--connected" style="background:#e7f5ea; padding:15px; border:1px solid #c3e6cb; margin-bottom:20px;">
					<strong>✅ <?php esc_html_e('Connected', 'moneybird-sync-for-woo'); ?></strong>
				</div>
			<?php else: ?>
				<div class="mbsfw-connection-status mbsfw-connection-status--disconnected" style="background:#fcf2f2; padding:15px; border:1px solid #f5c6cb; margin-bottom:20px;">
					<strong>❌ <?php esc_html_e('Not connected', 'moneybird-sync-for-woo'); ?></strong>
					<p><a href="<?php echo esc_url(admin_url('admin.php?page=mb-onboarding')); ?>" class="button button-primary"><?php esc_html_e('Run Setup Wizard →', 'moneybird-sync-for-woo'); ?></a></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields('mbsfw_settings_group'); ?>
				<h2><?php esc_html_e('Sync Settings', 'moneybird-sync-for-woo'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Enable Sync', 'moneybird-sync-for-woo'); ?></th>
						<td><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_enabled]" value="1" <?php checked(!empty($s['sync_enabled'])); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Payment Methods to Sync', 'moneybird-sync-for-woo'); ?></th>
						<td>
							<?php
							$allowed_raw = (string) ($s['allowed_payment_methods'] ?? '["stripe"]');
							$allowed = json_decode($allowed_raw, true);
							if (!is_array($allowed) || empty($allowed)) $allowed = array('stripe');

							foreach (WC()->payment_gateways()->get_available_payment_gateways() as $id => $gateway): ?>
								<label style="display:block; margin-bottom:5px;">
									<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allowed_payment_methods][]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, $allowed, true)); ?> />
									<?php echo esc_html($gateway->get_method_title() ?: $gateway->get_title()); ?> (<code><?php echo esc_html($id); ?></code>)
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e('Account Mapping', 'moneybird-sync-for-woo'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Stripe Clearing Account ID', 'moneybird-sync-for-woo'); ?></th>
						<td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[clearing_account_id]" value="<?php echo esc_attr($s['clearing_account_id'] ?? ''); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Bank Account ID', 'moneybird-sync-for-woo'); ?></th>
						<td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[bank_account_id]" value="<?php echo esc_attr($s['bank_account_id'] ?? ''); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Fees Ledger Account ID', 'moneybird-sync-for-woo'); ?></th>
						<td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[fees_ledger_account_id]" value="<?php echo esc_attr($s['fees_ledger_account_id'] ?? ''); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_onboarding(): void { $this->onboarding->render(); }

	private function render_task_table(array $tasks): void
	{
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Task ID', 'moneybird-sync-for-woo'); ?></th>
					<th><?php esc_html_e('Type', 'moneybird-sync-for-woo'); ?></th>
					<th><?php esc_html_e('Status', 'moneybird-sync-for-woo'); ?></th>
					<th><?php esc_html_e('Actions', 'moneybird-sync-for-woo'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($tasks as $task): ?>
					<tr>
						<td>#<?php echo esc_html((string) $task->id); ?></td>
						<td><?php echo esc_html((string) $task->type); ?></td>
						<td><?php echo esc_html((string) $task->status); ?></td>
						<td>
							<button class="button button-small mbsfw-view-logs" data-task-id="<?php echo esc_attr((string) $task->id); ?>"><?php esc_html_e('Logs', 'moneybird-sync-for-woo'); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_logs_panel(): void
	{
		?>
		<div id="mbsfw-logs-panel" style="display:none; margin-top:20px;">
			<div class="mbsfw-wizard-card" style="padding:15px; background:#fff; border:1px solid #ccd0d4;">
				<h2><?php esc_html_e('Task Logs', 'moneybird-sync-for-woo'); ?> <button id="mbsfw-logs-close" class="button button-small"><?php esc_html_e('Close', 'moneybird-sync-for-woo'); ?></button></h2>
				<div id="mbsfw-logs-content"></div>
			</div>
		</div>
		<?php
	}

	private function stat_card(string $label, string $value, string $icon, string $color): void
	{
		?>
		<div class="mbsfw-stat-card mbsfw-stat-card--<?php echo esc_attr($color); ?>">
			<span class="dashicons <?php echo esc_attr($icon); ?>"></span>
			<div class="mbsfw-stat-card__content">
				<span class="mbsfw-stat-card__label"><?php echo esc_html($label); ?></span>
				<span class="mbsfw-stat-card__value"><?php echo esc_html($value); ?></span>
			</div>
		</div>
		<?php
	}

	public function ajax_get_logs(): void
	{
		check_ajax_referer(self::NONCE_ACTION);
		$task_id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : null;
		$logs = $this->logger->get_logs($task_id);
		wp_send_json_success(array_map(static function ($log) {
			return array('message' => esc_html($log->message), 'created_at' => esc_html($log->created_at));
		}, $logs));
	}

	public function ajax_retry_task(): void
	{
		check_ajax_referer(self::NONCE_ACTION);
		$task_id = (int) ($_POST['task_id'] ?? 0);
		$this->db->update($this->db->prefix . 'mb_tasks', array('status' => Task::STATUS_PENDING, 'attempts' => 0), array('id' => $task_id));
		wp_send_json_success();
	}

	public function ajax_delete_task(): void
	{
		check_ajax_referer(self::NONCE_ACTION);
		$task_id = (int) ($_POST['task_id'] ?? 0);
		$this->db->delete($this->db->prefix . 'mb_tasks', array('id' => $task_id));
		wp_send_json_success();
	}

	public function ajax_manual_sync(): void
	{
		check_ajax_referer(self::NONCE_ACTION);
		$order_id = (int) ($_POST['order_id'] ?? 0);
		$this->queue->create(Task::TYPE_SYNC_ORDER, array('order_id' => $order_id));
		wp_send_json_success();
	}

	public function ajax_trigger_worker(): void
	{
		check_ajax_referer(self::NONCE_ACTION);
		$this->queue->reset_failed();
		$this->worker->run();
		wp_send_json_success();
	}

	private function build_task_map(): array
	{
		$tasks = $this->db->get_results("SELECT * FROM {$this->db->prefix}mb_tasks WHERE type = 'sync_order'");
		$map = array();
		foreach ($tasks as $row) {
			$p = json_decode((string)$row->payload, true);
			if (isset($p['order_id'])) $map[$p['order_id']] = $row;
		}
		return $map;
	}

	private function get_unsynced_orders(string $method, string $after, string $before, int $limit, int $offset): array
	{
		$args = array('limit' => $limit, 'offset' => $offset);
		if ($method) $args['payment_method'] = $method;
		if ($after) $args['date_after'] = $after;
		if ($before) $args['date_before'] = $before;
		return wc_get_orders($args);
	}

	private function sync_status_class(\WC_Order $order, $task): string
	{
		if (!$task) return 'unsynced';
		return (string) $task->status;
	}

	private function count_synced_orders(): int
	{
		return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}mb_tasks WHERE status = 'completed'");
	}

	private function get_last_sync_time(): ?string
	{
		return $this->db->get_var("SELECT completed_at FROM {$this->db->prefix}mb_tasks WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1");
	}

	private function get_last_task_error(int $task_id): string
	{
		$log = $this->db->get_row($this->db->prepare("SELECT message FROM {$this->db->prefix}mb_logs WHERE task_id = %d AND level = 'error' ORDER BY created_at DESC LIMIT 1", $task_id));
		return $log ? (string) $log->message : '';
	}
}
