<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum TaxType.
 *
 * Tax of 3 група: ЄП, ВЗ or ЄСВ.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum TaxType: string implements BaseEnumInterface
{
    use BaseEnum;

    case SingleTax = 'single_tax';
    case MilitaryLevy = 'military_levy';
    case SocialContribution = 'social_contribution';
}
