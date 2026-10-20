<?php

namespace PryTax\Services\Transfers;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use PryTax\Helpers\Decimal;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Profile;
use PryTax\Services\Rates\NbuRateProvider;

/**
 * Class TransferMetrics.
 *
 * Fees and FX spread of transfers (design.md §6.4). Loss is the sum of the explicit fees, never "invoiced − landed".
 * The spread of an FX transfer is the gap beyond its explicit fee (accounting.md: "the rest of the gap is spread"),
 * measured on one date, so NBU drift between the receipt and the withdrawal is left out:
 * `fx_spread = (amount_sent − fee_amount) × NBU(currency_sent, received_at) − amount_received` on the UAH leg; a
 * negative one is a gain. Fees are in UAH at the transfer's NBU rate, or at the cached one of its date; a fee, whose
 * rate isn't cached, is unknown, never a call to the NBU while reading.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Transfers
 */
class TransferMetrics
{
    /**
     * TransferMetrics constructor.
     *
     * @param NbuRateProvider $rates
     */
    public function __construct(
        protected NbuRateProvider $rates
    ) {}

    /**
     * Get the FX spread of the transfer in UAH, or null, when it isn't an FX transfer with a UAH leg and a rate.
     *
     * @param AccountTransfer $transfer
     *
     * @return BigDecimal|null
     */
    public static function spread(AccountTransfer $transfer): ?BigDecimal
    {
        if (!$transfer->isFx() || $transfer->nbu_rate === null) {
            return null;
        }

        // The fee is in the sent currency and isn't converted, so only the rest is sold or bought
        $converted = Decimal::of($transfer->amount_sent)->minus(Decimal::of($transfer->fee_amount));

        if ($transfer->currency_received === 'UAH') {
            return Decimal::times($converted, $transfer->nbu_rate)
                ->minus(Decimal::of($transfer->amount_received));
        }

        if ($transfer->currency_sent === 'UAH') {
            return $converted->minus(Decimal::times($transfer->amount_received, $transfer->nbu_rate));
        }

        return null;
    }

    /**
     * Get the effective rate of an FX transfer: `amount_received / amount_sent`, with four decimals.
     *
     * @param AccountTransfer $transfer
     *
     * @return string|null
     */
    public static function effectiveRate(AccountTransfer $transfer): ?string
    {
        return $transfer->isFx() ? Decimal::rate(Decimal::divide($transfer->amount_received, $transfer->amount_sent, 4)) : null;
    }

    /**
     * Get the fee of the transfer in UAH: at the transfer's NBU rate, when it has a UAH leg, else at the cached NBU
     * rate of its currency on `received_at`, or null, when that isn't cached.
     *
     * @param AccountTransfer $transfer
     *
     * @return BigDecimal|null
     */
    public function feeInUah(AccountTransfer $transfer): ?BigDecimal
    {
        if ($transfer->fee_amount === null || $transfer->fee_currency === null) {
            return Decimal::zero();
        }

        if ($transfer->fee_currency === 'UAH') {
            return Decimal::of($transfer->fee_amount);
        }

        if ($transfer->nbu_rate !== null && $transfer->currency_received === 'UAH') {
            return Decimal::times($transfer->fee_amount, $transfer->nbu_rate);
        }

        $rate = $this->rates->cached($transfer->fee_currency, $transfer->received_at)?->rate;

        return $rate === null ? null : Decimal::times($transfer->fee_amount, $rate);
    }

    /**
     * Get the year's losses of the profile's transfers: fees and spread in UAH, and each route (from → to) with its
     * share of loss in the amount sent.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array{fees_uah: string, spread_uah: string, unknown_fees_count: int, routes: array<int, array<string, mixed>>}
     */
    public function forYear(Profile $profile, int $year): array
    {
        /** @var Collection<int, AccountTransfer> $transfers */
        $transfers = AccountTransfer::query()
            ->where('profile_id', $profile->getKey())
            ->whereYear('received_at', $year)
            ->with(['fromAccount', 'toAccount'])
            ->get();

        $fees = Decimal::zero();
        $spread = Decimal::zero();
        $routes = [];
        $unknown = 0;

        foreach ($transfers as $transfer) {
            $fee = $this->feeInUah($transfer);
            $unknown += $fee === null ? 1 : 0;
            $fee ??= Decimal::zero();
            $transferSpread = static::spread($transfer) ?? Decimal::zero();
            $fees = $fees->plus($fee);
            $spread = $spread->plus($transferSpread);

            $key = $transfer->from_account_id . '-' . $transfer->to_account_id;
            $route = $routes[$key] ?? [
                'from_account_id' => $transfer->from_account_id,
                'from_account_name' => $transfer->fromAccount->name,
                'to_account_id' => $transfer->to_account_id,
                'to_account_name' => $transfer->toAccount->name,
                'currency_sent' => $transfer->currency_sent,
                'currency_received' => $transfer->currency_received,
                'count' => 0,
                'sent' => Decimal::zero(),
                'fees' => Decimal::zero(),
                'fees_uah' => Decimal::zero(),
                'spread_uah' => Decimal::zero(),
                'sent_uah' => Decimal::zero(),
            ];

            $route['count']++;
            $route['sent'] = $route['sent']->plus(Decimal::of($transfer->amount_sent));
            $route['fees'] = $route['fees']->plus(Decimal::of($transfer->fee_amount));
            $route['fees_uah'] = $route['fees_uah']->plus($fee);
            $route['spread_uah'] = $route['spread_uah']->plus($transferSpread);

            if ($transfer->nbu_rate !== null && $transfer->currency_received === 'UAH') {
                $route['sent_uah'] = $route['sent_uah']->plus(Decimal::times($transfer->amount_sent, $transfer->nbu_rate));
            }

            $routes[$key] = $route;
        }

        return [
            'fees_uah' => Decimal::money($fees),
            'spread_uah' => Decimal::money($spread),
            // Transfers, whose fees aren't in fees_uah, since the NBU rate of their currency isn't cached
            'unknown_fees_count' => $unknown,
            'routes' => array_values(array_map(fn (array $route) => [
                ...$route,
                'sent' => Decimal::money($route['sent']),
                'fees' => Decimal::money($route['fees']),
                'fees_uah' => Decimal::money($route['fees_uah']),
                'spread_uah' => Decimal::money($route['spread_uah']),
                'sent_uah' => Decimal::money($route['sent_uah']),
                // An FX route loses its fees and its spread against the NBU, the others their fees only
                'loss_percent' => $route['sent_uah']->isPositive()
                    ? Decimal::percent($route['spread_uah']->plus($route['fees_uah']), $route['sent_uah'], 2)
                    : Decimal::percent($route['fees'], $route['sent'], 2),
            ], $routes)),
        ];
    }
}
