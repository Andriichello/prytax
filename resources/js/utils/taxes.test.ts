import { describe, expect, it } from 'vitest';
import type { TaxPayment } from '@/api/types';
import { receiptTaxPayment } from '@/utils/taxes';

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
