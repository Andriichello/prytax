<?php

namespace PryTax\Backups;

use Carbon\CarbonImmutable;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\DataLifecycle\Contracts\ReferenceResolver;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Services\Rates\NbuRateProvider;

/**
 * Class NbuReferenceResolver.
 *
 * The reference rows, which an archive may reference and this database lack: `exchange_rates` is a cache of the NBU's
 * rates, so a new installation (the desktop app's, say) has none of an archive's past days, and the rate is fetched
 * again from the NBU, which it came from. Tax rules come with the migrations, and users map to nobody, so nothing else
 * is made.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
class NbuReferenceResolver implements ReferenceResolver
{
    /**
     * NbuReferenceResolver constructor.
     *
     * @param NbuRateProvider $rates
     */
    public function __construct(protected NbuRateProvider $rates) {}

    /**
     * Get the id of the reference row, which matches the archive's row, found or made, or null, when it can't be.
     *
     * @param string $table
     * @param array<string, string|null> $row
     *
     * @return int|null
     * @throws ImportException When the NBU's rate can't be fetched.
     */
    public function resolve(string $table, array $row): ?int
    {
        if ($table !== 'exchange_rates' || ($row['currency'] ?? '') === '' || ($row['rate_date'] ?? '') === '') {
            return null;
        }

        try {
            return $this->rates->rate((string) $row['currency'], CarbonImmutable::parse((string) $row['rate_date']))->id;
        } catch (ExchangeRateUnavailableException) {
            throw new ImportException(__('The NBU rate of :currency on :date, which the archive references, couldn\'t be fetched. Connect to the internet, and try again.', [
                'currency' => $row['currency'],
                'date' => CarbonImmutable::parse((string) $row['rate_date'])->format('d.m.Y'),
            ]));
        }
    }
}
