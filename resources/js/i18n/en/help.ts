import type { Translation } from '@/i18n/types';
import type uk from '@/i18n/uk/help';

/**
 * The English of `i18n/uk/help.ts`.
 */
export default {
    title: 'Help',
    subtitle: 'What you can do in PryTax, and how',
    intro: 'PryTax keeps the books of a group 3 ФОП: invoices to clients, receipts, transfers between accounts, ЄП, ВЗ and ЄСВ, the income book. Below is where to find each thing and how to do it. Open a question to read its answer.',
    search: 'Search the help',
    searchPlaceholder: 'E.g. pay ЄСВ',
    open: 'Open «{page}»',

    firstSteps: {
        title: 'First steps',
        hint: 'To issue your first invoice, set up the ФОП in this order:',
        profile: {
            title: 'ФОП profile',
            text: 'Full name, tax ID, address and contacts, which invoices print. The PDF signature and the money format are here too.',
        },
        accounts: {
            title: 'Accounts',
            text: 'The platforms, foreign banks and ФОП accounts you get paid into, with the payment details for invoices. One account per currency.',
        },
        taxes: {
            title: 'Taxes and notifications',
            text: 'The accounts you pay taxes from, the budget’s payment details, opening balances if your books don’t start from the beginning, and reminders.',
        },
        clients: {
            title: 'Clients and contracts',
            text: 'A client with an abbreviation, which prefixes the numbers of its invoices, and a contract: currency, rate, payment terms.',
        },
        document: {
            title: 'Invoice document',
            text: 'The PDF style, the language and the texts of invoices. Next to it, «Invoice numbering» sets the number format.',
        },
        invoice: {
            title: 'Your first invoice',
            text: 'Create an invoice, preview its PDF and freeze it — that gives it its number.',
        },
    },

    home: {
        title: 'Home',
        lead: 'Only what needs your attention: open and overdue invoices, drafts, and money still sitting on platforms. When nothing does, it says so.',
        quick: {
            title: 'Quick actions',
            text: '«New invoice» and «New receipt» are the shortest way to the two most frequent tasks. They show for those whose role may create.',
        },
        transfer: {
            title: 'What «Due for transfer» means',
            text: 'A receipt’s money has stayed on a platform or in a foreign bank longer than the rule in «Taxes and notifications → Money on platforms» allows. «New transfer» opens a transfer from the account of the oldest of them.',
        },
    },

    invoices: {
        title: 'Invoices',
        lead: 'You create an invoice in PryTax or upload a ready PDF. While it’s a draft it can change; freezing gives it a number, and then it no longer changes.',
        create: {
            title: 'Create an invoice',
            text: '«New» on «Invoices», or «New invoice» on «Home». Fill in the groups «Client and contract», «Period and dates» and «Getting paid», then add the items: the invoice takes the currency and the rate from the contract; without a contract you type each price. The draft is saved after each group, so you can close it and carry on later.',
        },
        external: {
            title: 'Upload a ready PDF',
            text: 'For older invoices, or when the client issues the invoice: in a new invoice choose «External PDF», add the file and type its number and total. If the same file is already in another invoice, PryTax tells you.',
        },
        preview: {
            title: 'Preview the PDF',
            text: 'A saved draft has «Preview PDF»: the invoice as freezing would print it, marked as a draft. A frozen one has the «Document» card, where the PDF can be previewed and downloaded.',
        },
        freeze: {
            title: 'Freeze and send',
            text: '«Freeze» assigns the number, keeps the ФОП’s and the client’s details as they are now and makes the PDF. Download it, send it to the client and mark the invoice: «Mark as sent».',
        },
        statuses: {
            title: 'Invoice statuses',
            text: 'Draft → Ready (frozen) → Sent → Paid. «Mark as paid» becomes available once the receipts cover the total. A cancelled invoice stays in the list with its number struck through, and that number is never used again.',
        },
        fix: {
            title: 'Correct a frozen invoice',
            text: 'While it has no receipts, «Return to draft»: the number is kept, and the invoice can be changed and frozen again. If it has receipts, cancel it and issue a new one, then move the receipts to it by editing each receipt.',
        },
        find: {
            title: 'Find an invoice',
            text: 'The filters above the list: period, client, status and currency. The list runs from the newest to the oldest, by the month of issue.',
        },
    },

    receipts: {
        title: 'Receipts',
        lead: 'A receipt is a client’s money that reached your account or platform. It is the income: the credit date sets its quarter, the NBU rate of that day its hryvnias, and ЄП and ВЗ are counted from each receipt.',
        record: {
            title: 'Record a receipt',
            text: '«Add a receipt» on the invoice’s page, or «Receipt» on «Accounting», then choose the invoice. Enter the account, the amount received, the credit date and the fee. Before saving you see what will be stored: the hryvnias, ЄП, ВЗ and the quarter.',
        },
        fee: {
            title: 'The platform or the bank kept a fee',
            text: 'The income is the full invoiced amount, even when less arrived. Enter what was kept in «Fee on receipt»: it doesn’t lower the taxes, but shows what you lose.',
        },
        several: {
            title: 'One payment for several invoices',
            text: 'In the receipt’s form, «Another invoice in this payment». The invoices must be in one currency; each gets its own receipt.',
        },
        virtual: {
            title: 'A payment without an invoice',
            text: 'An advance or another payment under the contract without an invoice: when choosing the invoice, «Paid under the contract, no invoice». It becomes a «No invoice» record for the books only: the client gets nothing, and no number is used. Later it can be issued as an invoice or attached to an issued one.',
        },
        rate: {
            title: 'The NBU rate',
            text: 'The rate is taken by itself, for the credit date. You can type one only while the NBU hasn’t published the rate of that date; the record then takes the NBU’s.',
        },
    },

    transfers: {
        title: 'Transfers and fees',
        lead: 'A transfer is money moving between your own accounts: from a platform to a ФОП account, a currency sale. It isn’t income and doesn’t change the taxes.',
        record: {
            title: 'Record a transfer',
            text: '«{settings} → Transfers and fees» → «New», or «New transfer» on «Home». Enter where from and where to, what was sent and what arrived. PryTax suggests whose receipts’ money you are moving — the oldest first, or an exact match; the amounts can be changed.',
        },
        fees: {
            title: 'What the fees cost',
            text: '«Transfers» shows the year’s losses by route and the link «Fees and net income»: fees and the FX spread by quarter, account, client and route. They don’t lower the income for taxes.',
        },
    },

    taxes: {
        title: 'Taxes',
        lead: 'One ledger of ЄП, ВЗ and ЄСВ for the chosen period: what was accrued, sent and paid. ЄП and ВЗ are accrued on each receipt, ЄСВ for every month, even without income.',
        payIncome: {
            title: 'Pay ЄП or ВЗ',
            text: 'Open the tax’s row on «Taxes», or in the receipt under «Paying taxes». The amount is already counted from the receipt. Copy the payment details and the purpose into your bank, then enter the date, the status and the account, and press «Save payment».',
        },
        payEsv: {
            title: 'Pay ЄСВ',
            text: 'On «Taxes», open the ЄСВ row of the month — ahead of time too. The amount is the month’s minimum contribution, and you may pay more. The rest is as with ЄП.',
        },
        statuses: {
            title: 'Sent, paid, failed',
            text: '«Sent» means the payment has gone but the bank hasn’t confirmed it: it doesn’t reduce what you owe. Once the money has left, change the status to «Paid». «Failed» counts nowhere.',
        },
        deadlines: {
            title: 'Deadlines',
            text: 'A quarter’s ЄП and ВЗ are paid within 50 days after it ends, ЄСВ by the 20th of the month after the quarter. Each unpaid row shows its own «pay by» date, and notifications remind you of it ahead.',
        },
        filters: {
            title: 'Choose the period and the taxes',
            text: 'The filters above the ledger: the quarters of a year (one, several in a row, or the whole year), the taxes and the statuses. The summary counts only what is chosen.',
        },
        declaration: {
            title: 'The declaration',
            text: 'PryTax counts the figures but doesn’t file the declaration: you file it in the State Tax Service’s Electronic Cabinet. ЄП and ВЗ cumulative from the start of the year, as the declaration has them, are in «Accounting books → Taxes».',
        },
    },

    accounting: {
        title: 'Accounting',
        lead: 'A quarter on one page: the income per currency, the income for taxes in hryvnias, the quarter’s taxes and all of its receipts.',
        view: {
            title: 'Group and show',
            text: 'The filters above the list: the year and the quarter, and the grouping — by month, by invoice or ungrouped. The switches «Taxes» and «Fees and net» show each receipt’s ЄП and ВЗ, or its fees and net amount.',
        },
        export: {
            title: 'Export for your accountant',
            text: 'The «Export» block: CSV is the quarter’s table of receipts, «Archive» is all the data and PDFs of a period in a ZIP. The archive isn’t a backup: a ФОП can’t be restored from it.',
        },
    },

    books: {
        title: 'Accounting books',
        lead: 'The income book and the taxes since the start of the year — what the tax office or an accountant needs. They open from the book icon at the top of a page, and on a computer from the sidebar too.',
        income: {
            title: 'Income book',
            text: 'Every receipt by its credit date, in hryvnias at the NBU rate, by month and quarter, with the total since the start of the year and what is left of the group 3 limit. A refund to a client reduces the income of the day it was made.',
        },
        taxes: {
            title: 'Taxes since the start of the year',
            text: 'Accrued, sent and paid: by quarter, cumulative as in the declaration, or by tax.',
        },
        pdf: {
            title: 'Download a PDF',
            text: '«Export» at the foot of a tab saves it for the chosen year as a PDF.',
        },
    },

    alerts: {
        title: 'Notifications',
        lead: 'The bell at the top reminds you of the declaration’s and the taxes’ deadlines, money left on platforms, the limits of accounts and of income, contracts that are ending, and overdue or unsent invoices.',
        read: {
            title: 'Read a notification',
            text: 'A notification becomes read when you tap it, its action or its check mark. Opening the page alone doesn’t read it.',
        },
        settings: {
            title: 'Set up reminders',
            text: '«{settings} → Taxes and notifications» → «Notifications»: when to remind you of money on platforms, how many days ahead to warn of deadlines, and when to remind you of unsent invoices.',
        },
    },

    clients: {
        title: 'Clients and contracts',
        lead: 'A client sets the abbreviation of the numbers, the address and the details on the invoice; a contract sets the currency, the rates and the payment terms, which a new invoice takes by itself.',
        add: {
            title: 'Add a client',
            text: '«{settings} → Clients and contracts» → «New». The abbreviation (Latin letters and digits, up to 10) prefixes the numbers of its invoices. Type the address as it should print, line by line, with the country.',
        },
        contract: {
            title: 'Add a contract',
            text: '«New contract» on the client’s page: the subject, the dates, the currency, the payment terms, the item description on invoices and the hourly rates. A rate applies from its «from» date, and a new invoice takes the one in force at the end of its service period.',
        },
        own: {
            title: 'A client’s own invoice settings',
            text: 'A client may have its own currency, language, style and invoice texts. Empty fields take the ФОП’s values, and say «As the ФОП’s».',
        },
        inactive: {
            title: 'A client no longer works with you',
            text: 'Clients aren’t deleted but made inactive: their invoices and receipts stay in the books, and new invoices don’t offer them.',
        },
    },

    accounts: {
        title: 'Accounts',
        lead: 'Where money comes in and goes out: platforms, foreign banks, ФОП accounts in Ukraine and personal ones. One account per currency.',
        add: {
            title: 'Add an account',
            text: '«{settings} → Accounts» → «New»: the name, the kind, the currency and the balance on a date. The payment details for invoices print in the payment block of each invoice paid into this account.',
        },
        balance: {
            title: 'An account’s balance',
            text: 'The balance is counted from the receipts, transfers, taxes and movements you recorded. Add bank fees, interest, expenses and withdrawals on the account’s page as money movements — they aren’t the ФОП’s income.',
        },
        limit: {
            title: 'An account’s limit',
            text: 'An account can have a limit of receipts per month, quarter or year — a notification warns you when it is nearly used up.',
        },
    },

    document: {
        title: 'Invoice numbering and document',
        lead: 'How invoices are numbered and how their PDF looks. Changes apply to new invoices and drafts; frozen ones don’t change.',
        numbering: {
            title: 'Numbering',
            text: '«{settings} → Invoice numbering»: the client’s abbreviation in front, the date in the number, the counter — one per ФОП or one per client — and its yearly reset. The «Next number» card shows what it will be.',
        },
        style: {
            title: 'Style and language',
            text: '«{settings} → Invoice document»: the PDF style with a preview, the document’s language and the contract section.',
        },
        texts: {
            title: 'Invoice texts',
            text: 'Also there: the payment purpose with placeholders (number, period, services), the act of acceptance note, the payment note and another note. Each but the purpose can be switched off.',
        },
        cascade: {
            title: 'ФОП, client, invoice',
            text: 'The ФОП’s settings apply to every client; a client may have its own, and a single invoice its own over the client’s. Each field shows where its value comes from: «Default», «As the ФОП’s», «As the client’s» or its own.',
        },
        profile: {
            title: 'Signature and money format',
            text: '«{settings} → Profile and details»: the signature printed in the PDF, and the money format — separately for the app and for invoices.',
        },
    },

    profiles: {
        title: 'Several ФОП',
        lead: 'An organization may have several ФОП. You work with one at a time: each has its own invoices, books, taxes and settings, while users and roles are shared.',
        switch: {
            title: 'Switch the ФОП',
            text: '«Switch ФОП» on the ФОП card — in the sidebar on a computer, or on «More» on a phone. An inactive ФОП opens too, to read its history.',
        },
        add: {
            title: 'Add a ФОП',
            text: 'Owners only: «New» in the list of the organization’s ФОП. Once created, its taxes and notifications open.',
        },
        close: {
            title: 'Close a ФОП',
            text: 'The «Active ФОП» switch in its profile: taxes are counted up to the closing date, and the history stays.',
        },
    },

    users: {
        title: 'Users and roles',
        lead: 'Every user sees every ФОП of the organization; what they may do depends on their role.',
        roles: {
            title: 'Roles',
            text: 'Owner — everything, including the ФОП and the users. Accountant — every financial operation of every ФОП and their settings, but the taxpayer’s details. Viewer — reading only.',
        },
        invite: {
            title: 'Invite someone',
            text: 'Owners: «{settings} → Users and roles» → «Invite» — the name, the e-mail and the role. The person gets an e-mail and sets a password. An invitation can be sent again, and a role changed.',
        },
    },

    backups: {
        title: 'Backups',
        lead: 'Copies of each ФОП’s data, on a schedule and on demand, in «{settings} → Backups».',
        create: {
            title: 'Make and download a copy',
            text: '«Back up now» makes a copy at once. Each ФОП’s archive can be downloaded on its own, and «All ФОП · ZIP» all of them together.',
        },
        policy: {
            title: 'Schedule and retention',
            text: 'How often copies are made, how many are kept and what they include. Owners change it.',
        },
        restore: {
            title: 'Restore a ФОП from an archive',
            text: 'An owner restores a ФОП from a downloaded archive as a new ФОП of the organization, never over an existing one. «Check» first tells whose archive it is and what it holds.',
        },
        recoveryKit: {
            title: 'Recovery kit',
            text: 'In the desktop app, keep the «Recovery kit» away from the computer: without it a copy of the database can’t be read. It can be downloaded again on «Backups».',
        },
    },

    account: {
        title: 'Language, sign-in and password',
        lead: 'Your own settings: they change neither the ФОП nor the other users.',
        language: {
            title: 'App language',
            text: 'The globe at the foot of the sidebar on a computer, or «More → App language» on a phone. It is the interface’s language only; the invoices’ language is in «Invoice document» and on the client’s card.',
        },
        remember: {
            title: 'Stay signed in',
            text: '«Remember me» when signing in keeps you signed in after the browser is closed.',
        },
        password: {
            title: 'Forgot your password',
            text: '«Forgot your password?» on the sign-in page e-mails you a link to set a new one.',
        },
    },
} satisfies Translation<typeof uk>;
