<?php

namespace PryTax\DataLifecycle\Backup\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum BackupNotify.
 *
 * When the owners get an e-mail of a run: when it failed or was partial, after every run, or never.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Enums
 */
enum BackupNotify: string implements BaseEnumInterface
{
    use BaseEnum;

    case Failures = 'failures';
    case Always = 'always';
    case Never = 'never';
}
