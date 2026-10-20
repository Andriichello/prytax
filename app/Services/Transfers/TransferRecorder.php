<?php

namespace PryTax\Services\Transfers;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\AccountKind;
use PryTax\Enums\FxGainPolicy;
use PryTax\Enums\PaymentKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Payments\PaymentSnapshotter;
use PryTax\Services\Taxes\PeriodLock;
use Throwable;

/**
 * Class TransferRecorder.
 *
 * Records transfers between a profile's own accounts with the receipts they carry (design.md §6.2–6.5). It enforces
 * that a same-currency transfer's fee is `amount_sent − amount_received` (and defaults to it), that the allocations
 * never exceed `amount_sent`, and that what is allocated out of an account for a receipt never exceeds what the
 * receipt holds there, also downstream after an edit. An FX transfer into a ФОП account with a positive difference
 * against the NBU rate creates a synthetic FX gain receipt, when the profile counts FX gains as income.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Transfers
 */
class TransferRecorder
{
    /**
     * TransferRecorder constructor.
     *
     * @param HoldingCalculator $holdings
     * @param PaymentSnapshotter $snapshotter
     * @param PeriodLock $lock
     * @param AlertSync $alerts
     */
    public function __construct(
        protected HoldingCalculator $holdings,
        protected PaymentSnapshotter $snapshotter,
        protected PeriodLock $lock,
        protected AlertSync $alerts
    ) {}

    /**
     * Record a transfer of the validated data.
     *
     * @param Profile $profile
     * @param array $data
     *
     * @return AccountTransfer
     * @throws ValidationException
     * @throws Throwable
     */
    public function record(Profile $profile, array $data): AccountTransfer
    {
        $transfer = new AccountTransfer;
        $transfer->profile_id = $profile->getKey();

        return $this->save($profile, $transfer, $data);
    }

    /**
     * Update the transfer with the validated data; allocations, when given, replace its own.
     *
     * @param Profile $profile
     * @param AccountTransfer $transfer
     * @param array $data
     *
     * @return AccountTransfer
     * @throws ValidationException
     * @throws Throwable
     */
    public function update(Profile $profile, AccountTransfer $transfer, array $data): AccountTransfer
    {
        $data = [...$transfer->only([
            'from_account_id', 'to_account_id', 'amount_sent', 'amount_received', 'sent_at', 'reference', 'note',
        ]), 'received_at' => $transfer->received_at->toDateString(), ...$data];

        if (!array_key_exists('allocations', $data)) {
            $data['allocations'] = $transfer->allocations
                ->map(fn ($allocation) => ['payment_id' => $allocation->payment_id, 'amount' => $allocation->amount])
                ->all();
        }

        if (!array_key_exists('fee_amount', $data) && $transfer->isFx()) {
            $data['fee_amount'] = $transfer->fee_amount;
        }

        // A manual rate stays, while the NBU's is still unavailable
        if (!array_key_exists('nbu_rate', $data) && $transfer->rate_is_manual) {
            $data['nbu_rate'] = $transfer->nbu_rate;
        }

        return $this->save($profile, $transfer, $data);
    }

    /**
     * Delete the transfer, unless the money it brought has moved on.
     *
     * @param Profile $profile
     * @param AccountTransfer $transfer
     * @param string|null $correctionNote
     *
     * @return void
     * @throws ValidationException
     * @throws Throwable
     */
    public function delete(Profile $profile, AccountTransfer $transfer, ?string $correctionNote = null): void
    {
        $gain = Payment::query()->where('source_transfer_id', $transfer->getKey())->first();

        if ($gain) {
            $this->lock->assertOpen($profile, $gain->received_at, $correctionNote);
        }

        DB::transaction(function () use ($transfer) {
            $payments = $transfer->allocations()->pluck('payment_id')->all();

            $transfer->delete();

            $this->assertHoldings($payments);
        });

        $this->alerts->syncAfterCommit($profile);
    }

    /**
     * Fill the transfer with the data, check its fee and allocations, and save it with them.
     *
     * @param Profile $profile
     * @param AccountTransfer $transfer
     * @param array $data
     *
     * @return AccountTransfer
     * @throws ValidationException
     * @throws Throwable
     */
    protected function save(Profile $profile, AccountTransfer $transfer, array $data): AccountTransfer
    {
        $from = Account::query()->find($data['from_account_id']);
        $to = Account::query()->find($data['to_account_id']);

        if (!$from || !$to || $from->is($to)) {
            throw ValidationException::withMessages([
                'to_account_id' => [__('A transfer moves money between two different accounts of the profile.')],
            ]);
        }

        $sent = Decimal::of($data['amount_sent']);
        $received = Decimal::of($data['amount_received']);
        $receivedAt = CarbonImmutable::parse($data['received_at']);
        $isFx = $from->currency !== $to->currency;

        $fee = $this->fee($isFx, $sent, $received, $data['fee_amount'] ?? null);
        [$nbuRate, $rateIsManual] = $isFx
            ? $this->nbuRate($from->currency, $to->currency, $receivedAt, $data['nbu_rate'] ?? null)
            : [null, false];

        $allocations = $this->allocations($data['allocations'] ?? [], $sent);

        $transfer->fill([
            'from_account_id' => $from->getKey(),
            'to_account_id' => $to->getKey(),
            'amount_sent' => Decimal::money($sent),
            'amount_received' => Decimal::money($received),
            'fee_amount' => Decimal::moneyOrNull($fee),
            'sent_at' => $data['sent_at'] ?? null,
            'received_at' => $receivedAt->toDateString(),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
        $transfer->forceFill([
            'currency_sent' => $from->currency,
            'currency_received' => $to->currency,
            'fee_currency' => $fee === null ? null : $from->currency,
            'nbu_rate' => $nbuRate,
            'rate_is_manual' => $rateIsManual,
        ]);

        DB::transaction(function () use ($profile, $transfer, $allocations, $from, $data) {
            $previous = $transfer->exists ? $transfer->allocations()->pluck('payment_id')->all() : [];

            $transfer->save();

            foreach ($allocations as $paymentId => $amount) {
                $payment = Payment::query()->find($paymentId);

                if (!$payment) {
                    throw ValidationException::withMessages(['allocations' => [__('A receipt is not found.')]]);
                }

                $holding = $this->holdings->holding($payment, $from, $transfer->getKey());

                if (Decimal::of($amount)->isGreaterThan($holding)) {
                    throw ValidationException::withMessages([
                        'allocations' => [__('The receipt holds only :holding :currency on :account.', [
                            'holding' => Decimal::money($holding),
                            'currency' => $from->currency,
                            'account' => $from->name,
                        ])],
                    ]);
                }
            }

            $transfer->allocations()->delete();

            foreach ($allocations as $paymentId => $amount) {
                $transfer->allocations()->create(['payment_id' => $paymentId, 'amount' => $amount]);
            }

            $this->assertHoldings([...$previous, ...array_keys($allocations)]);
            $this->syncFxGain($profile, $transfer->refresh(), $data['correction_note'] ?? null);
        });

        $this->alerts->syncAfterCommit($profile);

        return $transfer->refresh()->load('allocations');
    }

    /**
     * Get the transfer's fee: `amount_sent − amount_received` for a same-currency transfer, which a given fee must
     * equal, or the explicit one of an FX transfer.
     *
     * @param bool $isFx
     * @param BigDecimal $sent
     * @param BigDecimal $received
     * @param string|null $given
     *
     * @return BigDecimal|null
     * @throws ValidationException
     */
    protected function fee(bool $isFx, $sent, $received, ?string $given): ?BigDecimal
    {
        if ($isFx) {
            return $given === null || $given === '' ? null : Decimal::of($given);
        }

        $fee = $sent->minus($received);

        if ($fee->isNegative()) {
            throw ValidationException::withMessages([
                'amount_received' => [__('More can\'t arrive than was sent in the same currency.')],
            ]);
        }

        if ($given !== null && $given !== '' && !Decimal::of($given)->isEqualTo($fee)) {
            throw ValidationException::withMessages([
                'fee_amount' => [__('The fee of a same-currency transfer is the amount sent less the amount received: :fee.', [
                    'fee' => Decimal::money($fee),
                ])],
            ]);
        }

        return $fee;
    }

    /**
     * Get the NBU rate of the UAH leg of an FX transfer on its date and whether the user entered it, or no rate for a
     * transfer between foreign currencies. Without the NBU's rate a manual one is required, since the spread and an
     * FX gain depend on it.
     *
     * @param string $sent
     * @param string $received
     * @param CarbonImmutable $date
     * @param string|null $manualRate
     *
     * @return array{0: string|null, 1: bool}
     * @throws ValidationException When neither the NBU's rate nor a manual one is available.
     */
    protected function nbuRate(string $sent, string $received, CarbonImmutable $date, ?string $manualRate): array
    {
        $foreign = match (true) {
            $received === 'UAH' => $sent,
            $sent === 'UAH' => $received,
            default => null,
        };

        if ($foreign === null) {
            return [null, false];
        }

        [$rate, , $isManual] = $this->snapshotter->rateOf($foreign, $date, $manualRate);

        return [$rate, $isManual];
    }

    /**
     * Get the allocations of the data by id of the receipt, making sure each receipt is allocated once and they
     * don't exceed the amount sent.
     *
     * @param array<int, array{payment_id: int, amount: string}> $allocations
     * @param BigDecimal $sent
     *
     * @return array<int, string>
     * @throws ValidationException
     */
    protected function allocations(array $allocations, $sent): array
    {
        $byPayment = [];

        foreach ($allocations as $allocation) {
            $id = (int) $allocation['payment_id'];
            $amount = Decimal::of($allocation['amount']);

            if (!$amount->isPositive()) {
                continue;
            }

            if (isset($byPayment[$id])) {
                throw ValidationException::withMessages(['allocations' => [__('A receipt is allocated twice.')]]);
            }

            $byPayment[$id] = Decimal::money($amount);
        }

        if (Decimal::sum($byPayment)->isGreaterThan($sent)) {
            throw ValidationException::withMessages([
                'allocations' => [__('The allocations exceed the amount sent.')],
            ]);
        }

        return $byPayment;
    }

    /**
     * Make sure no receipt holds a negative amount on any account: what was moved on from an account can't exceed
     * what arrived there.
     *
     * @param array<int, int> $paymentIds
     *
     * @return void
     * @throws ValidationException
     */
    protected function assertHoldings(array $paymentIds): void
    {
        $payments = Payment::query()->whereKey(array_unique($paymentIds))->with('allocations.transfer')->get();

        foreach ($payments as $payment) {
            foreach ($this->holdings->holdingsOf($payment) as $amount) {
                if ($amount->isNegative()) {
                    throw ValidationException::withMessages([
                        'allocations' => [__('Money of a receipt of :date was moved on from an account, to which less would arrive.', [
                            'date' => $payment->received_at->format('d.m.Y'),
                        ])],
                    ]);
                }
            }
        }
    }

    /**
     * Create, update or delete the synthetic FX gain receipt of the transfer (design.md §6.5): when the profile
     * counts FX gains, the transfer sells currency into a ФОП account, and less is spread than nothing, the gain is
     * `−fx_spread` in UAH, dated on the transfer's `received_at`.
     *
     * @param Profile $profile
     * @param AccountTransfer $transfer
     * @param string|null $correctionNote
     *
     * @return void
     * @throws ValidationException
     */
    protected function syncFxGain(Profile $profile, AccountTransfer $transfer, ?string $correctionNote): void
    {
        $existing = Payment::query()->where('source_transfer_id', $transfer->getKey())->first();
        $spread = TransferMetrics::spread($transfer);

        $gain = $profile->setting('income.fx_gain') === FxGainPolicy::Include->value
            && $transfer->currency_received === 'UAH'
            && $transfer->toAccount->kind === AccountKind::FopBank
            && $spread !== null
            && $spread->isNegative()
                ? $spread->negated()
                : null;

        if ($gain === null) {
            if ($existing) {
                $this->lock->assertOpen($profile, $existing->received_at, $correctionNote);
                $existing->delete();
            }

            return;
        }

        $this->lock->assertOpen($profile, $transfer->received_at, $correctionNote);

        $snapshot = $this->snapshotter->compute(
            $profile,
            'UAH',
            Decimal::money($gain),
            '0.00',
            'UAH',
            $transfer->received_at,
        );

        $payment = $existing ?? new Payment;
        $payment->forceFill([
            'profile_id' => $profile->getKey(),
            'kind' => PaymentKind::FxGain,
            'description' => __('FX gain of the transfer of :date', ['date' => $transfer->received_at->format('d.m.Y')]),
            'currency' => 'UAH',
            'amount' => Decimal::money($gain),
            'received_to_account_id' => $transfer->to_account_id,
            'received_amount' => '0.00',
            'received_currency' => 'UAH',
            'received_at' => $transfer->received_at->toDateString(),
            'source_transfer_id' => $transfer->getKey(),
            ...$snapshot,
        ])->save();
    }
}
