import type { AccountKind, MovementCategory, MovementDirection } from '@/api/types';
import { t } from '@/i18n';

/**
 * The kinds of accounts, as the screens name them (getters, which translate when they are read).
 */
export const ACCOUNT_KINDS: Record<AccountKind, { label: string; group: string }> = {
    platform: { get label() { return t('Платформа'); }, get group() { return t('Платформи'); } },
    fop_bank: { get label() { return t('ФОП-рахунок в Україні'); }, get group() { return t('ФОП-рахунки в Україні'); } },
    foreign_bank: { get label() { return t('Іноземний банк'); }, get group() { return t('Іноземні банки'); } },
    personal: { get label() { return t('Особистий'); }, get group() { return t('Особисті'); } },
};

/**
 * The categories of an account's movements (neither income nor transfers), with the directions each may have.
 */
export const MOVEMENT_CATEGORIES: Record<MovementCategory, { label: string; directions: MovementDirection[] }> = {
    bank_fee: { get label() { return t('Комісія банку'); }, directions: ['out'] },
    expense: { get label() { return t('Витрата'); }, directions: ['out'] },
    owner_withdrawal: { get label() { return t('Зняття власником'); }, directions: ['out'] },
    interest: { get label() { return t('Відсотки'); }, directions: ['in'] },
    other: { get label() { return t('Інший рух'); }, directions: ['in', 'out'] },
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
