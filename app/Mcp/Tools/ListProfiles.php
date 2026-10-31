<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Services\Reports\ProfileStatus;

/**
 * Class ListProfiles.
 *
 * The organization's ФОП, which Claude may see, the active first, each with what needs doing: taxes to pay, overdue
 * invoices, the next ЄСВ due date.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('List the ФОП (sole proprietors) Claude may see in PryTax, the active first, each with its status: taxes to pay (taxes_to_pay, UAH: this year\'s unpaid accruals of ЄП, ВЗ and ЄСВ, before opening balances such as an overpayment carried in; get-declaration\'s remaining.<tax>.to_pay is net of them), overdue invoices, the next ЄСВ due date. Their ids and names name a ФОП in the other tools.')]
class ListProfiles extends ReadTool
{
    /**
     * Whether the tool reads one ФОП.
     *
     * @var bool
     */
    protected bool $ofProfile = false;

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
        return [
            'profiles' => $this->allowedProfiles($user, $integration)->map(fn (Profile $profile) => [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'short_name' => $profile->short_name,
                'is_active' => $profile->is_active,
                'closed_at' => $profile->closed_at?->toDateString(),
                'tax_id_masked' => $profile->tax_id_masked,
                'default_currency' => $profile->default_currency,
                'status' => CurrentProfile::run($profile, fn () => app(ProfileStatus::class)->forProfile($profile)),
            ])->all(),
        ];
    }
}
