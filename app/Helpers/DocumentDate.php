<?php

namespace PryTax\Helpers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Class DocumentDate.
 *
 * Formats the dates of an invoice's document in its language (design.md §4.1): `Oct 31, 2026` in English, `31.10.2026`
 * in Ukrainian. The PDF and the payment purpose share it, so the two never write a date two ways. The language is
 * passed, never taken from the application's, which a request sets to its user's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
class DocumentDate
{
    /**
     * Formats of the dates by language.
     *
     * @var array<string, string>
     */
    public const array FORMATS = [
        'en' => 'M j, Y',
        'uk' => 'd.m.Y',
    ];

    /**
     * Format the date in the language, or give an empty string for none.
     *
     * @param CarbonInterface|string|null $date
     * @param string $locale
     *
     * @return string
     */
    public static function format(CarbonInterface|string|null $date, string $locale): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return CarbonImmutable::parse($date)->locale($locale)->translatedFormat(self::FORMATS[$locale] ?? self::FORMATS['en']);
    }
}
