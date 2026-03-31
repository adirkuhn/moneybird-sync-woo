# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Dev Commands

```bash
composer install                          # install all dependencies

vendor/bin/phpunit                        # run all tests
vendor/bin/phpunit tests/unit/FooTest.php # run a single test file
vendor/bin/phpunit --filter test_name     # run a single test by name

vendor/bin/phpstan analyse --configuration=config/phpstan.neon
vendor/bin/phpcs --standard=config/phpcs.xml
```

CI runs all three commands in sequence and fails on any error.

## Accounting model (non-negotiable)

All Stripe payments flow through a **Stripe Clearing Account** in Moneybird — never directly to the bank.

| Event | Moneybird action |
|---|---|
| Order paid via Stripe | Invoice payment → Stripe Clearing Account |
| Stripe fee charged | Journal entry: Debit Fees ledger / Credit Clearing |
| Stripe payout to bank | Transfer: Clearing → Bank |
| Reconciliation | Compare Bank ↔ Clearing totals. **Never** per-invoice matching. |

## Architecture overview

### OAuth & connection state

`OAuthClient` handles the full OAuth 2.0 authorization code flow. Credentials can be set via `wp-config.php` constants (`MBSFW_OAUTH_CLIENT_ID`, `MBSFW_OAUTH_CLIENT_SECRET`) or the Settings page. The access token is stored in `wp_options` as `mbsfw_oauth_token`. The selected administration ID is stored as `mbsfw_administration_id`.

OAuth callback URL (must be registered in Moneybird):
`{site}/wp-admin/admin.php?page=mb-onboarding&mbsfw_oauth=callback`

### Admin pages

| Slug | Class method | Purpose |
|---|---|---|
| `mb-onboarding` | `Onboarding::render()` | 5-step setup wizard |
| `mb-dashboard` | `AdminUI::render_dashboard()` | Stats + recent tasks |
| `mb-orders` | `AdminUI::render_orders()` | Unsynced WooCommerce orders |
| `mb-errors` | `AdminUI::render_errors()` | Failed tasks with retry/delete |
| `mb-payouts` | `AdminUI::render_payouts()` | Payout task visibility |
| `mb-settings` | `AdminUI::render_settings()` | OAuth credentials + sync toggle |

The top-level menu points to `mb-dashboard` when sync is enabled, and `mb-onboarding` otherwise.

### Onboarding wizard (5 steps)

Progress is derived from stored state — not a separate counter — so it's always consistent.

1. **Connect** — OAuth button → Moneybird grants access token.
2. **Administration** — fetch `/api/v2/administrations`, user picks one.
3. **Accounts** — fetch `/api/v2/{admin_id}/financial_accounts` + `/ledger_accounts`, user maps Clearing / Bank / Fees.
4. **Test** — calls `MoneybirdClient::test_connection()`, stores `mbsfw_connection_tested`.
5. **Enable** — sets `mbsfw_settings.sync_enabled = 1`, redirects to dashboard.

All form submissions are nonce-protected (`mbsfw_onboarding`) and handled in `Onboarding::handle_requests()` via `admin_init` (before any output, enabling safe redirects).

### Task queue

Every sync operation is persisted as a row in `wp_mb_tasks` before anything is sent to Moneybird. The WP-Cron worker (`Worker`) runs every minute, fetches up to 10 pending tasks, locks each one atomically, and dispatches by `type`:

- `sync_order` → `SyncService`
- `sync_fee` → `FeeService`
- `sync_payout` → `PayoutService`

Failed tasks are re-queued up to `max_attempts` (default 3), then marked `failed`. Stale locks (>300 s) are released automatically.

### Idempotency

`SyncService` uses two WooCommerce order meta flags:
- `_mb_invoice_id` — set once the Moneybird invoice exists.
- `_mb_payment_created` — set once the payment is registered.

`OrderListener` uses `_mb_sync_queued` to prevent duplicate task creation.

### Key files

| File | Role |
|---|---|
| `moneybird-sync-for-woo.php` | Plugin header, constants, activation/deactivation, bootstrap |
| `includes/class-plugin.php` | Singleton wiring all services |
| `includes/class-oauth-client.php` | OAuth 2.0 flow, token storage, disconnect |
| `includes/class-onboarding.php` | 5-step setup wizard (request handling + rendering) |
| `includes/class-admin-ui.php` | 5 operational pages + all AJAX endpoints |
| `includes/class-worker.php` | WP-Cron dispatch, lock/retry logic |
| `includes/class-moneybird-client.php` | All Moneybird API calls |
| `includes/class-task-queue.php` | DB CRUD for `wp_mb_tasks`, atomic locking |
| `includes/class-sync-service.php` | `sync_order` handler |
| `includes/class-fee-service.php` | `sync_fee` handler |
| `includes/class-payout-service.php` | `sync_payout` handler |
| `includes/class-reconciliation-service.php` | Bank ↔ Clearing reconciliation |
| `includes/class-order-listener.php` | WooCommerce hooks → task queue |
| `includes/class-logger.php` | DB-backed structured logging |
| `models/class-task.php` | Task value object |
| `database/migrations.sql` | `dbDelta`-compatible DDL (`{prefix}` replaced at activation) |
| `assets/admin.css` | Wizard step bar, stat cards, badges, filter bar |
| `assets/admin.js` | AJAX for logs, retry, delete, manual sync, payload viewer |

### Settings storage

All settings stored in `wp_options` under `mbsfw_settings` (array):
- `oauth_client_id`, `oauth_client_secret` (if not in wp-config.php)
- `clearing_account_id`, `bank_account_id`, `fees_ledger_account_id`
- `sync_enabled`

Token stored separately under `mbsfw_oauth_token`. Administration ID under `mbsfw_administration_id`.

### Test approach

Tests run without WordPress. `tests/bootstrap.php` stubs all WP/WC globals:
- HTTP: `$GLOBALS['__mbsfw_http_handler']` (callable)
- Orders: `$GLOBALS['__mbsfw_wc_order_map']` (int → WC_Order mock)
- Options: `$GLOBALS['__mbsfw_options']` (simulated wp_options)
- `wpdb` is mocked with PHPUnit — no real DB calls.
