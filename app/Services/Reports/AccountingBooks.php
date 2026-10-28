<?php

namespace PryTax\Services\Reports;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxRule;
use PryTax\Services\Taxes\TaxLedger;
use PryTax\Services\Taxes\TaxRuleResolver;

/**
 * Class AccountingBooks.
 *
 * Книги обліку (design.md §16.7): the income book of a year (п. 296.1), each started quarter and month with its total
 * and the running total at its end, and every receipt and refund of a month with the running total after it; and the
 * year's taxes on `TaxLedger`, so they match Податки: what is accrued, sent and paid per tax and quarter, cumulative
 * from I кв. as the declaration counts them, and per month for ЄСВ. Lists run newest first; the running totals are
 * counted from 1 January, in the order the money came.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class AccountingBooks
{
    /**
     * The taxes in the order of the books' rows.
     *
     * @var array<int, TaxType>
     */
    public const TAXES = [TaxType::SocialContribution, TaxType::SingleTax, TaxType::MilitaryLevy];

    /**
     * AccountingBooks constructor.
     *
     * @param TaxLedger $ledger
     * @param TaxRuleResolver $rules
     */
    public function __construct(
        protected TaxLedger $ledger,
        protected TaxRuleResolver $rules
    ) {}

    /**
     * Get the income book of the profile's year, newest first: the started quarters with their started months and the
     * receipts (and refunds) of each, and the income limit.
     *
     * @param Profile $profile
     * @param int $year
     * @param CarbonImmutable|null $today
     *
     * @return array{
     *     year: int,
     *     ytd: string,
     *     has_receipts: bool,
     *     limit: array{cap: string, percent: string, left: string},
     *     quarters: array<int, array<string, mixed>>,
     * }
     */
    public function income(Profile $profile, int $year, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $lastMonth = $this->lastStartedMonth($year, $today);
        $entries = $this->entries($profile, $year);

        $running = Decimal::zero();
        $byMonth = [];

        foreach ($entries as $entry) {
            $running = $running->plus($entry['amount']);
            $byMonth[$entry['month']][] = [...$entry, 'amount_uah' => Decimal::money($entry['amount']), 'ytd_after' => Decimal::money($running)];
        }

        $ytd = Decimal::zero();
        $quarters = [];

        for ($month = 1; $month <= $lastMonth; $month++) {
            $own = $byMonth[$month] ?? [];
            $total = Decimal::sum(array_column($own, 'amount'));
            $ytd = $ytd->plus($total);
            $quarter = intdiv($month - 1, 3) + 1;
            $isCurrent = $year === $today->year && $month === $today->month;

            $quarters[$quarter] ??= ['quarter' => $quarter, 'total' => Decimal::zero(), 'months' => []];
            $quarters[$quarter]['total'] = $quarters[$quarter]['total']->plus($total);
            $quarters[$quarter]['ytd_end'] = Decimal::money($ytd);
            $quarters[$quarter]['months'][] = [
                'month' => $month,
                'total' => Decimal::money($total),
                'ytd_end' => Decimal::money($ytd),
                'count' => count(array_filter($own, fn (array $entry) => $entry['kind'] === 'receipt')),
                'state' => $own !== [] ? 'ok' : ($isCurrent ? 'not_yet' : 'no_income'),
                'current' => $isCurrent,
                'receipts' => array_reverse(array_map(fn (array $entry) => array_diff_key($entry, ['amount' => true, 'month' => true]), $own)),
            ];
        }

        $rule = $this->ruleOf($year, $lastMonth);
        $cap = Decimal::of($rule?->income_cap_uah);

        return [
            'year' => $year,
            'ytd' => Decimal::money($ytd),
            'has_receipts' => $entries->isNotEmpty(),
            'limit' => [
                'cap' => Decimal::money($cap),
                'percent' => Decimal::percent($ytd, $cap),
                'left' => Decimal::money(Decimal::max($cap->minus($ytd), 0)),
            ],
            'quarters' => array_values(array_reverse(array_map(fn (array $quarter) => [
                ...$quarter,
                'total' => Decimal::money($quarter['total']),
                'current' => $year === $today->year && $quarter['quarter'] === $today->quarter,
                'months' => array_reverse($quarter['months']),
            ], $quarters))),
        ];
    }

    /**
     * Get the taxes of the profile's year: the totals, the cumulative periods of the started quarters (I кв., I–II кв.,
     * …, newest first) and each tax with its deadline, its segments (ЄСВ by month, ЄП and ВЗ by quarter) and its
     * quarters.
     *
     * @param Profile $profile
     * @param int $year
     * @param CarbonImmutable|null $today
     *
     * @return array{
     *     year: int,
     *     esv_monthly: string|null,
     *     totals: array{accrued: string, sent: string, paid: string, percent: string},
     *     cumulative: array<int, array<string, mixed>>,
     *     by_tax: array<int, array<string, mixed>>,
     * }
     */
    public function taxes(Profile $profile, int $year, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $lastQuarter = intdiv($this->lastStartedMonth($year, $today) + 2, 3);
        $items = $this->ledger->items($profile, $year, [1, 2, 3, 4], $today)
            ->filter(fn (array $item) => $item['quarter']['year'] === $year && $item['status'] !== TaxPaymentStatus::Failed->value);

        $sums = fn (Collection $own) => [
            'accrued' => Decimal::sum($own->pluck('amount')),
            'sent' => Decimal::sum($own->where('status', TaxPaymentStatus::Sent->value)->pluck('amount')),
            'paid' => Decimal::sum($own->where('status', TaxPaymentStatus::Paid->value)->pluck('amount')),
        ];
        $ofTax = fn (TaxType $type) => $items->where('tax_type', $type->value);
        $ofQuarter = fn (Collection $own, int $quarter) => $own->filter(fn (array $item) => $item['quarter']['quarter'] === $quarter);
        $upTo = fn (Collection $own, int $quarter) => $own->filter(fn (array $item) => $item['quarter']['quarter'] <= $quarter);

        $cumulative = [];

        for ($quarter = $lastQuarter; $quarter >= 1; $quarter--) {
            $rows = array_map(function (TaxType $type) use ($ofTax, $ofQuarter, $upTo, $sums, $quarter) {
                $toDate = $sums($upTo($ofTax($type), $quarter));

                return [
                    'tax_type' => $type->value,
                    'quarter' => Decimal::money($sums($ofQuarter($ofTax($type), $quarter))['accrued']),
                    'cumulative' => Decimal::money($toDate['accrued']),
                    'sent' => Decimal::money($toDate['sent']),
                    'paid' => Decimal::money($toDate['paid']),
                ];
            }, self::TAXES);

            $cumulative[] = [
                'to_quarter' => $quarter,
                'current' => $year === $today->year && $quarter === $today->quarter,
                'total' => Decimal::money(Decimal::sum(array_column($rows, 'cumulative'))),
                'rows' => $rows,
            ];
        }

        $byTax = array_map(function (TaxType $type) use ($ofTax, $ofQuarter, $upTo, $sums, $lastQuarter) {
            $own = $ofTax($type);
            $totals = $sums($own);
            $segments = $type === TaxType::SocialContribution
                ? array_map(fn (int $month) => ['period' => $month, ...$this->segmentOf($sums($own->where('period_month', $month)))], range(1, 12))
                : array_map(fn (int $quarter) => ['period' => $quarter, ...$this->segmentOf($sums($ofQuarter($own, $quarter)))], range(1, 4));
            $due = $own->where('status', '!=', TaxPaymentStatus::Paid->value)->sortBy('due_at')->first();

            return [
                'tax_type' => $type->value,
                'accrued' => Decimal::money($totals['accrued']),
                'sent' => Decimal::money($totals['sent']),
                'paid' => Decimal::money($totals['paid']),
                'due_at' => $due['due_at'] ?? null,
                'due_period' => $due === null ? null : ($type === TaxType::SocialContribution ? $due['period_month'] : $due['quarter']['quarter']),
                'segments' => $segments,
                'quarters' => array_map(fn (int $quarter) => [
                    'quarter' => $quarter,
                    'amount' => Decimal::money($sums($ofQuarter($own, $quarter))['accrued']),
                    'cumulative' => Decimal::money($sums($upTo($own, $quarter))['accrued']),
                ], $lastQuarter > 0 ? range($lastQuarter, 1) : []),
            ];
        }, self::TAXES);

        $totals = $sums($items);

        return [
            'year' => $year,
            'esv_monthly' => $this->ruleOf($year, max($this->lastStartedMonth($year, $today), 1))?->esv_monthly,
            'totals' => [
                'accrued' => Decimal::money($totals['accrued']),
                'sent' => Decimal::money($totals['sent']),
                'paid' => Decimal::money($totals['paid']),
                'percent' => Decimal::percent($totals['paid'], $totals['accrued']),
            ],
            'cumulative' => $cumulative,
            'by_tax' => $byTax,
        ];
    }

    /**
     * Get the receipts and refunds of the year in the order the money came: a receipt on its credit date, a refund (a
     * negative amount) on its own date, each with its receipt, client and invoice.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return Collection<int, array{kind: string, id: int, payment_id: int, month: int, credited_on: string, client: string|null, invoice_number: string|null, virtual: bool, amount: BigDecimal}>
     */
    protected function entries(Profile $profile, int $year): Collection
    {
        $receipts = Payment::query()
            ->where('profile_id', $profile->getKey())
            ->where('period_year', $year)
            ->with('invoice.client')
            ->get()
            ->map(fn (Payment $payment) => [
                'kind' => 'receipt',
                'id' => $payment->id,
                'payment_id' => $payment->id,
                'month' => (int) $payment->period_month,
                'credited_on' => $payment->received_at->toDateString(),
                'client' => $payment->invoice?->client?->name,
                'invoice_number' => $payment->invoice?->number,
                'virtual' => $payment->invoice?->source === InvoiceSource::Virtual,
                'amount' => Decimal::of($payment->uah_amount),
            ]);

        $refunds = Refund::query()
            ->where('profile_id', $profile->getKey())
            ->where('period_year', $year)
            ->with('payment.invoice.client')
            ->get()
            ->map(fn (Refund $refund) => [
                'kind' => 'refund',
                'id' => $refund->id,
                'payment_id' => $refund->payment_id,
                'month' => (int) $refund->period_month,
                'credited_on' => $refund->refunded_at->toDateString(),
                'client' => $refund->payment?->invoice?->client?->name,
                'invoice_number' => $refund->payment?->invoice?->number,
                'virtual' => $refund->payment?->invoice?->source === InvoiceSource::Virtual,
                'amount' => Decimal::of($refund->uah_amount)->negated(),
            ]);

        return $receipts->concat($refunds)
            ->sortBy([['credited_on', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Get the last month of the year, which has started: 12 for a past year, none for a year ahead.
     *
     * @param int $year
     * @param CarbonImmutable $today
     *
     * @return int
     */
    protected function lastStartedMonth(int $year, CarbonImmutable $today): int
    {
        return match (true) {
            $year < $today->year => 12,
            $year === $today->year => $today->month,
            default => 0,
        };
    }

    /**
     * Get the tax rule in effect at the end of the month of the year, or the latest one.
     *
     * @param int $year
     * @param int $month
     *
     * @return TaxRule|null
     */
    protected function ruleOf(int $year, int $month): ?TaxRule
    {
        return $this->rules->find(CarbonImmutable::create($year, max($month, 1))->endOfMonth())
            ?? TaxRule::query()->orderByDesc('effective_from')->first();
    }

    /**
     * Get a segment of a tax's bar: its sums and its state, which is paid once its payments cover what is accrued,
     * sent once sent and paid ones do, and unpaid otherwise (or none, when nothing is accrued).
     *
     * @param array{accrued: BigDecimal, sent: BigDecimal, paid: BigDecimal} $sums
     *
     * @return array{accrued: string, sent: string, paid: string, state: string}
     */
    protected function segmentOf(array $sums): array
    {
        $state = match (true) {
            $sums['accrued']->isZero() => 'none',
            $sums['paid']->isGreaterThanOrEqualTo($sums['accrued']) => 'paid',
            $sums['paid']->plus($sums['sent'])->isGreaterThanOrEqualTo($sums['accrued']) => 'sent',
            default => 'unpaid',
        };

        return [
            'accrued' => Decimal::money($sums['accrued']),
            'sent' => Decimal::money($sums['sent']),
            'paid' => Decimal::money($sums['paid']),
            'state' => $state,
        ];
    }
}
