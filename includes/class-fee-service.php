<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;

/**
 * Processes sync_fee tasks.
 *
 * Accounting model:
 *   Debit  → Stripe Fees expense ledger account
 *   Credit → Stripe Clearing Account (reduces clearing balance)
 */
class FeeService {
	private MoneybirdClient $client;
	private Logger $logger;
	private string $clearing_account_id;
	private string $fees_ledger_account_id;

	public function __construct(
		MoneybirdClient $client,
		Logger $logger,
		string $clearing_account_id,
		string $fees_ledger_account_id
	) {
		$this->client                 = $client;
		$this->logger                 = $logger;
		$this->clearing_account_id    = $clearing_account_id;
		$this->fees_ledger_account_id = $fees_ledger_account_id;
	}

	/**
	 * @throws \RuntimeException|\InvalidArgumentException
	 */
	public function process( Task $task ): void {
		$stripe_fee_id = isset( $task->payload['stripe_fee_id'] ) ? (string) $task->payload['stripe_fee_id'] : '';
		$amount        = isset( $task->payload['amount'] ) ? (string) $task->payload['amount'] : '';
		$date          = isset( $task->payload['date'] ) ? (string) $task->payload['date'] : gmdate( 'Y-m-d' );
		$description   = isset( $task->payload['description'] ) ? (string) $task->payload['description'] : 'Stripe transaction fee';

		if ( ! $stripe_fee_id || ! $amount ) {
			throw new \InvalidArgumentException( 'Missing stripe_fee_id or amount in task payload.' );
		}

		$this->logger->info( "Processing sync_fee for Stripe fee {$stripe_fee_id}.", $task->id );

		$this->client->create_journal_document(
			array(
				'reference'   => 'STRIPE-FEE-' . $stripe_fee_id,
				'date'        => $date,
				'description' => $description,
				'general_journal_document_entries_attributes' => array(
					array(
						'ledger_account_id' => $this->fees_ledger_account_id,
						'debit'             => $amount,
					),
					array(
						'financial_account_id' => $this->clearing_account_id,
						'credit'               => $amount,
					),
				),
			)
		);

		$this->logger->info( "Fee journal entry created: {$amount} (fee ID: {$stripe_fee_id}).", $task->id );
	}
}
