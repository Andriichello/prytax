<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use Core\Models\User;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\CurrentProfile;
use PryTax\Helpers\Decimal;
use PryTax\Models\Alert;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxReconciler;

/**
 * Class PracticeOverview.
 *
 * Огляд практики (design.md §16): one quarter of every profile of the organization, which was active in it (an
 * inactive one, closed during or after the quarter, too; every inactive one on request), each with its yearly cap
 * progress, the quarter's income as invoiced versus received per currency over the invoices issued in it (not drafts
 * or cancelled ones), the taxable income of the quarter, its ЄСВ, ЄП and ВЗ owed, paid, pending, outstanding and
 * the running balance, and the user's unread notifications.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class PracticeOverview
{
    /**
     * PracticeOverview constructor.
     *
     * @param TaxLiabilityCalculator $liabilities
     * @param TaxReconciler $reconciler
     * @param EsvObligationScheduler $esv
     */
    public function __construct(
        protected TaxLiabilityCalculator $liabilities,
        protected TaxReconciler $reconciler,
        protected EsvObligationScheduler $esv
    ) {}

    /**
     * Get the overview of the quarter for the user.
     *
     * @param User $user
     * @param int $year
     * @param int $quarter
     * @param bool $withInactive Whether every inactive profile gets its card, as "Показати неактивні" asks.
     *
     * @return array{year: int, quarter: int, profiles: array<int, array<string, mixed>>, inactive_count: int}
     */
    public function forQuarter(User $user, int $year, int $quarter, bool $withInactive = false): array
    {
        $start = CarbonImmutable::create($year, $quarter * 3 - 2);
        $profiles = Profile::query()->index($user)->orderBy('full_name')->get();

        [$shown, $hidden] = $profiles->partition(fn (Profile $profile) => $withInactive
            || $profile->is_active
            || ($profile->closed_at !== null && $profile->closed_at->gte($start)));

        return [
            'year' => $year,
            'quarter' => $quarter,
            'profiles' => $shown
                ->map(fn (Profile $profile) => CurrentProfile::run($profile, fn () => $this->profile($user, $profile, $year, $quarter)))
                ->values()
                ->all(),
            'inactive_count' => $hidden->count(),
        ];
    }

    /**
     * Get the card of a profile.
     *
     * @param User $user
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return array<string, mixed>
     */
    protected function profile(User $user, Profile $profile, int $year, int $quarter): array
    {
        $this->esv->ensureYear($profile, $year);

        $liability = $this->liabilities->forQuarter($profile, $year, $quarter);
        $start = sprintf('%d-%02d-01', $year, ($quarter - 1) * 3 + 1);
        $end = date('Y-m-t', strtotime("$start +2 months"));

        $income = Invoice::query()
            ->withPaidAmount()
            ->whereBetween('issued_at', [$start, $end])
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->get()
            ->groupBy('currency')
            ->map(function ($invoices, string $currency) {
                $invoiced = Decimal::sum($invoices->pluck('total'));
                $received = Decimal::sum($invoices->pluck('paid_amount'));

                return [
                    'currency' => $currency,
                    'invoiced' => Decimal::money($invoiced),
                    'received' => Decimal::money($received),
                    'percent' => Decimal::percent($received, $invoiced, 0),
                ];
            })
            ->sortKeys()
            ->values();

        $taxes = [];

        foreach (TaxType::cases() as $type) {
            $sums = $this->reconciler->paymentSums($profile, $type, $year, $quarter);
            $owed = $this->reconciler->owed($profile, $type, $year, $quarter);
            $series = collect($this->reconciler->forYear($profile, $type, $year)['quarters'])->firstWhere('quarter', $quarter);

            $taxes[$type->value] = [
                'owed' => Decimal::money($owed),
                'paid' => Decimal::money($sums['paid']),
                'pending' => Decimal::money($sums['pending']),
                'outstanding' => Decimal::money(Decimal::max($owed->minus($sums['paid']), 0)),
                // Running, with the opening balance and the earlier quarters
                'balance' => $series['balance'] ?? null,
            ];
        }

        return [
            'profile' => [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'short_name' => $profile->short_name,
                'initials' => $profile->initials,
                'is_active' => $profile->is_active,
                'closed_at' => $profile->closed_at?->toDateString(),
            ],
            'cap' => $liability['cap'],
            'income' => $income->all(),
            'taxable_income' => $liability['quarter_income'],
            'taxes' => $taxes,
            'unread_alerts' => Alert::query()->unresolved()->unreadBy($user)->count(),
        ];
    }
}
