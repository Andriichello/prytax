import type { AccountKind, MovementCategory, MovementDirection } from '@/api/types';
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
