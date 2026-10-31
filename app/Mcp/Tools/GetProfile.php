<?php

namespace PryTax\Mcp\Tools;

use Carbon\CarbonImmutable;
use Core\Models\User;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Services\Reports\ProfileStatus;
use PryTax\Services\Taxes\TaxLiabilityCalculator;

/**
 * Class GetProfile.
 *
 * One ФОП: its details (the tax ID masked), its settings, this year's income against the 3rd group's cap, and what
 * needs doing.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get one ФОП: details (tax ID masked), settings, this year\'s income against the 3rd group\'s income cap (UAH, percent used), and its status: taxes to pay (taxes_to_pay: this year\'s unpaid accruals, before opening balances such as an overpayment carried in; get-declaration\'s remaining.<tax>.to_pay is net of them), overdue invoices, the next ЄСВ due date.')]
class GetProfile extends ReadTool
{
    /**
     * Read the ФОП.
     *
     * @param array<string, mixed> $arguments
     * @param User $user
     * @param Integration $integration
     * @param Profile|null $profile
     *
     * @return array<string, mixed>
     */
    protected function read(array $arguments, User $user, Integration $integration, ?Profile $profile): array
    {
        $today = CarbonImmutable::now();

        return [
            'profile' => [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'full_name_en' => $profile->full_name_en,
                'short_name' => $profile->short_name,
                'is_active' => $profile->is_active,
                'registered_at' => $profile->registered_at?->toDateString(),
                'closed_at' => $profile->closed_at?->toDateString(),
                'tax_group' => $profile->tax_group,
                'tax_id_masked' => $profile->tax_id_masked,
                'address' => $profile->address,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'default_currency' => $profile->default_currency,
            ],
            'settings' => $profile->settingValues(),
            'income_cap' => app(TaxLiabilityCalculator::class)->forQuarter($profile, $today->year, $today->quarter)['cap'],
            'status' => app(ProfileStatus::class)->forProfile($profile),
        ];
    }
}
