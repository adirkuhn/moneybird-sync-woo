<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;
use WC_Order;

/**
 * Hooks into WooCommerce order lifecycle events and enqueues sync_order tasks.
 *
 * Idempotency: _mb_sync_queued meta prevents enqueueing the same order twice.
 */
class OrderListener {
	private TaskQueue $queue;
	private Logger $logger;

	public function __construct( TaskQueue $queue, Logger $logger ) {
		$this->queue  = $queue;
		$this->logger = $logger;
	}

	public function register_hooks(): void {
		// Fires when a Stripe payment is captured.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ) );
		// Fires on manual status changes to 'processing'.
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_payment_complete' ) );
		// Fires when an order is manually completed (e.g. virtual products).
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_payment_complete' ) );
	}

	public function on_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'stripe' !== $order->get_payment_method() ) {
			return;
		}

		// Prevent duplicate tasks for the same order.
		if ( $order->get_meta( '_mb_sync_queued', true ) ) {
			return;
		}

		$task_id = $this->queue->create(
			Task::TYPE_SYNC_ORDER,
			array( 'order_id' => $order_id )
		);

		$order->update_meta_data( '_mb_sync_queued', '1' );
		$order->save_meta_data();

		$this->logger->info( "Queued sync_order task #{$task_id} for order {$order_id}." );
	}
}
