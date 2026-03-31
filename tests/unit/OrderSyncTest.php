<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo\Tests\Unit;

use MoneybirdSyncForWoo\Logger;
use MoneybirdSyncForWoo\MoneybirdClient;
use MoneybirdSyncForWoo\Models\Task;
use MoneybirdSyncForWoo\SyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WC_Order;

class OrderSyncTest extends TestCase {
	private MoneybirdClient&MockObject $client;
	private Logger&MockObject $logger;
	private SyncService $service;

	protected function setUp(): void {
		$this->client  = $this->createMock( MoneybirdClient::class );
		$this->logger  = $this->createMock( Logger::class );
		$this->service = new SyncService( $this->client, $this->logger, 'clearing_123' );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function make_task( array $payload ): Task {
		$task              = new Task();
		$task->id          = 1;
		$task->type        = Task::TYPE_SYNC_ORDER;
		$task->status      = Task::STATUS_PENDING;
		$task->payload     = $payload;
		$task->attempts    = 0;
		$task->max_attempts = 3;
		$task->locked_at   = null;
		$task->completed_at = null;
		$task->created_at  = '2024-01-01 00:00:00';
		$task->updated_at  = '2024-01-01 00:00:00';
		return $task;
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function make_order( int $id = 42, string $payment_method = 'stripe', array $meta = [] ): WC_Order&MockObject {
		$order = $this->createMock( WC_Order::class );
		$order->method( 'get_id' )->willReturn( $id );
		$order->method( 'get_payment_method' )->willReturn( $payment_method );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );
		$order->method( 'get_billing_first_name' )->willReturn( 'Jane' );
		$order->method( 'get_billing_last_name' )->willReturn( 'Doe' );
		$order->method( 'get_total' )->willReturn( 99.99 );
		$order->method( 'get_items' )->willReturn( array() );
		$order->method( 'get_date_created' )->willReturn( null );
		$order->method( 'get_date_paid' )->willReturn( null );
		$order->method( 'get_meta' )->willReturnCallback(
			static function ( string $key ) use ( $meta ): string {
				return $meta[ $key ] ?? '';
			}
		);
		return $order;
	}

	// ── Tests ──────────────────────────────────────────────────────────────

	public function test_skips_non_stripe_order(): void {
		$order = $this->make_order( 42, 'paypal' );

		$this->client->expects( $this->never() )->method( 'create_invoice' );
		$this->client->expects( $this->never() )->method( 'create_invoice_payment' );

		$this->service_process_with_order( $order, 42 );
	}

	public function test_creates_invoice_when_missing(): void {
		$order = $this->make_order( 42, 'stripe', array( '_mb_invoice_id' => '', '_mb_payment_created' => '' ) );

		$this->client->method( 'find_invoice_by_reference' )->willReturn( null );
		$this->client->method( 'find_or_create_contact' )->willReturn( array( 'id' => 'contact_1' ) );
		$this->client->expects( $this->once() )
			->method( 'create_invoice' )
			->with( $this->arrayHasKey( 'reference' ) )
			->willReturn( array( 'id' => 'inv_abc' ) );
		$this->client->expects( $this->once() )
			->method( 'create_invoice_payment' )
			->with( 'inv_abc', 'clearing_123', '99.99', $this->isType( 'string' ) );

		$order->expects( $this->atLeastOnce() )->method( 'update_meta_data' );
		$order->expects( $this->atLeastOnce() )->method( 'save_meta_data' );

		$this->service_process_with_order( $order, 42 );
	}

	public function test_does_not_duplicate_invoice(): void {
		// _mb_invoice_id already set — should skip creation.
		$order = $this->make_order( 42, 'stripe', array( '_mb_invoice_id' => 'inv_existing', '_mb_payment_created' => '' ) );

		$this->client->expects( $this->never() )->method( 'create_invoice' );
		$this->client->expects( $this->once() )
			->method( 'create_invoice_payment' )
			->with( 'inv_existing', 'clearing_123', '99.99', $this->isType( 'string' ) );

		$this->service_process_with_order( $order, 42 );
	}

	public function test_does_not_duplicate_payment(): void {
		// Both meta flags are set — should skip both invoice creation and payment.
		$order = $this->make_order(
			42,
			'stripe',
			array( '_mb_invoice_id' => 'inv_existing', '_mb_payment_created' => '1' )
		);

		$this->client->expects( $this->never() )->method( 'create_invoice' );
		$this->client->expects( $this->never() )->method( 'create_invoice_payment' );

		$this->service_process_with_order( $order, 42 );
	}

	public function test_creates_payment_in_clearing_account(): void {
		$order = $this->make_order( 42, 'stripe', array( '_mb_invoice_id' => 'inv_abc', '_mb_payment_created' => '' ) );

		$this->client->expects( $this->once() )
			->method( 'create_invoice_payment' )
			->with(
				'inv_abc',
				'clearing_123',   // must target Stripe Clearing Account.
				'99.99',
				$this->isType( 'string' )
			)
			->willReturn( array( 'id' => 'pay_1' ) );

		$this->service_process_with_order( $order, 42 );
	}

	public function test_recovers_invoice_id_from_moneybird_when_meta_is_missing(): void {
		// Meta is empty but Moneybird already has an invoice with this reference.
		$order = $this->make_order( 42, 'stripe', array( '_mb_invoice_id' => '', '_mb_payment_created' => '' ) );

		$this->client->expects( $this->once() )
			->method( 'find_invoice_by_reference' )
			->with( 'WOO-42' )
			->willReturn( array( 'id' => 'inv_recovered' ) );

		// Must NOT create a new invoice.
		$this->client->expects( $this->never() )->method( 'create_invoice' );

		// Must update meta with the recovered invoice ID.
		$order->expects( $this->atLeastOnce() )
			->method( 'update_meta_data' )
			->with( '_mb_invoice_id', 'inv_recovered' );
		$order->expects( $this->atLeastOnce() )->method( 'save_meta_data' );

		// Payment must still be registered on the recovered invoice.
		$this->client->expects( $this->once() )
			->method( 'create_invoice_payment' )
			->with( 'inv_recovered', 'clearing_123', '99.99', $this->isType( 'string' ) );

		$this->service_process_with_order( $order, 42 );
	}

	public function test_throws_on_missing_order_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		$task = $this->make_task( array() );
		$this->service->process( $task );
	}

	// ── Internal helper: inject order via wc_get_order override ────────────

	/**
	 * Temporarily overrides wc_get_order so SyncService can retrieve the mock.
	 */
	private function service_process_with_order( WC_Order $order, int $order_id ): void {
		// Store order in global registry and patch the stub.
		$GLOBALS['__mbsfw_wc_order_map'] = array( $order_id => $order );
		$task = $this->make_task( array( 'order_id' => $order_id ) );
		try {
			$this->service->process( $task );
		} finally {
			$GLOBALS['__mbsfw_wc_order_map'] = array();
		}
	}
}
