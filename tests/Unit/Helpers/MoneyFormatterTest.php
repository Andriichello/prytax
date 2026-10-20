<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PryTax\Helpers\MoneyFormatter;

/**
 * Class MoneyFormatterTest.
 *
 * The six money formats of a profile's settings, and the parsing of amounts, which people type with either decimal
 * separator (design.md §10).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Unit\Helpers
 */
class MoneyFormatterTest extends TestCase
{
    /**
     * Each format with 1 840 135.05 formatted in it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formats(): array
    {
        return [
            'plain_dot' => ['plain_dot', '1840135.05'],
            'space_dot' => ['space_dot', "1\u{202F}840\u{202F}135.05"],
            'comma_dot' => ['comma_dot', '1,840,135.05'],
            'plain_comma' => ['plain_comma', '1840135,05'],
            'space_comma' => ['space_comma', "1\u{202F}840\u{202F}135,05"],
            'dot_comma' => ['dot_comma', '1.840.135,05'],
        ];
    }

    /**
     * Test that an amount is formatted with the separators of each format.
     *
     * @param string $format
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('formats')]
    public function testFormatsAmountsWithTheSeparatorsOfTheFormat(string $format, string $expected): void
    {
        $this->assertSame($expected, MoneyFormatter::of($format)->amount('1840135.05'));
    }

    /**
     * Test that UAH is shown as ₴ after the amount, other currencies as their codes, and negatives with a minus sign.
     *
     * @return void
     */
    public function testPutsTheCurrencyAfterTheAmount(): void
    {
        $formatter = MoneyFormatter::of('comma_dot');

        $this->assertSame('3,892.50 USD', $formatter->money('3892.5', 'usd'));
        $this->assertSame("\u{2212}765.69 ₴", $formatter->money('-765.69', 'UAH'));
        $this->assertSame('0.00 EUR', $formatter->money(null, 'EUR'));
    }

    /**
     * Test that an unknown format falls back to the app's default, `1 234.99`.
     *
     * @return void
     */
    public function testFallsBackToTheDefaultFormat(): void
    {
        $this->assertSame("2\u{202F}851.99", MoneyFormatter::of('nonsense')->amount('2851.99'));
    }

    /**
     * Typed amounts with their canonical forms.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function typedAmounts(): array
    {
        return [
            'plain dot' => ['2851.99', '2851.99'],
            'decimal comma' => ['2851,99', '2851.99'],
            'grouped by spaces with a comma' => ['2 851,99', '2851.99'],
            'grouped by commas' => ['2,851.99', '2851.99'],
            'grouped by dots' => ['2.851,99', '2851.99'],
            'grouped by narrow spaces' => ["1\u{202F}840\u{202F}135.05", '1840135.05'],
            'negative with a minus sign' => ["\u{2212}12,50", '-12.50'],
            'whole number' => ['  42 ', '42'],
            'letters' => ['12a', null],
            'empty' => ['', null],
        ];
    }

    /**
     * Test that typed amounts are parsed into their canonical forms, or refused.
     *
     * @param string $input
     * @param string|null $expected
     *
     * @return void
     */
    #[DataProvider('typedAmounts')]
    public function testParsesTypedAmountsIntoCanonicalForm(string $input, ?string $expected): void
    {
        $this->assertSame($expected, MoneyFormatter::parse($input));
    }
}
