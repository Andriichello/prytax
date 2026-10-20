<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum NumberingStrategy.
 *
 * Format of generated invoice numbers: `{YYYY-MM-DD}-{n}` or `{n}`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum NumberingStrategy: string implements BaseEnumInterface
{
    use BaseEnum;

    case DateSequence = 'date_sequence';
    case Sequence = 'sequence';
}
