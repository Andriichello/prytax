<?php

namespace PryTax\Services\Invoices;

use Barryvdh\DomPDF\Facade\Pdf;
use Core\Models\File;
use PryTax\Helpers\MoneyFormatter;
use PryTax\Models\Invoice;

/**
 * Class InvoicePdfRenderer.
 *
 * Renders an invoice's PDF from its snapshot only, never from live relations, in the money format, which the snapshot
 * keeps (design.md §4, §10), with dompdf and its bundled DejaVu Sans for Cyrillic. The template reads the snapshot's
 * `schema_version`, so old invoices keep rendering after the template changes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoicePdfRenderer
{
    /**
     * Version of the template, which is recorded with each generated file.
     *
     * @var int
     */
    public const int TEMPLATE_VERSION = 1;

    /**
     * Render the PDF of the frozen invoice.
     *
     * @param Invoice $invoice
     *
     * @return string
     */
    public function render(Invoice $invoice): string
    {
        $snapshot = $invoice->snapshot ?? [];
        $signature = null;

        if ($id = data_get($snapshot, 'profile.signature_file_id')) {
            $file = File::query()->find($id);
            $contents = $file?->contents();

            if ($contents !== null) {
                $signature = 'data:' . $file->mime_type . ';base64,' . base64_encode($contents);
            }
        }

        return Pdf::loadView('pdf.invoice', [
            'snapshot' => $snapshot,
            'money' => MoneyFormatter::of($snapshot['money_format'] ?? null),
            'signature' => $signature,
        ])
            ->setPaper('a4')
            // Embeds only the glyphs in use, rather than whole fonts (megabytes per PDF)
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    /**
     * Render the PDF and attach it to the invoice as its generated, primary document.
     *
     * @param Invoice $invoice
     *
     * @return File
     */
    public function attachGenerated(Invoice $invoice): File
    {
        $file = $invoice->attachContents(
            $this->render($invoice),
            ($invoice->number ?? 'invoice') . '.pdf',
            Invoice::GENERATED_COLLECTION,
            ['template_version' => self::TEMPLATE_VERSION, 'schema_version' => data_get($invoice->snapshot, 'schema_version')],
        );

        $invoice->forceFill(['primary_file_id' => $file->getKey()])->save();

        return $file;
    }
}
