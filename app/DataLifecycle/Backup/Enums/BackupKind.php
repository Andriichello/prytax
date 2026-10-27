<?php

namespace PryTax\DataLifecycle\Backup\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum BackupKind.
 *
 * Kind of a run, and the tier of its backups (backups.md §2.2, §4.1): scheduled ones are kept by the policy's
 * grandfather-father-son retention, manual ones until deleted (20 at most), automatic (safety) ones 30 days, and
 * exports (a period, or a bundle) a day.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Enums
 */
enum BackupKind: string implements BaseEnumInterface
{
    use BaseEnum;

    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Automatic = 'automatic';
    case Export = 'export';
}
