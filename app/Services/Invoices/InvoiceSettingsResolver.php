<?php

namespace PryTax\Services\Invoices;

use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\SettingSource;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class InvoiceSettingsResolver.
 *
 * Resolves the settings of an invoice's document (design.md §4.1): its language, payment purpose template, act note
 * (on or off, and its text) and contract section. Each is the invoice's own, else the client's, else the profile's,
 * else the built-in one; null at a level takes the next. The profile's language is its document language
 * (`profiles.locale`); the others are its `invoice.*` settings. The built-in texts are in the resolved language. A
 * draft reads them live; the freeze writes them into the snapshot, so a later change never alters an issued document.
 * The currency is resolved once, when an invoice is created, and stored on it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoiceSettingsResolver
{
    /**
     * Get the settings of the invoice, each with the level it comes from.
     *
     * @param Invoice $invoice
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('client.profile');

        return $this->resolve($invoice->client->profile, $invoice->client, $invoice);
    }

    /**
     * Get the settings, which the client's new invoices take, each with the level it comes from.
     *
     * @param Client $client
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    public function forClient(Client $client): array
    {
        $client->loadMissing('profile');

        return $this->resolve($client->profile, $client);
    }

    /**
     * Get the values of the resolved settings, without their levels.
     *
     * @param array<string, array{value: mixed, source: string}> $settings
     *
     * @return array<string, mixed>
     */
    public static function values(array $settings): array
    {
        return array_map(fn (array $setting) => $setting['value'], $settings);
    }

    /**
     * Get the currency of a new invoice: the contract's, else the client's, else the profile's default one.
     *
     * @param Profile $profile
     * @param Client|null $client
     * @param Contract|null $contract
     *
     * @return string
     */
    public function currencyFor(Profile $profile, ?Client $client, ?Contract $contract): string
    {
        return strtoupper($contract?->currency ?? $client?->currency ?? $profile->default_currency);
    }

    /**
     * Resolve the settings at the levels of the invoice (when there is one), the client and the profile.
     *
     * @param Profile $profile
     * @param Client $client
     * @param Invoice|null $invoice
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    protected function resolve(Profile $profile, Client $client, ?Invoice $invoice = null): array
    {
        $locale = $this->pick(
            $invoice?->locale?->value,
            $client->invoice_locale?->value,
            InvoiceLocale::tryFrom((string) $profile->locale)?->value,
            InvoiceLocale::En->value,
        );

        return [
            'locale' => $locale,
            'payment_purpose_template' => $this->pick(
                $invoice?->payment_purpose_template,
                $client->payment_purpose_template,
                $profile->setting('invoice.payment_purpose_template'),
                trans('invoice.defaults.payment_purpose', [], $locale['value']),
            ),
            'act_note_enabled' => $this->pick(
                $invoice?->act_note_enabled,
                $client->act_note_enabled,
                $profile->setting('invoice.act_note_enabled'),
                true,
            ),
            'act_note' => $this->pick(
                $invoice?->act_note,
                $client->act_note,
                $profile->setting('invoice.act_note'),
                trans('invoice.defaults.act_note', [], $locale['value']),
            ),
            'show_contract' => $this->pick(
                $invoice?->show_contract,
                $client->show_contract,
                $profile->setting('invoice.show_contract'),
                true,
            ),
        ];
    }

    /**
     * Pick the first value, which isn't null, with its level.
     *
     * @param mixed $invoice
     * @param mixed $client
     * @param mixed $profile
     * @param mixed $default
     *
     * @return array{value: mixed, source: string}
     */
    protected function pick(mixed $invoice, mixed $client, mixed $profile, mixed $default): array
    {
        [$value, $source] = match (true) {
            $invoice !== null => [$invoice, SettingSource::Invoice],
            $client !== null => [$client, SettingSource::Client],
            $profile !== null => [$profile, SettingSource::Profile],
            default => [$default, SettingSource::Default],
        };

        return ['value' => $value, 'source' => $source->value];
    }
}
