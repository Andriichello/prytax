import { describe, expect, it } from 'vitest';
import { splitFigures } from '@/utils/text';

describe('splitFigures', () => {
    it('sets an invoice number with the client\'s abbreviation as one figure', () => {
        expect(splitFigures('Інвойс BL-2026-08-31-11 прострочено')).toEqual([
            { text: 'Інвойс ', figure: false },
            { text: 'BL-2026-08-31-11', figure: true },
            { text: ' прострочено', figure: false },
        ]);
    });

    it('sets an invoice number without the abbreviation as one figure', () => {
        expect(splitFigures('Інвойс 2026-08-31-11')).toEqual([
            { text: 'Інвойс ', figure: false },
            { text: '2026-08-31-11', figure: true },
        ]);
    });
});
