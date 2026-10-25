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
            'Net income is income minus fees, ЄП, ВЗ and ЄСВ; the spread is shown separately, as it’s an exchange loss, not a fee.',
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
        closed: 'settled',
        dueBy: 'by {date}',
        accrued: 'Accrued',
        paid: 'Paid',
        forMonth: 'for {month}',
        allTaxes: 'All taxes',
        allStatuses: 'All statuses',
        summaryTitle: 'Summary of the period',
        total: 'Taxes in total',
        sent: 'Sent',
        failedNotCounted: '{amount} failed (not counted)',
        byMonth: 'Taxes by month',
        receivedOn: 'received {date}',
        footnote: 'In each month: first what isn’t paid (ЄП and ВЗ in the month of the receipt, ЄСВ in the month it is for), then «Failed», «Sent» and «Paid». «Sent» doesn’t lower the debt; «Failed» is never counted.',
        empty: 'No payments for the chosen period and taxes.',
        filterTaxes: 'Taxes',
        filterStatuses: 'Status',
        taxesHint: 'Choose one or more. With none chosen, all are shown.',
        statusesHint: 'The summary counts the chosen statuses only. With none chosen, all are shown.',
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
        receiptOnly: 'ЄП and ВЗ are paid from a receipt only; ЄСВ separately, by month.',
        accruedFrom: '{rate} of {base} ₴',
        accruedFromReceipt: 'accrued from the receipt',
        paidOn: 'Payment date',
        primary: 'Primary',
        primaryHint: 'The primary one is set in «Taxes and notifications». From a currency account the bank first sells the currency into the ФОП’s UAH account.',
        writtenOff: 'Debited, {currency}',
        writtenOffHint: 'What left the account, from the bank statement. The bank’s rate isn’t the NBU’s, so you type the amount.',
        bankRate: 'The bank’s rate: 1 {currency} = {rate} ₴',
        esvTitle: 'Paying ЄСВ',
        payBy: 'pay by {date}',
        alreadyPaidFor: 'Already paid for {month}: {date} · {amount}',
        esvMinimum: 'The minimum contribution for a month is {amount} ₴ (22 % of the minimum wage). You may pay more.',
        deletePayment: 'Delete the payment',
        deleteQuestion: 'Delete the payment?',
        deleteExplained: 'The payment leaves the tax books, and what it paid is owed again.',
    },

} satisfies Translation<typeof uk>;
