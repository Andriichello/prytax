import { describe, expect, it } from 'vitest';
import { adjacentStyle, INVOICE_STYLES, styleIndex, styleName, styleThumbnail } from '@/utils/invoiceStyles';

describe('adjacentStyle', () => {
    it('steps through the catalogue in its order', () => {
        expect(adjacentStyle('outlined', 1)).toBe('simplified');
        expect(adjacentStyle('colored', -1)).toBe('simplified');
    });

    it('goes round at either end', () => {
        expect(adjacentStyle('andriichello_carpathians', 1)).toBe('outlined');
        expect(adjacentStyle('outlined', -1)).toBe('andriichello_carpathians');
    });

    it('visits every style once in a full round', () => {
        const visited = new Set<string>();
        let key: string = 'outlined';

        for (let step = 0; step < INVOICE_STYLES.length; step++) {
            visited.add(key);
            key = adjacentStyle(key, 1);
        }

        expect(visited.size).toBe(13);
        expect(key).toBe('outlined');
    });
});

describe('styleName', () => {
    it('names a style, and an unknown one as the built-in one', () => {
        expect(styleName('freeform_mono')).toBe('Free-form Mono');
        expect(styleName('removed_style')).toBe('Outlined');
        expect(styleIndex(null)).toBe(0);
    });
});

describe('styleThumbnail', () => {
    it('points at the thumbnail in the invoice language', () => {
        expect(styleThumbnail('andriichello_sakura', 'uk')).toBe('/images/invoice-styles/andriichello_sakura-uk.png');
    });
});
