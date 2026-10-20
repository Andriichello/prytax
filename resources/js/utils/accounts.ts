import type { AccountKind, MovementCategory, MovementDirection } from '@/api/types';

/**
 * The kinds of accounts, as the screens name them.
 */
export const ACCOUNT_KINDS: Record<AccountKind, { label: string; group: string }> = {
    platform: { label: 'Платформа', group: 'Платформи' },
    fop_bank: { label: 'ФОП-рахунок в Україні', group: 'ФОП-рахунки в Україні' },
    foreign_bank: { label: 'Іноземний банк', group: 'Іноземні банки' },
    personal: { label: 'Особистий', group: 'Особисті' },
};

/**
 * The categories of an account's movements (neither income nor transfers), with the directions each may have.
 */
export const MOVEMENT_CATEGORIES: Record<MovementCategory, { label: string; directions: MovementDirection[] }> = {
    bank_fee: { label: 'Комісія банку', directions: ['out'] },
    expense: { label: 'Витрата', directions: ['out'] },
    owner_withdrawal: { label: 'Зняття власником', directions: ['out'] },
    interest: { label: 'Відсотки', directions: ['in'] },
    other: { label: 'Інший рух', directions: ['in', 'out'] },
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
