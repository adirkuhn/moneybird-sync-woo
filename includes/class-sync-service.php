<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;
use WC_Order;

/**
 * Processes sync_order tasks.
 *
 * Flow:
 *   1. Validate order is a Stripe payment.
 *   2. Find or create a Moneybird sales invoice (idempotent via _mb_invoice_id meta).
 *   3. Register a payment on the invoice pointing to the Stripe Clearing Account
 *      (idempotent via _mb_payment_created meta).
 */
class SyncService {
	private MoneybirdClient $client;
	private Logger $logger;
	private string $clearing_account_id;

	public function __construct(
		MoneybirdClient $client,
		Logger $logger,
		string $clearing_account_id
	) {
		$this->client              = $client;
		$this->logger              = $logger;
		$this->clearing_account_id = $clearing_account_id;
	}

	/**
	 * @throws \RuntimeException|\InvalidArgumentException
	 */
	public function process( Task $task ): void {
		$order_id = isset( $task->payload['order_id'] ) ? (int) $task->payload['order_id'] : 0;
		if ( ! $order_id ) {
			throw new \InvalidArgumentException( 'Missing order_id in task payload.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new \RuntimeException( "Order {$order_id} not found." );
		}

		if ( 'stripe' !== $order->get_payment_method() ) {
			$this->logger->info( "Order {$order_id} is not a Stripe order. Skipping.", $task->id );
			return;
		}

		$this->logger->info( "Processing sync_order for order {$order_id}.", $task->id );

		$invoice_id = $this->ensure_invoice( $order, $task->id );
		$this->ensure_payment( $order, $invoice_id, $task->id );

		$this->logger->info( "Order {$order_id} synced successfully.", $task->id );
	}

	private function ensure_invoice( WC_Order $order, int $task_id ): string {
		$existing = (string) $order->get_meta( '_mb_invoice_id', true );
		if ( $existing ) {
			$this->logger->info( "Invoice already exists: {$existing}. Skipping creation.", $task_id );
			return $existing;
		}

		// Guard against duplicate via Moneybird reference lookup.
		$reference = 'WOO-' . $order->get_id();
		$invoice   = $this->client->find_invoice_by_reference( $reference );

		if ( $invoice ) {
			$invoice_id = (string) $invoice['id'];
			$order->update_meta_data( '_mb_invoice_id', $invoice_id );
			$order->save_meta_data();
			$this->logger->info( "Found existing Moneybird invoice {$invoice_id}.", $task_id );
			return $invoice_id;
		}

		$contact = $this->client->find_or_create_contact(
			(string) $order->get_billing_email(),
			trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() )
		);

		$details = array();
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$qty       = max( 1, $item->get_quantity() );
			$details[] = array(
				'description' => $item->get_name(),
				'amount'      => $qty,
				'price'       => number_format( (float) $item->get_total() / $qty, 2, '.', '' ),
			);
		}

		$invoice    = $this->client->create_invoice(
			array(
				'contact_id'                  => (string) $contact['id'],
				'reference'                   => $reference,
				'invoice_date'                => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
				'details_attributes'          => $details,
			)
		);
		$invoice_id = (string) $invoice['id'];

		$order->update_meta_data( '_mb_invoice_id', $invoice_id );
		$order->save_meta_data();
		$this->logger->info( "Created Moneybird invoice {$invoice_id}.", $task_id );
		return $invoice_id;
	}

	private function ensure_payment( WC_Order $order, string $invoice_id, int $task_id ): void {
		if ( $order->get_meta( '_mb_payment_created', true ) ) {
			$this->logger->info( 'Payment already registered. Skipping.', $task_id );
			return;
		}

		$amount = number_format( (float) $order->get_total(), 2, '.', '' );
		$date   = $order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d' ) : gmdate( 'Y-m-d' );

		$this->client->create_invoice_payment(
			$invoice_id,
			$this->clearing_account_id,
			$amount,
			$date
		);

		$order->update_meta_data( '_mb_payment_created', '1' );
		$order->save_meta_data();
		$this->logger->info(
			"Payment of {$amount} registered on invoice {$invoice_id} → Stripe Clearing Account.",
			$task_id
		);
	}
}
