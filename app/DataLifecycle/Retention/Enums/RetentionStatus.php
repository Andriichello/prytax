<?php

namespace PryTax\DataLifecycle\Retention\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum RetentionStatus.
 *
 * Status of a deletion run or of one of its jobs (backups.md §9.4): a job is `dispatched` once a worker claimed it; a
 * run is derived from its jobs.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Enums
 */
enum RetentionStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
