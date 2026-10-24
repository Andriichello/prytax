<?php

namespace PryTax\Services\Invoices;

use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\InvoiceStyle;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class InvoiceStylePreview.
 *
 * Renders an invoice's PDF in a style, which the style picker previews (design.md §4.1), with the real renderer and
 * nothing stored: the given invoice, else the client's latest one, else the ФОП's latest one, as a freeze would print
 * it now, only in the asked style and language; without any invoice, a demo one. Its other settings resolve as usual.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoiceStylePreview
{
    /**
     * InvoiceStylePreview constructor.
     *
     * @param InvoiceFreezer $freezer
     * @param InvoicePdfRenderer $renderer
     * @param PreviewInvoiceFactory $demo
     */
    public function __construct(
        protected InvoiceFreezer $freezer,
        protected InvoicePdfRenderer $renderer,
        protected PreviewInvoiceFactory $demo
    ) {}

    /**
     * Render the PDF of the preview.
     *
     * @param Profile $profile
     * @param InvoiceStyle $style
     * @param InvoiceLocale|null $locale Null takes the invoice's resolved language.
     * @param Client|null $client
     * @param Invoice|null $invoice
     *
     * @return string
     */
    public function render(Profile $profile, InvoiceStyle $style, ?InvoiceLocale $locale, ?Client $client = null, ?Invoice $invoice = null): string
    {
        $sample = $this->sample($profile, $client, $invoice);

        if ($sample === null) {
            $demo = $this->demo->make($style, $locale ?? InvoiceLocale::tryFrom((string) $profile->locale) ?? InvoiceLocale::En);

            return $this->renderer->render($demo, InvoicePdfRenderer::TEMPLATE_VERSION);
        }

        // In memory only: the asked style and language over the invoice's own, and the number a freeze would give
        $sample->forceFill([
            'style' => $style->value,
            'locale' => $locale ?? $sample->locale,
            'number' => $sample->number ?? $sample->number_preview,
        ]);
        $sample->snapshot = $this->freezer->snapshot($profile, $sample);

        return $this->renderer->render($sample, InvoicePdfRenderer::TEMPLATE_VERSION);
    }

    /**
     * Get the invoice to preview: the given one, which has a generated PDF, else the latest one of the client or of the
     * profile, which isn't cancelled.
     *
     * @param Profile $profile
     * @param Client|null $client
     * @param Invoice|null $invoice
     *
     * @return Invoice|null
     */
    protected function sample(Profile $profile, ?Client $client, ?Invoice $invoice): ?Invoice
    {
        if ($invoice !== null && $invoice->isGenerated()) {
            return $invoice;
        }

        return Invoice::query()
            ->where('profile_id', $profile->getKey())
            ->when($client, fn ($query) => $query->where('client_id', $client->getKey()))
            ->where('source', InvoiceSource::Generated)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->latest('issued_at')
            ->latest('id')
            ->first();
    }
}
