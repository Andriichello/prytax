<?php

namespace PryTax\Services\Alerts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
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
use PryTax\Services\Taxes\TaxRuleResolver;
use PryTax\Services\Taxes\TaxTracking;
use PryTax\Services\Transfers\HoldingCalculator;

/**
 * Class AlertCollector.
 *
 * Evaluates the conditions, which a profile's notifications report (design.md §9), and describes each one that
 * holds, keyed by its dedupe key: deadlines within `alerts.deadline_days` or past with a debt, platform money not
 * repatriated by the rule, account limits at 80 % and 100 %, the income cap at 80, 90 and 100 %, quarters needing an
 * уточнююча декларація, contracts ending or ended with open invoices, overdue invoices, ready invoices without
 * receipts for too long, and receipts or transfers saved at a manual rate, which differs from the NBU's published
 * since. Each one's texts are a closure (`texts`), which AlertSync renders in every language of the app: the title,
 * the body, the action's label and the context line, written with `__()`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Alerts
 */
class AlertCollector
{
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
     * @param TaxTracking $tracking
     * @param TaxRuleResolver $rules
     */
    public function __construct(
        protected DeadlineScheduler $deadlines,
        protected TaxReconciler $reconciler,
        protected TaxLiabilityCalculator $liabilities,
        protected HoldingCalculator $holdings,
        protected RepatriationRule $repatriation,
        protected AccountLimitCalculator $limits,
        protected NbuRateProvider $rates,
        protected TaxTracking $tracking,
        protected TaxRuleResolver $rules
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
            ...$this->invoices($profile),
            ...$this->manualRates(),
            ...$this->taxRulesOfNextYear(),
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

        // Every tracked quarter, which ended: a debt of an earlier year stays until it is paid
        $periods = TaxPeriod::query()
            ->orderBy('year')
            ->orderBy('quarter')
            ->get()
            ->filter(fn (TaxPeriod $period) => CarbonImmutable::create($period->year, $period->quarter * 3)->endOfMonth()->lt($this->today)
                && $this->tracking->tracks($profile, $period->year, $period->quarter, $this->today));

        $balances = [];

        foreach ([TaxType::SingleTax, TaxType::MilitaryLevy, TaxType::SocialContribution] as $type) {
            foreach (array_unique($periods->pluck('year')->all()) as $year) {
                foreach ($this->reconciler->forYear($profile, $type, $year, $this->today)['quarters'] as $quarter) {
                    $balances[$type->value]["{$quarter['year']}:{$quarter['quarter']}"] = Decimal::of($quarter['balance']);
                }
            }
        }

        // A debt is one notice, at the latest quarter due: its running balance holds the earlier quarters' debts, and a
        // payment, which an earlier debt took through a later quarter, clears it
        $lastTaxDue = $periods->filter(fn (TaxPeriod $period) => $period->tax_due_at->lte($horizon))->last();
        $lastEsvDue = $periods->filter(fn (TaxPeriod $period) => $period->esv_due_at->lte($horizon))->last();

        foreach ($periods as $period) {
            $key = "{$period->year}:{$period->quarter}";
            $route = "/taxes/declaration/{$period->year}/{$period->quarter}";

            $declared = in_array($period->declaration_status, [DeclarationStatus::Submitted, DeclarationStatus::Accepted, DeclarationStatus::Corrected], true);

            if (!$declared && $period->declaration_due_at->lte($horizon)) {
                $alerts["deadline:{$period->id}:declaration"] = $this->deadline(
                    $period->declaration_due_at,
                    fn () => __('Declaration for :quarter', ['quarter' => $this->quarterLabel($period->year, $period->quarter)]),
                    fn () => __('File the declaration'),
                    $route,
                    fn () => __('Open the declaration'),
                    ['tax_period_id' => $period->id, 'kind' => 'declaration'],
                );
            }

            $taxDebt = Decimal::zero();

            foreach ([TaxType::SingleTax, TaxType::MilitaryLevy] as $type) {
                $balance = $balances[$type->value][$key] ?? Decimal::zero();
                $taxDebt = $taxDebt->plus($balance->isNegative() ? $balance->negated() : Decimal::zero());
            }

            if ($taxDebt->isPositive() && $period->is($lastTaxDue)) {
                $alerts["deadline:{$period->id}:tax"] = $this->deadline(
                    $period->tax_due_at,
                    fn () => __('Single tax and military levy for :quarter', ['quarter' => $this->quarterLabel($period->year, $period->quarter)]),
                    fn () => __('Due :amount', ['amount' => $this->money->money($taxDebt, 'UAH')]),
                    "/taxes?year={$period->year}",
                    fn () => __('Pay'),
                    ['tax_period_id' => $period->id, 'kind' => 'tax', 'amount' => Decimal::money($taxDebt)],
                );
            }

            $esvBalance = $balances[TaxType::SocialContribution->value][$key] ?? Decimal::zero();

            if ($esvBalance->isNegative() && $period->is($lastEsvDue)) {
                // An unpaid month of ЄСВ is a warning, also past its deadline (design.md §9)
                $alerts["deadline:{$period->id}:esv"] = $this->deadline(
                    $period->esv_due_at,
                    fn () => __('SSC for :quarter', ['quarter' => $this->quarterLabel($period->year, $period->quarter)]),
                    fn () => __('Due :amount', ['amount' => $this->money->money($esvBalance->negated(), 'UAH')]),
                    "/accounting/esv?year={$period->year}",
                    fn () => __('Pay SSC'),
                    ['tax_period_id' => $period->id, 'kind' => 'esv', 'amount' => Decimal::money($esvBalance->negated())],
                    AlertSeverity::Warning,
                );
            }
        }

        return $alerts;
    }

    /**
     * Describe a deadline: a warning, while it is ahead, and an error, once it passed.
     *
     * @param CarbonImmutable|CarbonInterface $due
     * @param Closure(): string $title
     * @param Closure(): string $todo What is to be done, which the deadline follows.
     * @param string $route
     * @param Closure(): string $action
     * @param array $payload
     * @param AlertSeverity $pastSeverity
     *
     * @return array<string, mixed>
     */
    protected function deadline(
        $due,
        Closure $title,
        Closure $todo,
        string $route,
        Closure $action,
        array $payload,
        AlertSeverity $pastSeverity = AlertSeverity::Error
    ): array {
        $past = $due->lt($this->today);
        $days = (int) $this->today->diffInDays($due, false);
        $date = $due->format('d.m.Y');

        return [
            'type' => 'deadline',
            'category' => AlertCategory::Deadlines,
            'severity' => $past ? $pastSeverity : AlertSeverity::Warning,
            'texts' => fn () => [
                'title' => $title(),
                'body' => match (true) {
                    $past => __(':todo — the deadline passed on :date.', ['todo' => $todo(), 'date' => $date]),
                    $days === 0 => __(':todo by :date — today.', ['todo' => $todo(), 'date' => $date]),
                    default => trans_choice(':todo by :date — in :count day.|:todo by :date — in :count days.', $days, ['todo' => $todo(), 'date' => $date]),
                },
                'action_label' => $action(),
                'context' => __('Deadline'),
            ],
            'payload' => [...$payload, 'due_at' => $due->toDateString()],
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
                $alerts["correction:{$period->id}"] = [
                    'type' => 'correction',
                    'category' => AlertCategory::Deadlines,
                    'severity' => AlertSeverity::Error,
                    'texts' => fn () => [
                        'title' => __('A corrective declaration is needed for :quarter', ['quarter' => $this->quarterLabel($period->year, $period->quarter)]),
                        'body' => __('The income of a filed quarter changed. Declarations are cumulative, so this quarter and the later filed quarters of the year need correcting.'),
                        'action_label' => __('Open the declaration'),
                        'context' => __('Declaration'),
                    ],
                    'payload' => ['tax_period_id' => $period->id],
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

        $platforms = Account::query()->get()->filter(fn (Account $account) => $account->needsRepatriation());

        foreach ($platforms as $account) {
            foreach ($this->holdings->holdingsOn($account) as $holding) {
                /** @var Payment $payment */
                $payment = $holding['payment'];
                $deadline = $this->repatriation->deadline($profile, $payment->received_at);

                if ($deadline === null || !$deadline->lt($this->today) || $payment->repatriation_ack_at !== null) {
                    continue;
                }

                $replace = [
                    'amount' => $this->money->money($holding['amount'], $account->currency),
                    'date' => $payment->received_at->format('d.m.Y'),
                    'invoice' => $payment->invoice?->number,
                    'account' => $account->name,
                ];
                $since = $deadline->addDay()->format('d.m.Y');

                $alerts["repatriation:{$payment->id}:{$account->id}"] = [
                    'type' => 'repatriation',
                    'category' => AlertCategory::Funds,
                    'severity' => AlertSeverity::Warning,
                    'texts' => fn () => [
                        'title' => __('Money left on the platform past the deadline'),
                        'body' => $replace['invoice'] !== null
                            ? __(':amount of the receipt of :date for invoice :invoice is still on :account. The tax service may treat it as personal income (18% personal income tax + 5% military levy).', $replace)
                            : __(':amount of the receipt of :date is still on :account. The tax service may treat it as personal income (18% personal income tax + 5% military levy).', $replace),
                        'action_label' => __('Create a transfer'),
                        'context' => __('Repatriation · since :date', ['date' => $since]),
                    ],
                    'payload' => [
                        'payment_id' => $payment->id,
                        'account_id' => $account->id,
                        'amount' => Decimal::money($holding['amount']),
                        'currency' => $account->currency,
                    ],
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

        Account::query()->active()->whereNotNull('limit_amount')->get()->each(function (Account $account) use (&$alerts) {
            $progress = $this->limits->progress($account, $this->today);

            if ($progress === null) {
                return;
            }

            $threshold = $this->threshold($progress['used'], $progress['limit'], [100, 80]);

            if ($threshold === null) {
                return;
            }

            $percent = $progress['percent'];
            // A calendar window's crossing is news in each window; a rolling one's is one condition
            $window = $progress['window'] === 'calendar' ? ":{$progress['from']}" : '';

            $title = ['account' => $account->name, 'percent' => $percent];
            $body = [
                'used' => $this->money->amount($progress['used']),
                'limit' => $this->money->money($progress['limit'], $account->currency),
                'remaining' => $this->money->money($progress['remaining'], $account->currency),
            ];

            $alerts["limit:{$account->id}:$threshold$window"] = [
                'type' => 'limit',
                'category' => AlertCategory::Funds,
                'severity' => $threshold === 100 ? AlertSeverity::Error : AlertSeverity::Warning,
                'texts' => fn () => [
                    'title' => match ($progress['period']) {
                        'month' => __(':account — :percent% of the monthly limit', $title),
                        'quarter' => __(':account — :percent% of the quarterly limit', $title),
                        'year' => __(':account — :percent% of the yearly limit', $title),
                        default => __(':account — :percent% of the limit', $title),
                    },
                    'body' => __(':used / :limit. :remaining left.', $body),
                    'action_label' => __('Open the account'),
                    'context' => __('Account limit'),
                ],
                'payload' => [...$progress, 'account_id' => $account->id],
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
        $threshold = $this->threshold($cap['ytd_income'], $cap['income_cap'], [100, 90, 80]);

        if ($threshold === null) {
            return [];
        }

        $replace = [
            'income' => $this->money->money($cap['ytd_income'], 'UAH'),
            'cap' => $this->money->money($cap['income_cap'], 'UAH'),
            'excess' => $this->money->money($cap['excess'], 'UAH'),
            'rate' => rtrim(rtrim(Decimal::of($cap['excess_rate'])->multipliedBy(100)->toScale(2)->__toString(), '0'), '.'),
            'tax' => $this->money->money($cap['excess_tax'], 'UAH'),
        ];

        return ["cap:{$this->today->year}:$threshold" => [
            'type' => 'cap',
            'category' => AlertCategory::Funds,
            'severity' => $threshold === 100 ? AlertSeverity::Error : AlertSeverity::Warning,
            'texts' => fn () => [
                'title' => __('Income — :percent% of the yearly cap', ['percent' => $cap['percent']]),
                'body' => $threshold === 100
                    ? __(':income of :cap. The excess of :excess is taxed at :rate% (:tax); from the next quarter, the general system applies.', $replace)
                    : __(':income of :cap.', $replace),
                'action_label' => __('Open accounting'),
                'context' => __('Income cap'),
            ],
            'payload' => $cap,
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
                $replace = [
                    'client' => $contract->client->name,
                    'contract' => trim($contract->name . ' ' . ($contract->number ?? '')),
                    'date' => $contract->ends_at->format('d.m.Y'),
                ];

                $alerts["contract:{$contract->id}:" . ($ended ? 'ended' : 'ending')] = [
                    'type' => 'contract',
                    'category' => AlertCategory::Invoices,
                    'severity' => AlertSeverity::Info,
                    'texts' => fn () => [
                        'title' => $ended
                            ? __('The contract with :client has ended', $replace)
                            : __('The contract with :client is ending', $replace),
                        'body' => ($ended
                            ? __(':contract was valid until :date.', $replace)
                            : trans_choice(':contract is valid until :date — in :count day.|:contract is valid until :date — in :count days.', $days, $replace))
                            . ($open > 0 ? ' ' . __('Open invoices: :count.', ['count' => $open]) : ''),
                        'action_label' => __('Open the contract'),
                        'context' => __('Contract'),
                    ],
                    'payload' => ['contract_id' => $contract->id, 'client_id' => $contract->client_id, 'open_invoices' => $open],
                    'action_route' => "/clients/{$contract->client_id}/contracts/{$contract->id}",
                ];
            });

        return $alerts;
    }

    /**
     * Overdue invoices, and ready ones without receipts for longer than the profile's days.
     *
     * @param Profile $profile
     *
     * @return array<string, array<string, mixed>>
     */
    protected function invoices(Profile $profile): array
    {
        $alerts = [];
        $readyDays = (int) $profile->setting('alerts.ready_days');

        Invoice::query()->open()->withPaidAmount()->with('client')->get()->each(function (Invoice $invoice) use (&$alerts, $readyDays) {
            $left = Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount));

            if ($invoice->due_at !== null && $invoice->due_at->lt($this->today) && $left->isPositive()) {
                $replace = ['date' => $invoice->due_at->format('d.m.Y'), 'amount' => $this->money->money($left, $invoice->currency)];

                $alerts["overdue:{$invoice->id}"] = [
                    'type' => 'overdue',
                    'category' => AlertCategory::Invoices,
                    'severity' => AlertSeverity::Info,
                    'texts' => fn () => [
                        'title' => "{$invoice->number} · {$invoice->client->name}",
                        'body' => __('Payment was due on :date. Unpaid: :amount.', $replace),
                        'action_label' => __('Open the invoice'),
                        'context' => __('Overdue invoice'),
                    ],
                    'payload' => ['invoice_id' => $invoice->id, 'remaining' => Decimal::money($left), 'currency' => $invoice->currency],
                    'action_route' => "/invoices/{$invoice->id}",
                ];
            }

            $stale = $invoice->status === InvoiceStatus::Ready
                && $invoice->locked_at !== null
                && $invoice->locked_at->lt($this->today->subDays($readyDays))
                && Decimal::of($invoice->paid_amount)->isZero();

            if ($stale) {
                $frozen = $invoice->locked_at->format('d.m.Y');

                $alerts["ready:{$invoice->id}"] = [
                    'type' => 'ready_without_receipts',
                    'category' => AlertCategory::Invoices,
                    'severity' => AlertSeverity::Info,
                    'texts' => fn () => [
                        'title' => "{$invoice->number} · {$invoice->client->name}",
                        'body' => __('The invoice was frozen on :date, but it isn’t marked sent and has no receipts.', ['date' => $frozen]),
                        'action_label' => __('Open the invoice'),
                        'context' => __('Invoice without activity'),
                    ],
                    'payload' => ['invoice_id' => $invoice->id],
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

            $number = $payment->invoice?->number;
            $date = $payment->received_at->format('d.m.Y');

            $alerts["manual_rate:payment:{$payment->id}"] = $this->manualRate(
                fn () => $number !== null
                    ? __('The receipt for invoice :invoice was saved at a manually entered rate', ['invoice' => $number])
                    : __('The receipt of :date was saved at a manually entered rate', ['date' => $date]),
                $payment->currency,
                $payment->nbu_rate,
                $nbu->rate,
                ['payment_id' => $payment->id],
                "/payments/{$payment->id}",
                fn () => __('Open the receipt'),
            );
        });

        AccountTransfer::query()->where('rate_is_manual', true)->get()->each(function (AccountTransfer $transfer) use (&$alerts) {
            $currency = $transfer->currency_sent === 'UAH' ? $transfer->currency_received : $transfer->currency_sent;
            $nbu = $this->rates->cached($currency, $transfer->received_at);

            if ($nbu === null || Decimal::of($nbu->rate)->isEqualTo(Decimal::of($transfer->nbu_rate))) {
                return;
            }

            $date = $transfer->received_at->format('d.m.Y');

            $alerts["manual_rate:transfer:{$transfer->id}"] = $this->manualRate(
                fn () => __('The transfer of :date was saved at a manually entered rate', ['date' => $date]),
                $currency,
                $transfer->nbu_rate,
                $nbu->rate,
                ['transfer_id' => $transfer->id],
                "/transfers/{$transfer->id}",
                fn () => __('Open the transfer'),
            );
        });

        return $alerts;
    }

    /**
     * Describe a record saved at a manual rate, which differs from the NBU's.
     *
     * @param Closure(): string $title
     * @param string $currency
     * @param string $manual
     * @param string $nbu
     * @param array $payload
     * @param string $route
     * @param Closure(): string $action
     *
     * @return array<string, mixed>
     */
    protected function manualRate(Closure $title, string $currency, string $manual, string $nbu, array $payload, string $route, Closure $action): array
    {
        return [
            'type' => 'manual_rate',
            'category' => AlertCategory::Funds,
            'severity' => AlertSeverity::Warning,
            'texts' => fn () => [
                'title' => $title(),
                'body' => __('The rate is 1 :currency = :manual ₴, while the NBU published :nbu ₴. Update the record to take the NBU’s rate.', [
                    'currency' => $currency,
                    'manual' => $manual,
                    'nbu' => $nbu,
                ]),
                'action_label' => $action(),
                'context' => __('NBU rate'),
            ],
            'payload' => [...$payload, 'currency' => $currency, 'manual_rate' => $manual, 'nbu_rate' => $nbu],
            'action_route' => $route,
        ];
    }

    /**
     * The tax rules of the next year, while they aren't added in its last month: without them, its receipts can't be
     * taxed and its ЄСВ isn't owed.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function taxRulesOfNextYear(): array
    {
        $next = $this->today->year + 1;

        if ($this->today->month < 12 || $this->rules->find(CarbonImmutable::create($next)) !== null) {
            return [];
        }

        return ["tax_rules:$next" => [
            'type' => 'tax_rules',
            'category' => AlertCategory::Deadlines,
            'severity' => AlertSeverity::Warning,
            'texts' => fn () => [
                'title' => __('The tax rules of :year aren’t added yet', ['year' => $next]),
                'body' => __('The minimum wage, SSC and the income cap of :year aren’t in PryTax yet. Without them, receipts of :year won’t be accepted and SSC won’t be charged. Add them by updating the app.', ['year' => $next]),
                'action_label' => null,
                'context' => __('Tax rules'),
            ],
            'payload' => ['year' => $next],
            'action_route' => null,
        ]];
    }

    /**
     * Get the highest of the thresholds (percents), which the used amount reached of the limit, compared exactly,
     * not by a rounded percent, or null.
     *
     * @param string $used
     * @param string $limit
     * @param array<int, int> $thresholds From the highest.
     *
     * @return int|null
     */
    protected function threshold(string $used, string $limit, array $thresholds): ?int
    {
        $limit = Decimal::of($limit);

        if (!$limit->isPositive()) {
            return null;
        }

        foreach ($thresholds as $threshold) {
            if (Decimal::of($used)->multipliedBy(100)->isGreaterThanOrEqualTo($limit->multipliedBy($threshold))) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * Name a quarter in the current language, such as "III кв. 2026" or "Q3 2026".
     *
     * @param int $year
     * @param int $quarter
     *
     * @return string
     */
    protected function quarterLabel(int $year, int $quarter): string
    {
        return __('Q:quarter :year', ['quarter' => $quarter, 'roman' => ['I', 'II', 'III', 'IV'][$quarter - 1], 'year' => $year]);
    }
}
