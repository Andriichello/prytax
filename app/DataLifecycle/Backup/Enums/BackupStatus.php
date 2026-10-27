<?php

namespace PryTax\DataLifecycle\Backup\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum BackupStatus.
 *
 * Outcome of a profile in a run: an archive, none since its data didn't change, a failure, or an archive, which
 * retention has deleted.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Enums
 */
enum BackupStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case Completed = 'completed';
    case SkippedUnchanged = 'skipped_unchanged';
    case Failed = 'failed';
    case Pruned = 'pruned';
}
