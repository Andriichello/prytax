<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PryTax\Helpers\Decimal;

/**
 * Class DecimalTest.
 *
 * Money arithmetic without floats: splitting an amount in proportion to weights, to the kopeck, by the largest
 * remainder, so the shares add up to the amount (design.md §6.1, one wire for several invoices).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Unit\Helpers
 */
class DecimalTest extends TestCase
{
    /**
     * Amounts, weights and their shares.
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}>
     */
    public static function splits(): array
    {
        return [
            'even' => ['100.00', ['50.00', '50.00'], ['50.00', '50.00']],
            'kopeck to the largest remainder' => ['100.00', ['1.00', '1.00', '1.00'], ['33.34', '33.33', '33.33']],
            'fee of a wire' => ['7 763.40', ['3892.50', '3892.50'], ['3881.70', '3881.70']],
            'uneven' => ['985.00', ['600.00', '400.00'], ['591.00', '394.00']],
            'remainders by size' => ['10.00', ['1.00', '2.00', '4.00'], ['1.43', '2.86', '5.71']],
            'zero weights' => ['10.00', ['0.00', '0.00'], ['0.00', '0.00']],
        ];
    }

    /**
     * Test that the shares are proportional, rounded down, with the kopecks left to the largest remainders.
     *
     * @param string $amount
     * @param array<int, string> $weights
     * @param array<int, string> $shares
     *
     * @return void
     */
    #[DataProvider('splits')]
    public function testSplitsAnAmountByTheLargestRemainder(string $amount, array $weights, array $shares): void
    {
        $this->assertSame($shares, Decimal::split(str_replace(' ', '', $amount), $weights));
    }
}
