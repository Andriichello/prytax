import type { Translation } from '@/i18n/types';
import type uk from '@/i18n/uk/accounting';

/**
 * The English of `i18n/uk/accounting.ts`.
 */
export default {

    quarters: {
        feesNet: 'Fees and net',
        noReceipts: 'No receipts in {quarter} {year}.',
        quarterCsv: 'Quarter CSV',
        yearCsv: 'Year CSV',
        byMonth: 'By month',
        byInvoice: 'By invoice',
        ungrouped: 'Ungrouped',
        summary: 'The quarter',
    },

    fees: {
        intro: 'What the money’s way from the client to hryvnias costs: fees and FX spread.',
        noReceiptsTransfers: 'No receipts or transfers in {year}.',
        yearTotals: 'Year totals',
        lossShare: 'Fees and spread cost {percent} of income.',
        netIncomeExplained:
            'Net income is income minus fees, single tax, military levy and SSC; the spread is shown separately, as it’s an exchange loss, not a fee.',
        byQuarter: 'By quarter',
        losses: 'Losses',
        net: 'Net',
        byAccount: 'By account',
        feesAmount: 'fees {amount}',
        transfersOnly: 'transfers only',
        byClient: 'By client',
        noClient: 'No client',
        byRoute: 'By route',
        feesWithoutRate: 'Transfer fees not counted without an NBU rate: {count}.',
    },

    taxes: {
        view: 'View',
        esv: 'SSC',
        accrual: 'Accrued',
        history: 'Payments',
        forQuarter: 'Taxes of the quarter',
        closed: 'settled',
        dueBy: 'by {date}',
        accrued: 'Accrued',
        paid: 'Paid',
        toPay: 'To pay',
        pendingSent: '+ {amount} sent',
        payEsvFor: 'Pay SSC for {month}',
        byReceipt: 'By receipt',
        noReceipts: 'No receipts in the quarter.',
        receiptsHint: 'The single tax and the military levy are paid from a receipt: tap a tax or «Pay … of this receipt». SSC is paid monthly, with the «SSC» button.',
        historyTitle: 'Payments, newest first',
        forMonth: 'for {month}',
        noTaxPayments: 'No tax payments in {year}.',
        historyHint: '«Sent»: the payment is sent but not confirmed yet, and doesn’t lower the debt. A «Failed» one isn’t counted in the month’s total.',
        notTracked: 'This quarter’s taxes aren’t tracked: it is before the opening balances or outside the FOP’s registration.',
    },

    taxPayment: {
        title: 'Tax payment',
        amountUah: 'Amount, ₴',
        sentIsPending: '“Sent” shows as pending and doesn’t reduce what’s outstanding',
        paidFromAccount: 'Paid from account',
        notSpecified: 'Not specified',
        paymentDetails: '{tax} payment details',
        copyAll: 'Copy all',
        amount: 'Amount',
        noPaymentDetails: 'There are no {tax} payment details for this period. Add them in the tax settings.',
        paymentOrderNumber: 'Payment order number',
        savePayment: 'Save payment',
        receiptOnly: 'The single tax and the military levy are paid from a receipt only; SSC separately, by month.',
        accruedFrom: '{rate} of {base} ₴',
        accruedFromReceipt: 'accrued from the receipt',
        paidOn: 'Payment date',
        primary: 'Primary',
        primaryHint: 'The primary one is set in «Taxes and notifications». From a currency account the bank first sells the currency into the FOP’s UAH account.',
        writtenOff: 'Debited, {currency}',
        writtenOffHint: 'What left the account, from the bank statement. The bank’s rate isn’t the NBU’s, so you type the amount.',
        bankRate: 'The bank’s rate: 1 {currency} = {rate} ₴',
        esvTitle: 'Paying SSC',
        payBy: 'pay by {date}',
        alreadyPaidFor: 'Already paid for {month}: {date} · {amount}',
        esvMinimum: 'The minimum contribution for a month is {amount} ₴ (22 % of the minimum wage). You may pay more.',
        deletePayment: 'Delete the payment',
        deleteQuestion: 'Delete the payment?',
        deleteExplained: 'The payment leaves the tax books, and what it paid is owed again.',
    },

} satisfies Translation<typeof uk>;
