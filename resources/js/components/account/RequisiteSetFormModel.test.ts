import { describe, expect, it } from 'vitest';
import { blankRequisiteSet, isBlankRequisiteSet, ownRow, requisiteSetBody, type RequisiteSetForm } from '@/components/account/RequisiteSetFormModel';

/** The blank set, with the values and labels given by field, and rows of one's own after it. */
function formWith(values: Record<string, { value?: string; label?: string; shown?: boolean }>, own: { label: string; value: string }[] = []): RequisiteSetForm {
    const form = blankRequisiteSet();

    form.rows = [
        ...form.rows.map((row) => ({ ...row, ...values[row.field!] })),
        ...own.map((row) => ({ ...ownRow(), ...row })),
    ];

    return form;
}

describe('requisiteSetBody', () => {
    it('sends the values (the IBAN and the code compact), the labels given, its own filled rows and the order of them all', () => {
        const form = formWith({
            holder_name: { value: ' PE PRYKHODKO ANDRIY ', label: ' Beneficiary name ' },
            iban: { value: 'ua21 3223 1300 0002 6007 2335 6600 1' },
            swift: { value: 'unjsuaukxxx', label: 'SWIFT code', shown: false },
        }, [{ label: 'Address', value: ' Uzhhorod ' }, { label: ' ', value: 'dropped' }]);
        form.name = ' SWIFT ';

        expect(requisiteSetBody(form)).toEqual({
            name: 'SWIFT',
            holder_name: 'PE PRYKHODKO ANDRIY', bank_name: null, iban: 'UA213223130000026007233566001', account_number: null, swift: 'UNJSUAUKXXX',
            details: [{ label: 'Address', value: 'Uzhhorod' }],
            labels: { holder_name: 'Beneficiary name', swift: 'SWIFT code' },
            invoice_requisites: [
                { field: 'holder_name', shown: true }, { field: 'bank_name', shown: true }, { field: 'iban', shown: true },
                { field: 'account_number', shown: true }, { field: 'swift', shown: false }, { label: 'Address', shown: true },
            ],
        });
    });
});

describe('isBlankRequisiteSet', () => {
    it('is blank with labels alone, and not with any value', () => {
        expect(isBlankRequisiteSet(formWith({ holder_name: { label: 'Beneficiary name' } }))).toBe(true);
        expect(isBlankRequisiteSet(formWith({ swift: { value: 'UNJSUAUKXXX' } }))).toBe(false);
    });
});
