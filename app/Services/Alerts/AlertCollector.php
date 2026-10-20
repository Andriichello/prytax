<?php

namespace PryTax\Services\Alerts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Enums\AccountKind;
use PryTax\Enums\AlertCategory;
use PryTax\Enums\AlertSeverity;
use PryTax\Enums\DeclarationStatus;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Helpers\MoneyFormatter;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Contract;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Accounts\AccountLimitCalculator;
use PryTax\Services\Payments\RepatriationRule;
use PryTax\Services\Rates\NbuRateProvider;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxReconciler;
use PryTax\Services\Transfers\HoldingCalculator;

/**
 * Class AlertCollector.
 *
 * Evaluates the conditions, which a profile's notifications report (design.md §9), and describes each one that
 * holds, keyed by its dedupe key: deadlines within `alerts.deadline_days` or past with a debt, platform money not
 * repatriated by the rule, account limits at 80 % and 100 %, the income cap at 80, 90 and 100 %, quarters needing an
 * уточнююча декларація, contracts ending or ended with open invoices, overdue invoices, ready invoices without
 * receipts for too long, and receipts or transfers saved at a manual rate, which differs from the NBU's published
 * since. Their texts are Ukrainian, as the app's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Alerts
 */
class AlertCollector
{
    /**
     * Days, after which a ready invoice without receipts is reported.
     *
     * @var int
     */
    public const int READY_WITHOUT_RECEIPTS_DAYS = 14;

    /**
     * Days before a contract's end, from which it is reported.
     *
     * @var int
     */
    public const int CONTRACT_ENDING_DAYS = 30;

    /**
     * Today, as the collector sees it.
     *
     * @var CarbonImmutable
     */
    protected CarbonImmutable $today;

    /**
     * Formatter of the profile's amounts.
     *
     * @var MoneyFormatter
     */
    protected MoneyFormatter $money;

    /**
     * AlertCollector constructor.
     *
     * @param DeadlineScheduler $deadlines
     * @param TaxReconciler $reconciler
     * @param TaxLiabilityCalculator $liabilities
     * @param HoldingCalculator $holdings
     * @param RepatriationRule $repatriation
     * @param AccountLimitCalculator $limits
     * @param NbuRateProvider $rates
     */
    public function __construct(
        protected DeadlineScheduler $deadlines,
        protected TaxReconciler $reconciler,
        protected TaxLiabilityCalculator $liabilities,
        protected HoldingCalculator $holdings,
        protected RepatriationRule $repatriation,
        protected AccountLimitCalculator $limits,
        protected NbuRateProvider $rates
    ) {}

    /**
     * Get the notifications, whose conditions hold for the profile now, keyed by their dedupe keys.
     *
     * @param Profile $profile
     * @param CarbonImmutable|null $today
     *
     * @return array<string, array<string, mixed>>
     */
    public function collect(Profile $profile, ?CarbonImmutable $today = null): array
    {
        $this->today = $today ?? CarbonImmutable::today();
        $this->money = MoneyFormatter::of($profile->setting('display.money_format'));

        if (!$profile->is_active) {
            return [];
        }

        $this->deadlines->ensureCurrentAndNext($profile, $this->today);

        return [
            ...$this->taxDeadlines($profile),
            ...$this->corrections(),
            ...$this->repatriation($profile),
            ...$this->accountLimits(),
            ...$this->incomeCap($profile),
            ...$this->contracts(),
            ...$this->invoices(),
            ...$this->manualRates(),
        ];
    }

    /**
     * Deadlines of the declarations and of the payments of ЄП, ВЗ and ЄСВ, within the profile's days of warning or
     * past while a debt remains.
     *
     * @param Profile $profile
     *
     * @return array<string, array<string, mixed>>
     */
    protected function taxDeadlines(Profile $profile): array
    {
        $alerts = [];
        $days = (int) $profile->setting('alerts.deadline_days');
        $horizon = $this->today->addDays($days);

        $periods = TaxPeriod::query()
            ->whereIn('year', [$this->today->year - 1, $this->today->year])
            ->orderBy('year')
            ->orderBy('quarter')
            ->get()
            ->filter(fn (TaxPeriod $period) => CarbonImmutable::create($period->year, $period->quarter * 3)->endOfMonth()->lt($this->today));

        $balances = [];

        foreach ([TaxType::SingleTax, TaxType::MilitaryLevy, TaxType::SocialContribution] as $type) {
            foreach (array_unique($periods->pluck('year')->all()) as $year) {
                foreach ($this->reconciler->forYear($profile, $type, $year, $this->today)['quarters'] as $quarter) {
                    $balances[$type->value]["{$quarter['year']}:{$quarter['quarter']}"] = Decimal::of($quarter['balance']);
                }
            }
        }

        foreach ($periods as $period) {
            $label = $this->quarterLabel($period->year, $period->quarter);
            $key = "{$period->year}:{$period->quarter}";
            $route = "/taxes/declaration/{$period->year}/{$period->quarter}";

            $declared = in_array($period->declaration_status, [DeclarationStatus::Submitted, DeclarationStatus::Accepted, DeclarationStatus::Corrected], true);

            if (!$declared && $period->declaration_due_at->lte($horizon)) {
                $alerts["deadline:{$period->id}:declaration"] = $this->deadline(
                    $period->declaration_due_at,
                    "Декларація за $label",
                    'Подайте декларацію',
                    $route,
                    'Відкрити декларацію',
                    ['tax_period_id' => $period->id, 'kind' => 'declaration'],
                );
            }

            $taxDebt = Decimal::zero();

            foreach ([TaxType::SingleTax, TaxType::MilitaryLevy] as $type) {
                $balance = $balances[$type->value][$key] ?? Decimal::zero();
                $taxDebt = $taxDebt->plus($balance->isNegative() ? $balance->negated() : Decimal::zero());
            }

            if ($taxDebt->isPositive() && $period->tax_due_at->lte($horizon)) {
                $alerts["deadline:{$period->id}:tax"] = $this->deadline(
                    $period->tax_due_at,
                    "ЄП і ВЗ за $label",
                    'До сплати ' . $this->money->money($taxDebt, 'UAH'),
                    "/taxes?year={$period->year}",
                    'Сплатити',
                    ['tax_period_id' => $period->id, 'kind' => 'tax', 'amount' => Decimal::money($taxDebt)],
                );
            }

            $esvBalance = $balances[TaxType::SocialContribution->value][$key] ?? Decimal::zero();

            if ($esvBalance->isNegative() && $period->esv_due_at->lte($horizon)) {
                $alerts["deadline:{$period->id}:esv"] = $this->deadline(
                    $period->esv_due_at,
                    "ЄСВ за $label",
                    'До сплати ' . $this->money->money($esvBalance->negated(), 'UAH'),
                    "/accounting/esv?year={$period->year}",
                    'Сплатити ЄСВ',
                    ['tax_period_id' => $period->id, 'kind' => 'esv', 'amount' => Decimal::money($esvBalance->negated())],
                );
            }
        }

        return $alerts;
    }

    /**
     * Describe a deadline: a warning, while it is ahead, and an error, once it passed.
     *
     * @param CarbonImmutable|CarbonInterface $due
     * @param string $title
     * @param string $body
     * @param string $route
     * @param string $action
     * @param array $payload
     *
     * @return array<string, mixed>
     */
    protected function deadline($due, string $title, string $body, string $route, string $action, array $payload): array
    {
        $past = $due->lt($this->today);
        $days = (int) $this->today->diffInDays($due, false);

        return [
            'type' => 'deadline',
            'category' => AlertCategory::Deadlines,
            'severity' => $past ? AlertSeverity::Error : AlertSeverity::Warning,
            'title' => $title,
            'body' => $body . ($past
                ? ' — строк минув ' . $due->format('d.m.Y') . '.'
                : ' до ' . $due->format('d.m.Y') . ($days === 0 ? ' — сьогодні.' : " — через $days дн.")),
            'payload' => [...$payload, 'due_at' => $due->toDateString(), 'context' => 'Термін'],
            'action_label' => $action,
            'action_route' => $route,
        ];
    }

    /**
     * Quarters, whose declarations need an уточнююча декларація after a correction.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function corrections(): array
    {
        $alerts = [];

        TaxPeriod::query()
            ->where('declaration_status', DeclarationStatus::Corrected->value)
            ->get()
            ->each(function (TaxPeriod $period) use (&$alerts) {
                $label = $this->quarterLabel($period->year, $period->quarter);

                $alerts["correction:{$period->id}"] = [
                    'type' => 'correction',
                    'category' => AlertCategory::Deadlines,
                    'severity' => AlertSeverity::Error,
                    'title' => "Потрібна уточнююча декларація за $label",
                    'body' => 'Дохід поданого кварталу змінено. Декларації наростаючим підсумком, тож уточнення потрібне для цього й наступних поданих кварталів року.',
                    'payload' => ['tax_period_id' => $period->id, 'context' => 'Декларація'],
                    'action_label' => 'Відкрити декларацію',
                    'action_route' => "/taxes/declaration/{$period->year}/{$period->quarter}",
                ];
            });

        return $alerts;
    }

    /**
     * Receipts, whose money is still on a platform past the profile's repatriation rule, and wasn't dismissed.
     *
     * @param Profile $profile
     *
     * @return array<string, array<string, mixed>>
     */
    protected function repatriation(Profile $profile): array
    {
        $alerts = [];

        $platforms = Account::query()->whereIn('kind', [AccountKind::Platform->value, AccountKind::ForeignBank->value])->get();

        foreach ($platforms as $account) {
            foreach ($this->holdings->holdingsOn($account) as $holding) {
                /** @var Payment $payment */
                $payment = $holding['payment'];
                $deadline = $this->repatriation->deadline($profile, $payment->received_at);

                if ($deadline === null || !$deadline->lt($this->today) || $payment->repatriation_ack_at !== null) {
                    continue;
                }

                $invoice = $payment->invoice?->number ? ' за інвойсом ' . $payment->invoice->number : '';

                $alerts["repatriation:{$payment->id}:{$account->id}"] = [
                    'type' => 'repatriation',
                    'category' => AlertCategory::Funds,
                    'severity' => AlertSeverity::Warning,
                    'title' => 'Кошти на платформі після кінця строку',
                    'body' => $this->money->money($holding['amount'], $account->currency)
                        . ' з надходження ' . $payment->received_at->format('d.m.Y') . $invoice
                        . " досі на {$account->name}. ДПС може вважати це доходом фізособи (18 % ПДФО + 5 % ВЗ).",
                    'payload' => [
                        'payment_id' => $payment->id,
                        'account_id' => $account->id,
                        'amount' => Decimal::money($holding['amount']),
                        'currency' => $account->currency,
                        'context' => 'Репатріація · з ' . $deadline->addDay()->format('d.m.Y'),
                    ],
                    'action_label' => 'Створити переказ',
                    'action_route' => "/transfers/new?from={$account->id}",
                ];
            }
        }

        return $alerts;
    }

    /**
     * Active accounts at 80 % or 100 % of their limits.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function accountLimits(): array
    {
        $alerts = [];
        $periods = ['month' => 'місячного', 'quarter' => 'квартального', 'year' => 'річного'];

        Account::query()->active()->whereNotNull('limit_amount')->get()->each(function (Account $account) use (&$alerts, $periods) {
            $progress = $this->limits->progress($account, $this->today);
            $percent = (int) ($progress['percent'] ?? 0);

            if ($progress === null || $percent < 80) {
                return;
            }

            $threshold = $percent >= 100 ? 100 : 80;

            $alerts["limit:{$account->id}:$threshold"] = [
                'type' => 'limit',
                'category' => AlertCategory::Funds,
                'severity' => $threshold === 100 ? AlertSeverity::Error : AlertSeverity::Warning,
                'title' => "{$account->name} — $percent % " . ($periods[$progress['period']] ?? '') . ' ліміту',
                'body' => $this->money->amount($progress['used']) . ' / ' . $this->money->money($progress['limit'], $account->currency)
                    . '. Залишок ' . $this->money->money($progress['remaining'], $account->currency) . '.',
                'payload' => [...$progress, 'account_id' => $account->id, 'context' => 'Ліміт рахунку'],
                'action_label' => 'Відкрити рахунок',
                'action_route' => "/accounts/{$account->id}",
            ];
        });

        return $alerts;
    }

    /**
     * Year-to-date income at 80 %, 90 % or 100 % of the income cap, with the tax on the excess.
     *
     * @param Profile $profile
     *
     * @return array<string, array<string, mixed>>
     */
    protected function incomeCap(Profile $profile): array
    {
        $cap = $this->liabilities->forQuarter($profile, $this->today->year, $this->today->quarter)['cap'];
        $percent = Decimal::of($cap['percent']);

        $threshold = collect([100, 90, 80])->first(fn (int $threshold) => $percent->isGreaterThanOrEqualTo($threshold));

        if ($threshold === null) {
            return [];
        }

        return ["cap:{$this->today->year}:$threshold" => [
            'type' => 'cap',
            'category' => AlertCategory::Funds,
            'severity' => $threshold === 100 ? AlertSeverity::Error : AlertSeverity::Warning,
            'title' => "Дохід — {$cap['percent']} % річного ліміту",
            'body' => $this->money->money($cap['ytd_income'], 'UAH') . ' з ' . $this->money->money($cap['income_cap'], 'UAH')
                . ($threshold === 100
                    ? '. Перевищення ' . $this->money->money($cap['excess'], 'UAH') . ' оподатковується за 15 % ('
                        . $this->money->money($cap['excess_tax'], 'UAH') . '), з наступного кварталу — загальна система.'
                    : '.'),
            'payload' => [...$cap, 'context' => 'Ліміт доходу'],
            'action_label' => 'Відкрити облік',
            'action_route' => "/accounting/{$this->today->year}/{$this->today->quarter}",
        ]];
    }

    /**
     * Contracts ending within 30 days, and ended ones with open invoices.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function contracts(): array
    {
        $alerts = [];

        Contract::query()
            ->active()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $this->today->addDays(self::CONTRACT_ENDING_DAYS)->toDateString())
            ->with('client')
            ->get()
            ->each(function (Contract $contract) use (&$alerts) {
                $open = Invoice::query()->open()->where('contract_id', $contract->id)->count();
                $ended = $contract->ends_at->lt($this->today);

                if ($ended && $open === 0) {
                    return;
                }

                $days = (int) $this->today->diffInDays($contract->ends_at);
                $name = trim($contract->name . ' ' . ($contract->number ?? ''));

                $alerts["contract:{$contract->id}:" . ($ended ? 'ended' : 'ending')] = [
                    'type' => 'contract',
                    'category' => AlertCategory::Invoices,
                    'severity' => AlertSeverity::Info,
                    'title' => "Договір з {$contract->client->name} " . ($ended ? 'закінчився' : 'закінчується'),
                    'body' => $name . ($ended
                        ? ' діяв до ' . $contract->ends_at->format('d.m.Y') . '.'
                        : ' діє до ' . $contract->ends_at->format('d.m.Y') . " — через $days дн.")
                        . ($open > 0 ? " Відкритих інвойсів: $open." : ''),
                    'payload' => ['contract_id' => $contract->id, 'client_id' => $contract->client_id, 'open_invoices' => $open, 'context' => 'Договір'],
                    'action_label' => 'Відкрити договір',
                    'action_route' => "/clients/{$contract->client_id}/contracts/{$contract->id}",
                ];
            });

        return $alerts;
    }

    /**
     * Overdue invoices, and ready ones without receipts for too long.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function invoices(): array
    {
        $alerts = [];

        Invoice::query()->open()->withPaidAmount()->with('client')->get()->each(function (Invoice $invoice) use (&$alerts) {
            $left = Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount));

            if ($invoice->due_at !== null && $invoice->due_at->lt($this->today) && $left->isPositive()) {
                $alerts["overdue:{$invoice->id}"] = [
                    'type' => 'overdue',
                    'category' => AlertCategory::Invoices,
                    'severity' => AlertSeverity::Info,
                    'title' => "{$invoice->number} · {$invoice->client->name}",
                    'body' => 'Термін оплати минув ' . $invoice->due_at->format('d.m.Y') . '. Не оплачено '
                        . $this->money->money($left, $invoice->currency) . '.',
                    'payload' => ['invoice_id' => $invoice->id, 'remaining' => Decimal::money($left), 'currency' => $invoice->currency, 'context' => 'Інвойс прострочено'],
                    'action_label' => 'Відкрити інвойс',
                    'action_route' => "/invoices/{$invoice->id}",
                ];
            }

            $stale = $invoice->status === InvoiceStatus::Ready
                && $invoice->locked_at !== null
                && $invoice->locked_at->lt($this->today->subDays(self::READY_WITHOUT_RECEIPTS_DAYS))
                && Decimal::of($invoice->paid_amount)->isZero();

            if ($stale) {
                $alerts["ready:{$invoice->id}"] = [
                    'type' => 'ready_without_receipts',
                    'category' => AlertCategory::Invoices,
                    'severity' => AlertSeverity::Info,
                    'title' => "{$invoice->number} · {$invoice->client->name}",
                    'body' => 'Інвойс зафіксовано ' . $invoice->locked_at->format('d.m.Y') . ', але не позначено надісланим і надходжень немає.',
                    'payload' => ['invoice_id' => $invoice->id, 'context' => 'Інвойс без руху'],
                    'action_label' => 'Відкрити інвойс',
                    'action_route' => "/invoices/{$invoice->id}",
                ];
            }
        });

        return $alerts;
    }

    /**
     * Receipts and transfers saved at a manual rate, while the NBU's rate of their day, published since, differs:
     * updating them takes the NBU's rate.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function manualRates(): array
    {
        $alerts = [];

        Payment::query()->where('rate_is_manual', true)->with('invoice')->get()->each(function (Payment $payment) use (&$alerts) {
            $nbu = $this->rates->cached($payment->currency, $payment->nbu_rate_date);

            if ($nbu === null || Decimal::of($nbu->rate)->isEqualTo(Decimal::of($payment->nbu_rate))) {
                return;
            }

            $what = $payment->invoice?->number ? 'за інвойсом ' . $payment->invoice->number : 'від ' . $payment->received_at->format('d.m.Y');

            $alerts["manual_rate:payment:{$payment->id}"] = $this->manualRate(
                "Надходження $what записано за курсом, введеним вручну",
                $payment->currency,
                $payment->nbu_rate,
                $nbu->rate,
                ['payment_id' => $payment->id],
                "/payments/{$payment->id}",
                'Відкрити надходження',
            );
        });

        AccountTransfer::query()->where('rate_is_manual', true)->get()->each(function (AccountTransfer $transfer) use (&$alerts) {
            $currency = $transfer->currency_sent === 'UAH' ? $transfer->currency_received : $transfer->currency_sent;
            $nbu = $this->rates->cached($currency, $transfer->received_at);

            if ($nbu === null || Decimal::of($nbu->rate)->isEqualTo(Decimal::of($transfer->nbu_rate))) {
                return;
            }

            $alerts["manual_rate:transfer:{$transfer->id}"] = $this->manualRate(
                'Переказ ' . $transfer->received_at->format('d.m.Y') . ' записано за курсом, введеним вручну',
                $currency,
                $transfer->nbu_rate,
                $nbu->rate,
                ['transfer_id' => $transfer->id],
                "/transfers/{$transfer->id}",
                'Відкрити переказ',
            );
        });

        return $alerts;
    }

    /**
     * Describe a record saved at a manual rate, which differs from the NBU's.
     *
     * @param string $title
     * @param string $currency
     * @param string $manual
     * @param string $nbu
     * @param array $payload
     * @param string $route
     * @param string $action
     *
     * @return array<string, mixed>
     */
    protected function manualRate(string $title, string $currency, string $manual, string $nbu, array $payload, string $route, string $action): array
    {
        return [
            'type' => 'manual_rate',
            'category' => AlertCategory::Funds,
            'severity' => AlertSeverity::Warning,
            'title' => $title,
            'body' => "Курс 1 $currency = $manual ₴, а НБУ опублікував $nbu ₴. Оновіть запис, щоб він узяв курс НБУ.",
            'payload' => [...$payload, 'currency' => $currency, 'manual_rate' => $manual, 'nbu_rate' => $nbu, 'context' => 'Курс НБУ'],
            'action_label' => $action,
            'action_route' => $route,
        ];
    }

    /**
     * Name a quarter, such as "III кв. 2026".
     *
     * @param int $year
     * @param int $quarter
     *
     * @return string
     */
    protected function quarterLabel(int $year, int $quarter): string
    {
        return ['I', 'II', 'III', 'IV'][$quarter - 1] . " кв. $year";
    }
}
