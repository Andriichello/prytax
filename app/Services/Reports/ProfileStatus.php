<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\CurrentProfile;
use PryTax\Helpers\Decimal;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\TaxLedger;

/**
 * Class ProfileStatus.
 *
 * The status line of a ФОП on «ФОП організації» (design.md §16.5): the taxes it still has to pay, its overdue
 * invoices and the next deadline of its ЄСВ. The taxes are the ledger's unpaid obligations (`TaxLedger`, so they
 * match Податки) of the current year, and of the previous year's IV кв. until its deadlines in the first quarter.
 * The unread notifications come from the profile's `unread_alerts_count`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class ProfileStatus
{
    /**
     * ProfileStatus constructor.
     *
     * @param TaxLedger $ledger
     */
    public function __construct(
        protected TaxLedger $ledger
    ) {}

    /**
     * Get the status of the profile, computed in its own context.
     *
     * @param Profile $profile
     * @param CarbonImmutable|null $today
     *
     * @return array{taxes_to_pay: string, overdue_invoices: int, esv_due_at: string|null}
     */
    public function forProfile(Profile $profile, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return CurrentProfile::run($profile, fn () => $this->compute($profile, $today));
    }

    /**
     * Compute the status, the profile being the current one.
     *
     * @param Profile $profile
     * @param CarbonImmutable $today
     *
     * @return array{taxes_to_pay: string, overdue_invoices: int, esv_due_at: string|null}
     */
    protected function compute(Profile $profile, CarbonImmutable $today): array
    {
        $periods = [[$today->year, []]];

        if ($today->quarter === 1) {
            $periods[] = [$today->year - 1, [4]];
        }

        $toPay = Decimal::zero();
        $esvDueAt = null;

        foreach ($periods as [$year, $quarters]) {
            $ledger = $this->ledger->forPeriod($profile, $year, $quarters, [], [TaxLedger::UNPAID], $today);

            foreach ($ledger['summary']['rows'] as $row) {
                $toPay = $toPay->plus(Decimal::of($row['accrued']));

                if ($row['tax_type'] === TaxType::SocialContribution->value && $row['due_at'] !== null && Decimal::of($row['accrued'])->isPositive()) {
                    $esvDueAt = $esvDueAt === null || $row['due_at'] < $esvDueAt ? $row['due_at'] : $esvDueAt;
                }
            }
        }

        $overdue = Invoice::query()
            ->where('profile_id', $profile->getKey())
            ->whereIn('status', [InvoiceStatus::Ready->value, InvoiceStatus::Sent->value])
            ->where('source', '!=', InvoiceSource::Virtual->value)
            ->whereDate('due_at', '<', $today->toDateString())
            ->count();

        return [
            'taxes_to_pay' => Decimal::money($toPay),
            'overdue_invoices' => $overdue,
            'esv_due_at' => $esvDueAt,
        ];
    }
}
