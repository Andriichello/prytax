import type { Translation } from '@/i18n/types';
import type uk from '@/i18n/uk/core';

/**
 * The English of `i18n/uk/core.ts`.
 */
export default {
    common: {
        save: 'Save',
        delete: 'Delete',
        cancel: 'Cancel',
        month: 'Month',
        quarter: 'Quarter',
        year: 'Year',
        currency: 'Currency',
        period: 'Period',
        date: 'Date',
        note: 'Note',
        name: 'Name',
        status: 'Status',
        total: 'Total',
        number: 'Number',
        amountInCurrency: 'Amount, {currency}',
        active: 'Active',
        inactive: 'Inactive',
        chooseAccount: 'Choose an account',
        yesDelete: 'Yes, delete',
        asOf: 'As of',
        asOfDate: 'as of {date}',
        optional: 'Optional',
        plusAdd: '+ Add',
        add: 'Add',
        edit: 'Edit',
        done: 'Done',
        retry: 'Retry',
        loading: 'Loading…',
        new: 'New',
        saveChanges: 'Save changes',
        moreActions: 'More actions',
        fromDate: 'from {date}',
        untilDate: 'until {date}',
        previousYear: 'Previous year',
        nextYear: 'Next year',
        nothingFound: 'Nothing found.',
        overdue: 'overdue',
        overdueSince: 'overdue since {date}',
        client: 'Client',
        clients: 'Clients',
        contract: 'Contract',
        project: 'Project',
        workType: 'Work type',
        invoice: 'Invoice',
        address: 'Address',
        tax: 'Tax',
        recipient: 'Recipient',
        receipts: 'Receipts',
        receipt: 'Receipt',
        paid: 'Paid',
    },

    invoiceStatus: {
        draft: 'Draft',
        ready: 'Ready',
        sent: 'Sent',
        paid: 'Paid',
        cancelled: 'Cancelled',
    },

    alertSeverity: {
        info: 'Info',
        warning: 'Warning',
        error: 'Urgent',
    },

    declarationStatus: {
        none: 'Not filed',
        filled: 'Filled in',
        submitted: 'Filed',
        accepted: 'Accepted',
        rejected: 'Rejected',
        corrected: 'Correction needed',
    },

    taxPaymentStatus: {
        sent: 'Sent',
        paid: 'Paid',
        failed: 'Failed',
        unpaid: 'Not paid',
        sentOn: 'sent {date}',
        paidOn: 'paid {date}',
        failedShort: 'failed',
        unpaidShort: 'not paid',
    },

    taxes: {
        singleTax: 'Single tax',
        militaryLevy: 'Military levy',
        socialContribution: 'SSC',
    },

    accountKinds: {
        platform: 'Platform',
        fopBank: 'FOP account in Ukraine',
        foreignBank: 'Foreign bank',
        personal: 'Personal',
    },

    accountGroups: {
        platform: 'Platforms',
        fopBank: 'FOP accounts in Ukraine',
        foreignBank: 'Foreign banks',
        personal: 'Personal',
    },

    movementKinds: {
        bankFee: 'Bank fee',
        expense: 'Expense',
        ownerWithdrawal: 'Owner withdrawal',
        interest: 'Interest',
        other: 'Other movement',
    },

    roles: {
        owner: 'owner',
        accountant: 'accountant',
        viewer: 'viewer',
    },

    counts: {
        invoices: { one: '{count} invoice', other: '{count} invoices' },
        payments: { one: '{count} receipt', other: '{count} receipts' },
        contracts: { one: '{count} contract', other: '{count} contracts' },
        days: { one: '{count} day', other: '{count} days' },
        transfers: { one: '{count} transfer', other: '{count} transfers' },
    },

    quarters: {
        q1: 'Q1',
        q2: 'Q2',
        q3: 'Q3',
        q4: 'Q4',
    },

    quarterNames: {
        q1: 'Q1',
        q2: 'Q2',
        q3: 'Q3',
        q4: 'Q4',
    },

    errors: {
        sessionEnded: 'Your session has ended. Sign in again.',
        forbidden: 'You don’t have permission to do this.',
        notFound: 'Not found.',
        conflict: 'The record has changed or is locked. Reload the page.',
        sessionExpired: 'The session has expired. Reload the page.',
        checkForm: 'Check the form.',
        tooManyAttempts: 'Too many attempts. Wait a minute and try again.',
        somethingWentWrong: 'Something went wrong. Try again.',
        offline: 'Couldn’t reach the server. Check your connection and try again.',
    },

    apiMessages: {
        accountSuspended: 'This account is suspended. Contact the organization’s owner.',
        passwordUpdateRequired: 'Change your password to continue.',
        invitationInvalid: 'The invitation link is invalid, has expired or has been used. Ask to be invited again.',
        severalOrganizations: 'This email is used in several organizations. Contact the organization’s owner.',
    },

    accordion: {
        editing: 'Editing',
        oneAtATime: 'One group is open at a time; the others are dimmed until you save or cancel.',
    },
} satisfies Translation<typeof uk>;
