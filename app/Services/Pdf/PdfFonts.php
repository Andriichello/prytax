<?php

namespace PryTax\Services\Pdf;

use Dompdf\FontMetrics;
use FontLib\Font;
use Illuminate\Support\Str;

/**
 * Class PdfFonts.
 *
 * The fonts of the PDFs beyond dompdf's DejaVu (the invoices' styles, the books' figures), installed in dompdf's font
 * directory, which every dompdf document reads, rather than by `@font-face`, so they measure before any PDF has used
 * them, and so their height is DejaVu's (see metrics()).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Pdf
 */
class PdfFonts
{
    /**
     * Fonts beyond dompdf's DejaVu, by their family and weight, and their files in `resources/fonts`, which metrics()
     * installs. dompdf looks a font up by its exact weight (600 is neither normal nor bold), so each weight, which the
     * styles use, has one: Plex Mono's 600 and bold are its SemiBold; Unbounded has no Regular, so its normal is the
     * SemiBold too.
     *
     * @var array<string, array<string, string>>
     */
    public const array FONTS = [
        'PlexMonoSemi' => ['normal' => 'IBMPlexMono-SemiBold.ttf'],
        'Plex Mono' => ['normal' => 'IBMPlexMono-Regular.ttf', '600' => 'IBMPlexMono-SemiBold.ttf', 'bold' => 'IBMPlexMono-SemiBold.ttf'],
        'Unbounded' => ['normal' => 'Unbounded-SemiBold.ttf', '600' => 'Unbounded-SemiBold.ttf', 'bold' => 'Unbounded-Bold.ttf'],
    ];

    /**
     * Get dompdf's font metrics with the fonts installed: dompdf makes a line `line-height × (ascender − descender) /
     * 1164` high, which is the CSS one for DejaVu only, and 1.12 times higher for Plex Mono (1300), 1.07 for Unbounded
     * (1240). An offset in their metrics makes up the difference, which dompdf adds to the font's height. The invoice
     * number's PlexMonoSemi keeps its height: it shares a line with DejaVu, which browsers make as much higher. A font
     * is installed again when its file is newer than the installed one.
     *
     * @return FontMetrics
     */
    public function metrics(): FontMetrics
    {
        $metrics = app('dompdf')->getFontMetrics();
        $directory = $metrics->getOptions()->getFontDir();

        foreach (self::FONTS as $family => $weights) {
            $entry = $metrics->getFontFamilies()[mb_strtolower($family)] ?? [];
            $installed = $entry;

            foreach ($weights as $weight => $file) {
                $source = resource_path("fonts/$file");
                $path = $directory . '/prytax_' . Str::slug($family, '_') . '_' . $weight;

                if (!is_file("$path.ufm") || filemtime($source) > filemtime("$path.ufm")) {
                    $this->install($source, $path, $family !== 'PlexMonoSemi');
                }

                $entry[$weight] = $path;
            }

            if ($entry !== $installed) {
                $metrics->setFontFamily($family, $entry);
            }
        }

        return $metrics;
    }

    /**
     * Copy a font into dompdf's font directory with its metrics, whose height an offset makes DejaVu's, when it is
     * levelled.
     *
     * @param string $source
     * @param string $path The installed font's path without its extension.
     * @param bool $levelled
     *
     * @return void
     */
    protected function install(string $source, string $path, bool $levelled): void
    {
        copy($source, "$path.ttf");

        $font = Font::load("$path.ttf");
        $font->parse();
        $font->saveAdobeFontMetrics("$path.ufm");
        $font->close();

        $metrics = (string) file_get_contents("$path.ufm");
        preg_match('/^Ascender (-?\d+)/m', $metrics, $ascender);
        preg_match('/^Descender (-?\d+)/m', $metrics, $descender);
        $offset = 1164 - ((int) ($ascender[1] ?? 928) - (int) ($descender[1] ?? -236));

        if ($levelled) {
            $metrics = preg_replace('/^(Descender -?\d+)$/m', "$1\nFontHeightOffset $offset", $metrics, 1);
        }

        file_put_contents("$path.ufm", $metrics);
        @unlink("$path.ufm.json");
    }
}
