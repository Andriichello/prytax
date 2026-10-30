import type { RouteLocationRaw } from 'vue-router';
import { t } from '@/i18n';

/**
 * Довідка's contents (HelpPage): the first steps of setting up a ФОП, and the topics — what each part of the app is
 * for, its questions with their answers, and the page it is about. They translate when they are built, so the page
 * builds them in a computed value, which follows the language of the screens.
 */

/** A step of setting up a ФОП, leading to the page where it's done. */
export interface HelpStep {
    key: string;
    title: string;
    text: string;
    to: RouteLocationRaw;
}

/** A question of a topic, with its answer. */
export interface HelpQuestion {
    key: string;
    title: string;
    text: string;
}

/** A part of the app: what it is for, its questions, and the page it is about with that page's name. */
export interface HelpTopic {
    key: string;
    title: string;
    lead: string;
    questions: HelpQuestion[];
    page?: { name: string; to: RouteLocationRaw };
}

/** A topic found by a search: the questions shown, and the keys of those, which matched and open. */
export interface FoundTopic extends HelpTopic {
    opened: string[];
}

/** What the topics depend on where they are read. */
export interface HelpContext {
    /** The name of the settings page: Налаштування on desktop, Ще on a phone. */
    settings: string;
    /** Whether the reader sees the backups (owners and accountants). */
    backups: boolean;
    /** Whether the app is the desktop app, whose owner keeps a recovery kit. */
    desktopApp: boolean;
}

/**
 * The steps of setting up a ФОП for its first invoice, in their order.
 */
export function helpSteps(): HelpStep[] {
    return [
        { key: 'profile', title: t('help.firstSteps.profile.title'), text: t('help.firstSteps.profile.text'), to: { name: 'settings-profile' } },
        { key: 'accounts', title: t('help.firstSteps.accounts.title'), text: t('help.firstSteps.accounts.text'), to: { name: 'accounts' } },
        { key: 'taxes', title: t('help.firstSteps.taxes.title'), text: t('help.firstSteps.taxes.text'), to: { name: 'settings-taxes' } },
        { key: 'clients', title: t('help.firstSteps.clients.title'), text: t('help.firstSteps.clients.text'), to: { name: 'clients' } },
        { key: 'document', title: t('help.firstSteps.document.title'), text: t('help.firstSteps.document.text'), to: { name: 'settings-invoice-document' } },
        { key: 'invoice', title: t('help.firstSteps.invoice.title'), text: t('help.firstSteps.invoice.text'), to: { name: 'invoice-new' } },
    ];
}

/**
 * The topics, in the order a ФОП's work goes: the daily pages first, then the settings, then the user's own. Backups
 * are left out for those who don't see them, and the recovery kit outside the desktop app.
 */
export function helpTopics(context: HelpContext): HelpTopic[] {
    const params = { settings: context.settings };

    const topics: HelpTopic[] = [
        {
            key: 'home',
            title: t('help.home.title'),
            lead: t('help.home.lead'),
            questions: [
                { key: 'quick', title: t('help.home.quick.title'), text: t('help.home.quick.text', params) },
                { key: 'transfer', title: t('help.home.transfer.title'), text: t('help.home.transfer.text', params) },
            ],
            page: { name: t('shell.nav.home'), to: { name: 'home' } },
        },
        {
            key: 'invoices',
            title: t('help.invoices.title'),
            lead: t('help.invoices.lead'),
            questions: [
                { key: 'create', title: t('help.invoices.create.title'), text: t('help.invoices.create.text', params) },
                { key: 'external', title: t('help.invoices.external.title'), text: t('help.invoices.external.text', params) },
                { key: 'preview', title: t('help.invoices.preview.title'), text: t('help.invoices.preview.text', params) },
                { key: 'freeze', title: t('help.invoices.freeze.title'), text: t('help.invoices.freeze.text', params) },
                { key: 'statuses', title: t('help.invoices.statuses.title'), text: t('help.invoices.statuses.text', params) },
                { key: 'fix', title: t('help.invoices.fix.title'), text: t('help.invoices.fix.text', params) },
                { key: 'find', title: t('help.invoices.find.title'), text: t('help.invoices.find.text', params) },
            ],
            page: { name: t('shell.nav.invoices'), to: { name: 'invoices' } },
        },
        {
            key: 'receipts',
            title: t('help.receipts.title'),
            lead: t('help.receipts.lead'),
            questions: [
                { key: 'record', title: t('help.receipts.record.title'), text: t('help.receipts.record.text', params) },
                { key: 'fee', title: t('help.receipts.fee.title'), text: t('help.receipts.fee.text', params) },
                { key: 'several', title: t('help.receipts.several.title'), text: t('help.receipts.several.text', params) },
                { key: 'virtual', title: t('help.receipts.virtual.title'), text: t('help.receipts.virtual.text', params) },
                { key: 'rate', title: t('help.receipts.rate.title'), text: t('help.receipts.rate.text', params) },
            ],
            page: { name: t('shell.nav.accounting'), to: { name: 'accounting' } },
        },
        {
            key: 'transfers',
            title: t('help.transfers.title'),
            lead: t('help.transfers.lead'),
            questions: [
                { key: 'record', title: t('help.transfers.record.title'), text: t('help.transfers.record.text', params) },
                { key: 'fees', title: t('help.transfers.fees.title'), text: t('help.transfers.fees.text', params) },
            ],
            page: { name: t('money.transfers.title'), to: { name: 'transfers' } },
        },
        {
            key: 'taxes',
            title: t('help.taxes.title'),
            lead: t('help.taxes.lead'),
            questions: [
                { key: 'payIncome', title: t('help.taxes.payIncome.title'), text: t('help.taxes.payIncome.text', params) },
                { key: 'payEsv', title: t('help.taxes.payEsv.title'), text: t('help.taxes.payEsv.text', params) },
                { key: 'statuses', title: t('help.taxes.statuses.title'), text: t('help.taxes.statuses.text', params) },
                { key: 'deadlines', title: t('help.taxes.deadlines.title'), text: t('help.taxes.deadlines.text', params) },
                { key: 'filters', title: t('help.taxes.filters.title'), text: t('help.taxes.filters.text', params) },
                { key: 'declaration', title: t('help.taxes.declaration.title'), text: t('help.taxes.declaration.text', params) },
            ],
            page: { name: t('shell.nav.taxes'), to: { name: 'taxes' } },
        },
        {
            key: 'accounting',
            title: t('help.accounting.title'),
            lead: t('help.accounting.lead'),
            questions: [
                { key: 'view', title: t('help.accounting.view.title'), text: t('help.accounting.view.text', params) },
                { key: 'export', title: t('help.accounting.export.title'), text: t('help.accounting.export.text', params) },
            ],
            page: { name: t('shell.nav.accounting'), to: { name: 'accounting' } },
        },
        {
            key: 'books',
            title: t('help.books.title'),
            lead: t('help.books.lead'),
            questions: [
                { key: 'income', title: t('help.books.income.title'), text: t('help.books.income.text', params) },
                { key: 'taxes', title: t('help.books.taxes.title'), text: t('help.books.taxes.text', params) },
                { key: 'pdf', title: t('help.books.pdf.title'), text: t('help.books.pdf.text', params) },
            ],
            page: { name: t('shell.nav.books'), to: { name: 'books' } },
        },
        {
            key: 'alerts',
            title: t('help.alerts.title'),
            lead: t('help.alerts.lead'),
            questions: [
                { key: 'read', title: t('help.alerts.read.title'), text: t('help.alerts.read.text', params) },
                { key: 'settings', title: t('help.alerts.settings.title'), text: t('help.alerts.settings.text', params) },
            ],
            page: { name: t('shell.alerts.notifications'), to: { name: 'alerts' } },
        },
        {
            key: 'clients',
            title: t('help.clients.title'),
            lead: t('help.clients.lead'),
            questions: [
                { key: 'add', title: t('help.clients.add.title'), text: t('help.clients.add.text', params) },
                { key: 'contract', title: t('help.clients.contract.title'), text: t('help.clients.contract.text', params) },
                { key: 'own', title: t('help.clients.own.title'), text: t('help.clients.own.text', params) },
                { key: 'inactive', title: t('help.clients.inactive.title'), text: t('help.clients.inactive.text', params) },
            ],
            page: { name: t('core.common.clients'), to: { name: 'clients' } },
        },
        {
            key: 'accounts',
            title: t('help.accounts.title'),
            lead: t('help.accounts.lead'),
            questions: [
                { key: 'add', title: t('help.accounts.add.title'), text: t('help.accounts.add.text', params) },
                { key: 'balance', title: t('help.accounts.balance.title'), text: t('help.accounts.balance.text', params) },
                { key: 'limit', title: t('help.accounts.limit.title'), text: t('help.accounts.limit.text', params) },
            ],
            page: { name: t('money.accounts.title'), to: { name: 'accounts' } },
        },
        {
            key: 'document',
            title: t('help.document.title'),
            lead: t('help.document.lead'),
            questions: [
                { key: 'numbering', title: t('help.document.numbering.title'), text: t('help.document.numbering.text', params) },
                { key: 'style', title: t('help.document.style.title'), text: t('help.document.style.text', params) },
                { key: 'texts', title: t('help.document.texts.title'), text: t('help.document.texts.text', params) },
                { key: 'cascade', title: t('help.document.cascade.title'), text: t('help.document.cascade.text', params) },
                { key: 'profile', title: t('help.document.profile.title'), text: t('help.document.profile.text', params) },
            ],
            page: { name: t('settings.document.title'), to: { name: 'settings-invoice-document' } },
        },
        {
            key: 'profiles',
            title: t('help.profiles.title'),
            lead: t('help.profiles.lead'),
            questions: [
                { key: 'switch', title: t('help.profiles.switch.title'), text: t('help.profiles.switch.text', params) },
                { key: 'add', title: t('help.profiles.add.title'), text: t('help.profiles.add.text', params) },
                { key: 'close', title: t('help.profiles.close.title'), text: t('help.profiles.close.text', params) },
            ],
            page: { name: t('shell.profiles.title'), to: { name: 'profiles' } },
        },
        {
            key: 'users',
            title: t('help.users.title'),
            lead: t('help.users.lead'),
            questions: [
                { key: 'roles', title: t('help.users.roles.title'), text: t('help.users.roles.text', params) },
                { key: 'invite', title: t('help.users.invite.title'), text: t('help.users.invite.text', params) },
            ],
            page: { name: t('shell.more.usersRoles'), to: { name: 'users' } },
        },
    ];

    if (context.backups) {
        const questions: HelpQuestion[] = [
            { key: 'create', title: t('help.backups.create.title'), text: t('help.backups.create.text', params) },
            { key: 'policy', title: t('help.backups.policy.title'), text: t('help.backups.policy.text', params) },
            { key: 'restore', title: t('help.backups.restore.title'), text: t('help.backups.restore.text', params) },
        ];

        if (context.desktopApp) {
            questions.push({ key: 'recoveryKit', title: t('help.backups.recoveryKit.title'), text: t('help.backups.recoveryKit.text', params) });
        }

        topics.push({
            key: 'backups',
            title: t('help.backups.title'),
            lead: t('help.backups.lead', params),
            questions,
            page: { name: t('settings.backups.title'), to: { name: 'backups' } },
        });
    }

    topics.push({
        key: 'account',
        title: t('help.account.title'),
        lead: t('help.account.lead'),
        questions: [
            { key: 'language', title: t('help.account.language.title'), text: t('help.account.language.text', params) },
            { key: 'remember', title: t('help.account.remember.title'), text: t('help.account.remember.text', params) },
            { key: 'password', title: t('help.account.password.title'), text: t('help.account.password.text', params) },
        ],
    });

    return topics;
}

/**
 * A text as a search compares it: in lower case, with one apostrophe for ’, ʼ and '.
 */
function searchable(text: string): string {
    return text.toLocaleLowerCase().replace(/[’ʼ']/g, '\'');
}

/**
 * The topics, which a search finds, with every word of the query: in a topic's title and lead, which shows its every
 * question, opening those, which have every word themselves; or in a question with its topic's title, which shows
 * that question of the topic, open. An empty query finds them all, closed.
 */
export function searchTopics(topics: HelpTopic[], query: string): FoundTopic[] {
    const words = searchable(query).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return topics.map((topic) => ({ ...topic, opened: [] }));
    }

    const matches = (...texts: string[]): boolean => {
        const haystack = searchable(texts.join(' '));

        return words.every((word) => haystack.includes(word));
    };

    return topics.flatMap((topic) => {
        if (matches(topic.title, topic.lead)) {
            return [{ ...topic, opened: topic.questions.filter((question) => matches(question.title, question.text)).map((question) => question.key) }];
        }

        const questions = topic.questions.filter((question) => matches(topic.title, question.title, question.text));

        return questions.length === 0 ? [] : [{ ...topic, questions, opened: questions.map((question) => question.key) }];
    });
}
