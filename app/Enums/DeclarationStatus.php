<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum DeclarationStatus.
 *
 * Status of a quarter's declaration. Submitted and accepted lock the quarter.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum DeclarationStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case None = 'none';
    case Filled = 'filled';
    case Submitted = 'submitted';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Corrected = 'corrected';

    /**
     * Determine if the status locks the quarter, as a submitted declaration does.
     *
     * @return bool
     */
    public function locks(): bool
    {
        return in_array($this, [self::Submitted, self::Accepted], true);
    }
}
