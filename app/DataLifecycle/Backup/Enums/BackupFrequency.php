<?php

namespace PryTax\DataLifecycle\Backup\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum BackupFrequency.
 *
 * How often a policy runs (backups.md §2.2): every day, on a weekday, or on a day of the month (1–28).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Enums
 */
enum BackupFrequency: string implements BaseEnumInterface
{
    use BaseEnum;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
