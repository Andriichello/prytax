/**
 * What is left to pay of a tax per quarter, from the reconciler's running balances, and the payment of a receipt's tax.
 */

import type { Amount, ReconciledQuarter, TaxPayment, TaxReconciliation, TaxType } from '@/api/types';
import { compareAmounts, subtractAmounts } from '@/utils/money';

/** A debt as a positive amount: a negative balance's absolute value, else 0. */
function debtOf(balance: Amount): Amount {
    return compareAmounts(balance, '0') < 0 ? balance.replace('-', '') : '0.00';
}

/** The smaller of two amounts. */
function smaller(a: Amount, b: Amount): Amount {
    return compareAmounts(a, b) <= 0 ? a : b;
}

/**
 * The amount each quarter of the year still has to be paid for, keyed by quarter.
 *
 * A quarter's running balance carries the debts of the quarters before it, so paying it whole would pay an earlier
 * debt twice (once on its own quarter's card, once on the later one). A quarter pays what its running balance owes
 * beyond the debt it started with: `max(0, −balance_q) − max(0, −balance_{q−1})`. Money paid into a later quarter
 * covers the oldest debts first, as on the integrated card, so the debts are capped by what the year's last quarter
 * still owes, counting from the newest quarter back.
 */
export function amountsToPay(reconciliation: TaxReconciliation): Map<number, Amount> {
    const quarters = [...reconciliation.quarters].sort((a, b) => a.quarter - b.quarter);
    const amounts = new Map<number, Amount>();

    let left = debtOf(quarters[quarters.length - 1]?.balance ?? '0.00');

    for (const quarter of [...quarters].reverse()) {
        const started = subtractAmounts(quarter.balance, quarter.quarter_balance);
        const own = subtractAmounts(debtOf(quarter.balance), debtOf(started));
        const due = smaller(compareAmounts(own, '0') > 0 ? own : '0.00', left);

        amounts.set(quarter.quarter, due);
        left = subtractAmounts(left, due);
    }

    return amounts;
}

/**
 * Whether a quarter is to be paid now: its deadline came (due or overdue) and something of its own is left.
 */
export function isToPay(quarter: ReconciledQuarter, amount: Amount | undefined): boolean {
    return (quarter.state === 'due' || quarter.state === 'overdue') && amount !== undefined && compareAmounts(amount, '0') > 0;
}

/**
 * The payment of a receipt's ЄП or ВЗ (design.md §8.5): the one that didn't fail. Without it the tax is unpaid,
 * whatever attempts failed, as on Податки's ledger, so its row records a new payment.
 */
export function receiptTaxPayment(taxPayments: TaxPayment[] | undefined, type: TaxType): TaxPayment | null {
    return taxPayments?.find((item) => item.tax_type === type && item.status !== 'failed') ?? null;
}
