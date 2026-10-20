<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum EsvStatus.
 *
 * Status of a month of ЄСВ: due, or exempt (the voluntary window, territorial exemptions).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum EsvStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case Due = 'due';
    case Exempt = 'exempt';
}
