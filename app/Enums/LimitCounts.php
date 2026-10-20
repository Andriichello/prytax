<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum LimitCounts.
 *
 * What counts towards an account's limit: client receipts only, or every inbound credit.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum LimitCounts: string implements BaseEnumInterface
{
    use BaseEnum;

    case Receipts = 'receipts';
    case AllInbound = 'all_inbound';
}
