<?php

namespace PryTax\Services\Taxes;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\EsvObligation;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPayment;

/**
 * Class TaxReconciler.
 *
 * Compares what each quarter owes of a tax (the year-to-date delta of ЄП and ВЗ, the due months of ЄСВ) with the
 * tax payments mapped to it in any allocation mode (design.md §8.2). Paid ones count; sent ones are pending, shown
 * but not counted; failed ones are ignored. The running balance starts from the tax's opening balance and carries
 * across quarters and years: positive is overpaid, negative is outstanding.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class TaxReconciler
{
    /**
     * Liabilities of the quarters, which were computed, by profile, year and quarter.
     *
     * @var array<string, array>
     */
    protected array $liabilities = [];

    /**
     * TaxReconciler constructor.
     *
     * @param TaxLiabilityCalculator $calculator
     * @param DeadlineScheduler $deadlines
     * @param EsvObligationScheduler $esv
     */
    public function __construct(
        protected TaxLiabilityCalculator $calculator,
        protected DeadlineScheduler $deadlines,
        protected EsvObligationScheduler $esv
    ) {}

    /**
     * Get the reconciliation of the tax in the profile's year: each quarter's owed, paid and pending amounts with
     * the running balance at its end, and the balance today (at the current quarter, for the current year).
     *
     * @param Profile $profile
     * @param TaxType $type
     * @param int $year
     * @param CarbonInterface|null $today
     *
     * @return array{
     *     tax_type: string,
     *     year: int,
     *     opening_balance: array{as_of: string, amount: string}|null,
     *     balance: string,
     *     quarters: array<int, array<string, mixed>>,
     * }
     */
    public function forYear(Profile $profile, TaxType $type, int $year, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        [$startYear, $startQuarter, $opening] = $this->start($profile, $type, $year);

        $balance = Decimal::of($opening?->amount);
        $quarters = [];

        for ($y = $startYear; $y <= $year; $y++) {
            if ($type === TaxType::SocialContribution) {
                $this->esv->ensureYear($profile, $y);
            }

            for ($q = $y === $startYear ? $startQuarter : 1; $q <= 4; $q++) {
                $owed = $this->owed($profile, $type, $y, $q);
                $sums = $this->paymentSums($profile, $type, $y, $q);
                $balance = $balance->plus($sums['paid'])->minus($owed);

                if ($y === $year) {
                    $quarters[$q] = $this->quarter($profile, $type, $y, $q, $owed, $sums, $balance, $today);
                }
            }
        }

        $current = $year === $today->year ? $today->quarter : 4;

        return [
            'tax_type' => $type->value,
            'year' => $year,
            'opening_balance' => $opening ? [
                'as_of' => $opening->as_of->toDateString(),
                'amount' => Decimal::money($opening->amount),
            ] : null,
            'balance' => $quarters[$current]['balance'] ?? Decimal::money($balance),
            'quarters' => array_values($quarters),
        ];
    }

    /**
     * Get what the quarter owes of the tax.
     *
     * @param Profile $profile
     * @param TaxType $type
     * @param int $year
     * @param int $quarter
     *
     * @return BigDecimal
     */
    public function owed(Profile $profile, TaxType $type, int $year, int $quarter): BigDecimal
    {
        $liability = $this->liabilities["{$profile->getKey()}:$year:$quarter"]
            ??= $this->calculator->forQuarter($profile, $year, $quarter);

        return Decimal::of(match ($type) {
            TaxType::SingleTax => $liability['single_tax']['owed'],
            TaxType::MilitaryLevy => $liability['military_levy']['owed'],
            TaxType::SocialContribution => $liability['esv']['owed'],
        });
    }

    /**
     * Get the sums of the tax payments mapped to the quarter: paid, pending (sent) and failed ones.
     *
     * @param Profile $profile
     * @param TaxType $type
     * @param int $year
     * @param int $quarter
     *
     * @return array{paid: BigDecimal, pending: BigDecimal, failed: BigDecimal}
     */
    public function paymentSums(Profile $profile, TaxType $type, int $year, int $quarter): array
    {
        $sums = TaxPayment::query()
            ->where('profile_id', $profile->getKey())
            ->where('tax_type', $type->value)
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->groupBy('status')
            ->selectRaw('status, SUM(amount) as total')
            ->pluck('total', 'status');

        return [
            'paid' => Decimal::of((string) ($sums[TaxPaymentStatus::Paid->value] ?? '0')),
            'pending' => Decimal::of((string) ($sums[TaxPaymentStatus::Sent->value] ?? '0')),
            'failed' => Decimal::of((string) ($sums[TaxPaymentStatus::Failed->value] ?? '0')),
        ];
    }

    /**
     * Get the first quarter of the series and the opening balance it starts from: the quarter of the latest opening
     * balance up to the year's end, or else the first year with records of the tax.
     *
     * @param Profile $profile
     * @param TaxType $type
     * @param int $year
     *
     * @return array{0: int, 1: int, 2: TaxOpeningBalance|null}
     */
    protected function start(Profile $profile, TaxType $type, int $year): array
    {
        $opening = TaxOpeningBalance::query()
            ->where('profile_id', $profile->getKey())
            ->where('tax_type', $type->value)
            ->where('as_of', '<=', "$year-12-31")
            ->orderByDesc('as_of')
            ->first();

        if ($opening) {
            return [$opening->as_of->year, $opening->as_of->quarter, $opening];
        }

        $years = array_filter([
            Payment::query()->where('profile_id', $profile->getKey())->min('period_year'),
            TaxPayment::query()->where('profile_id', $profile->getKey())->where('tax_type', $type->value)->min('period_year'),
            $type === TaxType::SocialContribution
                ? EsvObligation::query()->where('profile_id', $profile->getKey())->min('year')
                : null,
        ]);

        return [min([$year, ...array_map('intval', $years)]), 1, null];
    }

    /**
     * Describe a quarter of the series.
     *
     * @param Profile $profile
     * @param TaxType $type
     * @param int $year
     * @param int $quarter
     * @param BigDecimal $owed
     * @param array{paid: BigDecimal, pending: BigDecimal, failed: BigDecimal} $sums
     * @param BigDecimal $balance
     * @param CarbonImmutable $today
     *
     * @return array<string, mixed>
     */
    protected function quarter(
        Profile $profile,
        TaxType $type,
        int $year,
        int $quarter,
        BigDecimal $owed,
        array $sums,
        BigDecimal $balance,
        CarbonImmutable $today
    ): array {
        $period = $this->deadlines->ensure($profile, $year, $quarter);
        $dueAt = $type === TaxType::SocialContribution ? $period->esv_due_at : $period->tax_due_at;

        $index = $year * 10 + $quarter;
        $todayIndex = $today->year * 10 + $today->quarter;

        $state = match (true) {
            $index > $todayIndex => 'future',
            $index === $todayIndex => 'current',
            $balance->isPositiveOrZero() => 'closed',
            $dueAt->lt($today) => 'overdue',
            default => 'due',
        };

        $pending = TaxPayment::query()
            ->where('profile_id', $profile->getKey())
            ->where('tax_type', $type->value)
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->where('status', TaxPaymentStatus::Sent->value)
            ->orderBy('paid_at')
            ->get(['id', 'amount', 'paid_at', 'allocation', 'payment_id']);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'owed' => Decimal::money($owed),
            'paid' => Decimal::money($sums['paid']),
            'pending' => Decimal::money($sums['pending']),
            'failed' => Decimal::money($sums['failed']),
            'quarter_balance' => Decimal::money($sums['paid']->minus($owed)),
            'balance' => Decimal::money($balance),
            'due_at' => $dueAt->toDateString(),
            'state' => $state,
            'pending_payments' => $pending->map(fn (TaxPayment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toDateString(),
                'allocation' => $payment->allocation->value,
                'payment_id' => $payment->payment_id,
            ])->all(),
        ];
    }
}
