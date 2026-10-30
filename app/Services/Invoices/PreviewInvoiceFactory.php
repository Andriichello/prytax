<?php

namespace PryTax\Services\Invoices;

use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\InvoiceStyle;
use PryTax\Helpers\DocumentDate;
use PryTax\Models\Invoice;

/**
 * Class PreviewInvoiceFactory.
 *
 * Makes a demo invoice, which is never saved, for a preview of a style where there is no invoice to show it on, and
 * for the thumbnails of the style picker: the fixture of the handoff's references (BRIT-2026-10-31-2 to Bring IT LLC,
 * three items in dollars through Payoneer), with the contract, the act of acceptance and both notes, frozen into a
 * snapshot of version 3 in the style and language. Its ФОП is Myrchuk Mykola, or Prykhodko Andrii in the Andriichello
 * styles, as in the references. It reads nothing from the database.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class PreviewInvoiceFactory
{
    /**
     * Make the demo invoice in the style and language.
     *
     * @param InvoiceStyle $style
     * @param InvoiceLocale|string $locale
     *
     * @return Invoice
     */
    public function make(InvoiceStyle $style, InvoiceLocale|string $locale): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill(['number' => 'BRIT-2026-10-31-2', 'snapshot' => $this->snapshot($style, InvoiceLocale::from($locale instanceof InvoiceLocale ? $locale->value : $locale))]);

        return $invoice;
    }

    /**
     * Build the snapshot of the demo invoice.
     *
     * @param InvoiceStyle $style
     * @param InvoiceLocale $locale
     *
     * @return array<string, mixed>
     */
    public function snapshot(InvoiceStyle $style, InvoiceLocale $locale): array
    {
        $uk = $locale === InvoiceLocale::Uk;
        $andrii = $style->group() === 'andriichello';
        $name = $andrii ? ($uk ? 'Приходько Андрій' : 'Prykhodko Andrii') : ($uk ? 'Мирчук Микола' : 'Myrchuk Mykola');
        $description = $uk
            ? 'ІТ-послуги згідно з Договором про надання послуг № 109-23 від 1 вересня 2026'
            : 'IT services in accordance to the Service Agreement #109-23 dated September 1, 2026';
        $item = fn (int $position, string $unit, string $quantity, string $price, string $amount) => [
            'position' => $position,
            'project_code' => 'CRONE',
            'charge_type' => 'Billable',
            'description' => $description,
            'unit' => $unit,
            'quantity' => $quantity,
            'unit_price' => $price,
            'amount' => $amount,
        ];
        $purpose = $uk ? 'Оплата за ІТ-послуги згідно з інвойсом № BRIT-2026-10-31-2 від :date' : 'Payment for IT services by the invoice No. BRIT-2026-10-31-2 from :date';

        return [
            'schema_version' => 3,
            'money_format' => 'plain_dot',
            'locale' => $locale->value,
            'frozen_at' => '2026-10-31T12:00:00+02:00',
            'profile' => [
                'name' => $name,
                'address' => $uk ? 'вул. Капушанська, 42, кв. 15, м. Ужгород, Україна' : "Kapushans'ka St, 42, 15, Uzhhorod, Ukraine",
                'tax_id' => '1234500592',
                'tax_id_kind' => 'rnokpp',
                'registered_at' => '2020-01-15',
                'email' => $andrii ? 'andrii@example.com' : 'mykola@example.com',
                'phone' => '+3801237654',
                'signature_file_id' => null,
            ],
            'client' => [
                'name' => 'Bring IT LLC',
                'country' => 'US',
                'vat_number' => null,
                'tax_id' => null,
                'address' => ['text' => "500 Market St, Suite 200\nNew Jersey 25902\nUS"],
                'email' => 'billing@bringit.example.com',
            ],
            'contract' => [
                'name' => $uk ? 'Договір про надання послуг' : 'Service Agreement',
                'number' => '109-23',
                'subject' => $uk ? 'ІТ-послуги' : 'IT services',
                'started_at' => '2026-09-01',
                'ends_at' => null,
                'payment_terms_days' => 14,
            ],
            'presentation' => [
                'style' => $style->value,
                'payment_purpose' => str_replace(':date', DocumentDate::format('2026-10-31', $locale->value), $purpose),
                'act_note' => trans('invoice.defaults.act_note', [], $locale->value),
                'show_contract' => true,
                'payment_note' => $uk
                    ? 'Вкажіть номер інвойсу в призначенні платежу.'
                    : 'Please quote the invoice number in the transfer reference.',
                'other_note' => 'Some small note for Bring IT LLC.',
            ],
            'account' => [
                'name' => 'Payoneer USD',
                'kind' => 'platform',
                'currency' => 'USD',
                'holder_name' => $andrii ? 'Prykhodko Andrii' : 'Myrchuk Mykola',
                'bank_name' => 'Payoneer',
                'iban' => null,
                'swift' => 'CITIUS33',
                'account_number' => '70450600001112233',
                'details' => [
                    ['label' => 'Bank name', 'value' => 'Citibank'],
                    ['label' => 'Bank address', 'value' => '111 Wall Street New York, NY 10043 USA'],
                    ['label' => 'Routing (ABA)', 'value' => '022200101'],
                    ['label' => 'Account type', 'value' => 'CHECKING'],
                ],
            ],
            'invoice' => [
                'number' => 'BRIT-2026-10-31-2',
                'source' => 'generated',
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'issued_at' => '2026-10-31',
                'due_at' => '2026-11-14',
                'currency' => 'USD',
            ],
            'items' => [
                $item(1, 'hour', '20.00', '40.00', '800.00'),
                $item(2, 'fixed', '30.00', '25.00', '750.00'),
                $item(3, 'item', '37.00', '99.00', '3663.00'),
            ],
            'totals' => ['subtotal' => '5213.00', 'adjustment_amount' => '0.00', 'adjustment_label' => null, 'total' => '5213.00'],
        ];
    }
}
