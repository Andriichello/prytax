/**
 * Ukrainian plurals: the form for one (1, 21), a few (2–4, 22–24) and many (0, 5–20, 25…).
 */

const rules = new Intl.PluralRules('uk');

export type PluralForms = [one: string, few: string, many: string];

/**
 * The word for a count: plural(2, ['інвойс', 'інвойси', 'інвойсів']) is "інвойси".
 */
export function plural(count: number, [one, few, many]: PluralForms): string {
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
 * The count with its word: "2 інвойси".
 */
export function countOf(count: number, forms: PluralForms): string {
    return `${count} ${plural(count, forms)}`;
}

export const INVOICES: PluralForms = ['інвойс', 'інвойси', 'інвойсів'];
export const PAYMENTS: PluralForms = ['надходження', 'надходження', 'надходжень'];
export const NOTIFICATIONS: PluralForms = ['сповіщення', 'сповіщення', 'сповіщень'];
export const CONTRACTS: PluralForms = ['договір', 'договори', 'договорів'];
export const DAYS: PluralForms = ['день', 'дні', 'днів'];
export const TRANSFERS: PluralForms = ['переказ', 'перекази', 'переказів'];
