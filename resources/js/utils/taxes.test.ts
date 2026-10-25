import { describe, expect, it } from 'vitest';
import type { ReconciledQuarter, TaxPayment, TaxReconciliation } from '@/api/types';
import { amountsToPay, receiptTaxPayment } from '@/utils/taxes';

/**
 * A quarter of the reconciliation with its own paid − owed and its running balance.
 */
function quarter(number: number, quarterBalance: string, balance: string): ReconciledQuarter {
    return {
        year: 2026,
        quarter: number,
        owed: '0.00',
        paid: '0.00',
        pending: '0.00',
        failed: '0.00',
        quarter_balance: quarterBalance,
        balance,
        due_at: '2026-01-01',
        state: 'due',
        pending_payments: [],
    } as ReconciledQuarter;
}

function reconciliation(quarters: ReconciledQuarter[]): TaxReconciliation {
    return { tax_type: 'single_tax', year: 2026, opening_balance: null, balance: quarters.at(-1)!.balance, quarters };
}

describe('amountsToPay', () => {
    it('asks each quarter for its own debt only, not again for the debts it carries', () => {
        const amounts = amountsToPay(reconciliation([
            quarter(1, '-100.00', '-100.00'),
            quarter(2, '-50.00', '-150.00'),
        ]));

        expect(amounts.get(1)).toBe('100.00');
        expect(amounts.get(2)).toBe('50.00');
    });

    it('lets money paid into a later quarter cover the oldest debts first', () => {
        const amounts = amountsToPay(reconciliation([
            quarter(1, '-100.00', '-100.00'),
            quarter(2, '60.00', '-40.00'),
        ]));

        expect(amounts.get(1)).toBe('40.00');
        expect(amounts.get(2)).toBe('0.00');
    });

    it('asks nothing of a year that ends overpaid', () => {
        const amounts = amountsToPay(reconciliation([
            quarter(1, '-100.00', '-100.00'),
            quarter(2, '300.00', '200.00'),
        ]));

        expect([...amounts.values()]).toEqual(['0.00', '0.00']);
    });
});

/** A receipt's payment of a tax with a status. */
function taxPayment(id: number, taxType: TaxPayment['tax_type'], status: TaxPayment['status']): TaxPayment {
    return { id, tax_type: taxType, status, amount: '2050.00', payment_id: 1 } as TaxPayment;
}

describe('receiptTaxPayment', () => {
    it('takes the payment of the tax, which didn\'t fail, after a failed attempt', () => {
        const payments = [taxPayment(1, 'single_tax', 'failed'), taxPayment(2, 'military_levy', 'paid'), taxPayment(3, 'single_tax', 'sent')];

        expect(receiptTaxPayment(payments, 'single_tax')?.id).toBe(3);
        expect(receiptTaxPayment(payments, 'military_levy')?.id).toBe(2);
    });

    it('leaves a tax unpaid, which only failed, so its row records a new payment', () => {
        expect(receiptTaxPayment([taxPayment(1, 'single_tax', 'failed')], 'single_tax')).toBeNull();
        expect(receiptTaxPayment(undefined, 'single_tax')).toBeNull();
    });
});
