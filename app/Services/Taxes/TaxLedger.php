<?php

namespace PryTax\Services\Taxes;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PryTax\Enums\EsvStatus;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;

/**
 * Class TaxLedger.
 *
 * Податки as one ledger (design.md §8.5): the obligations, which are computed and never stored, merged with the tax
 * payments. A receipt owes its ЄП and ВЗ (its hints) while it has no payment of that tax, which didn't fail; a due
 * month of ЄСВ owes what its payments, which didn't fail, leave of the minimum, for every month of a quarter, which
 * has started. `unpaid` is the status of an obligation; `failed`, `sent` and `paid` are the payments' own.
 *
 * Each item belongs to the quarter of its tax (a receipt's, or ЄСВ's month's), by which the summary counts it, and is
 * listed in a month: an obligation in the receipt's month or ЄСВ's own month, a payment in the month of its date (or
 * of its tax, when it has none). The summary sums what is accrued (all but the failed payments), sent and paid per
 * tax, and the list groups the items by month, newest first, the unpaid ones on top, then failed, sent and paid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class TaxLedger
{
    /**
     * Status of an obligation, which nothing paid yet.
     *
     * @var string
     */
    public const UNPAID = 'unpaid';

    /**
     * Statuses of the ledger's items, in the order of a month's list.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['unpaid', 'failed', 'sent', 'paid'];

    /**
     * Order of the taxes inside a receipt's pair and among equal items.
     *
     * @var array<string, int>
     */
    protected const TAX_ORDER = ['single_tax' => 0, 'military_levy' => 1, 'social_contribution' => 2];

    /**
     * Deadlines of the quarters, which were looked up, by year and quarter.
     *
     * @var array<string, array{tax: string, esv: string}>
     */
    protected array $deadlines = [];

    /**
     * TaxLedger constructor.
     *
     * @param DeadlineScheduler $scheduler
     * @param EsvObligationScheduler $esv
     */
    public function __construct(
        protected DeadlineScheduler $scheduler,
        protected EsvObligationScheduler $esv
    ) {}

    /**
     * Get the ledger of the profile's period: the summary of the items, which belong to the period's quarters, and
     * the months of the period with the items listed in them, both narrowed to the taxes and the statuses.
     *
     * @param Profile $profile
     * @param int $year
     * @param array<int, int> $quarters The period's quarters; none is the whole year.
     * @param array<int, TaxType> $types The taxes; none is all of them.
     * @param array<int, string> $statuses The statuses; none is all of them.
     * @param CarbonInterface|null $today
     *
     * @return array{
     *     year: int,
     *     quarters: array<int, int>,
     *     summary: array{total: array{accrued: string, sent: string, paid: string}, rows: array<int, array<string, mixed>>},
     *     months: array<int, array<string, mixed>>,
     * }
     */
    public function forPeriod(
        Profile $profile,
        int $year,
        array $quarters = [],
        array $types = [],
        array $statuses = [],
        ?CarbonInterface $today = null
    ): array {
        $quarters = $quarters === [] ? [1, 2, 3, 4] : array_values(array_unique($quarters));
        sort($quarters);
        $types = $types === [] ? TaxType::cases() : $types;
        $typeValues = array_map(fn (TaxType $type) => $type->value, $types);
        $statuses = $statuses === [] ? self::STATUSES : $statuses;

        $items = $this->items($profile, $year, $quarters, $today)
            ->filter(fn (array $item) => in_array($item['tax_type'], $typeValues, true) && in_array($item['status'], $statuses, true));

        $inPeriod = fn (int $itemYear, int $quarter) => $itemYear === $year && in_array($quarter, $quarters, true);

        return [
            'year' => $year,
            'quarters' => $quarters,
            'summary' => $this->summary(
                $items->filter(fn (array $item) => $inPeriod($item['quarter']['year'], $item['quarter']['quarter'])),
                $types
            ),
            'months' => $this->months(
                $items->filter(fn (array $item) => $inPeriod((int) substr($item['month'], 0, 4), intdiv((int) substr($item['month'], 5, 2) - 1, 3) + 1))
            ),
        ];
    }

    /**
     * Get every item of the period's quarters, each with its deadline: the obligations, which nothing paid yet, and the
     * payments of every status. Книги обліку sum them by quarter and by month (design.md §16.7).
     *
     * @param Profile $profile
     * @param int $year
     * @param array<int, int> $quarters The period's quarters; none is the whole year.
     * @param CarbonInterface|null $today
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(Profile $profile, int $year, array $quarters = [], ?CarbonInterface $today = null): Collection
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $quarters = $quarters === [] ? [1, 2, 3, 4] : $quarters;

        return collect([
            ...$this->receiptObligations($profile, $year, $quarters),
            ...$this->esvObligations($profile, $year, $quarters, $today),
            ...$this->payments($profile, $year, $quarters),
        ])->map(fn (array $item) => [...$item, 'due_at' => $this->dueAt($profile, $item)]);
    }

    /**
     * Get the ЄП and ВЗ, which the receipts of the period's quarters owe and no payment, which didn't fail, pays.
     *
     * @param Profile $profile
     * @param int $year
     * @param array<int, int> $quarters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function receiptObligations(Profile $profile, int $year, array $quarters): array
    {
        $items = [];

        $receipts = Payment::query()
            ->where('profile_id', $profile->getKey())
            ->where('period_year', $year)
            ->whereIn('period_quarter', $quarters)
            ->with([
                'invoice.client',
                'taxPayments' => fn ($query) => $query->where('status', '!=', TaxPaymentStatus::Failed->value),
            ])
            ->get();

        foreach ($receipts as $receipt) {
            foreach ([TaxType::SingleTax, TaxType::MilitaryLevy] as $type) {
                $amount = Decimal::of($type === TaxType::SingleTax ? $receipt->single_tax_uah : $receipt->military_levy_uah);

                if ($amount->isZero() || $receipt->taxPayments->contains(fn (TaxPayment $payment) => $payment->tax_type === $type)) {
                    continue;
                }

                $items[] = [
                    'kind' => 'obligation',
                    'tax_type' => $type->value,
                    'amount' => Decimal::money($amount),
                    'status' => self::UNPAID,
                    'tax_payment_id' => null,
                    'receipt' => $this->receiptOf($receipt),
                    'period_month' => null,
                    'paid_at' => null,
                    'account' => null,
                    'quarter' => ['year' => $receipt->period_year, 'quarter' => $receipt->period_quarter],
                    'month' => $receipt->received_at->format('Y-m'),
                ];
            }
        }

        return $items;
    }

    /**
     * Get what the due months of ЄСВ of the period's started quarters still owe after their payments, which didn't
     * fail; a quarter, once started, owes all of its months, the ones ahead included.
     *
     * @param Profile $profile
     * @param int $year
     * @param array<int, int> $quarters
     * @param CarbonImmutable $today
     *
     * @return array<int, array<string, mixed>>
     */
    protected function esvObligations(Profile $profile, int $year, array $quarters, CarbonImmutable $today): array
    {
        $started = array_filter($quarters, fn (int $quarter) => $year < $today->year || ($year === $today->year && $quarter <= $today->quarter));

        if ($started === []) {
            return [];
        }

        $months = array_merge(...array_map(fn (int $quarter) => range($quarter * 3 - 2, $quarter * 3), $started));

        $paid = TaxPayment::query()
            ->where('profile_id', $profile->getKey())
            ->where('tax_type', TaxType::SocialContribution->value)
            ->where('period_year', $year)
            ->whereIn('period_month', $months)
            ->where('status', '!=', TaxPaymentStatus::Failed->value)
            ->groupBy('period_month')
            ->selectRaw('period_month, SUM(amount) as total')
            ->pluck('total', 'period_month');

        $items = [];

        foreach ($this->esv->ensureYear($profile, $year)->only($months) as $month => $obligation) {
            $left = Decimal::of($obligation->amount_due)->minus(Decimal::of((string) ($paid[$month] ?? '0')));

            if ($obligation->status !== EsvStatus::Due || !$left->isPositive()) {
                continue;
            }

            $items[] = [
                'kind' => 'obligation',
                'tax_type' => TaxType::SocialContribution->value,
                'amount' => Decimal::money($left),
                'status' => self::UNPAID,
                'tax_payment_id' => null,
                'receipt' => null,
                'period_month' => $month,
                'paid_at' => null,
                'account' => null,
                'quarter' => ['year' => $year, 'quarter' => intdiv($month - 1, 3) + 1],
                'month' => sprintf('%d-%02d', $year, $month),
            ];
        }

        return $items;
    }

    /**
     * Get the tax payments, which belong to the period's quarters or were made in them.
     *
     * @param Profile $profile
     * @param int $year
     * @param array<int, int> $quarters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function payments(Profile $profile, int $year, array $quarters): array
    {
        return TaxPayment::query()
            ->where('profile_id', $profile->getKey())
            ->where(function ($query) use ($year, $quarters) {
                $query->where(fn ($query) => $query->where('period_year', $year)->whereIn('period_quarter', $quarters));

                foreach ($quarters as $quarter) {
                    $first = CarbonImmutable::create($year, $quarter * 3 - 2);
                    $query->orWhereBetween('paid_at', [$first->toDateString(), $first->endOfQuarter()->toDateString()]);
                }
            })
            ->with(['payment.invoice.client', 'paidFromAccount'])
            ->get()
            ->map(fn (TaxPayment $payment) => [
                'kind' => 'payment',
                'tax_type' => $payment->tax_type->value,
                'amount' => Decimal::money($payment->amount),
                'status' => $payment->status->value,
                'tax_payment_id' => $payment->id,
                'receipt' => $payment->payment ? $this->receiptOf($payment->payment) : null,
                'period_month' => $payment->period_month,
                'paid_at' => $payment->paid_at?->toDateString(),
                'account' => $payment->paidFromAccount ? [
                    'id' => $payment->paidFromAccount->id,
                    'name' => $payment->paidFromAccount->name,
                    'currency' => $payment->paidFromAccount->currency,
                ] : null,
                'quarter' => ['year' => $payment->period_year, 'quarter' => $payment->period_quarter],
                'month' => $this->monthOf($payment),
            ])
            ->all();
    }

    /**
     * Get the month, in which a payment is listed: the month of its date, or else of its tax: its receipt's, its
     * month of ЄСВ, or the last month of its quarter.
     *
     * @param TaxPayment $payment
     *
     * @return string
     */
    protected function monthOf(TaxPayment $payment): string
    {
        return match (true) {
            $payment->paid_at !== null => $payment->paid_at->format('Y-m'),
            $payment->payment !== null => $payment->payment->received_at->format('Y-m'),
            default => sprintf('%d-%02d', $payment->period_year, $payment->period_month ?? $payment->period_quarter * 3),
        };
    }

    /**
     * Describe a receipt, which an item pays or owes for.
     *
     * @param Payment $receipt
     *
     * @return array<string, mixed>
     */
    protected function receiptOf(Payment $receipt): array
    {
        return [
            'id' => $receipt->id,
            'invoice_id' => $receipt->invoice_id,
            'invoice_number' => $receipt->invoice?->number,
            'invoice_source' => $receipt->invoice?->source?->value,
            'client_name' => $receipt->invoice?->client?->name,
            'description' => $receipt->description,
            'received_at' => $receipt->received_at->toDateString(),
        ];
    }

    /**
     * Get the deadline of an item's tax: its quarter's ЄП and ВЗ deadline, or ЄСВ's, which is due with its quarter.
     *
     * @param Profile $profile
     * @param array<string, mixed> $item
     *
     * @return string
     */
    protected function dueAt(Profile $profile, array $item): string
    {
        ['year' => $year, 'quarter' => $quarter] = $item['quarter'];

        $deadlines = $this->deadlines["{$profile->getKey()}:$year:$quarter"] ??= (function () use ($profile, $year, $quarter) {
            $period = $this->scheduler->ensure($profile, $year, $quarter);

            return ['tax' => $period->tax_due_at->toDateString(), 'esv' => $period->esv_due_at->toDateString()];
        })();

        return $item['tax_type'] === TaxType::SocialContribution->value ? $deadlines['esv'] : $deadlines['tax'];
    }

    /**
     * Sum the items of the period's quarters per tax and in total. A tax with nothing in the period is left out; its
     * deadline is the earliest one of its items, which aren't paid yet (unpaid or sent).
     *
     * @param Collection<int, array<string, mixed>> $items
     * @param array<int, TaxType> $types
     *
     * @return array{total: array{accrued: string, sent: string, paid: string}, rows: array<int, array<string, mixed>>}
     */
    protected function summary(Collection $items, array $types): array
    {
        $rows = [];

        foreach (TaxType::cases() as $type) {
            if (!in_array($type, $types, true)) {
                continue;
            }

            $own = $items->where('tax_type', $type->value);
            $sums = $this->sums($own);
            $failed = Decimal::sum($own->where('status', TaxPaymentStatus::Failed->value)->pluck('amount'));

            if ($sums['accrued']->isZero() && $failed->isZero()) {
                continue;
            }

            $rows[] = [
                'tax_type' => $type->value,
                'accrued' => Decimal::money($sums['accrued']),
                'sent' => Decimal::money($sums['sent']),
                'paid' => Decimal::money($sums['paid']),
                'failed' => Decimal::money($failed),
                'due_at' => $own->whereIn('status', [self::UNPAID, TaxPaymentStatus::Sent->value])->min('due_at'),
            ];
        }

        $sum = fn (string $field) => Decimal::money(Decimal::sum(array_column($rows, $field)));

        return [
            'total' => ['accrued' => $sum('accrued'), 'sent' => $sum('sent'), 'paid' => $sum('paid')],
            'rows' => $rows,
        ];
    }

    /**
     * Group the listed items by month, newest first, each with its sums and its items in the order of the list.
     *
     * @param Collection<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    protected function months(Collection $items): array
    {
        return $items
            ->groupBy('month')
            ->sortKeysDesc()
            ->map(function (Collection $own, string $month) {
                $sums = $this->sums($own);

                return [
                    'month' => $month,
                    'accrued' => Decimal::money($sums['accrued']),
                    'sent' => Decimal::money($sums['sent']),
                    'paid' => Decimal::money($sums['paid']),
                    'items' => $own->sort(fn (array $a, array $b) => $this->compare($a, $b))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Sum what the items accrue (all but the failed payments), what is sent and what is paid.
     *
     * @param Collection<int, array<string, mixed>> $items
     *
     * @return array{accrued: BigDecimal, sent: BigDecimal, paid: BigDecimal}
     */
    protected function sums(Collection $items): array
    {
        return [
            'accrued' => Decimal::sum($items->where('status', '!=', TaxPaymentStatus::Failed->value)->pluck('amount')),
            'sent' => Decimal::sum($items->where('status', TaxPaymentStatus::Sent->value)->pluck('amount')),
            'paid' => Decimal::sum($items->where('status', TaxPaymentStatus::Paid->value)->pluck('amount')),
        ];
    }

    /**
     * Compare two items of a month: by status (unpaid, failed, sent, paid); the unpaid ЄСВ before the receipts'
     * taxes; then the newest first (a receipt's date, ЄСВ's month or a payment's date), and a receipt's ЄП before
     * its ВЗ.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return int
     */
    protected function compare(array $a, array $b): int
    {
        $rank = fn (array $item) => [
            array_search($item['status'], self::STATUSES, true),
            $item['status'] === self::UNPAID && $item['tax_type'] !== TaxType::SocialContribution->value ? 1 : 0,
        ];
        $date = fn (array $item) => $item['paid_at'] ?? $item['receipt']['received_at'] ?? $item['month'];
        $id = fn (array $item) => $item['tax_payment_id'] ?? $item['receipt']['id'] ?? 0;

        return $rank($a) <=> $rank($b)
            ?: $date($b) <=> $date($a)
            ?: $id($b) <=> $id($a)
            ?: self::TAX_ORDER[$a['tax_type']] <=> self::TAX_ORDER[$b['tax_type']];
    }
}
