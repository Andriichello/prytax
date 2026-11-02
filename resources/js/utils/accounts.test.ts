import { describe, expect, it } from 'vitest';
import type { InvoiceRequisiteEntry } from '@/api/types';
import { orderedRequisites, requisiteOrder } from '@/utils/accounts';

const account = (invoiceRequisites: InvoiceRequisiteEntry[] | null) => ({
    holder_name: 'Myrchuk Mykola',
    bank_name: 'Payoneer',
    iban: null,
    account_number: '70450600001112233',
    swift: 'CITIUS33',
    bic: null,
    details: [{ label: 'Routing (ABA)', value: '022200101' }],
    invoice_requisites: invoiceRequisites,
});

describe('orderedRequisites', () => {
    it('gives the fields with a value, then the details, all printed, without an order', () => {
        expect(orderedRequisites(account(null)).map(({ label, shown }) => [label, shown])).toEqual([
            ['Отримувач', true], ['Банк', true], ['Account number', true], ['SWIFT', true], ['Routing (ABA)', true],
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
            ['Routing (ABA)', true], ['Account number', true], ['Отримувач', false], ['SWIFT', true], ['Банк', true],
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
