/**
 * The payment of a receipt's tax.
 */

import type { TaxPayment, TaxType } from '@/api/types';

/**
 * The payment of a receipt's ЄП or ВЗ (design.md §8.5): the one that didn't fail. Without it the tax is unpaid,
 * whatever attempts failed, as on Податки's ledger, so its row records a new payment.
 */
export function receiptTaxPayment(taxPayments: TaxPayment[] | undefined, type: TaxType): TaxPayment | null {
    return taxPayments?.find((item) => item.tax_type === type && item.status !== 'failed') ?? null;
}
