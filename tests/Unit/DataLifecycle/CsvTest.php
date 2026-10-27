<?php

namespace Tests\Unit\DataLifecycle;

use PHPUnit\Framework\TestCase;
use PryTax\DataLifecycle\Backup\Internal\Csv;

/**
 * Class CsvTest.
 *
 * The archives' CSV (backups.md §2.5): RFC 4180 quoting of commas, quotes and line breaks, NULL as an empty unquoted
 * field and an empty string as `""`, which a parse tells apart again, Cyrillic and the BOM.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Unit\DataLifecycle
 */
class CsvTest extends TestCase
{
    /**
     * Test that a row is written with quotes only where they are needed, NULL unquoted and an empty string quoted.
     *
     * @return void
     */
    public function testQuotesOnlyWhatNeedsIt(): void
    {
        $this->assertSame(
            "1,,\"\",\"a, b\",\"say \"\"hi\"\"\",\"two\r\nlines\",Коваленко,1234.99\r\n",
            Csv::line(['1', null, '', 'a, b', 'say "hi"', "two\r\nlines", 'Коваленко', '1234.99'])
        );
    }

    /**
     * Test that what is written parses back to the same values, NULL and the empty string apart.
     *
     * @return void
     */
    public function testParsesWhatItWritesBack(): void
    {
        $rows = [
            ['id', 'description', 'note', 'amount'],
            ['1', "Послуги, жовтень\n\"Brightline\"", null, '3892.50'],
            ['2', '', null, '0.00'],
            [null, ',', '""', "\r"],
        ];

        $csv = Csv::BOM . implode('', array_map(fn (array $row) => Csv::line($row), $rows));

        $this->assertSame($rows, Csv::parse($csv));
    }

    /**
     * Test that an empty file has no rows, and a last row without a line break is read.
     *
     * @return void
     */
    public function testReadsTheEdgesOfAFile(): void
    {
        $this->assertSame([], Csv::parse(Csv::BOM));
        $this->assertSame([['a', null], ['b', 'c']], Csv::parse("a,\r\nb,c"));
    }
}
