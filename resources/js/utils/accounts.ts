import type { AccountKind } from '@/api/types';

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
 * An account's name with its currency, unless the name says it already: "ПриватБанк ФОП · USD", "Wise USD".
 */
export function accountLabel(name: string | null | undefined, currency: string | null | undefined): string {
    if (!name) {
        return currency ?? '';
    }

    return currency && !name.toUpperCase().includes(currency.toUpperCase()) ? `${name} · ${currency}` : name;
}
