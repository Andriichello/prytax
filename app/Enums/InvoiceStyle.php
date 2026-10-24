<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum InvoiceStyle.
 *
 * Look of an invoice's PDF (template v3): the same content in each, laid out with its own colours, rules, fonts and
 * decoration. A setting of the document cascade (design.md §4.1), `outlined` by default. A string column, rather than
 * a database enum, since styles will be added; the cases are in the order of the catalogue, which the style picker and
 * its preview follow.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum InvoiceStyle: string implements BaseEnumInterface
{
    use BaseEnum;

    case Outlined = 'outlined';
    case Simplified = 'simplified';
    case Colored = 'colored';
    case Vivid = 'vivid';
    case Monochrome = 'monochrome';
    case Freeform = 'freeform';
    case FreeformMono = 'freeform_mono';
    case FreeformFacets = 'freeform_facets';
    case FreeformBlocks = 'freeform_blocks';
    case Andriichello = 'andriichello';
    case AndriichelloSchema = 'andriichello_schema';
    case AndriichelloSakura = 'andriichello_sakura';
    case AndriichelloCarpathians = 'andriichello_carpathians';

    /**
     * Get the style's name, which is the same in every language.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Outlined => 'Outlined',
            self::Simplified => 'Simplified',
            self::Colored => 'Colored',
            self::Vivid => 'Vivid',
            self::Monochrome => 'Monochrome',
            self::Freeform => 'Free-form',
            self::FreeformMono => 'Free-form Mono',
            self::FreeformFacets => 'Facets',
            self::FreeformBlocks => 'Blocks',
            self::Andriichello => 'Andriichello',
            self::AndriichelloSchema => 'Schema',
            self::AndriichelloSakura => 'Sakura',
            self::AndriichelloCarpathians => 'Carpathians',
        };
    }

    /**
     * Get the group of the catalogue, under which the picker lists the style: `classic`, `geometric` or
     * `andriichello`.
     *
     * @return string
     */
    public function group(): string
    {
        return match ($this) {
            self::Outlined, self::Simplified, self::Colored, self::Vivid, self::Monochrome => 'classic',
            self::Freeform, self::FreeformMono, self::FreeformFacets, self::FreeformBlocks => 'geometric',
            default => 'andriichello',
        };
    }

    /**
     * Get the class of the PDF's root element, by which the style's CSS applies.
     *
     * @return string
     */
    public function cssClass(): string
    {
        return match ($this) {
            self::Outlined => 's-outlined',
            self::Simplified => 's-simplified',
            self::Colored => 's-colored',
            self::Vivid => 's-vivid',
            self::Monochrome => 's-mono',
            self::Freeform => 's-free',
            self::FreeformMono => 's-ffmono',
            self::FreeformFacets => 's-fffacets',
            self::FreeformBlocks => 's-ffblocks',
            self::Andriichello => 's-andrii',
            self::AndriichelloSchema => 's-schema',
            self::AndriichelloSakura => 's-sakura',
            self::AndriichelloCarpathians => 's-carp',
        };
    }
}
