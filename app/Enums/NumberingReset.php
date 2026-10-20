<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum NumberingReset.
 *
 * When an invoice counter restarts.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum NumberingReset: string implements BaseEnumInterface
{
    use BaseEnum;

    case Never = 'never';
    case Yearly = 'yearly';
}
