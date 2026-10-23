import { locale } from '@/i18n';

/**
 * Plurals of the screens' languages: Ukrainian has the form for one (1, 21), a few (2–4, 22–24) and many (0, 5–20,
 * 25…); English has the form for one and the other.
 */

const rules = new Intl.PluralRules('uk');

export interface PluralForms {
    uk: [one: string, few: string, many: string];
    en: [one: string, other: string];
}

/**
 * The word for a count in the current language: plural(2, INVOICES) is "інвойси", or "invoices" in English.
 */
export function plural(count: number, { uk: [one, few, many], en }: PluralForms): string {
    if (locale.value === 'en') {
        return count === 1 ? en[0] : en[1];
    }

    switch (rules.select(count)) {
        case 'one':
            return one;
        case 'few':
            return few;
        default:
            return many;
    }
}

/**
 * The count with its word: "2 інвойси", "2 invoices".
 */
export function countOf(count: number, forms: PluralForms): string {
    return `${count} ${plural(count, forms)}`;
}

export const INVOICES: PluralForms = { uk: ['інвойс', 'інвойси', 'інвойсів'], en: ['invoice', 'invoices'] };
export const PAYMENTS: PluralForms = { uk: ['надходження', 'надходження', 'надходжень'], en: ['receipt', 'receipts'] };
export const NOTIFICATIONS: PluralForms = { uk: ['сповіщення', 'сповіщення', 'сповіщень'], en: ['notification', 'notifications'] };
export const CONTRACTS: PluralForms = { uk: ['договір', 'договори', 'договорів'], en: ['contract', 'contracts'] };
export const DAYS: PluralForms = { uk: ['день', 'дні', 'днів'], en: ['day', 'days'] };
export const TRANSFERS: PluralForms = { uk: ['переказ', 'перекази', 'переказів'], en: ['transfer', 'transfers'] };
