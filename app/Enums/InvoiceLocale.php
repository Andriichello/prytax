<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum InvoiceLocale.
 *
 * Language of an invoice's PDF: its labels, dates and the built-in texts of the payment purpose and the act note
 * (design.md §4.1). A string column, rather than a database enum, so a bilingual one needs no migration.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum InvoiceLocale: string implements BaseEnumInterface
{
    use BaseEnum;

    case En = 'en';
    case Uk = 'uk';
}
