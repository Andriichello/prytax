<?php

namespace PryTax\DataLifecycle\Backup\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum RunStatus.
 *
 * Status of a run: partial, when some of its profiles failed and others didn't.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Enums
 */
enum RunStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    /**
     * Determine if the run is over.
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed], true);
    }
}
