<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;

/**
 * WP-Cron worker that processes the task queue every minute.
 *
 * Task dispatch:
 *   sync_order  → SyncService
 *   sync_fee    → FeeService
 *   sync_payout → PayoutService
 *
 * Each task is atomically locked before processing. On failure the task is
 * re-queued until max_attempts is reached, after which it is marked 'failed'.
 */
class Worker {
	public const CRON_HOOK     = 'mbsfw_process_tasks';
	public const CRON_INTERVAL = 'mbsfw_every_minute';

	private TaskQueue $queue;
	private SyncService $sync_service;
	private FeeService $fee_service;
	private PayoutService $payout_service;
	private Logger $logger;

	public function __construct(
		TaskQueue $queue,
		SyncService $sync_service,
		FeeService $fee_service,
		PayoutService $payout_service,
		Logger $logger
	) {
		$this->queue          = $queue;
		$this->sync_service   = $sync_service;
		$this->fee_service    = $fee_service;
		$this->payout_service = $payout_service;
		$this->logger         = $logger;
	}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules
	 * @return array<string, array<string, int|string>>
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'moneybird-sync-for-woo' ),
		);
		return $schedules;
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public function run(): void {
		$tasks = $this->queue->get_pending( 10 );
		foreach ( $tasks as $task ) {
			$this->process_task( $task );
		}
	}

	private function process_task( Task $task ): void {
		if ( ! $this->queue->lock( $task->id ) ) {
			$this->logger->warning( "Could not lock task #{$task->id}. Skipping.", $task->id );
			return;
		}

		$this->logger->info( "Processing task #{$task->id} (type: {$task->type}, attempt: " . ( $task->attempts + 1 ) . ").", $task->id );

		try {
			match ( $task->type ) {
				Task::TYPE_SYNC_ORDER  => $this->sync_service->process( $task ),
				Task::TYPE_SYNC_FEE   => $this->fee_service->process( $task ),
				Task::TYPE_SYNC_PAYOUT => $this->payout_service->process( $task ),
				default                => throw new \UnexpectedValueException( "Unknown task type: {$task->type}" ),
			};

			$this->queue->complete( $task->id );
			$this->logger->info( "Task #{$task->id} completed.", $task->id );
		} catch ( \Throwable $e ) {
			$this->queue->fail( $task->id );
			$this->logger->error(
				"Task #{$task->id} failed: " . $e->getMessage(),
				$task->id,
				array(
					'exception' => get_class( $e ),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
		}
	}
}
