<?php

namespace PryTax\Services\Invoices;

use Carbon\CarbonInterface;
use Core\Models\Sequence;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\NumberingDateSource;
use PryTax\Enums\NumberingReset;
use PryTax\Enums\NumberingScope;
use PryTax\Enums\NumberingStrategy;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class InvoiceNumberGenerator.
 *
 * Numbers invoices by the profile's settings (design.md §5): `{YYYY-MM-DD}-{n}`, such as `2026-10-14-13` (the
 * period ending on 14 October, invoice 13 of the client), or `{n}`. The counter is per client or one per profile,
 * restarting never or yearly; counters are api-core sequences of the profile, allocated under a row lock within the
 * freeze's transaction. A draft only previews its number. A re-freeze rebuilds a generated number's date with the
 * same `{n}`, unless the counter's scope changed; a number the user typed is never touched.
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
        if ($profile->setting('invoice.numbering.strategy') === NumberingStrategy::Sequence->value) {
            return (string) $number;
        }

        return $this->dateOf($profile, $invoice)->format('Y-m-d') . '-' . $number;
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

        if ($invoice->sequence_number === null || $invoice->sequence_scope_key !== $scope) {
            $invoice->forceFill([
                'sequence_scope_key' => $scope,
                'sequence_number' => Sequence::next($profile, self::KEY_PREFIX . $scope),
            ]);
        }

        $invoice->number = $this->format($profile, $invoice, $invoice->sequence_number);
    }

    /**
     * Get the profile's counters with their last allocated numbers, and the clients they count for.
     *
     * @param Profile $profile
     *
     * @return Collection<int, array{scope_key: string, last_number: int, client_id: int|null, client_name: string|null}>
     */
    public function counters(Profile $profile): Collection
    {
        $sequences = $profile->sequences()
            ->where('key', 'like', self::KEY_PREFIX . '%')
            ->orderBy('key')
            ->get();

        $clients = Client::query()->get(['id', 'name'])->keyBy('id');

        return $sequences->map(function (Sequence $sequence) use ($clients) {
            $scope = substr($sequence->key, strlen(self::KEY_PREFIX));
            $clientId = preg_match('/^client:(\d+)/', $scope, $match) ? (int) $match[1] : null;

            return [
                'scope_key' => $scope,
                'last_number' => (int) $sequence->next_number - 1,
                'client_id' => $clientId,
                'client_name' => $clientId ? $clients->get($clientId)?->name : null,
            ];
        })->values();
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
