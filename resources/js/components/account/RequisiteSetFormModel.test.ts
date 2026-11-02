import { describe, expect, it } from 'vitest';
import { blankRequisiteSet, isBlankRequisiteSet, requisiteSetBody } from '@/components/account/RequisiteSetFormModel';

describe('requisiteSetBody', () => {
    it('sends the codes upper case without spaces, blanks as null and only filled details', () => {
        const body = requisiteSetBody({
            ...blankRequisiteSet(),
            name: ' SEPA ',
            iban: 'gb33 clju 0413 0780 0000 00',
            bic: 'cljugb21',
            details: [{ label: 'Address', value: ' Uzhhorod ' }, { label: ' ', value: 'x' }],
        });

        expect(body).toEqual({
            name: 'SEPA', holder_name: null, bank_name: null, account_number: null,
            iban: 'GB33CLJU04130780000000', swift: null, bic: 'CLJUGB21', details: [{ label: 'Address', value: 'Uzhhorod' }],
        });
    });
});

describe('isBlankRequisiteSet', () => {
    it('is blank with a name alone, and not with any requisite', () => {
        expect(isBlankRequisiteSet({ ...blankRequisiteSet(), name: 'SEPA', details: [{ label: '', value: '' }] })).toBe(true);
        expect(isBlankRequisiteSet({ ...blankRequisiteSet(), swift: 'UNJSUAUKXXX' })).toBe(false);
    });
});
