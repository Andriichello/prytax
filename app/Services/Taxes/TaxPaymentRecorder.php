<?php

namespace PryTax\Services\Taxes;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxType;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class TaxPaymentRecorder.
 *
 * Records payments of taxes in one of three allocations (design.md §3, tax_payments): to a receipt, whose quarter
 * and month it takes; to a month, whose quarter it computes; or to a quarter. The period columns are always filled.
 * ЄСВ isn't a tax of a receipt, so it is allocated to months or quarters only.
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
            'tax_type', 'allocation', 'payment_id', 'period_year', 'period_quarter', 'period_month',
        ]), ...$data]);
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

        $payment->fill(Arr::only($data, $payment->getFillable()));

        DB::transaction(fn () => $payment->save());

        $this->alerts->syncAfterCommit($profile);

        return $payment;
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
