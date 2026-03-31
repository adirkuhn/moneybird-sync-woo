<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo\Models;

class Task
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED = 'failed';

	public const TYPE_SYNC_ORDER = 'sync_order';
	public const TYPE_SYNC_FEE = 'sync_fee';
	public const TYPE_SYNC_PAYOUT = 'sync_payout';

	public int $id;
	public string $type;
	public string $status;
	/** @var array<string, mixed> */
	public array $payload;
	public int $attempts;
	public int $max_attempts;
	public ?string $locked_at;
	public ?string $completed_at;
	public string $created_at;
	public string $updated_at;

	public static function from_row(object $row): self
	{
		$task = new self();
		$task->id = (int) $row->id;
		$task->type = (string) $row->type;
		$task->status = (string) $row->status;
		$task->payload = (array) json_decode((string) $row->payload, true);
		$task->attempts = (int) $row->attempts;
		$task->max_attempts = (int) $row->max_attempts;
		$task->locked_at = isset($row->locked_at) ? (string) $row->locked_at : null;
		$task->completed_at = isset($row->completed_at) ? (string) $row->completed_at : null;
		$task->created_at = (string) $row->created_at;
		$task->updated_at = (string) $row->updated_at;
		return $task;
	}
}
