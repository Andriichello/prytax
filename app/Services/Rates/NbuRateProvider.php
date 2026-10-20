<?php

namespace PryTax\Services\Rates;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Helpers\Decimal;
use PryTax\Models\ExchangeRate;

/**
 * Class NbuRateProvider.
 *
 * The official NBU rate of a currency on a date (п. 292.5), with `exchange_rates` as its cache. The rate is taken
 * from the raw body, since `json_decode` would turn it into a float. UAH needs no rate. On failure it throws, so the
 * form offers a manual rate, rather than using a silent default.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Rates
 */
class NbuRateProvider
{
    /**
     * Endpoint of the NBU's exchange rates.
     *
     * @var string
     */
    public const string URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange';

    /**
     * Get the rate of the currency on the date: the cached one, or else the NBU's, which is cached.
     *
     * @param string $currency
     * @param CarbonInterface $date
     *
     * @return ExchangeRate
     * @throws ExchangeRateUnavailableException
     */
    public function rate(string $currency, CarbonInterface $date): ExchangeRate
    {
        $currency = strtoupper($currency);

        $cached = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', $date->toDateString())
            ->first();

        return $cached ?? $this->fetch($currency, $date);
    }

    /**
     * Get the cached rate of the currency on the date, or null.
     *
     * @param string $currency
     * @param CarbonInterface $date
     *
     * @return ExchangeRate|null
     */
    public function cached(string $currency, CarbonInterface $date): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('currency', strtoupper($currency))
            ->whereDate('rate_date', $date->toDateString())
            ->first();
    }

    /**
     * Fetch the NBU rate of the currency on the date, and cache it.
     *
     * @param string $currency
     * @param CarbonInterface $date
     *
     * @return ExchangeRate
     * @throws ExchangeRateUnavailableException
     */
    public function fetch(string $currency, CarbonInterface $date): ExchangeRate
    {
        $currency = strtoupper($currency);

        try {
            $response = Http::timeout(10)
                ->retry(2, 200, throw: false)
                ->get(static::URL, ['valcode' => $currency, 'date' => $date->format('Ymd'), 'json' => '']);
        } catch (ConnectionException $exception) {
            $response = null;
            Log::warning('The NBU rate could not be fetched.', [
                'currency' => $currency,
                'date' => $date->toDateString(),
                'error' => $exception->getMessage(),
            ]);
        }

        $rate = $response?->successful() ? static::parseRate($response->body()) : null;

        if ($rate === null) {
            Log::warning('The NBU rate is unavailable.', [
                'currency' => $currency,
                'date' => $date->toDateString(),
                'status' => $response?->status(),
            ]);

            throw new ExchangeRateUnavailableException(
                __('The NBU rate of :currency on :date is unavailable. Enter the rate manually.', [
                    'currency' => $currency,
                    'date' => $date->format('d.m.Y'),
                ])
            );
        }

        return ExchangeRate::query()->updateOrCreate(
            ['currency' => $currency, 'rate_date' => $date->toDateString()],
            ['rate' => $rate, 'fetched_at' => now()],
        );
    }

    /**
     * Get the rate from the raw body of the NBU's answer, with four decimals, or null, when it has none.
     *
     * @param string $body
     *
     * @return string|null
     */
    public static function parseRate(string $body): ?string
    {
        if (!preg_match('/"rate"\s*:\s*([0-9]+(?:\.[0-9]+)?)/', $body, $match)) {
            return null;
        }

        $rate = Decimal::of($match[1]);

        return $rate->isPositive() ? Decimal::rate($rate) : null;
    }
}
