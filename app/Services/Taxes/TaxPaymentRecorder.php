<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class TaxPaymentRecorder.
 *
 * Records payments of taxes (design.md §8.4) in one of three allocations: to a receipt, whose quarter and month it
 * takes, and whose own ЄП or ВЗ it pays when no amount is given; to a month, whose quarter it computes; or to a quarter
 * (left for imported history). The period columns are always filled. ЄСВ isn't a tax of a receipt, so it is allocated
 * to months or quarters only. A receipt has one ЄП and one ВЗ payment, which hasn't failed. A payment from a
 * foreign-currency account keeps what left it, which the bank sold at its own rate.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class TaxPaymentRecorder
{
    /**
     * TaxPaymentRecorder constructor.
     *
     * @param AlertSync $alerts
     */
    public function __construct(
        protected AlertSync $alerts
    ) {}

    /**
     * Record a tax payment of the validated data.
     *
     * @param Profile $profile
     * @param array $data
     *
     * @return TaxPayment
     * @throws ValidationException
     */
    public function record(Profile $profile, array $data): TaxPayment
    {
        $payment = new TaxPayment;
        $payment->profile_id = $profile->getKey();

        return $this->save($profile, $payment, $data);
    }

    /**
     * Update the tax payment with the validated data.
     *
     * @param Profile $profile
     * @param TaxPayment $payment
     * @param array $data
     *
     * @return TaxPayment
     * @throws ValidationException
     */
    public function update(Profile $profile, TaxPayment $payment, array $data): TaxPayment
    {
        return $this->save($profile, $payment, [...$payment->only([
            'tax_type', 'allocation', 'payment_id', 'period_year', 'period_quarter', 'period_month', 'status',
            'paid_from_account_id', 'original_amount',
        ]), 'paid_at' => $payment->paid_at?->toDateString(), ...$data]);
    }

    /**
     * Delete the tax payment.
     *
     * @param Profile $profile
     * @param TaxPayment $payment
     *
     * @return void
     */
    public function delete(Profile $profile, TaxPayment $payment): void
    {
        DB::transaction(fn () => $payment->delete());

        $this->alerts->syncAfterCommit($profile);
    }

    /**
     * Fill the tax payment with the data, with its period derived from the allocation, and save it.
     *
     * @param Profile $profile
     * @param TaxPayment $payment
     * @param array $data
     *
     * @return TaxPayment
     * @throws ValidationException
     */
    protected function save(Profile $profile, TaxPayment $payment, array $data): TaxPayment
    {
        $type = $data['tax_type'] instanceof TaxType ? $data['tax_type'] : TaxType::from($data['tax_type']);
        $allocation = $data['allocation'] instanceof TaxAllocation
            ? $data['allocation']
            : TaxAllocation::from($data['allocation']);

        $data = [...$data, ...$this->period($type, $allocation, $data)];
        $data = [...$data, ...$this->amountOfReceipt($payment, $type, $data), ...$this->original($data)];
        $this->assertDate($data);

        $payment->fill(Arr::only($data, $payment->getFillable()));
        $this->assertOnePerReceipt($payment);

        DB::transaction(fn () => $payment->save());

        $this->alerts->syncAfterCommit($profile);

        return $payment;
    }

    /**
     * Make sure a sent or paid tax payment has its date, which the account's balance takes it on, and that a paid
     * one isn't dated in the future.
     *
     * @param array $data
     *
     * @return void
     * @throws ValidationException
     */
    protected function assertDate(array $data): void
    {
        $status = $data['status'] ?? TaxPaymentStatus::Sent;
        $status = $status instanceof TaxPaymentStatus ? $status : TaxPaymentStatus::from($status);

        if ($status === TaxPaymentStatus::Failed) {
            return;
        }

        if (empty($data['paid_at'])) {
            throw ValidationException::withMessages(['paid_at' => [__('The date of the payment is required.')]]);
        }

        if ($status === TaxPaymentStatus::Paid && CarbonImmutable::parse($data['paid_at'])->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['paid_at' => [__('A paid tax payment can\'t be dated in the future.')]]);
        }
    }

    /**
     * Get the amount of a payment of a receipt's ЄП or ВЗ: the receipt's own one, unless the code gives one (the API
     * refuses a typed one), and again when the payment moves to another receipt or tax. A saved payment otherwise keeps
     * its amount, when the receipt changes later; the difference shows in the reconciliation.
     *
     * @param TaxPayment $payment
     * @param TaxType $type
     * @param array $data
     *
     * @return array{amount?: string}
     */
    protected function amountOfReceipt(TaxPayment $payment, TaxType $type, array $data): array
    {
        if ($data['payment_id'] === null || $type === TaxType::SocialContribution) {
            return [];
        }

        $moved = $payment->exists && ($payment->payment_id !== $data['payment_id'] || $payment->tax_type !== $type);

        // An amount given by the code (an import, the demo), or the one the payment keeps
        if (!$moved && (!empty($data['amount']) || $payment->exists)) {
            return [];
        }

        $receipt = Payment::query()->findOrFail($data['payment_id']);

        return ['amount' => $type === TaxType::SingleTax ? $receipt->single_tax_uah : $receipt->military_levy_uah];
    }

    /**
     * Get what left the account of the payment: the amount in the account's currency, required from a foreign-currency
     * account, whose bank sold it at its own rate, and none from a UAH account.
     *
     * @param array $data
     *
     * @return array{original_amount: string|null, original_currency: string|null}
     * @throws ValidationException
     */
    protected function original(array $data): array
    {
        $account = empty($data['paid_from_account_id']) ? null : Account::query()->find($data['paid_from_account_id']);

        if ($account === null || $account->currency === 'UAH') {
            return ['original_amount' => null, 'original_currency' => null];
        }

        if (empty($data['original_amount'])) {
            throw ValidationException::withMessages([
                'original_amount' => [__('Enter the amount in :currency, which left the account.', ['currency' => $account->currency])],
            ]);
        }

        return ['original_amount' => Decimal::money($data['original_amount']), 'original_currency' => $account->currency];
    }

    /**
     * Make sure the payment is the only one of its receipt's tax, which hasn't failed.
     *
     * @param TaxPayment $payment
     *
     * @return void
     * @throws ValidationException
     */
    protected function assertOnePerReceipt(TaxPayment $payment): void
    {
        if ($payment->payment_id === null || $payment->status === TaxPaymentStatus::Failed) {
            return;
        }

        $other = TaxPayment::query()
            ->where('payment_id', $payment->payment_id)
            ->where('tax_type', $payment->tax_type->value)
            ->where('status', '<>', TaxPaymentStatus::Failed->value)
            ->when($payment->exists, fn ($query) => $query->whereKeyNot($payment->getKey()))
            ->exists();

        if ($other) {
            throw ValidationException::withMessages([
                'payment_id' => [__('This tax of the receipt is paid already: change that payment instead.')],
            ]);
        }
    }

    /**
     * Get the period columns of the allocation: a receipt's quarter and month, a month and its quarter, or a
     * quarter without a month.
     *
     * @param TaxType $type
     * @param TaxAllocation $allocation
     * @param array $data
     *
     * @return array{payment_id: int|null, period_year: int, period_quarter: int, period_month: int|null}
     * @throws ValidationException
     */
    protected function period(TaxType $type, TaxAllocation $allocation, array $data): array
    {
        if ($allocation === TaxAllocation::Payment) {
            if ($type === TaxType::SocialContribution) {
                throw ValidationException::withMessages([
                    'allocation' => [__('ЄСВ is allocated to a month or a quarter, not to a receipt.')],
                ]);
            }

            $receipt = Payment::query()->find($data['payment_id'] ?? null);

            if (!$receipt) {
                throw ValidationException::withMessages(['payment_id' => [__('The receipt is not found.')]]);
            }

            return [
                'payment_id' => $receipt->getKey(),
                'period_year' => $receipt->period_year,
                'period_quarter' => $receipt->period_quarter,
                'period_month' => $receipt->period_month,
            ];
        }

        if (empty($data['period_year'])) {
            throw ValidationException::withMessages(['period_year' => [__('The year is required.')]]);
        }

        if ($allocation === TaxAllocation::Month) {
            $month = (int) ($data['period_month'] ?? 0);

            if ($month < 1 || $month > 12) {
                throw ValidationException::withMessages(['period_month' => [__('The month is required.')]]);
            }

            return [
                'payment_id' => null,
                'period_year' => (int) $data['period_year'],
                'period_quarter' => intdiv($month - 1, 3) + 1,
                'period_month' => $month,
            ];
        }

        $quarter = (int) ($data['period_quarter'] ?? 0);

        if ($quarter < 1 || $quarter > 4) {
            throw ValidationException::withMessages(['period_quarter' => [__('The quarter is required.')]]);
        }

        return [
            'payment_id' => null,
            'period_year' => (int) $data['period_year'],
            'period_quarter' => $quarter,
            'period_month' => null,
        ];
    }
}
