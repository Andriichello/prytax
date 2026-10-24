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
 * else the built-in one; null at a level takes the next. Each tells the level it comes from, and the value the levels
 * below give it, which a form shows beside its own one. The profile's language is its document language
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
     * Get the settings of the invoice, each with the level it comes from, and the one the client gives it.
     *
     * @param Invoice $invoice
     *
     * @return array<string, array{value: mixed, source: string, inherited: array{value: mixed, source: string}}>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('client.profile');

        return $this->resolve($invoice->client->profile, $invoice->client, $invoice);
    }

    /**
     * Get the settings, which the client's new invoices take, each with the level it comes from, and the one the
     * profile gives it.
     *
     * @param Client $client
     *
     * @return array<string, array{value: mixed, source: string, inherited: array{value: mixed, source: string}}>
     */
    public function forClient(Client $client): array
    {
        $client->loadMissing('profile');

        return $this->resolve($client->profile, $client);
    }

    /**
     * Get the settings, which the profile's clients take, each with the level it comes from, and the built-in one.
     *
     * @param Profile $profile
     *
     * @return array<string, array{value: mixed, source: string, inherited: array{value: mixed, source: string}}>
     */
    public function forProfile(Profile $profile): array
    {
        return $this->resolve($profile);
    }

    /**
     * Get the built-in texts in each language: the payment purpose template, the act note and the word for services,
     * which a form shows for a setting, that inherits the built-in one, in the language it chose.
     *
     * @return array<string, array{payment_purpose: string, act_note: string, services: string}>
     */
    public function builtInTexts(): array
    {
        $texts = [];

        foreach (InvoiceLocale::values() as $locale) {
            $texts[$locale] = [
                'payment_purpose' => trans('invoice.defaults.payment_purpose', [], $locale),
                'act_note' => trans('invoice.defaults.act_note', [], $locale),
                'services' => trans('invoice.defaults.services', [], $locale),
            ];
        }

        return $texts;
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
     * Resolve the settings at the levels of the invoice and the client (when there are ones) and the profile; the
     * inherited value of each is the one of the levels below the lowest given one.
     *
     * @param Profile $profile
     * @param Client|null $client
     * @param Invoice|null $invoice
     *
     * @return array<string, array{value: mixed, source: string, inherited: array{value: mixed, source: string}}>
     */
    protected function resolve(Profile $profile, ?Client $client = null, ?Invoice $invoice = null): array
    {
        $own = match (true) {
            $invoice !== null => SettingSource::Invoice,
            $client !== null => SettingSource::Client,
            default => SettingSource::Profile,
        };

        $locale = $this->pick($own, [
            $invoice?->locale?->value,
            $client?->invoice_locale?->value,
            InvoiceLocale::tryFrom((string) $profile->locale)?->value,
            InvoiceLocale::En->value,
        ]);

        return [
            'locale' => $locale,
            'payment_purpose_template' => $this->pick($own, [
                $invoice?->payment_purpose_template,
                $client?->payment_purpose_template,
                $profile->setting('invoice.payment_purpose_template'),
                trans('invoice.defaults.payment_purpose', [], $locale['value']),
            ]),
            'act_note_enabled' => $this->pick($own, [
                $invoice?->act_note_enabled,
                $client?->act_note_enabled,
                $profile->setting('invoice.act_note_enabled'),
                true,
            ]),
            'act_note' => $this->pick($own, [
                $invoice?->act_note,
                $client?->act_note,
                $profile->setting('invoice.act_note'),
                trans('invoice.defaults.act_note', [], $locale['value']),
            ]),
            'show_contract' => $this->pick($own, [
                $invoice?->show_contract,
                $client?->show_contract,
                $profile->setting('invoice.show_contract'),
                true,
            ]),
        ];
    }

    /**
     * Pick the first value, which isn't null, of the invoice's, client's, profile's and built-in ones, with its
     * level, and the first one below the given level, which it inherits.
     *
     * @param SettingSource $own
     * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed} $values
     *
     * @return array{value: mixed, source: string, inherited: array{value: mixed, source: string}}
     */
    protected function pick(SettingSource $own, array $values): array
    {
        $levels = array_combine(
            [SettingSource::Invoice->value, SettingSource::Client->value, SettingSource::Profile->value, SettingSource::Default->value],
            $values,
        );
        $first = fn (array $levels) => collect($levels)
            ->filter(fn (mixed $value) => $value !== null)
            ->map(fn (mixed $value, string $source) => ['value' => $value, 'source' => $source])
            ->first();

        $below = array_slice($levels, array_search($own->value, array_keys($levels), true) + 1, null, true);

        return [...$first($levels), 'inherited' => $first($below)];
    }
}
