<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

/**
 * Reconciliation: Bank ↔ Stripe Clearing Account totals.
 *
 * Rule: NEVER reconcile per invoice. Always compare account-level totals.
 */
class ReconciliationService {
	private MoneybirdClient $client;
	private Logger $logger;

	public function __construct( MoneybirdClient $client, Logger $logger ) {
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * Verify that the Stripe Clearing Account and Bank Account mutation totals match
	 * for the given date range.
	 *
	 * @return bool True if balanced, false on discrepancy.
	 */
	public function reconcile(
		string $clearing_account_id,
		string $bank_account_id,
		string $start_date,
		string $end_date
	): bool {
		$this->logger->info(
			"Reconciling Clearing ({$clearing_account_id}) ↔ Bank ({$bank_account_id}) [{$start_date}..{$end_date}]."
		);

		$filter = "period:{$start_date}..{$end_date}";

		$clearing_mutations = $this->client->get_financial_mutations( $clearing_account_id, array( 'filter' => $filter ) );
		$bank_mutations     = $this->client->get_financial_mutations( $bank_account_id, array( 'filter' => $filter ) );

		$clearing_total = $this->sum_mutations( $clearing_mutations );
		$bank_total     = $this->sum_mutations( $bank_mutations );

		$balanced = 0 === bccomp( (string) $clearing_total, (string) $bank_total, 2 );

		if ( $balanced ) {
			$this->logger->info( "Reconciliation balanced: clearing={$clearing_total}, bank={$bank_total}." );
		} else {
			$diff = bcsub( (string) $clearing_total, (string) $bank_total, 2 );
			$this->logger->warning(
				"Reconciliation discrepancy: clearing={$clearing_total}, bank={$bank_total}, diff={$diff}."
			);
		}

		return $balanced;
	}

	/**
	 * @param array<int, array<string, mixed>> $mutations
	 */
	private function sum_mutations( array $mutations ): float {
		return (float) array_reduce(
			$mutations,
			static function ( float $carry, array $m ): float {
				return $carry + (float) ( $m['amount'] ?? 0 );
			},
			0.0
		);
	}
}
