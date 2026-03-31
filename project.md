You are a senior WordPress plugin engineer specialized in WooCommerce and Moneybird integrations.

Build a production-grade WooCommerce plugin that syncs Stripe orders to Moneybird using a Stripe Clearing Account model.

---

# 🎯 CORE ACCOUNTING MODEL (CRITICAL)

1. All Stripe payments → Stripe Clearing Account (NOT bank)
2. Fees → expense (reduce clearing)
3. Payouts → transfer clearing → bank
4. Matching:

   * NEVER per invoice
   * ALWAYS: Bank ↔ Stripe Clearing totals

---

# 🧱 SYSTEM ARCHITECTURE

/plugin
/includes
class-plugin.php
class-order-listener.php
class-sync-service.php
class-moneybird-client.php
class-task-queue.php
class-worker.php
class-fee-service.php
class-payout-service.php
class-reconciliation-service.php
class-logger.php
class-admin-ui.php

/models
class-task.php

/database
migrations.sql

/tests
/unit
bootstrap.php

/config
phpstan.neon
phpcs.xml

/.github/workflows
ci.yml

---

# 📦 DEPENDENCY MANAGEMENT (COMPOSER REQUIRED)

Create composer.json with:

* phpunit/phpunit
* phpstan/phpstan
* dealerdirect/phpcodesniffer-composer-installer
* wp-coding-standards/wpcs

---

# 🧪 UNIT TESTING (MANDATORY)

Use PHPUnit.

## Structure:

/tests/unit
OrderSyncTest.php
TaskQueueTest.php
MoneybirdClientTest.php

---

## TEST REQUIREMENTS

### Order Sync

* creates invoice if missing
* does not duplicate invoice
* creates payment in clearing account
* respects idempotency flags

### Task Queue

* creates task
* processes tasks correctly
* retries on failure
* stops after max_attempts

### Moneybird Client

* builds correct API payloads
* handles API errors
* validates responses

---

## TEST RULES

* DO NOT call real Moneybird API

* Use mocks for:

  * Moneybird client
  * WooCommerce order

* All tests must be deterministic

---

## CRITICAL TEST CASES

* duplicate execution → no duplicate payment
* partial failure → resumes correctly
* retry → succeeds after failure
* interrupted task → safe re-run

---

# 🔍 STATIC ANALYSIS (PHPSTAN)

Use PHPStan

## Configuration:

File: /config/phpstan.neon

Rules:

* level: max (or 8+)
* enforce strict typing
* no undefined variables
* proper return types
* avoid mixed types

---

# 🎨 CODE QUALITY (PHPCS)

Use WordPress Coding Standards.

File: /config/phpcs.xml

Enforce:

* escaping output
* sanitizing input
* naming conventions
* WordPress best practices

---

# ⚙️ CI/CD (GITHUB ACTIONS REQUIRED)

Use GitHub Actions

File: /.github/workflows/ci.yml

---

## PIPELINE STEPS

1. Checkout repository
2. Setup PHP (8.1+)
3. Install dependencies (composer install)
4. Run PHPStan
5. Run PHPCS
6. Run PHPUnit

---

## REQUIRED COMMANDS

* vendor/bin/phpstan analyse
* vendor/bin/phpcs
* vendor/bin/phpunit

---

## FAILURE CONDITIONS

Pipeline MUST fail if:

* any PHPUnit test fails
* PHPStan finds errors
* PHPCS violations exist

---

# 🧠 TASK SYSTEM

ALL actions must be tasks.

Types:

* sync_order
* sync_fee
* sync_payout

---

# ⚙️ WORKER

Runs via WP-Cron every minute:

* fetch pending tasks
* lock task
* process
* retry if needed

---

# 🧾 ORDER SYNC

1. Validate Stripe payment

2. Ensure invoice

3. Create payment:

   * full amount
   * Stripe Clearing Account

4. Save meta:

   * _mb_invoice_id
   * _mb_payment_created

---

# 💸 FEE SYNC

* Pull Stripe fees
* Create journal:

  * Debit: Fees
  * Credit: Clearing

---

# 🏦 PAYOUT SYNC

* Pull Stripe payout
* Create transfer:

  * Clearing → Bank

---

# 🔗 RECONCILIATION LOGIC

* NEVER match payout with invoices
* ALWAYS reconcile totals:

  Bank ↔ Stripe Clearing

---

# 🧠 IDEMPOTENCY

Use meta flags:

* _mb_invoice_id
* _mb_payment_created

Ensure all operations are repeat-safe.

---

# 📜 LOGGING

* Log every task step
* Store logs in DB
* UI must display logs

---

# ♻️ CONTINUITY SYSTEM (AI HANDOFF)

System must be resumable:

* all tasks persisted
* payload self-contained
* logs detailed
* no hidden state

Another AI must be able to:

* read DB
* resume tasks
* continue safely

---

# 🧪 TEST SCENARIOS (MANDATORY)

* 1 order success
* 500+ orders batch
* duplicate trigger
* API failure
* retry success
* interrupted execution resume

---

# 🎨 UI REQUIREMENTS

* WordPress admin UI standards
* filterable tables
* AJAX actions
* clear error messages

---

# 🔒 SECURITY

* Nonces for all actions
* sanitize input
* escape output

---

# 🧩 FINAL GOAL

System must guarantee:

* no duplicate payments
* no lost data
* full auditability
* reliable reconciliation
* CI/CD validated quality

---

Output clean, modular, production-ready PHP code with full test coverage and CI setup.
