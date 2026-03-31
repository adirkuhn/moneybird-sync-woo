<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

/**
 * Plugin bootstrap singleton. Wires all services together and delegates
 * hook registration to each component.
 *
 * Initialization order:
 *   1. OAuthClient    – reads stored token.
 *   2. MoneybirdClient – built with OAuth token + selected administration.
 *   3. All services   – built with client + logger.
 *   4. Onboarding     – registers its own admin_init handler.
 *   5. AdminUI        – registers menu + AJAX.
 *   6. Worker         – registers cron hook.
 *   7. OrderListener  – registers WooCommerce hooks (only if sync enabled).
 */
class Plugin {
	private static ?self $instance = null;

	private OAuthClient $oauth;
	private MoneybirdClient $client;
	private Logger $logger;
	private TaskQueue $queue;
	private SyncService $sync_service;
	private FeeService $fee_service;
	private PayoutService $payout_service;
	private ReconciliationService $reconciliation;
	private OrderListener $order_listener;
	private Worker $worker;
	private AdminUI $admin_ui;
	private Onboarding $onboarding;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		global $wpdb;

		// ── OAuth & API client ─────────────────────────────────────────────────
		$this->oauth  = OAuthClient::from_settings();
		$this->client = new MoneybirdClient(
			$this->oauth->get_access_token(),
			$this->oauth->get_selected_administration_id()
		);

		// ── Core infrastructure ────────────────────────────────────────────────
		$this->logger = new Logger( $wpdb );
		$this->queue  = new TaskQueue( $wpdb );

		// ── Domain services ────────────────────────────────────────────────────
		$settings = AdminUI::get_settings();
		$clearing = (string) ( $settings['clearing_account_id'] ?? '' );
		$bank     = (string) ( $settings['bank_account_id'] ?? '' );
		$fees_la  = (string) ( $settings['fees_ledger_account_id'] ?? '' );

		$this->sync_service    = new SyncService( $this->client, $this->logger, $clearing );
		$this->fee_service     = new FeeService( $this->client, $this->logger, $clearing, $fees_la );
		$this->payout_service  = new PayoutService( $this->client, $this->logger, $clearing, $bank );
		$this->reconciliation  = new ReconciliationService( $this->client, $this->logger );

		// ── Listeners & workers ────────────────────────────────────────────────
		$this->order_listener = new OrderListener( $this->queue, $this->logger );
		$this->worker         = new Worker(
			$this->queue,
			$this->sync_service,
			$this->fee_service,
			$this->payout_service,
			$this->logger
		);

		// ── Admin ──────────────────────────────────────────────────────────────
		$this->admin_ui   = new AdminUI( $this->queue, $this->logger, $wpdb );
		$this->onboarding = new Onboarding( $this->oauth, $this->client );

		// ── Hook registration ──────────────────────────────────────────────────
		$this->onboarding->register();
		$this->admin_ui->register();
		$this->worker->register();

		// Only queue new orders when sync is explicitly enabled.
		if ( ! empty( $settings['sync_enabled'] ) ) {
			$this->order_listener->register_hooks();
		}
	}

	public function get_reconciliation_service(): ReconciliationService {
		return $this->reconciliation;
	}

	public function get_oauth(): OAuthClient {
		return $this->oauth;
	}
}
