import { describe, expect, it } from 'vitest';
import { normalizeAbbreviation, suggestAbbreviation } from '@/utils/clients';

describe('suggestAbbreviation', () => {
    it.each([
        ['Brightline Labs LLC', 'BL'],
        ['Brightline Labs, L.L.C.', 'BL'],
        ['Orbit Analytics Inc.', 'OA'],
        ['Lumen Systems GmbH', 'LS'],
        ['Nordwave GmbH', 'NOR'],
        ['Acme', 'ACM'],
        ['Blue Ocean Data Works Group', 'BODW'],
        ['3D Print Hub', '3PH'],
    ])('suggests the initials of %s: %s', (name, abbreviation) => {
        expect(suggestAbbreviation(name)).toBe(abbreviation);
    });

    it('suggests nothing for a name without Latin letters or digits', () => {
        expect(suggestAbbreviation('ТОВ «Ромашка»')).toBe('');
        expect(suggestAbbreviation('')).toBe('');
    });
});

describe('normalizeAbbreviation', () => {
    it('keeps upper-case Latin letters and digits only', () => {
        expect(normalizeAbbreviation('bl-2')).toBe('BL2');
        expect(normalizeAbbreviation(' Nw ')).toBe('NW');
        expect(normalizeAbbreviation('БЛ')).toBe('');
    });
});
