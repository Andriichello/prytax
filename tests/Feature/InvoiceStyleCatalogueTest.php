<?php

namespace Tests\Feature;

use PryTax\Enums\InvoiceStyle;
use Tests\TestCase;

/**
 * Class InvoiceStyleCatalogueTest.
 *
 * The SPA's catalogue of invoice styles (`resources/js/utils/invoiceStyles.ts`), which the picker and the preview's
 * arrows follow, lists the styles of InvoiceStyle in its order, with the same names and groups.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature
 */
class InvoiceStyleCatalogueTest extends TestCase
{
    /**
     * Test that the SPA's catalogue is the enum's: each case in order, with its name and group.
     *
     * @return void
     */
    public function testListsTheStylesOfTheEnumInItsOrder(): void
    {
        $source = file_get_contents(resource_path('js/utils/invoiceStyles.ts'));
        preg_match_all("/\\{ key: '([a-z_]+)', name: '([^']+)', group: '([a-z]+)' \\}/", $source, $matches, PREG_SET_ORDER);

        $this->assertSame(
            array_map(fn (InvoiceStyle $style) => [$style->value, $style->label(), $style->group()], InvoiceStyle::cases()),
            array_map(fn (array $match) => [$match[1], $match[2], $match[3]], $matches),
        );
    }
}
