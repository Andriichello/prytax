<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum NumberingPrefix.
 *
 * What generated invoice numbers start with: the client's abbreviation (`BL-2026-10-18-13`), or nothing.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum NumberingPrefix: string implements BaseEnumInterface
{
    use BaseEnum;

    case ClientAbbreviation = 'client_abbreviation';
    case None = 'none';
}
