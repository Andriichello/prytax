<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum NumberingScope.
 *
 * Scope of an invoice counter: one per client, or one per profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum NumberingScope: string implements BaseEnumInterface
{
    use BaseEnum;

    case PerClient = 'per_client';
    case Global = 'global';
}
