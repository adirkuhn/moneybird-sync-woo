<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

class Logger {
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	private \wpdb $db;
	private string $table;

	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'mb_logs';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, ?int $task_id = null, array $context = [] ): void {
		$this->log( self::LEVEL_INFO, $message, $task_id, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, ?int $task_id = null, array $context = [] ): void {
		$this->log( self::LEVEL_WARNING, $message, $task_id, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, ?int $task_id = null, array $context = [] ): void {
		$this->log( self::LEVEL_ERROR, $message, $task_id, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, ?int $task_id, array $context ): void {
		$this->db->insert(
			$this->table,
			array(
				'task_id'    => $task_id,
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @return object[]
	 */
	public function get_logs( ?int $task_id = null, int $limit = 100, int $offset = 0 ): array {
		if ( null !== $task_id ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table} WHERE task_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$task_id,
					$limit,
					$offset
				)
			);
		} else {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				)
			);
		}
		return $rows ?? array();
	}

	public function count_logs( ?int $task_id = null ): int {
		if ( null !== $task_id ) {
			return (int) $this->db->get_var(
				$this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE task_id = %d", $task_id )
			);
		}
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}
}
