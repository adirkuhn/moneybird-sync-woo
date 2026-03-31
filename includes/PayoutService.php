<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;

/**
 * Processes sync_payout tasks.
 *
 * Accounting model:
 *   Transfer: Stripe Clearing Account → Bank Account
 *
 * IMPORTANT: Payouts are NEVER matched to individual invoices.
 * Reconciliation is always done on totals: Bank ↔ Stripe Clearing.
 */
class PayoutService {
	private MoneybirdClient $client;
	private Logger $logger;
	private string $clearing_account_id;
	private string $bank_account_id;

	public function __construct(
		MoneybirdClient $client,
		Logger $logger,
		string $clearing_account_id,
		string $bank_account_id
	) {
		$this->client              = $client;
		$this->logger              = $logger;
		$this->clearing_account_id = $clearing_account_id;
		$this->bank_account_id     = $bank_account_id;
	}

	/**
	 * @throws \RuntimeException|\InvalidArgumentException
	 */
	public function process( Task $task ): void {
		$stripe_payout_id = isset( $task->payload['stripe_payout_id'] ) ? (string) $task->payload['stripe_payout_id'] : '';
		$amount           = isset( $task->payload['amount'] ) ? (string) $task->payload['amount'] : '';
		$date             = isset( $task->payload['date'] ) ? (string) $task->payload['date'] : gmdate( 'Y-m-d' );
		$description      = isset( $task->payload['description'] ) ? (string) $task->payload['description'] : 'Stripe payout';

		if ( ! $stripe_payout_id || ! $amount ) {
			throw new \InvalidArgumentException( 'Missing stripe_payout_id or amount in task payload.' );
		}

		$this->logger->info( "Processing sync_payout for Stripe payout {$stripe_payout_id}.", $task->id );

		// Transfer from Stripe Clearing → Bank. Never link to invoices.
		$this->client->create_transfer(
			$this->clearing_account_id,
			$this->bank_account_id,
			$amount,
			$date,
			$description . ' (' . $stripe_payout_id . ')'
		);

		$this->logger->info(
			"Transfer {$amount} from Stripe Clearing → Bank (payout: {$stripe_payout_id}).",
			$task->id
		);
	}
}
