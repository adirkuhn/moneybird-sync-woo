<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

use MoneybirdSyncForWoo\Models\Task;

class TaskQueue {
	public const MAX_ATTEMPTS  = 3;
	public const LOCK_TIMEOUT  = 300; // seconds before a stale lock is released.

	private \wpdb $db;
	private string $table;

	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'mb_tasks';
	}

	/**
	 * Persist a new task and return its ID.
	 *
	 * @param array<string, mixed> $payload Self-contained data the worker needs.
	 */
	public function create( string $type, array $payload ): int {
		$now = current_time( 'mysql', true );
		$this->db->insert(
			$this->table,
			array(
				'type'         => $type,
				'status'       => Task::STATUS_PENDING,
				'payload'      => wp_json_encode( $payload ),
				'attempts'     => 0,
				'max_attempts' => self::MAX_ATTEMPTS,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $this->db->insert_id;
	}

	/**
	 * Return pending tasks, releasing any stale locks first.
	 *
	 * @return Task[]
	 */
	public function get_pending( int $limit = 10 ): array {
		$this->release_stale_locks();

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
                 WHERE status = %s
                   AND attempts < max_attempts
                 ORDER BY created_at ASC
                 LIMIT %d",
				Task::STATUS_PENDING,
				$limit
			)
		);

		return array_map( array( Task::class, 'from_row' ), $rows ?? array() );
	}

	/**
	 * Atomically lock a task for processing. Returns false if already taken.
	 */
	public function lock( int $id ): bool {
		$now      = current_time( 'mysql', true );
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
                 SET status = %s, locked_at = %s, updated_at = %s
                 WHERE id = %d AND status = %s",
				Task::STATUS_PROCESSING,
				$now,
				$now,
				$id,
				Task::STATUS_PENDING
			)
		);
		return 1 === $affected;
	}

	public function complete( int $id ): void {
		$now = current_time( 'mysql', true );
		$this->db->update(
			$this->table,
			array(
				'status'       => Task::STATUS_COMPLETED,
				'locked_at'    => null,
				'completed_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment attempts; mark failed if max_attempts exhausted, otherwise re-queue.
	 */
	public function fail( int $id ): void {
		$now = current_time( 'mysql', true );
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
                 SET attempts    = attempts + 1,
                     status      = CASE WHEN attempts + 1 >= max_attempts THEN %s ELSE %s END,
                     locked_at   = NULL,
                     updated_at  = %s
                 WHERE id = %d",
				Task::STATUS_FAILED,
				Task::STATUS_PENDING,
				$now,
				$id
			)
		);
	}

	public function get( int $id ): ?Task {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
		);
		return $row ? Task::from_row( $row ) : null;
	}

	public function count_by_status( string $status ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", $status )
		);
	}

	/**
	 * @return Task[]
	 */
	public function get_all( int $limit = 50, int $offset = 0 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
		return array_map( array( Task::class, 'from_row' ), $rows ?? array() );
	}

	/**
	 * Reset tasks stuck in 'processing' for longer than LOCK_TIMEOUT seconds.
	 */
	private function release_stale_locks(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT );
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
                 SET status = %s, locked_at = NULL, updated_at = %s
                 WHERE status = %s AND locked_at < %s",
				Task::STATUS_PENDING,
				current_time( 'mysql', true ),
				Task::STATUS_PROCESSING,
				$cutoff
			)
		);
	}
}
