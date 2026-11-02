import type { Account, AccountKind, InvoiceRequisiteEntry, MovementCategory, MovementDirection, RequisiteField } from '@/api/types';
import { t } from '@/i18n';

/**
 * The kinds of accounts, as the screens name them (getters, which translate when they are read).
 */
export const ACCOUNT_KINDS: Record<AccountKind, { label: string; group: string }> = {
    platform: { get label() { return t('core.accountKinds.platform'); }, get group() { return t('core.accountGroups.platform'); } },
    fop_bank: { get label() { return t('core.accountKinds.fopBank'); }, get group() { return t('core.accountGroups.fopBank'); } },
    foreign_bank: { get label() { return t('core.accountKinds.foreignBank'); }, get group() { return t('core.accountGroups.foreignBank'); } },
    personal: { get label() { return t('core.accountKinds.personal'); }, get group() { return t('core.accountGroups.personal'); } },
};

/**
 * The categories of an account's movements (neither income nor transfers), with the directions each may have.
 */
export const MOVEMENT_CATEGORIES: Record<MovementCategory, { label: string; directions: MovementDirection[] }> = {
    bank_fee: { get label() { return t('core.movementKinds.bankFee'); }, directions: ['out'] },
    expense: { get label() { return t('core.movementKinds.expense'); }, directions: ['out'] },
    owner_withdrawal: { get label() { return t('core.movementKinds.ownerWithdrawal'); }, directions: ['out'] },
    interest: { get label() { return t('core.movementKinds.interest'); }, directions: ['in'] },
    other: { get label() { return t('core.movementKinds.other'); }, directions: ['in', 'out'] },
};

/**
 * A movement of an account, as its sheet edits it.
 */
export interface MovementDraft {
    id: number;
    direction: MovementDirection;
    /** Positive; the direction gives the sign. */
    amount: string;
    booked_at: string;
    category: MovementCategory;
    note: string | null;
}

/**
 * An account's name with its currency, unless the name says it already: "ПриватБанк ФОП · USD", "Wise USD".
 */
export function accountLabel(name: string | null | undefined, currency: string | null | undefined): string {
    if (!name) {
        return currency ?? '';
    }

    return currency && !name.toUpperCase().includes(currency.toUpperCase()) ? `${name} · ${currency}` : name;
}

/**
 * The account's fields, which are requisites in invoices, in the built-in order, as the account's page names them
 * (getters, which translate when they are read).
 */
export const REQUISITE_FIELDS: Record<RequisiteField, { label: string; figure: boolean; masked: boolean }> = {
    holder_name: { get label() { return t('core.common.recipient'); }, figure: false, masked: false },
    bank_name: { get label() { return t('money.account.bank'); }, figure: false, masked: false },
    iban: { label: 'IBAN', figure: true, masked: true },
    account_number: { label: 'Account number', figure: true, masked: true },
    swift: { label: 'SWIFT / BIC', figure: true, masked: false },
};

/**
 * A requisite of an account in invoices: a field, or one of its own details, with its value and whether it prints.
 */
export interface Requisite {
    /** Unique among the account's requisites, for lists. */
    key: string;
    field: RequisiteField | null;
    label: string;
    value: string;
    shown: boolean;
}

/**
 * The account's requisites in the order they print in invoices, each printed or not, as the API's
 * `InvoiceRequisites::ordered()` puts them: those `invoice_requisites` names, in its order (a field by its column, a
 * detail by its label), then the others in the built-in order (the fields, then the details as typed), printed.
 */
export function orderedRequisites(account: Pick<Account, RequisiteField | 'details' | 'invoice_requisites'>): Requisite[] {
    const unnamed: Requisite[] = [];

    for (const field of Object.keys(REQUISITE_FIELDS) as RequisiteField[]) {
        const value = account[field];

        if (value) {
            unnamed.push({ key: field, field, label: REQUISITE_FIELDS[field].label, value, shown: true });
        }
    }

    (account.details ?? []).forEach((detail, index) => {
        unnamed.push({ key: `detail-${index}`, field: null, label: detail.label, value: detail.value, shown: true });
    });

    const named: Requisite[] = [];

    for (const entry of account.invoice_requisites ?? []) {
        const index = unnamed.findIndex((requisite) => (entry.field
            ? requisite.field === entry.field
            : requisite.field === null && requisite.label === entry.label));

        if (index !== -1) {
            named.push({ ...unnamed[index]!, shown: entry.shown });
            unnamed.splice(index, 1);
        }
    }

    return [...named, ...unnamed];
}

/**
 * The order to save of requisites, as the account's `invoice_requisites` holds it.
 */
export function requisiteOrder(requisites: Requisite[]): InvoiceRequisiteEntry[] {
    return requisites.map((requisite) => (requisite.field
        ? { field: requisite.field, shown: requisite.shown }
        : { label: requisite.label, shown: requisite.shown }));
}

/**
 * Whether a requisite's value is a number, which the screens show masked to its last four digits: an IBAN, an
 * account number, or a detail of six digits or more.
 */
export function isMaskedRequisite(requisite: Requisite): boolean {
    return requisite.field ? REQUISITE_FIELDS[requisite.field].masked : /^\d{6,}$/.test(requisite.value.replace(/\s/g, ''));
}

/**
 * A number masked to its last four digits: «•••• •••• 6001» for a long one (an IBAN), «•••• 2233» for a short one.
 */
export function maskNumber(value: string): string {
    const digits = value.replace(/\s/g, '');

    return digits.length > 8 ? `•••• •••• ${digits.slice(-4)}` : `•••• ${digits.slice(-4)}`;
}
