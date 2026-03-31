<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo\Tests\Unit;

use MoneybirdSyncForWoo\Models\Task;
use MoneybirdSyncForWoo\TaskQueue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use wpdb;

class TaskQueueTest extends TestCase {
	private wpdb&MockObject $wpdb;
	private TaskQueue $queue;

	protected function setUp(): void {
		$this->wpdb         = $this->createMock( wpdb::class );
		$this->wpdb->prefix = 'wp_';
		$this->queue        = new TaskQueue( $this->wpdb );
	}

	// ── create ──────────────────────────────────────────────────────────────

	public function test_create_inserts_row_and_returns_id(): void {
		$this->wpdb->insert_id = 7;

		$this->wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				'wp_mb_tasks',
				$this->callback( static function ( array $data ): bool {
					return 'sync_order' === $data['type']
						&& Task::STATUS_PENDING === $data['status']
						&& 0 === $data['attempts']
						&& 3 === $data['max_attempts']
						&& isset( $data['payload'] );
				} )
			);

		$id = $this->queue->create( Task::TYPE_SYNC_ORDER, array( 'order_id' => 42 ) );
		$this->assertSame( 7, $id );
	}

	public function test_create_encodes_payload_as_json(): void {
		$this->wpdb->insert_id = 1;
		$captured = null;

		$this->wpdb->method( 'insert' )
			->willReturnCallback( function ( string $table, array $data ) use ( &$captured ): void {
				$captured = $data;
			} );

		$this->queue->create( Task::TYPE_SYNC_ORDER, array( 'order_id' => 99 ) );

		$this->assertIsString( $captured['payload'] );
		$decoded = json_decode( $captured['payload'], true );
		$this->assertSame( 99, $decoded['order_id'] );
	}

	// ── lock ────────────────────────────────────────────────────────────────

	public function test_lock_returns_true_on_single_affected_row(): void {
		$this->wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$this->wpdb->method( 'query' )->willReturn( 1 );

		$this->assertTrue( $this->queue->lock( 5 ) );
	}

	public function test_lock_returns_false_when_already_taken(): void {
		$this->wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$this->wpdb->method( 'query' )->willReturn( 0 );

		$this->assertFalse( $this->queue->lock( 5 ) );
	}

	// ── complete ────────────────────────────────────────────────────────────

	public function test_complete_sets_status_to_completed(): void {
		$this->wpdb->expects( $this->once() )
			->method( 'update' )
			->with(
				'wp_mb_tasks',
				$this->callback( static function ( array $data ): bool {
					return Task::STATUS_COMPLETED === $data['status']
						&& isset( $data['completed_at'] );
				} ),
				array( 'id' => 3 )
			);

		$this->queue->complete( 3 );
	}

	// ── fail / retry ────────────────────────────────────────────────────────

	public function test_fail_increments_attempts(): void {
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query, mixed ...$args ): string {
				return $query; // Return the raw query for assertion.
			}
		);

		$this->wpdb->expects( $this->once() )
			->method( 'query' )
			->with( $this->stringContains( 'attempts + 1' ) );

		$this->queue->fail( 2 );
	}

	public function test_fail_marks_failed_after_max_attempts(): void {
		$this->wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query, mixed ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%d', $query ) ), $args );
			}
		);

		$this->wpdb->expects( $this->once() )
			->method( 'query' )
			->with( $this->stringContains( Task::STATUS_FAILED ) );

		$this->queue->fail( 4 );
	}

	// ── get_pending ─────────────────────────────────────────────────────────

	public function test_get_pending_returns_task_objects(): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = (object) array(
			'id'          => 1,
			'type'        => Task::TYPE_SYNC_ORDER,
			'status'      => Task::STATUS_PENDING,
			'payload'     => '{"order_id":42}',
			'attempts'    => 0,
			'max_attempts' => 3,
			'locked_at'   => null,
			'completed_at' => null,
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$this->wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$this->wpdb->method( 'query' )->willReturn( 0 );    // stale lock release.
		$this->wpdb->method( 'get_results' )->willReturn( array( $row ) );

		$tasks = $this->queue->get_pending( 10 );

		$this->assertCount( 1, $tasks );
		$this->assertInstanceOf( Task::class, $tasks[0] );
		$this->assertSame( 42, $tasks[0]->payload['order_id'] );
	}

	public function test_get_pending_returns_empty_array_when_no_tasks(): void {
		$this->wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$this->wpdb->method( 'query' )->willReturn( 0 );
		$this->wpdb->method( 'get_results' )->willReturn( array() );

		$tasks = $this->queue->get_pending();
		$this->assertSame( array(), $tasks );
	}

	// ── count_by_status ──────────────────────────────────────────────────────

	public function test_count_by_status(): void {
		$this->wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$this->wpdb->method( 'get_var' )->willReturn( '5' );

		$this->assertSame( 5, $this->queue->count_by_status( Task::STATUS_FAILED ) );
	}
}
