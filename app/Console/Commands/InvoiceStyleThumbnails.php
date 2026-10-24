<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\InvoiceStyle;
use PryTax\Services\Invoices\InvoicePdfRenderer;
use PryTax\Services\Invoices\PreviewInvoiceFactory;

/**
 * Class InvoiceStyleThumbnails.
 *
 * Draws the thumbnails of the invoice style picker (docs/specs/invoice.md §8): the demo invoice in every style and
 * language, rendered by the real renderer, its first page rasterized to 316px wide by `pdftoppm` (poppler-utils, a
 * development tool), into `public/images/invoice-styles/{style}-{locale}.png`, which are committed. Run it after a
 * style or the template changes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('invoice:style-thumbnails {--style= : Key of one style}')]
#[Description('Draw the thumbnails of the invoice styles')]
class InvoiceStyleThumbnails extends Command
{
    /**
     * Width of a thumbnail in px.
     *
     * @var int
     */
    public const int WIDTH = 316;

    /**
     * Execute the console command.
     *
     * @param PreviewInvoiceFactory $factory
     * @param InvoicePdfRenderer $renderer
     *
     * @return int
     */
    public function handle(PreviewInvoiceFactory $factory, InvoicePdfRenderer $renderer): int
    {
        $directory = public_path('images/invoice-styles');
        $temporary = storage_path('app/private/invoice-style-thumbnails');
        $styles = $this->option('style') ? [InvoiceStyle::from($this->option('style'))] : InvoiceStyle::cases();

        File::ensureDirectoryExists($directory);
        File::ensureDirectoryExists($temporary);

        foreach ($styles as $style) {
            foreach (InvoiceLocale::cases() as $locale) {
                $name = "{$style->value}-{$locale->value}";

                File::put("$temporary/$name.pdf", $renderer->render($factory->make($style, $locale)));

                $result = Process::run([
                    'pdftoppm', '-png', '-singlefile', '-f', '1', '-l', '1', '-scale-to-x', (string) self::WIDTH, '-scale-to-y', '-1',
                    "$temporary/$name.pdf", "$directory/$name",
                ]);

                if ($result->failed()) {
                    $this->error("$name: {$result->errorOutput()}");
                    File::deleteDirectory($temporary);

                    return self::FAILURE;
                }

                $this->line("$name.png");
            }
        }

        File::deleteDirectory($temporary);

        return self::SUCCESS;
    }
}
