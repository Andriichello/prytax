<?php

namespace PryTax\Services\Payments;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Models\Profile;

/**
 * Class RepatriationRule.
 *
 * By when money of a platform receipt should reach a Ukrainian ФОП account (design.md §6.7), by the profile's
 * `compliance.platform_repatriation`: the end of the receipt's quarter, N days after it, or never. It only informs.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Payments
 */
class RepatriationRule
{
    /**
     * Get the deadline of a receipt of the date, or null, when the rule is off.
     *
     * @param Profile $profile
     * @param CarbonInterface $receivedAt
     *
     * @return CarbonImmutable|null
     */
    public function deadline(Profile $profile, CarbonInterface $receivedAt): ?CarbonImmutable
    {
        $rule = (string) $profile->setting('compliance.platform_repatriation');
        $date = CarbonImmutable::parse($receivedAt->toDateString());

        if ($rule === 'quarter_end') {
            return $date->endOfQuarter()->startOfDay();
        }

        if (preg_match('/^days:(\d+)$/', $rule, $match)) {
            return $date->addDays((int) $match[1]);
        }

        return null;
    }
}
