<?php

namespace PryTax\Services\Payments;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\TaxableBase;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Helpers\Decimal;
use PryTax\Models\Profile;
use PryTax\Services\Rates\NbuRateProvider;
use PryTax\Services\Taxes\TaxRuleResolver;

/**
 * Class PaymentSnapshotter.
 *
 * Computes the snapshot of a receipt (design.md §2.2–2.3): the NBU rate of its currency on `received_at`, with
 * four decimals; `uah_amount = ROUND_HALF_UP(amount × rate, 2)`; the tax rule of the date; and the per-receipt ЄП
 * and ВЗ, `ROUND_HALF_UP(uah_amount × rate, 2)`, which are hints, not the declaration's figures. The taxable base is
 * the invoiced amount, unless the profile chose the received one (at its own risk).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Payments
 */
class PaymentSnapshotter
{
    /**
     * PaymentSnapshotter constructor.
     *
     * @param NbuRateProvider $rates
     * @param TaxRuleResolver $rules
     */
    public function __construct(
        protected NbuRateProvider $rates,
        protected TaxRuleResolver $rules
    ) {}

    /**
     * Compute the snapshot of a receipt.
     *
     * @param Profile $profile
     * @param string $currency Currency of the taxable amount.
     * @param string $amount The invoiced amount.
     * @param string $receivedAmount What landed on the account.
     * @param string $receivedCurrency Currency of the account.
     * @param CarbonInterface $receivedAt
     * @param string|null $manualRate A rate, which the user entered, taken only when the NBU's one is unavailable.
     *
     * @return array{
     *     nbu_rate: string,
     *     nbu_rate_date: string,
     *     exchange_rate_id: int|null,
     *     rate_is_manual: bool,
     *     uah_amount: string,
     *     tax_rule_id: int,
     *     single_tax_uah: string,
     *     military_levy_uah: string,
     *     receipt_rate: string|null,
     * }
     * @throws ValidationException When neither the NBU's rate nor a manual one is available, or no tax rule is.
     */
    public function compute(
        Profile $profile,
        string $currency,
        string $amount,
        string $receivedAmount,
        string $receivedCurrency,
        CarbonInterface $receivedAt,
        ?string $manualRate = null
    ): array {
        [$rate, $exchangeRateId, $isManual] = $this->rateOf($currency, $receivedAt, $manualRate);
        $rule = $this->rules->forDate($receivedAt, 'received_at');

        $uah = $this->uahAmount($profile, $currency, $amount, $receivedAmount, $receivedCurrency, $rate);

        return [
            'nbu_rate' => $rate,
            'nbu_rate_date' => $receivedAt->toDateString(),
            'exchange_rate_id' => $exchangeRateId,
            'rate_is_manual' => $isManual,
            'uah_amount' => Decimal::money($uah),
            'tax_rule_id' => $rule->getKey(),
            'single_tax_uah' => Decimal::money(Decimal::times($uah, $rule->single_tax_rate)),
            'military_levy_uah' => Decimal::money(Decimal::times($uah, $rule->military_levy_rate)),
            'receipt_rate' => $receivedCurrency !== $currency && Decimal::of($amount)->isPositive()
                ? Decimal::rate(Decimal::divide($receivedAmount, $amount, 4))
                : null,
        ];
    }

    /**
     * Get the UAH rate of the currency on the date, the id of its cached row and whether the user entered it: 1 for
     * UAH, else the NBU's, and only when the NBU's is unavailable the one the user entered, which is never cached,
     * so it reaches no other record.
     *
     * @param string $currency
     * @param CarbonInterface $date
     * @param string|null $manualRate
     * @param string $field
     *
     * @return array{0: string, 1: int|null, 2: bool}
     * @throws ValidationException When neither the NBU's rate nor a manual one is available.
     */
    public function rateOf(string $currency, CarbonInterface $date, ?string $manualRate = null, string $field = 'nbu_rate'): array
    {
        if (strtoupper($currency) === 'UAH') {
            return ['1.0000', null, false];
        }

        try {
            $stored = $this->rates->rate($currency, $date);
        } catch (ExchangeRateUnavailableException $exception) {
            if ($manualRate !== null && $manualRate !== '') {
                return [Decimal::rate($manualRate), null, true];
            }

            throw ValidationException::withMessages([$field => [$exception->getMessage()]]);
        }

        return [Decimal::rate($stored->rate), $stored->getKey(), false];
    }

    /**
     * Get the income of a receipt in UAH: the taxable base at the rate.
     *
     * @param Profile $profile
     * @param string $currency
     * @param string $amount
     * @param string $receivedAmount
     * @param string $receivedCurrency
     * @param string $rate
     *
     * @return BigDecimal
     */
    protected function uahAmount(
        Profile $profile,
        string $currency,
        string $amount,
        string $receivedAmount,
        string $receivedCurrency,
        string $rate
    ): BigDecimal {
        if ($profile->setting('income.taxable_base') === TaxableBase::Received->value) {
            if ($receivedCurrency === 'UAH') {
                return Decimal::round($receivedAmount);
            }

            if ($receivedCurrency === $currency) {
                return Decimal::times($receivedAmount, $rate);
            }
        }

        return Decimal::times($amount, $rate);
    }
}
