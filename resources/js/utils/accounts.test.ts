import { describe, expect, it } from 'vitest';
import type { InvoiceRequisiteEntry } from '@/api/types';
import { orderedRequisites, requisiteOrder } from '@/utils/accounts';

const account = (invoiceRequisites: InvoiceRequisiteEntry[] | null) => ({
    holder_name: 'Myrchuk Mykola',
    bank_name: 'Payoneer',
    iban: null,
    account_number: '70450600001112233',
    swift: 'CITIUS33',
    labels: null as Record<string, string> | null,
    details: [{ label: 'Routing (ABA)', value: '022200101' }],
    invoice_requisites: invoiceRequisites,
});

describe('orderedRequisites', () => {
    it('gives the fields with a value, then the details, all printed, without an order', () => {
        expect(orderedRequisites(account(null)).map(({ label, shown }) => [label, shown])).toEqual([
            ['Отримувач', true], ['Банк', true], ['Account number', true], ['SWIFT / BIC', true], ['Routing (ABA)', true],
        ]);
    });

    it('follows the order, switched off ones included, then gives those it does not name, printed', () => {
        const ordered = orderedRequisites(account([
            { label: 'Routing (ABA)', shown: true },
            { field: 'account_number', shown: true },
            { field: 'holder_name', shown: false },
            { field: 'swift', shown: true },
            { label: 'Removed since', shown: true },
        ]));

        expect(ordered.map(({ label, shown }) => [label, shown])).toEqual([
            ['Routing (ABA)', true], ['Account number', true], ['Отримувач', false], ['SWIFT / BIC', true], ['Банк', true],
        ]);
    });
});

describe('orderedRequisites with labels', () => {
    it('names a field by its own label, and with empty, gives the fields without a value with their own labels alone', () => {
        const set = { ...account(null), labels: { holder_name: 'Beneficiary name' } };

        expect(orderedRequisites(set).map(({ label }) => label).slice(0, 2)).toEqual(['Beneficiary name', 'Банк']);
        expect(orderedRequisites(set, { empty: true }).map(({ field, label }) => [field, label])).toEqual([
            ['holder_name', 'Beneficiary name'], ['bank_name', ''], ['iban', ''], ['account_number', ''], ['swift', ''], [null, 'Routing (ABA)'],
        ]);
    });
});

describe('requisiteOrder', () => {
    it('names the fields by their columns and the details by their labels', () => {
        const ordered = orderedRequisites(account([{ label: 'Routing (ABA)', shown: false }]));

        expect(requisiteOrder(ordered)).toEqual([
            { label: 'Routing (ABA)', shown: false },
            { field: 'holder_name', shown: true },
            { field: 'bank_name', shown: true },
            { field: 'account_number', shown: true },
            { field: 'swift', shown: true },
        ]);
    });
});
