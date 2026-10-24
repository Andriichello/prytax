<?php

namespace PryTax\Services\Invoices;

use Closure;
use PryTax\Helpers\DocumentDate;
use PryTax\Models\Invoice;

/**
 * Class PaymentPurposeRenderer.
 *
 * Renders the payment purpose of an invoice from its template (design.md §4.1), such as "Payment for {services} by the
 * invoice No. {invoice.number} from {invoice.date}": the placeholders of a whitelist, filled in the invoice's language,
 * with the dates of DocumentDate. A template with an unknown placeholder is refused on save; one, which needs a value
 * of the contract the invoice lacks, on freeze. Payments in a foreign currency go through SWIFT or SEPA, whose
 * reference takes 140 characters of a restricted Latin set, so a longer or other one is warned of, never refused.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class PaymentPurposeRenderer
{
    /**
     * Pattern of a placeholder: `{services}` or `{invoice.number}`.
     *
     * @var string
     */
    public const string PATTERN = '/\{([a-z_]+(?:\.[a-z_]+)?)\}/';

    /**
     * Placeholders, which a template may use.
     *
     * @var array<int, string>
     */
    public const array PLACEHOLDERS = [
        'services',
        'invoice.number',
        'invoice.date',
        'period.start',
        'period.end',
        'contract.name',
        'contract.number',
        'contract.date',
        'client.name',
        'profile.name',
    ];

    /**
     * Length of a SWIFT (MT103 field 70) or SEPA payment reference.
     *
     * @var int
     */
    public const int REFERENCE_LENGTH = 140;

    /**
     * Characters of a SWIFT payment reference (the X character set).
     *
     * @var string
     */
    public const string REFERENCE_CHARSET = "/^[A-Za-z0-9\/\-?:().,'+ ]*$/";

    /**
     * Get the placeholders of the template, which aren't in the whitelist.
     *
     * @param string $template
     *
     * @return array<int, string>
     */
    public function unknownPlaceholders(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique(array_diff($matches[1], self::PLACEHOLDERS)));
    }

    /**
     * Get the values of the placeholders for the invoice in the language: a value of the contract, which the invoice
     * lacks, is null. A draft without a number takes the one it would get.
     *
     * @param Invoice $invoice
     * @param string $locale
     *
     * @return array<string, string|null>
     */
    public function values(Invoice $invoice, string $locale): array
    {
        $invoice->loadMissing(['client', 'contract', 'profile']);
        $contract = $invoice->contract;

        return [
            'services' => $contract?->subject ?: trans('invoice.defaults.services', [], $locale),
            'invoice.number' => (string) ($invoice->number ?? $invoice->number_preview),
            'invoice.date' => DocumentDate::format($invoice->issued_at, $locale),
            'period.start' => DocumentDate::format($invoice->period_start, $locale),
            'period.end' => DocumentDate::format($invoice->period_end, $locale),
            'contract.name' => $contract?->name,
            'contract.number' => $contract?->number ?: null,
            'contract.date' => $contract ? DocumentDate::format($contract->started_at, $locale) : null,
            'client.name' => $invoice->client->legalNameIn($locale),
            'profile.name' => $invoice->profile->nameIn($locale),
        ];
    }

    /**
     * Render the payment purpose of the invoice with its resolved settings.
     *
     * @param Invoice $invoice
     * @param array<string, mixed> $settings The values of InvoiceSettingsResolver's settings.
     *
     * @return string
     */
    public function forInvoice(Invoice $invoice, array $settings): string
    {
        return $this->render($settings['payment_purpose_template'], $this->values($invoice, $settings['locale']));
    }

    /**
     * Get the placeholders of the template, whose values are missing.
     *
     * @param string $template
     * @param array<string, string|null> $values
     *
     * @return array<int, string>
     */
    public function missing(string $template, array $values): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique(array_filter($matches[1], fn (string $name) => ($values[$name] ?? null) === null)));
    }

    /**
     * Fill in the template's placeholders; one without a value stays as it is.
     *
     * @param string $template
     * @param array<string, string|null> $values
     *
     * @return string
     */
    public function render(string $template, array $values): string
    {
        return (string) preg_replace_callback(self::PATTERN, fn (array $match) => $values[$match[1]] ?? $match[0], $template);
    }

    /**
     * Get the warnings of a payment purpose in the currency: none in hryvnias; in another currency, when it is longer
     * than a SWIFT or SEPA reference, or has characters outside of the SWIFT set.
     *
     * @param string $purpose
     * @param string $currency
     *
     * @return array<int, string>
     */
    public function warnings(string $purpose, string $currency): array
    {
        if ($currency === 'UAH') {
            return [];
        }

        $warnings = [];

        if (mb_strlen($purpose) > self::REFERENCE_LENGTH) {
            $warnings[] = 'payment_purpose_too_long';
        }

        if (!preg_match(self::REFERENCE_CHARSET, $purpose)) {
            $warnings[] = 'payment_purpose_charset';
        }

        return $warnings;
    }

    /**
     * Rule: the template uses the whitelisted placeholders only.
     *
     * @return Closure
     */
    public function templateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $unknown = is_string($value) ? $this->unknownPlaceholders($value) : [];

            if (!empty($unknown)) {
                $fail(__('The payment purpose has unknown placeholders: :placeholders.', [
                    'placeholders' => implode(', ', array_map(fn (string $name) => '{' . $name . '}', $unknown)),
                ]));
            }
        };
    }
}
