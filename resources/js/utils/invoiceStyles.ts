import type { InvoiceLocale, InvoiceStyle } from '@/api/types';

/**
 * The styles of invoices' PDFs (template v3), as the API's InvoiceStyle enum has them: in the order of the catalogue,
 * which the picker's grid and the preview's arrows follow, each with its name (the same in every language) and group.
 * `InvoiceStyleCatalogueTest` keeps the two equal.
 */

export type InvoiceStyleGroup = 'classic' | 'geometric' | 'andriichello';

export interface InvoiceStyleEntry {
    key: InvoiceStyle;
    name: string;
    group: InvoiceStyleGroup;
}

export const INVOICE_STYLES: readonly InvoiceStyleEntry[] = [
    { key: 'outlined', name: 'Outlined', group: 'classic' },
    { key: 'simplified', name: 'Simplified', group: 'classic' },
    { key: 'colored', name: 'Colored', group: 'classic' },
    { key: 'vivid', name: 'Vivid', group: 'classic' },
    { key: 'monochrome', name: 'Monochrome', group: 'classic' },
    { key: 'freeform', name: 'Free-form', group: 'geometric' },
    { key: 'freeform_mono', name: 'Free-form Mono', group: 'geometric' },
    { key: 'freeform_facets', name: 'Facets', group: 'geometric' },
    { key: 'freeform_blocks', name: 'Blocks', group: 'geometric' },
    { key: 'andriichello', name: 'Andriichello', group: 'andriichello' },
    { key: 'andriichello_schema', name: 'Schema', group: 'andriichello' },
    { key: 'andriichello_sakura', name: 'Sakura', group: 'andriichello' },
    { key: 'andriichello_carpathians', name: 'Carpathians', group: 'andriichello' },
];

/** The groups in the order of the catalogue. */
export const INVOICE_STYLE_GROUPS: readonly InvoiceStyleGroup[] = ['classic', 'geometric', 'andriichello'];

/** The built-in style, which an invoice takes when no level sets one. */
export const DEFAULT_INVOICE_STYLE: InvoiceStyle = 'outlined';

/** The position of the style in the catalogue; an unknown one (a removed style) is the built-in one's. */
export function styleIndex(key: string | null | undefined): number {
    const index = INVOICE_STYLES.findIndex((style) => style.key === key);

    return index === -1 ? INVOICE_STYLES.findIndex((style) => style.key === DEFAULT_INVOICE_STYLE) : index;
}

/** The style's name: "Outlined", "Free-form"; an unknown one is the built-in one's. */
export function styleName(key: string | null | undefined): string {
    return INVOICE_STYLES[styleIndex(key)]!.name;
}

/** The style before or after the given one in the catalogue, going round at either end. */
export function adjacentStyle(key: string | null | undefined, step: 1 | -1): InvoiceStyle {
    const count = INVOICE_STYLES.length;

    return INVOICE_STYLES[(styleIndex(key) + step + count) % count]!.key;
}

/** The thumbnail of the style's first page in the invoice's language, which the API serves as a static image. */
export function styleThumbnail(key: string | null | undefined, locale: InvoiceLocale): string {
    return `/images/invoice-styles/${INVOICE_STYLES[styleIndex(key)]!.key}-${locale}.png`;
}
