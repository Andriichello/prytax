<?php

namespace PryTax\Services\Invoices;

use Carbon\CarbonInterface;
use Core\Models\Sequence;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\NumberingDateSource;
use PryTax\Enums\NumberingPrefix;
use PryTax\Enums\NumberingReset;
use PryTax\Enums\NumberingScope;
use PryTax\Enums\NumberingStrategy;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class InvoiceNumberGenerator.
 *
 * Numbers invoices by the profile's settings (design.md §5): `{ABBREVIATION}-{YYYY-MM-DD}-{n}` by default, such as
 * `BL-2026-10-14-13` (Brightline Labs' invoice 13, for the period ending on 14 October), or `{n}`, each with the
 * client's abbreviation in front or without it. The counter is per client (by default) or one per profile, restarting
 * never or yearly; counters are api-core sequences of the profile, keyed by the client's id (so a changed abbreviation
 * keeps its counter), allocated under a row lock within the freeze's transaction. A draft only previews its number. A
 * re-freeze rebuilds a generated number's abbreviation and date with the same `{n}`, unless the counter's scope
 * changed; a number the user typed is never touched.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoiceNumberGenerator
{
    /**
     * Prefix of the keys of the profile's sequences, which number invoices.
     *
     * @var string
     */
    public const string KEY_PREFIX = 'invoices:';

    /**
     * Get the scope of the invoice's counter: `global` or `client:{id}`, with `:{year}` when it restarts yearly.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return string
     */
    public function scopeKey(Profile $profile, Invoice $invoice): string
    {
        $scope = $profile->setting('invoice.numbering.scope') === NumberingScope::Global->value
            ? 'global'
            : 'client:' . $invoice->client_id;

        if ($profile->setting('invoice.numbering.reset') === NumberingReset::Yearly->value) {
            $scope .= ':' . $this->dateOf($profile, $invoice)->year;
        }

        return $scope;
    }

    /**
     * Get the date, which the number starts with: the end of the period, or the issue date.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return CarbonInterface
     */
    public function dateOf(Profile $profile, Invoice $invoice): CarbonInterface
    {
        return $profile->setting('invoice.numbering.date_source') === NumberingDateSource::IssuedAt->value
            ? $invoice->issued_at
            : $invoice->period_end;
    }

    /**
     * Format the number of the invoice with the counter's value.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param int $number
     *
     * @return string
     */
    public function format(Profile $profile, Invoice $invoice, int $number): string
    {
        $formatted = $profile->setting('invoice.numbering.strategy') === NumberingStrategy::Sequence->value
            ? (string) $number
            : $this->dateOf($profile, $invoice)->format('Y-m-d') . '-' . $number;

        return $this->prefixOf($profile, $invoice) . $formatted;
    }

    /**
     * Get what the invoice's number starts with: the abbreviation of its client and a hyphen (`BL-`), as it is now,
     * or nothing, when numbers have no prefix.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return string
     */
    public function prefixOf(Profile $profile, Invoice $invoice): string
    {
        if ($profile->setting('invoice.numbering.prefix') === NumberingPrefix::None->value) {
            return '';
        }

        // A draft, whose client changed, still holds the client it was loaded with
        if ($invoice->relationLoaded('client') && $invoice->client?->getKey() !== $invoice->client_id) {
            $invoice->unsetRelation('client');
        }

        $abbreviation = $invoice->client?->abbreviation;

        return filled($abbreviation) ? $abbreviation . '-' : '';
    }

    /**
     * Determine if the invoice's number is one the user typed, which the generator never changes.
     *
     * @param Invoice $invoice
     *
     * @return bool
     */
    public function isTyped(Invoice $invoice): bool
    {
        return $invoice->number !== null && $invoice->sequence_number === null;
    }

    /**
     * Get the number the invoice would get, if it were frozen now, without allocating it.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return string
     */
    public function preview(Profile $profile, Invoice $invoice): string
    {
        if ($this->isTyped($invoice)) {
            return $invoice->number;
        }

        $scope = $this->scopeKey($profile, $invoice);

        if ($invoice->sequence_number !== null && $invoice->sequence_scope_key === $scope) {
            return $this->format($profile, $invoice, $invoice->sequence_number);
        }

        return $this->format($profile, $invoice, Sequence::peek($profile, self::KEY_PREFIX . $scope));
    }

    /**
     * Give the invoice its number, within the caller's transaction: keep a typed one, rebuild a generated one with
     * its `{n}` when its scope is the same, or else allocate the next one of the scope (the old one stays a gap).
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return void
     */
    public function assign(Profile $profile, Invoice $invoice): void
    {
        if ($this->isTyped($invoice)) {
            return;
        }

        $scope = $this->scopeKey($profile, $invoice);

        // The client's abbreviation as it is now, rather than as the invoice was loaded with
        $invoice->load('client');

        if ($invoice->sequence_number === null || $invoice->sequence_scope_key !== $scope) {
            $invoice->forceFill([
                'sequence_scope_key' => $scope,
                'sequence_number' => Sequence::next($profile, self::KEY_PREFIX . $scope),
            ]);
        }

        $invoice->number = $this->format($profile, $invoice, $invoice->sequence_number);
    }

    /**
     * Get the profile's counters with their last allocated numbers, and the clients they count for: the ones that
     * numbered invoices, and those of the numbering as it is now (each active client's, or the profile's one, this
     * year's when it restarts yearly), at 0 until their first invoice, so they can be set before it.
     *
     * @param Profile $profile
     *
     * @return Collection<int, array{scope_key: string, last_number: int, client_id: int|null, client_name: string|null, client_abbreviation: string|null}>
     */
    public function counters(Profile $profile): Collection
    {
        $clients = Client::query()->get(['id', 'name', 'abbreviation', 'is_active'])->keyBy('id');

        $nextNumbers = $profile->sequences()
            ->where('key', 'like', self::KEY_PREFIX . '%')
            ->get()
            ->mapWithKeys(fn (Sequence $sequence) => [substr($sequence->key, strlen(self::KEY_PREFIX)) => (int) $sequence->next_number])
            ->all();

        foreach ($this->currentScopes($profile, $clients) as $scope) {
            $nextNumbers[$scope] ??= 1;
        }

        ksort($nextNumbers, SORT_NATURAL);

        return collect($nextNumbers)->map(function (int $next, string $scope) use ($clients) {
            $clientId = preg_match('/^client:(\d+)/', $scope, $match) ? (int) $match[1] : null;

            return [
                'scope_key' => $scope,
                'last_number' => $next - 1,
                'client_id' => $clientId,
                'client_name' => $clientId ? $clients->get($clientId)?->name : null,
                'client_abbreviation' => $clientId ? $clients->get($clientId)?->abbreviation : null,
            ];
        })->values();
    }

    /**
     * Get the scopes of the counters, which the numbering as it is now counts with: each active client's, or the
     * profile's one, with this year when they restart yearly.
     *
     * @param Profile $profile
     * @param Collection<int, Client> $clients
     *
     * @return array<int, string>
     */
    protected function currentScopes(Profile $profile, Collection $clients): array
    {
        $year = $profile->setting('invoice.numbering.reset') === NumberingReset::Yearly->value ? ':' . now()->year : '';

        if ($profile->setting('invoice.numbering.scope') === NumberingScope::Global->value) {
            return ['global' . $year];
        }

        return $clients->where('is_active', true)
            ->map(fn (Client $client) => 'client:' . $client->getKey() . $year)
            ->values()
            ->all();
    }

    /**
     * Move a counter of the profile forward, such as after importing invoices numbered elsewhere: the next number it
     * allocates. A counter never goes back, so no number is given twice.
     *
     * @param Profile $profile
     * @param string $scope Such as `global`, `global:2027`, `client:5` or `client:5:2027`.
     * @param int $next
     *
     * @return void
     * @throws ValidationException
     */
    public function setNext(Profile $profile, string $scope, int $next): void
    {
        if (!preg_match('/^(global|client:(\d+))(:\d{4})?$/', $scope, $match)) {
            throw ValidationException::withMessages(['scope_key' => [__('A counter is global or client:{id}, with :{year} when it restarts yearly.')]]);
        }

        if (!empty($match[2]) && !Client::query()->whereKey((int) $match[2])->exists()) {
            throw ValidationException::withMessages(['scope_key' => [__('The client of the counter is not found.')]]);
        }

        $current = Sequence::peek($profile, self::KEY_PREFIX . $scope);

        if ($next < $current) {
            throw ValidationException::withMessages(['next_number' => [__('The counter is at :current already, and never goes back.', ['current' => $current])]]);
        }

        Sequence::setNext($profile, self::KEY_PREFIX . $scope, $next);
    }
}
